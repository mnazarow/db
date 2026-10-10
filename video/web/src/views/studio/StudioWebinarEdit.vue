<script setup>
// Управление вебинаром: лендинг, анкета, заявки, материалы, письма и отчёт.
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, patch, post, del, uploadFile } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import BarList from '../../components/charts/BarList.vue';
import { fmtDateTime, fmtDuration, fmtBytes, toLocalInput } from '../../utils/format.js';
import { copyWithToast } from '../../utils/clipboard.js';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const auth = useAuth();
const w = ref(null);
const form = ref({});
const tab = ref('landing');
const loading = ref(true);
const regs = ref([]);
const report = ref(null);
const inviteOpen = ref(false);
const invite = ref({ emails: '', note: '' });
const saving = ref(false);

const FIELD_TYPES = [['text', 'Строка'], ['textarea', 'Текст'], ['select', 'Выбор из списка'], ['checkbox', 'Галочка']];
const STATUS_LABEL = { approved: 'участник', pending: 'ждёт подтверждения', waitlist: 'лист ожидания', declined: 'отклонена', cancelled: 'отменена' };

async function load() {
  loading.value = true;
  try {
    const r = await get(`/api/studio/webinars/${route.params.id}`);
    w.value = r.webinar;
    form.value = {
      title: r.webinar.title, description: r.webinar.description, slug: r.webinar.slug,
      scheduledAt: toLocalInput(r.webinar.scheduledAt), visibility: r.webinar.visibility,
      registration: r.webinar.registration, registrationLimit: r.webinar.registrationLimit,
      registrationNote: r.webinar.registrationNote, regModeration: r.webinar.regModeration,
      regWaitlist: r.webinar.regWaitlist, regExternal: r.webinar.regExternal,
      regClosesAt: toLocalInput(r.webinar.regClosesAt), certEnabled: r.webinar.certEnabled,
      certMinPercent: r.webinar.certMinPercent, record: r.webinar.record, chatEnabled: r.webinar.chatEnabled,
      agenda: (r.webinar.agenda || []).map((a) => ({ ...a })), speakers: (r.webinar.speakers || []).map((s) => ({ ...s })),
      regFields: (r.webinar.regFields || []).map((f) => ({ ...f, optionsText: (f.options || []).join(', ') })),
      cta: r.webinar.cta ? { ...r.webinar.cta } : { label: '', url: '', text: '' },
    };
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);
watch(tab, (t) => { if (t === 'registrations') loadRegs(); if (t === 'report') loadReport(); });

async function loadRegs() { try { regs.value = (await get(`/api/studio/webinars/${route.params.id}/registrations`)).registrations; } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function loadReport() { try { report.value = await get(`/api/studio/webinars/${route.params.id}/report`); } catch (e) { ui.toast(e.message, { type: 'error' }); } }

async function save() {
  saving.value = true;
  try {
    const body = {
      ...form.value,
      scheduledAt: form.value.scheduledAt ? new Date(form.value.scheduledAt).toISOString() : null,
      regClosesAt: form.value.regClosesAt ? new Date(form.value.regClosesAt).toISOString() : null,
      regFields: form.value.regFields.map((f) => ({ ...f, options: String(f.optionsText || '').split(',').map((x) => x.trim()).filter(Boolean) })),
      cta: form.value.cta?.label && form.value.cta?.url ? form.value.cta : null,
    };
    await patch(`/api/studio/webinars/${route.params.id}`, body);
    ui.toast('Сохранено', { type: 'success' });
    await load();
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}

// --- Соведущие: назначает организатор, соведущий может сам отказаться от роли ---------------------------
const cohostQuery = ref('');
const cohostFound = ref([]);
let cohostTimer = null;
watch(cohostQuery, (q) => {
  clearTimeout(cohostTimer);
  if (String(q).trim().length < 2) { cohostFound.value = []; return; }
  cohostTimer = setTimeout(async () => {
    try {
      const r = await get(`/api/users/lookup?q=${encodeURIComponent(q.trim())}`);
      const taken = new Set([w.value.ownerId, ...(w.value.cohosts || []).map((c) => c.id)]);
      cohostFound.value = (r.users || []).filter((u) => !taken.has(u.id));
    } catch { cohostFound.value = []; }
  }, 250);
});
async function setCohosts(ids, message) {
  try {
    await patch(`/api/studio/webinars/${route.params.id}`, { cohosts: ids });
    ui.toast(message, { type: 'success' });
    cohostQuery.value = ''; cohostFound.value = [];
    await load();
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const addCohost = (u) => setCohosts([...(w.value.cohosts || []).map((c) => c.id), u.id], `${u.displayName} — соведущий, ему пришло уведомление`);
const removeCohost = (c) => setCohosts((w.value.cohosts || []).filter((x) => x.id !== c.id).map((x) => x.id), `${c.displayName} больше не соведущий`);
const iAmCohost = computed(() => !!w.value && (w.value.cohosts || []).some((c) => c.id === auth.user?.id) && w.value.ownerId !== auth.user?.id);
async function leaveCohost() {
  if (!(await ui.ask({ title: 'Отказаться от роли соведущего?', message: 'Вебинар пропадёт из вашей студии, организатор получит уведомление.', okLabel: 'Отказаться', danger: true }))) return;
  try { await post(`/api/studio/webinars/${route.params.id}/cohost/leave`, {}); ui.toast('Вы больше не соведущий'); router.push('/studio/webinars'); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}

function addAgenda() { form.value.agenda.push({ time: '', title: '', note: '' }); }
function addSpeaker() { form.value.speakers.push({ name: '', role: '', bio: '' }); }
function addField() { form.value.regFields.push({ id: `f${form.value.regFields.length + 1}`, label: '', type: 'text', required: false, optionsText: '' }); }

async function decide(r, decision) {
  try { await post(`/api/studio/webinars/${route.params.id}/registrations/${r.id}/decision`, { decision }); await loadRegs(); await load(); ui.toast(decision === 'approve' ? 'Заявка одобрена' : 'Заявка отклонена'); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function uploadCover(e) {
  const f = e.target.files?.[0]; if (!f) return;
  try { await uploadFile(`/api/studio/webinars/${route.params.id}/cover`, f); await load(); ui.toast('Обложка обновлена', { type: 'success' }); }
  catch (err) { ui.toast(err.message, { type: 'error' }); }
}
async function uploadMaterial(e) {
  const f = e.target.files?.[0]; if (!f) return;
  try { await uploadFile(`/api/studio/webinars/${route.params.id}/materials`, f); await load(); ui.toast('Материал добавлен', { type: 'success' }); }
  catch (err) { ui.toast(err.message, { type: 'error' }); }
}
async function removeMaterial(m) {
  try { await del(`/api/studio/webinars/${route.params.id}/materials/${m.id}`); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function sendInvites() {
  try { const r = await post(`/api/studio/webinars/${route.params.id}/invite`, invite.value); inviteOpen.value = false; ui.toast(`Приглашений отправлено: ${r.sent}`, { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function sendFollowup() {
  if (!(await ui.ask({ title: 'Отправить письмо со ссылкой на запись?', message: 'Письмо уйдёт всем участникам вебинара вместе с материалами и сертификатами.', okLabel: 'Отправить' }))) return;
  try { const r = await post(`/api/studio/webinars/${route.params.id}/followup`, {}); ui.toast(`Отправлено писем: ${r.sent}, сертификатов: ${r.certificates}`, { type: 'success' }); await load(); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const maxPresence = computed(() => Math.max(1, ...(report.value?.presence || []).map((p) => p.people)));
</script>

<template>
  <div v-if="loading" class="loading-block"><div class="spin"></div></div>
  <div v-else-if="w">
    <div class="row wrap mb-16" style="align-items:center">
      <div class="grow">
        <h2>{{ w.title }}</h2>
        <div class="small muted">
          <a :href="w.landingUrl" target="_blank" rel="noopener">{{ w.landingUrl }}</a>
          <button class="ibtn sm" style="width:22px;height:22px;vertical-align:middle" title="Скопировать ссылку" @click="copyWithToast(ui, w.landingUrl, 'Ссылка скопирована')" aria-label="Скопировать ссылку"><Icon name="copy" :size="13" /></button>
          · записались {{ w.counts.approved }}<span v-if="w.counts.pending"> · на проверке {{ w.counts.pending }}</span><span v-if="w.counts.waitlist"> · в листе ожидания {{ w.counts.waitlist }}</span>
        </div>
      </div>
      <router-link class="btn" :to="`/studio/live/${w.id}`"><Icon name="broadcast" :size="16" /> Эфир и подключение</router-link>
      <button class="btn" @click="inviteOpen = true"><Icon name="mail" :size="16" /> Пригласить</button>
      <button v-if="w.status === 'ended'" class="btn primary" @click="sendFollowup"><Icon name="emailFast" :size="16" /> Письмо с записью</button>
    </div>

    <div class="tabs mb-16">
      <button class="tab" :class="{ active: tab === 'landing' }" @click="tab = 'landing'">Страница</button>
      <button class="tab" :class="{ active: tab === 'form' }" @click="tab = 'form'">Анкета и доступ</button>
      <button class="tab" :class="{ active: tab === 'registrations' }" @click="tab = 'registrations'">Заявки<span v-if="w.counts.pending" class="badge warning">{{ w.counts.pending }}</span></button>
      <button class="tab" :class="{ active: tab === 'materials' }" @click="tab = 'materials'">Материалы</button>
      <button class="tab" :class="{ active: tab === 'report' }" @click="tab = 'report'">Отчёт</button>
    </div>

    <!-- Страница -->
    <div v-if="tab === 'landing'" class="col gap-16">
      <div class="panel">
        <div class="form-grid">
          <div class="field" style="grid-column:1/-1"><label>Название</label><input class="input" v-model="form.title" maxlength="150" /></div>
          <div class="field" style="grid-column:1/-1"><label>Описание</label><textarea class="textarea" rows="4" v-model="form.description"></textarea></div>
          <div class="field"><label>Дата и время</label><input class="input" type="datetime-local" v-model="form.scheduledAt" /></div>
          <div class="field"><label>Адрес страницы</label><div class="row gap-4" style="align-items:center"><span class="small muted">/webinar/</span><input class="input" v-model="form.slug" /></div></div>
          <div class="field"><label>Доступ</label><select class="select" v-model="form.visibility"><option value="internal">Сотрудникам</option><option value="public">Всем</option><option value="unlisted">По ссылке</option></select></div>
          <div class="field"><label>Обложка</label><div class="row gap-8" style="align-items:center"><img v-if="w.coverUrl" :src="w.coverUrl" alt="" style="width:120px;height:68px;object-fit:cover;border-radius:6px" /><label class="btn sm"><Icon name="image" :size="16" /> Загрузить<input type="file" class="hidden" accept="image/*" @change="uploadCover" /></label></div></div>
        </div>
      </div>

      <div class="panel">
        <h3 class="mb-4">Соведущие</h3>
        <p class="small muted mb-12">Соведущий управляет вебинаром наравне с организатором: заявки, материалы, письма и отчёт. О назначении ему приходит уведомление, и он может отказаться от роли.</p>
        <div v-if="!(w.cohosts || []).length" class="small muted mb-8">Соведущих нет.</div>
        <div v-for="c in w.cohosts" :key="c.id" class="row gap-8 mb-8" style="align-items:center">
          <Icon name="person" :size="18" /><span class="grow">{{ c.displayName }} <span class="small muted">{{ c.email }}</span></span>
          <button v-if="w.canEditCohosts" class="ibtn sm" :aria-label="`Убрать соведущего ${c.displayName}`" title="Убрать" @click="removeCohost(c)"><Icon name="close" :size="16" /></button>
        </div>
        <div v-if="w.canEditCohosts" class="cohost-add">
          <input class="input" v-model="cohostQuery" placeholder="Добавить: имя или почта сотрудника" aria-label="Найти сотрудника, чтобы назначить соведущим" />
          <div v-if="cohostFound.length" class="cohost-found">
            <button v-for="u in cohostFound" :key="u.id" class="cohost-opt" @click="addCohost(u)">{{ u.displayName }} <span class="small muted">{{ u.email }}</span></button>
          </div>
        </div>
        <button v-if="iAmCohost" class="btn sm mt-8" @click="leaveCohost"><Icon name="logout" :size="16" /> Отказаться от роли соведущего</button>
      </div>

      <div class="panel">
        <div class="row mb-8" style="align-items:center"><h3 class="grow">Программа</h3><button class="btn sm" @click="addAgenda"><Icon name="plus" :size="16" /> Пункт</button></div>
        <div v-for="(a, i) in form.agenda" :key="i" class="row gap-8 mb-8">
          <input class="input" style="max-width:110px" v-model="a.time" placeholder="10:00" />
          <input class="input grow" v-model="a.title" placeholder="О чём этот блок" />
          <input class="input grow" v-model="a.note" placeholder="Пояснение (необязательно)" />
          <button class="ibtn sm" title="Убрать" @click="form.agenda.splice(i, 1)" aria-label="Убрать"><Icon name="delete" :size="16" /></button>
        </div>
        <div v-if="!form.agenda.length" class="small muted">Программа не заполнена</div>
      </div>

      <div class="panel">
        <div class="row mb-8" style="align-items:center"><h3 class="grow">Спикеры</h3><button class="btn sm" @click="addSpeaker"><Icon name="plus" :size="16" /> Спикер</button></div>
        <div v-for="(s, i) in form.speakers" :key="i" class="row wrap gap-8 mb-8">
          <input class="input" style="min-width:200px" v-model="s.name" placeholder="Имя и фамилия" />
          <input class="input grow" v-model="s.role" placeholder="Должность" />
          <input class="input grow" v-model="s.bio" placeholder="Коротко о спикере" />
          <button class="ibtn sm" title="Убрать" @click="form.speakers.splice(i, 1)" aria-label="Убрать"><Icon name="delete" :size="16" /></button>
        </div>
        <div v-if="!form.speakers.length" class="small muted">Спикеры не указаны</div>
      </div>

      <div class="panel">
        <h3 class="mb-8">Кнопка действия во время вебинара</h3>
        <div class="form-grid">
          <div class="field"><label>Надпись на кнопке</label><input class="input" v-model="form.cta.label" placeholder="Например, «Скачать инструкцию»" /></div>
          <div class="field"><label>Ссылка</label><input class="input" v-model="form.cta.url" placeholder="https://" /></div>
          <div class="field" style="grid-column:1/-1"><label>Пояснение</label><input class="input" v-model="form.cta.text" /></div>
        </div>
      </div>
      <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save">{{ saving ? 'Сохранение…' : 'Сохранить' }}</button></div>
    </div>

    <!-- Анкета и доступ -->
    <div v-else-if="tab === 'form'" class="col gap-16">
      <div class="panel">
        <h3 class="mb-8">Регистрация</h3>
        <div class="col gap-12">
          <label class="switch"><input type="checkbox" v-model="form.registration" /><span class="track"></span><span>Нужна регистрация</span></label>
          <label class="switch"><input type="checkbox" v-model="form.regExternal" /><span class="track"></span><span>Пускать внешних участников (по почте, без учётной записи)</span></label>
          <label class="switch"><input type="checkbox" v-model="form.regModeration" /><span class="track"></span><span>Подтверждать заявки вручную</span></label>
          <label class="switch"><input type="checkbox" v-model="form.regWaitlist" /><span class="track"></span><span>Лист ожидания, когда мест нет</span></label>
          <label class="switch"><input type="checkbox" v-model="form.record" /><span class="track"></span><span>Записывать вебинар</span></label>
          <label class="switch"><input type="checkbox" v-model="form.certEnabled" /><span class="track"></span><span>Выдавать сертификат участника</span></label>
        </div>
        <div class="form-grid mt-16">
          <div class="field"><label>Ограничение мест</label><input class="input" type="number" min="1" v-model.number="form.registrationLimit" placeholder="без ограничения" /></div>
          <div class="field"><label>Регистрация закрывается</label><input class="input" type="datetime-local" v-model="form.regClosesAt" /></div>
          <div class="field"><label>Сертификат: нужно пробыть, % времени</label><input class="input" type="number" min="1" max="100" v-model.number="form.certMinPercent" /></div>
          <div class="field" style="grid-column:1/-1"><label>Пояснение на странице регистрации</label><input class="input" v-model="form.registrationNote" maxlength="200" /></div>
        </div>
      </div>
      <div class="panel">
        <div class="row mb-8" style="align-items:center"><h3 class="grow">Поля анкеты</h3><button class="btn sm" @click="addField"><Icon name="plus" :size="16" /> Поле</button></div>
        <div v-for="(f, i) in form.regFields" :key="i" class="row wrap gap-8 mb-8" style="align-items:center">
          <input class="input grow" v-model="f.label" placeholder="Название поля (например, «Подразделение»)" />
          <select class="select" style="max-width:190px" v-model="f.type"><option v-for="[k, l] in FIELD_TYPES" :key="k" :value="k">{{ l }}</option></select>
          <input v-if="f.type === 'select'" class="input grow" v-model="f.optionsText" placeholder="Варианты через запятую" />
          <label class="switch"><input type="checkbox" v-model="f.required" /><span class="track"></span><span>Обязательное</span></label>
          <button class="ibtn sm" title="Убрать" @click="form.regFields.splice(i, 1)" aria-label="Убрать"><Icon name="delete" :size="16" /></button>
        </div>
        <div v-if="!form.regFields.length" class="small muted">Дополнительных полей нет — участник указывает только имя и почту.</div>
      </div>
      <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save">Сохранить</button></div>
    </div>

    <!-- Заявки -->
    <div v-else-if="tab === 'registrations'">
      <div class="row mb-8"><a class="btn sm" :href="$url(`/api/studio/webinars/${w.id}/registrations?format=csv`)"><Icon name="csv" :size="16" /> Выгрузить CSV</a></div>
      <EmptyState v-if="!regs.length" icon="accountGroup" title="Заявок пока нет" text="Поделитесь ссылкой на страницу вебинара." />
      <div v-else class="table-wrap"><table class="table"><thead><tr><th>Участник</th><th>Организация</th><th v-for="f in w.regFields" :key="f.id">{{ f.label }}</th><th>Состояние</th><th></th></tr></thead><tbody>
        <tr v-for="r in regs" :key="r.id">
          <td><b>{{ r.name }}</b><div class="tiny muted">{{ r.email }}<span v-if="r.external"> · внешний</span></div></td>
          <td class="small">{{ r.company || '—' }}</td>
          <td v-for="f in w.regFields" :key="f.id" class="small">{{ typeof r.answers[f.id] === 'boolean' ? (r.answers[f.id] ? 'да' : 'нет') : (r.answers[f.id] || '—') }}</td>
          <td><span class="badge" :class="r.status === 'approved' ? 'success' : r.status === 'pending' ? 'warning' : ''">{{ STATUS_LABEL[r.status] }}</span><div v-if="r.seconds" class="tiny muted">был {{ fmtDuration(r.seconds) }}</div></td>
          <td class="actions nowrap">
            <template v-if="r.status === 'pending' || r.status === 'waitlist'">
              <button class="btn sm" @click="decide(r, 'approve')">Одобрить</button>
              <button class="btn ghost sm" @click="decide(r, 'decline')">Отклонить</button>
            </template>
          </td>
        </tr>
      </tbody></table></div>
    </div>

    <!-- Материалы -->
    <div v-else-if="tab === 'materials'" class="panel">
      <div class="row mb-8" style="align-items:center"><h3 class="grow">Материалы вебинара</h3><label class="btn sm"><Icon name="upload" :size="16" /> Загрузить<input type="file" class="hidden" @change="uploadMaterial" /></label></div>
      <p class="small muted">Файлы видны участникам на странице вебинара и уходят в письмах: подтверждение регистрации и письмо с записью.</p>
      <div v-for="m in w.materials" :key="m.id" class="row gap-8 mt-8" style="align-items:center">
        <Icon name="doc" :size="18" /><a class="grow" :href="m.url" target="_blank" rel="noopener">{{ m.name }}</a>
        <span class="tiny muted">{{ fmtBytes(m.bytes) }}</span>
        <button class="ibtn sm" title="Убрать" @click="removeMaterial(m)" aria-label="Убрать"><Icon name="delete" :size="16" /></button>
      </div>
      <div v-if="!w.materials.length" class="small muted mt-8">Материалов пока нет</div>
    </div>

    <!-- Отчёт -->
    <div v-else-if="tab === 'report'">
      <div v-if="!report" class="loading-block"><div class="spin"></div></div>
      <template v-else>
        <div class="stat-tiles mb-16">
          <div class="stat-tile"><div class="v">{{ report.funnel.registered }}</div><div class="l">Записались</div><div class="d">{{ report.funnel.external }} внешних</div></div>
          <div class="stat-tile"><div class="v">{{ report.funnel.attended }}</div><div class="l">Пришли</div><div class="d">{{ report.funnel.registered ? Math.round(report.funnel.attended / report.funnel.registered * 100) : 0 }}% от записавшихся</div></div>
          <div class="stat-tile"><div class="v">{{ report.funnel.stayed }}</div><div class="l">Досмотрели</div><div class="d">дольше {{ w.certMinPercent }}% времени</div></div>
          <div class="stat-tile"><div class="v">{{ fmtDuration(report.funnel.avgSeconds) }}</div><div class="l">В среднем в эфире</div><div class="d">пик: {{ report.funnel.peak }} человек</div></div>
        </div>
        <div v-if="report.presence.length" class="panel mb-16">
          <h3 class="mb-8">Присутствие по минутам</h3>
          <div class="presence">
            <div v-for="p in report.presence" :key="p.minute" class="pr-bar" :style="{ height: Math.round(p.people / maxPresence * 100) + '%' }" :title="`${p.minute} мин: ${p.people} чел.`"></div>
          </div>
          <div class="row tiny muted" style="justify-content:space-between"><span>начало</span><span>{{ report.presence.length }} мин</span></div>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Участник</th><th>Состояние</th><th>Был в эфире</th><th>Сертификат</th></tr></thead><tbody>
          <tr v-for="p in report.participants" :key="p.id">
            <td>{{ p.name }}<div class="tiny muted">{{ p.email }}</div></td>
            <td class="small">{{ STATUS_LABEL[p.status] }}</td>
            <td class="small">{{ p.seconds ? fmtDuration(p.seconds) : '—' }}</td>
            <td><router-link v-if="p.certId" :to="`/cert/${p.certId}`" class="badge success">выдан</router-link><span v-else class="tiny muted">—</span></td>
          </tr>
        </tbody></table></div>
      </template>
    </div>

    <Modal v-if="inviteOpen" title="Пригласить на вебинар" @close="inviteOpen = false">
      <div class="field"><label>Адреса через запятую или с новой строки</label><textarea class="textarea" rows="5" v-model="invite.emails" placeholder="ivanov@company.ru, petrov@partner.ru"></textarea></div>
      <div class="field"><label>Сопроводительный текст</label><input class="input" v-model="invite.note" maxlength="300" /></div>
      <template #footer><button class="btn ghost" @click="inviteOpen = false">Отмена</button><button class="btn primary" :disabled="!invite.emails.trim()" @click="sendInvites">Отправить приглашения</button></template>
    </Modal>
  </div>
</template>

<style scoped>
.cohost-add { position: relative; max-width: 420px; }
.cohost-found { position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% + 4px); background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-sm); box-shadow: var(--shadow-2, 0 8px 24px rgba(0,0,0,.12)); padding: 4px; }
.cohost-opt { display: block; width: 100%; text-align: left; padding: 8px 10px; border-radius: 6px; background: none; border: 0; cursor: pointer; color: var(--text); }
.cohost-opt:hover, .cohost-opt:focus-visible { background: var(--hover, rgba(0,0,0,.05)); }
.presence { display: flex; align-items: flex-end; gap: 2px; height: 120px; padding: 4px; background: var(--surface-2); border-radius: var(--radius-sm); }
.pr-bar { flex: 1 1 0; min-width: 2px; background: var(--brand); border-radius: 2px 2px 0 0; min-height: 2px; }
</style>
