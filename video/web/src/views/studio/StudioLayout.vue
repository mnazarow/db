<script setup>
import { useAuth } from '../../stores/auth.js';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
const auth = useAuth();
const NAV = [
  { to: '/studio', label: 'Обзор', icon: 'dashboard', exact: true },
  { to: '/studio/videos', label: 'Мои видео', icon: 'movie' },
  { to: '/studio/upload', label: 'Загрузить', icon: 'upload', upload: true },
  { to: '/studio/record', label: 'Записать экран', icon: 'screenShare', upload: true },
  { to: '/studio/live', label: 'Трансляции', icon: 'broadcast', live: true, feature: 'live.enabled' },
  { to: '/studio/webinars', label: 'Мои вебинары', icon: 'campaign', live: true, feature: 'webinars.enabled' },
  { to: '/studio/assignments', label: 'Обязательные просмотры', icon: 'clipboardList', assign: true, feature: 'assignments.enabled' },
  { to: '/studio/courses', label: 'Курсы', icon: 'school', assign: true, feature: 'courses.enabled' },
  { to: '/studio/reviews', label: 'Согласования', icon: 'checkAll', feature: 'review.enabled' },
  { to: '/studio/calendar', label: 'Календарь', icon: 'calendar', feature: 'calendar.enabled' },
  { to: '/studio/playlists', label: 'Плейлисты', icon: 'playlist', feature: 'playlists.enabled' },
  { to: '/studio/comments', label: 'Комментарии', icon: 'comment', feature: 'comments.enabled' },
  { to: '/studio/analytics', label: 'Аналитика', icon: 'analytics' },
  { to: '/studio/channel', label: 'Оформление канала', icon: 'palette' },
];
/** Возможность включена? До загрузки настроек пункт показываем. */
const on = (key) => !key || auth.config?.features?.[key] !== false;
</script>

<template>
  <div class="page studio">
    <div class="layout-with-nav">
      <div>
        <div class="studio-me">
          <ChannelAvatar :user="auth.user" size="lg" />
          <div style="min-width:0"><div class="ellipsis" style="font-weight:500">{{ auth.user.displayName }}</div><router-link :to="`/@${auth.user.handle}`" class="small">Открыть канал →</router-link></div>
        </div>
        <nav class="side-nav">
          <template v-for="n in NAV" :key="n.to">
            <router-link v-if="(!n.upload || auth.canUpload) && (!n.live || auth.canStream) && (!n.assign || auth.canAssign) && on(n.feature)" :to="n.to" :class="{ 'router-link-active': n.exact ? $route.path === n.to : $route.path.startsWith(n.to) }" active-class=""><Icon :name="n.icon" :size="20" /> {{ n.label }}</router-link>
          </template>
        </nav>
      </div>
      <div class="studio-content"><router-view /></div>
    </div>
  </div>
</template>

<style>
.studio-me { display: flex; gap: 12px; align-items: center; padding: 8px 12px 16px; }
.studio-content { min-width: 0; }
</style>
