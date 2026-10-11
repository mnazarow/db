<script setup>
import EmptyState from '../components/EmptyState.vue';
</script>
<template>
  <div class="page narrow"><EmptyState icon="help" title="Страница не найдена" text="Возможно, ссылка устарела или была введена с ошибкой."><router-link to="/" class="btn primary">На главную</router-link></EmptyState></div>
</template>
