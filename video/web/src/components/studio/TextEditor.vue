<script setup>
// Монтаж по расшифровке (как Descript): отмечаешь лишние фразы — портал вырезает их из видео.
// Отдельно ищутся слова-паразиты: «э-э», «ну», «как бы», «вот», «типа».
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDuration } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['changed']);
const ui = useUi();
const data = ref(null);
const loading = ref(true);
const picked = ref(new Set());
const fillers = ref(null);
const fillerPicked = ref(new Set());
const busy = ref('');
const activeJob = ref(null);
const trackId = ref('');
let timer = null;

const duration = computed(() => Number(props.video.duration) || 0);
const pickedSec = computed(() => (data.value?.segments || []).filter((s) => picked.value.has(s.index)).reduce((n, s) => n + (s.end - s.start), 0));
const fillerSec = computed(() => (fillers.value?.items || []).filter((_, i) => fillerPicked.value.has(i)).reduce((n, x) => n + (x.end - x.start), 0));

async function load() {
  loading.value = true;
  try {
    data.value = await get(`/api/videos/${props.video.id}/editor/transcript${trackId.value ? `?track=${trackId.value}` : ''}`);
    trackId.value = data.value.track?.id || '';
    picked.value = new Set();
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);
onBeforeUnmount(() => clearInterval(timer));

function toggle(i) { const s = new Set(picked.value); s.has(i) ? s.delete(i) : s.add(i); picked.value = s; }
function toggleFiller(i) { const s = new Set(fillerPicked.value); s.has(i) ? s.delete(i) : s.add(i); fillerPicked.value = s; }
function seek(s) { props.player?.seekTo?.(s); }

function track(jobId) {
  clearInterval(timer);
  timer = setInterval(async () => {
    try {
      const { job } = await get(`/api/videos/${props.video.id}/editor/jobs/${jobId}`);
      activeJob.value = job;
      if (['done', 'failed', 'cancelled'].includes(job.status)) {
        clearInterval(timer);
        busy.value = '';
        if (job.status === 'done') { ui.toast('Монтаж выполнен — видео обрабатывается заново', { type: 'success' }); picked.value = new Set(); fillers.value = null; emit('changed'); }
        else ui.toast(job.error || 'Задание не выполнено', { type: 'error', timeout: 8000 });
      }
    } catch { /* ignore */ }
  }, 2000);
}

async function cutPicked() {
  if (!picked.value.size || busy.value) return;
  if (!(await ui.ask({ title: 'Вырезать выбранные фразы?', message: `Из записи уйдёт ${picked.value.size} фраз (${fmtDuration(pickedSec.value)}). Видео пересоберётся как новая версия, главы и метки пересчитаются.`, okLabel: 'Вырезать' }))) return;
  busy.value = 'cut';
  try {
    const r = await post(`/api/videos/${props.video.id}/editor/text-cut`, { segments: [...picked.value], track: trackId.value || undefined });
    ui.toast('Монтаж поставлен в очередь');
    track(r.jobId);
  } catch (e) { busy.value = ''; ui.toast(e.message, { type: 'error' }); }
}
async function findFillers() {
  busy.value = 'fillers';
  try {
    fillers.value = await post(`/api/videos/${props.video.id}/editor/fillers`, { track: trackId.value || undefined });
    fillerPicked.value = new Set((fillers.value.items || []).map((_, i) => i));
    if (!fillers.value.items.length) ui.toast('Слов-паразитов не нашлось — речь чистая');
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = ''; }
}
async function cutFillers() {
  const items = (fillers.value?.items || []).filter((_, i) => fillerPicked.value.has(i));
  if (!items.length || busy.value) return;
  if (!(await ui.ask({ title: 'Убрать слова-паразиты?', message: `Будет вырезано ${items.length} мест (${fmtDuration(fillerSec.value)}). Видео пересоберётся как новая версия.`, okLabel: 'Убрать' }))) return;
  busy.value = 'fillers-apply';
  try {
    const r = await post(`/api/videos/${props.video.id}/editor/fillers`, { apply: true, items, track: trackId.value || undefined });
    ui.toast(`Вырезаем ${r.cuts.length} мест…`);
    track(r.jobId);
  } catch (e) { busy.value = ''; ui.toast(e.message, { type: 'error' }); }
}
</script>

<template>
  <div v-if="loading" class="loading-block"><div class="spin"></div></div>
  <div v-else class="col gap-16">
    <div v-if="!data?.segments?.length" class="panel">
      <h3>Монтаж по расшифровке</h3>
      <p class="small muted">У видео пока нет расшифровки. Включите распознавание речи в настройках портала или загрузите файл субтитров на вкладке «Субтитры» — после этого записью можно будет управлять как текстом.</p>
    </div>
    <template v-else>
      <div class="panel">
        <div class="row wrap gap-8" style="align-items:center">
          <h3 class="grow">Монтаж по расшифровке</h3>
          <select v-if="data.tracks.length > 1" class="select sm" v-model="trackId" @change="load"><option v-for="t in data.tracks" :key="t.id" :value="t.id">{{ t.label }}</option></select>
          <button class="btn sm" :disabled="!!busy" @click="findFillers"><Icon name="waveform" :size="16" /> Найти слова-паразиты</button>
        </div>
        <p class="small muted">Отметьте фразы, которых не должно быть в записи, — портал вырежет именно эти куски и пересчитает главы, подсказки и метки.</p>
        <div class="phrases mt-8">
          <label v-for="s in data.segments" :key="s.index" class="phrase" :class="{ picked: picked.has(s.index) }">
            <input type="checkbox" :checked="picked.has(s.index)" @change="toggle(s.index)" />
            <button class="tc" type="button" @click.prevent="seek(s.start)">{{ fmtDuration(s.start) }}</button>
            <span class="grow">{{ s.text }}</span>
          </label>
        </div>
        <div class="form-actions">
          <button class="btn primary" :disabled="!picked.size || !!busy" @click="cutPicked"><Icon name="cut" :size="16" /> Вырезать выбранное<span v-if="picked.size"> ({{ picked.size }} · {{ fmtDuration(pickedSec) }})</span></button>
          <button class="btn ghost" :disabled="!picked.size" @click="picked = new Set()">Снять выделение</button>
        </div>
      </div>

      <div v-if="fillers" class="panel">
        <h4 class="mb-4">Слова-паразиты: {{ fillers.items.length }}</h4>
        <p v-if="fillers.items.length" class="small muted">Всего {{ fmtDuration(fillers.totalSec) }}. Проверьте список — иногда «вот» и «значит» несут смысл.</p>
        <div v-if="fillers.items.length" class="phrases mt-8">
          <label v-for="(x, i) in fillers.items" :key="i" class="phrase" :class="{ picked: fillerPicked.has(i) }">
            <input type="checkbox" :checked="fillerPicked.has(i)" @change="toggleFiller(i)" />
            <button class="tc" type="button" @click.prevent="seek(x.start)">{{ fmtDuration(x.start) }}</button>
            <span class="grow"><b>{{ x.word }}</b> — <span class="muted">{{ x.text }}</span></span>
          </label>
        </div>
        <div v-if="fillers.items.length" class="form-actions">
          <button class="btn primary" :disabled="!fillerPicked.size || !!busy" @click="cutFillers"><Icon name="waveform" :size="16" /> Убрать выбранные ({{ fillerPicked.size }} · {{ fmtDuration(fillerSec) }})</button>
        </div>
      </div>

      <div v-if="activeJob && ['queued','running'].includes(activeJob.status)" class="panel soft">
        <div class="row gap-8" style="align-items:center"><div class="spin sm"></div><span class="grow">Пересобираем видео — {{ activeJob.progress || 0 }}%</span></div>
        <div class="progress mt-8"><div class="bar" :style="{ width: (activeJob.progress || 0) + '%' }"></div></div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.phrases { max-height: 460px; overflow: auto; border: 1px solid var(--border); border-radius: var(--radius); }
.phrase { display: flex; gap: 8px; align-items: flex-start; padding: 7px 10px; border-bottom: 1px solid var(--border); cursor: pointer; }
.phrase:last-child { border-bottom: 0; }
.phrase.picked { background: color-mix(in srgb, var(--danger) 10%, transparent); }
.phrase .tc { background: none; border: 0; color: var(--brand); font-variant-numeric: tabular-nums; cursor: pointer; padding: 0; min-width: 48px; text-align: left; }
</style>
