<script setup>
// Тренажёр с ветвлением: в заданный момент видео останавливается и зритель выбирает действие,
// а портал переводит его на нужную секунду (как Branching Scenario у H5P и сценарии Mindstamp).
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null }, active: { type: Boolean, default: true } });
const emit = defineEmits(['state']);
const ui = useUi();
const scenario = ref(null);
const mode = ref('idle');      // idle | ask | feedback | done
const point = ref(null);
const answer = ref(null);      // { feedback, correct, ending, goto }
const path = ref([]);          // пройденные развилки
const asked = new Set();
let timer = null;

const points = computed(() => scenario.value?.points || []);

async function load() {
  try {
    const r = await get(`/api/videos/${props.video.shortId}/scenario`);
    scenario.value = r.scenario && r.scenario.active ? r.scenario : null;
    emit('state', scenario.value);
  } catch { scenario.value = null; }
}

function tick() {
  if (!props.active || !scenario.value || mode.value !== 'idle') return;
  const t = props.player?.currentTime?.() || 0;
  if (props.player?.isPaused?.()) return;
  for (const p of points.value) {
    if (asked.has(p.id)) continue;
    if (t >= p.at && t < p.at + 1.5) { open(p); return; }
  }
}

function open(p) {
  asked.add(p.id);
  point.value = p;
  answer.value = null;
  mode.value = 'ask';
  props.player?.pause?.();
}

async function choose(option) {
  try {
    const r = await post(`/api/videos/${props.video.id}/scenario/choice`, { pointId: point.value.id, optionId: option.id });
    answer.value = { ...r, optionText: option.text };
    path.value.push({ point: point.value.text, option: option.text, correct: r.correct });
    mode.value = 'feedback';
    if (!r.feedback && !r.ending) continueAfter();
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

function continueAfter() {
  const a = answer.value;
  if (a?.ending) { mode.value = 'done'; props.player?.pause?.(); return; }
  if (a && a.goto !== null && a.goto !== undefined) props.player?.seekTo?.(a.goto);
  mode.value = 'idle';
  point.value = null;
  answer.value = null;
  props.player?.play?.();
}

function restart() {
  asked.clear();
  path.value = [];
  mode.value = 'idle';
  point.value = null;
  answer.value = null;
  props.player?.seekTo?.(0);
  props.player?.play?.();
}

onMounted(() => { load(); timer = setInterval(tick, 500); });
onBeforeUnmount(() => clearInterval(timer));
defineExpose({ restart });
</script>

<template>
  <div v-if="mode !== 'idle'" class="end-screen scenario-overlay" @click.stop>
    <div class="es-card sc-card">
      <template v-if="mode === 'ask'">
        <div class="tiny sc-kicker">{{ scenario.title }}</div>
        <div class="es-title sc-question">{{ point.text }}</div>
        <div class="col gap-8 mt-16">
          <button v-for="o in point.options" :key="o.id" class="btn sc-option" @click="choose(o)">{{ o.text }}</button>
        </div>
      </template>
      <template v-else-if="mode === 'feedback'">
        <div class="tiny sc-kicker">Ваш выбор: {{ answer.optionText }}</div>
        <div class="es-title" :class="answer.correct ? 'sc-ok' : 'sc-no'">
          <Icon :name="answer.correct ? 'done' : 'alertCircle'" :size="22" style="vertical-align:-4px" />
          {{ answer.correct ? 'Верно' : 'Не лучший вариант' }}
        </div>
        <p v-if="answer.feedback" class="sc-feedback">{{ answer.feedback }}</p>
        <div class="row mt-16" style="justify-content:center">
          <button class="btn primary" @click="continueAfter"><Icon name="play" :size="18" /> {{ answer.ending ? 'Посмотреть итог' : 'Дальше' }}</button>
        </div>
      </template>
      <template v-else>
        <div class="es-title">Сценарий пройден</div>
        <div class="col gap-4 mt-8 sc-path">
          <div v-for="(s, i) in path" :key="i" class="row gap-8 small">
            <Icon :name="s.correct ? 'done' : 'alertCircle'" :size="16" :style="{ color: s.correct ? 'var(--success)' : 'var(--warning)' }" />
            <span class="grow">{{ s.point }}</span>
            <b>{{ s.option }}</b>
          </div>
        </div>
        <div class="row mt-16" style="justify-content:center">
          <button class="btn primary" @click="restart"><Icon name="replay" :size="18" /> Пройти заново</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style>
.scenario-overlay .sc-card { max-width: 560px; text-align: center; }
.scenario-overlay .sc-kicker { opacity: .75; text-transform: uppercase; letter-spacing: .08em; }
.scenario-overlay .sc-question { margin-top: 6px; line-height: 1.3; }
.scenario-overlay .sc-option { border-color: rgba(255,255,255,.5); color: #fff; justify-content: center; white-space: normal; text-align: center; }
.scenario-overlay .sc-option:hover { background: rgba(255,255,255,.16); }
.scenario-overlay .sc-ok { color: #8ef0b4; }
.scenario-overlay .sc-no { color: #ffd27a; }
.scenario-overlay .sc-feedback { margin: 10px 0 0; opacity: .9; }
.scenario-overlay .sc-path { text-align: left; max-height: 180px; overflow-y: auto; }
</style>
