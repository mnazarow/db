<script setup>
// Редактор видео в студии: обрезка, вырезание фрагментов, удаление пауз, клипы как отдельные видео, OCR текста на экране.
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { fmtDuration, fmtDateTime, parseTime } from '../../utils/format.js';
import VideoCard from '../VideoCard.vue';
import { url as withBase } from '../../base.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['changed']);
const ui = useUi();
const auth = useAuth();
const state = ref(null);
const loading = ref(true);
const busy = ref('');
const trim = ref({ start: '0:00', end: '' });
const cuts = ref([]);
const newCut = ref({ start: '', end: '' });
const silence = ref({ noiseDb: -35, minSec: 1.5, keepSec: 0.3 });
const silenceResult = ref(null);
const clip = ref({ start: '0:00', end: '0:30', title: '', vertical: false, visibility: 'private' });
const activeJob = ref(null);
// 1.7: ИИ предлагает фрагменты для клипов (как OpusClip и Vizard)
const sugg = ref({ enabled: false, suggestions: [], suggestedAt: null });
const suggBusy = ref(false);
let suggPoll = null;
let timer = null;

const duration = computed(() => Number(props.video.duration) || 0);
const OPS = { trim: 'Обрезка', cut: 'Вырезание фрагментов', remove_silence: 'Удаление пауз', edit: 'Монтаж' };
const JOB_TYPES = { video_edit: 'Монтаж', remove_silence: 'Паузы', clip_create: 'Клип', ocr: 'Текст на экране', subtitle_translate: 'Перевод субтитров' };

async function loadSuggestions() {
  try { sugg.value = await get(`/api/videos/${props.video.id}/clip-suggestions`); } catch { /* ignore */ }
}
async function suggestClips() {
  suggBusy.value = true;
  try {
    await post(`/api/videos/${props.video.id}/clip-suggestions`, {});
    ui.toast('ИИ читает расшифровку — предложения появятся здесь');
    let tries = 0;
    clearInterval(suggPoll);
    suggPoll = setInterval(async () => {
      tries += 1;
      await loadSuggestions();
      if (sugg.value.suggestions.length || tries > 30) { clearInterval(suggPoll); suggBusy.value = false; }
    }, 3000);
  } catch (e) { ui.toast(e.message, { type: 'error' }); suggBusy.value = false; }
}
function useSuggestion(x) {
  clip.value.start = fmtDuration(x.start);
  clip.value.end = fmtDuration(x.end);
  clip.value.title = x.title;
  props.player?.seekTo?.(x.start);
  ui.toast('Границы клипа подставлены — проверьте и нажмите «Создать клип»');
}
async function dismissSuggestion(x) {
  try { await fetch(withBase(`/api/videos/${props.video.id}/clip-suggestions/${x.id}`), { method: 'DELETE', credentials: 'include' }); } catch { /* ignore */ }
  sugg.value.suggestions = sugg.value.suggestions.filter((s) => s.id !== x.id);
}

async function load() {
  loading.value = true;
  try {
    state.value = await get(`/api/videos/${props.video.id}/editor`);
    silence.value = { ...silence.value, ...state.value.silence };
    trim.value.end = fmtDuration(Math.floor(duration.value));
    clip.value.end = fmtDuration(Math.min(30, Math.floor(duration.value)));
    const running = state.value.jobs.find((j) => ['queued', 'running'].includes(j.status));
    if (running) track(running.id); else activeJob.value = null;
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(() => { load(); loadSuggestions(); });
onBeforeUnmount(() => { clearInterval(timer); clearInterval(suggPoll); });
watch(() => props.video.version, load);

function track(jobId) {
  clearInterval(timer);
  timer = setInterval(async () => {
    try {
      const { job } = await get(`/api/videos/${props.video.id}/editor/jobs/${jobId}`);
      activeJob.value = job;
      if (['done', 'failed', 'cancelled'].includes(job.status)) {
        clearInterval(timer);
        busy.value = '';
        if (job.status === 'done') {
          if (job.type === 'remove_silence' && !job.payload.apply) silenceResult.value = job.result;
          else if (job.type === 'clip_create') { ui.toast('Клип создан и обрабатывается', { type: 'success' }); emit('changed'); }
          else if (job.type === 'ocr') { ui.toast(`Текст на экране распознан: ${job.result.texts} фрагментов`, { type: 'success' }); emit('changed'); }
          else { ui.toast('Монтаж выполнен — видео обрабатывается заново', { type: 'success' }); emit('changed'); }
          await load();
        } else ui.toast(job.error || 'Задание не выполнено', { type: 'error', timeout: 8000 });
      }
    } catch { /* ignore */ }
  }, 2000);
}

function t(v) { const s = parseTime(v); return s === null ? null : Math.max(0, Math.min(duration.value, s)); }
function now() { return Math.floor(props.player?.currentTime() || 0); }
async function run(kind, url, body, label) {
  if (busy.value) return;
  busy.value = kind;
  try { const r = await post(url, body); ui.toast(label); track(r.jobId); } catch (e) { busy.value = ''; ui.toast(e.message, { type: 'error' }); }
}

async function doTrim() {
  const start = t(trim.value.start), end = t(trim.value.end);
  if (start === null || end === null) return ui.toast('Укажите время в формате м:сс', { type: 'error' });
  if (!(await ui.ask({ title: 'Обрезать видео?', message: `Останется фрагмент ${fmtDuration(start)}–${fmtDuration(end)} (${fmtDuration(end - start)}). Видео будет обработано заново как новая версия; ссылка, статистика и комментарии сохранятся.`, okLabel: 'Обрезать' }))) return;
  run('trim', `/api/videos/${props.video.id}/editor/trim`, { start, end }, 'Обрезка поставлена в очередь');
}
function addCut() {
  const start = t(newCut.value.start), end = t(newCut.value.end);
  if (start === null || end === null || end - start < 0.2) return ui.toast('Укажите начало и конец фрагмента', { type: 'error' });
  cuts.value.push({ start, end }); cuts.value.sort((a, b) => a.start - b.start); newCut.value = { start: '', end: '' };
}
async function doCut() {
  if (!cuts.value.length) return;
  const total = cuts.value.reduce((n, c) => n + (c.end - c.start), 0);
  if (!(await ui.ask({ title: 'Вырезать фрагменты?', message: `Будет удалено ${cuts.value.length} фрагм. общей длительностью ${fmtDuration(total)}. Видео будет обработано заново как новая версия.`, okLabel: 'Вырезать' }))) return;
  run('cut', `/api/videos/${props.video.id}/editor/cut`, { cuts: cuts.value }, 'Монтаж поставлен в очередь');
}
function detectSilence() { silenceResult.value = null; run('silence', `/api/videos/${props.video.id}/editor/silence`, { ...silence.value, apply: false }, 'Ищем паузы…'); }
async function applySilence() {
  if (!(await ui.ask({ title: 'Удалить паузы?', message: `Из видео будет вырезано ${silenceResult.value.cuts.length} пауз (${fmtDuration(silenceResult.value.removedSec)}). Видео будет обработано заново как новая версия.`, okLabel: 'Удалить паузы' }))) return;
  run('silence', `/api/videos/${props.video.id}/editor/silence`, { ...silence.value, apply: true }, 'Удаление пауз поставлено в очередь');
}
async function makeClip() {
  const start = t(clip.value.start), end = t(clip.value.end);
  if (start === null || end === null || end - start < 1) return ui.toast('Клип должен быть не короче секунды', { type: 'error' });
  run('clip', `/api/videos/${props.video.id}/clips`, { start, end, title: clip.value.title, vertical: clip.value.vertical, visibility: clip.value.visibility }, clip.value.vertical ? 'Создаём короткое видео…' : 'Создаём клип…');
}
function runOcr() { run('ocr', `/api/videos/${props.video.id}/ocr`, {}, 'Распознавание текста на экране запущено'); }
function seek(s) { props.player?.seekTo(s); }
</script>

<template>
  <div v-if="loading" class="loading-block"><div class="spin"></div></div>
  <div v-else-if="state" class="col gap-16">
    <div v-if="!state.enabled" class="alert warning">Редактор видео отключён администратором.</div>
    <div v-if="activeJob && ['queued', 'running'].includes(activeJob.status)" class="alert info row gap-12" style="align-items:center"><div class="spin sm"></div><div class="grow"><b>{{ JOB_TYPES[activeJob.type] || activeJob.type }}</b> — {{ activeJob.status === 'queued' ? 'в очереди' : 'выполняется' }}{{ activeJob.progress ? ` ${activeJob.progress}%` : '' }}<div class="meter mt-4" style="max-width:320px"><i :style="{ width: (activeJob.progress || 2) + '%' }"></i></div></div></div>
    <div v-if="video.status !== 'ready'" class="alert info">Видео обрабатывается — редактор станет доступен после окончания обработки.</div>

    <div class="editor-grid">
      <!-- Обрезка -->
      <section class="panel">
        <h4 class="mb-4"><Icon name="scissors" :size="18" style="vertical-align:-3px" /> Обрезать начало и конец</h4>
        <p class="small muted">Оставить только часть видео. Точная перекодировка, главы сдвигаются автоматически.</p>
        <div class="row wrap gap-8 mt-8" style="align-items:center">
          <label class="small">с</label><input class="input sm" style="width:90px" v-model="trim.start" placeholder="0:00" /><button class="ibtn sm" title="Текущее время плеера" @click="trim.start = fmtDuration(now())" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
          <label class="small">по</label><input class="input sm" style="width:90px" v-model="trim.end" /><button class="ibtn sm" title="Текущее время плеера" @click="trim.end = fmtDuration(now())" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
          <button class="btn primary sm" :disabled="!!busy || video.status !== 'ready'" @click="doTrim"><Icon name="scissors" :size="16" /> Обрезать</button>
        </div>
      </section>

      <!-- Вырезание -->
      <section class="panel">
        <h4 class="mb-4"><Icon name="cut" :size="18" style="vertical-align:-3px" /> Вырезать фрагменты</h4>
        <p class="small muted">Удалить один или несколько отрезков из середины (оговорки, паузы на настройку, лишние вопросы).</p>
        <div class="row wrap gap-8 mt-8" style="align-items:center">
          <input class="input sm" style="width:90px" v-model="newCut.start" placeholder="с 1:20" /><button class="ibtn sm" title="Текущее время плеера" @click="newCut.start = fmtDuration(now())" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
          <input class="input sm" style="width:90px" v-model="newCut.end" placeholder="по 1:45" /><button class="ibtn sm" title="Текущее время плеера" @click="newCut.end = fmtDuration(now())" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
          <button class="btn sm" @click="addCut"><Icon name="plus" :size="16" /> Добавить</button>
        </div>
        <div v-if="cuts.length" class="col gap-4 mt-8">
          <div v-for="(c, i) in cuts" :key="i" class="row gap-8 small" style="align-items:center"><a href="#" @click.prevent="seek(c.start)" class="mono">{{ fmtDuration(c.start) }} – {{ fmtDuration(c.end) }}</a><span class="muted">({{ fmtDuration(c.end - c.start) }})</span><button class="ibtn sm" @click="cuts.splice(i, 1)" aria-label="Убрать вырезаемый участок"><Icon name="close" :size="14" /></button></div>
          <div><button class="btn primary sm mt-4" :disabled="!!busy || video.status !== 'ready'" @click="doCut"><Icon name="cut" :size="16" /> Вырезать {{ cuts.length }} фрагм.</button></div>
        </div>
      </section>

      <!-- Паузы -->
      <section class="panel">
        <h4 class="mb-4"><Icon name="waveform" :size="18" style="vertical-align:-3px" /> Удалить паузы</h4>
        <p class="small muted">Автоматически находит тишину в звуковой дорожке (как в Descript) и вырезает её, оставляя короткий отступ по краям.</p>
        <div class="row wrap gap-12 mt-8" style="align-items:flex-end">
          <div class="field"><label>Порог тишины, дБ</label><input class="input sm" type="number" style="width:90px" v-model.number="silence.noiseDb" min="-90" max="0" /></div>
          <div class="field"><label>Пауза от, с</label><input class="input sm" type="number" style="width:90px" v-model.number="silence.minSec" min="0.3" max="30" step="0.1" /></div>
          <div class="field"><label>Отступ, с</label><input class="input sm" type="number" style="width:90px" v-model.number="silence.keepSec" min="0" max="5" step="0.1" /></div>
          <button class="btn sm" :disabled="!!busy || video.status !== 'ready'" @click="detectSilence"><Icon name="search" :size="16" /> Найти паузы</button>
        </div>
        <div v-if="silenceResult" class="mt-8">
          <div class="small" v-if="silenceResult.cuts.length">Найдено пауз: <b>{{ silenceResult.cuts.length }}</b>, можно сократить видео на <b>{{ fmtDuration(silenceResult.removedSec) }}</b> из {{ fmtDuration(silenceResult.duration) }}.</div>
          <div class="small muted" v-else>Пауз длиннее {{ silence.minSec }} с не найдено.</div>
          <div v-if="silenceResult.cuts.length" class="row wrap gap-4 mt-4"><a v-for="(c, i) in silenceResult.cuts.slice(0, 40)" :key="i" href="#" class="chip" @click.prevent="seek(Math.max(0, c.start - 1))">{{ fmtDuration(c.start) }}–{{ fmtDuration(c.end) }}</a><span v-if="silenceResult.cuts.length > 40" class="chip">…</span></div>
          <button v-if="silenceResult.cuts.length" class="btn primary sm mt-8" :disabled="!!busy" @click="applySilence"><Icon name="mute" :size="16" /> Удалить паузы</button>
        </div>
      </section>

      <!-- Клипы -->
      <section class="panel">
        <h4 class="mb-4"><Icon name="movieEdit" :size="18" style="vertical-align:-3px" /> Клип как отдельное видео</h4>
        <p class="small muted">Сохранить фрагмент как новое видео — например, вертикальный ролик для ленты «Короткие» (как клипы на Twitch и «сделать Short» на YouTube).</p>
        <div class="row wrap gap-8 mt-8" style="align-items:center">
          <label class="small">с</label><input class="input sm" style="width:90px" v-model="clip.start" /><button class="ibtn sm" title="Текущее время плеера" @click="clip.start = fmtDuration(now())" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
          <label class="small">по</label><input class="input sm" style="width:90px" v-model="clip.end" /><button class="ibtn sm" title="Текущее время плеера" @click="clip.end = fmtDuration(now())" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
        </div>
        <div class="row wrap gap-8 mt-8" style="align-items:center">
          <input class="input sm grow" v-model="clip.title" maxlength="150" placeholder="Название клипа (необязательно)" style="min-width:200px" />
          <select class="select sm" v-model="clip.visibility" style="width:170px"><option value="private">Приватное</option><option value="unlisted">По ссылке</option><option value="internal">Для сотрудников</option><option value="public">Публичное</option></select>
          <label class="switch"><input type="checkbox" v-model="clip.vertical" /><span class="track"></span><span>Вертикальный 9:16 (для ленты коротких)</span></label>
          <button class="btn primary sm" :disabled="!!busy || video.status !== 'ready'" @click="makeClip"><Icon name="shorts" :size="16" /> Создать клип</button>
        </div>
        <!-- Предложения ИИ (1.7) -->
        <div v-if="sugg.enabled && auth.config?.clipsAi !== false" class="ai-clips mt-12">
          <div class="row gap-8" style="align-items:center">
            <b class="small grow"><Icon name="sparkles" :size="16" style="vertical-align:-3px" /> ИИ предложит фрагменты</b>
            <button class="btn sm" :disabled="suggBusy || video.status !== 'ready' || duration < 60" @click="suggestClips">
              <template v-if="suggBusy"><span class="spin sm"></span> Подбираю…</template>
              <template v-else><Icon name="sparkles" :size="16" /> {{ sugg.suggestions.length ? 'Подобрать заново' : 'Предложить клипы' }}</template>
            </button>
          </div>
          <p v-if="duration < 60" class="tiny muted mt-4">Для видео короче минуты предложения не нужны.</p>
          <p v-else class="tiny muted mt-4">Портал читает расшифровку и находит законченные фрагменты: инструкцию, объяснение, объявление — как OpusClip и Vizard.</p>
          <div v-for="x in sugg.suggestions" :key="x.id" class="ai-clip">
            <button class="ai-clip-at" @click="player?.seekTo?.(x.start)">{{ fmtDuration(x.start) }}–{{ fmtDuration(x.end) }}</button>
            <div class="grow"><b class="small">{{ x.title }}</b><div class="tiny muted">{{ x.reason }}</div></div>
            <span class="tiny muted nowrap">{{ x.score }}%</span>
            <button class="btn sm soft" @click="useSuggestion(x)"><Icon name="scissors" :size="16" /> В клип</button>
            <button class="ibtn sm" title="Убрать" @click="dismissSuggestion(x)" aria-label="Убрать"><Icon name="close" :size="16" /></button>
          </div>
        </div>
        <div v-if="state.clips.length" class="mt-12"><div class="label mb-8">Клипы из этого видео</div><div class="clip-list"><VideoCard v-for="c in state.clips" :key="c.id" :video="c" :show-status="true" /></div></div>
      </section>
    </div>

    <!-- OCR -->
    <section class="panel" v-if="state.ocr.enabled || state.ocr.status">
      <h4 class="mb-4"><Icon name="ocr" :size="18" style="vertical-align:-3px" /> Текст на экране (OCR)</h4>
      <p class="small muted">Распознаёт надписи на слайдах и в демонстрациях программ: текст попадает в поиск и на страницу видео (панель «Текст на экране»), как в Panopto и Mediasite.</p>
      <div class="row wrap gap-8 mt-8" style="align-items:center">
        <span class="badge" :class="state.ocr.status === 'done' ? 'success' : state.ocr.status === 'failed' ? 'danger' : state.ocr.status ? 'warning' : ''">{{ { done: 'Распознано', failed: 'Ошибка', queued: 'В очереди', processing: 'Распознавание…' }[state.ocr.status] || 'Не выполнялось' }}</span>
        <span v-if="state.ocr.at" class="small muted">{{ fmtDateTime(state.ocr.at) }}</span>
        <button v-if="state.ocr.enabled" class="btn sm" :disabled="!!busy || video.status !== 'ready'" @click="runOcr"><Icon name="ocr" :size="16" /> {{ state.ocr.status === 'done' ? 'Распознать заново' : 'Распознать текст' }}</button>
        <router-link v-if="state.ocr.status === 'done'" :to="`/watch/${video.shortId}?panel=screen`" class="btn ghost sm">Открыть на странице видео</router-link>
      </div>
    </section>

    <!-- История -->
    <section class="panel" v-if="state.history.length || state.jobs.length">
      <h4 class="mb-8"><Icon name="history" :size="18" style="vertical-align:-3px" /> История монтажа</h4>
      <div class="table-wrap"><table class="table"><thead><tr><th>Когда</th><th>Операция</th><th>Результат</th></tr></thead><tbody>
        <tr v-for="(h, i) in [...state.history].reverse()" :key="'h' + i"><td class="small muted">{{ fmtDateTime(h.at) }}</td><td>{{ OPS[h.op] || h.op }}</td><td class="small">удалено {{ fmtDuration(h.removedSec || 0) }}<span v-if="h.keep?.length"> · оставлено фрагментов: {{ h.keep.length }}</span></td></tr>
        <tr v-for="j in state.jobs.filter((x) => x.status !== 'done')" :key="'j' + j.id"><td class="small muted">{{ fmtDateTime(j.createdAt) }}</td><td>{{ JOB_TYPES[j.type] || j.type }}</td><td class="small"><span class="badge" :class="j.status === 'failed' ? 'danger' : 'warning'">{{ { queued: 'в очереди', running: 'выполняется', failed: 'ошибка', cancelled: 'отменено' }[j.status] }}</span> <span v-if="j.error" class="muted">{{ j.error }}</span></td></tr>
      </tbody></table></div>
    </section>
  </div>
</template>

<style>
.ai-clips { border-top: 1px dashed var(--line-2); padding-top: 12px; }
.ai-clip { display: flex; gap: 8px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--line-2); }
.ai-clip:last-child { border-bottom: 0; }
.ai-clip-at { color: var(--brand); font-variant-numeric: tabular-nums; font-size: 13px; white-space: nowrap; }

.editor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; }
.editor-grid .panel { margin: 0; }
.clip-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.spin.sm { width: 18px; height: 18px; border-width: 2px; }
</style>
