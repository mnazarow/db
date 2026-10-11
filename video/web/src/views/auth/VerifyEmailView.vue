<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import AuthCard from '../../components/AuthCard.vue';

const route = useRoute();
const auth = useAuth();
const state = ref('loading');
const message = ref('');
const status = ref('');
onMounted(async () => {
  try {
    const r = await post('/api/auth/verify-email', { token: route.query.token });
    status.value = r.status; state.value = 'ok';
    await auth.load();
  } catch (e) { state.value = 'error'; message.value = e.message; }
});
</script>

<template>
  <AuthCard title="Подтверждение адреса">
    <div v-if="state === 'loading'" class="loading-block"><div class="spin"></div></div>
    <div v-else-if="state === 'ok'" class="col gap-16">
      <div class="alert success"><Icon name="check" :size="20" /><span v-if="status === 'active'">Адрес подтверждён, учётная запись активирована. Добро пожаловать!</span><span v-else>Адрес подтверждён. Заявка отправлена администратору — после одобрения вы получите письмо.</span></div>
      <router-link :to="status === 'active' ? '/' : '/account-status'" class="btn primary block">Продолжить</router-link>
    </div>
    <div v-else class="col gap-16">
      <div class="alert danger"><Icon name="alertCircle" :size="20" /> {{ message }}</div>
      <router-link to="/account-status" class="btn block">Запросить новое письмо</router-link>
    </div>
  </AuthCard>
</template>
