<script setup>
// Администрирование: сводка по обучению — курсы, назначения и сотрудники в разрезе групп.
import { ref, onMounted, watch } from 'vue';
import { get } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDate } from '../../utils/format.js';
import { url } from '../../base.js';

const ui = useUi();
const data = ref(null);
const loading = ref(true);
const groupId = ref('');
const tab = ref('people');

async function load() {
  loading.value = true;
  try { data.value = await get(`/api/learning/overview${groupId.value ? `?groupId=${groupId.value}` : ''}`); }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);
watch(groupId, load);
function csvUrl() { return url(`/api/learning/overview?format=csv${groupId.value ? `&groupId=${groupId.value}` : ''}`); }
const KIND = { video: 'видео', playlist: 'плейлист', course: 'курс' };
</script>

<template>
  <div>
    <div class="page-head">
      <div><h1>Обучение</h1><div class="sub">Курсы и обязательные просмотры: кто прошёл, кто задерживается</div></div>
      <div class="row gap-8">
        <select v-model="groupId" class="input sm" style="max-width:220px" aria-label="Группа">
          <option value="">Все сотрудники</option>
          <option v-for="g in data?.groups || []" :key="g.id" :value="g.id">{{ g.name }} ({{ g.memberCount }})</option>
        </select>
        <a class="btn ghost sm" :href="csvUrl()"><Icon name="csv" :size="16" /> CSV</a>
      </div>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <template v-else-if="data">
      <div class="stat-row mb-16">
        <div class="stat"><div class="stat-value">{{ data.totals.people }}</div><div class="stat-label">сотрудников с заданиями</div></div>
        <div class="stat" :class="{ danger: data.totals.overdue }"><div class="stat-value">{{ data.totals.overdue }}</div><div class="stat-label">с просрочкой</div></div>
        <div class="stat"><div class="stat-value">{{ data.totals.courses }}</div><div class="stat-label">курсов опубликовано</div></div>
        <div class="stat"><div class="stat-value">{{ data.totals.coursesCompleted }}</div><div class="stat-label">завершений курсов</div></div>
        <div class="stat"><div class="stat-value">{{ data.totals.assignments }}</div><div class="stat-label">активных назначений</div></div>
      </div>

      <div class="studio-tabs">
        <button class="tab" :class="{ active: tab === 'people' }" @click="tab = 'people'">Сотрудники</button>
        <button class="tab" :class="{ active: tab === 'courses' }" @click="tab = 'courses'">Курсы</button>
        <button class="tab" :class="{ active: tab === 'assignments' }" @click="tab = 'assignments'">Назначения</button>
      </div>

      <div v-if="tab === 'people'" class="panel">
        <EmptyState v-if="!data.people.length" icon="accounts" title="Заданий пока нет" text="Назначьте видео или курс — прогресс появится здесь." />
        <div v-else class="table-wrap"><table class="table"><thead><tr><th>Сотрудник</th><th>Группы</th><th>Назначения</th><th>Курсы</th><th>Прогресс</th></tr></thead><tbody>
          <tr v-for="p in data.people" :key="p.id" :class="{ 'row-warn': p.assignmentsOverdue > 0 }">
            <td><router-link :to="`/admin/users/${p.id}`"><b>{{ p.displayName }}</b></router-link><div class="tiny muted">{{ p.email }}</div></td>
            <td class="small muted">{{ p.groups || '—' }}</td>
            <td class="small">{{ p.assignmentsDone }} из {{ p.assignmentsTotal }}<span v-if="p.assignmentsOverdue" class="tiny" style="color:var(--danger)"> · просрочено {{ p.assignmentsOverdue }}</span></td>
            <td class="small">{{ p.coursesDone }} из {{ p.coursesTotal }}</td>
            <td style="min-width:130px"><div class="bar"><i :style="{ width: (p.percent || 0) + '%' }"></i></div><span class="tiny muted">{{ p.percent === null ? '—' : p.percent + '%' }}</span></td>
          </tr>
        </tbody></table></div>
      </div>

      <div v-else-if="tab === 'courses'" class="panel">
        <EmptyState v-if="!data.courses.length" icon="school" title="Курсов пока нет" text="Курсы создаются в студии: «Студия → Курсы»." />
        <div v-else class="table-wrap"><table class="table"><thead><tr><th>Курс</th><th>Шагов</th><th>Записаны</th><th>Прошли</th><th>Доля</th></tr></thead><tbody>
          <tr v-for="c in data.courses" :key="c.id">
            <td><router-link :to="`/course/${c.slug || c.id}`"><b>{{ c.title }}</b></router-link></td>
            <td class="small">{{ c.itemCount }}</td>
            <td class="small">{{ c.enrolled }}</td>
            <td class="small">{{ c.completed }}</td>
            <td style="min-width:130px"><div class="bar"><i :style="{ width: c.percent + '%' }"></i></div><span class="tiny muted">{{ c.percent }}%</span></td>
          </tr>
        </tbody></table></div>
      </div>

      <div v-else class="panel">
        <EmptyState v-if="!data.assignments.length" icon="assignment" title="Активных назначений нет" text="Назначения создаются из студии и со страницы видео." />
        <div v-else class="table-wrap"><table class="table"><thead><tr><th>Назначение</th><th>Тип</th><th>Срок</th><th>Выполнили</th><th>Просрочено</th></tr></thead><tbody>
          <tr v-for="a in data.assignments" :key="a.id" :class="{ 'row-warn': a.overdue > 0 }">
            <td><router-link :to="`/studio/assignments/${a.id}`"><b>{{ a.title }}</b></router-link></td>
            <td class="small muted">{{ KIND[a.kind] || a.kind }}</td>
            <td class="small">{{ a.dueAt ? fmtDate(a.dueAt) : '—' }}</td>
            <td class="small">{{ a.completed }} из {{ a.total }} <span class="tiny muted">({{ a.percent }}%)</span></td>
            <td class="small">{{ a.overdue || '—' }}</td>
          </tr>
        </tbody></table></div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.stat.danger .stat-value { color: var(--danger); }
.stat-value { font-size: 26px; font-weight: 700; }
.stat-label { font-size: 12px; color: var(--muted); }
.bar { height: 6px; border-radius: 3px; background: var(--surface-2); overflow: hidden; }
.bar i { display: block; height: 100%; background: var(--brand); }
.row-warn td { background: color-mix(in srgb, var(--danger) 6%, transparent); }
</style>
