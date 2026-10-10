<script setup>
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuth } from '../../stores/auth.js';
import AuthCard from '../../components/AuthCard.vue';
import { url } from '../../base.js';

const auth = useAuth();
const router = useRouter();
const route = useRoute();
const login = ref('');
const password = ref('');
const code = ref('');
const error = ref(String(route.query.error || ''));
const busy = ref(false);
const totp = ref(auth.totpRequired || route.query.totp === '1');
const showPass = ref(false);
// next — путь маршрутизатора (без префикса портала): сервер вернёт на него с префиксом сам
const ssoUrl = () => url(`/api/auth/oidc/start?next=${encodeURIComponent(String(route.query.next || '/'))}`);
// «Только SSO»: форма пароля скрыта, но доступна по /login?local=1 (запасной вход администратора)
const passwordForm = () => !(auth.config?.oidcEnabled && auth.config?.oidcOnly && route.query.local !== '1');

function go() {
  const next = String(route.query.next || '');
  if (auth.user && auth.user.status !== 'active') router.push('/account-status');
  else router.push(next && next.startsWith('/') ? next : '/');
}
async function submit() {
  error.value = ''; busy.value = true;
  try {
    const r = await auth.login(login.value.trim(), password.value);
    if (r.totpRequired) { totp.value = true; return; }
    go();
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function submitTotp() {
  error.value = ''; busy.value = true;
  try { await auth.totp(code.value.trim()); go(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <AuthCard title="Вход" :subtitle="totp ? 'Введите код из приложения-аутентификатора' : (auth.config?.ldapEnabled ? 'Используйте корпоративную почту или доменную учётную запись' : 'Используйте корпоративную электронную почту')">
    <div v-if="!totp && auth.config?.oidcEnabled" class="col gap-12 mb-16">
      <a class="btn primary lg block" :href="ssoUrl()"><Icon name="shield" :size="20" /> {{ auth.config.oidcButtonText || 'Войти через корпоративный SSO' }}</a>
      <div v-if="error && !passwordForm()" class="alert danger"><Icon name="alertCircle" :size="18" /> {{ error }}</div>
      <div v-if="passwordForm()" class="row" style="align-items:center; gap: 12px; color: var(--text-3); font-size: 13px"><span class="grow" style="height:1px; background: var(--line)"></span>или по паролю<span class="grow" style="height:1px; background: var(--line)"></span></div>
    </div>
    <form v-if="!totp && passwordForm()" class="col gap-16" @submit.prevent="submit">
      <div class="field"><label>{{ auth.config?.ldapEnabled ? 'E-mail или доменный логин' : 'Электронная почта' }}</label><input class="input" v-model="login" autocomplete="username" required autofocus /></div>
      <div class="field"><label>Пароль</label>
        <div class="input-group"><input class="input" :type="showPass ? 'text' : 'password'" v-model="password" autocomplete="current-password" required /><button type="button" class="btn ghost" style="border: 1px solid var(--line); border-left: 0" @click="showPass = !showPass" :aria-label="showPass ? 'Скрыть пароль' : 'Показать пароль'"><Icon :name="showPass ? 'eyeOff' : 'eye'" :size="18" /></button></div>
      </div>
      <div v-if="error" class="alert danger"><Icon name="alertCircle" :size="18" /> {{ error }}</div>
      <button class="btn primary lg block" :disabled="busy" type="submit">{{ busy ? 'Входим…' : 'Войти' }}</button>
      <div class="row" style="justify-content: space-between; font-size: 14px">
        <router-link to="/forgot-password">Забыли пароль?</router-link>
        <router-link v-if="auth.config?.registrationEnabled" to="/register">Регистрация</router-link>
      </div>
    </form>
    <form v-else class="col gap-16" @submit.prevent="submitTotp">
      <div class="field"><label>Код подтверждения</label><input class="input" v-model="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autofocus required style="font-size: 22px; letter-spacing: .3em; text-align: center" /></div>
      <div v-if="error" class="alert danger"><Icon name="alertCircle" :size="18" /> {{ error }}</div>
      <button class="btn primary lg block" :disabled="busy" type="submit">Подтвердить</button>
      <button type="button" class="btn ghost sm" @click="auth.logout(); totp = false">Отмена</button>
    </form>
  </AuthCard>
</template>
