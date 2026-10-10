<script setup>
import { ref } from 'vue';
import { useAuth } from '../stores/auth.js';
import { get, post } from '../api.js';
import { timeAgo } from '../utils/format.js';
import Dropdown from './Dropdown.vue';

const auth = useAuth();
const items = ref([]);
const loading = ref(false);
const dd = ref(null);

async function load() {
  loading.value = true;
  try {
    const r = await get('/api/notifications?limit=12');
    items.value = r.notifications;
    auth.unread = r.unread;
  } finally { loading.value = false; }
}
async function readAll() {
  await post('/api/notifications/read', {});
  items.value = items.value.map((n) => ({ ...n, readAt: n.readAt || new Date().toISOString() }));
  auth.unread = 0;
}
async function open(n) {
  if (!n.readAt) { post('/api/notifications/read', { ids: [n.id] }).catch(() => {}); n.readAt = new Date().toISOString(); auth.unread = Math.max(0, auth.unread - 1); }
}
const ICON = { new_video: 'videocam', comment: 'comment', comment_reply: 'comment', comment_heart: 'heart', subscribe: 'personAdd', video_ready: 'check', video_failed: 'alertCircle', moderation: 'gavel', registration: 'accountClock', report: 'flag', live_started: 'live', account_approved: 'verified', announcement: 'campaign', subtitles_ready: 'subtitles', video_blocked: 'cancel', video_removed: 'delete', comment_held: 'comment', live_recording: 'movie', assignment: 'assignment', assignment_reminder: 'clock', ai_ready: 'sparkles', certificate: 'verified', live_reminder: 'bellRing', video_expired: 'clock', webinar_cohost: 'accounts', live_recording_ready: 'movie' };
</script>

<template>
  <Dropdown ref="dd" width="380px">
    <template #trigger>
      <button class="ibtn nm-btn" aria-label="Уведомления" @click="load">
        <Icon name="bell" />
        <span v-if="auth.unread" class="nm-badge">{{ auth.unread > 99 ? '99+' : auth.unread }}</span>
      </button>
    </template>
    <div class="nm-head" @click.stop>
      <span class="head" style="font-size: 20px">Уведомления</span>
      <button class="btn ghost sm" @click="readAll" v-if="items.some((n) => !n.readAt)">Прочитать все</button>
    </div>
    <div class="sep"></div>
    <div class="nm-list" @click.stop>
      <div v-if="loading && !items.length" class="loading-block"><div class="spin"></div></div>
      <div v-else-if="!items.length" class="empty" style="padding: 32px"><Icon class="ic" name="bell" :size="36" /><div>Пока нет уведомлений</div></div>
      <component v-for="n in items" :key="n.id" :is="n.link ? 'router-link' : 'div'" :to="n.link || undefined" class="nm-item" :class="{ unread: !n.readAt }" @click="open(n); dd?.close()">
        <div class="nm-ic"><img v-if="n.image && !n._noImg" :src="n.image" alt="" @error="n._noImg = true" /><Icon v-else :name="ICON[n.type] || 'info'" :size="20" /></div>
        <div class="grow">
          <div class="nm-title">{{ n.title }}</div>
          <div v-if="n.body" class="small muted clamp-2">{{ n.body }}</div>
          <div class="tiny faint">{{ timeAgo(n.createdAt) }}</div>
        </div>
      </component>
    </div>
    <div class="sep"></div>
    <router-link to="/notifications" class="item" style="justify-content: center">Все уведомления</router-link>
  </Dropdown>
</template>

<style>
.nm-btn { position: relative; }
.nm-badge { position: absolute; top: 2px; right: 0; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--danger); color: #fff; font-size: 11px; font-weight: 500; display: flex; align-items: center; justify-content: center; }
.nm-head { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px 4px; }
.nm-list { max-height: 60vh; overflow-y: auto; }
.nm-item { display: flex; gap: 12px; padding: 10px 12px; border-radius: var(--radius-sm); color: var(--text); cursor: pointer; }
.nm-item:hover { background: var(--bg-2); }
:root[data-theme='dark'] .nm-item:hover { background: var(--surface-2); }
.nm-item.unread { background: var(--brand-50); }
.nm-item.unread .nm-title { font-weight: 500; }
.nm-ic { width: 44px; height: 44px; border-radius: 10px; background: var(--brand-100); color: var(--brand); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
.nm-ic img { width: 100%; height: 100%; object-fit: cover; }
.nm-title { font-size: 14px; }
</style>
