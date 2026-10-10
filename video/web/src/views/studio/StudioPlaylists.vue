<script setup>
import { ref, onMounted } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import PlaylistCard from '../../components/PlaylistCard.vue';
import Modal from '../../components/Modal.vue';
import EmptyState from '../../components/EmptyState.vue';
import { VISIBILITY } from '../../utils/format.js';

const ui = useUi();
const playlists = ref([]);
const creating = ref(false);
const form = ref({ title: '', visibility: 'internal', description: '' });
async function load() { playlists.value = (await get('/api/playlists')).playlists; }
onMounted(load);
async function create() { if (!form.value.title.trim()) return; try { await post('/api/playlists', form.value); creating.value = false; form.value = { title: '', visibility: 'internal', description: '' }; await load(); ui.toast('Плейлист создан', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Плейлисты</h1><div class="sub">Собирайте видео в подборки: курсы, серии инструкций, мероприятия</div></div><div class="actions"><button class="btn primary" @click="creating = true"><Icon name="plus" :size="18" /> Создать</button></div></div>
    <EmptyState v-if="!playlists.length" icon="playlist" title="Плейлистов нет" />
    <div v-else class="video-grid"><PlaylistCard v-for="p in playlists" :key="p.id" :playlist="p" :show-owner="false" /></div>
    <Modal v-if="creating" title="Новый плейлист" narrow @close="creating = false">
      <div class="col gap-16"><div class="field"><label>Название</label><input class="input" v-model="form.title" maxlength="150" @keydown.enter="create" /></div><div class="field"><label>Видимость</label><select class="select" v-model="form.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select></div><div class="field"><label>Описание</label><textarea class="textarea" v-model="form.description" style="min-height:72px"></textarea></div></div>
      <template #footer><button class="btn" @click="creating = false">Отмена</button><button class="btn primary" @click="create">Создать</button></template>
    </Modal>
  </div>
</template>
