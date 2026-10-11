<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';

const route = useRoute();
const router = useRouter();
const period = ref(String(route.query.period || 'week'));
const list = usePaged('/api/feed/trending', { extraQuery: () => ({ period: period.value }) });
const PERIODS = [['day', 'Сегодня'], ['week', 'Неделя'], ['month', 'Месяц'], ['year', 'Год']];
watch(period, (p) => router.replace({ query: { ...route.query, period: p, page: undefined } }));
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div><h1>Тренды</h1><div class="sub">Самые популярные видео за выбранный период</div></div>
      <div class="actions chips"><button v-for="[k, l] in PERIODS" :key="k" class="chip" :class="{ active: period === k }" @click="period = k">{{ l }}</button></div>
    </div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" layout="list" empty-title="Пока нет популярных видео" />
    <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
  </div>
</template>
