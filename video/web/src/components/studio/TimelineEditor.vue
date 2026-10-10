<script setup>
// Графический видеоредактор: многодорожечный таймлайн (видео, титры, картинки, музыка),
// живой предпросмотр с наложениями и сборка готового ролика на сервере.
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { get, put, post, del, uploadFile } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDuration, parseTime } from '../../utils/format.js';
import { url } from '../../base.js';

const props = defineProps({ video: { type: Object, required: true } });
const emit = defineEmits(['changed']);
const ui = useUi();

const state = ref(null);            // ответ /project
const project = ref(null);          // редактируемый проект
const loading = ref(true);
const saving = ref(false);
const dirty = ref(false);
const zoom = ref(60);               // пикселей на секунду * 10
const cursor = ref(0);              // положение курсора на таймлайне (секунды готового ролика)
const playing = ref(false);
const sel = ref(null);              // { track: 'clip'|'text'|'image'|'audio', id }
const videoEl = ref(null);
const audioEl = ref(null);
const stage = ref(null);
const serverFrame = ref('');
const rendering = ref(false);
const activeJob = ref(null);
let saveTimer = null; let raf = null; let jobTimer = null;

const ASPECT_LABELS = { source: 'Как в исходнике', '16:9': 'Широкий 16:9', '9:16': 'Вертикальный 9:16', '1:1': 'Квадрат 1:1', '4:5': 'Лента 4:5' };
const TRANSITIONS = [['none', 'Встык'], ['dissolve', 'Растворение'], ['fadeblack', 'Через чёрный'], ['slide', 'Сдвиг'], ['wipe', 'Шторка']];
const px = (sec) => sec * (zoom.value / 10);
const secFromPx = (p) => p / (zoom.value / 10);

const srcDuration = computed(() => Number(state.value?.duration || props.video.duration) || 0);
const clipLen = (c) => (c.end - c.start) / (c.speed || 1);
const outDuration = computed(() => {
  if (!project.value) return 0;
  const sum = project.value.clips.reduce((n, c) => n + clipLen(c), 0);
  const tr = project.value.clips.slice(0, -1).reduce((n, c) => n + (c.transition?.type !== 'none' ? c.transition.duration : 0), 0);
  return Math.max(0, Math.round((sum - tr) * 100) / 100);
});
/** Начало каждого куска на итоговой шкале. */
const clipStarts = computed(() => {
  const out = []; let acc = 0;
  (project.value?.clips || []).forEach((c, i) => {
    const prev = i > 0 ? project.value.clips[i - 1] : null;
    if (prev && prev.transition?.type !== 'none') acc -= prev.transition.duration;
    out.push(acc);
    acc += clipLen(c);
  });
  return out;
});
const selected = computed(() => {
  if (!sel.value || !project.value) return null;
  const list = { clip: project.value.clips, text: project.value.texts, image: project.value.images, audio: project.value.audio }[sel.value.track] || [];
  return list.find((x) => x.id === sel.value.id) || null;
});
const assetOf = (id) => (state.value?.assets || []).find((a) => a.id === id);
const images = computed(() => (state.value?.assets || []).filter((a) => a.kind === 'image'));
const sounds = computed(() => (state.value?.assets || []).filter((a) => a.kind === 'audio'));

async function load() {
  loading.value = true;
  try {
    state.value = await get(`/api/videos/${props.video.id}/project`);
    project.value = JSON.parse(JSON.stringify(state.value.project));
    dirty.value = false;
    const run = state.value.jobs?.find((j) => ['queued', 'running'].includes(j.status));
    if (run) trackJob(run.id);
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);
onBeforeUnmount(() => { clearTimeout(saveTimer); cancelAnimationFrame(raf); clearInterval(jobTimer); });

watch(project, () => { if (!loading.value) { dirty.value = true; scheduleSave(); } }, { deep: true });
function scheduleSave() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(save, 1200);
}
async function save() {
  if (!project.value) return;
  saving.value = true;
  try { const r = await put(`/api/videos/${props.video.id}/project`, { project: project.value }); dirty.value = false; state.value.outputDuration = r.outputDuration; }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}

// --- Предпросмотр ---------------------------------------------------------------------------
/** Курсор итогового ролика → место в исходнике. */
function mapCursor(t) {
  const clips = project.value?.clips || [];
  for (let i = 0; i < clips.length; i++) {
    const s = clipStarts.value[i], len = clipLen(clips[i]);
    if (t < s + len || i === clips.length - 1) {
      const within = Math.max(0, Math.min(len, t - s));
      return { index: i, clip: clips[i], source: clips[i].start + within * (clips[i].speed || 1) };
    }
  }
  return { index: 0, clip: clips[0], source: clips[0]?.start || 0 };
}
function seek(t) {
  cursor.value = Math.max(0, Math.min(outDuration.value, t));
  const m = mapCursor(cursor.value);
  if (videoEl.value && m.clip) {
    videoEl.value.currentTime = m.source;
    videoEl.value.playbackRate = m.clip.speed || 1;
  }
}
function togglePlay() {
  playing.value = !playing.value;
  if (playing.value) {
    seek(cursor.value >= outDuration.value - 0.1 ? 0 : cursor.value);
    videoEl.value?.play?.().catch(() => {});
    const mus = project.value.audio[0];
    if (mus && audioEl.value) { audioEl.value.volume = Math.min(1, mus.volume); audioEl.value.currentTime = mus.offset || 0; audioEl.value.play().catch(() => {}); }
    tick();
  } else {
    videoEl.value?.pause?.();
    audioEl.value?.pause?.();
    cancelAnimationFrame(raf);
  }
}
function tick() {
  cancelAnimationFrame(raf);
  raf = requestAnimationFrame(() => {
    if (!playing.value || !videoEl.value) return;
    const m = mapCursor(cursor.value);
    const clips = project.value.clips;
    const i = m.index;
    const cur = videoEl.value.currentTime;
    const c = clips[i];
    if (c && cur >= c.end - 0.05) {
      if (i + 1 < clips.length) { cursor.value = clipStarts.value[i + 1] + 0.01; seek(cursor.value); }
      else { playing.value = false; videoEl.value.pause(); audioEl.value?.pause(); return; }
    } else if (c) {
      cursor.value = Math.min(outDuration.value, clipStarts.value[i] + (cur - c.start) / (c.speed || 1));
    }
    tick();
  });
}
const activeTexts = computed(() => (project.value?.texts || []).filter((t) => cursor.value >= t.start && cursor.value <= t.end));
const activeImages = computed(() => (project.value?.images || []).filter((im) => cursor.value >= im.start && cursor.value <= im.end));
const previewRatio = computed(() => {
  const w = props.video.width || 1280, h = props.video.height || 720;
  const a = project.value?.aspect || 'source';
  if (a === 'source') return w / h;
  return { '16:9': 16 / 9, '9:16': 9 / 16, '1:1': 1, '4:5': 4 / 5 }[a] || w / h;
});
async function serverPreview() {
  try {
    await save();
    serverFrame.value = url(`/api/videos/${props.video.id}/project/preview?t=${cursor.value.toFixed(2)}&_=${Date.now()}`);
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

// --- Операции с кусками ----------------------------------------------------------------------
function splitAtCursor() {
  const m = mapCursor(cursor.value);
  const c = m.clip;
  if (!c || m.source - c.start < 0.4 || c.end - m.source < 0.4) return ui.toast('Поставьте курсор внутри фрагмента', { type: 'error' });
  const idx = project.value.clips.indexOf(c);
  const right = { ...c, id: `c${Date.now().toString(36)}`, start: Math.round(m.source * 100) / 100, transition: { ...c.transition } };
  project.value.clips.splice(idx + 1, 0, right);
  c.end = right.start;
  c.transition = { type: 'none', duration: 0 };
  sel.value = { track: 'clip', id: right.id };
}
function removeSel() {
  if (!selected.value) return;
  const t = sel.value.track;
  if (t === 'clip') {
    if (project.value.clips.length <= 1) return ui.toast('Должен остаться хотя бы один фрагмент', { type: 'error' });
    project.value.clips = project.value.clips.filter((c) => c.id !== sel.value.id);
  } else if (t === 'text') project.value.texts = project.value.texts.filter((x) => x.id !== sel.value.id);
  else if (t === 'image') project.value.images = project.value.images.filter((x) => x.id !== sel.value.id);
  else if (t === 'audio') project.value.audio = project.value.audio.filter((x) => x.id !== sel.value.id);
  sel.value = null;
}
function duplicateSel() {
  if (!selected.value) return;
  const copy = JSON.parse(JSON.stringify(selected.value));
  copy.id = `${sel.value.track[0]}${Date.now().toString(36)}`;
  const list = { clip: 'clips', text: 'texts', image: 'images', audio: 'audio' }[sel.value.track];
  const arr = project.value[list];
  arr.splice(arr.indexOf(selected.value) + 1, 0, copy);
  sel.value = { track: sel.value.track, id: copy.id };
}
function moveClip(dir) {
  const i = project.value.clips.findIndex((c) => c.id === sel.value?.id);
  const j = i + dir;
  if (i < 0 || j < 0 || j >= project.value.clips.length) return;
  const arr = project.value.clips;
  [arr[i], arr[j]] = [arr[j], arr[i]];
}
function addText() {
  const t = { id: `t${Date.now().toString(36)}`, text: 'Новый титр', start: Math.round(cursor.value * 10) / 10, end: Math.min(outDuration.value, cursor.value + 4), x: 0.5, y: 0.82, size: 48, color: '#ffffff', bg: '#000000', bgOpacity: 0.55, fade: 0.3 };
  project.value.texts.push(t);
  sel.value = { track: 'text', id: t.id };
}
function addImage(asset) {
  const im = { id: `i${Date.now().toString(36)}`, assetId: asset.id, start: Math.round(cursor.value * 10) / 10, end: Math.min(outDuration.value, cursor.value + 8), x: 0.94, y: 0.06, width: 0.18, opacity: 1 };
  project.value.images.push(im);
  sel.value = { track: 'image', id: im.id };
}
function addAudio(asset) {
  const len = Math.min(asset.duration || 30, outDuration.value);
  const a = { id: `a${Date.now().toString(36)}`, assetId: asset.id, start: 0, end: Math.round(len * 10) / 10, volume: 0.3, fadeIn: 1, fadeOut: 2, offset: 0 };
  project.value.audio.push(a);
  sel.value = { track: 'audio', id: a.id };
}
async function upload(e, kind) {
  const f = e.target.files?.[0]; if (!f) return;
  try {
    const r = await uploadFile(`/api/videos/${props.video.id}/project/assets`, f);
    state.value.assets.push(r.asset);
    if (r.asset.kind === 'image') addImage(r.asset); else addAudio(r.asset);
    ui.toast('Файл добавлен в проект', { type: 'success' });
  } catch (err) { ui.toast(err.message, { type: 'error' }); }
  e.target.value = '';
}
async function removeAsset(a) {
  if (!(await ui.ask({ title: `Удалить «${a.name}» из проекта?`, okLabel: 'Удалить', danger: true }))) return;
  try {
    await del(`/api/videos/${props.video.id}/project/assets/${a.id}`);
    state.value.assets = state.value.assets.filter((x) => x.id !== a.id);
    project.value.images = project.value.images.filter((x) => x.assetId !== a.id);
    project.value.audio = project.value.audio.filter((x) => x.assetId !== a.id);
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

// --- Перетаскивание и обрезка на таймлайне ----------------------------------------------------
let drag = null;
function startDrag(e, track, item, mode) {
  e.preventDefault(); e.stopPropagation();
  sel.value = { track, id: item.id };
  drag = { track, item, mode, x0: e.clientX, snap: JSON.parse(JSON.stringify(item)) };
  window.addEventListener('pointermove', onDrag);
  window.addEventListener('pointerup', endDrag, { once: true });
}
function onDrag(e) {
  if (!drag) return;
  const d = secFromPx(e.clientX - drag.x0);
  const it = drag.item, s = drag.snap;
  if (drag.track === 'clip') {
    if (drag.mode === 'left') it.start = Math.max(0, Math.min(s.end - 0.3, s.start + d * (s.speed || 1)));
    else if (drag.mode === 'right') it.end = Math.min(srcDuration.value, Math.max(s.start + 0.3, s.end + d * (s.speed || 1)));
  } else {
    const len = s.end - s.start;
    if (drag.mode === 'move') { it.start = Math.max(0, Math.min(outDuration.value - len, s.start + d)); it.end = it.start + len; }
    else if (drag.mode === 'left') it.start = Math.max(0, Math.min(s.end - 0.3, s.start + d));
    else if (drag.mode === 'right') it.end = Math.min(outDuration.value, Math.max(s.start + 0.3, s.end + d));
  }
  it.start = Math.round(it.start * 100) / 100;
  it.end = Math.round(it.end * 100) / 100;
}
function endDrag() { window.removeEventListener('pointermove', onDrag); drag = null; scheduleSave(); }
function rulerClick(e) {
  const box = e.currentTarget.getBoundingClientRect();
  seek(secFromPx(e.clientX - box.left + e.currentTarget.scrollLeft));
}

// --- Сборка -----------------------------------------------------------------------------------
async function render(output) {
  await save();
  const msg = output === 'new'
    ? 'Портал соберёт отдельное видео и положит его в вашу студию. Исходник останется как есть.'
    : 'Портал пересоберёт это видео: ссылка, просмотры и комментарии сохранятся, файл заменится смонтированной версией.';
  if (!(await ui.ask({ title: output === 'new' ? 'Собрать в новое видео?' : 'Собрать поверх этого видео?', message: `${msg} Длительность результата — ${fmtDuration(outDuration.value)}.`, okLabel: 'Собрать' }))) return;
  rendering.value = true;
  try {
    const r = await post(`/api/videos/${props.video.id}/project/render`, { output, title: `${props.video.title} — монтаж`, visibility: 'private' });
    ui.toast('Сборка запущена');
    trackJob(r.jobId);
  } catch (e) { rendering.value = false; ui.toast(e.message, { type: 'error' }); }
}
function trackJob(id) {
  rendering.value = true;
  clearInterval(jobTimer);
  jobTimer = setInterval(async () => {
    try {
      const { job } = await get(`/api/videos/${props.video.id}/editor/jobs/${id}`);
      activeJob.value = job;
      if (['done', 'failed', 'cancelled'].includes(job.status)) {
        clearInterval(jobTimer); rendering.value = false;
        if (job.status === 'done') {
          ui.toast(job.result?.output === 'new' ? 'Готово: смонтированное видео появилось в студии' : 'Готово: видео пересобрано', { type: 'success', timeout: 8000 });
          emit('changed');
          await load();
        } else ui.toast(job.error || 'Сборка не удалась', { type: 'error', timeout: 9000 });
      }
    } catch { /* ignore */ }
  }, 2000);
}
</script>

<template>
  <div v-if="loading" class="loading-block"><div class="spin"></div></div>
  <div v-else-if="project" class="tl-root">
    <!-- Предпросмотр -->
    <div class="tl-top">
      <div class="tl-preview">
        <div ref="stage" class="pv-stage" :style="{ aspectRatio: previewRatio }">
          <video ref="videoEl" :src="video.mp4Url || video.hlsUrl" preload="metadata" muted playsinline class="pv-video" @loadedmetadata="seek(0)"></video>
          <img v-if="serverFrame" :src="serverFrame" class="pv-frame" alt="Кадр с сервера" />
          <div v-for="im in activeImages" :key="im.id" class="pv-img" :style="{ left: im.x * 100 + '%', top: im.y * 100 + '%', width: im.width * 100 + '%', transform: `translate(-${im.x * 100}%, -${im.y * 100}%)`, opacity: im.opacity }">
            <img :src="assetOf(im.assetId)?.url" alt="" />
          </div>
          <div v-for="t in activeTexts" :key="t.id" class="pv-text" :style="{ left: t.x * 100 + '%', top: t.y * 100 + '%', transform: `translate(-${t.x * 100}%, -${t.y * 100}%)`, fontSize: `calc(${t.size / (video.height || 720) * 100} * 1cqh / 100 * 100)`, color: t.color, background: t.bg ? `${t.bg}${Math.round(t.bgOpacity * 255).toString(16).padStart(2, '0')}` : 'transparent' }">{{ t.text }}</div>
        </div>
        <div class="row gap-8 mt-8" style="align-items:center">
          <button class="ibtn" @click="togglePlay" :aria-label="playing ? 'Пауза' : 'Воспроизвести'"><Icon :name="playing ? 'pause' : 'play'" :size="20" /></button>
          <span class="mono small">{{ fmtDuration(cursor) }} / {{ fmtDuration(outDuration) }}</span>
          <button class="btn ghost sm" @click="serverPreview"><Icon name="image" :size="16" /> Точный кадр</button>
          <button v-if="serverFrame" class="btn ghost sm" @click="serverFrame = ''">Убрать кадр</button>
          <span class="grow"></span>
          <span class="tiny muted">{{ saving ? 'Сохраняем…' : dirty ? 'Есть изменения' : 'Проект сохранён' }}</span>
        </div>
      </div>

      <!-- Свойства выбранного элемента -->
      <div class="tl-props panel">
        <template v-if="!selected">
          <h4>Проект</h4>
          <div class="field"><label>Формат кадра</label><select class="select" v-model="project.aspect"><option v-for="(l, k) in ASPECT_LABELS" :key="k" :value="k">{{ l }}</option></select></div>
          <div class="field"><label>Громкость основного звука</label><input class="range" type="range" min="0" max="2" step="0.05" v-model.number="project.mainVolume" /><span class="tiny muted">{{ Math.round(project.mainVolume * 100) }}%</span></div>
          <div class="row gap-8">
            <div class="field grow"><label>Появление, с</label><input class="input sm" type="number" min="0" max="5" step="0.1" v-model.number="project.fadeIn" /></div>
            <div class="field grow"><label>Затухание, с</label><input class="input sm" type="number" min="0" max="5" step="0.1" v-model.number="project.fadeOut" /></div>
          </div>
          <label class="switch mt-8"><input type="checkbox" v-model="project.burnSubtitles" /><span class="track"></span><span>Вжечь субтитры в кадр</span></label>
          <select v-if="project.burnSubtitles && state.subtitles.length > 1" class="select mt-8" v-model="project.subtitleId"><option :value="null">По умолчанию</option><option v-for="s in state.subtitles" :key="s.id" :value="s.id">{{ s.label }}</option></select>
          <p class="tiny muted mt-8">Выберите элемент на таймлайне, чтобы настроить его.</p>
        </template>

        <template v-else-if="sel.track === 'clip'">
          <h4>Фрагмент</h4>
          <div class="row gap-8">
            <div class="field grow"><label>С (в исходнике)</label><input class="input sm" :value="fmtDuration(selected.start)" @change="selected.start = Math.min(selected.end - 0.3, parseTime($event.target.value) ?? selected.start)" /></div>
            <div class="field grow"><label>По</label><input class="input sm" :value="fmtDuration(selected.end)" @change="selected.end = Math.max(selected.start + 0.3, parseTime($event.target.value) ?? selected.end)" /></div>
          </div>
          <div class="field"><label>Скорость: {{ selected.speed }}×</label><input class="range" type="range" min="0.5" max="2" step="0.05" v-model.number="selected.speed" /></div>
          <div class="field"><label>Громкость: {{ Math.round(selected.volume * 100) }}%</label><input class="range" type="range" min="0" max="2" step="0.05" v-model.number="selected.volume" /></div>
          <div class="field"><label>Переход к следующему</label>
            <select class="select" v-model="selected.transition.type"><option v-for="[k, l] in TRANSITIONS" :key="k" :value="k">{{ l }}</option></select>
            <input v-if="selected.transition.type !== 'none'" class="input sm mt-4" type="number" min="0.1" max="3" step="0.1" v-model.number="selected.transition.duration" />
          </div>
          <div class="row gap-4 mt-8"><button class="btn sm" @click="moveClip(-1)" aria-label="Сдвинуть фрагмент раньше"><Icon name="chevronLeft" :size="16" /></button><button class="btn sm" @click="moveClip(1)" aria-label="Сдвинуть фрагмент позже"><Icon name="chevronRight" :size="16" /></button><button class="btn sm" @click="duplicateSel" aria-label="Дублировать фрагмент"><Icon name="copy" :size="16" /></button><button class="btn sm danger" @click="removeSel" aria-label="Удалить фрагмент"><Icon name="delete" :size="16" /></button></div>
        </template>

        <template v-else-if="sel.track === 'text'">
          <h4>Титр</h4>
          <div class="field"><label>Текст</label><textarea class="textarea" rows="3" v-model="selected.text"></textarea></div>
          <div class="row gap-8">
            <div class="field grow"><label>Размер</label><input class="input sm" type="number" min="10" max="200" v-model.number="selected.size" /></div>
            <div class="field grow"><label>Цвет</label><input class="input sm" type="color" v-model="selected.color" /></div>
          </div>
          <div class="row gap-8">
            <div class="field grow"><label>По горизонтали</label><input class="range" type="range" min="0" max="1" step="0.01" v-model.number="selected.x" /></div>
            <div class="field grow"><label>По вертикали</label><input class="range" type="range" min="0" max="1" step="0.01" v-model.number="selected.y" /></div>
          </div>
          <div class="field"><label class="switch"><input type="checkbox" :checked="!!selected.bg" @change="selected.bg = $event.target.checked ? '#000000' : null" /><span class="track"></span><span>Подложка</span></label></div>
          <div v-if="selected.bg" class="row gap-8">
            <div class="field grow"><label>Цвет подложки</label><input class="input sm" type="color" v-model="selected.bg" /></div>
            <div class="field grow"><label>Прозрачность</label><input class="range" type="range" min="0" max="1" step="0.05" v-model.number="selected.bgOpacity" /></div>
          </div>
          <div class="field"><label>Плавность появления, с</label><input class="input sm" type="number" min="0" max="2" step="0.1" v-model.number="selected.fade" /></div>
          <div class="row gap-4 mt-8"><button class="btn sm" @click="duplicateSel" aria-label="Дублировать надпись"><Icon name="copy" :size="16" /></button><button class="btn sm danger" @click="removeSel" aria-label="Удалить надпись"><Icon name="delete" :size="16" /></button></div>
        </template>

        <template v-else-if="sel.track === 'image'">
          <h4>Картинка</h4>
          <div class="small muted mb-8">{{ assetOf(selected.assetId)?.name }}</div>
          <div class="field"><label>Ширина: {{ Math.round(selected.width * 100) }}% кадра</label><input class="range" type="range" min="0.05" max="1" step="0.01" v-model.number="selected.width" /></div>
          <div class="row gap-8">
            <div class="field grow"><label>По горизонтали</label><input class="range" type="range" min="0" max="1" step="0.01" v-model.number="selected.x" /></div>
            <div class="field grow"><label>По вертикали</label><input class="range" type="range" min="0" max="1" step="0.01" v-model.number="selected.y" /></div>
          </div>
          <div class="field"><label>Прозрачность</label><input class="range" type="range" min="0.05" max="1" step="0.05" v-model.number="selected.opacity" /></div>
          <div class="row gap-4 mt-8"><button class="btn sm" @click="duplicateSel" aria-label="Дублировать картинку"><Icon name="copy" :size="16" /></button><button class="btn sm danger" @click="removeSel" aria-label="Удалить картинку"><Icon name="delete" :size="16" /></button></div>
        </template>

        <template v-else-if="sel.track === 'audio'">
          <h4>Музыка</h4>
          <div class="small muted mb-8">{{ assetOf(selected.assetId)?.name }}</div>
          <div class="field"><label>Громкость: {{ Math.round(selected.volume * 100) }}%</label><input class="range" type="range" min="0" max="1.5" step="0.05" v-model.number="selected.volume" /></div>
          <div class="row gap-8">
            <div class="field grow"><label>Нарастание, с</label><input class="input sm" type="number" min="0" max="10" step="0.5" v-model.number="selected.fadeIn" /></div>
            <div class="field grow"><label>Затухание, с</label><input class="input sm" type="number" min="0" max="10" step="0.5" v-model.number="selected.fadeOut" /></div>
          </div>
          <div class="field"><label>Начать файл с секунды</label><input class="input sm" type="number" min="0" step="1" v-model.number="selected.offset" /></div>
          <div class="row gap-4 mt-8"><button class="btn sm danger" @click="removeSel" aria-label="Удалить звуковую дорожку"><Icon name="delete" :size="16" /></button></div>
        </template>
      </div>
    </div>

    <!-- Панель инструментов -->
    <div class="tl-tools panel soft">
      <button class="btn sm" @click="splitAtCursor"><Icon name="cut" :size="16" /> Разрезать</button>
      <button class="btn sm" :disabled="!selected" @click="removeSel"><Icon name="delete" :size="16" /> Удалить</button>
      <button class="btn sm" :disabled="!selected" @click="duplicateSel"><Icon name="copy" :size="16" /> Дублировать</button>
      <span class="sep"></span>
      <button class="btn sm" @click="addText"><Icon name="subtitles" :size="16" /> Титр</button>
      <label class="btn sm"><Icon name="image" :size="16" /> Картинка<input type="file" class="hidden" accept="image/*" @change="upload($event, 'image')" /></label>
      <label class="btn sm"><Icon name="headphones" :size="16" /> Музыка<input type="file" class="hidden" accept="audio/*" @change="upload($event, 'audio')" /></label>
      <span class="sep"></span>
      <label class="small muted">Масштаб</label><input class="range" style="width:120px" type="range" min="15" max="300" v-model.number="zoom" />
      <span class="grow"></span>
      <button class="btn" :disabled="rendering" @click="render('new')"><Icon name="videoPlus" :size="16" /> Собрать в новое видео</button>
      <button class="btn primary" :disabled="rendering" @click="render('replace')"><Icon name="movieEdit" :size="16" /> Собрать поверх</button>
    </div>

    <div v-if="rendering && activeJob" class="panel soft">
      <div class="row gap-8" style="align-items:center"><div class="spin sm"></div><span class="grow">Собираем ролик — {{ activeJob.progress || 0 }}%</span></div>
      <div class="progress mt-8"><div class="bar" :style="{ width: (activeJob.progress || 0) + '%' }"></div></div>
    </div>

    <!-- Таймлайн -->
    <div class="tl-wrap">
      <div class="tl-labels">
        <div class="tl-label">Видео</div>
        <div class="tl-label">Титры</div>
        <div class="tl-label">Картинки</div>
        <div class="tl-label">Музыка</div>
      </div>
      <div class="tl-scroll">
        <div class="tl-inner" :style="{ width: Math.max(400, px(outDuration) + 60) + 'px' }">
          <div class="tl-ruler" @click="rulerClick">
            <div v-for="n in Math.ceil(outDuration) + 1" :key="n" class="tick" :style="{ left: px(n - 1) + 'px' }"><span v-if="(n - 1) % (zoom < 40 ? 10 : zoom < 100 ? 5 : 1) === 0" class="tiny">{{ fmtDuration(n - 1) }}</span></div>
          </div>
          <div class="tl-track">
            <div v-for="(c, i) in project.clips" :key="c.id" class="tl-clip" :class="{ active: sel?.track === 'clip' && sel.id === c.id }"
                 :style="{ left: px(clipStarts[i]) + 'px', width: Math.max(12, px(clipLen(c))) + 'px' }" @pointerdown="sel = { track: 'clip', id: c.id }">
              <span class="handle left" @pointerdown="startDrag($event, 'clip', c, 'left')"></span>
              <span class="cl-label">{{ fmtDuration(c.end - c.start) }}<template v-if="c.speed !== 1"> · {{ c.speed }}×</template></span>
              <span class="handle right" @pointerdown="startDrag($event, 'clip', c, 'right')"></span>
              <span v-if="c.transition?.type !== 'none' && i < project.clips.length - 1" class="tr-badge" :title="'Переход: ' + c.transition.type"><Icon name="swap" :size="12" /></span>
            </div>
          </div>
          <div class="tl-track">
            <div v-for="t in project.texts" :key="t.id" class="tl-item text" :class="{ active: sel?.track === 'text' && sel.id === t.id }"
                 :style="{ left: px(t.start) + 'px', width: Math.max(16, px(t.end - t.start)) + 'px' }" @pointerdown="startDrag($event, 'text', t, 'move')">
              <span class="handle left" @pointerdown="startDrag($event, 'text', t, 'left')"></span>
              <span class="it-label">{{ t.text }}</span>
              <span class="handle right" @pointerdown="startDrag($event, 'text', t, 'right')"></span>
            </div>
          </div>
          <div class="tl-track">
            <div v-for="im in project.images" :key="im.id" class="tl-item image" :class="{ active: sel?.track === 'image' && sel.id === im.id }"
                 :style="{ left: px(im.start) + 'px', width: Math.max(16, px(im.end - im.start)) + 'px' }" @pointerdown="startDrag($event, 'image', im, 'move')">
              <span class="handle left" @pointerdown="startDrag($event, 'image', im, 'left')"></span>
              <span class="it-label">{{ assetOf(im.assetId)?.name }}</span>
              <span class="handle right" @pointerdown="startDrag($event, 'image', im, 'right')"></span>
            </div>
          </div>
          <div class="tl-track">
            <div v-for="a in project.audio" :key="a.id" class="tl-item audio" :class="{ active: sel?.track === 'audio' && sel.id === a.id }"
                 :style="{ left: px(a.start) + 'px', width: Math.max(16, px(a.end - a.start)) + 'px' }" @pointerdown="startDrag($event, 'audio', a, 'move')">
              <span class="handle left" @pointerdown="startDrag($event, 'audio', a, 'left')"></span>
              <span class="it-label">{{ assetOf(a.assetId)?.name }}</span>
              <span class="handle right" @pointerdown="startDrag($event, 'audio', a, 'right')"></span>
            </div>
          </div>
          <div class="tl-playhead" :style="{ left: px(cursor) + 'px' }"></div>
        </div>
      </div>
    </div>

    <!-- Файлы проекта -->
    <div v-if="state.assets.length" class="panel soft">
      <div class="small muted mb-8">Файлы проекта</div>
      <div class="row wrap gap-8">
        <span v-for="a in state.assets" :key="a.id" class="chip">
          <Icon :name="a.kind === 'image' ? 'image' : 'headphones'" :size="14" /> {{ a.name }}
          <button class="ibtn sm" style="width:18px;height:18px" @click="a.kind === 'image' ? addImage(a) : addAudio(a)" title="Добавить на таймлайн" aria-label="Добавить на таймлайн"><Icon name="plus" :size="12" /></button>
          <button class="ibtn sm" style="width:18px;height:18px" @click="removeAsset(a)" title="Удалить файл" aria-label="Удалить файл"><Icon name="close" :size="12" /></button>
        </span>
      </div>
    </div>
    <audio v-if="project.audio[0]" ref="audioEl" :src="assetOf(project.audio[0].assetId)?.url" preload="none"></audio>
  </div>
</template>

<style scoped>
.tl-root { display: flex; flex-direction: column; gap: 12px; }
.tl-top { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
.pv-stage { position: relative; background: #000; border-radius: var(--radius); overflow: hidden; container-type: size; max-height: 420px; margin: 0 auto; }
.pv-video, .pv-frame { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #000; }
.pv-frame { z-index: 3; }
.pv-img { position: absolute; }
.pv-img img { width: 100%; height: auto; display: block; }
.pv-text { position: absolute; padding: 4px 10px; white-space: pre-wrap; text-align: center; font-weight: 700; line-height: 1.15; max-width: 92%; }
.tl-props { min-height: 200px; }
.tl-props h4 { margin: 0 0 8px; }
.tl-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.tl-tools .sep { width: 1px; height: 22px; background: var(--border); }
.tl-wrap { display: grid; grid-template-columns: 86px minmax(0, 1fr); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.tl-labels { background: var(--surface-2); padding-top: 26px; }
.tl-label { height: 40px; display: flex; align-items: center; padding-left: 10px; font-size: 12px; color: var(--text-3); border-bottom: 1px solid var(--border); }
.tl-scroll { overflow-x: auto; background: var(--surface); }
.tl-inner { position: relative; }
.tl-ruler { position: relative; height: 26px; border-bottom: 1px solid var(--border); background: var(--surface-2); cursor: pointer; }
.tl-ruler .tick { position: absolute; top: 0; height: 100%; border-left: 1px solid var(--line-2); padding-left: 3px; color: var(--text-3); }
.tl-track { position: relative; height: 40px; border-bottom: 1px solid var(--border); }
.tl-clip, .tl-item { position: absolute; top: 4px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: grab; user-select: none; touch-action: none; }
.tl-clip { background: color-mix(in srgb, var(--brand) 22%, transparent); border: 1px solid var(--brand); }
.tl-clip.active, .tl-item.active { outline: 2px solid var(--brand); }
.tl-item.text { background: color-mix(in srgb, #7a4fd6 25%, transparent); border: 1px solid #7a4fd6; }
.tl-item.image { background: color-mix(in srgb, #2f9e63 25%, transparent); border: 1px solid #2f9e63; }
.tl-item.audio { background: color-mix(in srgb, #d98324 25%, transparent); border: 1px solid #d98324; }
.cl-label, .it-label { font-size: 11px; padding: 0 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
.handle { position: absolute; top: 0; width: 8px; height: 100%; cursor: ew-resize; background: rgba(0, 0, 0, .12); }
.handle.left { left: 0; } .handle.right { right: 0; }
.tr-badge { position: absolute; right: 10px; top: 2px; opacity: .8; }
.tl-playhead { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--danger); pointer-events: none; }
@media (max-width: 980px) { .tl-top { grid-template-columns: 1fr; } }
</style>
