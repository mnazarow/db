<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { post, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { usePaged } from '../../utils/paged.js';
import Pagination from '../../components/Pagination.vue';
import Dropdown from '../../components/Dropdown.vue';
import Modal from '../../components/Modal.vue';
import { fmtDuration, fmtNumber, fmtDateShort, fmtBytes, VISIBILITY, STATUS } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const f = ref({ q: route.query.q || '', status: route.query.status || '', visibility: route.query.visibility || '', moderation: route.query.moderation || '', sort: route.query.sort || '', deleted: route.query.deleted || '', blocked: route.query.blocked || '' });
const list = usePaged('/api/admin/videos', { limit: 30, extraQuery: () => ({ ...f.value }) });
const selected = ref(new Set());
const blockDlg = ref(null); const blockReason = ref('');
let t = null;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.replace({ query: Object.fromEntries(Object.entries(f.value).filter(([, v]) => v)) }), 250); }, { deep: true });
async function act(v, action, body = {}) { try { await post(`/api/admin/videos/${v.id}/${action}`, body); list.load(); ui.toast('Готово', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function remove(v) { if (await ui.ask({ title: 'Удалить видео?', message: `«${v.title}» (${v.owner?.displayName})`, okLabel: 'Удалить', danger: true })) { await del(`/api/videos/${v.id}`); list.load(); } }
async function bulk(action, extra = {}) { await post('/api/admin/videos/bulk', { ids: [...selected.value], action, ...extra }); selected.value = new Set(); list.load(); ui.toast('Выполнено', { type: 'success' }); }
function toggle(id) { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Все видео</h1><div class="sub">{{ list.total.value }} видео</div></div></div>
    <div class="row wrap mb-16">
      <input class="input" v-model="f.q" placeholder="Название или автор" style="max-width: 240px" />
      <select class="select" v-model="f.status" style="max-width: 160px"><option value="">Любой статус</option><option v-for="(l, k) in STATUS" :key="k" :value="k">{{ l }}</option></select>
      <select class="select" v-model="f.visibility" style="max-width: 180px"><option value="">Любая видимость</option><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select>
      <select class="select" v-model="f.moderation" style="max-width: 180px"><option value="">Любая модерация</option><option value="pending">На проверке</option><option value="approved">Одобрено</option><option value="rejected">Отклонено</option></select>
      <select class="select" v-model="f.sort" style="max-width: 180px"><option value="">Новые</option><option value="views">Просмотры</option><option value="size">Размер</option><option value="reports">Жалобы</option><option value="title">Название</option></select>
      <label class="check"><input type="checkbox" :checked="f.blocked === '1'" @change="f.blocked = $event.target.checked ? '1' : ''" /> Заблокированные</label>
      <label class="check"><input type="checkbox" :checked="f.deleted === '1'" @change="f.deleted = $event.target.checked ? '1' : ''" /> Удалённые</label>
    </div>
    <div v-if="selected.size" class="alert info mb-16 row wrap"><span>Выбрано: {{ selected.size }}</span><button class="btn sm primary" @click="bulk('approve')">Одобрить</button><button class="btn sm" @click="bulk('reject')">Отклонить</button>
      <Dropdown align="left"><template #trigger><button class="btn sm">Видимость <Icon name="chevronDown" :size="16" /></button></template><button v-for="(v, k) in VISIBILITY" :key="k" class="item" @click="bulk('visibility', { visibility: k })"><Icon class="ic" :name="v.icon" :size="18" /> {{ v.label }}</button></Dropdown>
      <button class="btn sm" @click="bulk('reprocess')">Обработать заново</button><button class="btn ghost sm" @click="selected = new Set()">Снять</button></div>
    <ListError v-if="list.error.value" compact class="mb-16" :error="list.error.value" @retry="list.load()" />
    <div class="table-wrap"><table class="table"><thead><tr><th style="width:32px"><input type="checkbox" @change="selected = $event.target.checked ? new Set(list.items.value.map((v) => v.id)) : new Set()" /></th><th>Видео</th><th>Автор</th><th>Статус</th><th>Видимость</th><th class="num">Просмотры</th><th class="num">Размер</th><th>Дата</th><th></th></tr></thead><tbody>
      <tr v-for="v in list.items.value" :key="v.id">
        <td><input type="checkbox" :checked="selected.has(v.id)" @change="toggle(v.id)" /></td>
        <td><div class="sv-video"><router-link :to="`/watch/${v.shortId}`" class="thumb sv-thumb"><img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" /><span v-if="v.duration" class="dur">{{ fmtDuration(v.duration) }}</span></router-link><div style="min-width:0"><router-link :to="`/studio/videos/${v.id}`" class="sv-title clamp-2">{{ v.title }}</router-link><div class="row gap-4 wrap tiny"><span v-if="v.openReports" class="badge danger">{{ v.openReports }} жалоб</span><span v-if="v.isBlocked" class="badge danger">Заблокировано</span><span v-if="v.moderationStatus === 'pending'" class="badge warning">На проверке</span><span v-if="v.moderationStatus === 'rejected'" class="badge danger">Отклонено</span><span v-if="v.deletedAt" class="badge">Удалено</span></div></div></div></td>
        <td class="small"><router-link :to="`/admin/users/${v.owner.id}`">{{ v.owner.displayName }}</router-link><div class="tiny muted">{{ v.ownerEmail }}</div></td>
        <td><span class="badge" :class="v.status === 'ready' ? 'success' : v.status === 'failed' ? 'danger' : 'warning'">{{ STATUS[v.status] }}</span></td>
        <td class="small">{{ VISIBILITY[v.visibility]?.label }}</td>
        <td class="num">{{ fmtNumber(v.viewCount) }}</td>
        <td class="num small">{{ fmtBytes(v.storageBytes) }}</td>
        <td class="small nowrap">{{ fmtDateShort(v.createdAt) }}</td>
        <td class="actions"><Dropdown><template #trigger><button class="ibtn sm" aria-label="Действия с видео"><Icon name="more" :size="20" /></button></template>
          <router-link :to="`/watch/${v.shortId}`" class="item"><Icon class="ic" name="play" :size="18" /> Открыть</router-link>
          <router-link :to="`/studio/videos/${v.id}`" class="item"><Icon class="ic" name="edit" :size="18" /> Редактировать</router-link>
          <button v-if="v.moderationStatus !== 'approved'" class="item" @click="act(v, 'approve')"><Icon class="ic" name="check" :size="18" /> Одобрить</button>
          <button v-if="v.moderationStatus !== 'rejected'" class="item" @click="act(v, 'reject', { note: '' })"><Icon class="ic" name="cancel" :size="18" /> Отклонить</button>
          <button v-if="!v.isBlocked" class="item" @click="blockDlg = v"><Icon class="ic" name="gavel" :size="18" /> Заблокировать</button>
          <button v-else class="item" @click="act(v, 'unblock')"><Icon class="ic" name="lockOpen" :size="18" /> Разблокировать</button>
          <button v-if="v.deletedAt" class="item" @click="act(v, 'restore')"><Icon class="ic" name="replay" :size="18" /> Восстановить</button>
          <div class="sep"></div><button v-if="!v.deletedAt" class="item danger" @click="remove(v)"><Icon class="ic" name="delete" :size="18" /> Удалить</button>
        </Dropdown></td>
      </tr>
      <tr v-if="!list.items.value.length && !list.loading.value"><td colspan="9" class="muted">Видео не найдены</td></tr>
    </tbody></table></div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="30" @change="list.setPage" />
    <Modal v-if="blockDlg" title="Заблокировать видео" narrow @close="blockDlg = null"><div class="field"><label>Причина (увидит автор)</label><textarea class="textarea" v-model="blockReason"></textarea></div><template #footer><button class="btn" @click="blockDlg = null">Отмена</button><button class="btn danger primary" @click="act(blockDlg, 'block', { reason: blockReason }); blockDlg = null">Заблокировать</button></template></Modal>
  </div>
</template>
