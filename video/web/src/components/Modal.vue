<script setup>
import { onMounted, onBeforeUnmount, ref, useId } from 'vue';
const props = defineProps({ title: { type: String, default: '' }, wide: Boolean, narrow: Boolean, noClose: Boolean });
const emit = defineEmits(['close']);
const box = ref(null);
const titleId = `modal-title-${useId ? useId() : Math.random().toString(36).slice(2)}`;
let returnTo = null;

/** Что внутри диалога можно сфокусировать. */
function focusables() {
  if (!box.value) return [];
  return [...box.value.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    .filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function onKey(e) {
  if (e.key === 'Escape' && !props.noClose) { emit('close'); return; }
  if (e.key !== 'Tab' || !box.value) return;
  // Ловушка фокуса: без неё Tab уходил на страницу под диалогом
  const list = focusables();
  if (!list.length) { e.preventDefault(); box.value.focus(); return; }
  const first = list[0], last = list[list.length - 1];
  const inside = box.value.contains(document.activeElement);
  if (!inside) { e.preventDefault(); (e.shiftKey ? last : first).focus(); return; }
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}

onMounted(() => {
  returnTo = document.activeElement;
  document.addEventListener('keydown', onKey);
  document.body.classList.add('no-scroll');
  // Фокус внутрь диалога: первое поле, иначе сам диалог
  requestAnimationFrame(() => {
    const list = focusables();
    const field = list.find((el) => /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
    (field || list[0] || box.value)?.focus?.();
  });
});
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey);
  document.body.classList.remove('no-scroll');
  // Возвращаем фокус туда, откуда диалог открыли
  try { returnTo?.focus?.(); } catch { /* элемента уже нет */ }
});
</script>

<template>
  <Teleport to="body">
    <div class="modal-backdrop" @mousedown.self="!noClose && $emit('close')">
      <div ref="box" class="modal" :class="{ wide, narrow }" role="dialog" aria-modal="true" :aria-labelledby="title ? titleId : undefined" tabindex="-1">
        <div class="modal-head" v-if="title || $slots.head">
          <slot name="head"><h3 :id="titleId">{{ title }}</h3></slot>
          <button v-if="!noClose" class="ibtn" @click="$emit('close')" aria-label="Закрыть"><Icon name="close" /></button>
        </div>
        <div class="modal-body"><slot /></div>
        <div class="modal-foot" v-if="$slots.footer"><slot name="footer" /></div>
      </div>
    </div>
  </Teleport>
</template>
