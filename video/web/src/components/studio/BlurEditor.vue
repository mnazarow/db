<script setup>
// Размытие лиц и областей в кадре (как инструмент размытия YouTube Studio):
// рамка рисуется мышью прямо на кадре, у каждой — свой отрезок времени и сила размытия.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { fmtDuration, parseTime } from '../../utils/format.js';
import { url } from '../../base.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['changed']);
const ui = useUi();
const auth = useAuth();
const regions = ref([]);
const at = ref(0);                 // момент кадра
const frameUrl = ref('');
const box = ref(null);             // рисуемая рамка
const busy = ref('');
const activeJob = ref(null);
const state = ref(null);
const stage = ref(null);
let timer = null;
let drag = null;

const duration = computed(() => Number(props.video.duration) || 0);
const canBlur = computed(() => auth.config?.blurEnabled !== false && state.value?.blur?.enabled !== false);
const canFaces = computed(() => auth.config?.facesEnabled !== false && state.value?.blur?.faces !== false);

function frameAt(sec) {
  at.value = Math.max(0, Math.min(duration.value, Math.round(sec * 10) / 10));
  frameUrl.value = url(`/api/videos/${props.video.id}/editor/frame?t=${at.value}`);
}
async function load() {
  try { state.value = await get(`/api/videos/${props.video.id}/editor`); } catch { /* ignore */ }
  frameAt(Math.min(duration.value / 3, 5));
}
onMounted(load);
onBeforeUnmount(() => clearInterval(timer));

function rel(e) {
  const r = stage.value.getBoundingClientRect();
  return { x: Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), y: Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)) };
}
function down(e) {
  if (!canBlur.value) return;
  e.preventDefault();
  const p = rel(e);
  drag = { x0: p.x, y0: p.y };
  box.value = { x: p.x, y: p.y, w: 0, h: 0 };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up, { once: true });
}
function move(e) {
  if (!drag) return;
  const p = rel(e);
  box.value = { x: Math.min(drag.x0, p.x), y: Math.min(drag.y0, p.y), w: Math.abs(p.x - drag.x0), h: Math.abs(p.y - drag.y0) };
}
function up() {
  window.removeEventListener('pointermove', move);
  drag = null;
  if (box.value && (box.value.w < 0.02 || box.value.h < 0.02)) box.value = null;
}
function addRegion() {
  if (!box.value) return ui.toast('Обведите область на кадре', { type: 'error' });
  const start = Math.max(0, at.value - 1);
  regions.value.push({ ...box.value, start, end: Math.min(duration.value, at.value + 5), strength: state.value?.blur?.strength || 22, startText: fmtDuration(start), endText: fmtDuration(Math.min(duration.value, at.value + 5)) });
  box.value = null;
}
function removeRegion(i) { regions.value.splice(i, 1); }
function syncTimes(r) {
  const s = parseTime(r.startText), e = parseTime(r.endText);
  if (s !== null) r.start = Math.max(0, Math.min(duration.value, s));
  if (e !== null) r.end = Math.max(0, Math.min(duration.value, e));
}
function showRegion(r) { frameAt((Number(r.start) + Number(r.end)) / 2); props.player?.seekTo?.(Number(r.start)); }
function fromPlayer() { const t = props.player?.currentTime?.(); if (t != null) frameAt(t); }

function track(jobId, kind) {
  clearInterval(timer);
  timer = setInterval(async () => {
    try {
      const { job } = await get(`/api/videos/${props.video.id}/editor/jobs/${jobId}`);
      activeJob.value = job;
      if (['done', 'failed', 'cancelled'].includes(job.status)) {
        clearInterval(timer);
        busy.value = '';
        if (job.status !== 'done') return ui.toast(job.error || 'Задание не выполнено', { type: 'error', timeout: 8000 });
        if (kind === 'faces') {
          const found = (job.result?.regions || []).map((r) => ({ ...r, startText: fmtDuration(r.start), endText: fmtDuration(r.end) }));
          if (!found.length) ui.toast('Лица не найдены — обведите области вручную', { type: 'warning' });
          else { regions.value = [...regions.value, ...found]; ui.toast(`Найдено лиц: ${found.length} — проверьте рамки и примените размытие`, { type: 'success' }); }
        } else {
          ui.toast('Размытие применено — видео обрабатывается заново', { type: 'success' });
          regions.value = [];
          emit('changed');
        }
      }
    } catch { /* ignore */ }
  }, 2000);
}

async function findFaces() {
  if (busy.value) return;
  busy.value = 'faces';
  try { const r = await post(`/api/videos/${props.video.id}/editor/faces`, {}); ui.toast('Ищем лица в записи…'); track(r.jobId, 'faces'); }
  catch (e) { busy.value = ''; ui.toast(e.message, { type: 'error' }); }
}
async function apply() {
  if (!regions.value.length || busy.value) return;
  if (!(await ui.ask({ title: 'Применить размытие?', message: `Будет размыто областей: ${regions.value.length}. Видео пересоберётся как новая версия — ссылка, просмотры и комментарии сохранятся.`, okLabel: 'Размыть' }))) return;
  busy.value = 'blur';
  try {
    const payload = regions.value.map((r) => ({ x: r.x, y: r.y, w: r.w, h: r.h, start: Number(r.start) || 0, end: Number(r.end) || null, strength: r.strength }));
    const r = await post(`/api/videos/${props.video.id}/editor/blur`, { regions: payload });
    ui.toast('Размытие поставлено в очередь');
    track(r.jobId, 'blur');
  } catch (e) { busy.value = ''; ui.toast(e.message, { type: 'error' }); }
}
</script>

<template>
  <div class="col gap-16">
    <div class="panel">
      <div class="row wrap gap-8" style="align-items:center">
        <h3 class="grow">Размытие лиц и областей</h3>
        <button v-if="canFaces" class="btn sm" :disabled="!!busy" @click="findFaces"><Icon name="person" :size="16" /> Найти лица</button>
      </div>
      <p class="small muted">Обведите на кадре то, что нужно закрыть: лицо коллеги, номер договора, экран с личными данными. Для каждой рамки задаётся отрезок времени — пока идёт этот отрезок, область размыта.</p>
      <div v-if="!canBlur" class="alert warning mt-8"><Icon name="alertCircle" :size="20" /><span>Размытие отключено администратором.</span></div>
      <template v-else>
        <div class="row wrap gap-8 mt-8" style="align-items:center">
          <label class="small muted">Кадр на</label>
          <input class="input" style="width:110px" :value="fmtDuration(at)" @change="frameAt(parseTime($event.target.value) ?? at)" />
          <input class="range grow" type="range" min="0" :max="Math.max(1, duration)" step="0.5" :value="at" @input="frameAt(Number($event.target.value))" />
          <button class="btn ghost sm" @click="fromPlayer">С текущего места плеера</button>
        </div>
        <div ref="stage" class="blur-stage mt-8" @pointerdown="down">
          <img :src="frameUrl" alt="Кадр видео" draggable="false" />
          <div v-for="(r, i) in regions" :key="i" class="blur-rect saved" :style="{ left: r.x * 100 + '%', top: r.y * 100 + '%', width: r.w * 100 + '%', height: r.h * 100 + '%', opacity: at >= r.start && at <= r.end ? 1 : 0.35 }"><span class="tiny">{{ i + 1 }}</span></div>
          <div v-if="box" class="blur-rect draft" :style="{ left: box.x * 100 + '%', top: box.y * 100 + '%', width: box.w * 100 + '%', height: box.h * 100 + '%' }"></div>
        </div>
        <div class="row gap-8 mt-8">
          <button class="btn sm" :disabled="!box" @click="addRegion"><Icon name="plus" :size="16" /> Добавить область</button>
          <span class="small muted">Нажмите на кадр и протяните рамку мышью</span>
        </div>
      </template>
    </div>

    <div v-if="regions.length" class="panel">
      <h4 class="mb-8">Области размытия ({{ regions.length }})</h4>
      <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>С</th><th>По</th><th>Сила</th><th></th></tr></thead><tbody>
        <tr v-for="(r, i) in regions" :key="i">
          <td>{{ i + 1 }}</td>
          <td><input class="input sm" style="width:90px" v-model="r.startText" @change="syncTimes(r)" /></td>
          <td><input class="input sm" style="width:90px" v-model="r.endText" @change="syncTimes(r)" /></td>
          <td><input class="range" type="range" min="6" max="50" v-model.number="r.strength" style="width:120px" /> <span class="tiny muted">{{ r.strength }}</span></td>
          <td class="actions nowrap"><button class="ibtn sm" title="Показать кадр" @click="showRegion(r)" aria-label="Показать кадр"><Icon name="eye" :size="16" /></button><button class="ibtn sm" title="Убрать" @click="removeRegion(i)" aria-label="Убрать"><Icon name="delete" :size="16" /></button></td>
        </tr>
      </tbody></table></div>
      <div class="form-actions">
        <button class="btn primary" :disabled="!!busy" @click="apply"><Icon name="eyeOff" :size="16" /> Применить размытие</button>
        <button class="btn ghost" @click="regions = []">Очистить</button>
      </div>
    </div>

    <div v-if="activeJob && ['queued','running'].includes(activeJob.status)" class="panel soft">
      <div class="row gap-8" style="align-items:center"><div class="spin sm"></div><span class="grow">{{ activeJob.type === 'face_detect' ? 'Ищем лица в записи' : 'Размываем и пересобираем видео' }} — {{ activeJob.progress || 0 }}%</span></div>
      <div class="progress mt-8"><div class="bar" :style="{ width: (activeJob.progress || 0) + '%' }"></div></div>
    </div>

    <div v-if="(video.blurRegions || []).length" class="panel soft">
      <div class="small muted">К этому видео уже применялось размытие: областей — {{ video.blurRegions.length }}. Исходный файл, если он сохранён, остался без изменений.</div>
    </div>
  </div>
</template>

<style scoped>
.blur-stage { position: relative; display: inline-block; max-width: 100%; line-height: 0; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); cursor: crosshair; touch-action: none; background: #000; }
.blur-stage img { max-width: 100%; height: auto; display: block; user-select: none; }
.blur-rect { position: absolute; border: 2px solid var(--brand); background: rgba(0, 0, 0, 0.45); backdrop-filter: blur(4px); }
.blur-rect.draft { border-style: dashed; background: rgba(0, 0, 0, 0.25); }
.blur-rect span { position: absolute; top: 2px; left: 4px; color: #fff; font-weight: 700; }
</style>
