<script setup>
import { ref, onMounted } from 'vue';
import { get, post, del } from '../api.js';
import { useUi } from '../stores/ui.js';
import Modal from './Modal.vue';
import { VISIBILITY } from '../utils/format.js';

const props = defineProps({ videoId: { type: String, required: true } });
const emit = defineEmits(['close', 'changed']);
const ui = useUi();
const lists = ref([]);
const loading = ref(true);
const creating = ref(false);
const newTitle = ref('');
const newVis = ref('private');

async function load() {
  loading.value = true;
  try { lists.value = (await get(`/api/playlists/for-video/${props.videoId}`)).playlists; } finally { loading.value = false; }
}
async function toggle(p) {
  try {
    if (p.containsVideo) { await del(`/api/playlists/${p.id}/items/${props.videoId}`); p.containsVideo = false; p.itemCount--; ui.toast(`Удалено из «${p.kind === 'watch_later' ? 'Смотреть позже' : p.title}»`); }
    else { await post(`/api/playlists/${p.id}/items`, { videoId: props.videoId }); p.containsVideo = true; p.itemCount++; ui.toast(`Добавлено в «${p.kind === 'watch_later' ? 'Смотреть позже' : p.title}»`, { type: 'success' }); }
    emit('changed', p);
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function create() {
  if (!newTitle.value.trim()) return;
  try {
    await post('/api/playlists', { title: newTitle.value.trim(), visibility: newVis.value, videoId: props.videoId });
    newTitle.value = ''; creating.value = false;
    await load();
    ui.toast('Плейлист создан', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
onMounted(load);
</script>

<template>
  <Modal title="Сохранить в плейлист" narrow @close="$emit('close')">
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <div v-else class="col gap-4">
      <label v-for="p in lists" :key="p.id" class="pl-row">
        <input type="checkbox" :checked="p.containsVideo" @change="toggle(p)" />
        <span class="grow ellipsis">{{ p.kind === 'watch_later' ? 'Смотреть позже' : p.title }}</span>
        <Icon :name="VISIBILITY[p.visibility]?.icon" :size="16" class="faint" :title="VISIBILITY[p.visibility]?.label" />
      </label>
    </div>
    <div class="divider"></div>
    <div v-if="!creating"><button class="btn ghost sm" @click="creating = true"><Icon name="plus" :size="16" /> Новый плейлист</button></div>
    <div v-else class="col gap-8">
      <input class="input" v-model="newTitle" placeholder="Название плейлиста" maxlength="150" @keydown.enter="create" />
      <select class="select" v-model="newVis"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select>
      <div class="row"><button class="btn primary sm" @click="create">Создать</button><button class="btn ghost sm" @click="creating = false">Отмена</button></div>
    </div>
  </Modal>
</template>

<style>
.pl-row { display: flex; align-items: center; gap: 12px; padding: 8px 6px; border-radius: var(--radius-sm); cursor: pointer; }
.pl-row:hover { background: var(--bg-2); }
.pl-row input { width: 18px; height: 18px; accent-color: var(--brand-500); }
</style>
