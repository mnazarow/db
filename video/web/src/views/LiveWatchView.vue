<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post, del } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import * as ws from '../ws.js';
import VideoPlayer from '../components/player/VideoPlayer.vue';
import LiveChat from '../components/LiveChat.vue';
import LiveInteract from '../components/LiveInteract.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import SubscribeButton from '../components/SubscribeButton.vue';
import RichText from '../components/RichText.vue';
import ShareDialog from '../components/ShareDialog.vue';
import ReportDialog from '../components/ReportDialog.vue';
import EmptyState from '../components/EmptyState.vue';
import { fmtNumber, timeAgo, fmtDateTime } from '../utils/format.js';
import { url as withBase } from '../base.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const stream = ref(null);
const error = ref(null);
const share = ref(false);
const report = ref(false);
const playerKey = ref(0);
const side = ref('chat');
let off = [];
async function toggleReminder() {
  if (!auth.isActive) return ui.toast('Войдите, чтобы получать напоминания');
  try { const r = await post(`/api/live/${stream.value.shortId}/remind`, { on: !stream.value.reminder }); stream.value.reminder = r.reminder; stream.value.reminderCount = r.count; ui.toast(r.reminder ? 'Напомним за 15 минут до начала' : 'Напоминание отключено', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

// 1.9: перемотка эфира назад (DVR) — портал отдаёт запись идущей трансляции с нужной секунды
const dvr = ref(null);           // { enabled, available, windowSec }
const dvrSrc = ref('');          // адрес записи, когда зритель ушёл из прямого эфира
const dvrFrom = ref(0);
async function loadDvr() {
  if (!stream.value || stream.value.status !== 'live' || auth.config?.dvrEnabled === false || stream.value.dvr === false) { dvr.value = null; return; }
  try { dvr.value = await get(`/api/live/${stream.value.shortId}/dvr`); } catch { dvr.value = null; }
}
function rewind(sec) {
  if (!dvr.value?.available) return ui.toast('Запись эфира ещё не накопилась — попробуйте через минуту');
  dvrFrom.value = Math.max(0, Math.round((Date.now() - new Date(stream.value.startedAt).getTime()) / 1000) - sec);
  dvrSrc.value = withBase(`/api/live/${stream.value.shortId}/dvr/play?back=${sec}`);
}
function fromStart() {
  if (!dvr.value?.available) return ui.toast('Запись эфира ещё не накопилась — попробуйте через минуту');
  dvrFrom.value = dvr.value.offsetSec || 0;
  dvrSrc.value = withBase(`/api/live/${stream.value.shortId}/dvr/play?from=${dvr.value.offsetSec || 0}`);
}
function backToLive() { dvrSrc.value = ''; playerKey.value += 1; }

// 1.6: живые субтитры эфира
const captions = ref([]);            // последние реплики
const captionsOn = ref(true);        // зритель может выключить их у себя
const captionLines = computed(() => (captionsOn.value ? captions.value.slice(-2) : []));
async function loadCaptions() {
  if (!stream.value?.captions) { captions.value = []; return; }
  try { const r = await get(`/api/live/${stream.value.shortId}/captions?limit=10`); captions.value = r.captions || []; } catch { captions.value = []; }
}

// 1.5: регистрация на вебинар и учёт присутствия
const reg = ref(null);
const regBusy = ref(false);
let attendTimer = null;
let attendAccrued = 0;

async function loadRegistration() {
  if (!stream.value) return;
  try { reg.value = await get(`/api/live/${stream.value.shortId}/registration`); } catch { reg.value = null; }
}
async function toggleRegistration() {
  if (!auth.isActive) return ui.toast('Войдите, чтобы зарегистрироваться');
  regBusy.value = true;
  try {
    if (reg.value?.registered) { await del(`/api/live/${stream.value.id}/registration`); ui.toast('Регистрация отменена'); }
    else { await post(`/api/live/${stream.value.id}/registration`, {}); ui.toast('Вы зарегистрированы — напомним перед началом', { type: 'success' }); }
    await loadRegistration();
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { regBusy.value = false; }
}
/** Присутствие: раз в минуту сообщаем, сколько времени зритель провёл на эфире. */
function startAttendance() {
  stopAttendance();
  // Сотрудник отмечается по учётной записи; гость вебинара (вход по ссылке из письма) — по регистрации
  const guestWebinar = !auth.isActive && stream.value?.webinar && stream.value?.webinarSlug;
  if (!auth.isActive && !guestWebinar) return;
  attendTimer = setInterval(async () => {
    if (document.hidden || !stream.value || stream.value.status !== 'live') return;
    attendAccrued += 60;
    const url = guestWebinar ? `/api/webinars/${encodeURIComponent(stream.value.webinarSlug)}/attendance` : `/api/live/${stream.value.id}/attendance`;
    try { await post(url, { seconds: 60 }); } catch { /* не мешаем просмотру */ }
  }, 60000);
}
function stopAttendance() { clearInterval(attendTimer); attendTimer = null; }

async function load() {
  try {
    stream.value = (await get(`/api/live/${route.params.id}`)).stream;
    document.title = `${stream.value.title} — эфир — ${auth.siteName}`;
    await loadRegistration();
    await loadCaptions();
    if (stream.value.status === 'live') startAttendance(); else stopAttendance();
  } catch (e) { error.value = e; }
}
onMounted(() => {
  load();
  off.push(
    ws.on('stream_status', async (m) => { if (stream.value && m.streamId === stream.value.id) { await load(); if (m.status === 'live') playerKey.value++; } }),
    ws.on('stream_update', (m) => { if (stream.value && m.stream?.id === stream.value.id) stream.value = { ...stream.value, ...m.stream, hlsUrl: stream.value.hlsUrl, ingest: stream.value.ingest }; }),
    ws.on('live.ended', (m) => { if (stream.value && m.streamId === stream.value.id) load(); }),
    ws.on('viewers', (m) => { if (stream.value && m.streamId === stream.value.id) stream.value = { ...stream.value, viewerCount: m.count }; }),
    ws.on('live.caption', (m) => {
      if (!stream.value || m.streamId !== stream.value.id) return;
      captions.value.push({ seq: m.seq, text: m.text, offsetSec: m.offsetSec });
      if (captions.value.length > 40) captions.value.splice(0, 20);
    }),
  );
});
watch(() => stream.value?.status, (st) => { if (st === 'live') loadDvr(); else { dvr.value = null; dvrSrc.value = ''; } });
onBeforeUnmount(() => { off.forEach((f) => f()); stopAttendance(); });
watch(() => route.params.id, load);
</script>

<template>
  <div v-if="error" class="page"><EmptyState :icon="error.status === 401 ? 'lock' : 'live'" :title="error.status === 401 ? 'Требуется вход' : 'Трансляция не найдена'" :text="error.message"><router-link v-if="error.status === 401" :to="{ name: 'login', query: { next: route.fullPath } }" class="btn primary">Войти</router-link></EmptyState></div>
  <div v-else-if="stream" class="live-page">
    <div class="live-main">
      <VideoPlayer v-if="stream.status === 'live' && !dvrSrc" :key="playerKey" :qoe-stream-id="stream.id" qoe-source="live" :src="stream.hlsUrl" :poster="stream.thumbnailUrl" live autoplay :allow-theater="false" :allow-mini="false" :title="stream.title" :caption-lines="captionLines" />
      <!-- Перемотка эфира назад (1.9): проигрывается запись идущей трансляции -->
      <div v-else-if="stream.status === 'live' && dvrSrc" class="player dvr-player">
        <video :src="dvrSrc" controls autoplay playsinline style="width:100%;height:100%;background:#000"></video>
      </div>
      <div v-else class="player live-placeholder">
        <div class="col" style="align-items:center; text-align:center; padding: 24px">
          <Icon name="broadcast" :size="48" />
          <h3 style="color:#fff">{{ stream.status === 'ended' ? 'Эфир завершён' : 'Эфир ещё не начался' }}</h3>
          <p class="small" style="opacity:.8" v-if="stream.status === 'idle' && stream.scheduledAt">Начало запланировано на {{ fmtDateTime(stream.scheduledAt) }}</p>
          <div v-if="stream.status === 'idle' && stream.scheduledAt" class="row wrap mt-8" style="justify-content:center">
            <button class="btn sm" :class="stream.reminder ? 'primary' : ''" style="border-color:#fff; color:#fff" @click="toggleReminder"><Icon :name="stream.reminder ? 'bellFill' : 'bell'" :size="16" /> {{ stream.reminder ? 'Напоминание включено' : 'Напомнить мне' }}<span v-if="stream.reminderCount" style="opacity:.8"> · {{ stream.reminderCount }}</span></button>
            <a class="btn sm" style="border-color:#fff; color:#fff" :href="$url(`/api/live/${stream.shortId}/calendar.ics`)"><Icon name="calendar" :size="16" /> В календарь</a>
            <button v-if="reg?.enabled" class="btn sm" :class="reg.registered ? '' : 'primary'" style="border-color:#fff; color:#fff" :disabled="regBusy || (!reg.registered && reg.seatsLeft === 0)" @click="toggleRegistration">
              <Icon :name="reg.registered ? 'checkAll' : 'personAdd'" :size="16" />
              {{ reg.registered ? 'Вы зарегистрированы' : (reg.seatsLeft === 0 ? 'Мест больше нет' : 'Зарегистрироваться') }}
            </button>
          </div>
          <p v-if="reg?.enabled" class="small" style="opacity:.85">
            {{ reg.note || 'Для участия нужна регистрация' }}
            <span v-if="reg.limit"> · записалось {{ reg.count }} из {{ reg.limit }}</span>
            <span v-else-if="reg.count"> · записалось {{ reg.count }}</span>
          </p>
          <p class="small" style="opacity:.8" v-else-if="stream.status === 'idle'">Страница обновится автоматически, когда автор выйдет в эфир</p>
          <router-link v-if="stream.status === 'ended' && stream.recordingShortId" :to="`/watch/${stream.recordingShortId}`" class="btn primary mt-8"><Icon name="play" :size="18" /> Смотреть запись</router-link>
          <p v-else-if="stream.status === 'ended' && stream.record" class="small" style="opacity:.8">Запись обрабатывается и скоро появится на канале</p>
        </div>
      </div>
      <!-- Кнопки перемотки эфира (1.9) -->
      <div v-if="stream.status === 'live' && dvr?.enabled" class="row wrap gap-8 mt-8" style="align-items:center">
        <template v-if="!dvrSrc">
          <button class="btn sm" :disabled="!dvr.available" @click="rewind(30)"><Icon name="back10" :size="16" /> Назад на 30 секунд</button>
          <button class="btn sm" :disabled="!dvr.available" @click="fromStart()"><Icon name="replay" :size="16" /> Смотреть с начала эфира</button>
          <span class="small muted" v-if="dvr.available">Доступно для перемотки: {{ Math.floor(dvr.available / 60) }} мин</span>
          <span class="small muted" v-else>Запись эфира ещё накапливается</span>
        </template>
        <template v-else>
          <span class="badge warning"><Icon name="history" :size="12" /> Смотрите запись эфира</span>
          <button class="btn sm primary" @click="backToLive"><Icon name="live" :size="16" /> Вернуться в прямой эфир</button>
        </template>
      </div>
      <div class="mt-16">
        <div class="row wrap gap-8 mb-8">
          <span v-if="stream.status === 'live'" class="badge live"><Icon name="live" :size="12" /> В эфире</span>
          <span v-else-if="stream.status === 'ended'" class="badge">Завершён</span>
          <span v-else class="badge brand">Анонс</span>
          <span class="small muted" v-if="stream.status === 'live'"><Icon name="eye" :size="14" style="vertical-align:-2px" /> {{ fmtNumber(stream.viewerCount) }} • начало {{ timeAgo(stream.startedAt) }}</span>
          <span class="small muted" v-else-if="stream.status === 'ended'">Пик зрителей: {{ stream.viewerPeak }}</span>
        </div>
        <h1 class="watch-title">{{ stream.title }}</h1>
        <div class="watch-row">
          <div class="row gap-16 watch-owner">
            <router-link :to="`/@${stream.owner.handle}`"><ChannelAvatar :user="stream.owner" size="lg" /></router-link>
            <div class="grow"><router-link :to="`/@${stream.owner.handle}`" class="owner-name">{{ stream.owner.displayName }}</router-link><div class="small muted">{{ fmtNumber(stream.owner.subscriberCount || 0) }} подписчиков</div></div>
            <SubscribeButton :handle="stream.owner.handle" :subscribed="stream.subscribed" :owner-id="stream.owner.id" />
          </div>
          <div class="row gap-8">
            <button class="btn soft" @click="share = true"><Icon name="share" :size="20" /> Поделиться</button>
            <router-link v-if="stream.isOwner || auth.isStaff" :to="`/studio/live/${stream.id}`" class="btn soft"><Icon name="settings" :size="20" /> Управление</router-link>
            <button v-else-if="auth.isActive" class="ibtn soft-round" title="Пожаловаться" @click="report = true" aria-label="Пожаловаться"><Icon name="flag" /></button>
          </div>
        </div>
        <div v-if="stream.description" class="panel soft"><RichText :text="stream.description" :seekable="false" /></div>
      </div>
    </div>
    <aside class="live-side">
      <div v-if="stream.captions && stream.status === 'live'" class="row gap-8 mb-8">
        <button class="btn sm soft grow" :class="{ active: captionsOn }" @click="captionsOn = !captionsOn">
          <Icon :name="captionsOn ? 'captionsFill' : 'captions'" :size="16" /> Живые субтитры {{ captionsOn ? 'вкл.' : 'выкл.' }}
        </button>
      </div>
      <div class="side-seg mb-8"><button class="side-seg-btn" :class="{ on: side === 'chat' }" @click="side = 'chat'"><Icon name="comment" :size="16" /> Чат</button><button class="side-seg-btn" :class="{ on: side === 'interact' }" @click="side = 'interact'"><Icon name="quizOutline" :size="16" /> Вопросы и опросы</button></div>
      <div class="live-side-body">
        <LiveChat v-show="side === 'chat'" :stream="stream" />
        <div v-show="side === 'interact'" class="live-interact"><LiveInteract :stream="stream" /></div>
      </div>
    </aside>
    <ShareDialog v-if="share" :url="`/live/${stream.shortId}`" title="Поделиться трансляцией" @close="share = false" />
    <ReportDialog v-if="report" target-type="live" :target-id="stream.id" @close="report = false" />
  </div>
  <div v-else class="page"><div class="skeleton" style="aspect-ratio:16/9; max-width: 1000px; border-radius: var(--radius)"></div></div>
</template>

<style>
.live-page { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 24px; padding: 20px var(--gutter) 64px; max-width: 1760px; margin: 0 auto; align-items: start; }
.live-side { position: sticky; top: calc(var(--header-h) + 20px); height: calc(100vh - var(--header-h) - 40px); display: flex; flex-direction: column; }
.live-side-body { flex: 1; min-height: 0; }
.live-side-body > * { height: 100%; }
.live-interact { border: 1px solid var(--line-2); border-radius: var(--radius); background: var(--surface); padding: 10px; }
.side-seg { display: flex; gap: 4px; padding: 4px; border-radius: var(--pill); background: var(--bg-3); }
.side-seg-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 34px; border-radius: var(--pill); font-size: 13px; font-weight: 500; color: var(--text-2); }
.side-seg-btn.on { background: var(--surface); color: var(--brand); box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,.08)); }
.live-placeholder { display: flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, var(--brand-700), var(--brand-400)); }
@media (max-width: 767px) { .live-placeholder { aspect-ratio: auto; min-height: 56.25vw; } }
@media (max-width: 1100px) { .live-page { grid-template-columns: 1fr; } .live-side { position: static; height: 480px; } }
</style>
