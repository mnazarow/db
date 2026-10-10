<script setup>
// Возможности портала: что включено, чем пользовались и что исчезнет при выключении.
import { ref, computed, onMounted } from 'vue';
import { get, patch, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';

const ui = useUi();
const auth = useAuth();
const groups = ref([]);
const total = ref(0);
const off = ref(0);
const loading = ref(true);
const error = ref('');
const saving = ref('');
const onlyUnused = ref(false);

async function load() {
  loading.value = true; error.value = '';
  try {
    const r = await get('/api/admin/features');
    groups.value = r.groups; total.value = r.total; off.value = r.off;
  } catch (e) { error.value = e.message; } finally { loading.value = false; }
}
onMounted(load);

const unusedCount = computed(() => groups.value.flatMap((g) => g.items).filter((i) => i.enabled && i.usage === 0).length);
const shown = (g) => (onlyUnused.value ? g.items.filter((i) => i.usage === 0 && i.enabled) : g.items);

async function toggle(item) {
  const next = !item.enabled;
  saving.value = item.key;
  try {
    await patch('/api/admin/features', { features: { [item.key]: next } });
    item.enabled = next;
    off.value += next ? -1 : 1;
    // Зависимые пункты сразу показываем как недоступные
    for (const g of groups.value) for (const i of g.items) {
      if ((i.deps || []).includes(item.key)) i.blockedBy = next ? i.blockedBy.filter((d) => d.key !== item.key) : [...i.blockedBy, { key: item.key, title: item.title }];
    }
    await auth.refreshConfig();
    ui.toast(next ? `«${item.title}» включено` : `«${item.title}» выключено`, { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = ''; }
}

async function disableUnused() {
  if (!confirm(`Выключить всё, чем ни разу не пользовались (${unusedCount.value})? Данные не удаляются, включить обратно можно в любой момент.`)) return;
  saving.value = 'all';
  try {
    const r = await post('/api/admin/features/disable-unused', {});
    ui.toast(r.disabled ? `Выключено: ${r.titles.join(', ')}` : 'Выключать нечего', { type: 'success' });
    await load();
    await auth.refreshConfig();
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = ''; }
}

function usageLabel(n) {
  if (n === null || n === undefined) return '';
  if (n === 0) return 'ни разу не использовалось';
  return `записей: ${n}`;
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>Возможности</h1>
        <div class="sub">Ненужные разделы можно выключить: они исчезнут из меню, а обращения к ним перестанут обрабатываться. Ничего не удаляется — включить обратно можно в любой момент.</div>
      </div>
      <div class="row">
        <button v-if="unusedCount" class="btn" :disabled="saving === 'all'" @click="disableUnused">
          <Icon name="broom" :size="18" /> Выключить неиспользуемое ({{ unusedCount }})
        </button>
      </div>
    </div>

    <div v-if="error" class="alert danger"><Icon name="alertCircle" :size="18" /> {{ error }} <button class="btn ghost sm" @click="load">Повторить</button></div>
    <div v-else-if="loading" class="loading-block"><div class="spin"></div></div>

    <template v-else>
      <div class="row wrap mb-16" style="align-items:center; gap:16px">
        <div class="small muted">Всего возможностей: <b>{{ total }}</b> · выключено: <b>{{ off }}</b> · ни разу не использовались: <b>{{ unusedCount }}</b></div>
        <label class="switch"><input type="checkbox" v-model="onlyUnused" /><span class="track"></span><span>Показать только неиспользуемые</span></label>
      </div>

      <div v-for="g in groups" :key="g.id" class="panel mb-16">
        <h3 class="mb-4">{{ g.title }}</h3>
        <p class="small muted mb-12">{{ g.hint }}</p>
        <div v-if="!shown(g).length" class="small muted">В этой группе ничего не подходит под фильтр.</div>
        <div v-for="item in shown(g)" :key="item.key" class="feat" :class="{ off: !item.enabled }">
          <label class="switch feat-sw">
            <input type="checkbox" :checked="item.enabled" :disabled="saving === item.key || saving === 'all'" @change="toggle(item)" />
            <span class="track"></span>
          </label>
          <div class="grow">
            <div class="row" style="gap:8px; align-items:center">
              <b>{{ item.title }}</b>
              <span v-if="item.usage === 0" class="badge">не использовалось</span>
              <span v-else-if="item.usage !== null" class="badge success">{{ usageLabel(item.usage) }}</span>
              <span v-if="!item.enabled" class="badge danger">выключено</span>
            </div>
            <div class="small muted mt-4">{{ item.desc }}</div>
            <div v-if="item.needs.length" class="small mt-4" style="color: var(--warning)">Нужно для работы: {{ item.needs.join(', ') }}</div>
            <div v-if="item.blockedBy.length" class="small mt-4" style="color: var(--warning)">
              Не работает, пока выключено: {{ item.blockedBy.map((b) => b.title).join(', ') }}
            </div>
            <code class="small faint">{{ item.key }}</code>
          </div>
        </div>
      </div>

      <div class="panel small muted">
        <b>Как это работает.</b> Выключенная возможность исчезает из меню и из студии, а её адреса API отвечают «Раздел отключён администратором портала» — то есть прячется не только кнопка. Уже накопленные данные остаются в базе и снова появятся, как только возможность включат. Счётчик «записей» показывает, сколько всего накоплено по этой части портала за всё время: ноль — верный признак, что возможностью не пользуются. Выключение не отменяет прав: тем, кому раздел и так был недоступен, ничего не изменится.
      </div>
    </template>
  </div>
</template>

<style scoped>
.feat { display: flex; gap: 14px; align-items: flex-start; padding: 14px 0; border-top: 1px solid var(--line); }
.feat:first-of-type { border-top: 0; }
.feat.off { opacity: .62; }
.feat-sw { flex: none; margin-top: 2px; }
.feat code { display: inline-block; margin-top: 6px; }
</style>
