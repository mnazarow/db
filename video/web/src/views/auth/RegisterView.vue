<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import AuthCard from '../../components/AuthCard.vue';

const auth = useAuth();
const router = useRouter();
const form = ref({ email: '', password: '', password2: '', displayName: '' });
const error = ref('');
const busy = ref(false);
const done = ref(null);
const strength = computed(() => {
  const p = form.value.password; let s = 0;
  if (p.length >= 8) s++; if (p.length >= 12) s++; if (/[A-ZА-Я]/.test(p) && /[a-zа-я]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^\w\s]/.test(p)) s++;
  return s;
});
async function submit() {
  error.value = '';
  if (form.value.password !== form.value.password2) { error.value = 'Пароли не совпадают'; return; }
  busy.value = true;
  try {
    const r = await post('/api/auth/register', { email: form.value.email.trim(), password: form.value.password, displayName: form.value.displayName.trim() });
    await auth.load();
    done.value = r;
    if (r.status === 'active') router.push('/');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
  <AuthCard v-if="!done" title="Регистрация" subtitle="Доступ предоставляется сотрудникам компании после проверки администратором">
    <form class="col gap-16" @submit.prevent="submit">
      <div class="field"><label>Имя и фамилия</label><input class="input" v-model="form.displayName" required minlength="2" maxlength="80" autocomplete="name" /></div>
      <div class="field"><label>Корпоративная электронная почта</label><input class="input" type="email" v-model="form.email" required autocomplete="email" placeholder="имя@vodokomfort.ru" /><div class="hint">Регистрация возможна только с разрешённых доменов компании.</div></div>
      <div class="field"><label>Пароль</label><input class="input" type="password" v-model="form.password" required minlength="8" autocomplete="new-password" />
        <div class="strength"><i v-for="i in 5" :key="i" :class="{ on: strength >= i, weak: strength <= 2, ok: strength === 3, good: strength >= 4 }"></i></div>
        <div class="hint">Не короче 8 символов, буквы и цифры.</div>
      </div>
      <div class="field"><label>Повторите пароль</label><input class="input" type="password" v-model="form.password2" required autocomplete="new-password" /></div>
      <div v-if="error" class="alert danger"><Icon name="alertCircle" :size="18" /> {{ error }}</div>
      <button class="btn primary lg block" :disabled="busy" type="submit">{{ busy ? 'Отправка…' : 'Зарегистрироваться' }}</button>
      <div class="small" style="text-align:center">Уже есть учётная запись? <router-link to="/login">Войти</router-link></div>
    </form>
  </AuthCard>
  <AuthCard v-else :title="done.status === 'pending_email' ? 'Подтвердите почту' : 'Заявка отправлена'">
    <div class="col gap-16">
      <div class="alert success"><Icon name="check" :size="20" /><span v-if="done.status === 'pending_email'">Мы отправили письмо на <b>{{ form.email }}</b>. Перейдите по ссылке из письма, чтобы подтвердить адрес — после этого заявка попадёт к администратору.</span><span v-else>Ваша заявка ожидает одобрения администратором. Вы получите уведомление на почту, когда доступ будет открыт.</span></div>
      <router-link to="/account-status" class="btn primary block">Статус учётной записи</router-link>
      <router-link to="/" class="btn block">На главную</router-link>
    </div>
  </AuthCard>
</template>

<style>
.strength { display: flex; gap: 4px; margin-top: 6px; }
.strength i { flex: 1; height: 4px; border-radius: 2px; background: var(--line-2); }
.strength i.on.weak { background: var(--danger); }
.strength i.on.ok { background: var(--warning); }
.strength i.on.good { background: var(--success); }
</style>
