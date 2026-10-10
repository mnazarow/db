<script setup>
// Универсальное выпадающее меню: слот trigger + слот содержимого, закрытие по клику вне и Esc.
// Доступность: у кнопки-триггера aria-haspopup/aria-expanded, у меню — role="menu", стрелки
// перемещают фокус по пунктам, Esc закрывает меню и возвращает фокус на триггер.
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({ align: { type: String, default: 'right' }, width: { type: String, default: '' } });
const open = ref(false);
const root = ref(null);
const menu = ref(null);
const trigger = () => root.value?.querySelector('.dd-trigger button, .dd-trigger a, .dd-trigger [tabindex]');
const items = () => [...(menu.value?.querySelectorAll('button:not([disabled]), a[href], [role="menuitem"]') || [])];
function toggle() { open.value = !open.value; }
function close({ focusTrigger = false } = {}) { open.value = false; if (focusTrigger) trigger()?.focus?.(); }
function onDoc(e) { if (root.value && !root.value.contains(e.target)) close(); }
function onKey(e) {
  if (!open.value) return;
  if (e.key === 'Escape') { close({ focusTrigger: true }); return; }
  if (!root.value?.contains(document.activeElement)) return;
  const list = items();
  if (!list.length) return;
  const i = list.indexOf(document.activeElement);
  if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
  else if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
  else if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
  else if (e.key === 'Tab') close();
}
function syncAria() {
  const t = trigger();
  if (t) { t.setAttribute('aria-haspopup', 'menu'); t.setAttribute('aria-expanded', open.value ? 'true' : 'false'); }
}
watch(open, async (v) => {
  syncAria();
  if (!v) return;
  await nextTick();
  for (const el of items()) if (!el.getAttribute('role')) el.setAttribute('role', 'menuitem');
  // Открыли с клавиатуры (фокус на триггере) — переводим фокус в меню
  if (root.value?.querySelector('.dd-trigger')?.contains(document.activeElement)) items()[0]?.focus();
});
onMounted(() => { document.addEventListener('click', onDoc); document.addEventListener('keydown', onKey); syncAria(); });
onBeforeUnmount(() => { document.removeEventListener('click', onDoc); document.removeEventListener('keydown', onKey); });
defineExpose({ close, open });
</script>

<template>
  <div class="dropdown" ref="root">
    <div class="dd-trigger" @click="toggle"><slot name="trigger" :open="open" /></div>
    <div v-if="open" ref="menu" class="menu dd-menu" role="menu" :class="align" :style="width ? { minWidth: width } : {}" @click="close()"><slot /></div>
  </div>
</template>

<style>
.dropdown { position: relative; display: inline-block; }
.dd-menu { top: calc(100% + 6px); }
.dd-menu.right { right: 0; }
.dd-menu.left { left: 0; }
</style>
