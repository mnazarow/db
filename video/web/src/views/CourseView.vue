<script setup>
// Страница учебной программы: шаги по разделам, прогресс, сертификат.
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get, post } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import EmptyState from '../components/EmptyState.vue';
import { fmtDuration, fmtDurationLong, fmtDate, plural } from '../utils/format.js';

const route = useRoute();
const auth = useAuth();
const ui = useUi();
const course = ref(null);
const items = ref([]);
const loading = ref(true);
const error = ref(null);
const busy = ref(0);

async function load() {
  loading.value = true; error.value = null;
  try {
    const r = await get(`/api/courses/${route.params.id}`);
    course.value = r.course; items.value = r.items;
    document.title = `${r.course.title} — ${auth.siteName}`;
  } catch (e) { error.value = e; } finally { loading.value = false; }
}
onMounted(load);
watch(() => route.params.id, load);

const sections = computed(() => {
  const out = [];
  for (const it of items.value) {
    const name = it.section || '';
    let s = out.find((x) => x.name === name);
    if (!s) { s = { name, items: [] }; out.push(s); }
    s.items.push(it);
  }
  return out;
});
const nextItem = computed(() => items.value.find((i) => i.id === course.value?.nextItemId) || items.value.find((i) => !i.completed && !i.locked));
const dueLabel = computed(() => {
  const d = course.value?.assignment?.dueAt;
  if (!d) return null;
  const days = Math.ceil((new Date(d) - Date.now()) / 86400000);
  if (course.value.completed) return `Выполнено, срок был до ${fmtDate(d)}`;
  if (days < 0) return `Просрочено — срок был до ${fmtDate(d)}`;
  if (days === 0) return 'Срок — сегодня';
  return `Срок до ${fmtDate(d)} (осталось ${days} ${plural(days, 'день', 'дня', 'дней')})`;
});

async function enroll() {
  busy.value++;
  try { await post(`/api/courses/${course.value.id}/enroll`, {}); await load(); ui.toast('Вы записаны на курс', { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value--; }
}
async function markDone(it) {
  busy.value++;
  try {
    await post(`/api/courses/${course.value.id}/items/${it.id}/complete`, {});
    await load();
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value--; }
}
function itemLink(it) {
  if (it.kind !== 'video' || !it.video) return null;
  return `/watch/${it.video.shortId}?course=${course.value.slug || course.value.id}`;
}
const ICONS = { video: 'play', material: 'inventory', text: 'doc' };
</script>

<template>
  <div class="page">
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="error" icon="lock" :title="error.status === 403 ? 'Нет доступа к курсу' : 'Курс не найден'" :text="error.message" />
    <template v-else-if="course">
      <div class="panel course-head">
        <div class="grow">
          <div class="row gap-8" style="align-items:center">
            <h1 style="margin:0">{{ course.title }}</h1>
            <span v-if="course.status !== 'published'" class="badge warning">Черновик</span>
            <span v-if="course.completed" class="badge success">Пройден</span>
          </div>
          <p v-if="course.description" class="muted" style="margin:8px 0 0; white-space:pre-line">{{ course.description }}</p>
          <div class="tiny muted mt-8">
            {{ course.itemCount }} {{ plural(course.itemCount, 'шаг', 'шага', 'шагов') }}<span v-if="course.durationSec"> · {{ fmtDurationLong(course.durationSec) }} видео</span>
            <span v-if="course.sequential"> · последовательное прохождение</span>
            <span v-if="course.issueCertificate"> · сертификат по завершении</span>
          </div>
          <div v-if="dueLabel" class="small mt-8" :class="{ 'text-danger': dueLabel.startsWith('Просрочено') }"><Icon name="clock" :size="16" style="vertical-align:-3px" /> {{ dueLabel }}</div>
        </div>
        <div class="course-side">
          <div class="course-ring" :style="{ '--p': (course.percent || 0) + '%' }"><span>{{ course.percent || 0 }}%</span></div>
          <div class="tiny muted" style="text-align:center">{{ course.doneCount }} из {{ course.requiredCount }} шагов</div>
          <router-link v-if="course.enrolled && nextItem && itemLink(nextItem)" class="btn primary" :to="itemLink(nextItem)"><Icon name="play" :size="16" /> Продолжить</router-link>
          <button v-else-if="!course.enrolled && auth.isActive" class="btn primary" :disabled="busy > 0" @click="enroll">Записаться на курс</button>
          <router-link v-if="course.certificateId" class="btn" :to="`/cert/${course.certificateId}`"><Icon name="verified" :size="16" /> Сертификат</router-link>
          <router-link v-if="course.canEdit" class="btn ghost sm" :to="`/studio/courses/${course.id}`"><Icon name="edit" :size="16" /> Редактировать</router-link>
        </div>
      </div>

      <div v-for="(sec, si) in sections" :key="si" class="panel mt-16">
        <h3 v-if="sec.name" class="section-name">{{ sec.name }}</h3>
        <ol class="steps">
          <li v-for="it in sec.items" :key="it.id" class="step" :class="{ done: it.completed, locked: it.locked }">
            <div class="step-icon" :aria-hidden="true">
              <Icon v-if="it.completed" name="check" :size="18" />
              <Icon v-else-if="it.locked" name="lock" :size="16" />
              <Icon v-else :name="ICONS[it.kind] || 'doc'" :size="16" />
            </div>
            <div class="grow" style="min-width:0">
              <div class="row gap-8" style="align-items:baseline">
                <component :is="it.locked || !itemLink(it) ? 'span' : 'router-link'" :to="itemLink(it) || undefined" class="step-title">{{ it.title }}</component>
                <span v-if="!it.required" class="tiny muted">необязательно</span>
                <span v-if="it.requireQuiz" class="tiny muted">с тестом</span>
              </div>
              <div v-if="it.kind === 'video' && it.video" class="tiny muted">Видео · {{ fmtDuration(it.video.duration) }}<span v-if="it.percent && !it.completed"> · просмотрено {{ it.percent }}%</span></div>
              <p v-if="it.kind === 'text' && it.body" class="small" style="margin:6px 0 0; white-space:pre-line">{{ it.body }}</p>
              <a v-if="it.kind === 'material' && it.url" class="small" :href="it.url" target="_blank" rel="noopener"><Icon name="openNew" :size="14" style="vertical-align:-2px" /> Открыть материал</a>
              <div v-if="it.locked" class="tiny muted">Откроется после прохождения предыдущих шагов</div>
            </div>
            <button v-if="it.kind !== 'video' && course.enrolled && !it.completed && !it.locked" class="btn sm" :disabled="busy > 0" @click="markDone(it)">Изучил</button>
            <span v-else-if="it.completed" class="tiny ok">Пройдено</span>
          </li>
        </ol>
      </div>

      <div v-if="!course.enrolled && auth.isActive" class="panel mt-16 row" style="align-items:center">
        <div class="grow"><b>Запишитесь на курс</b><div class="small muted">Прогресс сохраняется, а по завершении выдаётся сертификат.</div></div>
        <button class="btn primary" :disabled="busy > 0" @click="enroll">Записаться</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.course-head { display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start; }
.course-side { display: flex; flex-direction: column; gap: 8px; min-width: 200px; }
.course-ring { --p: 0%; width: 96px; height: 96px; margin: 0 auto; border-radius: 50%; display: grid; place-items: center;
  background: conic-gradient(var(--brand) var(--p), var(--surface-2) 0); position: relative; }
.course-ring::after { content: ''; position: absolute; inset: 8px; border-radius: 50%; background: var(--surface); }
.course-ring span { position: relative; z-index: 1; font-weight: 700; }
.section-name { margin: 0 0 8px; font-size: 16px; }
.steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.step { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-top: 1px solid var(--border); }
.step:first-child { border-top: 0; }
.step-icon { width: 30px; height: 30px; flex: none; border-radius: 50%; display: grid; place-items: center; background: var(--surface-2); color: var(--muted); }
.step.done .step-icon { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
.step.locked { opacity: .6; }
.step-title { font-weight: 600; color: inherit; text-decoration: none; }
a.step-title:hover { text-decoration: underline; }
.tiny.ok { color: var(--success); }
.text-danger { color: var(--danger); }
</style>
