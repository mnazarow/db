<script setup>
import { ref, watch } from 'vue';
import { post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { usePaged } from '../../utils/paged.js';
import Pagination from '../../components/Pagination.vue';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDateTime } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const ui = useUi();
const emit = defineEmits(['refresh']);
const status = ref('open');
const list = usePaged('/api/admin/reports', { key: 'reports', limit: 30, extraQuery: () => ({ status: status.value }) });
watch(status, () => list.load());
async function resolve(r, dismiss) { await post(`/api/admin/reports/${r.id}/resolve`, { dismiss }); list.load(); emit('refresh'); ui.toast(dismiss ? 'Жалоба отклонена' : 'Жалоба обработана', { type: 'success' }); }
async function actVideo(r, action) { await post(`/api/admin/videos/${r.targetId}/${action}`, { reason: 'По жалобе: ' + r.reason }); ui.toast('Готово', { type: 'success' }); list.load(); }
async function actComment(r, s) { await post(`/api/admin/comments/${r.targetId}/status`, { status: s }); ui.toast('Готово', { type: 'success' }); list.load(); }
const TYPE = { video: 'Видео', comment: 'Комментарий', user: 'Пользователь', live: 'Трансляция' };
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Жалобы</h1></div></div>
    <div class="tabs mb-16"><button class="tab" :class="{ active: status === 'open' }" @click="status = 'open'">Открытые</button><button class="tab" :class="{ active: status === 'resolved' }" @click="status = 'resolved'">Обработанные</button><button class="tab" :class="{ active: status === 'dismissed' }" @click="status = 'dismissed'">Отклонённые</button></div>
    <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
    <EmptyState v-else-if="!list.loading.value && !list.items.value.length" icon="flag" title="Жалоб нет" />
    <div class="col gap-12">
      <div v-for="r in list.items.value" :key="r.id" class="panel tight">
        <div class="row wrap"><span class="badge brand">{{ TYPE[r.targetType] }}</span><b>{{ r.reason }}</b><span class="small muted">от {{ r.reporter?.displayName || 'аноним' }} • {{ fmtDateTime(r.createdAt) }}</span></div>
        <p v-if="r.details" class="small pre" style="margin: 8px 0">{{ r.details }}</p>
        <div class="alert info small" v-if="r.target" style="margin: 8px 0">
          <Icon name="info" :size="16" />
          <span v-if="r.targetType === 'video'"><router-link :to="`/watch/${r.target.shortId}`">{{ r.target.title }}</router-link> — {{ r.target.owner }}<span v-if="r.target.blocked"> • заблокировано</span><span v-if="r.target.deleted"> • удалено</span></span>
          <span v-else-if="r.targetType === 'comment'">{{ r.target.author }}: «{{ r.target.body }}» <router-link :to="`/watch/${r.target.videoShortId}?comment=${r.targetId}`">открыть</router-link> • {{ r.target.status }}</span>
          <span v-else-if="r.targetType === 'user'"><router-link :to="`/admin/users/${r.targetId}`">{{ r.target.displayName }}</router-link> (@{{ r.target.handle }}) • {{ r.target.status }}</span>
          <span v-else><router-link :to="`/live/${r.target.shortId}`">{{ r.target.title }}</router-link> • {{ r.target.status }}</span>
        </div>
        <div v-else class="small muted" style="margin: 8px 0">Объект жалобы удалён</div>
        <div v-if="r.status === 'open'" class="row wrap gap-8">
          <template v-if="r.targetType === 'video' && r.target"><button class="btn sm danger" @click="actVideo(r, r.target.blocked ? 'unblock' : 'block')">{{ r.target.blocked ? 'Разблокировать видео' : 'Заблокировать видео' }}</button></template>
          <template v-if="r.targetType === 'comment' && r.target"><button class="btn sm danger" @click="actComment(r, 'hidden')">Скрыть комментарий</button></template>
          <button class="btn sm primary" @click="resolve(r, false)"><Icon name="check" :size="16" /> Обработано</button><button class="btn sm" @click="resolve(r, true)">Отклонить жалобу</button>
        </div>
        <div v-else class="small muted">{{ r.status === 'resolved' ? 'Обработано' : 'Отклонено' }} {{ fmtDateTime(r.resolvedAt) }}</div>
      </div>
    </div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="30" @change="list.setPage" />
  </div>
</template>
