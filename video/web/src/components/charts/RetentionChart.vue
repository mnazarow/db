<script setup>
// Кривая удержания аудитории: доля зрителей, дошедших до каждого процента видео.
import { ref, computed } from 'vue';
import { fmtDuration } from '../../utils/format.js';
const props = defineProps({ data: { type: Array, default: () => [] }, duration: { type: Number, default: 0 }, height: { type: Number, default: 200 } });
const W = 800, PAD = { l: 40, r: 12, t: 12, b: 26 };
const hover = ref(-1);
const pts = computed(() => props.data.map((v, i) => ({ x: PAD.l + (i / 99) * (W - PAD.l - PAD.r), y: PAD.t + (props.height - PAD.t - PAD.b) * (1 - Math.min(100, v) / 100), v })));
const path = computed(() => pts.value.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));
const area = computed(() => pts.value.length ? `${path.value} L${W - PAD.r},${props.height - PAD.b} L${PAD.l},${props.height - PAD.b} Z` : '');
function onMove(e) { const rect = e.currentTarget.getBoundingClientRect(); const x = ((e.clientX - rect.left) / rect.width) * W; hover.value = Math.max(0, Math.min(99, Math.round(((x - PAD.l) / (W - PAD.l - PAD.r)) * 99))); }
const hasData = computed(() => props.data.some((v) => v > 0));
</script>

<template>
  <div class="chart" :style="{ height: height + 'px' }">
    <svg :viewBox="`0 0 ${W} ${height}`" preserveAspectRatio="none" @mousemove="onMove" @mouseleave="hover = -1">
      <g class="grid"><line v-for="f in [0, 0.5, 1]" :key="f" :x1="PAD.l" :x2="W - PAD.r" :y1="PAD.t + (height - PAD.t - PAD.b) * (1 - f)" :y2="PAD.t + (height - PAD.t - PAD.b) * (1 - f)" /></g>
      <g class="ylabels"><text v-for="f in [0, 0.5, 1]" :key="f" :x="PAD.l - 6" :y="PAD.t + (height - PAD.t - PAD.b) * (1 - f) + 4" text-anchor="end">{{ f * 100 }}%</text></g>
      <path v-if="hasData" :d="area" class="area" style="fill: var(--brand-500)" />
      <path v-if="hasData" :d="path" class="line" style="stroke: var(--brand-500)" />
      <g class="xlabels"><text v-for="f in [0, 0.25, 0.5, 0.75, 1]" :key="f" :x="PAD.l + f * (W - PAD.l - PAD.r)" :y="height - 8" text-anchor="middle">{{ fmtDuration(duration * f) }}</text></g>
      <g v-if="hover >= 0 && pts[hover]"><line :x1="pts[hover].x" :x2="pts[hover].x" :y1="PAD.t" :y2="height - PAD.b" class="cursor" /><circle :cx="pts[hover].x" :cy="pts[hover].y" r="5" style="fill: var(--brand-500)" /></g>
    </svg>
    <div v-if="hover >= 0 && pts[hover]" class="chart-tip" :style="{ left: (pts[hover].x / W * 100) + '%' }"><b>{{ Math.round(pts[hover].v) }}%</b> зрителей<br><span class="tiny">{{ fmtDuration(duration * hover / 100) }}</span></div>
    <div v-if="!hasData" class="chart-empty small muted">Пока недостаточно просмотров</div>
  </div>
</template>
