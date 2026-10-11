<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, qs } from '../api.js';
import { useAuth } from '../stores/auth.js';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import SubscribeButton from '../components/SubscribeButton.vue';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';
import PlaylistCard from '../components/PlaylistCard.vue';
import LiveCard from '../components/LiveCard.vue';
import EmptyState from '../components/EmptyState.vue';
import RichText from '../components/RichText.vue';
import ReportDialog from '../components/ReportDialog.vue';
import { fmtSubs, fmtNumber, fmtDate } from '../utils/format.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const channel = ref(null);
const error = ref(null);
const tab = computed(() => String(route.query.tab || 'videos'));
const sort = ref(String(route.query.sort || 'newest'));
const videos = ref([]);
const total = ref(0);
const page = ref(Number(route.query.page) || 1);
const loading = ref(false);
const playlists = ref([]);
const streams = ref([]);
const report = ref(false);

async function load() {
  error.value = null;
  try {
    channel.value = (await get(`/api/channels/${route.params.handle}`)).channel;
    document.title = `${channel.value.displayName} — ${auth.siteName}`;
    loadTab();
  } catch (e) { error.value = e; }
}
async function loadTab() {
  loading.value = true;
  try {
    if (tab.value === 'videos' || tab.value === 'shorts' || tab.value === 'recordings') {
      const r = await get(`/api/channels/${route.params.handle}/videos${qs({ sort: sort.value, page: page.value, limit: 24, shorts: tab.value === 'shorts' ? 1 : undefined, live: tab.value === 'recordings' ? 1 : undefined })}`);
      videos.value = r.videos; total.value = r.total;
    } else if (tab.value === 'playlists') playlists.value = (await get(`/api/channels/${route.params.handle}/playlists`)).playlists;
    else if (tab.value === 'live') streams.value = (await get(`/api/channels/${route.params.handle}/live`)).streams;
  } finally { loading.value = false; }
}
function setTab(t) { router.replace({ query: { tab: t } }); }
function setPage(p) { page.value = p; router.replace({ query: { ...route.query, page: p > 1 ? p : undefined } }); loadTab(); }
watch(() => route.params.handle, load);
watch(() => route.query.tab, () => { page.value = 1; loadTab(); });
watch(sort, (s) => { page.value = 1; router.replace({ query: { ...route.query, sort: s, page: undefined } }); loadTab(); });
onMounted(load);
</script>

<template>
  <div v-if="error" class="page"><EmptyState icon="accountOff" title="Канал не найден" :text="error.message"><router-link to="/" class="btn">На главную</router-link></EmptyState></div>
  <div v-else-if="channel" class="channel">
    <div class="ch-banner" :style="channel.bannerUrl ? { backgroundImage: `url(${channel.bannerUrl})` } : {}"></div>
    <div class="page" style="padding-top: 0">
      <div class="ch-head">
        <ChannelAvatar :user="channel" size="xxl" class="ch-avatar" />
        <div class="grow" style="min-width: 0">
          <h1 style="font-family: var(--font-body); text-transform: none; font-size: 30px; letter-spacing: 0">{{ channel.displayName }}</h1>
          <div class="muted small">@{{ channel.handle }} • {{ fmtSubs(channel.subscriberCount) }} • {{ channel.videoCount }} видео • {{ fmtNumber(channel.totalViews) }} просмотров</div>
          <p v-if="channel.bio" class="ch-bio clamp-2 small muted" @click="setTab('about')">{{ channel.bio }}</p>
          <div class="row wrap mt-8">
            <SubscribeButton :handle="channel.handle" :subscribed="channel.subscribed" :notify="channel.notify" :owner-id="channel.id" @change="channel.subscriberCount = $event.subscriberCount" />
            <router-link v-if="channel.live" :to="`/live/${channel.live.shortId}`" class="btn danger"><Icon name="live" :size="18" /> Сейчас в эфире</router-link>
            <button v-if="auth.isActive && auth.user.id !== channel.id" class="ibtn" title="Пожаловаться" @click="report = true" aria-label="Пожаловаться"><Icon name="flag" :size="20" /></button>
            <a v-if="auth.config?.feedsEnabled" class="ibtn" title="RSS-лента канала" :href="$url(`/api/rss/channel/${channel.handle}`)" target="_blank" rel="noopener" aria-label="RSS-лента канала"><Icon name="rss" :size="20" /></a>
          </div>
        </div>
      </div>
      <div class="tabs mb-24">
        <button class="tab" :class="{ active: tab === 'videos' }" @click="setTab('videos')">Видео</button>
        <button class="tab" :class="{ active: tab === 'shorts' }" @click="setTab('shorts')">Короткие</button>
        <button class="tab" :class="{ active: tab === 'recordings' }" @click="setTab('recordings')">Записи эфиров</button>
        <button class="tab" :class="{ active: tab === 'live' }" @click="setTab('live')">Трансляции</button>
        <button class="tab" :class="{ active: tab === 'playlists' }" @click="setTab('playlists')">Плейлисты</button>
        <button class="tab" :class="{ active: tab === 'about' }" @click="setTab('about')">О канале</button>
      </div>
      <template v-if="tab === 'videos' || tab === 'shorts' || tab === 'recordings'">
        <div class="chips mb-16"><button class="chip" :class="{ active: sort === 'newest' }" @click="sort = 'newest'">Новые</button><button class="chip" :class="{ active: sort === 'popular' }" @click="sort = 'popular'">Популярные</button><button class="chip" :class="{ active: sort === 'oldest' }" @click="sort = 'oldest'">Старые</button></div>
        <VideoGrid :videos="videos" :loading="loading" :show-channel="false" :layout="tab === 'shorts' ? 'shorts' : 'grid'" empty-title="Видео пока нет" />
        <Pagination :page="page" :total="total" :limit="24" @change="setPage" />
      </template>
      <template v-else-if="tab === 'playlists'">
        <EmptyState v-if="!playlists.length && !loading" icon="playlist" title="Плейлистов пока нет" />
        <div v-else class="video-grid"><PlaylistCard v-for="p in playlists" :key="p.id" :playlist="p" :show-owner="false" /></div>
      </template>
      <template v-else-if="tab === 'live'">
        <EmptyState v-if="!streams.length && !loading" icon="live" title="Трансляций нет" text="Здесь отображаются текущие и запланированные эфиры канала." />
        <div v-else class="video-grid"><LiveCard v-for="s in streams" :key="s.id" :stream="s" /></div>
      </template>
      <template v-else>
        <div class="two-col">
          <div class="panel"><h3 class="mb-16">Описание</h3><RichText v-if="channel.bio" :text="channel.bio" :seekable="false" /><p v-else class="muted">Автор пока ничего не рассказал о себе.</p>
            <div v-if="channel.links?.length" class="mt-24"><div class="label mb-8">Ссылки</div><div class="col gap-4"><a v-for="l in channel.links" :key="l.url" :href="l.url" target="_blank" rel="noopener" class="row gap-8"><Icon name="link" :size="16" /> {{ l.title || l.url }}</a></div></div>
          </div>
          <div class="panel soft"><h3 class="mb-16">Статистика</h3>
            <div class="col gap-8 small"><div class="row"><Icon name="calendar" :size="18" class="muted" /> На портале с {{ fmtDate(channel.createdAt) }}</div><div class="row"><Icon name="eye" :size="18" class="muted" /> {{ fmtNumber(channel.totalViews) }} просмотров</div><div class="row"><Icon name="accounts" :size="18" class="muted" /> {{ fmtSubs(channel.subscriberCount) }}</div><div class="row"><Icon name="movie" :size="18" class="muted" /> {{ channel.videoCount }} видео</div></div>
          </div>
        </div>
      </template>
    </div>
    <ReportDialog v-if="report" target-type="user" :target-id="channel.id" @close="report = false" />
  </div>
  <div v-else class="page"><div class="skeleton" style="height: 200px; border-radius: var(--radius-xl)"></div></div>
</template>

<style>
.ch-banner { height: 220px; background: linear-gradient(120deg, var(--brand-500), var(--brand-300)); background-size: cover; background-position: center; }
.ch-head { display: flex; gap: 24px; align-items: flex-start; padding: 20px 0 16px; }
.ch-avatar { border: 4px solid var(--bg); margin-top: -70px; box-shadow: var(--shadow); }
.ch-bio { margin: 8px 0 0; max-width: 720px; cursor: pointer; }
@media (max-width: 640px) { .ch-banner { height: 120px; } .ch-head { flex-direction: column; align-items: center; text-align: center; } .ch-avatar { margin-top: -60px; } .ch-head .row { justify-content: center; } }
</style>
