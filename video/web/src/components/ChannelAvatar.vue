<script setup>
import { computed } from 'vue';
import { initials, avatarColor } from '../utils/format.js';

const props = defineProps({ user: { type: Object, default: null }, size: { type: String, default: '' } });
const name = computed(() => props.user?.displayName || props.user?.display_name || '?');
const url = computed(() => props.user?.avatarUrl || props.user?.avatar_url || null);
const color = computed(() => avatarColor(props.user?.id || name.value));
</script>

<template>
  <span class="avatar" :class="size" :style="url ? {} : { background: color }" :title="name">
    <img v-if="url" :src="url" :alt="name" loading="lazy" />
    <template v-else>{{ initials(name) }}</template>
  </span>
</template>
