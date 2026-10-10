<script setup>
// Студия: редактор учебной программы — шаги, порядок, публикация и отчёт о прохождении.
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import AssignDialog from '../../components/AssignDialog.vue';
import { fmtDuration, fmtDateTime, plural } from '../../utils/format.js';
import { url } from '../../base.js';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const course = ref(null);
const items = ref([]);
const loading = ref(true);
const saving = ref(false);
const report = ref(null);
const tab = ref('items');
const assignOpen = ref(false);

const picker = ref({ open: false, q: '', list: [], loading: false, section: '', requireQuiz: false });
const textForm = ref({ title: '', body: '', section: '', required: true });
const materialForm = ref({ title: '', url: '', section: '', required: true });

async function load() {
  loading.value = true;
  try {
    const r = await get(`/api/courses/${route.params.id}`);
    course.value = r.course; items.value = r.items;
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);
watch(() => route.params.id, load);
watch(tab, async (t) => { if (t === 'report' && course.value) await loadReport(); });

async function loadReport() {
  try { report.value = (await get(`/api/courses/${course.value.id}/report`)).report; }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function save(patchBody) {
  saving.value = true;
  try { const r = await patch(`/api/courses/${course.value.id}`, patchBody); course.value = { ...course.value, ...r.course }; ui.toast('Сохранено', { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
async function publish() { await save({ status: course.value.status === 'published' ? 'draft' : 'published' }); await load(); }

async function searchVideos() {
  picker.value.loading = true;
  try { picker.value.list = (await get(`/api/studio/videos?limit=20${picker.value.q ? `&q=${encodeURIComponent(picker.value.q)}` : ''}`)).videos; }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { picker.value.loading = false; }
}
watch(() => picker.value.open, (v) => { if (v && !picker.value.list.length) searchVideos(); });

async function addItem(body) {
  try {
    await post(`/api/courses/${course.value.id}/items`, body);
    await load();
    ui.toast('Шаг добавлен', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function addVideo(v) {
  await addItem({ kind: 'video', videoId: v.id, section: picker.value.section, requireQuiz: picker.value.requireQuiz && v.hasQuiz });
  picker.value.open = false;
}
async function addText() {
  if (!textForm.value.title.trim()) return ui.toast('Укажите заголовок шага', { type: 'error' });
  await addItem({ kind: 'text', ...textForm.value });
  textForm.value = { title: '', body: '', section: textForm.value.section, required: true };
}
async function addMaterial() {
  if (!materialForm.value.title.trim() || !materialForm.value.url.trim()) return ui.toast('Укажите название и ссылку', { type: 'error' });
  await addItem({ kind: 'material', ...materialForm.value });
  materialForm.value = { title: '', url: '', section: materialForm.value.section, required: true };
}
async function removeItem(it) {
  if (!(await ui.ask({ title: 'Удалить шаг?', okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/courses/${course.value.id}/items/${it.id}`); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function move(it, dir) {
  const ids = items.value.map((x) => x.id);
  const i = ids.indexOf(it.id);
  const j = i + dir;
  if (i < 0 || j < 0 || j >= ids.length) return;
  ids.splice(j, 0, ids.splice(i, 1)[0]);
  try { await post(`/api/courses/${course.value.id}/items/reorder`, { ids }); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function toggleItem(it, field) {
  try { await patch(`/api/courses/${course.value.id}/items/${it.id}`, { [field]: !it[field] }); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function setSection(it, section) {
  try { await patch(`/api/courses/${course.value.id}/items/${it.id}`, { section }); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function csvUrl() { return url(`/api/courses/${course.value.id}/report?format=csv`); }
const totalDuration = computed(() => items.value.reduce((n, i) => n + (i.video?.duration || 0), 0));
const ICONS = { video: 'play', material: 'inventory', text: 'doc' };
</script>

<template>
  <div v-if="loading" class="loading-block"><div class="spin"></div></div>
  <div v-else-if="course">
    <div class="page-head">
      <div>
        <div class="row gap-8" style="align-items:center">
          <h1 style="margin:0">{{ course.title }}</h1>
          <span class="badge" :class="course.status === 'published' ? 'success' : 'warning'">{{ course.status === 'published' ? 'Опубликован' : 'Черновик' }}</span>
        </div>
        <div class="sub">{{ items.length }} {{ plural(items.length, 'шаг', 'шага', 'шагов') }} · {{ fmtDuration(totalDuration) }} видео</div>
      </div>
      <div class="row gap-8">
        <router-link class="btn ghost" :to="`/course/${course.slug || course.id}`"><Icon name="eye" :size="16" /> Открыть</router-link>
        <button v-if="course.status === 'published'" class="btn" @click="assignOpen = true"><Icon name="assignment" :size="16" /> Назначить</button>
        <button class="btn primary" :disabled="saving" @click="publish">{{ course.status === 'published' ? 'Снять с публикации' : 'Опубликовать' }}</button>
      </div>
    </div>

    <div class="studio-tabs">
      <button class="tab" :class="{ active: tab === 'items' }" @click="tab = 'items'">Шаги</button>
      <button class="tab" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">Настройки</button>
      <button class="tab" :class="{ active: tab === 'report' }" @click="tab = 'report'">Прохождение</button>
    </div>

    <!-- Шаги -->
    <div v-if="tab === 'items'">
      <div class="panel">
        <EmptyState v-if="!items.length" icon="listStatus" title="Шагов пока нет" text="Добавьте видео, материалы и пояснения — они станут шагами курса." />
        <ol v-else class="steps">
          <li v-for="(it, i) in items" :key="it.id" class="step">
            <div class="step-icon"><Icon :name="ICONS[it.kind]" :size="16" /></div>
            <div class="grow" style="min-width:0">
              <div class="row gap-8 wrap" style="align-items:baseline">
                <b class="ellipsis">{{ it.title }}</b>
                <span v-if="it.kind === 'video' && it.video" class="tiny muted">{{ fmtDuration(it.video.duration) }}</span>
                <span v-if="!it.required" class="tiny muted">необязательный</span>
                <span v-if="it.requireQuiz" class="tiny muted">нужен зачёт по тесту</span>
              </div>
              <div class="row gap-8 mt-4 wrap">
                <input class="input sm" style="max-width:200px" :value="it.section" placeholder="Раздел" @change="setSection(it, $event.target.value)" />
                <label class="check tiny"><input type="checkbox" :checked="it.required" @change="toggleItem(it, 'required')" /> обязательный</label>
                <label v-if="it.kind === 'video' && it.video?.hasQuiz" class="check tiny"><input type="checkbox" :checked="it.requireQuiz" @change="toggleItem(it, 'requireQuiz')" /> нужен тест</label>
              </div>
            </div>
            <div class="actions nowrap">
              <button class="ibtn sm" title="Выше" :disabled="i === 0" @click="move(it, -1)" aria-label="Выше"><Icon name="chevronUp" :size="16" /></button>
              <button class="ibtn sm" title="Ниже" :disabled="i === items.length - 1" @click="move(it, 1)" aria-label="Ниже"><Icon name="chevronDown" :size="16" /></button>
              <button class="ibtn sm" title="Удалить" @click="removeItem(it)" aria-label="Удалить"><Icon name="delete" :size="16" /></button>
            </div>
          </li>
        </ol>
      </div>

      <div class="panel mt-16">
        <h3>Добавить шаг</h3>
        <div class="row gap-8 wrap mb-8">
          <button class="btn sm" @click="picker.open = !picker.open"><Icon name="play" :size="16" /> Видео</button>
        </div>
        <div v-if="picker.open" class="panel soft">
          <div class="row gap-8 mb-8">
            <input v-model="picker.q" class="input sm grow" placeholder="Поиск по своим видео" @keyup.enter="searchVideos" />
            <input v-model="picker.section" class="input sm" style="max-width:180px" placeholder="Раздел" />
            <button class="btn sm" @click="searchVideos">Найти</button>
          </div>
          <div v-if="picker.loading" class="loading-block"><div class="spin"></div></div>
          <div v-else class="col gap-4" style="max-height:320px; overflow:auto">
            <button v-for="v in picker.list" :key="v.id" class="pick-row" @click="addVideo(v)">
              <img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" />
              <span class="grow ellipsis">{{ v.title }}</span>
              <span class="tiny muted">{{ fmtDuration(v.duration) }}</span>
            </button>
            <div v-if="!picker.list.length" class="muted small">Ничего не найдено</div>
          </div>
        </div>

        <div class="form-grid mt-16">
          <div class="field"><label>Текстовый шаг — заголовок</label><input v-model="textForm.title" class="input sm" placeholder="Например: С чего начать" /></div>
          <div class="field"><label>Раздел</label><input v-model="textForm.section" class="input sm" placeholder="Раздел" /></div>
          <div class="field" style="grid-column: 1 / -1"><label>Текст</label><textarea v-model="textForm.body" class="input" rows="3" placeholder="Пояснение для сотрудника"></textarea></div>
        </div>
        <button class="btn sm" @click="addText"><Icon name="plus" :size="16" /> Добавить текст</button>

        <div class="form-grid mt-16">
          <div class="field"><label>Материал — название</label><input v-model="materialForm.title" class="input sm" placeholder="Регламент, инструкция" /></div>
          <div class="field"><label>Ссылка</label><input v-model="materialForm.url" class="input sm" placeholder="https://…" /></div>
          <div class="field"><label>Раздел</label><input v-model="materialForm.section" class="input sm" placeholder="Раздел" /></div>
        </div>
        <button class="btn sm" @click="addMaterial"><Icon name="plus" :size="16" /> Добавить материал</button>
      </div>
    </div>

    <!-- Настройки -->
    <div v-else-if="tab === 'settings'" class="panel">
      <div class="form-grid">
        <div class="field" style="grid-column: 1 / -1"><label>Название</label><input v-model="course.title" class="input" /></div>
        <div class="field" style="grid-column: 1 / -1"><label>Описание</label><textarea v-model="course.description" class="input" rows="4"></textarea></div>
        <div class="field"><label>Доступ</label>
          <select v-model="course.visibility" class="input">
            <option value="internal">Сотрудникам портала</option>
            <option value="public">Всем</option>
            <option value="private">Только по назначению</option>
          </select>
        </div>
        <div class="field"><label>Порог просмотра видео, %</label><input v-model.number="course.requiredPercent" class="input" type="number" min="10" max="100" /></div>
        <label class="check"><input v-model="course.sequential" type="checkbox" /> Последовательное прохождение шагов</label>
        <label class="check"><input v-model="course.issueCertificate" type="checkbox" /> Выдавать сертификат по завершении</label>
      </div>
      <div class="form-actions">
        <button class="btn primary" :disabled="saving" @click="save({ title: course.title, description: course.description, visibility: course.visibility, requiredPercent: course.requiredPercent, sequential: course.sequential, issueCertificate: course.issueCertificate })">Сохранить</button>
      </div>
    </div>

    <!-- Прохождение -->
    <div v-else class="panel">
      <div class="row mb-8" style="align-items:center">
        <h3 class="grow">Прохождение курса</h3>
        <a class="btn ghost sm" :href="csvUrl()"><Icon name="csv" :size="16" /> CSV</a>
      </div>
      <div v-if="!report" class="loading-block"><div class="spin"></div></div>
      <template v-else>
        <div class="tiny muted mb-8">Прошли курс: {{ report.completed }} из {{ report.total }}</div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Сотрудник</th><th>Прогресс</th><th>Шаги</th><th>Завершил</th></tr></thead><tbody>
          <tr v-for="p in report.people" :key="p.id">
            <td><b>{{ p.displayName }}</b><div class="tiny muted">{{ p.email }}</div></td>
            <td style="min-width:140px"><div class="bar"><i :style="{ width: p.percent + '%' }"></i></div><span class="tiny muted">{{ p.percent }}%</span></td>
            <td class="small">{{ p.doneItems }} из {{ p.totalItems }}</td>
            <td class="small">
              <span v-if="p.completed" class="badge success">{{ fmtDateTime(p.completedAt) }}</span>
              <span v-else-if="!p.enrolled" class="tiny muted">не начинал</span>
              <span v-else class="tiny muted">в процессе</span>
            </td>
          </tr>
          <tr v-if="!report.people.length"><td colspan="4" class="muted">Пока никто не записан на курс</td></tr>
        </tbody></table></div>
      </template>
    </div>

    <AssignDialog v-if="assignOpen" :course="course" @close="assignOpen = false" />
  </div>
</template>

<style scoped>
.steps { list-style: none; margin: 0; padding: 0; }
.step { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-top: 1px solid var(--border); }
.step:first-child { border-top: 0; }
.step-icon { width: 28px; height: 28px; flex: none; border-radius: 50%; display: grid; place-items: center; background: var(--surface-2); color: var(--muted); }
.pick-row { display: flex; gap: 8px; align-items: center; width: 100%; padding: 6px; border: 0; background: none; border-radius: 8px; cursor: pointer; text-align: left; color: inherit; }
.pick-row:hover { background: var(--surface-2); }
.pick-row img { width: 72px; aspect-ratio: 16/9; object-fit: cover; border-radius: 6px; }
.bar { height: 6px; border-radius: 3px; background: var(--surface-2); overflow: hidden; }
.bar i { display: block; height: 100%; background: var(--brand); }
</style>
