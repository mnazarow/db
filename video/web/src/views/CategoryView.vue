<script setup>
import { useAuth } from '../stores/auth.js';
import { ref, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const sort = ref(String(route.query.sort || 'newest'));
const list = usePaged(() => `/api/feed/categories/${route.params.slug}`, { extraQuery: () => ({ sort: sort.value }) });
const category = computed(() => list.extra.value?.category);
watch(sort, (s) => router.replace({ query: { ...route.query, sort: s, page: undefined } }));
watch(() => route.params.slug, () => list.load());
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div><h1><Icon v-if="category" :name="category.icon" :size="30" style="vertical-align:-4px" /> {{ category?.name || 'Категория' }}</h1><div class="sub">{{ category?.description || `${list.total.value} видео` }}</div></div>
      <div class="actions"><select class="select" v-model="sort" style="width: 200px"><option value="newest">Сначала новые</option><option value="popular">Популярные</option><option value="oldest">Сначала старые</option></select><a v-if="category && auth.config?.feedsEnabled" class="ibtn" title="RSS-лента категории" :href="$url(`/api/rss/category/${category.slug}`)" target="_blank" rel="noopener" aria-label="RSS-лента категории"><Icon name="rss" :size="20" /></a></div>
    </div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" empty-title="В категории пока нет видео" />
    <Pagination :page="list.page.value" :total="list.total.value" :limit="list.limit" @change="list.setPage" />
  </div>
</template>
