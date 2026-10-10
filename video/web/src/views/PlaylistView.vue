<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, patch, del, put } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import VideoCard from '../components/VideoCard.vue';
import EmptyState from '../components/EmptyState.vue';
import Modal from '../components/Modal.vue';
import ShareDialog from '../components/ShareDialog.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import { VISIBILITY, fmtDate, fmtDuration } from '../utils/format.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const data = ref(null);
const error = ref(null);
const editing = ref(false);
const share = ref(false);
const form = ref({ title: '', description: '', visibility: 'private' });
const dragIdx = ref(-1);

const pl = computed(() => data.value?.playlist);
const canEdit = computed(() => auth.user && (auth.user.id === pl.value?.owner?.id || auth.isStaff));
const totalDuration = computed(() => (data.value?.videos || []).reduce((a, v) => a + (v.duration || 0), 0));
const firstPlayable = computed(() => (data.value?.videos || []).find((v) => !v.unavailable));

async function load() {
  try { data.value = await get(`/api/playlists/${route.params.id}?limit=200`); document.title = `${pl.value.title} — ${auth.siteName}`; } catch (e) { error.value = e; }
}
function startEdit() { form.value = { title: pl.value.title, description: pl.value.description, visibility: pl.value.visibility }; editing.value = true; }
async function save() {
  try { const r = await patch(`/api/playlists/${pl.value.id}`, form.value); data.value.playlist = r.playlist; editing.value = false; ui.toast('Плейлист сохранён', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function remove() {
  if (!(await ui.ask({ title: 'Удалить плейлист?', message: 'Видео останутся на портале, будет удалён только список.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/playlists/${pl.value.id}`);
  router.push('/library');
}
async function removeItem(v) {
  await del(`/api/playlists/${pl.value.id}/items/${v.id}`);
  data.value.videos = data.value.videos.filter((x) => x.id !== v.id);
  data.value.playlist.itemCount--;
}
function onDragStart(i) { dragIdx.value = i; }
async function onDrop(i) {
  if (dragIdx.value < 0 || dragIdx.value === i) return;
  const arr = [...data.value.videos];
  const [m] = arr.splice(dragIdx.value, 1);
  arr.splice(i, 0, m);
  data.value.videos = arr; dragIdx.value = -1;
  await put(`/api/playlists/${pl.value.id}/order`, { videoIds: arr.map((v) => v.id) });
}
watch(() => route.params.id, load);
onMounted(load);
</script>

<template>
  <div v-if="error" class="page"><EmptyState icon="lock" :title="error.status === 403 ? 'Нет доступа к плейлисту' : 'Плейлист не найден'" :text="error.message" /></div>
  <div v-else-if="data" class="page playlist-page">
    <aside class="pl-side panel brand">
      <div class="thumb mb-16"><img v-if="pl.thumbnailUrl" :src="pl.thumbnailUrl" alt="" /><div v-else class="thumb-empty" style="color:rgba(255,255,255,.6)"><Icon name="playlist" :size="40" /></div></div>
      <h2 style="font-size: 26px">{{ pl.kind === 'watch_later' ? 'Смотреть позже' : pl.title }}</h2>
      <div class="row gap-8 mt-8" style="opacity:.9; font-size: 13px"><ChannelAvatar :user="pl.owner" size="xs" /> {{ pl.owner?.displayName }}</div>
      <div class="small mt-8" style="opacity:.85">{{ VISIBILITY[pl.visibility]?.label }} • {{ pl.itemCount }} видео • {{ fmtDuration(totalDuration) }}<br>Обновлён {{ fmtDate(pl.updatedAt) }}</div>
      <p v-if="pl.description" class="small mt-8 pre" style="opacity:.9">{{ pl.description }}</p>
      <div class="row wrap mt-16">
        <router-link v-if="firstPlayable" :to="{ name: 'watch', params: { id: firstPlayable.shortId }, query: { list: pl.id } }" class="btn primary"><Icon name="play" :size="18" /> Смотреть все</router-link>
        <button class="btn" @click="share = true" aria-label="Поделиться плейлистом"><Icon name="share" :size="18" /></button>
        <button v-if="canEdit && pl.kind !== 'watch_later'" class="btn" @click="startEdit" aria-label="Изменить плейлист"><Icon name="edit" :size="18" /></button>
        <button v-if="canEdit && pl.kind !== 'watch_later'" class="btn" @click="remove" aria-label="Удалить плейлист"><Icon name="delete" :size="18" /></button>
      </div>
    </aside>
    <div class="pl-list">
      <EmptyState v-if="!data.videos.length" icon="playlist" title="Плейлист пуст" text="Добавляйте видео через кнопку «Сохранить» на странице просмотра." />
      <div v-for="(v, i) in data.videos" :key="v.id" class="pl-row" :class="{ unavailable: v.unavailable }" :draggable="canEdit" @dragstart="onDragStart(i)" @dragover.prevent @drop="onDrop(i)">
        <span class="pl-num">{{ i + 1 }}</span>
        <VideoCard v-if="v.shortId" :video="v" layout="compact" :playlist-id="pl.id" class="grow" />
        <div v-else class="grow pl-stub"><Icon name="lock" :size="18" /> Видео недоступно</div>
        <button v-if="canEdit" class="ibtn" title="Убрать из плейлиста" @click="removeItem(v)" aria-label="Убрать из плейлиста"><Icon name="close" :size="18" /></button>
      </div>
    </div>
    <Modal v-if="editing" title="Редактировать плейлист" @close="editing = false">
      <div class="col gap-16">
        <div class="field"><label>Название</label><input class="input" v-model="form.title" maxlength="150" /></div>
        <div class="field"><label>Описание</label><textarea class="textarea" v-model="form.description" maxlength="5000"></textarea></div>
        <div class="field"><label>Видимость</label><select class="select" v-model="form.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }} — {{ v.hint }}</option></select></div>
      </div>
      <template #footer><button class="btn" @click="editing = false">Отмена</button><button class="btn primary" @click="save">Сохранить</button></template>
    </Modal>
    <ShareDialog v-if="share" :url="'/playlist/' + pl.id" title="Поделиться плейлистом" @close="share = false" />
  </div>
  <div v-else class="page"><div class="loading-block"><div class="spin"></div></div></div>
</template>

<style>
.playlist-page { display: grid; grid-template-columns: 360px 1fr; gap: 24px; align-items: start; }
.pl-side { position: sticky; top: calc(var(--header-h) + 24px); }
.pl-row { display: flex; align-items: center; gap: 8px; padding: 6px; border-radius: var(--radius-sm); }
.pl-row:hover { background: var(--bg-2); }
.pl-row.unavailable { opacity: 0.5; }
.pl-stub { display: flex; align-items: center; gap: 8px; padding: 12px; color: var(--text-muted); font-size: 14px; }
.pl-num { width: 24px; text-align: center; color: var(--text-3); font-size: 13px; }
@media (max-width: 1024px) { .playlist-page { grid-template-columns: 1fr; } .pl-side { position: static; } }
</style>
