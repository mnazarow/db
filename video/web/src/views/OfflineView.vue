<script setup>
// «Скачанные»: список видео, сохранённых на устройстве, и просмотр без сети.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import VideoPlayer from '../components/player/VideoPlayer.vue';
import EmptyState from '../components/EmptyState.vue';
import { listOffline, removeOffline, cleanupOffline, offlineTotalBytes, fmtBytes, offlineSupported } from '../utils/offline.js';
import { fmtDuration, fmtDate } from '../utils/format.js';

const auth = useAuth();
const ui = useUi();
const items = ref([]);
const playing = ref(null);
const online = ref(navigator.onLine);

const total = computed(() => offlineTotalBytes());
const limitMb = computed(() => Number(auth.config?.offlineMaxMb) || 0);
const usedPercent = computed(() => (limitMb.value ? Math.min(100, Math.round((total.value / (limitMb.value * 1024 * 1024)) * 100)) : 0));

async function refresh() {
  await cleanupOffline();
  items.value = listOffline();
}
async function remove(x) {
  if (!(await ui.ask({ title: 'Удалить с устройства?', message: `«${x.title}» перестанет открываться без сети.`, okLabel: 'Удалить', danger: true }))) return;
  await removeOffline(x.id);
  if (playing.value?.id === x.id) playing.value = null;
  await refresh();
}
function daysLeft(x) {
  if (!x.expiresAt) return null;
  return Math.max(0, Math.ceil((x.expiresAt - Date.now()) / 86400000));
}
const onOnline = () => { online.value = true; };
const onOffline = () => { online.value = false; };
onMounted(() => {
  refresh();
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  document.title = `Скачанные — ${auth.siteName}`;
});
onBeforeUnmount(() => { window.removeEventListener('online', onOnline); window.removeEventListener('offline', onOffline); });
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Скачанные</h1>
        <p class="muted">Эти видео лежат на устройстве и открываются без интернета — например, на объекте или в дороге.</p>
      </div>
      <div v-if="items.length" class="col" style="align-items:flex-end; gap:4px">
        <b>{{ fmtBytes(total) }}</b>
        <span class="tiny muted" v-if="limitMb">из {{ limitMb >= 1024 ? (limitMb / 1024).toFixed(1) + ' ГБ' : limitMb + ' МБ' }} · {{ usedPercent }}%</span>
      </div>
    </div>

    <div v-if="!online" class="panel soft mb-16 row gap-8" style="align-items:center">
      <Icon name="alert" :size="18" /><span class="small">Сети нет — доступны только скачанные видео.</span>
    </div>

    <EmptyState v-if="!offlineSupported()" icon="alertCircle" title="Браузер не поддерживает офлайн" text="Откройте портал в современном браузере или установите его как приложение." />
    <EmptyState v-else-if="!items.length" icon="download" title="Пока ничего не скачано"
      text="На странице видео нажмите «Скачать для офлайна» — ролик сохранится на устройстве и будет доступен без сети." />

    <div v-else class="col gap-16">
      <div v-if="playing" class="panel" style="padding:12px">
        <VideoPlayer :key="playing.id" :mp4="playing.mp4Url" :poster="playing.thumbnailUrl" :duration="playing.duration" :title="playing.title" :qoe="false" autoplay />
        <div class="row gap-8 mt-8" style="align-items:center">
          <b class="grow">{{ playing.title }}</b>
          <button class="btn sm soft" @click="playing = null"><Icon name="close" :size="16" /> Закрыть</button>
        </div>
      </div>

      <div class="panel table-scroll" style="padding:0">
        <table class="table">
          <thead><tr><th>Видео</th><th>Размер</th><th>Скачано</th><th>Доступно</th><th></th></tr></thead>
          <tbody>
            <tr v-for="x in items" :key="x.id">
              <td>
                <div class="row gap-8" style="align-items:center">
                  <img v-if="x.thumbnailUrl" :src="x.thumbnailUrl" alt="" style="width:96px; border-radius:6px" />
                  <div><b>{{ x.title }}</b><div class="tiny muted">{{ x.owner }}<template v-if="x.duration"> · {{ fmtDuration(x.duration) }}</template></div></div>
                </div>
              </td>
              <td class="nowrap">{{ fmtBytes(x.bytes) }}</td>
              <td class="nowrap tiny muted">{{ fmtDate(x.savedAt) }}</td>
              <td class="nowrap tiny muted"><template v-if="daysLeft(x) !== null">ещё {{ daysLeft(x) }} дн.</template><template v-else>без срока</template></td>
              <td class="nowrap" style="text-align:right">
                <button class="btn sm primary" @click="playing = x"><Icon name="play" :size="16" /> Смотреть</button>
                <button class="btn sm soft" @click="remove(x)" :aria-label="`Удалить «${x.title}» с устройства`"><Icon name="delete" :size="16" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="tiny muted">Скачанное хранится в браузере этого устройства: очистка данных сайта удалит его. Через {{ auth.config?.offlineDays || 30 }} дн. видео устаревает и удаляется само.</p>
    </div>
  </div>
</template>
