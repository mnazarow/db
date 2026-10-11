<script setup>
import { watch } from 'vue';
import { useRoute } from 'vue-router';
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';
const route = useRoute();
const list = usePaged(() => `/api/feed/tags/${encodeURIComponent(route.params.tag)}`);
watch(() => route.params.tag, () => list.load());
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>#{{ route.params.tag }}</h1><div class="sub">Видео с этим тегом</div></div></div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" empty-icon="tag" empty-title="Видео с таким тегом нет" />
    <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
  </div>
</template>
