<script setup>
import { useUi } from '../stores/ui.js';
const ui = useUi();
</script>

<template>
  <div class="toasts" role="status" aria-live="polite" aria-atomic="false">
    <div v-for="t in ui.toasts" :key="t.id" class="toast" :class="t.type">
      <span>{{ t.message }}</span>
      <router-link v-if="t.action?.to" :to="t.action.to" class="act" @click="ui.dismissToast(t.id)">{{ t.action.label }}</router-link>
      <button v-else-if="t.action?.onClick" class="act" @click="t.action.onClick(); ui.dismissToast(t.id)">{{ t.action.label }}</button>
      <button class="act" @click="ui.dismissToast(t.id)" aria-label="Закрыть"><Icon name="close" :size="16" /></button>
    </div>
  </div>
</template>
