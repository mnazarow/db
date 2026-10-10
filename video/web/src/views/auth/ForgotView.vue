<script setup>
import { ref } from 'vue';
import { post } from '../../api.js';
import AuthCard from '../../components/AuthCard.vue';
const email = ref('');
const done = ref(false);
const emailEnabled = ref(true);
const busy = ref(false);
const error = ref('');
async function submit() {
  busy.value = true; error.value = '';
  try { const r = await post('/api/auth/forgot', { email: email.value.trim() }); emailEnabled.value = r.emailEnabled; done.value = true; } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <AuthCard title="Восстановление пароля" subtitle="Укажите адрес, на который зарегистрирована учётная запись">
    <form v-if="!done" class="col gap-16" @submit.prevent="submit">
      <div class="field"><label>Электронная почта</label><input class="input" type="email" v-model="email" required autofocus /></div>
      <div v-if="error" class="alert danger">{{ error }}</div>
      <button class="btn primary lg block" type="submit" :disabled="busy">Отправить ссылку</button>
      <div class="small" style="text-align:center"><router-link to="/login">Вернуться ко входу</router-link></div>
    </form>
    <div v-else class="col gap-16">
      <div v-if="emailEnabled" class="alert success"><Icon name="check" :size="20" /> Если адрес зарегистрирован, на него отправлено письмо со ссылкой для смены пароля. Ссылка действует 2 часа.</div>
      <div v-else class="alert warning"><Icon name="alert" :size="20" /> Отправка почты на портале не настроена. Обратитесь к администратору — он сможет сбросить пароль вручную.</div>
      <router-link to="/login" class="btn block">Ко входу</router-link>
    </div>
  </AuthCard>
</template>
