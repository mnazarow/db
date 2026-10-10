<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import AuthCard from '../../components/AuthCard.vue';
const route = useRoute();
const router = useRouter();
const auth = useAuth();
const info = ref(null);
const error = ref('');
const form = ref({ displayName: '', password: '', password2: '' });
const busy = ref(false);
onMounted(async () => { try { info.value = await get(`/api/auth/invite/${route.params.token}`); } catch (e) { error.value = e.message; } });
async function submit() {
  if (form.value.password !== form.value.password2) { error.value = 'Пароли не совпадают'; return; }
  busy.value = true; error.value = '';
  try { await post(`/api/auth/invite/${route.params.token}`, { displayName: form.value.displayName, password: form.value.password }); await auth.load(); router.push('/'); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <AuthCard title="Приглашение" :subtitle="info ? `Создайте учётную запись для ${info.email}` : ''">
    <div v-if="!info && !error" class="loading-block"><div class="spin"></div></div>
    <div v-else-if="!info" class="alert danger">{{ error }}</div>
    <form v-else class="col gap-16" @submit.prevent="submit">
      <div class="field"><label>Имя и фамилия</label><input class="input" v-model="form.displayName" required minlength="2" autofocus /></div>
      <div class="field"><label>Пароль</label><input class="input" type="password" v-model="form.password" required minlength="8" autocomplete="new-password" /></div>
      <div class="field"><label>Повторите пароль</label><input class="input" type="password" v-model="form.password2" required autocomplete="new-password" /></div>
      <div v-if="error" class="alert danger">{{ error }}</div>
      <button class="btn primary lg block" type="submit" :disabled="busy">Создать учётную запись</button>
    </form>
  </AuthCard>
</template>
