<script setup>
// Редактор теста (проверки знаний) внутри видео: вопросы с таймкодами, варианты, проходной балл, результаты.
import { ref, computed, onMounted } from 'vue';
import { get, put, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDuration, fmtDateTime, parseTime } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['changed']);
const ui = useUi();
const quiz = ref({ title: 'Проверка знаний', passPercent: 70, showAnswers: true, allowRetry: true, questions: [] });
const stats = ref(null);
const results = ref(null);
const loading = ref(true);
const saving = ref(false);
const dirty = ref(false);
const showResults = ref(false);
let seq = 1;
const uid = (p) => `${p}${Date.now().toString(36)}${seq++}`;

async function load() {
  loading.value = true;
  try {
    const r = await get(`/api/videos/${props.video.id}/quiz/full`);
    if (r.quiz) quiz.value = { ...r.quiz, questions: r.quiz.questions.map((q) => ({ ...q, atText: q.at === null || q.at === undefined ? '' : fmtDuration(q.at) })) };
    stats.value = r.stats; dirty.value = false;
  } finally { loading.value = false; }
}
onMounted(load);
function touch() { dirty.value = true; }
function addQuestion() {
  quiz.value.questions.push({ id: uid('q'), at: null, atText: '', text: '', type: 'single', explanation: '', options: [{ id: uid('o'), text: '', correct: true }, { id: uid('o'), text: '', correct: false }] });
  touch();
}
function addOption(q) { if (q.options.length < 10) { q.options.push({ id: uid('o'), text: '', correct: false }); touch(); } }
function setCorrect(q, o) { if (q.type === 'single') q.options.forEach((x) => { x.correct = x === o; }); else o.correct = !o.correct; touch(); }
function useCurrent(q) { q.atText = fmtDuration(Math.floor(props.player?.currentTime?.() || 0)); touch(); }
function move(i, d) { const arr = quiz.value.questions; const j = i + d; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; touch(); }
async function save() {
  saving.value = true;
  try {
    const questions = quiz.value.questions.map((q) => ({ ...q, at: q.atText ? parseTime(q.atText) : null }));
    const bad = questions.find((q) => q.atText && q.at === null);
    if (bad) throw new Error(`Некорректный таймкод «${bad.atText}» — формат м:сс`);
    const r = await put(`/api/videos/${props.video.id}/quiz`, { ...quiz.value, questions });
    quiz.value = { ...r.quiz, questions: r.quiz.questions.map((q) => ({ ...q, atText: q.at === null || q.at === undefined ? '' : fmtDuration(q.at) })) };
    dirty.value = false; emit('changed', r.quiz.questions.length > 0); ui.toast('Тест сохранён', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
async function remove() {
  if (!(await ui.ask({ title: 'Удалить тест?', message: 'Вопросы и результаты сотрудников будут удалены.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/videos/${props.video.id}/quiz`); quiz.value = { title: 'Проверка знаний', passPercent: 70, showAnswers: true, allowRetry: true, questions: [] }; stats.value = null; emit('changed', false); ui.toast('Тест удалён');
}
async function loadResults() { results.value = (await get(`/api/videos/${props.video.id}/quiz/results?limit=500`)); showResults.value = true; }
const timed = computed(() => quiz.value.questions.filter((q) => q.atText).length);
</script>

<template>
  <div v-if="loading" class="loading-block"><div class="spin"></div></div>
  <div v-else>
    <div class="row wrap mb-16" style="align-items:flex-start">
      <div class="grow"><h3 class="mb-4">Проверка знаний</h3><p class="muted small" style="margin:0">Вопрос с таймкодом появляется по ходу просмотра и ставит видео на паузу; вопросы без таймкода задаются после просмотра. Тест можно сделать обязательным при назначении видео к просмотру.</p></div>
      <div v-if="stats && stats.attempts" class="stat-tiles" style="grid-template-columns: repeat(3, auto); gap: 8px"><div class="stat-tile" style="padding: 8px 12px"><div class="v" style="font-size: 20px">{{ stats.people }}</div><div class="l">проходили</div></div><div class="stat-tile" style="padding: 8px 12px"><div class="v" style="font-size: 20px">{{ stats.passed }}</div><div class="l">сдали</div></div><div class="stat-tile" style="padding: 8px 12px"><div class="v" style="font-size: 20px">{{ stats.avgPercent }}%</div><div class="l">средний балл</div></div></div>
    </div>
    <div class="form-grid mb-16">
      <div class="field"><label>Название теста</label><input class="input" v-model="quiz.title" maxlength="150" @input="touch" /></div>
      <div class="field"><label>Проходной балл</label><select class="select" v-model.number="quiz.passPercent" @change="touch"><option v-for="p in [50, 60, 70, 80, 90, 100]" :key="p" :value="p">{{ p }}% правильных ответов</option></select></div>
      <div class="field" style="grid-column: 1 / -1; flex-direction: row; gap: 24px; flex-wrap: wrap"><label class="switch"><input type="checkbox" v-model="quiz.showAnswers" @change="touch" /><span class="track"></span><span>Показывать правильные ответы после ответа</span></label><label class="switch"><input type="checkbox" v-model="quiz.allowRetry" @change="touch" /><span class="track"></span><span>Разрешить проходить повторно</span></label></div>
    </div>

    <div class="col gap-12">
      <div v-for="(q, qi) in quiz.questions" :key="q.id" class="panel soft q-card">
        <div class="row wrap gap-8 mb-8" style="align-items:center">
          <span class="badge brand">Вопрос {{ qi + 1 }}</span>
          <div class="row gap-4"><input class="input sm" v-model="q.atText" placeholder="таймкод" style="width: 90px" @input="touch" /><button class="btn ghost sm" title="Взять текущее время плеера" @click="useCurrent(q)" aria-label="Взять текущее время плеера"><Icon name="timer" :size="16" /></button><button v-if="q.atText" class="btn ghost sm" title="Без таймкода — после просмотра" @click="q.atText = ''; touch()" aria-label="Без таймкода — после просмотра"><Icon name="close" :size="14" /></button></div>
          <select class="select sm" v-model="q.type" style="width: 190px" @change="if (q.type === 'single') { let f = false; q.options.forEach((o) => { if (o.correct && f) o.correct = false; if (o.correct) f = true; }); } touch()"><option value="single">Один ответ</option><option value="multiple">Несколько ответов</option></select>
          <span class="grow"></span>
          <button class="ibtn sm" title="Выше" @click="move(qi, -1)" aria-label="Выше"><Icon name="chevronUp" :size="16" /></button><button class="ibtn sm" title="Ниже" @click="move(qi, 1)" aria-label="Ниже"><Icon name="chevronDown" :size="16" /></button><button class="ibtn sm" title="Удалить вопрос" @click="quiz.questions.splice(qi, 1); touch()" aria-label="Удалить вопрос"><Icon name="delete" :size="16" /></button>
        </div>
        <textarea class="textarea mb-8" v-model="q.text" rows="2" placeholder="Текст вопроса" @input="touch"></textarea>
        <div class="col gap-4">
          <div v-for="(o, oi) in q.options" :key="o.id" class="row gap-8" style="align-items:center">
            <button class="opt-mark" :class="{ on: o.correct, multi: q.type === 'multiple' }" :title="o.correct ? 'Правильный ответ' : 'Отметить как правильный'" @click="setCorrect(q, o)" :aria-label="o.correct ? 'Правильный ответ' : 'Отметить как правильный'"><Icon v-if="o.correct" name="check" :size="14" /></button>
            <input class="input sm grow" v-model="o.text" :placeholder="`Вариант ${oi + 1}`" @input="touch" />
            <button class="ibtn sm" :disabled="q.options.length <= 2" @click="q.options.splice(oi, 1); touch()" aria-label="Удалить вариант ответа"><Icon name="close" :size="14" /></button>
          </div>
        </div>
        <div class="row wrap mt-8"><button class="btn ghost sm" :disabled="q.options.length >= 10" @click="addOption(q)"><Icon name="plus" :size="14" /> Вариант</button><input class="input sm grow" v-model="q.explanation" placeholder="Пояснение к правильному ответу (необязательно)" @input="touch" /></div>
      </div>
    </div>
    <div class="row wrap mt-16"><button class="btn" @click="addQuestion"><Icon name="plus" :size="18" /> Добавить вопрос</button><span class="small muted" v-if="quiz.questions.length">{{ quiz.questions.length }} вопр., с таймкодом: {{ timed }}</span></div>
    <div class="form-actions">
      <button class="btn primary" :disabled="saving || !dirty" @click="save">{{ saving ? 'Сохранение…' : 'Сохранить тест' }}</button>
      <button v-if="stats" class="btn ghost" @click="loadResults"><Icon name="people" :size="16" /> Результаты сотрудников</button>
      <a v-if="stats && stats.attempts" class="btn ghost" :href="$url(`/api/videos/${video.id}/quiz/results?format=csv`)"><Icon name="csv" :size="16" /> CSV</a>
      <button v-if="stats" class="btn ghost danger" @click="remove">Удалить тест</button>
    </div>

    <div v-if="showResults && results" class="panel mt-16">
      <div class="row mb-8"><h4 class="grow">Результаты</h4><button class="ibtn" @click="showResults = false" aria-label="Закрыть результаты"><Icon name="close" /></button></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Сотрудник</th><th>Лучший результат</th><th>Попыток</th><th>Последняя</th><th>Итог</th></tr></thead><tbody>
        <tr v-for="r in results.results" :key="r.userId"><td>{{ r.displayName }}<div class="tiny muted">{{ r.email }}</div></td><td>{{ r.bestPercent }}% ({{ r.correct }} из {{ r.total }})</td><td>{{ r.attempts }}</td><td class="small muted">{{ fmtDateTime(r.lastAt) }}</td><td><span class="badge" :class="r.passed ? 'success' : 'danger'">{{ r.passed ? 'Сдан' : 'Не сдан' }}</span></td></tr>
        <tr v-if="!results.results.length"><td colspan="5" class="muted">Тест ещё никто не проходил</td></tr>
      </tbody></table></div>
    </div>
  </div>
</template>

<style>
.q-card { padding: 14px 16px; }
.opt-mark { width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--line-strong); display: inline-flex; align-items: center; justify-content: center; color: #fff; background: transparent; cursor: pointer; flex-shrink: 0; }
.opt-mark.multi { border-radius: 6px; }
.opt-mark.on { background: var(--success); border-color: var(--success); }
</style>
