<script setup>
import { ref, onMounted } from 'vue';
import { get } from '../api.js';
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';

const list = usePaged('/api/feed/subscriptions');
const channels = ref([]);
onMounted(async () => { try { channels.value = (await get('/api/me/subscriptions')).channels; } catch { /* ignore */ } });
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Подписки</h1><div class="sub">Новые видео каналов, на которые вы подписаны</div></div></div>
    <div v-if="channels.length" class="chips mb-24">
      <router-link v-for="c in channels" :key="c.id" :to="`/@${c.handle}`" class="chip"><ChannelAvatar :user="c" size="xs" /> {{ c.displayName }}<span v-if="c.isLive" class="badge live" style="margin-left:6px">эфир</span></router-link>
    </div>
    <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" :loading="list.loading.value" empty-icon="subscriptions" empty-title="Здесь пока пусто" text="Подпишитесь на каналы коллег, и их новые видео появятся в этой ленте.">
      <template #empty><router-link to="/search?type=channel" class="btn">Найти каналы</router-link></template>
    </VideoGrid>
    <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
  </div>
</template>
