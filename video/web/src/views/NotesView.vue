<script setup>
// «Мои заметки»: все личные заметки пользователя, сгруппированные по видео.
import { ref, onMounted } from 'vue';
import { get } from '../api.js';
import EmptyState from '../components/EmptyState.vue';
import { fmtDuration, fmtDate } from '../utils/format.js';

const videos = ref([]);
const loading = ref(true);
const q = ref('');
onMounted(async () => { try { videos.value = (await get('/api/me/notes')).videos; } finally { loading.value = false; } });
function filtered() { const s = q.value.trim().toLowerCase(); if (!s) return videos.value; return videos.value.map((v) => ({ ...v, notes: v.notes.filter((n) => n.body.toLowerCase().includes(s) || v.title.toLowerCase().includes(s)) })).filter((v) => v.notes.length); }
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Мои заметки</h1><div class="sub">Личные пометки с таймкодами — видите только вы</div></div><input class="input" v-model="q" placeholder="Поиск по заметкам" style="max-width: 280px" /></div>
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!filtered().length" icon="note" title="Заметок пока нет" text="Открывайте панель «Заметки» на странице видео и сохраняйте важные моменты с привязкой ко времени." />
    <div v-else class="col gap-16">
      <div v-for="v in filtered()" :key="v.videoId" class="panel">
        <div class="row gap-12 mb-8"><router-link :to="`/watch/${v.shortId}`"><img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" style="width: 120px; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px" /></router-link><div class="grow" style="min-width:0"><router-link :to="`/watch/${v.shortId}`" style="font-weight:500; color: var(--text)" class="clamp-2">{{ v.title }}</router-link><div class="tiny muted">{{ v.notes.length }} заметок</div></div><a class="btn ghost sm" :href="$url(`/api/videos/${v.shortId}/notes/export`)"><Icon name="download" :size="16" /> .md</a></div>
        <div class="col gap-4">
          <router-link v-for="n in v.notes" :key="n.id" :to="`/watch/${v.shortId}?t=${Math.floor(n.position)}`" class="note-row"><span class="ts">{{ fmtDuration(n.position) }}</span><span class="grow" style="white-space: pre-wrap">{{ n.body }}</span><span class="tiny muted nowrap">{{ fmtDate(n.updatedAt) }}</span></router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.note-row { display: flex; gap: 12px; align-items: flex-start; padding: 6px 8px; border-radius: var(--radius-sm); color: var(--text); font-size: 14px; }
.note-row:hover { background: var(--bg-2); }
.note-row .ts { color: var(--brand); font-weight: 500; font-variant-numeric: tabular-nums; flex: 0 0 48px; }
</style>
