<script setup>
// Простой линейный график (SVG) с сеткой, областью под линией и подсказкой при наведении.
import { ref, computed } from 'vue';
const props = defineProps({
  data: { type: Array, default: () => [] },       // [{ x: 'YYYY-MM-DD', y: number }]
  height: { type: Number, default: 220 },
  color: { type: String, default: 'var(--brand-500)' },
  format: { type: Function, default: (v) => String(v) },
  label: { type: String, default: '' },
});
const W = 800, PAD = { l: 44, r: 12, t: 12, b: 28 };
const hover = ref(-1);
const maxY = computed(() => Math.max(1, ...props.data.map((d) => d.y)));
const nice = computed(() => { const m = maxY.value; const p = 10 ** Math.floor(Math.log10(m)); const n = Math.ceil(m / p) * p; return n || 1; });
const pts = computed(() => {
  const n = props.data.length;
  const innerW = W - PAD.l - PAD.r, innerH = props.height - PAD.t - PAD.b;
  return props.data.map((d, i) => ({ x: PAD.l + (n > 1 ? (i / (n - 1)) * innerW : innerW / 2), y: PAD.t + innerH - (d.y / nice.value) * innerH, d }));
});
const path = computed(() => pts.value.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));
const area = computed(() => pts.value.length ? `${path.value} L${pts.value[pts.value.length - 1].x.toFixed(1)},${props.height - PAD.b} L${pts.value[0].x.toFixed(1)},${props.height - PAD.b} Z` : '');
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => ({ v: nice.value * f, y: PAD.t + (props.height - PAD.t - PAD.b) * (1 - f) })));
const xLabels = computed(() => { const n = pts.value.length; if (!n) return []; const step = Math.max(1, Math.ceil(n / 8)); return pts.value.filter((_, i) => i % step === 0 || i === n - 1); });
function fmtX(x) { const d = new Date(x); return Number.isNaN(d.getTime()) ? x : d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }); }
function onMove(e) {
  const rect = e.currentTarget.getBoundingClientRect();
  const x = ((e.clientX - rect.left) / rect.width) * W;
  let best = -1, bd = Infinity;
  pts.value.forEach((p, i) => { const d = Math.abs(p.x - x); if (d < bd) { bd = d; best = i; } });
  hover.value = best;
}
</script>

<template>
  <div class="chart" :style="{ height: height + 'px' }">
    <svg :viewBox="`0 0 ${W} ${height}`" preserveAspectRatio="none" @mousemove="onMove" @mouseleave="hover = -1">
      <g class="grid"><line v-for="t in ticks" :key="t.v" :x1="PAD.l" :x2="W - PAD.r" :y1="t.y" :y2="t.y" /></g>
      <g class="ylabels"><text v-for="t in ticks" :key="t.v" :x="PAD.l - 6" :y="t.y + 4" text-anchor="end">{{ format(t.v) }}</text></g>
      <path v-if="area" :d="area" class="area" :style="{ fill: color }" />
      <path v-if="path" :d="path" class="line" :style="{ stroke: color }" />
      <g class="xlabels"><text v-for="p in xLabels" :key="p.d.x" :x="p.x" :y="height - 8" text-anchor="middle">{{ fmtX(p.d.x) }}</text></g>
      <g v-if="hover >= 0 && pts[hover]"><line :x1="pts[hover].x" :x2="pts[hover].x" :y1="PAD.t" :y2="height - PAD.b" class="cursor" /><circle :cx="pts[hover].x" :cy="pts[hover].y" r="5" :style="{ fill: color }" /></g>
    </svg>
    <div v-if="hover >= 0 && pts[hover]" class="chart-tip" :style="{ left: (pts[hover].x / W * 100) + '%' }"><b>{{ format(pts[hover].d.y) }}</b> {{ label }}<br><span class="tiny">{{ fmtX(pts[hover].d.x) }}</span></div>
    <div v-if="!data.length" class="chart-empty small muted">Нет данных за период</div>
  </div>
</template>

<style>
.chart { position: relative; width: 100%; }
.chart svg { width: 100%; height: 100%; overflow: visible; font-family: var(--font-body); }
.chart .grid line { stroke: var(--line-2); stroke-width: 1; }
.chart .ylabels text, .chart .xlabels text { fill: var(--text-3); font-size: 11px; }
.chart .area { opacity: 0.12; }
.chart .line { fill: none; stroke-width: 2.5; stroke-linejoin: round; stroke-linecap: round; }
.chart .cursor { stroke: var(--line-strong); stroke-dasharray: 3 3; }
.chart-tip { position: absolute; top: 4px; transform: translateX(-50%); background: var(--surface); border: 1px solid var(--line-2); border-radius: 8px; padding: 6px 10px; font-size: 12px; box-shadow: var(--shadow); pointer-events: none; white-space: nowrap; }
.chart-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
</style>
