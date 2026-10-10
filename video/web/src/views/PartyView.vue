<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import * as ws from '../ws.js';
import VideoPlayer from '../components/player/VideoPlayer.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import EmptyState from '../components/EmptyState.vue';
import { copyText } from '../utils/clipboard.js';
import { fmtDuration } from '../utils/format.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();

const loading = ref(true);
const error = ref(null);
const party = ref(null);
const video = ref(null);
const media = ref({ hlsUrl: '', mp4Url: '' });
const messages = ref([]);
const text = ref('');
const list = ref(null);
const player = ref(null);
const startAt = ref(0);
const applying = ref(false);   // применяем чужое состояние — свои события не рассылаем
let off = [];
let pingTimer = null;

const code = computed(() => String(route.params.code || ''));
const canControl = computed(() => !!party.value?.canControl);

function scrollDown() { nextTick(() => { if (list.value) list.value.scrollTop = list.value.scrollHeight; }); }

async function load() {
  loading.value = true; error.value = null;
  try {
    const r = await get(`/api/party/${code.value}`);
    party.value = r.party;
    video.value = r.video;
    media.value = { hlsUrl: r.hlsUrl, mp4Url: r.mp4Url };
    messages.value = r.messages || [];
    startAt.value = r.party.position || 0;
    document.title = `Смотрим вместе: ${r.video.title} — ${auth.siteName}`;
    scrollDown();
    join();
  } catch (e) { error.value = e; } finally { loading.value = false; }
}

function join() {
  ws.join(`party:${party.value.id}`);
}

/** Привести свой плеер к состоянию комнаты. */
async function applyState(st) {
  if (!player.value) return;
  applying.value = true;
  try {
    const cur = player.value.currentTime() || 0;
    if (Math.abs(cur - st.position) > 1.5) player.value.seekTo(st.position);
    if (st.playing && player.value.isPaused()) await player.value.play().catch(() => {});
    if (!st.playing && !player.value.isPaused()) player.value.pause();
  } finally { setTimeout(() => { applying.value = false; }, 400); }
}

/** Отправить своё состояние в комнату (если разрешено управлять). */
async function push(playing) {
  if (!canControl.value || applying.value || !player.value) return;
  try {
    await post(`/api/party/${code.value}/state`, { position: player.value.currentTime() || 0, playing, rate: 1 });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function send() {
  const body = text.value.trim();
  if (!body) return;
  try { await post(`/api/party/${code.value}/chat`, { body }); text.value = ''; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function endParty() {
  if (!(await ui.ask({ title: 'Закрыть комнату?', message: 'Участники увидят, что совместный просмотр завершён.', okLabel: 'Закрыть', danger: true }))) return;
  try { await post(`/api/party/${code.value}/end`, {}); party.value.endedAt = new Date().toISOString(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function copyLink() {
  if (await copyText(location.href)) ui.toast('Ссылка на комнату скопирована');
}

// Подписки и таймеры создаются после загрузки. Если со страницы ушли, пока запрос был в полёте,
// очистка уже отработала — тогда ничего не заводим (иначе оставались вечный таймер и подписки).
let alive = true;
onMounted(async () => {
  await load();
  if (!alive) return;
  off.push(
    ws.on('party.state', (m) => {
      if (!party.value || m.partyId !== party.value.id) return;
      party.value.position = m.position; party.value.playing = m.playing;
      applyState(m);
    }),
    ws.on('room.message', (m) => {
      if (!party.value || m.room !== `party:${party.value.id}`) return;
      messages.value.push(m.message);
      if (messages.value.length > 300) messages.value.splice(0, 50);
      scrollDown();
    }),
    ws.on('party.ended', (m) => { if (party.value && m.partyId === party.value.id) { party.value.endedAt = new Date().toISOString(); player.value?.pause(); } }),
    ws.on('open', () => { if (party.value) join(); }),
  );
  pingTimer = setInterval(async () => {
    if (!party.value || party.value.endedAt) return;
    try {
      const r = await post(`/api/party/${code.value}/ping`, {});
      party.value.members = Array.from({ length: r.members }, (_, i) => party.value.members?.[i] || { id: `m${i}`, displayName: '' });
      // Догоняем комнату, если наш плеер отстал больше чем на 3 секунды
      if (r.playing && player.value && Math.abs((player.value.currentTime() || 0) - r.position) > 3) applyState(r);
    } catch { /* ignore */ }
  }, 30000);
});
onBeforeUnmount(() => {
  alive = false;
  off.forEach((f) => f());
  clearInterval(pingTimer);
  if (party.value) ws.leave(`party:${party.value.id}`);
});
</script>

<template>
  <div v-if="loading" class="page"><div class="skeleton" style="aspect-ratio:16/9; max-width: 900px; border-radius: var(--radius)"></div></div>
  <div v-else-if="error" class="page">
    <EmptyState icon="accounts" title="Комната недоступна" :text="error.message">
      <router-link to="/" class="btn primary">На главную</router-link>
    </EmptyState>
  </div>
  <div v-else class="party-page">
    <div class="party-main">
      <div class="row gap-8 mb-8" style="align-items:center">
        <span class="badge brand"><Icon name="accounts" :size="12" /> Смотрим вместе</span>
        <span v-if="party.endedAt" class="badge">Комната закрыта</span>
        <span class="small muted grow ellipsis">{{ party.members?.length || 1 }} участников · ведущий {{ party.isHost ? 'вы' : '' }}</span>
        <button class="btn sm soft" @click="copyLink"><Icon name="link" :size="16" /> Ссылка</button>
        <router-link class="btn sm soft" :to="`/watch/${video.shortId}`"><Icon name="openNew" :size="16" /> Открыть обычно</router-link>
        <button v-if="party.isHost && !party.endedAt" class="btn sm" @click="endParty"><Icon name="stop" :size="16" /> Закрыть</button>
      </div>
      <VideoPlayer
        ref="player" :key="video.id" :src="media.hlsUrl" :mp4="media.mp4Url" :poster="video.thumbnailUrl"
        :duration="video.duration" :start-at="startAt" :title="video.title" :autoplay="false"
        :qoe-video-id="video.id" qoe-source="party" :qoe="auth.config?.qoeEnabled !== false"
        @play="push(true)" @pause="push(false)" />
      <h1 class="watch-title mt-16">{{ video.title }}</h1>
      <div class="row gap-8 small muted">
        <span>{{ video.owner?.displayName }}</span><span>·</span><span>{{ fmtDuration(video.duration) }}</span>
        <span v-if="!canControl">· управляет ведущий</span>
      </div>
      <p v-if="!party.endedAt" class="small muted mt-8">
        Пауза и перемотка {{ canControl ? 'у вас общие с комнатой — все увидят то же самое.' : 'доступны ведущему: ваш плеер подстраивается автоматически.' }}
      </p>
    </div>
    <aside class="party-side">
      <div class="panel party-chat">
        <div class="row gap-8 pc-head"><Icon name="comment" :size="16" /><b>Чат комнаты</b><span class="tiny muted grow" style="text-align:right">{{ messages.length }}</span></div>
        <div class="pc-list" ref="list">
          <p v-if="!messages.length" class="small muted" style="text-align:center; padding: 16px">Пока тихо — напишите первым</p>
          <div v-for="m in messages" :key="m.id" class="pc-msg">
            <ChannelAvatar :user="m.user" size="xs" />
            <div class="grow"><span class="pc-name">{{ m.user.displayName }}</span> <span class="pc-body">{{ m.body }}</span></div>
          </div>
        </div>
        <form class="row gap-8 pc-form" @submit.prevent="send">
          <input v-model="text" class="input" maxlength="500" placeholder="Написать…" />
          <button class="ibtn primary" :disabled="!text.trim()" type="submit" aria-label="Отправить"><Icon name="send" :size="18" /></button>
        </form>
      </div>
    </aside>
  </div>
</template>

<style>
.party-page { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 24px; padding: 24px; max-width: 1600px; margin: 0 auto; }
.party-main { min-width: 0; }
.party-side { min-width: 0; }
.party-chat { display: flex; flex-direction: column; padding: 0; overflow: hidden; height: min(70vh, 640px); position: sticky; top: calc(var(--header-h) + 16px); }
.party-chat .pc-head { padding: 10px 12px; border-bottom: 1px solid var(--line-2); align-items: center; }
.party-chat .pc-list { flex: 1; overflow-y: auto; padding: 8px 12px; }
.party-chat .pc-msg { display: flex; gap: 8px; padding: 4px 0; font-size: 13px; align-items: flex-start; }
.party-chat .pc-name { font-weight: 600; color: var(--text-2); margin-right: 4px; }
.party-chat .pc-body { overflow-wrap: anywhere; }
.party-chat .pc-form { padding: 8px 12px; border-top: 1px solid var(--line-2); }
@media (max-width: 1023px) {
  .party-page { grid-template-columns: 1fr; padding: 16px; }
  .party-chat { height: 420px; position: static; }
}
</style>
