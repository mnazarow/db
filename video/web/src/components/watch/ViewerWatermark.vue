<script setup>
// Динамический водяной знак зрителя поверх видео: имя/почта и время, положение меняется каждые ~20 с.
import { ref, onMounted, onBeforeUnmount } from 'vue';
const props = defineProps({ text: { type: String, required: true } });
const pos = ref({ top: 12, left: 14 });
const stamp = ref('');
let timer = null;
function move() {
  pos.value = { top: 8 + Math.random() * 78, left: 6 + Math.random() * 70 };
  stamp.value = new Date().toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}
onMounted(() => { move(); timer = setInterval(move, 20000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
  <div class="wm" :style="{ top: pos.top + '%', left: pos.left + '%' }" aria-hidden="true"><span>{{ text }}</span><span class="wm-ts">{{ stamp }}</span></div>
</template>

<style>
.wm { position: absolute; z-index: 3; pointer-events: none; user-select: none; display: flex; flex-direction: column; gap: 2px; color: rgba(255,255,255,.45); text-shadow: 0 0 4px rgba(0,0,0,.7); font-size: 13px; font-weight: 500; letter-spacing: .02em; transition: top 1.2s ease, left 1.2s ease; mix-blend-mode: screen; }
.wm-ts { font-size: 11px; opacity: .85; }
</style>
