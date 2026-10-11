<script setup>
import { ref, watch } from 'vue';
import { del, patch } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';

const auth = useAuth();
const ui = useUi();
const q = ref('');
const list = usePaged('/api/me/history', { extraQuery: () => ({ q: q.value }) });
let t = null;
watch(q, () => { clearTimeout(t); t = setTimeout(() => list.load(), 300); });
async function clearAll() {
  if (!(await ui.ask({ title: 'Очистить историю просмотров?', message: 'Позиции просмотра и рекомендации на их основе будут сброшены.', okLabel: 'Очистить', danger: true }))) return;
  await del('/api/me/history'); list.load();
}
async function removeOne(v) { await del(`/api/me/history/${v.id}`); list.items.value = list.items.value.filter((x) => x.id !== v.id); }
async function togglePause() {
  const pause = !auth.user.prefs?.pauseHistory;
  const r = await patch('/api/me', { prefs: { pauseHistory: pause } });
  auth.setUser(r.user);
  ui.toast(pause ? 'История просмотров приостановлена' : 'История просмотров ведётся');
}
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div><h1>История просмотров</h1><div class="sub">{{ auth.user.prefs?.pauseHistory ? 'История приостановлена — новые просмотры не записываются' : 'Последние просмотренные видео' }}</div></div>
      <div class="actions">
        <input class="input" v-model="q" placeholder="Поиск в истории" style="width: 220px" />
        <button class="btn sm" @click="togglePause"><Icon :name="auth.user.prefs?.pauseHistory ? 'play' : 'pause'" :size="16" /> {{ auth.user.prefs?.pauseHistory ? 'Возобновить' : 'Приостановить' }}</button>
        <button class="btn sm danger" @click="clearAll"><Icon name="delete" :size="16" /> Очистить</button>
      </div>
    </div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" layout="list" empty-icon="history" empty-title="История пуста">
      <template #menu="{ video }"><button class="ibtn" title="Удалить из истории" @click="removeOne(video)" aria-label="Удалить из истории"><Icon name="close" :size="18" /></button></template>
    </VideoGrid>
    <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
  </div>
</template>
