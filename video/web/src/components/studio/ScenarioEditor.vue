<script setup>
// Тренажёр с ветвлением: развилки по таймкодам, варианты и переходы (H5P Branching Scenario, Mindstamp).
import { ref, computed, onMounted } from 'vue';
import { get, put } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDuration } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['changed']);
const ui = useUi();
const form = ref({ title: 'Тренажёр', active: true, showResult: true, points: [] });
const report = ref(null);
const saving = ref(false);
const loaded = ref(false);

const uid = () => Math.random().toString(36).slice(2, 10);

async function load() {
  try {
    const r = await get(`/api/videos/${props.video.shortId}/scenario`);
    if (r.scenario) {
      form.value = {
        title: r.scenario.title, active: r.scenario.active !== false, showResult: r.scenario.showResult !== false,
        points: (r.scenario.points || []).map((p) => ({ ...p, options: p.options.map((o) => ({ feedback: '', correct: false, ending: false, ...o })) })),
      };
    }
  } catch { /* тренажёра ещё нет */ }
  try { report.value = await get(`/api/videos/${props.video.id}/scenario/report`); } catch { report.value = null; }
  loaded.value = true;
}
onMounted(load);

function addPoint() {
  const at = Math.round(props.player?.currentTime?.() || 0);
  form.value.points.push({ id: uid(), at, text: '', options: [
    { id: uid(), text: '', goto: null, feedback: '', correct: true, ending: false },
    { id: uid(), text: '', goto: null, feedback: '', correct: false, ending: false },
  ] });
}
function addOption(p) { if (p.options.length < 6) p.options.push({ id: uid(), text: '', goto: null, feedback: '', correct: false, ending: false }); }
function removePoint(i) { form.value.points.splice(i, 1); }
function removeOption(p, i) { if (p.options.length > 2) p.options.splice(i, 1); }
function setAtFromPlayer(p) { p.at = Math.round(props.player?.currentTime?.() || 0); }
function setGotoFromPlayer(o) { o.goto = Math.round(props.player?.currentTime?.() || 0); }
function seek(t) { props.player?.seekTo?.(Math.max(0, Number(t) || 0)); }

const problems = computed(() => {
  const list = [];
  form.value.points.forEach((p, i) => {
    if (!p.text.trim()) list.push(`Развилка ${i + 1}: не задан вопрос`);
    if (p.options.filter((o) => o.text.trim()).length < 2) list.push(`Развилка ${i + 1}: нужно минимум два варианта`);
  });
  return list;
});

async function save() {
  saving.value = true;
  try {
    const r = await put(`/api/videos/${props.video.id}/scenario`, form.value);
    form.value.points = r.scenario.points.map((p) => ({ ...p, options: p.options.map((o) => ({ feedback: '', correct: false, ending: false, ...o })) }));
    emit('changed', form.value.points.length > 0 && form.value.active);
    ui.toast('Тренажёр сохранён', { type: 'success' });
    try { report.value = await get(`/api/videos/${props.video.id}/scenario/report`); } catch { /* ignore */ }
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
</script>

<template>
  <div class="panel">
    <h3 class="mb-8">Тренажёр с ветвлением</h3>
    <p class="muted small">Видео останавливается в заданный момент, зритель выбирает действие — и попадает на нужную секунду. Так делают сценарии-тренажёры по охране труда и работе с клиентом: «правильный» выбор ведёт дальше, ошибочный — к разбору ошибки.</p>

    <div class="form-grid mt-16">
      <div class="field"><label>Название</label><input class="input" v-model="form.title" maxlength="150" /></div>
      <div class="field" style="justify-content:flex-end; gap: 12px">
        <label class="switch"><input type="checkbox" v-model="form.active" /><span class="track"></span><span>Включён</span></label>
        <label class="switch"><input type="checkbox" v-model="form.showResult" /><span class="track"></span><span>Показывать итог</span></label>
      </div>
    </div>

    <div v-for="(p, i) in form.points" :key="p.id" class="panel soft mt-16 sc-point">
      <div class="row gap-8" style="align-items:center">
        <b class="grow">Развилка {{ i + 1 }}</b>
        <div class="row gap-4" style="align-items:center">
          <input class="input sm" type="number" min="0" step="1" style="width: 92px" v-model.number="p.at" />
          <span class="tiny muted">с</span>
          <button class="btn sm soft" title="Взять текущую секунду плеера" @click="setAtFromPlayer(p)" aria-label="Взять текущую секунду плеера"><Icon name="timer" :size="16" /></button>
          <button class="btn sm soft" title="Перейти к моменту" @click="seek(p.at)"><Icon name="play" :size="16" /> {{ fmtDuration(p.at) }}</button>
          <button class="btn sm soft" @click="removePoint(i)" aria-label="Удалить точку"><Icon name="delete" :size="16" /></button>
        </div>
      </div>
      <div class="field mt-8"><label>Вопрос зрителю</label><input class="input" v-model="p.text" maxlength="300" placeholder="Например: на участке утечка. Что делать первым?" /></div>

      <div v-for="(o, oi) in p.options" :key="o.id" class="sc-option-row">
        <input class="input grow" v-model="o.text" maxlength="200" placeholder="Вариант ответа" />
        <div class="row gap-4" style="align-items:center">
          <input class="input sm" type="number" min="0" step="1" style="width: 84px" v-model.number="o.goto" placeholder="→ с" title="Перейти к секунде" />
          <button class="btn sm soft" title="Взять текущую секунду плеера" @click="setGotoFromPlayer(o)" aria-label="Взять текущую секунду плеера"><Icon name="timer" :size="16" /></button>
          <label class="switch sm" title="Правильный вариант"><input type="checkbox" v-model="o.correct" /><span class="track"></span></label>
          <label class="switch sm" title="Завершить сценарий"><input type="checkbox" v-model="o.ending" /><span class="track"></span><span class="tiny">финал</span></label>
          <button class="btn sm soft" :disabled="p.options.length <= 2" @click="removeOption(p, oi)" aria-label="Удалить вариант"><Icon name="close" :size="16" /></button>
        </div>
        <input class="input" v-model="o.feedback" maxlength="300" placeholder="Что сказать после выбора (необязательно)" />
      </div>
      <button class="btn sm mt-8" :disabled="p.options.length >= 6" @click="addOption(p)"><Icon name="plus" :size="16" /> Вариант</button>
    </div>

    <div class="row gap-8 mt-16">
      <button class="btn" @click="addPoint"><Icon name="plus" :size="18" /> Развилка на текущей секунде</button>
      <button class="btn primary" :disabled="saving || !!problems.length" @click="save">Сохранить</button>
    </div>
    <ul v-if="problems.length" class="small mt-8" style="color: var(--warning)"><li v-for="(x, i) in problems" :key="i">{{ x }}</li></ul>

    <div v-if="report && report.totals.choices" class="mt-16">
      <h4>Как проходят</h4>
      <p class="small muted">Выборов: <b>{{ report.totals.choices }}</b> · сотрудников: <b>{{ report.totals.people }}</b> · ошибочных выборов: <b>{{ report.totals.mistakes }}</b></p>
      <div v-for="p in report.points" :key="p.id" class="mt-8">
        <div class="small"><b>{{ fmtDuration(p.at) }}</b> — {{ p.text }} <span class="tiny muted">({{ p.total }})</span></div>
        <div v-for="o in p.options" :key="o.id" class="row gap-8 tiny" style="align-items:center">
          <Icon :name="o.correct ? 'done' : 'dots'" :size="14" :style="{ color: o.correct ? 'var(--success)' : 'var(--text-3)' }" />
          <span class="grow ellipsis">{{ o.text }}</span>
          <div class="sc-bar"><i :style="{ width: o.percent + '%' }"></i></div>
          <span style="width: 58px; text-align:right">{{ o.count }} · {{ o.percent }}%</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.sc-point .sc-option-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; margin-top: 8px; }
.sc-point .sc-option-row > input:last-child { grid-column: 1 / -1; }
.sc-bar { width: 120px; height: 6px; border-radius: 3px; background: var(--line-2); overflow: hidden; }
.sc-bar i { display: block; height: 100%; background: var(--brand); }
</style>
