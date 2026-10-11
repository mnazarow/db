<script setup>
import { ref, onMounted } from 'vue';
import { get, post } from '../api.js';
import { useUi } from '../stores/ui.js';
import VideoCard from '../components/VideoCard.vue';
import PlaylistCard from '../components/PlaylistCard.vue';
import Modal from '../components/Modal.vue';
import { VISIBILITY } from '../utils/format.js';

const ui = useUi();
const history = ref([]);
const cont = ref([]);
const liked = ref([]);
const playlists = ref([]);
const creating = ref(false);
const form = ref({ title: '', visibility: 'private', description: '' });

onMounted(async () => {
  const [h, c, l, p] = await Promise.all([get('/api/me/history?limit=8'), get('/api/me/continue'), get('/api/me/liked?limit=8'), get('/api/playlists')]);
  history.value = h.videos; cont.value = c.videos; liked.value = l.videos; playlists.value = p.playlists;
});
async function create() {
  if (!form.value.title.trim()) return;
  try { await post('/api/playlists', form.value); playlists.value = (await get('/api/playlists')).playlists; creating.value = false; form.value = { title: '', visibility: 'private', description: '' }; ui.toast('Плейлист создан', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Библиотека</h1><div class="sub">История, сохранённые видео и плейлисты</div></div></div>
    <section v-if="cont.length" class="home-section">
      <div class="section-title"><h2>Продолжить просмотр</h2></div>
      <div class="video-grid"><VideoCard v-for="v in cont" :key="v.id" :video="v" /></div>
    </section>
    <section class="home-section">
      <div class="section-title"><h2>История</h2><router-link to="/history" class="link">Вся история <Icon name="chevronRight" :size="14" /></router-link></div>
      <div v-if="history.length" class="video-grid"><VideoCard v-for="v in history" :key="v.id" :video="v" /></div>
      <p v-else class="muted">Вы ещё ничего не смотрели.</p>
    </section>
    <section class="home-section">
      <div class="section-title"><h2>Плейлисты</h2><button class="btn sm" style="margin-left:auto" @click="creating = true"><Icon name="plus" :size="16" /> Создать</button></div>
      <div class="video-grid"><PlaylistCard v-for="p in playlists" :key="p.id" :playlist="p" :show-owner="false" /></div>
    </section>
    <section class="home-section">
      <div class="section-title"><h2>Понравившиеся</h2><router-link to="/liked" class="link">Все <Icon name="chevronRight" :size="14" /></router-link></div>
      <div v-if="liked.length" class="video-grid"><VideoCard v-for="v in liked" :key="v.id" :video="v" /></div>
      <p v-else class="muted">Отмечайте видео «Нравится» — они появятся здесь.</p>
    </section>
    <Modal v-if="creating" title="Новый плейлист" narrow @close="creating = false">
      <div class="col gap-16">
        <div class="field"><label>Название</label><input class="input" v-model="form.title" maxlength="150" @keydown.enter="create" /></div>
        <div class="field"><label>Видимость</label><select class="select" v-model="form.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select></div>
        <div class="field"><label>Описание</label><textarea class="textarea" v-model="form.description" style="min-height: 72px"></textarea></div>
      </div>
      <template #footer><button class="btn" @click="creating = false">Отмена</button><button class="btn primary" @click="create">Создать</button></template>
    </Modal>
  </div>
</template>
