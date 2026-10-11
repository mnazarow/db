<script setup>
// ИИ-помощник автора: по транскрипту предлагает название, описание-конспект, главы и теги. Автор применяет выборочно.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import * as ws from '../../ws.js';
import { fmtDuration, fmtDateTime } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true } });
const emit = defineEmits(['apply']);
const ui = useUi();
const auth = useAuth();
const suggestions = ref(props.video.aiSuggestions || null);
const pending = ref(false);
const fields = ref({ title: true, summary: true, chapters: true, tags: true });
let off = null;

const hasTranscript = computed(() => (props.video.subtitles || []).some((s) => s.status === 'ready') || props.video.hasTranscript);
async function refresh() { try { const r = await get(`/api/videos/${props.video.id}/ai`); suggestions.value = r.suggestions; pending.value = r.pending; } catch { /* ignore */ } }
onMounted(() => { refresh(); off = ws.on('video.ai', (m) => { if (m.videoId === props.video.id) { if (m.status === 'ready') { refresh(); ui.toast('ИИ подготовил подсказки', { type: 'success' }); } else if (m.status === 'failed') { pending.value = false; ui.toast('ИИ: ' + (m.error || 'ошибка'), { type: 'error' }); } } }); });
onBeforeUnmount(() => off?.());
async function run() {
  const list = Object.entries(fields.value).filter(([, v]) => v).map(([k]) => k);
  try { await post(`/api/videos/${props.video.id}/ai`, { fields: list }); pending.value = true; ui.toast('Запрос отправлен — подсказки появятся через минуту-другую'); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function dismiss() { await del(`/api/videos/${props.video.id}/ai`); suggestions.value = null; }
function apply(kind) { emit('apply', { kind, value: suggestions.value[kind] }); ui.toast('Подставлено в форму — проверьте и сохраните', { type: 'success' }); }
const chaptersText = computed(() => (suggestions.value?.chapters || []).map((c) => `${fmtDuration(c.start)} ${c.title}`).join('\n'));
</script>

<template>
  <div class="panel ai-panel">
    <div class="row wrap mb-8" style="align-items:flex-start">
      <div class="grow"><h4 class="mb-4"><Icon name="sparkles" :size="18" style="vertical-align:-3px; color: var(--brand)" /> ИИ-помощник</h4><p class="small muted" style="margin:0">Предложит название, описание-конспект, главы и теги по транскрипту (субтитрам). Ничего не применяется автоматически — вы выбираете, что взять.</p></div>
    </div>
    <div v-if="!auth.config?.aiEnabled" class="small muted">Функция отключена администратором (Настройки → ИИ).</div>
    <template v-else>
      <div class="row wrap gap-12 mb-8" style="align-items:center">
        <label v-for="[k, l] in [['title', 'Название'], ['summary', 'Описание'], ['chapters', 'Главы'], ['tags', 'Теги']]" :key="k" class="check small"><input type="checkbox" v-model="fields[k]" /> {{ l }}</label>
        <button class="btn primary sm" :disabled="pending || !hasTranscript || !Object.values(fields).some(Boolean)" @click="run"><Icon name="sparkles" :size="16" /> {{ pending ? 'Генерация…' : suggestions ? 'Сгенерировать заново' : 'Сгенерировать' }}</button>
        <span v-if="pending" class="spin sm"></span>
      </div>
      <div v-if="!hasTranscript" class="small muted">Нужны субтитры: загрузите их или создайте автоматически на вкладке «Субтитры».</div>
      <div v-if="suggestions?.error" class="alert danger mt-8"><Icon name="alertCircle" :size="18" /> {{ suggestions.error }}</div>
      <div v-else-if="suggestions" class="col gap-12 mt-8">
        <div class="tiny muted">Сгенерировано {{ fmtDateTime(suggestions.generatedAt) }}<span v-if="suggestions.model"> · модель {{ suggestions.model }}</span></div>
        <div v-if="suggestions.title" class="ai-item"><div class="l">Название</div><div class="v">{{ suggestions.title }}</div><button class="btn sm" @click="apply('title')">Применить</button></div>
        <div v-if="suggestions.summary" class="ai-item"><div class="l">Описание</div><div class="v" style="white-space: pre-wrap">{{ suggestions.summary }}</div><button class="btn sm" @click="apply('summary')">Применить</button></div>
        <div v-if="suggestions.chapters?.length" class="ai-item"><div class="l">Главы</div><div class="v" style="white-space: pre-wrap">{{ chaptersText }}</div><button class="btn sm" @click="apply('chapters')">Применить</button></div>
        <div v-if="suggestions.tags?.length" class="ai-item"><div class="l">Теги</div><div class="v"><span v-for="t in suggestions.tags" :key="t" class="chip" style="height: 26px; margin: 2px 4px 2px 0">#{{ t }}</span></div><button class="btn sm" @click="apply('tags')">Применить</button></div>
        <div><button class="btn ghost sm" @click="dismiss">Скрыть подсказки</button></div>
      </div>
    </template>
  </div>
</template>

<style>
.ai-panel { border: 1px solid var(--brand-100); }
.ai-item { display: grid; grid-template-columns: 90px 1fr auto; gap: 10px; align-items: start; padding: 10px 12px; border-radius: var(--radius-sm); background: var(--bg-2); }
.ai-item .l { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); padding-top: 4px; }
.ai-item .v { font-size: 14px; line-height: 1.45; min-width: 0; }
@media (max-width: 640px) { .ai-item { grid-template-columns: 1fr; } }
</style>
