<script setup>
// Панель «Текст на экране»: распознанные надписи со слайдов с переходом по времени и поиском.
import { ref, computed, onMounted, watch } from 'vue';
import { get } from '../../api.js';
import { fmtDuration } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['close']);
const items = ref([]);
const loading = ref(true);
const query = ref('');
const filtered = computed(() => { const q = query.value.trim().toLowerCase(); return items.value.filter((i) => !q || i.text.toLowerCase().includes(q)); });
async function load() { loading.value = true; try { items.value = (await get(`/api/videos/${props.video.shortId}/screen-text`)).items; } catch { items.value = []; } finally { loading.value = false; } }
onMounted(load);
watch(() => props.video.id, load);
function seek(t) { props.player?.seekTo(t); props.player?.play(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
function highlight(text) {
  const q = query.value.trim();
  const esc = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  if (!q) return esc(text).replace(/\n/g, '<br>');
  const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig');
  return esc(text).replace(re, (m) => `<mark>${m}</mark>`).replace(/\n/g, '<br>');
}
</script>

<template>
  <div class="transcript panel soft">
    <div class="row mb-8" style="align-items:center"><h4 class="grow"><Icon name="ocr" :size="18" style="vertical-align:-3px" /> Текст на экране</h4><button class="ibtn sm" @click="$emit('close')" aria-label="Закрыть текст на экране"><Icon name="close" :size="18" /></button></div>
    <input class="input sm mb-8" v-model="query" placeholder="Поиск по надписям на слайдах…" />
    <div v-if="loading" class="muted small">Загрузка…</div>
    <div v-else-if="!items.length" class="muted small">Текст на экране не распознан.</div>
    <div v-else class="tr-list">
      <div v-for="(it, i) in filtered" :key="i" class="tr-cue st-cue" @click="seek(it.t)"><span class="ts">{{ fmtDuration(it.t) }}</span><span class="st-text" v-html="highlight(it.text)"></span></div>
      <div v-if="!filtered.length" class="muted small">Ничего не найдено</div>
    </div>
  </div>
</template>

<style>
.st-cue .st-text { white-space: normal; }
.st-cue mark { background: var(--warning-bg, #fff3bf); color: inherit; border-radius: 2px; }
</style>
