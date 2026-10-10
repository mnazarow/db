<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useUi } from '../stores/ui.js';
import { post } from '../api.js';
import VideoPlayer from './player/VideoPlayer.vue';

const ui = useUi();
const router = useRouter();
const player = ref(null);
const v = ui.miniPlayer.video;

function expand() {
  const pos = player.value?.currentTime() || ui.miniPlayer.position || 0;
  const playing = player.value ? !player.value.isPaused() : false;
  ui.miniPlayer = { ...ui.miniPlayer, position: pos, playing, returning: true };
  router.push({ name: 'watch', params: { id: v.shortId }, query: { t: Math.floor(pos) } });
}
function close() { ui.closeMini(); }
async function onProgress(p) {
  try { await post(`/api/videos/${v.shortId}/progress`, { position: p.position, watchedDelta: p.delta, buckets: p.buckets, source: 'direct' }); } catch { /* ignore */ }
}
</script>

<template>
  <div class="mini-player">
    <VideoPlayer ref="player" :src="v.hlsUrl" :mp4="v.mp4Url" :poster="v.thumbnailUrl" :subtitles="v.subtitles || []" :chapters="v.chapters || []" :duration="v.duration" :start-at="ui.miniPlayer.position" :autoplay="ui.miniPlayer.playing !== false" compact :allow-theater="false" :allow-mini="false" @progress="onProgress" @ended="close">
      <template #overlay>
        <div class="mini-bar" @click.stop>
          <button class="ibtn sm" style="color:#fff" @click="expand" title="Развернуть" aria-label="Развернуть"><Icon name="openNew" :size="18" /></button>
          <span class="ellipsis mini-title" @click="expand">{{ v.title }}</span>
          <button class="ibtn sm" style="color:#fff" @click="close" title="Закрыть" aria-label="Закрыть"><Icon name="close" :size="18" /></button>
        </div>
      </template>
    </VideoPlayer>
  </div>
</template>

<style>
.mini-player { position: fixed; right: 20px; bottom: 20px; width: min(420px, calc(100vw - 32px)); z-index: 95; box-shadow: var(--shadow-lg); border-radius: var(--radius); overflow: hidden; }
.mini-bar { position: absolute; top: 0; left: 0; right: 0; display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent); color: #fff; font-size: 13px; }
.mini-title { flex: 1; cursor: pointer; }
</style>
