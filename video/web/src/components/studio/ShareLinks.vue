<script setup>
// Защищённые ссылки для внешних зрителей: создание (пароль, срок, лимит открытий, скачивание), список, отзыв.
import { ref, onMounted } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDateTime, toLocalInput } from '../../utils/format.js';
import { copyWithToast } from '../../utils/clipboard.js';

const props = defineProps({ video: { type: Object, required: true }, compact: Boolean });
const ui = useUi();
const links = ref([]);
const loading = ref(true);
const creating = ref(false);
const form = ref({ note: '', password: '', expiresAt: toLocalInput(new Date(Date.now() + 14 * 86400000)), maxViews: '', allowDownload: false });
const showRevoked = ref(false);

async function load() { loading.value = true; try { links.value = (await get(`/api/videos/${props.video.id}/share-links`)).links; } finally { loading.value = false; } }
onMounted(load);
async function create() {
  creating.value = true;
  try {
    const r = await post(`/api/videos/${props.video.id}/share-links`, { ...form.value, expiresAt: form.value.expiresAt ? new Date(form.value.expiresAt).toISOString() : null, maxViews: form.value.maxViews ? Number(form.value.maxViews) : null });
    links.value.unshift(r.link); copy(r.link.url); form.value.password = ''; form.value.note = '';
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { creating.value = false; }
}
async function revoke(l, revoked) { const r = await patch(`/api/videos/${props.video.id}/share-links/${l.id}`, { revoked }); Object.assign(l, r.link); }
async function remove(l) { if (!(await ui.ask({ title: 'Удалить ссылку?', message: 'Все, кто открыл видео по ней, потеряют доступ.', okLabel: 'Удалить', danger: true }))) return; await del(`/api/videos/${props.video.id}/share-links/${l.id}`); links.value = links.value.filter((x) => x.id !== l.id); }
async function copy(t) { await copyWithToast(ui, t, 'Ссылка скопирована'); }
function state(l) { if (l.revokedAt) return ['Отозвана', '']; if (l.expiresAt && new Date(l.expiresAt) < new Date()) return ['Истекла', 'warning']; if (l.maxViews != null && l.viewCount >= l.maxViews) return ['Лимит исчерпан', 'warning']; return ['Действует', 'success']; }
</script>

<template>
  <div>
    <p v-if="!compact" class="small muted">Ссылка открывает это видео зрителю без учётной записи — например, партнёру или подрядчику. Доступ можно защитить паролем, ограничить по сроку и числу открытий, а также отозвать в любой момент. Просмотры по ссылкам видны в аналитике.</p>
    <div class="form-grid mb-16" :class="{ compact }">
      <div class="field"><label>Для кого / примечание</label><input class="input" v-model="form.note" maxlength="200" placeholder="Партнёр «Ромашка»" /></div>
      <div class="field"><label>Пароль (необязательно)</label><input class="input" v-model="form.password" type="text" autocomplete="off" placeholder="минимум 4 символа" /></div>
      <div class="field"><label>Действует до</label><input class="input" type="datetime-local" v-model="form.expiresAt" /><div class="hint">Пусто — бессрочно</div></div>
      <div class="field"><label>Лимит открытий</label><input class="input" v-model="form.maxViews" type="number" min="1" placeholder="без лимита" /></div>
      <div class="field" style="grid-column: 1 / -1; flex-direction: row; align-items:center; gap: 16px; flex-wrap: wrap"><label class="switch"><input type="checkbox" v-model="form.allowDownload" /><span class="track"></span><span>Разрешить скачивание файла</span></label><button class="btn primary sm" :disabled="creating" @click="create"><Icon name="linkLock" :size="16" /> Создать ссылку</button></div>
    </div>
    <div v-if="loading" class="muted small">Загрузка…</div>
    <div v-else class="table-wrap"><table class="table">
      <thead><tr><th>Ссылка</th><th>Защита</th><th>Открытий</th><th>Статус</th><th></th></tr></thead>
      <tbody>
        <tr v-for="l in links.filter((x) => showRevoked || !x.revokedAt)" :key="l.id">
          <td><div class="row gap-4"><span class="mono small ellipsis" style="max-width: 260px">{{ l.url.replace(/^https?:\/\//, '') }}</span><button class="ibtn sm" title="Копировать" @click="copy(l.url)" aria-label="Копировать"><Icon name="copy" :size="14" /></button></div><div class="tiny muted">{{ l.note || 'без примечания' }} · {{ l.creatorName }} · {{ fmtDateTime(l.createdAt) }}</div></td>
          <td class="small"><span v-if="l.hasPassword"><Icon name="lock" :size="14" style="vertical-align:-2px" /> пароль</span><span v-if="l.expiresAt"><br v-if="l.hasPassword" />до {{ fmtDateTime(l.expiresAt) }}</span><span v-if="l.allowDownload"><br /><Icon name="download" :size="14" style="vertical-align:-2px" /> скачивание</span><span v-if="!l.hasPassword && !l.expiresAt" class="muted">—</span></td>
          <td class="small">{{ l.viewCount }}<span v-if="l.maxViews != null" class="muted"> / {{ l.maxViews }}</span><div v-if="l.lastUsedAt" class="tiny muted">{{ fmtDateTime(l.lastUsedAt) }}</div></td>
          <td><span class="badge" :class="state(l)[1]">{{ state(l)[0] }}</span></td>
          <td class="actions"><button v-if="!l.revokedAt" class="btn ghost sm" @click="revoke(l, true)">Отозвать</button><button v-else class="btn ghost sm" @click="revoke(l, false)">Вернуть</button><button class="ibtn sm" title="Удалить" @click="remove(l)" aria-label="Удалить"><Icon name="delete" :size="16" /></button></td>
        </tr>
        <tr v-if="!links.filter((x) => showRevoked || !x.revokedAt).length"><td colspan="5" class="muted">Ссылок пока нет</td></tr>
      </tbody>
    </table></div>
    <label v-if="links.some((l) => l.revokedAt)" class="check small mt-8"><input type="checkbox" v-model="showRevoked" /> Показывать отозванные</label>
  </div>
</template>

<style>
.form-grid.compact { grid-template-columns: 1fr 1fr; }
</style>
