<script setup>
import { ref, onMounted, watch } from 'vue';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import { get } from '../api.js';
import ChannelAvatar from './ChannelAvatar.vue';

defineProps({ mini: Boolean });
const auth = useAuth();
const ui = useUi();
const subs = ref([]);
const categories = ref([]);
const showcases = ref([]);   // витрины, закреплённые в меню (1.9)
const showAllSubs = ref(false);

async function load() {
  try { categories.value = (await get('/api/feed/categories')).categories; } catch { /* ignore */ }
  try { showcases.value = (await get('/api/showcases?menu')).showcases; } catch { showcases.value = []; }
  if (auth.isActive) {
    try { subs.value = (await get('/api/me/subscriptions')).channels; } catch { subs.value = []; }
  } else subs.value = [];
}
onMounted(load);
watch(() => auth.user?.id, load);

const main = [
  { to: '/', icon: 'home', label: 'Главная', exact: true },
  { to: '/trending', icon: 'trending', label: 'Тренды', feature: 'trending.enabled' },
  { to: '/subscriptions', icon: 'subscriptions', label: 'Подписки', auth: true, feature: 'subscriptions.enabled' },
  { to: '/live', icon: 'live', label: 'Трансляции', feature: 'live.enabled' },
  { to: '/webinars', icon: 'campaign', label: 'Вебинары', feature: 'webinars.enabled' },
  { to: '/shorts', icon: 'shorts', label: 'Короткие', feature: 'shorts.enabled' },
  { to: '/courses', icon: 'school', label: 'Курсы', feature: 'courses.enabled' },
];
const library = [
  { to: '/assignments', icon: 'assignment', label: 'Назначено мне', badge: 'assignments', feature: 'assignments.enabled' },
  { to: '/library', icon: 'library', label: 'Библиотека' },
  { to: '/notes', icon: 'note', label: 'Мои заметки', feature: 'notes.enabled' },
  { to: '/certificates', icon: 'verified', label: 'Сертификаты', feature: 'certificates.enabled' },
  { to: '/history', icon: 'history', label: 'История', feature: 'history.enabled' },
  { to: '/watch-later', icon: 'watchLater', label: 'Смотреть позже', feature: 'playlists.enabled' },
  { to: '/offline', icon: 'download', label: 'Скачанные', feature: 'offline.enabled' },
  { to: '/liked', icon: 'thumbUp', label: 'Понравившиеся', feature: 'likes.enabled' },
];
/** Возможность включена? Пока настройки не загрузились — показываем пункт, чтобы меню не «прыгало». */
const on = (key) => !key || auth.config?.features?.[key] !== false;
</script>

<template>
  <aside class="app-sidebar" :class="{ mini }">
    <nav class="sb-scroll">
      <div class="sb-group" v-if="auth.isPortal">
        <!-- Раздел «Видео» портала документации: обратно к документам -->
        <a :href="auth.portalHome" class="sb-item"><Icon name="folder" /><span>Документы</span></a>
        <a v-if="auth.isStaff || auth.canUpload" :href="auth.portal?.moderation || auth.config?.portal?.moderation || '/moderation'" class="sb-item"><Icon name="gavel" /><span>Модерация документов</span></a>
      </div>
      <div class="sb-group">
        <router-link v-for="m in main.filter((x) => (!x.auth || auth.isActive) && on(x.feature))" :key="m.to" :to="m.to" class="sb-item" :class="{ 'router-link-active': $route.path === m.to }" :exact-active-class="'router-link-active'" active-class="">
          <Icon :name="m.icon" /><span>{{ m.label }}</span>
        </router-link>
      </div>
      <template v-if="auth.isActive">
        <div class="sb-group">
          <div class="sb-title">Библиотека</div>
          <router-link v-for="m in library.filter((x) => on(x.feature))" :key="m.to" :to="m.to" class="sb-item"><Icon :name="m.icon" /><span>{{ m.label }}</span><b v-if="m.badge === 'assignments' && auth.assignmentsPending" class="sb-badge">{{ auth.assignmentsPending }}</b></router-link>
        </div>
        <div class="sb-group" v-if="subs.length">
          <div class="sb-title">Подписки</div>
          <router-link v-for="c in (showAllSubs ? subs : subs.slice(0, 7))" :key="c.id" :to="`/@${c.handle}`" class="sb-item sub">
            <ChannelAvatar :user="c" size="xs" /><span>{{ c.displayName }}</span>
            <i v-if="c.isLive" class="dot live" title="В эфире"></i>
            <i v-else-if="c.newVideos" class="dot" title="Новые видео"></i>
          </router-link>
          <button v-if="subs.length > 7" class="sb-item" @click="showAllSubs = !showAllSubs"><Icon :name="showAllSubs ? 'chevronUp' : 'chevronDown'" /><span>{{ showAllSubs ? 'Свернуть' : `Ещё ${subs.length - 7}` }}</span></button>
        </div>
        <div class="sb-group">
          <div class="sb-title">Творчество</div>
          <router-link to="/studio" class="sb-item"><Icon name="videocam" /><span>Студия</span></router-link>
          <router-link v-if="auth.canUpload" to="/studio/upload" class="sb-item"><Icon name="upload" /><span>Загрузить видео</span></router-link>
          <router-link v-if="auth.canStream && auth.config?.liveEnabled" to="/studio/live" class="sb-item"><Icon name="broadcast" /><span>Начать эфир</span></router-link>
          <router-link v-if="auth.canStream && on('webinars.enabled')" to="/studio/webinars" class="sb-item"><Icon name="campaign" /><span>Мои вебинары</span></router-link>
          <router-link v-if="auth.canUpload" to="/studio/record" class="sb-item"><Icon name="screenShare" /><span>Записать экран</span></router-link>
          <router-link v-if="auth.canAssign && on('assignments.enabled')" to="/studio/assignments" class="sb-item"><Icon name="clipboardList" /><span>Обязательные просмотры</span></router-link>
        </div>
      </template>
      <div class="sb-group" v-if="showcases.length">
        <div class="sb-title">Подборки</div>
        <router-link v-for="s in showcases" :key="s.id" :to="`/hub/${s.slug}`" class="sb-item"><Icon name="apps" /><span>{{ s.title }}</span></router-link>
      </div>
      <div class="sb-group" v-if="categories.length">
        <div class="sb-title">Категории</div>
        <router-link v-for="c in categories" :key="c.id" :to="`/category/${c.slug}`" class="sb-item"><Icon :name="c.icon" /><span>{{ c.name }}</span></router-link>
      </div>
      <div class="sb-group" v-if="auth.isStaff">
        <div class="sb-title">Управление</div>
        <router-link to="/admin" class="sb-item"><Icon name="admin" /><span>Администрирование</span></router-link>
      </div>
      <div class="sb-group">
        <router-link to="/settings" class="sb-item" v-if="auth.user"><Icon name="settings" /><span>Настройки</span></router-link>
        <button class="sb-item" @click="ui.toggleTheme()"><Icon :name="ui.effectiveTheme === 'dark' ? 'sun' : 'moon'" /><span>{{ ui.effectiveTheme === 'dark' ? 'Светлая тема' : 'Тёмная тема' }}</span></button>
      </div>
      <div class="sb-foot">
        <span>{{ auth.config?.footerText }}</span>
        <a v-if="auth.config?.companyUrl" :href="auth.config.companyUrl" target="_blank" rel="noopener">{{ auth.config.companyUrl.replace(/^https?:\/\//, '') }}</a>
      </div>
    </nav>
  </aside>
</template>

<style>
.app-sidebar { position: fixed; top: var(--header-h); left: 0; bottom: 0; width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--line-2); z-index: 85; transition: width var(--t), transform var(--t); overflow: hidden; }
.app-sidebar.mini { width: var(--sidebar-w-mini); }
.sb-scroll { height: 100%; overflow-y: auto; padding: 10px 10px 24px; scrollbar-width: thin; }
.sb-group { padding: 4px 0 8px; border-bottom: 1px solid var(--line-2); margin-bottom: 8px; }
.sb-group:last-of-type { border-bottom: 0; }
.sb-title { padding: 10px 14px 6px; font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-3); font-family: var(--font-body); }
.sb-item { display: flex; align-items: center; gap: 16px; width: 100%; padding: 9px 14px; border-radius: var(--radius-sm); color: var(--text); font-size: 14px; font-weight: 400; text-align: left; position: relative; }
.sb-item .icon { color: var(--text-2); }
.sb-item:hover { background: var(--bg-2); }
:root[data-theme='dark'] .sb-item:hover { background: var(--surface-2); }
.sb-item.router-link-active { background: var(--brand-100); color: var(--brand); font-weight: 500; }
.sb-item.router-link-active .icon { color: var(--brand); }
.sb-item span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-item .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-400); margin-left: auto; flex-shrink: 0; }
.sb-item .dot.live { background: var(--danger); }
.sb-badge { margin-left: auto; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--brand); color: #fff; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.app-sidebar.mini .sb-badge { position: absolute; top: 4px; right: 6px; margin: 0; }
.sb-foot { padding: 12px 14px; font-size: 12px; color: var(--text-3); display: flex; flex-direction: column; gap: 4px; }
.app-sidebar.mini .sb-item { flex-direction: column; gap: 4px; padding: 10px 4px; font-size: 10px; }
.app-sidebar.mini .sb-item span { white-space: normal; text-align: center; line-height: 1.1; }
.app-sidebar.mini .sb-item .dot, .app-sidebar.mini .sb-title, .app-sidebar.mini .sb-foot, .app-sidebar.mini .sb-item.sub { display: none; }
@media (max-width: 1023px) {
  .app-sidebar, .app-sidebar.mini { width: var(--sidebar-w); transform: translateX(-100%); box-shadow: none; }
  .sidebar-open .app-sidebar { transform: none; box-shadow: var(--shadow-lg); }
  .app-sidebar.mini .sb-item { flex-direction: row; gap: 16px; padding: 9px 14px; font-size: 14px; }
  .app-sidebar.mini .sb-item span { white-space: nowrap; text-align: left; }
  .app-sidebar.mini .sb-item .dot, .app-sidebar.mini .sb-title, .app-sidebar.mini .sb-foot, .app-sidebar.mini .sb-item.sub { display: flex; }
}
</style>
