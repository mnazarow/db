<script setup>
// Управление вебхуками: список, создание, проверка, журнал доставок, повтор.
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDateTime } from '../../utils/format.js';
import { copyWithToast } from '../../utils/clipboard.js';

const ui = useUi();
const hooks = ref([]);
const events = ref([]);
const form = ref({ name: '', url: '', secret: '', events: [], format: 'json' });
// 1.8: формат доставки — обычный JSON или карточка мессенджера
const FORMATS = [['json', 'JSON (для систем и интеграций)'], ['slack', 'Slack'], ['mattermost', 'Mattermost'], ['teams', 'Microsoft Teams']];
const showForm = ref(false);
const deliveries = ref({ hook: null, list: [], open: null });
const saving = ref(false);

const loadError = ref('');
async function load() {
  try {
    const [h, e] = await Promise.all([get('/api/admin/webhooks'), get('/api/admin/webhooks/events')]);
    hooks.value = h.webhooks; events.value = e.events; loadError.value = '';
  } catch (e) { loadError.value = e.message; ui.toast(e.message, { type: 'error' }); }
}
onMounted(load);

// Отложенные обновления журнала: гасим при уходе со страницы, иначе обращение к закрытой панели
const timers = new Set();
function later(fn, ms) { const t = setTimeout(() => { timers.delete(t); fn(); }, ms); timers.add(t); }
onBeforeUnmount(() => { for (const t of timers) clearTimeout(t); timers.clear(); });
async function create() {
  saving.value = true;
  try { const r = await post('/api/admin/webhooks', form.value); hooks.value.push(r.webhook); showForm.value = false; form.value = { name: '', url: '', secret: '', events: [], format: 'json' }; ui.toast(`Вебхук создан. Секрет для проверки подписи: ${r.webhook.secret}`, { type: 'success', timeout: 12000 }); } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
async function toggle(h) { try { const r = await patch(`/api/admin/webhooks/${h.id}`, { enabled: !h.enabled }); Object.assign(h, r.webhook); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function remove(h) {
  if (!(await ui.ask({ title: `Удалить вебхук «${h.name}»?`, okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/admin/webhooks/${h.id}`); hooks.value = hooks.value.filter((x) => x.id !== h.id); if (deliveries.value.hook?.id === h.id) deliveries.value = { hook: null, list: [], open: null }; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function test(h) { try { await post(`/api/admin/webhooks/${h.id}/test`, {}); ui.toast('Проверочное событие поставлено в очередь'); later(() => showDeliveries(h), 2500); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function showDeliveries(h) {
  if (!h || !hooks.value.some((x) => x.id === h.id)) return; // вебхук могли удалить, пока ждал таймер
  try {
    const list = (await get(`/api/admin/webhooks/${h.id}/deliveries?limit=50`)).deliveries;
    deliveries.value = { hook: h, list, open: deliveries.value.hook?.id === h.id ? deliveries.value.open : null };
    hooks.value = (await get('/api/admin/webhooks')).webhooks;
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function retry(d) {
  try { await post(`/api/admin/webhooks/deliveries/${d.id}/retry`, {}); ui.toast('Повторная доставка поставлена в очередь'); later(() => showDeliveries(deliveries.value.hook), 2500); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
// Секрет в списке не приходит — забираем его отдельным запросом (действие попадает в журнал аудита)
async function copySecret(h) {
  try { const r = await get(`/api/admin/webhooks/${h.id}/secret`); await copyWithToast(ui, r.secret, 'Секрет скопирован'); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function toggleEvent(id) { const e = form.value.events; form.value.events = e.includes(id) ? e.filter((x) => x !== id) : [...e, id]; }
</script>

<template>
  <div>
    <div class="row wrap mb-8" style="align-items:center"><div class="grow"><p class="small muted" style="margin:0">Портал отправляет POST с JSON <span class="mono">{event, at, portal, data}</span> и заголовками <span class="mono">X-CorpVideo-Event</span>, <span class="mono">X-CorpVideo-Delivery</span>, <span class="mono">X-CorpVideo-Timestamp</span>, <span class="mono">X-CorpVideo-Signature: sha256=HMAC(secret, timestamp + "." + body)</span>. Неудачные доставки повторяются до 5 раз с нарастающей задержкой.</p></div><button class="btn primary sm" @click="showForm = !showForm"><Icon name="plus" :size="16" /> Добавить вебхук</button></div>
    <div v-if="showForm" class="panel soft mb-16">
      <div class="form-grid">
        <div class="field"><label>Название</label><input class="input" v-model="form.name" placeholder="Например, Битрикс24 / n8n / LMS" /></div>
        <div class="field"><label>Адрес (URL)</label><input class="input" v-model="form.url" placeholder="https://hooks.company.ru/corpvideo" /></div>
        <div class="field"><label>Формат сообщения</label>
          <select class="select" v-model="form.format"><option v-for="[id, label] in FORMATS" :key="id" :value="id">{{ label }}</option></select>
          <div class="hint">Для Teams, Slack и Mattermost портал отправит готовую карточку с заголовком, подробностями и кнопкой «Открыть» — вставьте адрес входящего вебхука канала.</div>
        </div>
        <div class="field" style="grid-column: 1 / -1"><label>Секрет подписи (пусто — сгенерировать)</label><input class="input" v-model="form.secret" /></div>
      </div>
      <div class="label mt-8 mb-4">События (ничего не выбрано — все)</div>
      <div class="row wrap gap-4"><button v-for="e in events" :key="e.id" class="chip" :class="{ active: form.events.includes(e.id) }" @click="toggleEvent(e.id)"><span class="mono">{{ e.id }}</span> — {{ e.label }}</button></div>
      <div class="form-actions"><button class="btn primary" :disabled="saving || !form.url" @click="create">Создать</button><button class="btn ghost" @click="showForm = false">Отмена</button></div>
    </div>
    <div class="table-wrap"><table class="table wh-table"><thead><tr><th>Вебхук</th><th>События</th><th>Состояние</th><th></th></tr></thead><tbody>
      <tr v-for="h in hooks" :key="h.id" :class="{ muted: !h.enabled }">
        <td><b>{{ h.name }}</b><div class="tiny mono ellipsis" style="max-width:260px" :title="h.url">{{ h.url }}</div><div class="tiny muted">секрет: <span class="mono">{{ h.secretHint }}</span> <button class="ibtn sm" style="width:20px;height:20px;vertical-align:middle" title="Скопировать секрет" @click="copySecret(h)" aria-label="Скопировать секрет"><Icon name="copy" :size="12" /></button></div></td>
        <td class="small" style="max-width:220px">{{ h.events.length ? h.events.join(', ') : 'все' }}<div v-if="h.format && h.format !== 'json'" class="tiny muted">формат: {{ ({ slack: 'Slack', mattermost: 'Mattermost', teams: 'Teams' })[h.format] }}</div></td>
        <td class="small"><span v-if="h.lastStatus" class="badge" :class="h.lastStatus < 300 ? 'success' : 'danger'">HTTP {{ h.lastStatus }}</span><span v-else class="muted">ещё не вызывался</span><div class="tiny muted">доставок: {{ h.deliveries }}<span v-if="h.failed" style="color:var(--danger)"> ({{ h.failed }} неуд.)</span><span v-if="h.failCount"> · ошибок подряд: {{ h.failCount }}</span></div><div v-if="h.lastAt" class="tiny muted">{{ fmtDateTime(h.lastAt) }}</div></td>
        <td class="actions nowrap"><button class="ibtn sm" title="Отправить проверочное событие" @click="test(h)" aria-label="Отправить проверочное событие"><Icon name="send" :size="16" /></button><button class="ibtn sm" title="Журнал доставок" @click="showDeliveries(h)" aria-label="Журнал доставок"><Icon name="clipboardList" :size="16" /></button><button class="ibtn sm" :title="h.enabled ? 'Отключить' : 'Включить'" @click="toggle(h)" :aria-label="h.enabled ? 'Отключить' : 'Включить'"><Icon :name="h.enabled ? 'eyeOff' : 'eye'" :size="16" /></button><button class="ibtn sm" title="Удалить" @click="remove(h)" aria-label="Удалить"><Icon name="delete" :size="16" /></button></td>
      </tr>
      <tr v-if="!hooks.length"><td colspan="4" class="muted">{{ loadError ? `Не удалось загрузить список: ${loadError}` : 'Вебхуков пока нет' }}</td></tr>
    </tbody></table></div>
    <div v-if="deliveries.hook" class="panel soft mt-16">
      <div class="row mb-8" style="align-items:center"><h4 class="grow">Журнал доставок — {{ deliveries.hook.name }}</h4><button class="ibtn sm" @click="deliveries.hook = null" aria-label="Закрыть журнал доставок"><Icon name="close" :size="16" /></button></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Событие</th><th>Статус</th><th>Попыток</th><th>Когда</th><th></th></tr></thead><tbody>
        <template v-for="d in deliveries.list" :key="d.id">
          <tr><td class="mono small">{{ d.id }}</td><td class="mono small">{{ d.event }}</td><td><span class="badge" :class="d.status === 'ok' ? 'success' : d.status === 'failed' ? 'danger' : 'warning'">{{ { ok: 'доставлено', failed: 'ошибка', queued: 'в очереди' }[d.status] }}</span><span v-if="d.httpStatus" class="tiny muted"> HTTP {{ d.httpStatus }}</span></td><td class="small">{{ d.attempts }}</td><td class="small muted">{{ fmtDateTime(d.createdAt) }}</td><td class="actions nowrap"><button class="ibtn sm" title="Данные" @click="deliveries.open = deliveries.open === d.id ? null : d.id" aria-label="Данные"><Icon name="code" :size="16" /></button><button v-if="d.status !== 'ok'" class="ibtn sm" title="Повторить" @click="retry(d)" aria-label="Повторить"><Icon name="refresh" :size="16" /></button></td></tr>
          <tr v-if="deliveries.open === d.id"><td colspan="6"><pre class="code" style="max-height:260px;overflow:auto;white-space:pre-wrap">{{ JSON.stringify(d.payload, null, 2) }}</pre><div v-if="d.response" class="tiny muted">Ответ: {{ d.response }}</div></td></tr>
        </template>
        <tr v-if="!deliveries.list.length"><td colspan="6" class="muted">Доставок ещё не было</td></tr>
      </tbody></table></div>
    </div>
  </div>
</template>
