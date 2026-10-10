<script setup>
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';
const list = usePaged('/api/me/liked');
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Понравившиеся</h1><div class="sub">{{ list.total.value }} видео</div></div></div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" empty-icon="thumbUp" empty-title="Пока нет понравившихся видео" />
    <Pagination :page="list.page.value" :total="list.total.value" :limit="list.limit" @change="list.setPage" />
  </div>
</template>
