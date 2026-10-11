<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { get, post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import * as ws from '../../ws.js';
import ChannelAvatar from '../ChannelAvatar.vue';

const props = defineProps({ video: { type: Object, required: true }, compact: Boolean });
const auth = useAuth();
const ui = useUi();
const messages = ref([]);
const text = ref('');
const list = ref(null);
const sending = ref(false);
let off = [];

function scrollDown() { nextTick(() => { if (list.value) list.value.scrollTop = list.value.scrollHeight; }); }

async function load() {
  try {
    const r = await get(`/api/videos/${props.video.shortId}/premiere`);
    messages.value = r.messages || [];
    scrollDown();
  } catch { /* чат не критичен */ }
}

async function send() {
  const body = text.value.trim();
  if (!body || sending.value) return;
  sending.value = true;
  try { await post(`/api/videos/${props.video.shortId}/premiere/chat`, { body }); text.value = ''; } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { sending.value = false; }
}

onMounted(() => {
  load();
  ws.join(`premiere:${props.video.id}`);
  off.push(ws.on('room.message', (m) => {
    if (m.room !== `premiere:${props.video.id}`) return;
    messages.value.push(m.message);
    if (messages.value.length > 300) messages.value.splice(0, 50);
    scrollDown();
  }), ws.on('open', () => ws.join(`premiere:${props.video.id}`)));
});
onBeforeUnmount(() => { off.forEach((f) => f()); ws.leave(`premiere:${props.video.id}`); });
</script>

<template>
  <div class="panel premiere-chat" :class="{ compact }">
    <div class="row gap-8 pc-head"><Icon name="comment" :size="16" /><b>Чат премьеры</b><span class="tiny muted grow" style="text-align:right">{{ messages.length }}</span></div>
    <div class="pc-list" ref="list">
      <p v-if="!messages.length" class="small muted" style="text-align:center; padding: 16px">Сообщений пока нет — напишите первым</p>
      <div v-for="m in messages" :key="m.id" class="pc-msg">
        <ChannelAvatar :user="m.user" size="xs" />
        <div class="grow"><span class="pc-name">{{ m.user.displayName }}</span> <span class="pc-body">{{ m.body }}</span></div>
      </div>
    </div>
    <form v-if="auth.isActive" class="row gap-8 pc-form" @submit.prevent="send">
      <input v-model="text" class="input" maxlength="500" placeholder="Написать в чат премьеры…" />
      <button class="ibtn primary" :disabled="!text.trim() || sending" type="submit" aria-label="Отправить"><Icon name="send" :size="18" /></button>
    </form>
    <p v-else class="small muted" style="padding: 8px 12px">Войдите, чтобы писать в чат</p>
  </div>
</template>

<style>
.premiere-chat { display: flex; flex-direction: column; padding: 0; overflow: hidden; max-height: 420px; }
.premiere-chat .pc-head { padding: 10px 12px; border-bottom: 1px solid var(--line-2); align-items: center; }
.premiere-chat .pc-list { flex: 1; overflow-y: auto; padding: 8px 12px; min-height: 120px; }
.premiere-chat .pc-msg { display: flex; gap: 8px; padding: 4px 0; font-size: 13px; align-items: flex-start; }
.premiere-chat .pc-name { font-weight: 600; color: var(--text-2); margin-right: 4px; }
.premiere-chat .pc-body { overflow-wrap: anywhere; }
.premiere-chat .pc-form { padding: 8px 12px; border-top: 1px solid var(--line-2); }
.premiere-chat.compact { max-height: 260px; }
</style>
