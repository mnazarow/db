<script setup>
defineProps({ title: { type: String, default: 'Пока ничего нет' }, text: { type: String, default: '' }, icon: { type: String, default: 'movie' } });
</script>

<template>
  <div class="empty">
    <Icon class="ic" :name="icon" :size="48" />
    <h3>{{ title }}</h3>
    <p v-if="text" class="muted" style="margin: 0 0 12px">{{ text }}</p>
    <slot />
  </div>
</template>
