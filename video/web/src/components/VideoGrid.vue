<script setup>
import VideoCard from './VideoCard.vue';
import EmptyState from './EmptyState.vue';
import ListError from './ListError.vue';
defineEmits(['retry']);
defineProps({
  error: { type: [Object, String], default: null },
  videos: { type: Array, default: () => [] },
  loading: Boolean,
  layout: { type: String, default: 'grid' },
  emptyTitle: { type: String, default: 'Пока ничего нет' },
  emptyText: { type: String, default: '' },
  emptyIcon: { type: String, default: 'movie' },
  showChannel: { type: Boolean, default: true },
  showStatus: { type: Boolean, default: false },
  skeletons: { type: Number, default: 8 },
  playlistId: { type: String, default: '' },
  searchQuery: { type: String, default: '' },
});
</script>

<template>
  <div v-if="loading && !videos.length" class="video-grid" :class="{ shorts: layout === 'shorts', compact: layout === 'compact' }">
    <div v-for="i in skeletons" :key="i" class="vcard">
      <div class="skeleton thumb" style="border-radius: var(--radius)"></div>
      <div class="row"><div class="skeleton" style="width: 36px; height: 36px; border-radius: 50%"></div><div class="grow"><div class="skeleton" style="height: 14px; width: 90%; margin-bottom: 8px"></div><div class="skeleton" style="height: 12px; width: 60%"></div></div></div>
    </div>
  </div>
  <ListError v-else-if="error && !videos.length" :error="error" @retry="$emit('retry')" />
  <EmptyState v-else-if="!videos.length" :title="emptyTitle" :text="emptyText" :icon="emptyIcon"><slot name="empty" /></EmptyState>
  <div v-else class="video-grid" :class="{ shorts: layout === 'shorts', compact: layout === 'compact', 'list-layout': layout === 'list' }">
    <VideoCard v-for="v in videos" :key="v.id" :video="v" :layout="layout" :show-channel="showChannel" :show-status="showStatus" :playlist-id="playlistId" :search-query="searchQuery">
      <template #menu><slot name="menu" :video="v" /></template>
    </VideoCard>
  </div>
</template>

<style>
.video-grid.list-layout { grid-template-columns: 1fr; gap: 20px; }
</style>
