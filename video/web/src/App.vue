<script setup>
import { computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useAuth } from './stores/auth.js';
import { useUi } from './stores/ui.js';
import * as ws from './ws.js';
import AppHeader from './components/AppHeader.vue';
import AppSidebar from './components/AppSidebar.vue';
import Toasts from './components/Toasts.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';
import MiniPlayer from './components/MiniPlayer.vue';

const route = useRoute();
const auth = useAuth();
const ui = useUi();

const isEmbed = computed(() => route.meta.embed);
const isPlain = computed(() => route.meta.plain);
const isWatch = computed(() => route.name === 'watch' || route.name === 'live-watch');

onMounted(() => {
  ui.setTheme(ui.theme);
  // Повышенная контрастность: личный выбор или значение по умолчанию из настроек портала
  // Хранилище может быть заблокировано политикой или приватным режимом: без try/catch
  // сюда прилетал SecurityError и остаток onMounted (в том числе WebSocket) не выполнялся
  let contrastSaved = null;
  try { contrastSaved = localStorage.getItem('cv:a11y:contrast'); } catch { contrastSaved = null; }
  ui.setHighContrast(ui.highContrast || (contrastSaved === null && auth.config?.highContrastDefault === true));
  ws.connect();
  ws.on('notification', (m) => { auth.unread = m.unread ?? auth.unread + 1; ui.toast(m.notification?.title || 'Новое уведомление', { type: 'info', action: m.notification?.link ? { label: 'Открыть', to: m.notification.link } : null }); });
  ws.on('settings.changed', () => auth.refreshConfig());
  ws.on('video.ready', (m) => ui.toast(`Видео обработано`, { type: 'success' }));
  ws.on('video.failed', (m) => ui.toast(`Ошибка обработки видео: ${m.error || ''}`, { type: 'error', timeout: 8000 }));
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (ui.theme === 'system') ui.setTheme('system'); });
});

watch(() => auth.user?.id, (id, prev) => { if (id !== prev) ws.reconnect(); });
watch(() => route.fullPath, () => ui.closeSidebar());
</script>

<template>
  <div v-if="isEmbed" class="embed-root">
    <router-view />
  </div>
  <div v-else-if="isPlain" class="plain-root">
    <router-view />
    <Toasts />
  </div>
  <div v-else class="app-root" :class="{ 'sidebar-mini': ui.sidebarCollapsed || isWatch, 'sidebar-open': ui.sidebarOpen }">
    <AppHeader />
    <AppSidebar :mini="ui.sidebarCollapsed || isWatch" />
    <div class="sidebar-backdrop" @click="ui.closeSidebar()" />
    <main class="app-main">
      <div v-if="auth.config?.announcement" class="announce" :class="auth.config.announcement.level">
        <Icon name="campaign" :size="20" /> <span>{{ auth.config.announcement.text }}</span>
      </div>
      <router-view v-slot="{ Component }">
        <component :is="Component" :key="route.name === 'watch' ? 'watch' : route.fullPath.split('?')[0]" />
      </router-view>
    </main>
    <MiniPlayer v-if="ui.miniPlayer && route.name !== 'watch'" />
    <Toasts />
    <ConfirmDialog />
  </div>
</template>

<style>
.app-root { min-height: 100vh; }
.app-main { padding-top: var(--header-h); margin-left: var(--sidebar-w); min-height: 100vh; transition: margin-left var(--t); }
.sidebar-mini .app-main { margin-left: var(--sidebar-w-mini); }
.sidebar-backdrop { display: none; }
.announce { display: flex; align-items: center; gap: 10px; padding: 10px var(--gutter); font-size: 14px; background: var(--info-bg); color: var(--text); }
.announce.warning { background: var(--warning-bg); color: var(--warning); }
.announce.danger { background: var(--danger-bg); color: var(--danger); }
.plain-root { min-height: 100vh; background: var(--bg-2); }
.embed-root { position: fixed; inset: 0; background: #000; }
@media (max-width: 1023px) {
  .app-main, .sidebar-mini .app-main { margin-left: 0; }
  .sidebar-backdrop { position: fixed; inset: 0; z-index: 80; background: var(--overlay); opacity: 0; pointer-events: none; transition: opacity var(--t); }
  .sidebar-open .sidebar-backdrop { display: block; opacity: 1; pointer-events: auto; }
}
</style>
