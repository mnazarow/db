<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, patch, post, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
import VideoCard from '../../components/VideoCard.vue';
import { fmtDateTime, timeAgo } from '../../utils/format.js';
import { copyWithToast } from '../../utils/clipboard.js';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const auth = useAuth();
const data = ref(null);
const form = ref({});
const resetLink = ref('');
async function copy(t) { await copyWithToast(ui, t, 'Ссылка скопирована'); }
async function load() { data.value = await get(`/api/admin/users/${route.params.id}`); const u = data.value.user; form.value = { displayName: u.displayName, email: u.email, handle: u.handle, role: u.role, canUpload: u.canUpload, canStream: u.canStream, password: '' }; }
onMounted(load);
async function save() { try { const r = await patch(`/api/admin/users/${route.params.id}`, form.value); data.value.user = r.user; form.value.password = ''; ui.toast('Сохранено', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function act(action, body = {}) { try { await post(`/api/admin/users/${route.params.id}/${action}`, body); await load(); ui.toast('Готово', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function reset() { const r = await post(`/api/admin/users/${route.params.id}/password-reset-link`, {}); resetLink.value = r.url; ui.toast(r.sent ? 'Ссылка отправлена на почту пользователя' : 'Почта не настроена — передайте ссылку вручную'); }
async function totpReset() { await patch(`/api/admin/users/${route.params.id}`, { totpReset: true }); await load(); ui.toast('2FA сброшена'); }
async function remove(withVideos) {
  if (!(await ui.ask({ title: 'Удалить пользователя?', message: withVideos ? 'Учётная запись и все видео пользователя будут удалены.' : 'Учётная запись будет удалена, видео останутся на портале.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/admin/users/${route.params.id}${withVideos ? '?videos=delete' : ''}`); router.push('/admin/users');
}
const STATUS = { active: ['Активен', 'success'], pending_approval: ['Ждёт одобрения', 'warning'], pending_email: ['Не подтвердил почту', ''], blocked: ['Заблокирован', 'danger'], rejected: ['Отклонён', 'danger'] };
</script>

<template>
  <div v-if="data">
    <div class="row mb-16"><router-link to="/admin/users" class="btn ghost sm"><Icon name="back" :size="18" /> Пользователи</router-link></div>
    <div class="row gap-16 mb-24" style="align-items:flex-start">
      <ChannelAvatar :user="data.user" size="xl" />
      <div class="grow"><h1 style="font-family: var(--font-body); text-transform: none; letter-spacing: 0; font-size: 26px">{{ data.user.displayName }}</h1><div class="muted">{{ data.user.email }} • <router-link :to="`/@${data.user.handle}`">@{{ data.user.handle }}</router-link></div>
        <div class="row wrap gap-8 mt-8"><span class="badge" :class="STATUS[data.user.status]?.[1]">{{ STATUS[data.user.status]?.[0] }}</span><span class="badge brand">{{ { user: 'Пользователь', moderator: 'Модератор', admin: 'Администратор' }[data.user.role] }}</span><span class="badge">{{ data.user.authProvider === 'ldap' ? 'AD/LDAP' : 'Локальная учётная запись' }}</span><span v-if="data.user.totpEnabled" class="badge success">2FA</span></div>
        <div class="small muted mt-8">Зарегистрирован {{ fmtDateTime(data.user.createdAt) }}<span v-if="data.user.approvedAt"> • одобрен {{ fmtDateTime(data.user.approvedAt) }}</span><span v-if="data.user.lastSeenAt"> • был {{ timeAgo(data.user.lastSeenAt) }}</span></div>
        <div v-if="data.user.rejectionNote" class="small mt-8">Примечание: {{ data.user.rejectionNote }}</div>
      </div>
      <div class="row wrap gap-8">
        <button v-if="['pending_approval', 'pending_email'].includes(data.user.status)" class="btn primary" @click="act('approve')">Одобрить</button>
        <button v-if="data.user.status !== 'active' && data.user.status !== 'blocked'" class="btn" @click="act('reject')">Отклонить</button>
        <button v-if="data.user.status === 'active' && data.user.id !== auth.user.id" class="btn danger" @click="act('block')">Заблокировать</button>
        <button v-if="['blocked', 'rejected'].includes(data.user.status)" class="btn" @click="act('unblock')">Активировать</button>
      </div>
    </div>
    <div class="two-col">
      <div class="col gap-24">
        <div class="panel" v-if="auth.isAdmin">
          <h3 class="mb-16">Учётная запись</h3>
          <div class="form-grid">
            <div class="field"><label>Имя</label><input class="input" v-model="form.displayName" /></div>
            <div class="field"><label>E-mail</label><input class="input" v-model="form.email" /></div>
            <div class="field"><label>Канал</label><input class="input" v-model="form.handle" /></div>
            <div class="field"><label>Роль</label><select class="select" v-model="form.role" :disabled="data.user.id === auth.user.id"><option value="user">Пользователь</option><option value="moderator">Модератор</option><option value="admin">Администратор</option></select></div>
            <div class="field"><label>Новый пароль (необязательно)</label><input class="input" v-model="form.password" placeholder="оставьте пустым, чтобы не менять" /></div>
            <div class="field" style="justify-content:flex-end; gap: 10px"><label class="switch"><input type="checkbox" v-model="form.canUpload" /><span class="track"></span><span>Может загружать видео</span></label><label class="switch"><input type="checkbox" v-model="form.canStream" /><span class="track"></span><span>Может вести трансляции</span></label></div>
          </div>
          <div class="form-actions"><button class="btn primary" @click="save">Сохранить</button><button class="btn" @click="reset"><Icon name="key" :size="16" /> Ссылка для сброса пароля</button><button v-if="data.user.totpEnabled" class="btn" @click="totpReset">Сбросить 2FA</button></div>
          <div v-if="resetLink" class="code-box mt-8"><span>{{ resetLink }}</span><button class="ibtn sm" @click="copy(resetLink)" aria-label="Скопировать ссылку сброса пароля"><Icon name="copy" :size="16" /></button></div>
          <div class="divider"></div>
          <div class="row wrap"><button class="btn ghost danger sm" @click="remove(false)">Удалить учётную запись</button><button class="btn ghost danger sm" @click="remove(true)">Удалить вместе с видео</button></div>
        </div>
        <div class="panel"><h3 class="mb-16">Видео пользователя</h3><div v-if="data.videos.length" class="video-grid compact"><VideoCard v-for="v in data.videos" :key="v.id" :video="v" :show-channel="false" show-status /></div><p v-else class="muted small">Видео нет</p></div>
      </div>
      <div class="col gap-24">
        <div class="panel"><h3 class="mb-16">Сессии</h3><div class="col gap-8 small"><div v-for="s in data.sessions" :key="s.id"><div>{{ s.userAgent }}</div><div class="tiny muted">{{ s.ip }} • {{ fmtDateTime(s.lastSeenAt) }}</div></div><span v-if="!data.sessions.length" class="muted">Нет активных сессий</span></div></div>
        <div class="panel"><h3 class="mb-16">Журнал</h3><div class="col gap-8 small"><div v-for="a in data.audit" :key="a.id"><span class="mono">{{ a.action }}</span> <span class="tiny muted">{{ fmtDateTime(a.created_at) }} {{ a.ip }}</span></div><span v-if="!data.audit.length" class="muted">Записей нет</span></div></div>
      </div>
    </div>
  </div>
  <div v-else class="loading-block"><div class="spin"></div></div>
</template>
