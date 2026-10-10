<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { usePaged } from '../../utils/paged.js';
import Pagination from '../../components/Pagination.vue';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
import Modal from '../../components/Modal.vue';
import { fmtDateShort, fmtBytes, timeAgo } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const auth = useAuth();
const emit = defineEmits(['refresh']);
const q = ref(String(route.query.q || ''));
const status = ref(String(route.query.status || ''));
const role = ref(String(route.query.role || ''));
const list = usePaged('/api/admin/users', { key: 'users', limit: 30, extraQuery: () => ({ q: q.value, status: status.value, role: role.value }) });
const selected = ref(new Set());
const rejectDlg = ref(null); const rejectNote = ref('');
const createDlg = ref(false); const createForm = ref({ email: '', displayName: '', password: '', role: 'user' });
let t = null;
watch([q, status, role], () => { clearTimeout(t); t = setTimeout(() => router.replace({ query: { q: q.value || undefined, status: status.value || undefined, role: role.value || undefined } }), 250); });
watch(() => route.query.status, (v) => { status.value = String(v || ''); });

const STATUS = { active: ['Активен', 'success'], pending_approval: ['Ждёт одобрения', 'warning'], pending_email: ['Не подтвердил почту', ''], blocked: ['Заблокирован', 'danger'], rejected: ['Отклонён', 'danger'] };
const ROLE = { user: 'Пользователь', moderator: 'Модератор', admin: 'Администратор' };
async function act(u, action, body = {}) {
  try { await post(`/api/admin/users/${u.id}/${action}`, body); ui.toast({ approve: 'Пользователь одобрен', reject: 'Заявка отклонена', block: 'Пользователь заблокирован', unblock: 'Пользователь разблокирован' }[action] || 'Готово', { type: 'success' }); list.load(); emit('refresh'); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function bulk(action) { await post('/api/admin/users/bulk', { ids: [...selected.value], action }); selected.value = new Set(); list.load(); emit('refresh'); ui.toast('Выполнено', { type: 'success' }); }
function toggle(id) { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; }
async function create() { try { await post('/api/admin/users/create', createForm.value); createDlg.value = false; createForm.value = { email: '', displayName: '', password: '', role: 'user' }; list.load(); ui.toast('Пользователь создан', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Пользователи</h1><div class="sub" v-if="list.extra.value?.counts">{{ list.extra.value.counts.active }} активных • {{ list.extra.value.counts.pending }} ждут одобрения • {{ list.extra.value.counts.blocked }} заблокированных</div></div><div class="actions" v-if="!auth.isPortal"><button v-if="auth.isAdmin" class="btn primary" @click="createDlg = true"><Icon name="personAdd" :size="18" /> Создать</button><router-link to="/admin/domains" class="btn"><Icon name="mail" :size="18" /> Пригласить</router-link></div></div>
    <div v-if="auth.isPortal" class="panel mb-16"><p>Сотрудники входят через портал документации и появляются здесь при первом входе в раздел «Видео». Роли назначаются в портале (Панель администратора → Пользователи): <b>маркетолог</b> — модератор видео, <b>помощник маркетолога</b> — загружает видео, они публикуются после модерации, <b>пользователь</b> — смотрит одобренные видео. Здесь можно сменить имя канала или заблокировать сотрудника в видео.</p></div>
    <div class="tabs mb-16">
      <button class="tab" :class="{ active: status === '' }" @click="status = ''">Все</button>
      <button class="tab" :class="{ active: status === 'pending_approval' }" @click="status = 'pending_approval'">Заявки<span v-if="list.extra.value?.counts?.pending" class="count">{{ list.extra.value.counts.pending }}</span></button>
      <button class="tab" :class="{ active: status === 'active' }" @click="status = 'active'">Активные</button>
      <button class="tab" :class="{ active: status === 'pending_email' }" @click="status = 'pending_email'">Без подтверждения</button>
      <button class="tab" :class="{ active: status === 'blocked' }" @click="status = 'blocked'">Заблокированные</button>
      <button class="tab" :class="{ active: status === 'rejected' }" @click="status = 'rejected'">Отклонённые</button>
    </div>
    <div class="row wrap mb-16"><input class="input" v-model="q" placeholder="Поиск по имени, почте, каналу" style="max-width: 300px" /><select class="select" v-model="role" style="max-width: 200px"><option value="">Любая роль</option><option v-for="(l, k) in ROLE" :key="k" :value="k">{{ l }}</option></select></div>
    <div v-if="selected.size" class="alert info mb-16 row wrap"><span>Выбрано: {{ selected.size }}</span><button class="btn sm primary" @click="bulk('approve')">Одобрить</button><button class="btn sm" @click="bulk('reject')">Отклонить</button><button class="btn sm danger" @click="bulk('block')">Заблокировать</button><button class="btn sm" @click="bulk('unblock')">Разблокировать</button><button class="btn ghost sm" @click="selected = new Set()">Снять</button></div>
    <ListError v-if="list.error.value" compact class="mb-16" :error="list.error.value" @retry="list.load()" />
    <div class="table-wrap"><table class="table"><thead><tr><th style="width:32px"><input type="checkbox" @change="selected = $event.target.checked ? new Set(list.items.value.map((u) => u.id)) : new Set()" /></th><th>Пользователь</th><th>Статус</th><th>Роль</th><th>Вход</th><th>Видео</th><th>Активность</th><th></th></tr></thead><tbody>
      <tr v-for="u in list.items.value" :key="u.id">
        <td><input type="checkbox" :checked="selected.has(u.id)" @change="toggle(u.id)" /></td>
        <td><router-link :to="`/admin/users/${u.id}`" class="row" style="color:var(--text)"><ChannelAvatar :user="u" size="sm" /><span><div style="font-weight:500">{{ u.displayName }}</div><div class="tiny muted">{{ u.email }} • @{{ u.handle }}</div></span></router-link></td>
        <td><span class="badge" :class="STATUS[u.status]?.[1]">{{ STATUS[u.status]?.[0] }}</span></td>
        <td class="small">{{ ROLE[u.role] }}</td>
        <td class="small">{{ u.authProvider === 'ldap' ? 'AD/LDAP' : 'Локально' }}</td>
        <td class="small">{{ u.videoCount }} <span class="faint">({{ fmtBytes(u.storageBytes) }})</span></td>
        <td class="small nowrap">{{ u.lastSeenAt ? timeAgo(u.lastSeenAt) : 'рег. ' + fmtDateShort(u.createdAt) }}</td>
        <td class="actions">
          <template v-if="u.status === 'pending_approval' || u.status === 'pending_email'"><button class="btn sm primary" @click="act(u, 'approve')">Одобрить</button> <button class="btn sm" @click="rejectDlg = u">Отклонить</button></template>
          <button v-else-if="u.status === 'active' && u.id !== auth.user.id" class="btn ghost sm danger" @click="act(u, 'block')">Заблокировать</button>
          <button v-else-if="u.status === 'blocked' || u.status === 'rejected'" class="btn sm" @click="act(u, 'unblock')">Активировать</button>
        </td>
      </tr>
      <tr v-if="!list.items.value.length && !list.loading.value"><td colspan="8" class="muted">Пользователи не найдены</td></tr>
    </tbody></table></div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="30" @change="list.setPage" />
    <Modal v-if="rejectDlg" title="Отклонить заявку" narrow @close="rejectDlg = null">
      <p class="small">{{ rejectDlg.displayName }} ({{ rejectDlg.email }})</p>
      <div class="field"><label>Причина (будет отправлена пользователю)</label><textarea class="textarea" v-model="rejectNote"></textarea></div>
      <template #footer><button class="btn" @click="rejectDlg = null">Отмена</button><button class="btn danger primary" @click="act(rejectDlg, 'reject', { note: rejectNote }); rejectDlg = null">Отклонить</button></template>
    </Modal>
    <Modal v-if="createDlg" title="Создать пользователя" narrow @close="createDlg = false">
      <div class="col gap-12"><div class="field"><label>E-mail</label><input class="input" type="email" v-model="createForm.email" /></div><div class="field"><label>Имя</label><input class="input" v-model="createForm.displayName" /></div><div class="field"><label>Пароль</label><input class="input" v-model="createForm.password" /></div><div class="field"><label>Роль</label><select class="select" v-model="createForm.role"><option v-for="(l, k) in ROLE" :key="k" :value="k">{{ l }}</option></select></div></div>
      <template #footer><button class="btn" @click="createDlg = false">Отмена</button><button class="btn primary" @click="create">Создать</button></template>
    </Modal>
  </div>
</template>
