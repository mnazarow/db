<script setup>
// Итоги встречи: краткое содержание, решения, задачи и открытые вопросы с переходом к моменту записи.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post, del } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import { copyText } from '../../utils/clipboard.js';
import { fmtDuration } from '../../utils/format.js';
import { url } from '../../base.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const auth = useAuth();
const ui = useUi();
const data = ref(null);       // { notes, canMake, enabled }
const busy = ref(false);
const tab = ref('summary');
let poll = null;

const notes = computed(() => data.value?.notes || null);
const ready = computed(() => notes.value?.status === 'ready');
const TABS = computed(() => [
  { id: 'summary', label: 'Кратко', n: null },
  { id: 'decisions', label: 'Решения', n: notes.value?.decisions?.length || 0 },
  { id: 'tasks', label: 'Задачи', n: notes.value?.tasks?.length || 0 },
  { id: 'topics', label: 'Темы', n: notes.value?.topics?.length || 0 },
  { id: 'questions', label: 'Вопросы', n: notes.value?.questions?.length || 0 },
]);

async function load() {
  try { data.value = await get(`/api/videos/${props.video.shortId}/meeting-notes`); } catch { data.value = null; }
  if (notes.value?.status === 'processing' || notes.value?.status === 'queued') startPoll(); else stopPoll();
}
function startPoll() { if (!poll) poll = setInterval(load, 4000); }
function stopPoll() { clearInterval(poll); poll = null; }

async function make() {
  busy.value = true;
  try {
    await post(`/api/videos/${props.video.id}/meeting-notes`, { force: true });
    ui.toast('Разбираю запись — итоги появятся здесь через минуту');
    await load();
    startPoll();
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = false; }
}

async function remove() {
  if (!(await ui.ask({ title: 'Удалить итоги?', message: 'Конспект можно будет собрать заново.', okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/videos/${props.video.id}/meeting-notes`); data.value = { ...data.value, notes: null }; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

function seek(at) { props.player?.seekTo?.(Math.max(0, at)); props.player?.play?.(); }

async function copyAll() {
  try {
    const r = await fetch(url(`/api/videos/${props.video.shortId}/meeting-notes/export`), { credentials: 'include' });
    if (await copyText(await r.text())) ui.toast('Итоги скопированы — можно вставить в письмо');
  } catch { ui.toast('Не удалось скопировать', { type: 'error' }); }
}

onMounted(load);
onBeforeUnmount(stopPoll);
</script>

<template>
  <div v-if="data && (notes || data.canMake)" class="panel notes-panel">
    <div class="row gap-8 np-head">
      <Icon name="clipboardList" :size="18" />
      <b class="grow">Итоги встречи</b>
      <template v-if="ready">
        <button class="btn sm soft" @click="copyAll"><Icon name="copy" :size="16" /> Скопировать</button>
        <a class="btn sm soft" :href="$url(`/api/videos/${video.shortId}/meeting-notes/export?format=csv`)"><Icon name="csv" :size="16" /> CSV</a>
        <button v-if="data.canMake" class="btn sm soft" :disabled="busy" title="Собрать заново" @click="make" aria-label="Собрать заново"><Icon name="refresh" :size="16" /></button>
        <button v-if="data.canMake" class="btn sm soft" title="Удалить" @click="remove" aria-label="Удалить"><Icon name="delete" :size="16" /></button>
      </template>
    </div>

    <div v-if="!notes" class="np-empty">
      <p class="small muted">ИИ прочитает расшифровку записи и соберёт краткое содержание, решения, задачи и открытые вопросы — с переходом к нужному моменту.</p>
      <button class="btn primary sm" :disabled="busy" @click="make"><Icon name="sparkles" :size="16" /> Собрать итоги встречи</button>
    </div>
    <div v-else-if="notes.status === 'failed'" class="np-empty">
      <p class="small" style="color: var(--danger)">{{ notes.error || 'Не удалось собрать итоги' }}</p>
      <button v-if="data.canMake" class="btn sm" :disabled="busy" @click="make"><Icon name="refresh" :size="16" /> Попробовать снова</button>
    </div>
    <div v-else-if="!ready" class="row gap-8 np-empty" style="align-items:center">
      <div class="spin"></div><span class="small muted">Разбираю запись…</span>
    </div>
    <template v-else>
      <div class="np-tabs">
        <button v-for="t in TABS" :key="t.id" class="np-tab" :class="{ on: tab === t.id }" @click="tab = t.id">
          {{ t.label }}<b v-if="t.n">{{ t.n }}</b>
        </button>
      </div>
      <div class="np-body">
        <p v-if="tab === 'summary'" class="np-summary">{{ notes.summary || 'Краткого содержания нет' }}</p>
        <template v-else-if="tab === 'decisions'">
          <p v-if="!notes.decisions.length" class="small muted">Решений не зафиксировано</p>
          <button v-for="(d, i) in notes.decisions" :key="i" class="np-item" @click="seek(d.at)">
            <span class="np-at">{{ fmtDuration(d.at) }}</span><span class="grow">{{ d.text }}</span>
          </button>
        </template>
        <template v-else-if="tab === 'tasks'">
          <p v-if="!notes.tasks.length" class="small muted">Задач не зафиксировано</p>
          <button v-for="(t, i) in notes.tasks" :key="i" class="np-item" @click="seek(t.at)">
            <span class="np-at">{{ fmtDuration(t.at) }}</span>
            <span class="grow">{{ t.text }}<span v-if="t.who" class="np-who">{{ t.who }}<template v-if="t.due">, {{ t.due }}</template></span></span>
          </button>
        </template>
        <template v-else-if="tab === 'topics'">
          <p v-if="!notes.topics.length" class="small muted">Тем не выделено</p>
          <button v-for="(t, i) in notes.topics" :key="i" class="np-item" @click="seek(t.at)">
            <span class="np-at">{{ fmtDuration(t.at) }}</span>
            <span class="grow"><b>{{ t.title }}</b><template v-if="t.text && t.text !== t.title"> — {{ t.text }}</template></span>
          </button>
        </template>
        <template v-else>
          <p v-if="!notes.questions.length" class="small muted">Открытых вопросов нет</p>
          <button v-for="(q, i) in notes.questions" :key="i" class="np-item" @click="seek(q.at)">
            <span class="np-at">{{ fmtDuration(q.at) }}</span><span class="grow">{{ q.text }}</span>
          </button>
        </template>
      </div>
      <p class="tiny muted np-foot">Составлено ИИ по расшифровке — проверяйте по записи.</p>
    </template>
  </div>
</template>

<style>
.notes-panel { padding: 0; overflow: hidden; }
.notes-panel .np-head { padding: 10px 12px; border-bottom: 1px solid var(--line-2); align-items: center; }
.notes-panel .np-empty { padding: 14px 12px; display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
.notes-panel .np-tabs { display: flex; gap: 4px; padding: 8px 12px 0; flex-wrap: wrap; }
.notes-panel .np-tab { padding: 6px 10px; border-radius: 999px; font-size: 13px; color: var(--text-2); display: inline-flex; gap: 6px; align-items: center; }
.notes-panel .np-tab.on { background: var(--brand-100); color: var(--brand); font-weight: 600; }
.notes-panel .np-tab b { font-size: 11px; opacity: .8; }
.notes-panel .np-body { padding: 8px 12px 12px; max-height: 320px; overflow-y: auto; }
.notes-panel .np-summary { margin: 4px 0; line-height: 1.5; }
.notes-panel .np-item { display: flex; gap: 10px; width: 100%; text-align: left; padding: 7px 8px; border-radius: 8px; font-size: 14px; align-items: flex-start; }
.notes-panel .np-item:hover { background: var(--bg-2); }
.notes-panel .np-at { color: var(--brand); font-variant-numeric: tabular-nums; font-size: 13px; flex-shrink: 0; padding-top: 1px; }
.notes-panel .np-who { display: block; font-size: 12px; color: var(--text-3); }
.notes-panel .np-foot { padding: 0 12px 10px; }
</style>
