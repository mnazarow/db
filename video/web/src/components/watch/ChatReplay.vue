<script setup>
// Повтор чата эфира в записи: сообщения появляются синхронно с воспроизведением (как на Twitch и YouTube).
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { get } from '../../api.js';
import { fmtDuration } from '../../utils/format.js';
import ChannelAvatar from '../ChannelAvatar.vue';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['close']);
const all = ref([]);
const loading = ref(true);
const time = ref(0);
const list = ref(null);
let timer = null;
const visible = computed(() => all.value.filter((m) => m.offset <= time.value));

async function load() {
  loading.value = true;
  try { const r = await get(`/api/videos/${props.video.shortId}/chat-replay`); all.value = r.available ? r.messages : []; } catch { all.value = []; } finally { loading.value = false; }
}
onMounted(() => { load(); timer = setInterval(() => { const t = props.player?.currentTime?.(); if (Number.isFinite(t)) time.value = t; }, 500); });
onBeforeUnmount(() => clearInterval(timer));
watch(() => props.video.id, load);
watch(() => visible.value.length, () => nextTick(() => { if (list.value) list.value.scrollTop = list.value.scrollHeight; }));
</script>

<template>
  <div class="transcript panel soft chat-replay">
    <div class="row mb-8" style="align-items:center"><h4 class="grow"><Icon name="chatReplay" :size="18" style="vertical-align:-3px" /> Чат эфира</h4><span class="tiny muted">{{ visible.length }} / {{ all.length }}</span><button class="ibtn sm" @click="$emit('close')" aria-label="Закрыть чат эфира"><Icon name="close" :size="18" /></button></div>
    <div v-if="loading" class="muted small">Загрузка…</div>
    <div v-else-if="!all.length" class="muted small">Сообщений чата за время записи не было.</div>
    <div v-else ref="list" class="tr-list cr-list">
      <div v-for="m in visible" :key="m.id" class="cr-msg"><ChannelAvatar :user="m.user" size="xs" /><div class="grow" style="min-width:0"><span class="cr-time">{{ fmtDuration(m.offset) }}</span> <b class="cr-name" :class="{ staff: ['admin', 'moderator'].includes(m.user.role) }">{{ m.user.displayName }}</b> <span class="cr-body">{{ m.body }}</span></div></div>
      <div v-if="!visible.length" class="muted small">Сообщения появятся по ходу воспроизведения</div>
    </div>
  </div>
</template>

<style>
.cr-list { gap: 6px; }
.cr-msg { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.35; }
.cr-time { color: var(--brand); font-variant-numeric: tabular-nums; font-size: 12px; }
.cr-name.staff { color: var(--brand); }
.cr-body { word-break: break-word; }
</style>
