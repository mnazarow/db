<script setup>
import { ref, onMounted } from 'vue';
import { get } from '../../api.js';
import LineChart from '../../components/charts/LineChart.vue';
import VideoCard from '../../components/VideoCard.vue';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
import { fmtNumber, fmtBytes, fmtDurationLong, timeAgo } from '../../utils/format.js';

const s = ref(null);
onMounted(async () => { s.value = await get('/api/admin/stats'); });
const STATUS_LABEL = { active: 'Активен', pending_approval: 'Ждёт одобрения', pending_email: 'Ждёт подтверждения', blocked: 'Заблокирован', rejected: 'Отклонён' };
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Дашборд</h1><div class="sub">Состояние портала</div></div></div>
    <div v-if="!s" class="loading-block"><div class="spin"></div></div>
    <template v-else>
      <div class="row wrap mb-24 gap-8">
        <router-link v-if="s.users.pending" to="/admin/users?status=pending_approval" class="alert warning" style="flex:1; min-width: 240px"><Icon name="accountClock" :size="20" /> {{ s.users.pending }} заявок на регистрацию ждут одобрения</router-link>
        <router-link v-if="s.videos.pendingModeration" to="/admin/moderation" class="alert warning" style="flex:1; min-width: 240px"><Icon name="gavel" :size="20" /> {{ s.videos.pendingModeration }} видео на проверке</router-link>
        <router-link v-if="s.reportsOpen" to="/admin/reports" class="alert danger" style="flex:1; min-width: 240px"><Icon name="flag" :size="20" /> {{ s.reportsOpen }} открытых жалоб</router-link>
        <router-link v-if="s.jobs.failed" to="/admin/jobs?status=failed" class="alert danger" style="flex:1; min-width: 240px"><Icon name="alertCircle" :size="20" /> {{ s.jobs.failed }} заданий завершились с ошибкой</router-link>
      </div>
      <div class="stat-tiles mb-24">
        <div class="stat-tile"><div class="v">{{ s.users.active }}</div><div class="l">Активных пользователей</div><div class="d">{{ s.users.activeToday }} были сегодня • +{{ s.users.newWeek }} за неделю</div></div>
        <div class="stat-tile"><div class="v">{{ s.videos.ready }}</div><div class="l">Видео</div><div class="d">{{ fmtDurationLong(s.videos.durationSeconds) }} контента • +{{ s.videos.newWeek }} за неделю</div></div>
        <div class="stat-tile"><div class="v">{{ fmtNumber(s.videos.views) }}</div><div class="l">Просмотров всего</div><div class="d">{{ s.today.views }} сегодня</div></div>
        <div class="stat-tile"><div class="v" style="font-size:24px">{{ fmtDurationLong(s.today.watchSeconds) }}</div><div class="l">Время просмотра сегодня</div></div>
        <div class="stat-tile"><div class="v" style="font-size:24px">{{ fmtBytes(s.videos.bytes) }}</div><div class="l">Медиахранилище</div><div class="d">свободно {{ fmtBytes(s.disk.free) }} из {{ fmtBytes(s.disk.total) }}</div></div>
        <div class="stat-tile"><div class="v">{{ s.jobs.queued + s.jobs.running }}</div><div class="l">Заданий в работе</div><div class="d">{{ s.videos.processing }} видео обрабатывается</div></div>
        <div class="stat-tile"><div class="v">{{ s.liveNow }}</div><div class="l">Эфиров сейчас</div></div>
      </div>
      <div class="panel mb-24"><h4 class="mb-8">Просмотры за 14 дней</h4><LineChart :data="s.daily.map((d) => ({ x: d.day, y: d.views }))" label="просмотров" /></div>
      <div class="two-col">
        <div class="panel"><h4 class="mb-16">Популярное за неделю</h4><div class="col gap-12"><VideoCard v-for="v in s.topVideos" :key="v.id" :video="v" layout="compact" /><span v-if="!s.topVideos.length" class="muted small">Нет данных</span></div></div>
        <div class="panel"><h4 class="mb-16">Новые пользователи</h4><div class="col gap-8"><router-link v-for="u in s.recentUsers" :key="u.id" :to="`/admin/users/${u.id}`" class="row" style="color:var(--text)"><ChannelAvatar :user="u" size="sm" /><div class="grow" style="min-width:0"><div class="ellipsis small" style="font-weight:500">{{ u.displayName }}</div><div class="tiny muted ellipsis">{{ u.email }} • {{ timeAgo(u.createdAt) }}</div></div><span class="badge" :class="u.status === 'active' ? 'success' : u.status === 'blocked' ? 'danger' : 'warning'">{{ STATUS_LABEL[u.status] }}</span></router-link></div></div>
      </div>
    </template>
  </div>
</template>
