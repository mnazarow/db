<script setup>
import { computed } from 'vue';
const props = defineProps({ page: { type: Number, default: 1 }, total: { type: Number, default: 0 }, limit: { type: Number, default: 24 }, hasMore: { type: Boolean, default: undefined } });
const emit = defineEmits(['change']);
const pages = computed(() => Math.max(1, Math.ceil(props.total / props.limit)));
const list = computed(() => {
  const p = props.page, n = pages.value;
  const set = new Set([1, n, p - 1, p, p + 1, p - 2, p + 2].filter((x) => x >= 1 && x <= n));
  const arr = [...set].sort((a, b) => a - b);
  const out = [];
  for (let i = 0; i < arr.length; i++) { if (i && arr[i] - arr[i - 1] > 1) out.push('…'); out.push(arr[i]); }
  return out;
});
</script>

<template>
  <nav v-if="hasMore !== undefined ? (page > 1 || hasMore) : pages > 1" class="pagination" aria-label="Страницы">
    <button class="pg" :disabled="page <= 1" @click="$emit('change', page - 1)" aria-label="Предыдущая страница"><Icon name="chevronLeft" :size="20" /></button>
    <template v-if="hasMore === undefined">
      <template v-for="(p, i) in list" :key="i">
        <span v-if="p === '…'" class="pg">…</span>
        <button v-else class="pg" :class="{ active: p === page }" @click="$emit('change', p)">{{ p }}</button>
      </template>
    </template>
    <span v-else class="pg active">{{ page }}</span>
    <button class="pg" :disabled="hasMore !== undefined ? !hasMore : page >= pages" @click="$emit('change', page + 1)"><Icon name="chevronRight" :size="20" /></button>
  </nav>
</template>
