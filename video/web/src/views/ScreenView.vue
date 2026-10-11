<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../api.js';
import { fmtDuration } from '../utils/format.js';

// Витрина для телевизора в холле: плейлист крутится по кругу, интерфейса нет, вход не нужен.
const route = useRoute();
const items = ref([]);
const screen = ref({ name: '', subtitles: true, showTitle: true, muted: true });
const idx = ref(0);
const video = ref(null);
const error = ref('');
const started = ref(false);
const now = ref(new Date());
let refreshTimer = null;
let clockTimer = null;

const token = String(route.params.token || '');

async function loadList(keepPosition = false) {
  try {
    const r = await get(`/api/screens/${token}/playlist`);
    screen.value = r.screen;
    const before = items.value[idx.value]?.id;
    items.value = r.items;
    error.value = items.value.length ? '' : 'Нет видео для показа';
    if (keepPosition && before) {
      const i = items.value.findIndex((x) => x.id === before);
      idx.value = i >= 0 ? i : 0;
    }
  } catch (e) { error.value = e.message; }
}

function current() { return items.value[idx.value] || null; }

function playCurrent() {
  const it = current();
  const el = video.value;
  if (!it || !el) return;
  el.src = it.mp4Url || it.hlsUrl || '';
  el.load();
  el.play().catch((e) => {
    // Браузер запретил автозапуск — просим нажать «Запустить» ещё раз; иначе просто идём дальше по списку
    if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) started.value = false;
    else next();
  });
}

let lastSwitch = 0;
function next() {
  if (!items.value.length) return;
  // Если видео не воспроизводится (нет кодека, битый файл), не пролистываем весь список за секунду
  const now = Date.now();
  if (now - lastSwitch < 2500) { setTimeout(next, 2500); return; }
  lastSwitch = now;
  idx.value = (idx.value + 1) % items.value.length;
  playCurrent();
}

function start() {
  started.value = true;
  if (video.value) video.value.muted = screen.value.muted !== false;
  playCurrent();
  document.documentElement.requestFullscreen?.().catch(() => {});
}

let alive = true;   // экран закрыли во время загрузки — таймеры не заводим
onMounted(async () => {
  document.body.classList.add('screen-mode');
  await loadList();
  if (!alive) return;
  // Плейлист обновляется сам: администратор добавил видео — экран подхватит без перезапуска
  refreshTimer = setInterval(() => loadList(true), 10 * 60 * 1000);
  clockTimer = setInterval(() => { now.value = new Date(); }, 30000);
});
onBeforeUnmount(() => {
  alive = false;
  document.body.classList.remove('screen-mode');
  clearInterval(refreshTimer); clearInterval(clockTimer);
});
</script>

<template>
  <div class="screen-page">
    <video ref="video" class="screen-video" playsinline :poster="current()?.thumbnailUrl || ''" :muted="screen.muted !== false" @ended="next" @error="next">
      <track v-if="screen.subtitles && current()?.subtitlesUrl" kind="subtitles" srclang="ru" label="Русские" :src="current().subtitlesUrl" default />
    </video>
    <div v-if="!started" class="screen-start">
      <div class="col" style="align-items:center; gap:14px; text-align:center">
        <Icon name="tv" :size="48" />
        <div class="ss-name">{{ screen.name || 'Экран' }}</div>
        <div class="small" style="opacity:.8">{{ items.length }} видео в показе</div>
        <button class="btn primary lg" :disabled="!items.length" @click="start"><Icon name="play" :size="20" /> Запустить показ</button>
        <div v-if="error" class="small" style="color: var(--danger)">{{ error }}</div>
        <div class="tiny" style="opacity:.6; max-width: 420px">Показ идёт по кругу. Достаточно один раз нажать «Запустить» — дальше экран работает сам.</div>
      </div>
    </div>
    <div v-else-if="screen.showTitle !== false && current()" class="screen-bar">
      <div class="sb-title ellipsis">{{ current().title }}</div>
      <div class="sb-meta">{{ current().owner?.displayName || '' }} · {{ fmtDuration(current().duration) }} · {{ idx + 1 }} / {{ items.length }}</div>
    </div>
    <div v-if="started" class="screen-clock">{{ now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) }}</div>
  </div>
</template>

<style>
body.screen-mode { overflow: hidden; background: #000; }
.screen-page { position: fixed; inset: 0; background: #000; color: #fff; }
.screen-video { width: 100%; height: 100%; object-fit: contain; background: #000; }
.screen-start { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--brand-700), var(--brand-400)); }
.screen-start .ss-name { font-family: var(--font-head); font-size: 44px; text-transform: uppercase; letter-spacing: .04em; }
.screen-bar { position: absolute; left: 0; right: 0; bottom: 0; padding: 28px 40px; background: linear-gradient(transparent, rgba(0,0,0,.75)); pointer-events: none; }
.screen-bar .sb-title { font-family: var(--font-head); font-size: clamp(22px, 3.2vw, 44px); text-transform: uppercase; letter-spacing: .03em; }
.screen-bar .sb-meta { font-size: clamp(13px, 1.4vw, 20px); opacity: .85; margin-top: 4px; }
.screen-clock { position: absolute; top: 24px; right: 32px; font-family: var(--font-head); font-size: clamp(18px, 2vw, 32px); opacity: .8; pointer-events: none; }
</style>
