<script setup>
// Назначения к обязательному просмотру: список, создание, отчёт «кто посмотрел», CSV, напоминания.
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post, patch, del } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import AssignDialog from '../../components/AssignDialog.vue';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import { fmtDate, fmtDateTime, toLocalInput } from '../../utils/format.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const list = ref([]);
const loading = ref(true);
const status = ref('active');
const mine = ref(!auth.isStaff);
const picker = ref(null); // { videos, playlists, q, tab, choice }
const dialog = ref(null); // { videoId, playlistId, title, hasQuiz }
const current = ref(null); // { assignment, report }
const editing = ref(null);
const peopleFilter = ref('all');
const peopleQuery = ref('');

async function load() {
  loading.value = true;
  try { list.value = (await get(`/api/assignments?status=${status.value}&mine=${mine.value ? 1 : 0}&limit=100`)).assignments; } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
async function openReport(id) {
  try { current.value = await get(`/api/assignments/${id}`); } catch (e) { ui.toast(e.message, { type: 'error' }); router.replace('/studio/assignments'); }
}
watch([status, mine], load);
watch(() => route.params.id, (id) => { if (id) openReport(id); else current.value = null; }, { immediate: true });
onMounted(load);

// Выбор материала для нового назначения
async function startNew() {
  const [v, p] = await Promise.all([get('/api/studio/videos?limit=100&status=ready').catch(() => ({ videos: [] })), get('/api/playlists').catch(() => ({ playlists: [] }))]);
  picker.value = { videos: v.videos || [], playlists: (p.playlists || []).filter((x) => x.kind !== 'watch_later'), q: '', tab: 'video' };
}
const pickerVideos = computed(() => picker.value ? picker.value.videos.filter((v) => v.status === 'ready' && (!picker.value.q || v.title.toLowerCase().includes(picker.value.q.toLowerCase()))) : []);
function pick(kind, item) { dialog.value = kind === 'video' ? { videoId: item.id, title: item.title, hasQuiz: !!item.hasQuiz } : { playlistId: item.id, title: item.title, hasQuiz: true }; picker.value = null; }
function onCreated(a) { load(); router.push(`/studio/assignments/${a.id}`); }

const people = computed(() => {
  if (!current.value) return [];
  const q = peopleQuery.value.trim().toLowerCase();
  return current.value.report.people.filter((p) => (peopleFilter.value === 'all' || (peopleFilter.value === 'done' ? p.completed : !p.completed)) && (!q || p.displayName.toLowerCase().includes(q) || p.email.toLowerCase().includes(q)));
});
async function remind() {
  try { const r = await post(`/api/assignments/${current.value.assignment.id}/remind`, {}); ui.toast(`Напоминание отправлено: ${r.sent}`, { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function close(a, st) { await patch(`/api/assignments/${a.id}`, { status: st }); ui.toast(st === 'closed' ? 'Назначение закрыто' : 'Назначение снова активно'); load(); if (current.value) openReport(a.id); }
async function remove(a) {
  if (!(await ui.ask({ title: 'Удалить назначение?', message: 'Прогресс сотрудников по нему будет удалён.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/assignments/${a.id}`); if (current.value?.assignment.id === a.id) router.replace('/studio/assignments'); load();
}
function startEdit(a) { editing.value = { id: a.id, note: a.note, dueAt: toLocalInput(a.dueAt), requiredPercent: a.requiredPercent, requireQuiz: a.requireQuiz, attentionCheckMin: a.attentionCheckMin || 0, certificate: !!a.certificate }; }
async function saveEdit() {
  try { await patch(`/api/assignments/${editing.value.id}`, { note: editing.value.note, dueAt: editing.value.dueAt ? new Date(editing.value.dueAt).toISOString() : null, requiredPercent: editing.value.requiredPercent, requireQuiz: editing.value.requireQuiz, attentionCheckMin: editing.value.attentionCheckMin, certificate: editing.value.certificate }); editing.value = null; load(); if (current.value) openReport(current.value.assignment.id); ui.toast('Сохранено', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function targetsLabel(a) { return (a.targets || []).map((t) => t.name).filter(Boolean).join(', ') || '—'; }
function pct(a) { return a.total ? Math.round((a.completed / a.total) * 100) : 0; }
</script>

<template>
  <div>
    <!-- Отчёт по назначению -->
    <div v-if="current">
      <div class="row mb-16"><router-link to="/studio/assignments" class="btn ghost sm"><Icon name="back" :size="18" /> Все назначения</router-link></div>
      <div class="page-head"><div><h1 class="clamp-2">{{ current.assignment.title }}</h1><div class="sub">{{ current.assignment.kind === 'playlist' ? 'Плейлист' : 'Видео' }} · назначил {{ current.assignment.creatorName }} {{ fmtDate(current.assignment.createdAt) }} · кому: {{ targetsLabel(current.assignment) }}</div></div>
        <div class="row wrap gap-8">
          <a class="btn sm" :href="$url(`/api/assignments/${current.assignment.id}?format=csv`)"><Icon name="csv" :size="16" /> Экспорт CSV</a>
          <a v-if="current.assignment.certificate" class="btn sm" :href="$url(`/api/assignments/${current.assignment.id}/certificates?format=csv`)"><Icon name="verified" :size="16" /> Сертификаты CSV</a>
          <button class="btn sm" @click="remind" :disabled="current.assignment.status !== 'active'"><Icon name="bellRing" :size="16" /> Напомнить не выполнившим</button>
          <button class="btn ghost sm" @click="startEdit(current.assignment)"><Icon name="edit" :size="16" /> Изменить</button>
          <button v-if="current.assignment.status === 'active'" class="btn ghost sm" @click="close(current.assignment, 'closed')">Закрыть</button><button v-else class="btn ghost sm" @click="close(current.assignment, 'active')">Возобновить</button>
          <button class="btn ghost sm danger" @click="remove(current.assignment)" aria-label="Удалить назначение"><Icon name="delete" :size="16" /></button>
        </div>
      </div>
      <div class="stat-tiles mb-24">
        <div class="stat-tile"><div class="v">{{ current.report.total }}</div><div class="l">Адресатов</div></div>
        <div class="stat-tile"><div class="v" style="color: var(--success)">{{ current.report.completed }}</div><div class="l">Выполнили</div><div class="d">{{ current.report.total ? Math.round((current.report.completed / current.report.total) * 100) : 0 }}%</div></div>
        <div class="stat-tile"><div class="v" :style="current.report.overdue ? 'color: var(--danger)' : ''">{{ current.report.overdue }}</div><div class="l">Просрочили</div></div>
        <div class="stat-tile"><div class="v" style="font-size: 22px">{{ current.assignment.dueAt ? fmtDate(current.assignment.dueAt) : '—' }}</div><div class="l">Срок</div><div class="d">засчитывается от {{ current.assignment.requiredPercent }}%{{ current.assignment.requireQuiz ? ' + тест' : '' }}{{ current.assignment.certificate ? ' · сертификат' : '' }}{{ current.assignment.attentionCheckMin ? ` · контроль присутствия ${current.assignment.attentionCheckMin} мин` : '' }}</div></div>
      </div>
      <div v-if="current.assignment.note" class="alert info mb-16"><Icon name="info" :size="20" /> {{ current.assignment.note }}</div>
      <div class="panel">
        <div class="row wrap mb-12"><h3 class="grow">Кто посмотрел</h3><div class="chips"><button v-for="[k, l] in [['all', 'Все'], ['pending', 'Не выполнили'], ['done', 'Выполнили']]" :key="k" class="chip" :class="{ active: peopleFilter === k }" @click="peopleFilter = k">{{ l }}</button></div><input class="input sm" v-model="peopleQuery" placeholder="Поиск" style="max-width: 220px" /></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Сотрудник</th><th style="width: 30%">Просмотрено</th><th>Видео</th><th v-if="current.assignment.requireQuiz || current.report.people.some((p) => p.quizPassed !== null)">Тест</th><th>Последняя активность</th><th>Статус</th></tr></thead><tbody>
          <tr v-for="p in people" :key="p.id"><td><div>{{ p.displayName }}</div><div class="tiny muted">{{ p.email }}</div></td><td><div class="row gap-8" style="align-items:center"><div class="meter grow"><i :class="p.completed ? 'ok' : ''" :style="{ width: p.percent + '%' }"></i></div><span class="small" style="width: 40px">{{ p.percent }}%</span></div></td><td class="small">{{ p.completedVideos }} из {{ p.totalVideos }}</td><td v-if="current.assignment.requireQuiz || current.report.people.some((x) => x.quizPassed !== null)"><span v-if="p.quizPassed === true" class="badge success">сдан</span><span v-else-if="p.quizPassed === false" class="badge danger">не сдан</span><span v-else class="muted small">—</span></td><td class="small muted">{{ p.lastAt ? fmtDateTime(p.lastAt) : 'не открывал(а)' }}</td><td><span class="badge" :class="p.completed ? 'success' : (current.assignment.dueAt && new Date(current.assignment.dueAt) < new Date()) ? 'danger' : 'warning'">{{ p.completed ? 'Выполнено' : (current.assignment.dueAt && new Date(current.assignment.dueAt) < new Date()) ? 'Просрочено' : 'В процессе' }}</span></td></tr>
          <tr v-if="!people.length"><td colspan="6" class="muted">Никого не найдено</td></tr>
        </tbody></table></div>
      </div>
    </div>

    <!-- Список назначений -->
    <div v-else>
      <div class="page-head"><div><h1>Обязательные просмотры</h1><div class="sub">Назначайте видео и плейлисты сотрудникам, группам или всем — и следите, кто посмотрел</div></div><button class="btn primary" @click="startNew"><Icon name="plus" :size="18" /> Назначить</button></div>
      <div class="row wrap mb-16"><div class="chips"><button class="chip" :class="{ active: status === 'active' }" @click="status = 'active'">Активные</button><button class="chip" :class="{ active: status === 'closed' }" @click="status = 'closed'">Закрытые</button></div><label v-if="auth.isStaff" class="check" style="margin-left:auto"><input type="checkbox" v-model="mine" /> Только мои</label></div>
      <div v-if="loading" class="loading-block"><div class="spin"></div></div>
      <EmptyState v-else-if="!list.length" icon="assignment" title="Назначений пока нет" text="Нажмите «Назначить», выберите видео или плейлист и укажите, кому его нужно посмотреть." />
      <div v-else class="table-wrap panel" style="padding:0"><table class="table"><thead><tr><th>Материал</th><th>Кому</th><th>Срок</th><th style="width: 24%">Выполнение</th><th>Создано</th><th></th></tr></thead><tbody>
        <tr v-for="a in list" :key="a.id">
          <td><router-link :to="`/studio/assignments/${a.id}`" class="row gap-8" style="color: var(--text)"><img v-if="a.thumbnailUrl" :src="a.thumbnailUrl" alt="" style="width: 72px; aspect-ratio: 16/9; object-fit: cover; border-radius: 6px" /><span><span class="clamp-2" style="font-weight: 500">{{ a.title }}</span><span class="tiny muted">{{ a.kind === 'playlist' ? 'Плейлист' : 'Видео' }}{{ a.requireQuiz ? ' · с тестом' : '' }}</span></span></router-link></td>
          <td class="small">{{ targetsLabel(a) }}</td>
          <td class="small"><span v-if="a.dueAt" class="due" :class="{ overdue: new Date(a.dueAt) < new Date() && a.completed < a.total }">{{ fmtDate(a.dueAt) }}</span><span v-else class="muted">—</span></td>
          <td><div class="row gap-8" style="align-items:center"><div class="meter grow"><i :class="pct(a) === 100 ? 'ok' : a.overdue ? 'bad' : ''" :style="{ width: pct(a) + '%' }"></i></div><span class="small nowrap">{{ a.completed }} / {{ a.total }}</span></div></td>
          <td class="small muted">{{ fmtDate(a.createdAt) }}<div class="tiny">{{ a.creatorName }}</div></td>
          <td class="actions"><router-link :to="`/studio/assignments/${a.id}`" class="btn ghost sm">Отчёт</router-link><button class="ibtn sm" title="Удалить" @click="remove(a)" aria-label="Удалить"><Icon name="delete" :size="16" /></button></td>
        </tr>
      </tbody></table></div>
    </div>

    <!-- Выбор материала -->
    <Modal v-if="picker" title="Что назначить к просмотру" @close="picker = null">
      <div class="tabs mb-12"><button class="tab" :class="{ active: picker.tab === 'video' }" @click="picker.tab = 'video'">Видео</button><button class="tab" :class="{ active: picker.tab === 'playlist' }" @click="picker.tab = 'playlist'">Плейлисты</button></div>
      <input v-if="picker.tab === 'video'" class="input mb-8" v-model="picker.q" placeholder="Поиск по названию" />
      <div class="pick-list">
        <template v-if="picker.tab === 'video'">
          <button v-for="v in pickerVideos" :key="v.id" class="pick-item" @click="pick('video', v)"><img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" /><span class="grow"><span class="clamp-2">{{ v.title }}</span><span class="tiny muted">{{ v.hasQuiz ? 'с тестом · ' : '' }}{{ v.viewCount }} просмотров</span></span></button>
          <div v-if="!pickerVideos.length" class="muted small">Нет готовых видео. Загрузите видео в студии.</div>
        </template>
        <template v-else>
          <button v-for="p in picker.playlists" :key="p.id" class="pick-item" @click="pick('playlist', p)"><Icon name="playlist" :size="24" /><span class="grow"><span>{{ p.title }}</span><span class="tiny muted">{{ p.videoCount }} видео</span></span></button>
          <div v-if="!picker.playlists.length" class="muted small">Плейлистов нет.</div>
        </template>
      </div>
    </Modal>
    <AssignDialog v-if="dialog" v-bind="dialog" @close="dialog = null" @created="onCreated" />
    <Modal v-if="editing" title="Изменить назначение" @close="editing = null">
      <div class="form-grid">
        <div class="field"><label>Срок</label><input class="input" type="datetime-local" v-model="editing.dueAt" /></div>
        <div class="field"><label>Засчитывать при просмотре</label><select class="select" v-model.number="editing.requiredPercent"><option v-for="p in [50, 70, 80, 90, 95, 100]" :key="p" :value="p">{{ p }}%</option></select></div>
        <div class="field"><label>Контроль присутствия</label><select class="select" v-model.number="editing.attentionCheckMin"><option :value="0">выключен</option><option v-for="m in [3, 5, 10, 15]" :key="m" :value="m">каждые {{ m }} мин</option></select></div>
        <div class="field" style="grid-column: 1 / -1; flex-direction: row; gap: 24px; flex-wrap: wrap"><label class="switch"><input type="checkbox" v-model="editing.requireQuiz" /><span class="track"></span><span>Требуется сдать тест</span></label><label class="switch"><input type="checkbox" v-model="editing.certificate" /><span class="track"></span><span>Сертификат о прохождении</span></label></div>
        <div class="field" style="grid-column: 1 / -1"><label>Сообщение</label><textarea class="textarea" v-model="editing.note" rows="2"></textarea></div>
      </div>
      <template #footer><button class="btn ghost" @click="editing = null">Отмена</button><button class="btn primary" @click="saveEdit">Сохранить</button></template>
    </Modal>
  </div>
</template>

<style>
.pick-list { max-height: 50vh; overflow: auto; display: flex; flex-direction: column; gap: 4px; }
.pick-item { display: flex; gap: 12px; align-items: center; width: 100%; padding: 8px; border-radius: var(--radius-sm); text-align: left; font: inherit; color: var(--text); background: none; border: 0; cursor: pointer; }
.pick-item:hover { background: var(--bg-2); }
.pick-item img { width: 96px; aspect-ratio: 16/9; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
</style>
