<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { get, del } from '../api.js';

const emit = defineEmits(['close']);
const router = useRouter();
const route = useRoute();
const q = ref(String(route.query.q || ''));
const suggestions = ref([]);
const open = ref(false);
const active = ref(-1);
const box = ref(null);
let timer = null;

watch(() => route.query.q, (v) => { if (route.name === 'search') q.value = String(v || ''); });

// Ответы подсказок могут прийти не по порядку: «аб» позже «абв». Показываем только ответ
// на последний запрос и только пока поле в фокусе (после Enter меню не должно всплывать снова).
let seq = 0;
const focused = ref(false);
async function fetchSuggestions() {
  const my = ++seq;
  const text = q.value.trim();
  try {
    const r = await get(`/api/search/suggest?q=${encodeURIComponent(text)}`);
    if (my !== seq || !focused.value) return;
    suggestions.value = r.suggestions || [];
    open.value = suggestions.value.length > 0;
    active.value = -1;
  } catch { if (my === seq) suggestions.value = []; }
}
function onInput() {
  clearTimeout(timer);
  timer = setTimeout(fetchSuggestions, 180);
}
function onFocus() { focused.value = true; fetchSuggestions(); }
function onBlur() { focused.value = false; }
function submit(text) {
  const query = (text ?? q.value).trim();
  clearTimeout(timer); seq++;          // запрос подсказок «в полёте» больше не нужен
  open.value = false;
  emit('close');
  if (!query) return;
  q.value = query;
  router.push({ name: 'search', query: { ...(route.name === 'search' ? route.query : {}), q: query, page: undefined } });
}
function pick(s) {
  if (s.kind === 'channel' && s.handle) { open.value = false; router.push(`/@${s.handle}`); return; }
  submit(s.text);
}
function onKey(e) {
  if (!open.value) { if (e.key === 'Enter') submit(); return; }
  if (e.key === 'ArrowDown') { e.preventDefault(); active.value = (active.value + 1) % suggestions.value.length; }
  else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = (active.value - 1 + suggestions.value.length) % suggestions.value.length; }
  else if (e.key === 'Enter') { e.preventDefault(); if (active.value >= 0) pick(suggestions.value[active.value]); else submit(); }
  else if (e.key === 'Escape') { open.value = false; }
}
async function removeHistory(s, e) {
  e.stopPropagation();
  try { await del(`/api/search/history?q=${encodeURIComponent(s.text)}`); suggestions.value = suggestions.value.filter((x) => x !== s); } catch { /* ignore */ }
}
function onDocClick(e) { if (box.value && !box.value.contains(e.target)) open.value = false; }
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
  <div class="searchbox" ref="box">
    <div class="sb-input">
      <input v-model="q" type="search" placeholder="Поиск видео, каналов, плейлистов" @input="onInput" @focus="onFocus" @blur="onBlur" @keydown="onKey" autocomplete="off" aria-label="Поиск"
        role="combobox" aria-autocomplete="list" :aria-expanded="open ? 'true' : 'false'" aria-controls="sb-suggestions" :aria-activedescendant="open && active >= 0 ? `sb-opt-${active}` : undefined" />
      <button class="sb-btn" @click="submit()" aria-label="Найти"><Icon name="search" /></button>
    </div>
    <div v-if="open" id="sb-suggestions" class="menu sb-menu" role="listbox" aria-label="Подсказки поиска">
      <!-- Вариант — не кнопка: внутри него своя кнопка «удалить из истории», а кнопка в кнопке недопустима -->
      <div v-for="(s, i) in suggestions" :id="`sb-opt-${i}`" :key="s.kind + s.text" class="item" role="option" :aria-selected="i === active ? 'true' : 'false'" :class="{ hover: i === active }" @mousedown.prevent="pick(s)">
        <Icon class="ic" :name="s.kind === 'history' ? 'history' : s.kind === 'channel' ? 'account' : s.kind === 'tag' ? 'tag' : 'search'" :size="20" />
        <span class="grow ellipsis">{{ s.kind === 'tag' ? '#' + s.text : s.text }}</span>
        <button v-if="s.kind === 'history'" class="ibtn sm" title="Удалить из истории" tabindex="-1" @mousedown.prevent.stop="removeHistory(s, $event)" aria-label="Удалить из истории"><Icon name="close" :size="16" /></button>
      </div>
    </div>
  </div>
</template>

<style>
.searchbox { position: relative; width: 100%; }
.sb-input { display: flex; align-items: center; height: 42px; border: 1px solid var(--brand-300); border-radius: var(--pill); background: var(--surface); overflow: hidden; transition: box-shadow var(--t-fast), border-color var(--t-fast); }
.sb-input:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-100); }
.sb-input input { flex: 1; min-width: 0; height: 100%; border: 0; background: transparent; padding: 0 18px; font-size: 15px; outline: none; }
.sb-input input::-webkit-search-cancel-button { appearance: none; }
.sb-btn { display: flex; align-items: center; justify-content: center; width: 64px; height: 100%; background: var(--brand-100); color: var(--brand); border-left: 1px solid var(--brand-200); }
.sb-btn:hover { background: var(--brand-200); }
:root[data-theme='dark'] .sb-btn { background: var(--brand-100); color: var(--brand-300); border-left-color: var(--line); }
.sb-menu { left: 0; right: 0; top: calc(100% + 6px); }
.sb-menu .item { cursor: pointer; }
.sb-menu .item.hover { background: var(--bg-2); }
:root[data-theme='dark'] .sb-menu .item.hover { background: var(--surface-2); }
</style>
