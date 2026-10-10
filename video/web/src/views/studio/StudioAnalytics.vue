<script setup>
import { ref, watch, onMounted } from 'vue';
import { get } from '../../api.js';
import LineChart from '../../components/charts/LineChart.vue';
import BarList from '../../components/charts/BarList.vue';
import VideoCard from '../../components/VideoCard.vue';
import { fmtNumber, fmtDurationLong, fmtDuration } from '../../utils/format.js';

const range = ref('28d');
const data = ref(null);
const SOURCES = { home: 'Главная', search: 'Поиск', subscriptions: 'Подписки', channel: 'Канал', playlist: 'Плейлист', related: 'Похожие', direct: 'Прямая ссылка', embed: 'Встраивание', notification: 'Уведомление', trending: 'Тренды', library: 'Библиотека', external: 'Внешние сайты', share: 'Поделиться' };
const DEVICES = { desktop: 'Компьютер', mobile: 'Телефон', tablet: 'Планшет', tv: 'ТВ' };
async function load() { data.value = await get(`/api/studio/analytics?range=${range.value}`); }
watch(range, load);
onMounted(load);
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Аналитика канала</h1></div><div class="actions chips"><button v-for="[k, l] in [['7d', '7 дней'], ['28d', '28 дней'], ['90d', '90 дней'], ['365d', 'Год'], ['all', 'Всё время']]" :key="k" class="chip" :class="{ active: range === k }" @click="range = k">{{ l }}</button></div></div>
    <div v-if="!data" class="loading-block"><div class="spin"></div></div>
    <template v-else>
      <div class="stat-tiles mb-24">
        <div class="stat-tile"><div class="v">{{ fmtNumber(data.totals.views) }}</div><div class="l">Просмотров</div><div class="d">всего {{ fmtNumber(data.lifetime.views) }}</div></div>
        <div class="stat-tile"><div class="v" style="font-size:24px">{{ fmtDurationLong(data.totals.watch) }}</div><div class="l">Время просмотра</div></div>
        <div class="stat-tile"><div class="v">{{ fmtNumber(data.totals.subscribers) }}</div><div class="l">Подписчиков</div><div class="d">+{{ data.totals.subscribersGained }} / −{{ data.totals.subscribersLost }}</div></div>
        <div class="stat-tile"><div class="v">{{ data.totals.likes }}</div><div class="l">Лайков</div></div>
        <div class="stat-tile"><div class="v">{{ data.totals.comments }}</div><div class="l">Комментариев</div></div>
        <div class="stat-tile"><div class="v">{{ data.lifetime.videos }}</div><div class="l">Видео</div></div>
      </div>
      <div class="panel mb-24"><h4 class="mb-8">Просмотры</h4><LineChart :data="data.daily.map((d) => ({ x: d.day, y: d.views }))" label="просмотров" /></div>
      <div class="two-col mb-24">
        <div class="panel"><h4 class="mb-8">Время просмотра, минут</h4><LineChart :data="data.daily.map((d) => ({ x: d.day, y: Math.round(d.watchSeconds / 60) }))" label="мин" color="var(--brand-300)" :height="180" /></div>
        <div class="panel"><h4 class="mb-8">Новые подписчики</h4><LineChart :data="data.subscribersDaily.map((d) => ({ x: d.day, y: d.gained }))" label="подписок" color="var(--success)" :height="180" /></div>
      </div>
      <div class="two-col mb-24"><div class="panel"><h4 class="mb-16">Источники просмотров</h4><BarList :data="data.sources" :labels="SOURCES" /></div><div class="panel"><h4 class="mb-16">Устройства</h4><BarList :data="data.devices" :labels="DEVICES" /></div></div>
      <div class="panel"><h4 class="mb-16">Лучшие видео за период</h4>
        <div class="table-wrap"><table class="table"><thead><tr><th>Видео</th><th class="num">Просмотры</th><th class="num">Время просмотра</th><th class="num">Всего просмотров</th></tr></thead><tbody>
          <tr v-for="v in data.top" :key="v.id"><td><router-link :to="`/studio/videos/${v.id}/analytics`" class="row"><img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" style="width:80px;border-radius:6px" /><span class="clamp-2" style="color:var(--text)">{{ v.title }}</span></router-link></td><td class="num">{{ v.rangeViews }}</td><td class="num">{{ fmtDurationLong(v.rangeWatchSeconds) }}</td><td class="num">{{ fmtNumber(v.viewCount) }}</td></tr>
          <tr v-if="!data.top.length"><td colspan="4" class="muted">Нет данных</td></tr>
        </tbody></table></div>
      </div>
    </template>
  </div>
</template>
