<script setup>
import { ref, onMounted } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { fmtDateTime } from '../../utils/format.js';
import { copyWithToast } from '../../utils/clipboard.js';

const ui = useUi();
const auth = useAuth();
const domains = ref([]);
const invites = ref([]);
const form = ref({ domain: '', autoApprove: false, note: '' });
const invite = ref({ email: '', role: 'user' });
const lastInvite = ref(null);
async function load() { domains.value = (await get('/api/admin/domains')).domains; invites.value = (await get('/api/admin/invites')).invites; }
onMounted(load);
async function addDomain() { try { await post('/api/admin/domains', form.value); form.value = { domain: '', autoApprove: false, note: '' }; await load(); ui.toast('Домен добавлен', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function toggleAuto(d) { await patch(`/api/admin/domains/${d.id}`, { autoApprove: !d.autoApprove }); d.autoApprove = !d.autoApprove; }
async function removeDomain(d) { if (await ui.ask({ title: `Удалить домен @${d.domain}?`, message: 'Новые пользователи с этого домена не смогут регистрироваться. Существующие учётные записи не затрагиваются.', okLabel: 'Удалить', danger: true })) { await del(`/api/admin/domains/${d.id}`); load(); } }
async function sendInvite() { try { const r = await post('/api/admin/invites', invite.value); lastInvite.value = r; invite.value.email = ''; await load(); ui.toast(r.sent ? 'Приглашение отправлено' : 'Почта не настроена — передайте ссылку вручную', { type: r.sent ? 'success' : 'info' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function revoke(i) { await del(`/api/admin/invites/${i.token}`); load(); }
async function copy(t) { await copyWithToast(ui, t); }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Домены и приглашения</h1><div class="sub">Кто может зарегистрироваться на портале</div></div></div>
    <div class="two-col">
      <div class="col gap-24">
        <div class="panel">
          <h3 class="mb-8">Разрешённые домены почты</h3>
          <p class="small muted">Регистрация доступна только с адресов этих доменов. «Автоодобрение» активирует учётную запись сразу после подтверждения почты, иначе заявку одобряет администратор.</p>
          <div v-if="auth.isAdmin" class="row wrap mb-16"><input class="input" v-model="form.domain" placeholder="vodokomfort.ru" style="max-width: 240px" @keydown.enter="addDomain" /><input class="input" v-model="form.note" placeholder="Примечание" style="max-width: 220px" /><label class="check"><input type="checkbox" v-model="form.autoApprove" /> Автоодобрение</label><button class="btn primary sm" @click="addDomain"><Icon name="plus" :size="16" /> Добавить</button></div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Домен</th><th>Одобрение</th><th>Пользователей</th><th>Примечание</th><th></th></tr></thead><tbody>
            <tr v-for="d in domains" :key="d.id"><td class="mono">@{{ d.domain }}</td><td><label class="switch"><input type="checkbox" :checked="d.autoApprove" :disabled="!auth.isAdmin" @change="toggleAuto(d)" /><span class="track"></span><span class="small">{{ d.autoApprove ? 'Автоматически' : 'Администратором' }}</span></label></td><td>{{ d.users }}</td><td class="small muted">{{ d.note }}</td><td class="actions"><button v-if="auth.isAdmin" class="btn ghost sm danger" @click="removeDomain(d)">Удалить</button></td></tr>
            <tr v-if="!domains.length"><td colspan="5" class="muted">Домены не заданы — регистрация невозможна</td></tr>
          </tbody></table></div>
        </div>
      </div>
      <div class="col gap-24">
        <div class="panel">
          <h3 class="mb-8">Приглашения</h3>
          <p class="small muted">Приглашённый создаёт учётную запись по ссылке без проверки домена и одобрения. Ссылка действует 7 дней.</p>
          <div class="col gap-8 mb-16"><input class="input" type="email" v-model="invite.email" placeholder="email@example.com" /><div class="row"><select v-if="auth.isAdmin" class="select" v-model="invite.role"><option value="user">Пользователь</option><option value="moderator">Модератор</option><option value="admin">Администратор</option></select><button class="btn primary sm" @click="sendInvite"><Icon name="emailFast" :size="16" /> Пригласить</button></div></div>
          <div v-if="lastInvite" class="code-box mb-16"><span>{{ lastInvite.url }}</span><button class="ibtn sm" @click="copy(lastInvite.url)" aria-label="Скопировать ссылку приглашения"><Icon name="copy" :size="16" /></button></div>
          <div class="col gap-8 small"><div v-for="i in invites" :key="i.token" class="row"><span class="grow ellipsis">{{ i.email }} <span class="badge">{{ i.role }}</span><div class="tiny muted">от {{ i.inviter }} • до {{ fmtDateTime(i.expiresAt) }}</div></span><button class="ibtn sm" title="Копировать ссылку" @click="copy(i.url)" aria-label="Копировать ссылку"><Icon name="copy" :size="16" /></button><button class="ibtn sm" title="Отозвать" @click="revoke(i)" aria-label="Отозвать"><Icon name="close" :size="16" /></button></div><span v-if="!invites.length" class="muted">Активных приглашений нет</span></div>
        </div>
      </div>
    </div>
  </div>
</template>
