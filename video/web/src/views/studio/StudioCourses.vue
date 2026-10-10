<script setup>
// Студия: список учебных программ автора с созданием нового курса.
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { get, post, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDateTime, plural } from '../../utils/format.js';

const router = useRouter();
const ui = useUi();
const courses = ref([]);
const loading = ref(true);
const showForm = ref(false);
const saving = ref(false);
const form = ref({ title: '', description: '', visibility: 'internal', sequential: true, requiredPercent: 90, issueCertificate: true });

async function load() {
  loading.value = true;
  try { courses.value = (await get('/api/courses?manage=1')).courses; }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);

async function create() {
  if (!form.value.title.trim()) return ui.toast('Укажите название курса', { type: 'error' });
  saving.value = true;
  try {
    const r = await post('/api/courses', form.value);
    router.push(`/studio/courses/${r.course.id}`);
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
async function remove(c) {
  if (!(await ui.ask({ title: `Удалить курс «${c.title}»?`, message: 'Прогресс сотрудников по этому курсу будет удалён.', okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/courses/${c.id}`); courses.value = courses.value.filter((x) => x.id !== c.id); ui.toast('Курс удалён'); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const STATUS = { draft: ['Черновик', 'warning'], published: ['Опубликован', 'success'], archived: ['В архиве', ''] };
</script>

<template>
  <div>
    <div class="page-head">
      <div><h1>Курсы</h1><div class="sub">Учебные программы: видео, материалы и проверка знаний по шагам</div></div>
      <button class="btn primary" @click="showForm = !showForm"><Icon name="plus" :size="16" /> Новый курс</button>
    </div>

    <div v-if="showForm" class="panel mb-16">
      <div class="form-grid">
        <div class="field" style="grid-column: 1 / -1"><label>Название</label><input v-model="form.title" class="input" placeholder="Например: Охрана труда для новых сотрудников" /></div>
        <div class="field" style="grid-column: 1 / -1"><label>Описание</label><textarea v-model="form.description" class="input" rows="3" placeholder="Кому предназначен курс и что даёт"></textarea></div>
        <div class="field"><label>Доступ</label>
          <select v-model="form.visibility" class="input">
            <option value="internal">Сотрудникам портала</option>
            <option value="public">Всем</option>
            <option value="private">Только по назначению</option>
          </select>
        </div>
        <div class="field"><label>Порог просмотра видео</label><input v-model.number="form.requiredPercent" class="input" type="number" min="10" max="100" /></div>
        <label class="check"><input v-model="form.sequential" type="checkbox" /> Последовательное прохождение</label>
        <label class="check"><input v-model="form.issueCertificate" type="checkbox" /> Выдавать сертификат</label>
      </div>
      <div class="form-actions"><button class="btn primary" :disabled="saving" @click="create">{{ saving ? 'Создание…' : 'Создать' }}</button><button class="btn ghost" @click="showForm = false">Отмена</button></div>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!courses.length" icon="school" title="Курсов пока нет" text="Соберите курс из уже загруженных видео, материалов и текстовых пояснений." />
    <div v-else class="table-wrap"><table class="table"><thead><tr><th>Курс</th><th>Шагов</th><th>Участники</th><th>Состояние</th><th></th></tr></thead><tbody>
      <tr v-for="c in courses" :key="c.id">
        <td><router-link :to="`/studio/courses/${c.id}`"><b>{{ c.title }}</b></router-link><div class="tiny muted">{{ fmtDateTime(c.updatedAt) }}</div></td>
        <td class="small">{{ c.itemCount }} {{ plural(c.itemCount, 'шаг', 'шага', 'шагов') }}</td>
        <td class="small">{{ c.enrolledCount || 0 }}<span v-if="c.completedCount" class="tiny muted"> · прошли {{ c.completedCount }}</span></td>
        <td><span class="badge" :class="STATUS[c.status]?.[1]">{{ STATUS[c.status]?.[0] || c.status }}</span></td>
        <td class="actions nowrap">
          <router-link class="ibtn sm" :to="`/course/${c.slug || c.id}`" title="Открыть страницу курса" aria-label="Открыть страницу курса"><Icon name="eye" :size="16" /></router-link>
          <router-link class="ibtn sm" :to="`/studio/courses/${c.id}`" title="Редактировать" aria-label="Редактировать"><Icon name="edit" :size="16" /></router-link>
          <button class="ibtn sm" title="Удалить" @click="remove(c)" aria-label="Удалить"><Icon name="delete" :size="16" /></button>
        </td>
      </tr>
    </tbody></table></div>
  </div>
</template>
