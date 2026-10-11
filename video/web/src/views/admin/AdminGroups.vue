<script setup>
import { ref, onMounted, watch } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
import Modal from '../../components/Modal.vue';
import { fmtDateTime, downloadBlob } from '../../utils/format.js';

const ui = useUi();
const auth = useAuth();
const groups = ref([]);
const selected = ref(null);
const members = ref([]);
const memberQuery = ref('');
const editor = ref(null); // { id?, name, description, ldapDn }
const addText = ref('');
const userQuery = ref('');
const userResults = ref([]);
let ut = null;

async function load() { groups.value = (await get('/api/admin/groups')).groups; if (selected.value) { const g = groups.value.find((x) => x.id === selected.value.id); if (g) selected.value = g; } }
async function open(g) { selected.value = g; memberQuery.value = ''; await loadMembers(); }
async function loadMembers() { if (!selected.value) return; const r = await get(`/api/admin/groups/${selected.value.id}?q=${encodeURIComponent(memberQuery.value)}&limit=500`); members.value = r.members; selected.value = r.group; }
onMounted(load);
watch(memberQuery, () => { clearTimeout(ut); ut = setTimeout(loadMembers, 250); });
watch(userQuery, (q) => { clearTimeout(ut); if (q.trim().length < 2) { userResults.value = []; return; } ut = setTimeout(async () => { userResults.value = (await get(`/api/users/lookup?q=${encodeURIComponent(q.trim())}`)).users.filter((u) => !members.value.some((m) => m.id === u.id)); }, 250); });

function newGroup() { editor.value = { name: '', description: '', ldapDn: '' }; }
function editGroup(g) { editor.value = { id: g.id, name: g.name, description: g.description, ldapDn: g.ldapDn || '' }; }
async function saveGroup() {
  try {
    if (editor.value.id) await patch(`/api/admin/groups/${editor.value.id}`, editor.value);
    else { const r = await post('/api/admin/groups', editor.value); await load(); open(r.group); }
    editor.value = null; await load(); ui.toast('Сохранено', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function removeGroup(g) {
  if (!(await ui.ask({ title: `Удалить группу «${g.name}»?`, message: 'Доступы и назначения, выданные этой группе, перестанут действовать. Учётные записи сотрудников не затрагиваются.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/admin/groups/${g.id}`); if (selected.value?.id === g.id) selected.value = null; await load();
}
async function addUser(u) { await addMembers([u.id]); userQuery.value = ''; userResults.value = []; }
async function addBulk() {
  const items = addText.value.split(/[\s,;]+/).map((s) => s.trim()).filter(Boolean);
  if (!items.length) return;
  await addMembers(items); addText.value = '';
}
async function addMembers(users) {
  try {
    const r = await post(`/api/admin/groups/${selected.value.id}/members`, { users });
    await loadMembers(); await load();
    ui.toast(`Добавлено: ${r.added}${r.notFound.length ? `, не найдено: ${r.notFound.join(', ')}` : ''}`, { type: r.notFound.length ? 'info' : 'success', timeout: 7000 });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function removeMember(m) { await del(`/api/admin/groups/${selected.value.id}/members/${m.id}`); await loadMembers(); await load(); }
function exportCsv() {
  const rows = [['Сотрудник', 'E-mail', 'Источник', 'Добавлен'], ...members.value.map((m) => [m.displayName, m.email, m.viaLdap ? 'Active Directory' : 'вручную', new Date(m.addedAt).toLocaleString('ru-RU')])];
  const csv = '﻿' + rows.map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
  downloadBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), `group-${selected.value.name}.csv`);
}
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Группы сотрудников</h1><div class="sub">Для выдачи доступа к приватным видео и назначения обязательных просмотров целым отделам</div></div><button class="btn primary" @click="newGroup"><Icon name="plus" :size="18" /> Новая группа</button></div>
    <div class="groups-layout">
      <div class="panel" style="padding: 8px">
        <button v-for="g in groups" :key="g.id" class="group-row" :class="{ active: selected?.id === g.id }" @click="open(g)">
          <Icon name="groups" :size="20" class="muted" />
          <span class="grow" style="min-width:0"><span class="ellipsis" style="display:block; font-weight:500">{{ g.name }}</span><span class="tiny muted">{{ g.memberCount }} чел.<span v-if="g.ldapDn"> • AD</span></span></span>
        </button>
        <div v-if="!groups.length" class="muted small" style="padding: 12px">Групп пока нет. Создайте первую — например, «Отдел продаж» или «Новые сотрудники».</div>
      </div>
      <div v-if="selected" class="panel">
        <div class="row wrap mb-8" style="align-items:flex-start">
          <div class="grow" style="min-width:0"><h3>{{ selected.name }}</h3><div class="small muted">{{ selected.description || 'Без описания' }}</div><div v-if="selected.ldapDn" class="tiny muted mono mt-4">AD: {{ selected.ldapDn }}</div></div>
          <div class="row gap-4"><button class="btn ghost sm" @click="editGroup(selected)"><Icon name="edit" :size="16" /> Изменить</button><button class="btn ghost sm" @click="exportCsv" :disabled="!members.length"><Icon name="csv" :size="16" /> CSV</button><button v-if="auth.isAdmin" class="btn ghost sm danger" @click="removeGroup(selected)" aria-label="Удалить группу"><Icon name="delete" :size="16" /></button></div>
        </div>
        <div class="row wrap mb-16" style="align-items:flex-end">
          <div class="field grow" style="position:relative; min-width: 240px"><label>Добавить сотрудника</label><input class="input" v-model="userQuery" placeholder="Имя или e-mail" />
            <div v-if="userResults.length" class="menu" style="left:0;right:0;top:100%; z-index: 5"><button v-for="u in userResults" :key="u.id" class="item" @click="addUser(u)"><ChannelAvatar :user="u" size="xs" /> {{ u.displayName }} <span class="tiny muted">{{ u.email }}</span></button></div>
          </div>
          <div class="field grow" style="min-width: 240px"><label>Или список адресов (через запятую, пробел или с новой строки)</label><div class="row"><input class="input" v-model="addText" placeholder="ivanov@vodokomfort.ru, petrov@vodokomfort.ru" @keydown.enter="addBulk" /><button class="btn sm" @click="addBulk">Добавить</button></div></div>
        </div>
        <div class="row mb-8"><input class="input sm" v-model="memberQuery" placeholder="Поиск среди участников" style="max-width: 280px" /><span class="small muted">{{ selected.memberCount }} чел.</span></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Сотрудник</th><th>E-mail</th><th>Источник</th><th>Добавлен</th><th></th></tr></thead><tbody>
          <tr v-for="m in members" :key="m.id"><td><div class="row gap-8"><ChannelAvatar :user="m" size="xs" /><router-link :to="`/admin/users/${m.id}`">{{ m.displayName }}</router-link><span v-if="m.status !== 'active'" class="badge warning">{{ m.status }}</span></div></td><td class="small">{{ m.email }}</td><td class="small">{{ m.viaLdap ? 'Active Directory' : 'Вручную' }}</td><td class="small muted">{{ fmtDateTime(m.addedAt) }}</td><td class="actions"><button class="ibtn sm" title="Исключить" @click="removeMember(m)" aria-label="Исключить"><Icon name="close" :size="16" /></button></td></tr>
          <tr v-if="!members.length"><td colspan="5" class="muted">В группе пока никого</td></tr>
        </tbody></table></div>
      </div>
      <div v-else class="panel muted small" style="display:flex; align-items:center; justify-content:center; min-height: 240px; text-align:center">Выберите группу слева или создайте новую.<br>Группы с указанным DN синхронизируются из Active Directory при входе сотрудника через LDAP.</div>
    </div>

    <Modal v-if="editor" :title="editor.id ? 'Группа' : 'Новая группа'" @close="editor = null">
      <div class="col gap-12">
        <div class="field"><label>Название</label><input class="input" v-model="editor.name" maxlength="80" placeholder="Отдел продаж" @keydown.enter="saveGroup" /></div>
        <div class="field"><label>Описание</label><input class="input" v-model="editor.description" maxlength="500" /></div>
        <div class="field"><label>DN группы Active Directory (необязательно)</label><input class="input mono" v-model="editor.ldapDn" placeholder="CN=Sales,OU=Groups,DC=example,DC=local" /><div class="hint">При входе через LDAP состав группы обновляется по атрибуту memberOf сотрудника.</div></div>
      </div>
      <template #footer><button class="btn ghost" @click="editor = null">Отмена</button><button class="btn primary" :disabled="!editor.name.trim()" @click="saveGroup">Сохранить</button></template>
    </Modal>
  </div>
</template>

<style>
.groups-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 20px; align-items: start; }
.group-row { display: flex; gap: 10px; align-items: center; width: 100%; padding: 10px 12px; border-radius: var(--radius-sm); text-align: left; font: inherit; color: var(--text); background: none; border: 0; cursor: pointer; }
.group-row:hover { background: var(--bg-2); }
.group-row.active { background: var(--brand-100); color: var(--brand); }
@media (max-width: 900px) { .groups-layout { grid-template-columns: 1fr; } }
</style>
