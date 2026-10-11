<script setup>
import { ref, onMounted } from 'vue';
import { get } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import VideoCard from '../../components/VideoCard.vue';
import LineChart from '../../components/charts/LineChart.vue';
import { fmtNumber, fmtBytes, fmtDurationLong, fmtSubs } from '../../utils/format.js';

const auth = useAuth();
const data = ref(null);
const analytics = ref(null);
onMounted(async () => {
  data.value = await get('/api/studio/videos?limit=6');
  analytics.value = await get('/api/studio/analytics?range=28d');
});
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Студия</h1><div class="sub">Обзор вашего канала</div></div><div class="actions"><router-link v-if="auth.canUpload" to="/studio/upload" class="btn primary"><Icon name="upload" :size="18" /> Загрузить видео</router-link></div></div>
    <div v-if="!data" class="loading-block"><div class="spin"></div></div>
    <template v-else>
      <div class="stat-tiles mb-24">
        <div class="stat-tile"><div class="v">{{ data.summary.videos }}</div><div class="l">Видео</div><div class="d" v-if="data.summary.processing">{{ data.summary.processing }} в обработке</div></div>
        <div class="stat-tile"><div class="v">{{ fmtNumber(data.summary.views) }}</div><div class="l">Просмотров</div><div class="d" v-if="analytics">{{ fmtNumber(analytics.totals.views) }} за 28 дней</div></div>
        <div class="stat-tile"><div class="v">{{ fmtNumber(auth.user.subscriberCount) }}</div><div class="l">Подписчиков</div><div class="d" v-if="analytics">+{{ analytics.totals.subscribersGained }} за 28 дней</div></div>
        <div class="stat-tile"><div class="v">{{ fmtNumber(data.summary.likes) }}</div><div class="l">Отметок «нравится»</div></div>
        <div class="stat-tile"><div class="v">{{ fmtNumber(data.summary.comments) }}</div><div class="l">Комментариев</div></div>
        <div class="stat-tile"><div class="v" style="font-size: 26px">{{ fmtBytes(data.summary.bytes) }}</div><div class="l">Занято места</div></div>
      </div>
      <div v-if="data.summary.pending" class="alert warning mb-24"><Icon name="gavel" :size="20" /> {{ data.summary.pending }} видео ожидают проверки модератором.</div>
      <div class="panel mb-24" v-if="analytics">
        <div class="row mb-8"><h3>Просмотры за 28 дней</h3><router-link to="/studio/analytics" class="small" style="margin-left:auto">Подробная аналитика →</router-link></div>
        <LineChart :data="analytics.daily.map((d) => ({ x: d.day, y: d.views }))" label="просмотров" :height="200" />
        <div class="small muted mt-8">Время просмотра: {{ fmtDurationLong(analytics.totals.watch) }}</div>
      </div>
      <div class="section-title"><h2>Последние видео</h2><router-link to="/studio/videos" class="link">Все видео <Icon name="chevronRight" :size="14" /></router-link></div>
      <div v-if="data.videos.length" class="video-grid compact">
        <VideoCard v-for="v in data.videos" :key="v.id" :video="v" :show-channel="false" show-status />
      </div>
      <div v-else class="empty"><Icon class="ic" name="movie" :size="40" /><h3>Видео пока нет</h3><router-link v-if="auth.canUpload" to="/studio/upload" class="btn primary mt-8">Загрузить первое видео</router-link></div>
    </template>
  </div>
</template>
