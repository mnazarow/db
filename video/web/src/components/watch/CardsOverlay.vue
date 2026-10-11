<script setup>
// Подсказки по таймкодам и конечная заставка автора поверх плеера (как на YouTube).
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { get } from '../../api.js';
import { fmtDuration } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null }, ended: Boolean });
const emit = defineEmits(['navigate']);
const router = useRouter();
const time = ref(0);
const dismissed = ref(new Set());
const expanded = ref(null);
const endItems = ref([]);
let timer = null;

const activeCard = computed(() => (props.video.cards || []).find((c) => time.value >= c.start && time.value < c.start + (c.duration || 15) && !dismissed.value.has(c.id)) || null);
const duration = computed(() => Number(props.video.duration) || 0);
const showEnd = computed(() => !!props.video.endScreen && endItems.value.length > 0 && duration.value > 0 && (props.ended || time.value >= duration.value - props.video.endScreen.seconds));

function tick() { const t = props.player?.currentTime?.(); if (Number.isFinite(t)) time.value = t; }
onMounted(() => { timer = setInterval(tick, 500); resolveEnd(); });
onBeforeUnmount(() => clearInterval(timer));
watch(() => props.video.id, () => { dismissed.value = new Set(); expanded.value = null; resolveEnd(); });

let endSeq = 0;
async function resolveEnd() {
  const seq = ++endSeq;
  const es = props.video.endScreen; endItems.value = [];
  if (!es) return;
  const items = [];
  for (const it of es.items) {
    try {
      if (it.type === 'video') { const v = (await get(`/api/videos/${it.target}`)).video; items.push({ ...it, title: it.title || v.title, thumb: v.thumbnailUrl, sub: v.owner?.displayName, to: `/watch/${v.shortId}`, dur: v.duration }); }
      else if (it.type === 'playlist') { const p = await get(`/api/playlists/${it.target}?limit=1`); items.push({ ...it, title: it.title || p.playlist.title, thumb: p.videos?.[0]?.thumbnailUrl, sub: `Плейлист · ${p.playlist.itemCount} видео`, to: `/playlist/${p.playlist.id}` }); }
      else if (it.type === 'latest') { const r = await get(`/api/channels/${props.video.owner.handle}/videos?limit=2`); const v = (r.videos || []).find((x) => x.id !== props.video.id); if (v) items.push({ ...it, title: it.title || v.title, thumb: v.thumbnailUrl, sub: v.owner?.displayName, to: `/watch/${v.shortId}`, dur: v.duration }); }
      else if (it.type === 'subscribe') items.push({ ...it, title: it.title || `Подписаться на ${props.video.owner?.displayName}`, thumb: props.video.owner?.avatarUrl, sub: 'Канал', to: `/@${props.video.owner?.handle}` });
      else if (it.type === 'url') items.push({ ...it, title: it.title || it.target, sub: new URL(it.target).host, href: it.target });
    } catch { /* пропускаем недоступные элементы */ }
  }
  if (seq === endSeq) endItems.value = items;
}
function open(card) {
  if (card.type === 'url') { window.open(card.target, '_blank', 'noopener'); return; }
  router.push(card.type === 'playlist' ? `/playlist/${card.target}` : `/watch/${card.target}`);
}
function openItem(it) { if (it.href) window.open(it.href, '_blank', 'noopener'); else router.push(it.to); }
</script>

<template>
  <div class="cards-layer">
    <transition name="fade">
      <div v-if="activeCard && !showEnd" class="card-hint" :class="{ open: expanded === activeCard.id }" @click.stop>
        <button class="card-hint-btn" :title="activeCard.title" @click="expanded = expanded === activeCard.id ? null : activeCard.id"><Icon name="info" :size="20" /><span v-if="expanded !== activeCard.id" class="card-hint-teaser ellipsis">{{ activeCard.title || 'Подсказка' }}</span></button>
        <div v-if="expanded === activeCard.id" class="card-hint-body">
          <div class="card-hint-title">{{ activeCard.title || 'Подсказка' }}</div>
          <div v-if="activeCard.text" class="tiny" style="opacity:.85">{{ activeCard.text }}</div>
          <div class="row gap-8 mt-8"><button class="btn primary sm" @click="open(activeCard)"><Icon :name="activeCard.type === 'url' ? 'openNew' : 'play'" :size="16" /> {{ activeCard.type === 'url' ? 'Открыть' : activeCard.type === 'playlist' ? 'К плейлисту' : 'Смотреть' }}</button><button class="btn sm" style="border-color:#fff;color:#fff" @click="dismissed.add(activeCard.id); expanded = null">Скрыть</button></div>
        </div>
      </div>
    </transition>
    <div v-if="showEnd" class="end-tiles" @click.stop>
      <button v-for="(it, i) in endItems" :key="i" class="end-tile" @click="openItem(it)">
        <div class="end-tile-thumb"><img v-if="it.thumb" :src="it.thumb" alt="" /><Icon v-else :name="it.type === 'subscribe' ? 'bell' : it.type === 'url' ? 'link' : 'play'" :size="28" /><span v-if="it.dur" class="end-tile-dur">{{ fmtDuration(it.dur) }}</span></div>
        <div class="end-tile-text"><div class="end-tile-title clamp-2">{{ it.title }}</div><div class="tiny" style="opacity:.8">{{ it.sub }}</div></div>
      </button>
    </div>
  </div>
</template>

<style>
.cards-layer { position: absolute; inset: 0; pointer-events: none; z-index: 4; }
.card-hint { position: absolute; top: 12px; right: 12px; pointer-events: auto; max-width: 320px; }
.card-hint-btn { display: flex; align-items: center; gap: 8px; background: rgba(0,0,0,.75); color: #fff; border: 0; border-radius: 20px; padding: 6px 12px 6px 8px; cursor: pointer; font: inherit; max-width: 300px; }
.card-hint-teaser { font-size: 13px; max-width: 220px; }
.card-hint-body { background: rgba(0,0,0,.85); color: #fff; border-radius: 12px; padding: 12px 14px; margin-top: 6px; }
.card-hint-title { font-weight: 600; font-size: 14px; }
.end-tiles { position: absolute; left: 0; right: 0; bottom: 64px; display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; padding: 0 16px; pointer-events: auto; }
.end-tile { display: flex; flex-direction: column; width: 200px; background: rgba(0,0,0,.8); color: #fff; border: 1px solid rgba(255,255,255,.2); border-radius: 10px; overflow: hidden; cursor: pointer; text-align: left; padding: 0; font: inherit; transition: transform .15s, border-color .15s; }
.end-tile:hover { transform: translateY(-3px); border-color: #fff; }
.end-tile-thumb { position: relative; aspect-ratio: 16/9; background: #222; display: flex; align-items: center; justify-content: center; }
.end-tile-thumb img { width: 100%; height: 100%; object-fit: cover; }
.end-tile-dur { position: absolute; right: 6px; bottom: 6px; background: rgba(0,0,0,.8); font-size: 11px; padding: 1px 5px; border-radius: 4px; }
.end-tile-text { padding: 8px 10px; }
.end-tile-title { font-size: 13px; font-weight: 600; line-height: 1.25; }
@media (max-width: 700px) { .end-tile { width: 140px; } .end-tile-text { padding: 6px 8px; } .end-tiles { bottom: 48px; gap: 8px; } }
</style>
