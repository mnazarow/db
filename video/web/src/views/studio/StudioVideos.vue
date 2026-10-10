<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { del, post, patch } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { usePaged } from '../../utils/paged.js';
import * as ws from '../../ws.js';
import Pagination from '../../components/Pagination.vue';
import EmptyState from '../../components/EmptyState.vue';
import Dropdown from '../../components/Dropdown.vue';
import { fmtDuration, fmtNumber, fmtDateShort, fmtBytes, VISIBILITY, STATUS } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const auth = useAuth();
const q = ref(String(route.query.q || ''));
const status = ref(String(route.query.status || ''));
const visibility = ref(String(route.query.visibility || ''));
const sort = ref(String(route.query.sort || ''));
const selected = ref(new Set());
const list = usePaged('/api/studio/videos', { limit: 20, extraQuery: () => ({ q: q.value, status: status.value, visibility: visibility.value, sort: sort.value }) });
let t = null;
watch([q, status, visibility, sort], () => { clearTimeout(t); t = setTimeout(() => { router.replace({ query: { q: q.value || undefined, status: status.value || undefined, visibility: visibility.value || undefined, sort: sort.value || undefined } }); }, 250); });
let off = [];
onMounted(() => {
  off.push(ws.on('video.progress', (m) => { const v = list.items.value.find((x) => x.id === m.videoId); if (v) { v.status = 'processing'; v.processingProgress = m.progress; v.processingStage = m.stage; } }));
  off.push(ws.on('video.ready', () => list.load()), ws.on('video.failed', () => list.load()));
});
onBeforeUnmount(() => off.forEach((f) => f()));

async function remove(v) {
  if (!(await ui.ask({ title: 'Удалить видео?', message: `«${v.title}» будет удалено безвозвратно вместе со статистикой и комментариями.`, okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/videos/${v.id}`); ui.toast('Видео удалено'); list.load();
}
async function setVisibility(v, vis) { await patch(`/api/videos/${v.id}`, { visibility: vis }); v.visibility = vis; ui.toast('Видимость изменена', { type: 'success' }); }
async function reprocess(v) { try { await post(`/api/videos/${v.id}/reprocess`, {}); ui.toast('Видео поставлено в очередь на обработку'); list.load(); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
function toggleAll(e) { selected.value = e.target.checked ? new Set(list.items.value.map((v) => v.id)) : new Set(); }
function toggle(id) { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; }
async function bulkVisibility(vis) { for (const id of selected.value) await patch(`/api/videos/${id}`, { visibility: vis }); selected.value = new Set(); list.load(); ui.toast('Видимость обновлена', { type: 'success' }); }
async function bulkDelete() {
  if (!(await ui.ask({ title: `Удалить ${selected.value.size} видео?`, message: 'Действие нельзя отменить.', okLabel: 'Удалить', danger: true }))) return;
  for (const id of selected.value) await del(`/api/videos/${id}`);
  selected.value = new Set(); list.load();
}
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Мои видео</h1><div class="sub" v-if="list.extra.value?.summary">{{ list.extra.value.summary.videos }} видео • {{ fmtNumber(list.extra.value.summary.views) }} просмотров • {{ fmtBytes(list.extra.value.summary.bytes) }}</div></div><div class="actions"><router-link v-if="auth.canUpload" to="/studio/upload" class="btn primary"><Icon name="upload" :size="18" /> Загрузить</router-link></div></div>
    <div class="row wrap mb-16">
      <input class="input" v-model="q" placeholder="Поиск по названию" style="max-width: 260px" />
      <select class="select" v-model="status" style="max-width: 180px"><option value="">Любой статус</option><option v-for="(l, k) in STATUS" :key="k" :value="k">{{ l }}</option></select>
      <select class="select" v-model="visibility" style="max-width: 200px"><option value="">Любая видимость</option><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select>
      <select class="select" v-model="sort" style="max-width: 200px"><option value="">Сначала новые</option><option value="oldest">Сначала старые</option><option value="views">По просмотрам</option><option value="likes">По лайкам</option><option value="comments">По комментариям</option><option value="title">По названию</option></select>
    </div>
    <div v-if="selected.size" class="alert info mb-16 row wrap"><span>Выбрано: {{ selected.size }}</span>
      <Dropdown align="left"><template #trigger><button class="btn sm">Видимость <Icon name="chevronDown" :size="16" /></button></template><button v-for="(v, k) in VISIBILITY" :key="k" class="item" @click="bulkVisibility(k)"><Icon class="ic" :name="v.icon" :size="18" /> {{ v.label }}</button></Dropdown>
      <button class="btn sm danger" @click="bulkDelete"><Icon name="delete" :size="16" /> Удалить</button><button class="btn ghost sm" @click="selected = new Set()">Снять выделение</button>
    </div>
    <div v-if="list.loading.value && !list.items.value.length" class="loading-block"><div class="spin"></div></div>
    <ListError v-else-if="list.error.value" :error="list.error.value" @retry="list.load()" />
    <EmptyState v-else-if="!list.items.value.length" icon="movie" title="Видео не найдены" :text="q || status || visibility ? 'Попробуйте изменить фильтры.' : 'Загрузите первое видео.'" />
    <div v-else class="table-wrap">
      <table class="table sv-table">
        <thead><tr><th style="width:32px"><input type="checkbox" @change="toggleAll" /></th><th>Видео</th><th>Видимость</th><th>Дата</th><th class="num">Просмотры</th><th class="num">Лайки</th><th class="num">Комм.</th><th></th></tr></thead>
        <tbody>
          <tr v-for="v in list.items.value" :key="v.id">
            <td><input type="checkbox" :checked="selected.has(v.id)" @change="toggle(v.id)" /></td>
            <td>
              <div class="sv-video">
                <router-link :to="`/studio/videos/${v.id}`" class="thumb sv-thumb"><img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" /><span v-if="v.duration" class="dur">{{ fmtDuration(v.duration) }}</span></router-link>
                <div style="min-width:0">
                  <router-link :to="`/studio/videos/${v.id}`" class="sv-title clamp-2">{{ v.title }}</router-link>
                  <div class="row gap-4 wrap tiny mt-4">
                    <span v-if="v.status !== 'ready'" class="badge" :class="v.status === 'failed' ? 'danger' : 'warning'">{{ STATUS[v.status] }}<template v-if="v.status === 'processing'"> {{ v.processingProgress }}%</template></span>
                    <span v-if="v.moderationStatus === 'pending'" class="badge warning">На проверке</span>
                    <span v-if="v.moderationStatus === 'rejected'" class="badge danger" :title="v.moderationNote">Отклонено</span>
                    <span v-if="v.isBlocked" class="badge danger">Заблокировано</span>
                    <span v-if="v.scheduledAt && new Date(v.scheduledAt) > new Date()" class="badge brand">Запланировано</span>
                    <span v-if="v.status === 'failed'" class="faint" :title="v.processingError">{{ (v.processingError || '').slice(0, 80) }}</span>
                  </div>
                </div>
              </div>
            </td>
            <td>
              <Dropdown align="left"><template #trigger><button class="chip"><Icon :name="VISIBILITY[v.visibility]?.icon" :size="14" /> {{ VISIBILITY[v.visibility]?.label }} <Icon name="chevronDown" :size="14" /></button></template>
                <button v-for="(vis, k) in VISIBILITY" :key="k" class="item" @click="setVisibility(v, k)"><Icon class="ic" :name="vis.icon" :size="18" /><span><div>{{ vis.label }}</div><div class="tiny muted">{{ vis.hint }}</div></span></button></Dropdown>
            </td>
            <td class="nowrap small">{{ fmtDateShort(v.publishedAt || v.createdAt) }}</td>
            <td class="num">{{ fmtNumber(v.viewCount) }}</td>
            <td class="num">{{ fmtNumber(v.likeCount) }}</td>
            <td class="num">{{ fmtNumber(v.commentCount) }}</td>
            <td class="actions">
              <Dropdown><template #trigger><button class="ibtn sm" aria-label="Действия с видео"><Icon name="more" :size="20" /></button></template>
                <router-link :to="`/studio/videos/${v.id}`" class="item"><Icon class="ic" name="edit" :size="18" /> Редактировать</router-link>
                <router-link :to="`/watch/${v.shortId}`" class="item"><Icon class="ic" name="play" :size="18" /> Открыть</router-link>
                <router-link :to="`/studio/videos/${v.id}/analytics`" class="item"><Icon class="ic" name="analytics" :size="18" /> Аналитика</router-link>
                <router-link :to="`/studio/comments?videoId=${v.id}`" class="item"><Icon class="ic" name="comment" :size="18" /> Комментарии</router-link>
                <button v-if="v.originalKept" class="item" @click="reprocess(v)"><Icon class="ic" name="refresh" :size="18" /> Обработать заново</button>
                <a v-if="v.status === 'ready'" class="item" :href="$url(`/api/videos/${v.shortId}/download`)"><Icon class="ic" name="download" :size="18" /> Скачать</a>
                <div class="sep"></div>
                <button class="item danger" @click="remove(v)"><Icon class="ic" name="delete" :size="18" /> Удалить</button>
              </Dropdown>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="20" @change="list.setPage" />
  </div>
</template>

<style>
.sv-video { display: flex; gap: 12px; align-items: center; min-width: 320px; }
.sv-thumb { width: 120px; min-width: 120px; border-radius: 6px; }
.sv-title { color: var(--text); font-weight: 500; font-size: 14px; }
</style>
