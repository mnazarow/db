<script setup>
// Диалог «Назначить к просмотру»: адресаты (сотрудники, группы, все), срок, требования.
import { ref, watch, onMounted, computed } from 'vue';
import { get, post } from '../api.js';
import { useUi } from '../stores/ui.js';
import { useAuth } from '../stores/auth.js';
import Modal from './Modal.vue';
import ChannelAvatar from './ChannelAvatar.vue';
import { toLocalInput } from '../utils/format.js';

const props = defineProps({ videoId: { type: String, default: '' }, playlistId: { type: String, default: '' }, course: { type: Object, default: null }, title: { type: String, default: '' }, hasQuiz: Boolean });
const emit = defineEmits(['close', 'created']);
const ui = useUi();
const auth = useAuth();
const groups = ref([]);
const targets = ref([]); // {type:'user'|'group'|'all', id, name}
const query = ref('');
const results = ref([]);
const form = ref({ dueAt: toLocalInput(new Date(Date.now() + 7 * 86400000)), note: '', requiredPercent: auth.config?.assignmentsDefaultPercent || 90, requireQuiz: false, remindDays: 3, attentionCheckMin: 0, certificate: false });
const saving = ref(false);
let t = null;

onMounted(async () => { try { groups.value = (await get('/api/groups')).groups; } catch { groups.value = []; } });
watch(query, (q) => {
  clearTimeout(t);
  if (q.trim().length < 2) { results.value = []; return; }
  t = setTimeout(async () => {
    const [u, g] = await Promise.all([get(`/api/users/lookup?q=${encodeURIComponent(q.trim())}`).catch(() => ({ users: [] })), get(`/api/groups?q=${encodeURIComponent(q.trim())}`).catch(() => ({ groups: [] }))]);
    results.value = [
      ...g.groups.map((x) => ({ type: 'group', id: x.id, name: x.name, sub: `${x.memberCount} чел.` })),
      ...u.users.map((x) => ({ type: 'user', id: x.id, name: x.displayName, sub: x.email, user: x })),
    ].filter((r) => !targets.value.some((x) => x.type === r.type && x.id === r.id)).slice(0, 12);
  }, 250);
});
function add(r) { targets.value.push(r); query.value = ''; results.value = []; }
function addAll() { if (!targets.value.some((x) => x.type === 'all')) targets.value.push({ type: 'all', name: 'Все сотрудники' }); }
const people = computed(() => targets.value.some((x) => x.type === 'all') ? 'всем сотрудникам' : `${targets.value.length} адресат${targets.value.length === 1 ? 'у' : 'ам'}`);
async function submit() {
  if (!targets.value.length) return ui.toast('Добавьте хотя бы одного адресата', { type: 'error' });
  saving.value = true;
  try {
    const r = await post('/api/assignments', {
      videoId: props.videoId || undefined, playlistId: props.playlistId || undefined, courseId: props.course?.id || undefined,
      targets: targets.value.map((x) => ({ type: x.type, id: x.id })),
      dueAt: form.value.dueAt ? new Date(form.value.dueAt).toISOString() : null, note: form.value.note, requiredPercent: form.value.requiredPercent, requireQuiz: form.value.requireQuiz, remindDays: form.value.remindDays, attentionCheckMin: form.value.attentionCheckMin, certificate: form.value.certificate,
    });
    ui.toast(`Назначено ${people.value}${r.assignment.notified ? `, уведомлено: ${r.assignment.notified}` : ''}`, { type: 'success', timeout: 6000 });
    emit('created', r.assignment); emit('close');
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
</script>

<template>
  <Modal title="Назначить к обязательному просмотру" @close="$emit('close')">
    <div class="col gap-12">
      <div class="small muted" v-if="title || course"><Icon :name="course ? 'school' : 'assignment'" :size="16" style="vertical-align:-3px" /> {{ course ? `Курс: ${course.title}` : title }}</div>
      <div class="field">
        <label>Кому</label>
        <div class="row wrap gap-8 mb-8" v-if="targets.length">
          <span v-for="(x, i) in targets" :key="x.type + x.id" class="chip" :class="{ brand: x.type !== 'user' }"><Icon :name="x.type === 'all' ? 'accounts' : x.type === 'group' ? 'groups' : 'person'" :size="14" /> {{ x.name }}<button class="ibtn sm" style="width:20px;height:20px" @click="targets.splice(i, 1)" :aria-label="`Убрать: ${x.name}`"><Icon name="close" :size="14" /></button></span>
        </div>
        <div style="position: relative"><input class="input" v-model="query" placeholder="Сотрудник (имя, e-mail) или группа" />
          <div v-if="results.length" class="menu" style="left:0;right:0;top:100%; z-index: 5"><button v-for="r in results" :key="r.type + r.id" class="item" @click="add(r)"><ChannelAvatar v-if="r.user" :user="r.user" size="xs" /><Icon v-else name="groups" :size="18" /> {{ r.name }} <span class="tiny muted">{{ r.sub }}</span></button></div>
        </div>
        <div class="row wrap gap-4 mt-8">
          <button v-for="g in groups.slice(0, 6)" :key="g.id" class="chip" :disabled="targets.some((x) => x.type === 'group' && x.id === g.id)" @click="add({ type: 'group', id: g.id, name: g.name })"><Icon name="groups" :size="14" /> {{ g.name }}</button>
          <button v-if="auth.isStaff" class="chip" @click="addAll"><Icon name="accounts" :size="14" /> Все сотрудники</button>
        </div>
      </div>
      <div class="form-grid">
        <div class="field"><label>Срок</label><input class="input" type="datetime-local" v-model="form.dueAt" /><div class="hint">Пусто — без срока</div></div>
        <div class="field"><label>Засчитывать при просмотре</label><select class="select" v-model.number="form.requiredPercent"><option v-for="p in [50, 70, 80, 90, 95, 100]" :key="p" :value="p">{{ p }}% видео</option></select></div>
        <div class="field"><label>Напомнить за</label><select class="select" v-model.number="form.remindDays"><option :value="0">не напоминать</option><option v-for="d in [1, 2, 3, 5, 7, 14]" :key="d" :value="d">{{ d }} дн. до срока</option></select></div>
        <div class="field" style="justify-content:flex-end"><label class="switch"><input type="checkbox" v-model="form.requireQuiz" :disabled="!hasQuiz && !!videoId" /><span class="track"></span><span>Требуется сдать тест</span></label><div v-if="videoId && !hasQuiz" class="hint">У видео нет теста — добавьте его в студии</div></div>
        <div class="field"><label>Контроль присутствия</label><select class="select" v-model.number="form.attentionCheckMin"><option :value="0">выключен</option><option v-for="m in [3, 5, 10, 15]" :key="m" :value="m">каждые {{ m }} мин — «Вы смотрите?»</option></select><div class="hint">Видео ставится на паузу, пока сотрудник не подтвердит присутствие</div></div>
        <div class="field" style="justify-content:flex-end"><label class="switch"><input type="checkbox" v-model="form.certificate" :disabled="auth.config?.certificatesEnabled === false" /><span class="track"></span><span>Выдать сертификат о прохождении</span></label><div class="hint">Именной сертификат с номером и ссылкой для проверки</div></div>
        <div class="field" style="grid-column: 1 / -1"><label>Сообщение адресатам</label><textarea class="textarea" v-model="form.note" rows="2" placeholder="Например: обязательный инструктаж для новых сотрудников"></textarea></div>
      </div>
      <p class="small muted" style="margin:0">Адресаты получат уведомление на портале и по почте (если почта настроена). Видео с ограниченным доступом станет доступно адресатам на время назначения.</p>
    </div>
    <template #footer><button class="btn ghost" @click="$emit('close')">Отмена</button><button class="btn primary" :disabled="saving || !targets.length" @click="submit"><Icon name="assignment" :size="18" /> {{ saving ? 'Назначение…' : 'Назначить' }}</button></template>
  </Modal>
</template>
