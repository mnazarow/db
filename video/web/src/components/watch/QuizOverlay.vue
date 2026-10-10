<script setup>
// Тест внутри плеера: вопросы по таймкодам (пауза видео) и итоговый тест после просмотра.
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null }, ended: Boolean, active: { type: Boolean, default: true } });
const emit = defineEmits(['passed', 'state']);
const ui = useUi();
const data = ref(null); // { quiz, attempts, best, passed, canRetry }
const mode = ref('idle'); // idle | question | final | result
const current = ref(null); // вопрос с таймкодом
const answers = ref({});
const feedback = ref(null); // результат проверки одного вопроса
const finalIndex = ref(0);
const result = ref(null);
const asked = new Set();
const skipped = new Set();
let timer = null;

const questions = computed(() => data.value?.quiz?.questions || []);
const timed = computed(() => questions.value.filter((q) => q.at !== null && q.at !== undefined));
const finalList = computed(() => questions.value); // итоговый тест всегда включает все вопросы
const fq = computed(() => finalList.value[finalIndex.value]);
const answeredCount = computed(() => finalList.value.filter((q) => (answers.value[q.id] || []).length).length);

async function load() {
  try { data.value = await get(`/api/videos/${props.video.shortId}/quiz`); emit('state', data.value); } catch { data.value = null; }
}
onMounted(() => { load(); timer = setInterval(tick, 500); });
onBeforeUnmount(() => clearInterval(timer));

function tick() {
  if (!props.active || !data.value?.quiz || mode.value !== 'idle' || data.value.passed) return;
  const t = props.player?.currentTime?.() || 0;
  const paused = props.player?.isPaused?.();
  if (paused) return;
  for (const q of timed.value) {
    if (asked.has(q.id)) continue;
    if (t >= q.at && t < q.at + 1.5) { openQuestion(q); return; }
  }
}
function openQuestion(q) {
  asked.add(q.id); current.value = q; feedback.value = null; answers.value[q.id] = []; mode.value = 'question';
  props.player?.pause?.();
}
function toggle(q, oId) {
  const arr = answers.value[q.id] || [];
  if (q.type === 'single') answers.value[q.id] = [oId];
  else answers.value[q.id] = arr.includes(oId) ? arr.filter((x) => x !== oId) : [...arr, oId];
}
async function checkCurrent() {
  const q = current.value; const a = answers.value[q.id] || [];
  if (!a.length) return;
  try { feedback.value = await post(`/api/videos/${props.video.shortId}/quiz/check`, { questionId: q.id, answer: a }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function continueVideo() { mode.value = 'idle'; current.value = null; feedback.value = null; props.player?.play?.(); }
function skipQuestion() { skipped.add(current.value.id); continueVideo(); }
function startFinal() { finalIndex.value = 0; result.value = null; mode.value = 'final'; props.player?.pause?.(); }
async function finish() {
  try {
    const r = await post(`/api/videos/${props.video.shortId}/quiz/finish`, { answers: answers.value });
    result.value = r.result; mode.value = 'result';
    await load();
    if (r.result.passed) emit('passed');
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function retry() { answers.value = {}; asked.clear(); skipped.clear(); startFinal(); }
// После окончания видео — предложить итоговый тест, если он не сдан
watch(() => props.ended, (e) => { if (e && data.value?.quiz && !data.value.passed && mode.value === 'idle' && (data.value.canRetry || !data.value.attempts.length)) mode.value = 'prompt'; });
defineExpose({ start: startFinal, hasQuiz: () => !!data.value?.quiz, passed: () => !!data.value?.passed });
</script>

<template>
  <div v-if="data?.quiz && mode !== 'idle'" class="quiz-ov" @click.stop @keydown.stop>
    <!-- Вопрос по ходу видео -->
    <div v-if="mode === 'question' && current" class="quiz-card">
      <div class="tiny" style="opacity:.8; text-transform:uppercase; letter-spacing:.08em"><Icon name="quiz" :size="14" style="vertical-align:-2px" /> Проверка знаний</div>
      <div class="q-text">{{ current.text }}</div>
      <div class="col gap-8">
        <button v-for="o in current.options" :key="o.id" class="q-opt" :class="{ on: (answers[current.id] || []).includes(o.id), right: feedback?.right?.includes(o.id), wrong: feedback && (answers[current.id] || []).includes(o.id) && feedback.right && !feedback.right.includes(o.id) }" :disabled="!!feedback" @click="toggle(current, o.id)"><span class="mark"><Icon v-if="(answers[current.id] || []).includes(o.id)" name="check" :size="14" /></span>{{ o.text }}</button>
      </div>
      <div v-if="feedback" class="q-fb" :class="feedback.correct ? 'ok' : 'bad'"><Icon :name="feedback.correct ? 'done' : 'alertCircle'" :size="20" /> <span>{{ feedback.correct ? 'Верно!' : 'Неверно.' }} {{ feedback.explanation || '' }}</span></div>
      <div class="row mt-16">
        <button v-if="!feedback" class="btn primary sm" :disabled="!(answers[current.id] || []).length" @click="checkCurrent">Ответить</button>
        <button v-else class="btn primary sm" @click="continueVideo"><Icon name="play" :size="16" /> Продолжить просмотр</button>
        <button v-if="!feedback" class="btn sm ghost-light" @click="skipQuestion">Пропустить</button>
        <span class="tiny" style="opacity:.7; margin-left:auto">Итоговый результат засчитывается после теста в конце видео</span>
      </div>
    </div>

    <!-- Предложение пройти итоговый тест -->
    <div v-else-if="mode === 'prompt'" class="quiz-card">
      <div class="q-text"><Icon name="quiz" :size="22" style="vertical-align:-4px" /> {{ data.quiz.title }}</div>
      <p class="small" style="opacity:.85">{{ data.quiz.total }} вопрос(ов), проходной балл {{ data.quiz.passPercent }}%.<span v-if="data.best"> Ваш лучший результат: {{ data.best.percent }}%.</span></p>
      <div class="row mt-8"><button class="btn primary sm" @click="startFinal"><Icon name="play" :size="16" /> Пройти тест</button><button class="btn sm ghost-light" @click="mode = 'idle'">Позже</button></div>
    </div>

    <!-- Итоговый тест -->
    <div v-else-if="mode === 'final' && fq" class="quiz-card">
      <div class="row" style="justify-content:space-between"><div class="tiny" style="opacity:.8; text-transform:uppercase; letter-spacing:.08em">{{ data.quiz.title }} — вопрос {{ finalIndex + 1 }} из {{ finalList.length }}</div><button class="ibtn" style="color:#fff" title="Закрыть" @click="mode = 'idle'" aria-label="Закрыть"><Icon name="close" :size="18" /></button></div>
      <div class="meter mb-8" style="background: rgba(255,255,255,.2)"><i :style="{ width: ((finalIndex) / finalList.length * 100) + '%' }"></i></div>
      <div class="q-text">{{ fq.text }}</div>
      <div class="tiny mb-8" style="opacity:.7">{{ fq.type === 'multiple' ? 'Выберите все подходящие варианты' : 'Выберите один вариант' }}</div>
      <div class="col gap-8"><button v-for="o in fq.options" :key="o.id" class="q-opt" :class="{ on: (answers[fq.id] || []).includes(o.id) }" @click="toggle(fq, o.id)"><span class="mark"><Icon v-if="(answers[fq.id] || []).includes(o.id)" name="check" :size="14" /></span>{{ o.text }}</button></div>
      <div class="row mt-16">
        <button class="btn sm ghost-light" :disabled="finalIndex === 0" @click="finalIndex--"><Icon name="chevronLeft" :size="16" /> Назад</button>
        <button v-if="finalIndex < finalList.length - 1" class="btn primary sm" :disabled="!(answers[fq.id] || []).length" @click="finalIndex++">Далее <Icon name="chevronRight" :size="16" /></button>
        <button v-else class="btn primary sm" :disabled="answeredCount < finalList.length" @click="finish"><Icon name="check" :size="16" /> Завершить тест</button>
        <span class="tiny" style="opacity:.7; margin-left:auto">Отвечено {{ answeredCount }} из {{ finalList.length }}</span>
      </div>
    </div>

    <!-- Результат -->
    <div v-else-if="mode === 'result' && result" class="quiz-card">
      <div class="q-res" :class="result.passed ? 'ok' : 'bad'"><Icon :name="result.passed ? 'done' : 'alertCircle'" :size="40" /><div><div class="q-text" style="margin:0">{{ result.passed ? 'Тест сдан' : 'Тест не сдан' }}</div><div class="small" style="opacity:.85">Правильных ответов: {{ result.correct }} из {{ result.total }} ({{ result.percent }}%), проходной балл {{ result.passPercent }}%</div></div></div>
      <div v-if="data.quiz.showAnswers" class="q-details">
        <div v-for="(d, i) in result.details" :key="d.id" class="row gap-8 small" style="align-items:flex-start"><Icon :name="d.correct ? 'doneOutline' : 'close'" :size="16" :style="{ color: d.correct ? 'var(--success)' : '#ff8a80', flexShrink: 0, marginTop: '2px' }" /><span><span>{{ i + 1 }}. {{ questions.find((q) => q.id === d.id)?.text }}</span><span v-if="!d.correct && d.right" style="opacity:.8"> — верно: {{ d.right.map((id) => questions.find((q) => q.id === d.id)?.options.find((o) => o.id === id)?.text).filter(Boolean).join('; ') }}</span><span v-if="d.explanation" class="tiny" style="display:block; opacity:.75">{{ d.explanation }}</span></span></div>
      </div>
      <div class="row mt-16"><button v-if="!result.passed && data.quiz.allowRetry" class="btn primary sm" @click="retry"><Icon name="replay" :size="16" /> Пройти ещё раз</button><button class="btn sm ghost-light" @click="mode = 'idle'">Закрыть</button></div>
    </div>
  </div>
</template>

<style>
.quiz-ov { position: absolute; inset: 0; background: rgba(6, 20, 40, 0.88); display: flex; align-items: center; justify-content: center; padding: 24px; color: #fff; z-index: 5; overflow: auto; }
.quiz-card { width: 100%; max-width: 640px; }
.quiz-card .q-text { font-size: 20px; font-weight: 500; line-height: 1.3; margin: 8px 0 14px; }
.q-opt { display: flex; align-items: center; gap: 12px; width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.06); color: #fff; font: inherit; font-size: 15px; text-align: left; cursor: pointer; transition: background .15s; }
.q-opt:hover:not(:disabled) { background: rgba(255,255,255,.14); }
.q-opt.on { border-color: #fff; background: rgba(255,255,255,.18); }
.q-opt.right { border-color: var(--success); background: rgba(30,142,90,.35); }
.q-opt.wrong { border-color: #ff5252; background: rgba(208,0,36,.35); }
.q-opt .mark { width: 22px; height: 22px; border-radius: 50%; border: 2px solid rgba(255,255,255,.6); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.q-opt.on .mark { background: #fff; color: var(--brand); border-color: #fff; }
.q-fb { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: rgba(255,255,255,.1); font-size: 14px; }
.q-fb.ok { color: #9be7bb; } .q-fb.bad { color: #ffb3b3; }
.q-res { display: flex; gap: 16px; align-items: center; margin-bottom: 12px; }
.q-res.ok .icon { color: #5fd68f; } .q-res.bad .icon { color: #ff8a80; }
.q-details { display: flex; flex-direction: column; gap: 6px; max-height: 40vh; overflow: auto; padding-right: 4px; }
.ghost-light { border-color: rgba(255,255,255,.5) !important; color: #fff !important; background: transparent !important; }
@media (max-width: 640px) { .quiz-ov { padding: 12px; align-items: flex-start; } .quiz-card .q-text { font-size: 16px; } }
</style>
