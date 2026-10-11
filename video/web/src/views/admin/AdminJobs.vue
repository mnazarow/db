<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { usePaged } from '../../utils/paged.js';
import * as ws from '../../ws.js';
import Pagination from '../../components/Pagination.vue';
import { fmtDateTime } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const route = useRoute(); const router = useRouter(); const ui = useUi();
const status = ref(String(route.query.status || '')); const type = ref(String(route.query.type || ''));
const list = usePaged('/api/admin/jobs', { key: 'jobs', limit: 40, extraQuery: () => ({ status: status.value, type: type.value }) });
watch([status, type], () => router.replace({ query: { status: status.value || undefined, type: type.value || undefined } }));
let timer = null; let off = null;
onMounted(() => { timer = setInterval(() => { if (!document.hidden) list.load(); }, 5000); off = ws.on('job.enqueued', () => list.load()); });
onBeforeUnmount(() => { clearInterval(timer); off?.(); });
async function retry(j) { await post(`/api/admin/jobs/${j.id}/retry`, {}); list.load(); }
async function cancel(j) {
  try {
    const r = await post(`/api/admin/jobs/${j.id}/cancel`, {});
    ui.toast(r.outcome === 'cancelling' ? 'Воркер получил команду прервать задание' : r.outcome === 'cancelled' ? 'Задание отменено' : 'Задание уже завершилось', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
  list.load();
}
async function retryAll() { const r = await post('/api/admin/jobs/retry-failed', {}); ui.toast(`Перезапущено: ${r.count}`); list.load(); }
const TYPES = { transcode: 'Транскодирование', thumbnails: 'Миниатюры', subtitles_asr: 'Субтитры (ASR)', email: 'Письмо', live_import: 'Импорт записи эфира', maintenance: 'Обслуживание', recompute_storage: 'Пересчёт хранилища' };
const STATUS = { queued: ['В очереди', 'brand'], running: ['Выполняется', 'warning'], done: ['Готово', 'success'], failed: ['Ошибка', 'danger'], cancelled: ['Отменено', ''] };
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Очередь заданий</h1><div class="sub" v-if="list.extra.value?.stats">в очереди {{ list.extra.value.stats.queued }} • выполняется {{ list.extra.value.stats.running }} • ошибок {{ list.extra.value.stats.failed }}</div></div><div class="actions"><button class="btn sm" @click="list.load()" aria-label="Обновить список"><Icon name="refresh" :size="16" /></button><button v-if="list.extra.value?.stats?.failed" class="btn sm" @click="retryAll">Перезапустить все ошибочные</button></div></div>
    <div class="row wrap mb-16"><select class="select" v-model="status" style="max-width: 180px"><option value="">Все статусы</option><option v-for="(v, k) in STATUS" :key="k" :value="k">{{ v[0] }}</option></select><select class="select" v-model="type" style="max-width: 220px"><option value="">Все типы</option><option v-for="(v, k) in TYPES" :key="k" :value="k">{{ v }}</option></select></div>
    <ListError v-if="list.error.value" compact class="mb-16" :error="list.error.value" @retry="list.load()" />
    <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Тип</th><th>Объект</th><th>Статус</th><th>Прогресс</th><th>Попыток</th><th>Воркер</th><th>Создано</th><th></th></tr></thead><tbody>
      <tr v-for="j in list.items.value" :key="j.id">
        <td class="mono">{{ j.id }}</td><td>{{ TYPES[j.type] || j.type }}</td>
        <td class="small"><router-link v-if="j.videoId" :to="`/studio/videos/${j.videoId}`">{{ j.videoTitle || j.videoId }}</router-link><span v-else-if="j.type === 'email'">{{ j.payload?.to }}: {{ j.payload?.subject }}</span><span v-else class="muted">—</span><div v-if="j.error" class="tiny" style="color: var(--danger); max-width: 420px; white-space: pre-wrap">{{ j.error }}</div></td>
        <td><span class="badge" :class="STATUS[j.status]?.[1]">{{ STATUS[j.status]?.[0] }}</span></td>
        <td style="min-width: 120px"><div v-if="j.status === 'running'" class="up-bar"><i :style="{ width: j.progress + '%' }"></i></div><div class="tiny muted">{{ j.status === 'running' ? j.progress + '%' + (j.stage ? ' · ' + j.stage : '') : '' }}</div></td>
        <td class="small">{{ j.attempts }}/{{ j.maxAttempts }}</td><td class="small mono">{{ j.lockedBy || '' }}</td><td class="small nowrap">{{ fmtDateTime(j.createdAt) }}</td>
        <td class="actions"><button v-if="j.status === 'failed' || j.status === 'cancelled'" class="btn ghost sm" @click="retry(j)">Повторить</button><button v-if="j.status === 'queued' || j.status === 'running'" class="btn ghost sm danger" @click="cancel(j)">Отменить</button></td>
      </tr>
      <tr v-if="!list.items.value.length"><td colspan="9" class="muted">Заданий нет</td></tr>
    </tbody></table></div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="40" @change="list.setPage" />
  </div>
</template>
