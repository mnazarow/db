<script setup>
// Студия: согласования — что просят проверить меня и что я отправил.
import { ref, onMounted, computed } from 'vue';
import { get } from '../../api.js';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDuration, fmtDateTime } from '../../utils/format.js';

const data = ref({ incoming: [], outgoing: [] });
const loading = ref(true);
const tab = ref('incoming');

const STATUS = {
  in_review: { label: 'На согласовании', cls: 'brand' },
  approved: { label: 'Согласовано', cls: 'success' },
  changes_requested: { label: 'Нужны правки', cls: 'warning' },
};
const overdue = (r) => r.dueAt && new Date(r.dueAt) < new Date();

async function load() {
  loading.value = true;
  try { data.value = await get('/api/studio/reviews'); } finally { loading.value = false; }
}
onMounted(load);
const list = computed(() => (tab.value === 'incoming' ? data.value.incoming : data.value.outgoing));
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>Согласования</h1>
        <p class="muted">Видео, которые проверяете вы, и те, что вы отправили коллегам на проверку.</p>
      </div>
    </div>

    <div class="tabs mb-16">
      <button class="tab" :class="{ active: tab === 'incoming' }" @click="tab = 'incoming'">Мне на проверку <b v-if="data.incoming.length">{{ data.incoming.length }}</b></button>
      <button class="tab" :class="{ active: tab === 'outgoing' }" @click="tab = 'outgoing'">Я отправил <b v-if="data.outgoing.length">{{ data.outgoing.length }}</b></button>
    </div>

    <div v-if="loading" class="skeleton" style="height: 160px"></div>
    <EmptyState v-else-if="!list.length" icon="checkAll"
      :title="tab === 'incoming' ? 'Нечего проверять' : 'Ничего не отправлено'"
      :text="tab === 'incoming' ? 'Когда коллега отправит видео на согласование, оно появится здесь.' : 'Отправить видео на проверку можно на его странице — панель «Согласование».'" />
    <div v-else class="col gap-8">
      <router-link v-for="r in list" :key="r.id" class="panel rev-row" :to="`/watch/${r.shortId}?panel=review`">
        <img v-if="r.thumbnailUrl" :src="r.thumbnailUrl" alt="" class="rev-thumb" />
        <div class="grow" style="min-width:0">
          <b class="ellipsis">{{ r.title }}</b>
          <div class="tiny muted">
            <template v-if="tab === 'incoming'">{{ r.author }} · </template>
            <template v-if="r.duration">{{ fmtDuration(r.duration) }} · </template>
            отправлено {{ fmtDateTime(r.createdAt) }}
            <template v-if="r.reviewers"> · решений {{ r.decided }} из {{ r.reviewers }}</template>
          </div>
          <div v-if="r.note" class="small mt-4">{{ r.note }}</div>
        </div>
        <div class="col" style="align-items:flex-end; gap:6px">
          <span class="badge" :class="STATUS[r.status]?.cls">{{ STATUS[r.status]?.label }}</span>
          <span v-if="r.dueAt" class="tiny" :class="overdue(r) ? 'danger-text' : 'muted'">до {{ fmtDateTime(r.dueAt) }}</span>
        </div>
      </router-link>
    </div>
  </div>
</template>

<style>
.rev-row { display: flex; gap: 12px; align-items: center; padding: 10px 12px; }
.rev-row:hover { border-color: var(--brand-200); }
.rev-thumb { width: 120px; border-radius: 8px; flex-shrink: 0; }
.danger-text { color: var(--danger); }
@media (max-width: 767px) { .rev-thumb { width: 84px; } }
</style>
