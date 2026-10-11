<script setup>
import { useUi } from '../stores/ui.js';
import Modal from './Modal.vue';
const ui = useUi();
</script>

<template>
  <Modal v-if="ui.confirm" :title="ui.confirm.title" narrow @close="ui.answer(false)">
    <p class="pre" style="margin: 0">{{ ui.confirm.message }}</p>
    <template #footer>
      <button class="btn" @click="ui.answer(false)">{{ ui.confirm.cancelLabel }}</button>
      <button class="btn primary" :class="{ danger: ui.confirm.danger }" @click="ui.answer(true)">{{ ui.confirm.okLabel }}</button>
    </template>
  </Modal>
</template>
