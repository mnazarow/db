<script setup>
// Администрирование: качество воспроизведения — время до первого кадра, буферизации, ошибки.
import { ref, computed, onMounted, watch } from 'vue';
import { get } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import LineChart from '../../components/charts/LineChart.vue';

const ui = useUi();
const data = ref(null);
const loading = ref(true);
const days = ref(7);

async function load() {
  loading.value = true;
  try { data.value = await get(`/api/admin/quality?days=${days.value}`); }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);
watch(days, load);

const DEVICE = { desktop: 'Компьютер', mobile: 'Телефон', tablet: 'Планшет', tv: 'ТВ' };
const startupSeries = computed(() => (data.value?.byDay || []).map((d) => ({ x: d.day, y: d.startupMedianMs || 0 })));
const rebufferSeries = computed(() => (data.value?.byDay || []).map((d) => ({ x: d.day, y: d.rebufferRatio || 0 })));
function ms(v) { return v === null || v === undefined ? '—' : (v >= 1000 ? `${(v / 1000).toFixed(1)} с` : `${v} мс`); }
function grade(t) {
  // Ориентиры отрасли: старт до 1 с — хорошо, буферизация до 0,5 % — хорошо
  if (t.startupMedianMs === null) return null;
  const okStart = t.startupMedianMs <= 1000;
  const okBuf = t.rebufferRatio <= 0.5;
  const okFail = t.failureRate <= 1;
  if (okStart && okBuf && okFail) return { label: 'Всё хорошо', cls: 'success' };
  if (t.rebufferRatio > 2 || t.failureRate > 5) return { label: 'Есть проблемы', cls: 'danger' };
  return { label: 'Приемлемо', cls: 'warning' };
}
</script>

<template>
  <div>
    <div class="page-head">
      <div><h1>Качество воспроизведения</h1><div class="sub">Как видео открывается у сотрудников: время старта, буферизации, ошибки</div></div>
      <div class="row gap-8">
        <select v-model.number="days" class="input sm" style="max-width:160px" aria-label="Период">
          <option :value="1">За сутки</option><option :value="7">За неделю</option><option :value="30">За месяц</option><option :value="90">За 90 дней</option>
        </select>
        <a class="btn ghost sm" :href="$url(`/api/admin/quality?days=${days}&format=csv`)"><Icon name="csv" :size="16" /> CSV</a>
      </div>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!data || !data.totals.sessions" icon="analytics" title="Данных пока нет"
      text="Метрики собираются при просмотре видео. Убедитесь, что сбор включён в «Настройки → Курсы и вовлечение»." />
    <template v-else>
      <div class="stat-row mb-16">
        <div class="stat"><div class="stat-value">{{ ms(data.totals.startupMedianMs) }}</div><div class="stat-label">старт видео (медиана)</div><div class="tiny muted">95-й процентиль: {{ ms(data.totals.startupP95Ms) }}</div></div>
        <div class="stat" :class="{ danger: data.totals.rebufferRatio > 2 }"><div class="stat-value">{{ data.totals.rebufferRatio }}%</div><div class="stat-label">времени в буферизации</div><div class="tiny muted">{{ data.totals.rebuffers }} остановок</div></div>
        <div class="stat" :class="{ danger: data.totals.failureRate > 5 }"><div class="stat-value">{{ data.totals.failureRate }}%</div><div class="stat-label">сеансов с ошибкой</div><div class="tiny muted">не стартовало: {{ data.totals.startFailureRate }}%</div></div>
        <div class="stat"><div class="stat-value">{{ data.totals.sessions }}</div><div class="stat-label">сеансов просмотра</div><div class="tiny muted">{{ data.totals.watchHours }} ч просмотра</div></div>
        <div class="stat" v-if="data.totals.avgBitrate"><div class="stat-value">{{ Math.round(data.totals.avgBitrate / 100) / 10 }} Мбит/с</div><div class="stat-label">средний битрейт</div></div>
      </div>

      <div v-if="grade(data.totals)" class="panel mb-16 row" style="align-items:center; gap:12px">
        <span class="badge" :class="grade(data.totals).cls">{{ grade(data.totals).label }}</span>
        <span class="small muted">Ориентиры: старт до 1 секунды, буферизация до 0,5 % времени просмотра, ошибки до 1 % сеансов.</span>
      </div>

      <div class="two-col mb-16">
        <div class="panel"><h3 class="mb-8">Время до первого кадра, мс</h3><LineChart :data="startupSeries" :height="200" :format="(v) => `${v} мс`" /></div>
        <div class="panel"><h3 class="mb-8">Доля буферизации, %</h3><LineChart :data="rebufferSeries" :height="200" :format="(v) => `${v} %`" /></div>
      </div>

      <div class="two-col">
        <div class="panel">
          <h3 class="mb-8">По устройствам</h3>
          <div class="table-wrap"><table class="table"><thead><tr><th>Устройство</th><th>Сеансов</th><th>Старт</th><th>Буферизация</th><th>Ошибки</th></tr></thead><tbody>
            <tr v-for="d in data.byDevice" :key="d.device">
              <td>{{ DEVICE[d.device] || d.device }}</td><td class="small">{{ d.sessions }}</td>
              <td class="small">{{ ms(d.startupMedianMs) }}</td><td class="small">{{ d.rebufferRatio }}%</td><td class="small">{{ d.failed || '—' }}</td>
            </tr>
          </tbody></table></div>
          <h3 class="mt-16 mb-8">По качеству</h3>
          <div class="row wrap gap-8">
            <span v-for="qq in data.byQuality" :key="qq.height" class="chip">{{ qq.height ? qq.height + 'p' : 'неизвестно' }} · {{ qq.sessions }}</span>
          </div>
        </div>
        <div class="panel">
          <h3 class="mb-8">Видео с худшим воспроизведением</h3>
          <div v-if="!data.worst.length" class="muted small">Проблемных видео нет</div>
          <div class="table-wrap" v-else><table class="table"><thead><tr><th>Видео</th><th>Сеансов</th><th>Буферизация</th><th>Старт</th></tr></thead><tbody>
            <tr v-for="w in data.worst" :key="w.id">
              <td class="ellipsis" style="max-width:220px"><router-link :to="`/watch/${w.shortId}`">{{ w.title }}</router-link></td>
              <td class="small">{{ w.sessions }}</td><td class="small">{{ w.rebufferRatio }}%</td><td class="small">{{ ms(w.startupMedianMs) }}</td>
            </tr>
          </tbody></table></div>
          <h3 class="mt-16 mb-8">Ошибки плеера</h3>
          <div v-if="!data.errors.length" class="muted small">Ошибок не было</div>
          <ul v-else class="small" style="margin:0; padding-left:18px">
            <li v-for="(e, i) in data.errors" :key="i"><span class="mono">{{ e.text }}</span> — {{ e.count }}</li>
          </ul>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.stat.danger .stat-value { color: var(--danger); }
.stat-value { font-size: 24px; font-weight: 700; }
.stat-label { font-size: 12px; color: var(--muted); }
.two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
@media (max-width: 900px) { .two-col { grid-template-columns: minmax(0, 1fr); } }
</style>
