<script setup>
import { ref, nextTick } from 'vue';
import { useUi } from '../../stores/ui.js';

const props = defineProps({ placeholder: { type: String, default: 'Ваш ответ…' }, initial: { type: String, default: '' }, autofocus: Boolean, submitLabel: { type: String, default: 'Отправить' }, cancelable: Boolean });
const emit = defineEmits(['submit', 'cancel']);
const ui = useUi();
const text = ref(props.initial);
const focused = ref(!!props.initial || props.autofocus);
const busy = ref(false);
const ta = ref(null);

async function submit() {
  const body = text.value.trim();
  if (!body) return;
  busy.value = true;
  try { await emit('submit', body); text.value = ''; focused.value = false; }
  catch (e) { ui.toast(e.message || 'Ошибка', { type: 'error' }); }
  finally { busy.value = false; }
}
function cancel() { text.value = ''; focused.value = false; emit('cancel'); }
function autosize() { const el = ta.value; if (!el) return; el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
if (props.autofocus) nextTick(() => ta.value?.focus());
</script>

<template>
  <form class="comment-form" @submit.prevent="submit">
    <textarea ref="ta" class="cf-input" :class="{ focused }" v-model="text" :placeholder="placeholder" rows="1" maxlength="5000" @focus="focused = true" @input="autosize" @keydown.ctrl.enter="submit" @keydown.meta.enter="submit"></textarea>
    <div v-if="focused || text" class="row" style="justify-content: flex-end; margin-top: 8px">
      <span class="tiny faint" style="margin-right: auto">Ctrl+Enter — отправить</span>
      <button type="button" class="btn ghost sm" @click="cancel">Отмена</button>
      <button type="submit" class="btn primary sm" :disabled="!text.trim() || busy">{{ submitLabel }}</button>
    </div>
  </form>
</template>

<style>
.cf-input { width: 100%; border: 0; border-bottom: 1px solid var(--line); background: transparent; padding: 8px 0; resize: none; min-height: 36px; line-height: 1.4; transition: border-color var(--t-fast); }
.cf-input:focus, .cf-input.focused { outline: none; border-bottom: 2px solid var(--brand); }
</style>
