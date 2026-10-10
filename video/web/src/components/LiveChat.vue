<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { get, post, del } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import * as ws from '../ws.js';
import ChannelAvatar from './ChannelAvatar.vue';

const props = defineProps({ stream: { type: Object, required: true } });
const auth = useAuth();
const ui = useUi();
const messages = ref([]);
const text = ref('');
const list = ref(null);
const viewers = ref(props.stream.viewerCount || 0);
const autoScroll = ref(true);
let off = [];

function scrollDown() { nextTick(() => { if (list.value && autoScroll.value) list.value.scrollTop = list.value.scrollHeight; }); }
function onScroll() { const el = list.value; autoScroll.value = el.scrollHeight - el.scrollTop - el.clientHeight < 60; }
async function load() { messages.value = (await get(`/api/live/${props.stream.shortId}/chat?limit=100`)).messages; scrollDown(); }
async function send() {
  const body = text.value.trim(); if (!body) return;
  try { await post(`/api/live/${props.stream.shortId}/chat`, { body }); text.value = ''; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function remove(m) { await del(`/api/live/${props.stream.shortId}/chat/${m.id}`); }
async function ban(m) { if (await ui.ask({ title: 'Заблокировать в чате?', message: `${m.user.displayName} больше не сможет писать в этой трансляции.`, okLabel: 'Заблокировать', danger: true })) await post(`/api/live/${props.stream.shortId}/ban`, { userId: m.user.id }); }
const canModerate = (m) => auth.user && (auth.user.id === props.stream.owner?.id || auth.isStaff) && m.user.id !== auth.user.id;

onMounted(() => {
  load();
  ws.join(`live:${props.stream.id}`);
  off.push(
    ws.on('chat', (m) => { if (m.streamId === props.stream.id) { messages.value.push(m.message); if (messages.value.length > 300) messages.value.splice(0, 50); scrollDown(); } }),
    ws.on('chat_delete', (m) => { if (m.streamId === props.stream.id) messages.value = messages.value.filter((x) => x.id !== m.messageId); }),
    ws.on('chat_ban', (m) => { if (m.streamId === props.stream.id) messages.value = messages.value.filter((x) => x.user.id !== m.userId); }),
    ws.on('viewers', (m) => { if (m.streamId === props.stream.id) viewers.value = m.count; }),
    ws.on('open', () => ws.join(`live:${props.stream.id}`)),
  );
});
onBeforeUnmount(() => { ws.leave(`live:${props.stream.id}`); off.forEach((f) => f()); });
defineExpose({ viewers });
</script>

<template>
  <div class="live-chat">
    <div class="lc-head"><span class="head">Чат</span><span class="small muted row gap-4"><Icon name="eye" :size="16" /> {{ viewers }}</span></div>
    <div class="lc-list" ref="list" @scroll="onScroll">
      <div v-for="m in messages" :key="m.id" class="lc-msg" :class="{ owner: m.user.id === stream.owner?.id }">
        <ChannelAvatar :user="m.user" size="xs" />
        <div class="grow" style="min-width:0"><span class="lc-name">{{ m.user.displayName }}<span v-if="m.user.id === stream.owner?.id" class="badge brand" style="margin-left:6px;font-size:9px">автор</span></span> <span class="lc-text">{{ m.body }}</span></div>
        <div v-if="canModerate(m)" class="lc-mod"><button class="ibtn sm" title="Удалить" @click="remove(m)" aria-label="Удалить"><Icon name="delete" :size="14" /></button><button class="ibtn sm" title="Заблокировать" @click="ban(m)" aria-label="Заблокировать"><Icon name="cancel" :size="14" /></button></div>
      </div>
      <div v-if="!messages.length" class="muted small" style="text-align:center; padding: 24px">Сообщений пока нет — напишите первым</div>
    </div>
    <button v-if="!autoScroll" class="lc-jump" @click="autoScroll = true; scrollDown()"><Icon name="chevronDown" :size="16" /> Новые сообщения</button>
    <form v-if="auth.isActive && stream.chatEnabled" class="lc-form" @submit.prevent="send">
      <input class="input" v-model="text" maxlength="500" placeholder="Написать в чат…" />
      <button class="btn primary icon-only" type="submit" :disabled="!text.trim()" aria-label="Отправить сообщение"><Icon name="send" :size="18" /></button>
    </form>
    <div v-else class="lc-form small muted" style="justify-content:center">{{ !stream.chatEnabled ? 'Чат отключён' : 'Войдите, чтобы писать в чат' }}</div>
  </div>
</template>

<style>
.live-chat { display: flex; flex-direction: column; height: 100%; min-height: 360px; border: 1px solid var(--line-2); border-radius: var(--radius); background: var(--surface); position: relative; }
.lc-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid var(--line-2); font-size: 18px; }
.lc-list { flex: 1; overflow-y: auto; padding: 8px 10px; display: flex; flex-direction: column; gap: 6px; }
.lc-msg { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.4; padding: 2px 4px; border-radius: 6px; }
.lc-msg:hover { background: var(--bg-2); }
.lc-msg:hover .lc-mod { visibility: visible; }
.lc-mod { visibility: hidden; display: flex; }
.lc-name { font-weight: 500; color: var(--text-2); }
.lc-msg.owner .lc-name { color: var(--brand); }
.lc-text { word-break: break-word; }
.lc-form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--line-2); }
.lc-jump { position: absolute; bottom: 64px; left: 50%; transform: translateX(-50%); padding: 4px 12px; border-radius: var(--pill); background: var(--brand-500); color: #fff; font-size: 12px; display: flex; align-items: center; gap: 4px; }
</style>
