<script setup>
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import Dropdown from './Dropdown.vue';
import ChannelAvatar from './ChannelAvatar.vue';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
async function logout() {
  await auth.logout();
  if (auth.isPortal) return; // браузер уходит на страницу выхода портала
  ui.toast('Вы вышли из системы');
  router.push('/');
}
</script>

<template>
  <Dropdown width="260px">
    <template #trigger>
      <button class="ibtn" aria-label="Меню пользователя"><ChannelAvatar :user="auth.user" size="sm" /></button>
    </template>
    <div class="um-head">
      <ChannelAvatar :user="auth.user" size="lg" />
      <div class="grow">
        <div class="um-name ellipsis">{{ auth.user.displayName }}</div>
        <div class="small muted ellipsis">@{{ auth.user.handle }}</div>
        <span v-if="auth.roleLabel" class="badge brand" style="margin-top: 4px">{{ auth.roleLabel }}</span>
      </div>
    </div>
    <div class="sep"></div>
    <router-link :to="`/@${auth.user.handle}`" class="item"><Icon class="ic" name="account" :size="20" /> Мой канал</router-link>
    <router-link v-if="auth.isActive" to="/studio" class="item"><Icon class="ic" name="videocam" :size="20" /> Студия</router-link>
    <router-link to="/settings" class="item"><Icon class="ic" name="settings" :size="20" /> Настройки</router-link>
    <router-link v-if="auth.isStaff" to="/admin" class="item"><Icon class="ic" name="admin" :size="20" /> Администрирование</router-link>
    <a v-if="auth.isPortal" :href="auth.portalHome" class="item"><Icon class="ic" name="folder" :size="20" /> {{ auth.portalName }}</a>
    <div class="sep"></div>
    <button class="item" @click.stop="ui.toggleTheme()"><Icon class="ic" :name="ui.effectiveTheme === 'dark' ? 'sun' : 'moon'" :size="20" /> {{ ui.effectiveTheme === 'dark' ? 'Светлая тема' : 'Тёмная тема' }}</button>
    <button v-if="ui.installPrompt" class="item" @click="ui.installApp().then((ok) => ok && ui.toast('Приложение установлено', { type: 'success' }))"><Icon class="ic" name="install" :size="20" /> Установить приложение</button>
    <button class="item" @click="logout"><Icon class="ic" name="logout" :size="20" /> Выйти</button>
  </Dropdown>
</template>

<style>
.um-head { display: flex; gap: 12px; align-items: center; padding: 10px 12px; }
.um-name { font-weight: 500; font-size: 15px; }
</style>
