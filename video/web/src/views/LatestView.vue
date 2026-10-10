<script setup>
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';
const list = usePaged('/api/feed/latest');
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Новые видео</h1><div class="sub">Последние опубликованные материалы</div></div></div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" />
    <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
  </div>
</template>
