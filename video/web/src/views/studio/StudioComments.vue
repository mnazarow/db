<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { post, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { usePaged } from '../../utils/paged.js';
import Pagination from '../../components/Pagination.vue';
import EmptyState from '../../components/EmptyState.vue';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
import { timeAgo } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const status = ref(String(route.query.status || ''));
const list = usePaged('/api/studio/comments', { key: 'comments', limit: 30, extraQuery: () => ({ status: status.value, videoId: route.query.videoId }) });
watch(status, (s) => router.replace({ query: { ...route.query, status: s || undefined, page: undefined } }));
async function moderate(c, action) { await post(`/api/comments/${c.id}/moderate`, { action }); c.status = action === 'approve' ? 'visible' : 'hidden'; ui.toast(action === 'approve' ? 'Опубликовано' : 'Скрыто'); }
async function remove(c) { if (await ui.ask({ title: 'Удалить комментарий?', message: c.body.slice(0, 200), okLabel: 'Удалить', danger: true })) { await del(`/api/comments/${c.id}`); list.load(); } }
async function heart(c) { const r = await post(`/api/comments/${c.id}/heart`, { hearted: !c.isHearted }); c.isHearted = r.hearted; }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Комментарии</h1><div class="sub">Комментарии к вашим видео</div></div></div>
    <div class="tabs mb-16"><button class="tab" :class="{ active: status === '' }" @click="status = ''">Все</button><button class="tab" :class="{ active: status === 'held' }" @click="status = 'held'">На проверке</button><button class="tab" :class="{ active: status === 'visible' }" @click="status = 'visible'">Опубликованные</button><button class="tab" :class="{ active: status === 'hidden' }" @click="status = 'hidden'">Скрытые</button></div>
    <div v-if="list.loading.value && !list.items.value.length" class="loading-block"><div class="spin"></div></div>
    <ListError v-else-if="list.error.value" :error="list.error.value" @retry="list.load()" />
    <EmptyState v-else-if="!list.items.value.length" icon="comment" title="Комментариев нет" />
    <div v-else class="col gap-8">
      <div v-for="c in list.items.value" :key="c.id" class="card row" style="align-items: flex-start">
        <ChannelAvatar :user="c.author" />
        <div class="grow" style="min-width:0">
          <div class="row wrap small"><b>{{ c.author.displayName }}</b><span class="faint">{{ timeAgo(c.createdAt) }}</span><span v-if="c.status === 'held'" class="badge warning">На проверке</span><span v-if="c.status === 'hidden'" class="badge danger">Скрыт</span><span v-if="c.parentId" class="badge">ответ</span></div>
          <div class="pre" style="margin: 4px 0">{{ c.body }}</div>
          <router-link :to="`/watch/${c.videoShortId}?comment=${c.id}`" class="small">{{ c.videoTitle }} →</router-link>
        </div>
        <div class="row gap-4">
          <button v-if="c.status !== 'visible'" class="btn sm primary" @click="moderate(c, 'approve')">Опубликовать</button>
          <button v-if="c.status === 'visible'" class="btn sm" @click="moderate(c, 'hide')">Скрыть</button>
          <button class="ibtn sm" :class="{ active: c.isHearted }" title="Сердечко" @click="heart(c)" aria-label="Сердечко"><Icon :name="c.isHearted ? 'heart' : 'heartOutline'" :size="18" /></button>
          <button class="ibtn sm" title="Удалить" @click="remove(c)" aria-label="Удалить"><Icon name="delete" :size="18" /></button>
        </div>
      </div>
    </div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="30" @change="list.setPage" />
  </div>
</template>
