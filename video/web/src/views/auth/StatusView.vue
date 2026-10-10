<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import AuthCard from '../../components/AuthCard.vue';
const auth = useAuth();
const ui = useUi();
const router = useRouter();
const sent = ref(false);
async function resend() { await post('/api/auth/resend-verification', {}); sent.value = true; ui.toast('Письмо отправлено'); }
async function refresh() { await auth.load(); if (auth.isActive) router.push('/'); else ui.toast('Статус пока не изменился'); }
async function logout() { await auth.logout(); router.push('/login'); }
const TEXT = {
  pending_email: { title: 'Подтвердите почту', text: 'Мы отправили письмо со ссылкой на ваш адрес. Если письма нет — проверьте папку «Спам» или запросите новое.' },
  pending_approval: { title: 'Ожидание одобрения', text: 'Ваша заявка получена. Администратор проверит её и откроет доступ — уведомление придёт на почту.' },
  blocked: { title: 'Учётная запись заблокирована', text: 'Доступ к порталу ограничен администратором. Обратитесь в службу поддержки.' },
  rejected: { title: 'Заявка отклонена', text: 'Администратор отклонил вашу заявку на доступ.' },
  active: { title: 'Учётная запись активна', text: 'Вам доступны все возможности портала.' },
};
</script>

<template>
  <AuthCard :title="TEXT[auth.user?.status]?.title || 'Статус'" :subtitle="auth.user?.email">
    <div class="col gap-16">
      <div class="alert" :class="auth.user?.status === 'active' ? 'success' : auth.user?.status === 'blocked' || auth.user?.status === 'rejected' ? 'danger' : 'info'"><Icon :name="auth.user?.status === 'active' ? 'check' : 'info'" :size="20" /> {{ TEXT[auth.user?.status]?.text }}</div>
      <p v-if="auth.user?.status === 'rejected' && auth.user?.rejectionNote" class="small muted">Причина: {{ auth.user.rejectionNote }}</p>
      <button v-if="auth.user?.status === 'pending_email'" class="btn block" @click="resend" :disabled="sent">{{ sent ? 'Письмо отправлено' : 'Отправить письмо повторно' }}</button>
      <button v-if="auth.user?.status === 'active'" class="btn primary block" @click="router.push('/')">Перейти на портал</button>
      <button v-else class="btn primary block" @click="refresh"><Icon name="refresh" :size="18" /> Проверить статус</button>
      <p v-if="auth.config?.supportEmail" class="small muted" style="text-align:center">Поддержка: <a :href="`mailto:${auth.config.supportEmail}`">{{ auth.config.supportEmail }}</a></p>
      <button class="btn ghost sm" @click="logout">Выйти</button>
    </div>
  </AuthCard>
</template>
