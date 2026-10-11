<script setup>
// Текст описания: ссылки, #теги, таймкоды (клик → перемотка), @упоминания.
import { computed } from 'vue';
import { BASE, routerPath } from '../base.js';
const props = defineProps({ text: { type: String, default: '' }, seekable: { type: Boolean, default: true } });
const emit = defineEmits(['seek']);
const html = computed(() => {
  const esc = (s) => s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  let t = esc(props.text || '');
  t = t.replace(/(https?:\/\/[^\s<]+)/g, (m) => `<a href="${m}" target="_blank" rel="noopener">${m}</a>`);
  if (props.seekable) t = t.replace(/(^|[\s(])((?:\d{1,2}:)?\d{1,2}:\d{2})(?=[\s)\-–—:.,]|$)/gm, (m, pre, ts) => `${pre}<a href="#" data-ts="${ts}" class="ts">${ts}</a>`);
  t = t.replace(/(^|\s)#([\p{L}\p{N}_]{2,40})/gu, (m, pre, tag) => `${pre}<a href="${BASE}/tag/${encodeURIComponent(tag)}" class="tag-link">#${tag}</a>`);
  t = t.replace(/(^|\s)@([\w.-]{2,30})/g, (m, pre, h) => `${pre}<a href="${BASE}/@${h}">@${h}</a>`);
  return t.replace(/\n/g, '<br>');
});
function onClick(e) {
  const a = e.target.closest('a');
  if (!a) return;
  if (a.dataset.ts) { e.preventDefault(); const s = a.dataset.ts.split(':').map(Number).reduce((x, y) => x * 60 + y, 0); emit('seek', s); window.dispatchEvent(new CustomEvent('cv:seek', { detail: s })); return; }
  // В href — адрес с префиксом портала (открытие в новой вкладке), маршрутизатору нужен путь без него
  if (a.getAttribute('href')?.startsWith('/')) { e.preventDefault(); window.dispatchEvent(new CustomEvent('cv:navigate', { detail: routerPath(a.getAttribute('href')) })); }
}
</script>

<template>
  <div class="rich" v-html="html" @click="onClick"></div>
</template>

<style>
.rich { white-space: pre-wrap; word-break: break-word; line-height: 1.5; }
.rich a.ts { font-weight: 500; }
</style>
