<script setup>
// Открытие видео по защищённой ссылке: проверка состояния, ввод пароля, переход к просмотру.
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post } from '../api.js';
import { useAuth } from '../stores/auth.js';
import AuthCard from '../components/AuthCard.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const info = ref(null);
const error = ref('');
const password = ref('');
const busy = ref(false);

async function load() {
  try {
    info.value = await get(`/api/share/${route.params.token}`);
    if (info.value.unlocked) router.replace({ name: 'watch', params: { id: info.value.shortId }, query: { src: 'share' } });
  } catch (e) { error.value = e.message; }
}
onMounted(load);
async function unlock() {
  busy.value = true; error.value = '';
  try { const r = await post(`/api/share/${route.params.token}/unlock`, { password: password.value }); router.replace({ name: 'watch', params: { id: r.shortId }, query: { src: 'share' } }); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
const STATES = { revoked: 'Ссылка отозвана автором видео', expired: 'Срок действия ссылки истёк', exhausted: 'Лимит открытий этой ссылки исчерпан' };
</script>

<template>
  <AuthCard title="Доступ по ссылке" wide>
      <div v-if="!info && !error" class="loading-block"><div class="spin"></div></div>
      <template v-else-if="info && info.state !== 'ok' && !info.unlocked">
        <div class="col" style="align-items:center; text-align:center; gap: 8px"><Icon name="linkLock" :size="40" class="muted" /><h2 style="font-size: 22px">Доступ закрыт</h2><p class="muted">{{ STATES[info.state] || 'Ссылка недействительна' }}.<br>Обратитесь к отправителю за новой ссылкой.</p><router-link to="/" class="btn">На главную</router-link></div>
      </template>
      <template v-else-if="info">
        <div class="row gap-12 mb-16" style="align-items:flex-start"><img v-if="info.thumbnailUrl" :src="info.thumbnailUrl" alt="" style="width: 120px; border-radius: 8px; aspect-ratio: 16/9; object-fit: cover" /><div><div style="font-weight:500; font-size: 16px" class="clamp-2">{{ info.title }}</div><div class="small muted">{{ info.ownerName }} · {{ auth.siteName }}</div><div v-if="info.expiresAt" class="tiny muted">Ссылка действует до {{ new Date(info.expiresAt).toLocaleString('ru-RU') }}</div></div></div>
        <p class="small muted">Видео открыто для вас по защищённой ссылке. Введите пароль, который вам передали вместе со ссылкой.</p>
        <form @submit.prevent="unlock" class="col gap-12">
          <div class="field"><label>Пароль</label><input class="input" v-model="password" type="password" autofocus autocomplete="off" /></div>
          <div v-if="error" class="alert danger"><Icon name="alertCircle" :size="18" /> {{ error }}</div>
          <button class="btn primary" :disabled="busy || !password">{{ busy ? 'Проверка…' : 'Открыть видео' }}</button>
        </form>
      </template>
      <div v-else class="col" style="align-items:center; text-align:center; gap: 8px"><Icon name="alertCircle" :size="40" class="muted" /><h2 style="font-size: 22px">Ссылка не найдена</h2><p class="muted">{{ error }}</p><router-link to="/" class="btn">На главную</router-link></div>
  </AuthCard>
</template>
