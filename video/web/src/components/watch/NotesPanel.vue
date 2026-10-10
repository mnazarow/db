<script setup>
// Личные заметки зрителя с таймкодами: список, добавление на текущем времени, редактирование, экспорт.
import { ref, onMounted, nextTick } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDuration } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['close', 'seek', 'count']);
const ui = useUi();
const notes = ref([]);
const loading = ref(true);
const draft = ref('');
const draftAt = ref(0);
const editing = ref(null); // id
const editText = ref('');
const input = ref(null);

async function load() { loading.value = true; try { notes.value = (await get(`/api/videos/${props.video.shortId}/notes`)).notes; emit('count', notes.value.length); } finally { loading.value = false; } }
onMounted(() => { load(); draftAt.value = Math.floor(props.player?.currentTime?.() || 0); });
function stamp() { draftAt.value = Math.floor(props.player?.currentTime?.() || 0); nextTick(() => input.value?.focus()); }
async function add() {
  const body = draft.value.trim(); if (!body) return;
  try { const r = await post(`/api/videos/${props.video.shortId}/notes`, { position: draftAt.value, body }); notes.value.push(r.note); notes.value.sort((a, b) => a.position - b.position); draft.value = ''; emit('count', notes.value.length); stamp(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function startEdit(n) { editing.value = n.id; editText.value = n.body; }
async function saveEdit(n) { const body = editText.value.trim(); if (!body) return; try { const r = await patch(`/api/videos/${props.video.shortId}/notes/${n.id}`, { body }); Object.assign(n, r.note); editing.value = null; } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function remove(n) { await del(`/api/videos/${props.video.shortId}/notes/${n.id}`); notes.value = notes.value.filter((x) => x.id !== n.id); emit('count', notes.value.length); }
function onKey(e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) add(); }
</script>

<template>
  <div class="notes panel soft">
    <div class="row mb-8" style="justify-content: space-between; align-items: center">
      <span class="head" style="font-size: 18px">Мои заметки</span>
      <div class="row gap-4"><a v-if="notes.length" class="ibtn" title="Скачать как Markdown" :href="$url(`/api/videos/${video.shortId}/notes/export`)" aria-label="Скачать как Markdown"><Icon name="download" :size="20" /></a><button class="ibtn" title="Закрыть" @click="$emit('close')" aria-label="Закрыть"><Icon name="close" /></button></div>
    </div>
    <div class="note-add">
      <button class="chip brand" :title="'Заметка на текущем времени'" @click="stamp"><Icon name="timer" :size="14" /> {{ fmtDuration(draftAt) }}</button>
      <textarea ref="input" class="textarea sm" v-model="draft" rows="2" placeholder="Что важно запомнить в этот момент? (Ctrl+Enter — сохранить)" @keydown="onKey"></textarea>
      <button class="btn primary sm" :disabled="!draft.trim()" @click="add"><Icon name="plus" :size="16" /> Добавить</button>
    </div>
    <div v-if="loading" class="muted small">Загрузка…</div>
    <div v-else-if="!notes.length" class="muted small mt-8">Заметки видите только вы. Нажмите на таймкод заметки, чтобы перейти к этому месту видео.</div>
    <div v-else class="note-list mt-8">
      <div v-for="n in notes" :key="n.id" class="note">
        <button class="ts" @click="$emit('seek', n.position)">{{ fmtDuration(n.position) }}</button>
        <div class="grow" style="min-width:0">
          <template v-if="editing === n.id"><textarea class="textarea sm" v-model="editText" rows="2" @keydown.enter.ctrl="saveEdit(n)"></textarea><div class="row gap-4 mt-4"><button class="btn primary sm" @click="saveEdit(n)">Сохранить</button><button class="btn ghost sm" @click="editing = null">Отмена</button></div></template>
          <div v-else class="note-body" @dblclick="startEdit(n)">{{ n.body }}</div>
        </div>
        <div class="row gap-0 note-actions"><button class="ibtn sm" title="Изменить" @click="startEdit(n)" aria-label="Изменить"><Icon name="edit" :size="14" /></button><button class="ibtn sm" title="Удалить" @click="remove(n)" aria-label="Удалить"><Icon name="close" :size="14" /></button></div>
      </div>
    </div>
  </div>
</template>

<style>
.notes { margin-bottom: 16px; }
.note-add { display: grid; grid-template-columns: auto 1fr; gap: 8px; align-items: start; }
.note-add .btn { grid-column: 2; justify-self: start; }
.note-list { max-height: 380px; overflow: auto; display: flex; flex-direction: column; gap: 4px; padding-right: 4px; }
.note { display: flex; gap: 10px; align-items: flex-start; padding: 6px 8px; border-radius: 8px; font-size: 13px; }
.note:hover { background: var(--surface-2); }
.note .ts { color: var(--brand); font-weight: 500; font-variant-numeric: tabular-nums; background: none; border: 0; padding: 0; cursor: pointer; font: inherit; font-weight: 500; flex: 0 0 44px; text-align: left; }
.note-body { white-space: pre-wrap; line-height: 1.4; }
.note-actions { opacity: 0; transition: opacity .15s; }
.note:hover .note-actions { opacity: 1; }
.textarea.sm { padding: 6px 10px; font-size: 13px; min-height: 0; }
</style>
