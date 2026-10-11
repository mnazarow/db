<script setup>
import BrandLogo from './BrandLogo.vue';
import { useAuth } from '../stores/auth.js';
defineProps({ title: { type: String, default: '' }, subtitle: { type: String, default: '' }, wide: Boolean });
const auth = useAuth();
</script>

<template>
  <div class="auth-wrap">
    <div class="auth-side">
      <router-link to="/"><BrandLogo :height="36" light /></router-link>
      <div class="auth-side-text">
        <h1>{{ auth.config?.siteName || 'Видео' }}</h1>
        <p>{{ auth.config?.description }}</p>
      </div>
      <div class="auth-side-foot">{{ auth.config?.footerText }}</div>
    </div>
    <div class="auth-main">
      <div class="auth-card" :class="{ wide }">
        <router-link to="/" class="auth-mobile-logo"><BrandLogo :height="28" /></router-link>
        <h2 v-if="title">{{ title }}</h2>
        <p v-if="subtitle" class="muted" style="margin: 4px 0 20px">{{ subtitle }}</p>
        <slot />
      </div>
    </div>
  </div>
</template>

<style>
.auth-wrap { min-height: 100vh; display: grid; grid-template-columns: minmax(320px, 42%) 1fr; }
.auth-side { background: linear-gradient(160deg, var(--brand-700), var(--brand-500) 60%, var(--brand-400)); color: #fff; padding: 40px 48px; display: flex; flex-direction: column; justify-content: space-between; }
.auth-side-text h1 { color: #fff; font-size: 56px; margin-bottom: 12px; }
.auth-side-text p { font-size: 17px; opacity: 0.9; max-width: 420px; line-height: 1.5; }
.auth-side-foot { font-size: 13px; opacity: 0.7; }
.auth-main { display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
.auth-card { width: 100%; max-width: 440px; background: var(--surface); border-radius: var(--radius-xl); padding: 36px; box-shadow: var(--shadow); }
.auth-card.wide { max-width: 560px; }
.auth-card h2 { font-size: 32px; }
.auth-mobile-logo { display: none; margin-bottom: 24px; }
@media (max-width: 860px) { .auth-wrap { grid-template-columns: 1fr; } .auth-side { display: none; } .auth-mobile-logo { display: inline-flex; } .auth-card { padding: 28px 22px; } }
</style>
