<script setup>
// Встраиваемый плеер. С параметром ?api=1 общается со страницей-родителем через postMessage
// (события play/pause/timeupdate/progress/ended, команды play/pause/seek/mute/unmute/setVolume/getState) —
// как IFrame Player API у YouTube и Player SDK у Vimeo/Mux. ?share=<токен> — гостевой доступ по защищённой ссылке (SCORM, LMS).
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { get, post } from '../api.js';
import VideoPlayer from '../components/player/VideoPlayer.vue';
import BrandLogo from '../components/BrandLogo.vue';

const route = useRoute();
const video = ref(null);
const error = ref(null);
const player = ref(null);
const startAt = Number(route.query.t) || 0;
const autoplay = route.query.autoplay === '1';
const api = route.query.api === '1' || route.query.scorm === '1';
let lastTime = -1; let lastProgressAt = 0; let maxPos = 0;

// Адрес встраивающей страницы: сообщения шлём именно ей, а не всем подряд ('*')
const parentOrigin = (() => {
  try {
    if (!document.referrer) return '*';
    const u = new URL(document.referrer);
    return /^https?:$/.test(u.protocol) ? u.origin : '*'; // file:// даёт origin "null" — postMessage с ним падает
  } catch { return '*'; }
})();
function send(event, extra = {}) {
  if (!api || window.parent === window) return;
  const v = video.value;
  try { window.parent.postMessage({ source: 'corpvideo', event, videoId: v?.id, shortId: v?.shortId, title: v?.title, duration: v?.duration, ...extra }, parentOrigin); } catch { /* ignore */ }
}
function stateOf(position) {
  const pos = Number.isFinite(position) ? position : (player.value?.currentTime() || 0);
  maxPos = Math.max(maxPos, pos);
  const d = video.value?.duration || 0;
  return { position: pos, maxPosition: maxPos, percent: d ? Math.min(100, Math.round((maxPos / d) * 100)) : 0, paused: player.value ? player.value.isPaused() : true };
}
async function onProgress(p) {
  try { await post(`/api/videos/${video.value.shortId}/progress`, { position: p.position, watchedDelta: p.delta, buckets: p.buckets, source: 'embed' }); } catch { /* ignore */ }
  send('progress', stateOf(p.position));
}
function onTime(t) {
  if (Math.abs(t - lastTime) >= 1) { lastTime = t; send('timeupdate', stateOf(t)); }
  if (Date.now() - lastProgressAt > 5000) { lastProgressAt = Date.now(); send('progress', stateOf(t)); }
}
function onMessage(e) {
  // Управлять плеером может только встраивающая страница (не соседний фрейм и не всплывающее окно)
  if (e.source !== window.parent) return;
  if (parentOrigin !== '*' && e.origin !== parentOrigin) return;
  const m = e.data || {};
  if (m.source !== 'corpvideo-host' || !player.value) return;
  const el = player.value.el?.();
  switch (m.method) {
    case 'play': player.value.play(); break;
    case 'pause': player.value.pause(); break;
    case 'seek': player.value.seekTo(Number(m.value) || 0); break;
    case 'mute': if (el) el.muted = true; break;
    case 'unmute': if (el) el.muted = false; break;
    case 'setVolume': if (el) el.volume = Math.max(0, Math.min(1, Number(m.value))); break;
    case 'getState': send('state', stateOf()); break;
    default: break;
  }
}
onMounted(async () => {
  try {
    if (route.query.share) { try { await get(`/api/share/${encodeURIComponent(String(route.query.share))}?embed=1`); } catch { /* ссылка недействительна — покажем обычную ошибку доступа */ } }
    video.value = (await get(`/api/videos/${route.params.id}`)).video;
    document.title = video.value.title;
    if (api) { window.addEventListener('message', onMessage); send('loaded', { hasQuiz: !!video.value.hasQuiz }); }
  } catch (e) { error.value = e; send('error', { status: e.status, message: e.message }); }
});
onBeforeUnmount(() => window.removeEventListener('message', onMessage));
</script>

<template>
  <div class="embed">
    <div v-if="error" class="embed-msg">
      <BrandLogo :height="28" light />
      <p>{{ error.status === 401 ? 'Для просмотра необходимо войти на портал' : error.message }}</p>
      <a v-if="error.status === 401" class="btn" style="border-color:#fff;color:#fff" :href="$url(`/login?next=/watch/${route.params.id}`)" target="_blank">Войти</a>
    </div>
    <template v-else-if="video">
      <VideoPlayer v-if="video.status === 'ready'" ref="player" :qoe-video-id="video.id" qoe-source="embed" :src="video.hlsUrl" :mp4="video.mp4Url" :poster="video.thumbnailUrl" :subtitles="video.subtitles" :chapters="video.chapters" :storyboard="video.storyboardUrl" :duration="video.duration" :start-at="startAt" :autoplay="autoplay" compact :allow-theater="false" :allow-mini="false" class="embed-player" @progress="onProgress" @timeupdate="onTime" @ready="send('ready', stateOf(0))" @play="send('play', stateOf())" @pause="send('pause', stateOf())" @ended="send('ended', { ...stateOf(video.duration), percent: 100 })" />
      <div v-else class="embed-msg"><p>Видео обрабатывается</p></div>
      <a class="embed-title" :href="$url(`/watch/${video.shortId}`)" target="_blank" rel="noopener"><BrandLogo :height="18" light /><span class="ellipsis">{{ video.title }}</span><Icon name="openNew" :size="16" /></a>
    </template>
  </div>
</template>

<style>
.embed { position: absolute; inset: 0; background: #000; }
.embed-player { border-radius: 0; height: 100%; aspect-ratio: auto; }
.embed-msg { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: #fff; text-align: center; padding: 24px; }
.embed-title { position: absolute; top: 0; left: 0; right: 0; display: flex; align-items: center; gap: 10px; padding: 10px 14px; color: #fff; font-weight: 500; background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent); z-index: 3; opacity: 0; transition: opacity var(--t); }
.embed:hover .embed-title { opacity: 1; }
</style>
