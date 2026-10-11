<script setup>
// Администрирование: порядок в хранилище — что занимает место и у кого.
import { ref, onMounted, computed } from 'vue';
import { get } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDuration, fmtDate } from '../../utils/format.js';

const auth = useAuth();
const data = ref(null);
const loading = ref(true);

function fmtBytes(n) {
  const b = Number(n) || 0;
  const gb = b / (1024 ** 3);
  if (gb >= 1) return `${gb.toFixed(gb < 10 ? 1 : 0)} ГБ`;
  return `${Math.max(0, Math.round(b / (1024 * 1024)))} МБ`;
}
async function load() {
  loading.value = true;
  try { data.value = await get('/api/admin/storage'); } finally { loading.value = false; }
}
onMounted(load);
const maxOwner = computed(() => Math.max(1, ...(data.value?.byOwner || []).map((o) => o.bytes)));
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>Хранилище</h1>
        <p class="muted">Сколько занимает видеотека, кто занимает больше всех и что можно освободить.</p>
      </div>
      <a class="btn soft" :href="$url('/api/admin/storage/export')"><Icon name="csv" :size="18" /> CSV</a>
    </div>

    <div v-if="loading" class="skeleton" style="height: 220px"></div>
    <template v-else-if="data">
      <div class="stat-row mb-16">
        <div class="stat"><b>{{ fmtBytes(data.totals.bytes) }}</b><span>всего в видеотеке</span><i class="tiny muted">{{ data.totals.videos }} видео</i></div>
        <div class="stat"><b>{{ fmtBytes(data.totals.originalsBytes) }}</b><span>исходники</span><i class="tiny muted">{{ data.totals.originals }} файлов</i></div>
        <div class="stat"><b>{{ fmtBytes(data.totals.trashBytes) }}</b><span>в корзине</span><i class="tiny muted">{{ data.totals.trashVideos }} видео</i></div>
        <div class="stat"><b>{{ data.quotaMb ? (data.quotaMb >= 1024 ? (data.quotaMb / 1024).toFixed(1) + ' ГБ' : data.quotaMb + ' МБ') : '—' }}</b><span>квота на канал</span><i class="tiny muted">Настройки → Хранилище</i></div>
      </div>

      <div v-if="data.oldOriginals.count" class="panel soft mb-16 row gap-8" style="align-items:center">
        <Icon name="harddisk" :size="20" />
        <span class="small grow">Исходники старше полугода: <b>{{ data.oldOriginals.count }}</b> файлов на <b>{{ fmtBytes(data.oldOriginals.bytes) }}</b>. Их можно удалять автоматически — настройка «Удалять исходники старше, дней»; видео при этом останется доступным.</span>
        <router-link class="btn sm" to="/admin/settings/storage">Настроить</router-link>
      </div>

      <div class="grid-2 st-grid">
        <div class="panel">
          <h3 class="mb-8">Кто занимает место</h3>
          <div v-for="o in data.byOwner" :key="o.id" class="row gap-8 st-row">
            <span class="grow ellipsis">{{ o.displayName }}<span class="tiny muted"> · {{ o.videos }}</span></span>
            <div class="st-bar"><i :style="{ width: Math.round((o.bytes / maxOwner) * 100) + '%' }"></i></div>
            <span class="nowrap small" style="width: 84px; text-align:right">{{ fmtBytes(o.bytes) }}</span>
            <span v-if="o.percentOfQuota !== null" class="tiny nowrap" :class="o.percentOfQuota > 90 ? 'danger-text' : 'muted'" style="width: 44px; text-align:right">{{ o.percentOfQuota }}%</span>
          </div>
        </div>

        <div class="panel">
          <h3 class="mb-8">Самые тяжёлые видео</h3>
          <div v-for="v in data.biggest" :key="v.id" class="row gap-8 st-row">
            <router-link class="grow ellipsis" :to="`/watch/${v.shortId}`">{{ v.title }}</router-link>
            <span class="tiny muted nowrap">{{ v.owner.displayName }}</span>
            <span class="nowrap small" style="width: 84px; text-align:right">{{ fmtBytes(v.storageBytes) }}</span>
          </div>
        </div>
      </div>
    </template>
    <EmptyState v-else icon="harddisk" title="Нет данных" text="Не удалось получить сведения о хранилище." />
  </div>
</template>

<style>
.st-row { padding: 5px 0; align-items: center; border-bottom: 1px solid var(--line-2); }
.st-row:last-child { border-bottom: 0; }
.st-bar { width: 120px; height: 6px; border-radius: 3px; background: var(--line-2); overflow: hidden; flex-shrink: 0; }
.st-bar i { display: block; height: 100%; background: var(--brand); }
.st-grid { grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); }
</style>
