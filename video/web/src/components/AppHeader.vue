<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import SearchBox from './SearchBox.vue';
import NotificationsMenu from './NotificationsMenu.vue';
import UserMenu from './UserMenu.vue';
import BrandLogo from './BrandLogo.vue';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
const mobileSearch = ref(false);
const canUpload = computed(() => auth.isActive && auth.canUpload);
</script>

<template>
  <header class="app-header">
    <div class="hl">
      <button class="ibtn" @click="ui.toggleSidebar()" aria-label="Меню"><Icon name="menu" /></button>
      <!-- В составе портала документации логотип ведёт на главную портала, а кнопка рядом — обратно на портал. -->
      <a v-if="auth.isPortal" :href="auth.portalHome" class="brand" :title="'На главную: ' + auth.portalName"><BrandLogo /></a>
      <router-link v-else to="/" class="brand"><BrandLogo /></router-link>
      <a v-if="auth.isPortal" :href="auth.portalHome" class="btn sm back-portal" :title="'Вернуться: ' + auth.portalName" :aria-label="'Вернуться: ' + auth.portalName"><Icon name="back" :size="18" /><span class="hide-mobile">{{ auth.portalName }}</span></a>
    </div>
    <div class="hc" :class="{ open: mobileSearch }">
      <SearchBox @close="mobileSearch = false" />
    </div>
    <div class="hr">
      <button class="ibtn only-mobile" @click="mobileSearch = !mobileSearch" aria-label="Поиск"><Icon name="search" /></button>
      <template v-if="auth.user">
        <router-link v-if="canUpload" to="/studio/upload" class="btn primary sm hide-mobile"><Icon name="upload" :size="18" /> Загрузить</router-link>
        <router-link v-if="canUpload" to="/studio/upload" class="ibtn only-mobile" aria-label="Загрузить"><Icon name="videoPlus" /></router-link>
        <NotificationsMenu />
        <UserMenu />
      </template>
      <template v-else>
        <button class="ibtn hide-mobile" @click="ui.toggleTheme()" :title="ui.effectiveTheme === 'dark' ? 'Светлая тема' : 'Тёмная тема'" :aria-label="ui.effectiveTheme === 'dark' ? 'Светлая тема' : 'Тёмная тема'"><Icon :name="ui.effectiveTheme === 'dark' ? 'sun' : 'moon'" /></button>
        <router-link :to="{ name: 'login', query: $route.fullPath !== '/' ? { next: $route.fullPath } : {} }" class="btn sm"><Icon name="account" :size="18" /> Войти</router-link>
      </template>
    </div>
  </header>
</template>

<style>
.app-header { position: fixed; top: 0; left: 0; right: 0; height: var(--header-h); z-index: 90; display: grid; grid-template-columns: minmax(200px, 1fr) minmax(0, 720px) minmax(200px, 1fr); align-items: center; padding: 0 16px; gap: 16px; background: var(--surface); border-bottom: 1px solid var(--line-2); }
.app-header .hl { display: flex; align-items: center; gap: 10px; }
.app-header .brand { display: flex; align-items: center; }
.app-header .back-portal { flex: 0 0 auto; white-space: nowrap; }
.app-header .hc { min-width: 0; }
.app-header .hr { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
.only-mobile { display: none !important; }
@media (max-width: 767px) {
  .app-header { grid-template-columns: minmax(0, 1fr) auto; padding: 0 8px; gap: 8px; }
  .app-header .hl, .app-header .brand { min-width: 0; overflow: hidden; }
  .app-header .back-portal { padding: 0 8px; }
  .app-header .brand-logo, .app-header .brand-logo img { height: 26px !important; }
  .app-header .brand-logo .video-word { font-size: 22px !important; }
  .app-header .hc { display: none; }
  .app-header .hc.open { display: block; position: absolute; left: 0; right: 0; top: var(--header-h); padding: 8px; background: var(--surface); border-bottom: 1px solid var(--line-2); }
  .hide-mobile { display: none !important; }
  .only-mobile { display: inline-flex !important; }
}
@media (max-width: 539px) {
  .app-header .brand-logo .video-word { display: none; }
}
</style>
