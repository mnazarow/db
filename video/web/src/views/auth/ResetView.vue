<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import AuthCard from '../../components/AuthCard.vue';
const route = useRoute();
const router = useRouter();
const ui = useUi();
const p1 = ref(''); const p2 = ref('');
const error = ref(''); const busy = ref(false);
async function submit() {
  if (p1.value !== p2.value) { error.value = 'Пароли не совпадают'; return; }
  busy.value = true; error.value = '';
  try { await post('/api/auth/reset', { token: route.query.token, password: p1.value }); ui.toast('Пароль изменён. Войдите с новым паролем.', { type: 'success' }); router.push('/login'); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <AuthCard title="Новый пароль">
    <form class="col gap-16" @submit.prevent="submit">
      <div class="field"><label>Новый пароль</label><input class="input" type="password" v-model="p1" required minlength="8" autocomplete="new-password" autofocus /></div>
      <div class="field"><label>Повторите пароль</label><input class="input" type="password" v-model="p2" required autocomplete="new-password" /></div>
      <div v-if="error" class="alert danger">{{ error }}</div>
      <button class="btn primary lg block" type="submit" :disabled="busy">Сохранить пароль</button>
    </form>
  </AuthCard>
</template>
