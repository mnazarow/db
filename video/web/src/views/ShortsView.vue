<script setup>
// Короткие видео: вертикальная лента со свайпом (как TikTok / YouTube Shorts / VK Клипы) и автовоспроизведением.
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Hls from 'hls.js';
import { get, post, del } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import ShareDialog from '../components/ShareDialog.vue';
import EmptyState from '../components/EmptyState.vue';
import { fmtNumber } from '../utils/format.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const items = ref([]);
const page = ref(1);
const hasMore = ref(true);
const loading = ref(false);
const current = ref(0);
const muted = ref(true);
const share = ref(null);
const feed = ref(null);
const videoEls = new Map(); // index → HTMLVideoElement
const hlsMap = new Map();
let observer = null;
let progressTimers = new Map();

async function load() {
  if (loading.value || !hasMore.value) return;
  loading.value = true;
  try {
    const r = await get(`/api/feed/shorts?page=${page.value}&limit=12`);
    items.value.push(...r.videos.map((v) => ({ ...v, paused: false, progress: 0 })));
    hasMore.value = r.videos.length >= 12;
    page.value++;
    if (route.query.v && items.value.length) { const i = items.value.findIndex((v) => v.shortId === route.query.v); if (i >= 0) nextTick(() => scrollTo(i, 'auto')); }
  } finally { loading.value = false; }
}
function setVideoRef(el, i) { if (el) videoEls.set(i, el); else videoEls.delete(i); }
function attach(i) {
  const el = videoEls.get(i); const v = items.value[i];
  if (!el || !v || el.dataset.attached) return;
  el.dataset.attached = '1';
  if (v.hlsUrl && Hls.isSupported()) { const h = new Hls({ maxBufferLength: 20 }); h.loadSource(v.hlsUrl); h.attachMedia(el); hlsMap.set(i, h); }
  else el.src = v.mp4Url || v.hlsUrl;
}
function detach(i) { const h = hlsMap.get(i); if (h) { h.destroy(); hlsMap.delete(i); } const el = videoEls.get(i); if (el) { el.removeAttribute('src'); delete el.dataset.attached; } }
async function play(i) {
  for (const [j, el] of videoEls) { if (j !== i) { el.pause(); } }
  attach(i);
  const el = videoEls.get(i); if (!el) return;
  el.muted = muted.value;
  try { await el.play(); items.value[i].paused = false; } catch { items.value[i].paused = true; }
  // предзагрузка соседей
  if (i + 1 < items.value.length) attach(i + 1);
  for (const j of [...videoEls.keys()]) if (Math.abs(j - i) > 2) detach(j);
  router.replace({ query: { v: items.value[i].shortId } });
  if (i >= items.value.length - 3) load();
}
function scrollTo(i, behavior = 'smooth') { const el = feed.value?.children[i]; if (el) el.scrollIntoView({ behavior, block: 'start' }); }
function togglePlay(i) { const el = videoEls.get(i); if (!el) return; if (el.paused) { el.play().catch(() => {}); items.value[i].paused = false; } else { el.pause(); items.value[i].paused = true; } }
function toggleMute() { muted.value = !muted.value; for (const el of videoEls.values()) el.muted = muted.value; }
function onTime(i) { const el = videoEls.get(i); if (el && el.duration) items.value[i].progress = (el.currentTime / el.duration) * 100; }
function onEnded(i) {
  // засчитываем просмотр и переходим к следующему
  if (i + 1 < items.value.length) scrollTo(i + 1); else { const el = videoEls.get(i); if (el) { el.currentTime = 0; el.play().catch(() => {}); } }
}
async function sendProgress(i) {
  const el = videoEls.get(i); const v = items.value[i]; if (!el || !v || !auth.user && false) return;
  try { await post(`/api/videos/${v.shortId}/progress`, { position: el.currentTime, watchedDelta: 5, buckets: [Math.min(99, Math.floor((el.currentTime / (el.duration || 1)) * 100))], source: 'home' }); } catch { /* ignore */ }
}
async function like(v) {
  if (!auth.isActive) return ui.toast('Войдите, чтобы ставить оценки');
  try { const r = await post(`/api/videos/${v.shortId}/like`, { value: v.liked ? 0 : 1 }); v.liked = !v.liked; v.likeCount = r.likeCount; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function subscribe(v) {
  if (!auth.isActive) return ui.toast('Войдите, чтобы подписаться');
  try { const r = v.subscribed ? await del(`/api/channels/${v.owner.handle}/subscribe`) : await post(`/api/channels/${v.owner.handle}/subscribe`, {}); v.subscribed = r.subscribed ?? !v.subscribed; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function onKey(e) {
  if (e.target.closest('input, textarea') || document.querySelector('.modal-backdrop')) return;
  if (e.key === 'ArrowDown' || e.key === 'j') { e.preventDefault(); scrollTo(Math.min(items.value.length - 1, current.value + 1)); }
  else if (e.key === 'ArrowUp' || e.key === 'k') { e.preventDefault(); scrollTo(Math.max(0, current.value - 1)); }
  else if (e.key === ' ') { e.preventDefault(); togglePlay(current.value); }
  else if (e.key === 'm') toggleMute();
}
let alive = true;   // ушли со страницы, пока грузилась лента, — наблюдатель и клавиши не заводим
onMounted(async () => {
  await load();
  await nextTick();
  if (!alive) return;
  observer = new IntersectionObserver((entries) => {
    for (const en of entries) {
      const i = Number(en.target.dataset.index);
      if (en.isIntersecting && en.intersectionRatio >= 0.6) { current.value = i; play(i); clearInterval(progressTimers.get(i)); progressTimers.set(i, setInterval(() => sendProgress(i), 5000)); }
      else { const el = videoEls.get(i); if (el) el.pause(); clearInterval(progressTimers.get(i)); progressTimers.delete(i); }
    }
  }, { root: feed.value, threshold: [0.6] });
  watch(() => items.value.length, () => nextTick(() => { for (const el of feed.value?.children || []) observer.observe(el); }), { immediate: true });
  document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => { alive = false; observer?.disconnect(); for (const t of progressTimers.values()) clearInterval(t); for (const i of [...hlsMap.keys()]) detach(i); document.removeEventListener('keydown', onKey); });
const canScroll = computed(() => items.value.length > 1);
</script>

<template>
  <div class="shorts-page">
    <EmptyState v-if="!loading && !items.length" icon="shorts" title="Коротких видео пока нет" text="Вертикальные ролики короче 60 секунд автоматически попадают сюда." />
    <div v-else class="shorts-wrap">
      <div ref="feed" class="shorts-feed">
        <section v-for="(v, i) in items" :key="v.id" class="short" :data-index="i">
          <div class="short-stage" @click="togglePlay(i)">
            <video :ref="(el) => setVideoRef(el, i)" playsinline loop :poster="v.thumbnailUrl" preload="none" @timeupdate="onTime(i)" @ended="onEnded(i)"></video>
            <div v-if="v.paused" class="short-paused"><Icon name="play" :size="56" /></div>
            <div class="short-progress"><i :style="{ width: v.progress + '%' }"></i></div>
            <div class="short-info" @click.stop>
              <router-link :to="`/@${v.owner.handle}`" class="row gap-8" style="color:#fff"><ChannelAvatar :user="v.owner" size="sm" /><b>{{ v.owner.displayName }}</b><button v-if="auth.user && v.owner.id !== auth.user.id" class="btn sm short-sub" :class="{ on: v.subscribed }" @click.prevent="subscribe(v)">{{ v.subscribed ? 'Вы подписаны' : 'Подписаться' }}</button></router-link>
              <router-link :to="`/watch/${v.shortId}`" class="short-title clamp-2">{{ v.title }}</router-link>
              <div v-if="v.tags?.length" class="tiny" style="opacity:.85"><span v-for="t in v.tags.slice(0, 4)" :key="t">#{{ t }} </span></div>
            </div>
          </div>
          <div class="short-actions" @click.stop>
            <button class="sa" :class="{ on: v.liked }" @click="like(v)"><Icon :name="v.liked ? 'thumbUpFill' : 'thumbUp'" /><span>{{ fmtNumber(v.likeCount) }}</span></button>
            <router-link class="sa" :to="`/watch/${v.shortId}#comments`"><Icon name="comment" /><span>{{ fmtNumber(v.commentCount) }}</span></router-link>
            <button class="sa" @click="share = v"><Icon name="share" /><span>Поделиться</span></button>
            <button class="sa" @click="toggleMute"><Icon :name="muted ? 'volumeOff' : 'volumeHigh'" /><span>{{ muted ? 'Звук' : 'Тихо' }}</span></button>
          </div>
        </section>
        <div v-if="loading" class="short" style="display:flex;align-items:center;justify-content:center"><div class="spin"></div></div>
      </div>
      <div v-if="canScroll" class="shorts-nav no-mobile">
        <button class="ibtn soft-round" :disabled="current === 0" @click="scrollTo(current - 1)" title="Предыдущее (↑)" aria-label="Предыдущее (↑)"><Icon name="chevronUp" /></button>
        <button class="ibtn soft-round" :disabled="current >= items.length - 1" @click="scrollTo(current + 1)" title="Следующее (↓)"><Icon name="chevronDown" /></button>
      </div>
    </div>
    <ShareDialog v-if="share" :video="share" :url="`/watch/${share.shortId}`" @close="share = null" />
  </div>
</template>

<style>
.shorts-page { height: calc(100vh - var(--header-h)); overflow: hidden; }
.shorts-wrap { position: relative; height: 100%; display: flex; justify-content: center; gap: 16px; }
.shorts-feed { height: 100%; overflow-y: auto; scroll-snap-type: y mandatory; scrollbar-width: none; width: min(100%, 560px); padding: 12px 0; }
.shorts-feed::-webkit-scrollbar { display: none; }
.short { height: calc(100vh - var(--header-h) - 24px); scroll-snap-align: start; scroll-snap-stop: always; display: flex; gap: 12px; align-items: flex-end; margin-bottom: 12px; }
.short-stage { position: relative; flex: 1; height: 100%; border-radius: var(--radius-xl); overflow: hidden; background: #000; cursor: pointer; }
.short-stage video { width: 100%; height: 100%; object-fit: cover; }
.short-paused { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; background: rgba(0,0,0,.25); }
.short-progress { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: rgba(255,255,255,.25); }
.short-progress i { display: block; height: 100%; background: #fff; }
.short-info { position: absolute; left: 0; right: 0; bottom: 0; padding: 16px 16px 20px; color: #fff; background: linear-gradient(to top, rgba(0,0,0,.75), transparent); display: flex; flex-direction: column; gap: 6px; }
.short-title { color: #fff; font-weight: 500; font-size: 15px; }
.short-sub { height: 28px; padding: 0 10px; border-color: #fff; color: #fff; background: transparent; margin-left: 6px; }
.short-sub.on { background: rgba(255,255,255,.25); }
.short-actions { display: flex; flex-direction: column; gap: 14px; padding-bottom: 12px; }
.sa { display: flex; flex-direction: column; align-items: center; gap: 2px; width: 56px; color: var(--text); font-size: 11px; background: none; border: 0; cursor: pointer; }
.sa .icon { width: 48px; height: 48px; padding: 12px; border-radius: 50%; background: var(--bg-3); }
.sa.on .icon { color: var(--brand); background: var(--brand-100); }
.shorts-nav { position: absolute; right: 24px; top: 50%; transform: translateY(-50%); display: flex; flex-direction: column; gap: 12px; }
@media (max-width: 767px) {
  .shorts-page { height: calc(100vh - var(--header-h)); }
  .shorts-feed { width: 100%; padding: 0; }
  .short { height: calc(100vh - var(--header-h)); margin: 0; gap: 0; }
  .short-stage { border-radius: 0; }
  .short-actions { position: absolute; right: 8px; bottom: 90px; z-index: 2; }
  .sa { color: #fff; } .sa .icon { background: rgba(0,0,0,.35); }
  .no-mobile { display: none; }
}
</style>
