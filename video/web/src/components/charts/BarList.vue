<script setup>
// Горизонтальные полосы долей (источники трафика, устройства).
import { computed } from 'vue';
const props = defineProps({ data: { type: Object, default: () => ({}) }, labels: { type: Object, default: () => ({}) } });
const rows = computed(() => {
  const entries = Object.entries(props.data || {}).map(([k, v]) => ({ k, v: Number(v) || 0 })).sort((a, b) => b.v - a.v);
  const total = entries.reduce((a, e) => a + e.v, 0) || 1;
  return entries.map((e) => ({ ...e, pct: Math.round((e.v / total) * 100), label: props.labels[e.k] || e.k }));
});
</script>

<template>
  <div class="barlist">
    <div v-for="r in rows" :key="r.k" class="bl-row">
      <span class="bl-label">{{ r.label }}</span>
      <span class="bl-bar"><i :style="{ width: r.pct + '%' }"></i></span>
      <span class="bl-val">{{ r.v }} <span class="faint">({{ r.pct }}%)</span></span>
    </div>
    <div v-if="!rows.length" class="small muted">Нет данных</div>
  </div>
</template>

<style>
.barlist { display: flex; flex-direction: column; gap: 8px; }
.bl-row { display: grid; grid-template-columns: 140px 1fr 90px; gap: 10px; align-items: center; font-size: 13px; }
.bl-bar { height: 10px; background: var(--bg-3); border-radius: 5px; overflow: hidden; }
.bl-bar i { display: block; height: 100%; background: var(--brand-400); border-radius: 5px; }
.bl-val { text-align: right; font-variant-numeric: tabular-nums; }
</style>
