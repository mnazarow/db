<script setup>
import { computed } from 'vue';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';

const props = defineProps({ height: { type: Number, default: 30 }, light: { type: Boolean, default: false } });
const auth = useAuth();
const ui = useUi();
const custom = computed(() => {
  const c = auth.config;
  if (!c) return null;
  if ((ui.effectiveTheme === 'dark' || props.light) && c.logoDarkUrl) return c.logoDarkUrl;
  return c.logoUrl;
});
const useLight = computed(() => props.light || ui.effectiveTheme === 'dark');
</script>

<template>
  <span class="brand-logo" :class="{ light: useLight }" :style="{ height: height + 'px' }">
    <img v-if="custom" :src="custom" alt="" :style="{ height: height + 'px' }" />
    <template v-else>
      <img :src="$url('/logo.svg')" alt="Водокомфорт" :style="{ height: height + 'px' }" class="logo-img" />
      <span class="video-word" :style="{ fontSize: Math.round(height * 0.86) + 'px' }">Видео</span>
    </template>
  </span>
</template>

<style>
.brand-logo { display: inline-flex; align-items: center; gap: 10px; }
.brand-logo .video-word { font-family: var(--font-head); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--brand); line-height: 1; padding-left: 10px; border-left: 2px solid var(--brand-200); }
.brand-logo.light .logo-img { filter: brightness(0) invert(1); }
.brand-logo.light .video-word { color: #fff; border-left-color: rgba(255,255,255,0.4); }
:root[data-theme='dark'] .brand-logo .logo-img { filter: brightness(0) invert(1); }
</style>
