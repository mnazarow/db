<script setup>
// Хранение: правила срока хранения, архив, отметка «не удалять» и пересмотр актуальности.
import { ref, onMounted, computed } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import { fmtDateTime, fmtDateShort, fmtBytes, fmtNumber } from '../../utils/format.js';

const ui = useUi();
const auth = useAuth();
const data = ref(null);
const loading = ref(true);
const dialog = ref(null);
const categories = ref([]);
const tab = ref('rules');
const videos = ref({ items: [], total: 0 });
const log = ref({ items: [], total: 0 });
const preview = ref(null);
const SCOPES = [['all', 'Все видео'], ['category', 'Категория'], ['visibility', 'Видимость'], ['tag', 'Тег'], ['live', 'Записи эфиров']];
const ACTIONS = [['archive', 'Убрать в архив'], ['delete', 'Удалить (в корзину)'], ['notify', 'Только уведомить автора']];
const ACTION_LABEL = { archive: 'в архив', delete: 'удалено', notify: 'уведомление', restore: 'возвращено' };

async function load() {
  loading.value = true;
  try {
    const [r, c] = await Promise.all([get('/api/admin/retention'), get('/api/feed/categories').catch(() => ({ categories: [] }))]);
    data.value = r; categories.value = c.categories || [];
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
async function loadVideos(kind) { try { videos.value = await get(`/api/admin/lifecycle/videos?kind=${kind}`); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function loadLog() { try { log.value = await get('/api/admin/retention/log?limit=50'); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
function go(t) {
  tab.value = t;
  if (['archived', 'hold', 'stale'].includes(t)) loadVideos(t);
  if (t === 'log') loadLog();
}
onMounted(load);

function openNew() { dialog.value = { id: null, name: '', scope: 'all', categoryId: '', visibility: 'internal', tag: '', afterDays: 365, action: 'archive', warnDays: 14, enabled: true }; }
function openEdit(r) { dialog.value = { ...r, categoryId: r.categoryId || '', tag: r.tag || '', visibility: r.visibility || 'internal' }; }
async function save() {
  const d = dialog.value;
  const body = { name: d.name, scope: d.scope, categoryId: d.categoryId || null, visibility: d.visibility, tag: d.tag, afterDays: Number(d.afterDays), action: d.action, warnDays: Number(d.warnDays), enabled: d.enabled };
  try {
    if (d.id) await patch(`/api/admin/retention/${d.id}`, body); else await post('/api/admin/retention', body);
    dialog.value = null; await load(); ui.toast('Правило сохранено', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function remove(r) {
  if (!(await ui.ask({ title: `Удалить правило «${r.name}»?`, okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/admin/retention/${r.id}`); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function showPreview(r) {
  try { const x = await get(`/api/admin/retention/${r.id}/preview`); preview.value = { rule: x.rule, ...x.preview }; }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function run(r) {
  const p = r.preview || {};
  if (!(await ui.ask({ title: `Применить правило «${r.name}» сейчас?`, message: `Под правило попадает ${p.total || 0} видео (${fmtBytes(p.bytes || 0)}). Действие: ${ACTIONS.find((a) => a[0] === r.action)?.[1]}.`, okLabel: 'Применить', danger: r.action === 'delete' }))) return;
  try { const res = await post(`/api/admin/retention/${r.id}/run`, {}); ui.toast(`Обработано видео: ${res.done}, предупреждений: ${res.warned}`, { type: 'success' }); await load(); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function unarchive(v) {
  try { await post(`/api/admin/lifecycle/videos/${v.id}/archive`, { archived: false }); await loadVideos('archived'); ui.toast('Видео возвращено', { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function toggleHold(v, on) {
  try { await post(`/api/admin/lifecycle/videos/${v.id}/hold`, { hold: on }); await loadVideos(tab.value === 'hold' ? 'hold' : tab.value); ui.toast(on ? 'Отметка «не удалять» поставлена' : 'Отметка снята', { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function extend(v) {
  try { await post(`/api/admin/lifecycle/videos/${v.id}/freshness`, { months: 12 }); await loadVideos('stale'); ui.toast('Актуальность продлена на год', { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const scopeLabel = (r) => r.scope === 'category' ? `категория «${categories.value.find((c) => c.id === r.categoryId)?.name || '—'}»`
  : r.scope === 'visibility' ? `видимость «${r.visibility}»` : r.scope === 'tag' ? `тег «${r.tag}»` : r.scope === 'live' ? 'записи эфиров' : 'все видео';
</script>

<template>
  <div>
    <div class="row wrap mb-16" style="align-items:center">
      <div class="grow"><h2>Хранение</h2><p class="small muted" style="margin:4px 0 0">Сколько живёт материал, что уходит в архив, что нельзя удалять и что пора пересмотреть.</p></div>
      <button v-if="auth.isAdmin && tab === 'rules'" class="btn primary" @click="openNew"><Icon name="plus" :size="16" /> Правило хранения</button>
    </div>

    <div v-if="data && !data.enabled" class="alert warning mb-16"><Icon name="alertCircle" :size="20" /><span>Правила хранения выключены в настройках портала — раздел «Хранение и очистка».</span></div>

    <div class="tabs mb-16">
      <button class="tab" :class="{ active: tab === 'rules' }" @click="go('rules')">Правила</button>
      <button class="tab" :class="{ active: tab === 'archived' }" @click="go('archived')">Архив<span v-if="data?.stats?.archived" class="badge">{{ data.stats.archived }}</span></button>
      <button class="tab" :class="{ active: tab === 'hold' }" @click="go('hold')">Не удалять<span v-if="data?.stats?.held" class="badge">{{ data.stats.held }}</span></button>
      <button class="tab" :class="{ active: tab === 'stale' }" @click="go('stale')">Требуют пересмотра<span v-if="data?.stats?.stale" class="badge warning">{{ data.stats.stale }}</span></button>
      <button class="tab" :class="{ active: tab === 'log' }" @click="go('log')">Журнал</button>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>

    <!-- Правила -->
    <template v-else-if="tab === 'rules'">
      <EmptyState v-if="!data.rules.length" icon="clock" title="Правил хранения нет" text="Например: «записи планёрок — 180 дней, потом в архив» или «видео с тегом «черновик» — 30 дней, потом в корзину»." />
      <div v-else class="col gap-12">
        <div v-for="r in data.rules" :key="r.id" class="panel" :class="{ muted: !r.enabled }">
          <div class="row wrap gap-8" style="align-items:center">
            <div class="grow">
              <b>{{ r.name }}</b>
              <div class="small muted">{{ scopeLabel(r) }} · через {{ r.afterDays }} дн. после публикации → {{ ACTIONS.find((a) => a[0] === r.action)?.[1].toLowerCase() }}<span v-if="r.warnDays"> · предупредить автора за {{ r.warnDays }} дн.</span></div>
              <div class="tiny muted">Сейчас под правило попадает <b>{{ r.preview?.total || 0 }}</b> видео ({{ fmtBytes(r.preview?.bytes || 0) }})<span v-if="r.appliedAt"> · последний раз применялось {{ fmtDateTime(r.appliedAt) }}, всего обработано {{ r.appliedCount }}</span></div>
            </div>
            <span class="badge" :class="r.enabled ? 'success' : ''">{{ r.enabled ? 'включено' : 'выключено' }}</span>
            <div class="row gap-4 nowrap" style="flex:0 0 auto">
              <button class="ibtn sm" title="Что попадёт" @click="showPreview(r)" aria-label="Что попадёт"><Icon name="eye" :size="16" /></button>
              <button v-if="auth.isAdmin" class="ibtn sm" title="Применить сейчас" @click="run(r)" aria-label="Применить сейчас"><Icon name="play" :size="16" /></button>
              <button v-if="auth.isAdmin" class="ibtn sm" title="Изменить" @click="openEdit(r)" aria-label="Изменить"><Icon name="edit" :size="16" /></button>
              <button v-if="auth.isAdmin" class="ibtn sm" title="Удалить" @click="remove(r)" aria-label="Удалить"><Icon name="delete" :size="16" /></button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Списки видео -->
    <template v-else-if="['archived', 'hold', 'stale'].includes(tab)">
      <EmptyState v-if="!videos.items.length" icon="done" :title="tab === 'archived' ? 'Архив пуст' : tab === 'hold' ? 'Отметок «не удалять» нет' : 'Всё актуально'" text="" />
      <div v-else class="table-wrap"><table class="table"><thead><tr><th>Видео</th><th>Автор</th><th v-if="tab === 'archived'">В архиве с</th><th v-if="tab === 'stale'">Актуально до</th><th>Размер</th><th></th></tr></thead><tbody>
        <tr v-for="v in videos.items" :key="v.id">
          <td><router-link :to="`/watch/${v.shortId}`">{{ v.title }}</router-link><div v-if="v.archivedReason" class="tiny muted">{{ v.archivedReason }}</div></td>
          <td class="small">{{ v.owner }}</td>
          <td v-if="tab === 'archived'" class="small muted">{{ fmtDateTime(v.archivedAt) }}</td>
          <td v-if="tab === 'stale'" class="small"><span class="badge danger">{{ fmtDateShort(v.freshUntil) }}</span></td>
          <td class="small">{{ fmtBytes(v.bytes) }}</td>
          <td class="actions nowrap">
            <button v-if="tab === 'archived'" class="btn sm" @click="unarchive(v)">Вернуть</button>
            <button v-if="tab === 'hold' && auth.isAdmin" class="btn sm" @click="toggleHold(v, false)">Снять отметку</button>
            <template v-if="tab === 'stale'">
              <button class="btn sm" @click="extend(v)">Актуально ещё год</button>
              <router-link class="btn ghost sm" :to="`/studio/videos/${v.id}`">Открыть</router-link>
            </template>
          </td>
        </tr>
      </tbody></table></div>
    </template>

    <!-- Журнал -->
    <template v-else-if="tab === 'log'">
      <div class="row mb-8"><a class="btn sm" :href="$url('/api/admin/retention/log?format=csv&limit=200')"><Icon name="csv" :size="16" /> Выгрузить CSV</a></div>
      <EmptyState v-if="!log.items.length" icon="clipboardList" title="Журнал пуст" text="Здесь появятся записи о том, какие видео и по какому правилу ушли в архив или в корзину." />
      <div v-else class="table-wrap"><table class="table"><thead><tr><th>Когда</th><th>Видео</th><th>Автор</th><th>Правило</th><th>Действие</th></tr></thead><tbody>
        <tr v-for="x in log.items" :key="x.id">
          <td class="small muted nowrap">{{ fmtDateTime(x.at) }}</td>
          <td><router-link v-if="x.shortId && x.alive" :to="`/watch/${x.shortId}`">{{ x.title }}</router-link><span v-else>{{ x.title }}</span></td>
          <td class="small">{{ x.owner || '—' }}</td>
          <td class="small">{{ x.rule || 'вручную' }}</td>
          <td><span class="badge" :class="x.action === 'delete' ? 'danger' : x.action === 'restore' ? 'success' : 'warning'">{{ ACTION_LABEL[x.action] || x.action }}</span></td>
        </tr>
      </tbody></table></div>
    </template>

    <Modal v-if="dialog" :title="dialog.id ? 'Правило хранения' : 'Новое правило хранения'" @close="dialog = null">
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1"><label>Название</label><input class="input" v-model="dialog.name" placeholder="Например, «Записи планёрок — полгода»" /></div>
        <div class="field"><label>К чему применяется</label><select class="select" v-model="dialog.scope"><option v-for="[k, l] in SCOPES" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="field" v-if="dialog.scope === 'category'"><label>Категория</label><select class="select" v-model="dialog.categoryId"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div class="field" v-else-if="dialog.scope === 'visibility'"><label>Видимость</label><select class="select" v-model="dialog.visibility"><option value="public">Всем</option><option value="internal">Сотрудникам</option><option value="unlisted">По ссылке</option><option value="private">Только по доступу</option></select></div>
        <div class="field" v-else-if="dialog.scope === 'tag'"><label>Тег</label><input class="input" v-model="dialog.tag" placeholder="черновик" /></div>
        <div class="field" v-else><label>&nbsp;</label><div class="hint">Правило охватит все видео портала — проверьте предпросмотр перед применением.</div></div>
        <div class="field"><label>Срок хранения, дней</label><input class="input" type="number" min="1" max="3650" v-model.number="dialog.afterDays" /><div class="hint">Отсчёт от даты публикации</div></div>
        <div class="field"><label>Что делать</label><select class="select" v-model="dialog.action"><option v-for="[k, l] in ACTIONS" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="field"><label>Предупредить автора за, дней</label><input class="input" type="number" min="0" max="180" v-model.number="dialog.warnDays" /></div>
        <div class="field" style="justify-content:flex-end"><label class="switch"><input type="checkbox" v-model="dialog.enabled" /><span class="track"></span><span>Правило включено</span></label></div>
      </div>
      <div class="alert mt-8"><Icon name="info" :size="20" /><span class="small">Видео с отметкой «не удалять» правила обходят стороной. Удаление отправляет видео в корзину — оттуда его можно вернуть, пока не истёк срок корзины.</span></div>
      <template #footer><button class="btn ghost" @click="dialog = null">Отмена</button><button class="btn primary" :disabled="!dialog.name || !dialog.afterDays" @click="save">Сохранить</button></template>
    </Modal>

    <Modal v-if="preview" :title="`Под правило «${preview.rule.name}» попадает ${preview.total} видео`" @close="preview = null">
      <p class="small muted">Всего {{ fmtBytes(preview.bytes) }}. Показаны первые {{ preview.items.length }}.</p>
      <div class="table-wrap"><table class="table"><thead><tr><th>Видео</th><th>Автор</th><th>Опубликовано</th><th>Размер</th></tr></thead><tbody>
        <tr v-for="x in preview.items" :key="x.id"><td><router-link :to="`/watch/${x.shortId}`">{{ x.title }}</router-link></td><td class="small">{{ x.owner }}</td><td class="small muted">{{ fmtDateShort(x.publishedAt) }}</td><td class="small">{{ fmtBytes(x.bytes) }}</td></tr>
        <tr v-if="!preview.items.length"><td colspan="4" class="muted">Под правило пока ничего не попадает</td></tr>
      </tbody></table></div>
      <template #footer><button class="btn" @click="preview = null">Закрыть</button></template>
    </Modal>
  </div>
</template>
