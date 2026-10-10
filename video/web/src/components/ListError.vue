<script setup>
// Ошибка загрузки списка: вместо «Пока ничего нет» — что случилось и кнопка «Повторить».
import { computed } from 'vue';
const props = defineProps({ error: { type: [Object, String], default: null }, compact: Boolean });
defineEmits(['retry']);
const message = computed(() => {
  const e = props.error;
  if (!e) return '';
  if (typeof e === 'string') return e;
  // fetch бросает TypeError, когда до сервера не достучаться вовсе
  if (!e.status && (e.name === 'TypeError' || /fetch|network/i.test(e.message || ''))) return 'Нет связи с сервером. Проверьте подключение к сети.';
  if (e.status === 429) return 'Слишком много запросов — подождите немного.';
  if (e.status >= 500) return `Сервер временно не отвечает (код ${e.status}).`;
  return e.message || 'Неизвестная ошибка';
});
</script>

<template>
  <div v-if="error" class="list-error" :class="{ compact }" role="alert">
    <Icon name="alertCircle" :size="compact ? 20 : 40" class="ic" />
    <div class="grow">
      <h3 v-if="!compact">Не удалось загрузить</h3>
      <p class="muted">{{ message }}</p>
    </div>
    <button class="btn" type="button" @click="$emit('retry')"><Icon name="refresh" :size="18" /> Повторить</button>
  </div>
</template>

<style>
.list-error { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 40px 16px; }
.list-error .ic { color: var(--danger); }
.list-error h3 { margin: 0; }
.list-error p { margin: 0 0 8px; }
.list-error.compact { flex-direction: row; text-align: left; padding: 12px 16px; border-radius: var(--radius-sm); background: var(--danger-bg); }
.list-error.compact p { margin: 0; color: var(--text); }
</style>
