<script setup>
import { post, del } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { usePaged } from '../utils/paged.js';
import Pagination from '../components/Pagination.vue';
import EmptyState from '../components/EmptyState.vue';
import { timeAgo } from '../utils/format.js';
import ListError from '../components/ListError.vue';

const auth = useAuth();
const list = usePaged('/api/notifications', { key: 'notifications', limit: 30 });
async function readAll() { await post('/api/notifications/read', {}); auth.unread = 0; list.load(); }
async function open(n) { if (!n.readAt) { await post('/api/notifications/read', { ids: [n.id] }); n.readAt = new Date().toISOString(); auth.unread = Math.max(0, auth.unread - 1); } }
async function remove(n) { await del(`/api/notifications/${n.id}`); list.items.value = list.items.value.filter((x) => x.id !== n.id); }
</script>

<template>
  <div class="page narrow">
    <div class="page-head"><div><h1>Уведомления</h1></div><div class="actions"><button class="btn sm" @click="readAll">Прочитать все</button></div></div>
    <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
    <EmptyState v-else-if="!list.loading.value && !list.items.value.length" icon="bell" title="Уведомлений нет" />
    <div class="col gap-4">
      <div v-for="n in list.items.value" :key="n.id" class="notif-row" :class="{ unread: !n.readAt }">
        <div class="nm-ic"><img v-if="n.image && !n._noImg" :src="n.image" alt="" @error="n._noImg = true" /><Icon v-else name="bell" :size="20" /></div>
        <div class="grow" @click="open(n)">
          <component :is="n.link ? 'router-link' : 'div'" :to="n.link || undefined" class="notif-title">{{ n.title }}</component>
          <div v-if="n.body" class="small muted">{{ n.body }}</div>
          <div class="tiny faint">{{ timeAgo(n.createdAt) }}</div>
        </div>
        <button class="ibtn sm" title="Удалить" @click="remove(n)" aria-label="Удалить"><Icon name="close" :size="16" /></button>
      </div>
    </div>
    <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
  </div>
</template>

<style>
.notif-row { display: flex; gap: 14px; align-items: flex-start; padding: 12px; border-radius: var(--radius); }
.notif-row.unread { background: var(--brand-50); }
.notif-row:hover { background: var(--bg-2); }
.notif-title { color: var(--text); font-weight: 500; }
</style>
