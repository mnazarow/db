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

const route = useRoute(); const router = useRouter(); const ui = useUi();
const status = ref(String(route.query.status || '')); const q = ref(String(route.query.q || ''));
const list = usePaged('/api/admin/comments', { key: 'comments', limit: 30, extraQuery: () => ({ status: status.value, q: q.value }) });
let t = null;
watch([status, q], () => { clearTimeout(t); t = setTimeout(() => router.replace({ query: { status: status.value || undefined, q: q.value || undefined } }), 250); });
async function setStatus(c, s) { await post(`/api/admin/comments/${c.id}/status`, { status: s }); c.status = s; ui.toast('Готово', { type: 'success' }); }
async function remove(c) { if (await ui.ask({ title: 'Удалить комментарий?', message: c.body.slice(0, 200), okLabel: 'Удалить', danger: true })) { await del(`/api/admin/comments/${c.id}`); list.load(); } }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Комментарии</h1><div class="sub">Все комментарии портала</div></div></div>
    <div class="row wrap mb-16"><input class="input" v-model="q" placeholder="Текст или автор" style="max-width: 280px" /><select class="select" v-model="status" style="max-width: 200px"><option value="">Все</option><option value="held">На проверке</option><option value="visible">Опубликованные</option><option value="hidden">Скрытые</option></select></div>
    <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
    <EmptyState v-else-if="!list.loading.value && !list.items.value.length" icon="comment" title="Комментариев нет" />
    <div class="col gap-8">
      <div v-for="c in list.items.value" :key="c.id" class="card row" style="align-items:flex-start">
        <ChannelAvatar :user="c.author" />
        <div class="grow" style="min-width:0"><div class="row wrap small"><router-link :to="`/admin/users/${c.author.id}`"><b>{{ c.author.displayName }}</b></router-link><span class="faint">{{ timeAgo(c.createdAt) }}</span><span v-if="c.status !== 'visible'" class="badge" :class="c.status === 'held' ? 'warning' : 'danger'">{{ c.status === 'held' ? 'На проверке' : 'Скрыт' }}</span></div><div class="pre" style="margin:4px 0">{{ c.body }}</div><router-link :to="`/watch/${c.videoShortId}?comment=${c.id}`" class="small">{{ c.videoTitle }} →</router-link></div>
        <div class="row gap-4"><button v-if="c.status !== 'visible'" class="btn sm primary" @click="setStatus(c, 'visible')">Опубликовать</button><button v-if="c.status !== 'hidden'" class="btn sm" @click="setStatus(c, 'hidden')">Скрыть</button><button class="ibtn sm" @click="remove(c)" aria-label="Удалить комментарий"><Icon name="delete" :size="18" /></button></div>
      </div>
    </div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="30" @change="list.setPage" />
  </div>
</template>
