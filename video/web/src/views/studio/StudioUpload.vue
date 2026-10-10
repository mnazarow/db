<script setup>
import { ref, computed, onMounted, onBeforeUnmount, markRaw } from 'vue';
import { useRouter } from 'vue-router';
import { get, patch, post, del } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import { ChunkedUpload } from '../../utils/upload.js';
import * as ws from '../../ws.js';
import { fmtBytes, VISIBILITY, STATUS } from '../../utils/format.js';
import { url as withBase } from '../../base.js';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
const uploads = ref([]);
const dragging = ref(false);
const categories = ref([]);
const defaults = ref({ visibility: 'internal', categoryId: '' });
let off = null;

const allowed = computed(() => auth.config?.uploadAllowedExtensions || []);
const maxMb = computed(() => auth.config?.uploadMaxSizeMb || 0);

onMounted(async () => {
  categories.value = (await get('/api/feed/categories')).categories;
  off = ws.on('video.progress', (m) => { const u = uploads.value.find((x) => x.videoId === m.videoId); if (u) { u.processing = m.progress; u.stage = m.stage; } });
  const r = ws.on('video.ready', (m) => { const u = uploads.value.find((x) => x.videoId === m.videoId); if (u) { u.processing = 100; u.ready = true; } });
  const f = ws.on('video.failed', (m) => { const u = uploads.value.find((x) => x.videoId === m.videoId); if (u) u.failed = m.error; });
  const o = off; off = () => { o(); r(); f(); };
  window.addEventListener('beforeunload', beforeUnload);
});
onBeforeUnmount(() => { off?.(); window.removeEventListener('beforeunload', beforeUnload); });
function beforeUnload(e) { if (uploads.value.some((u) => u.st.status === 'uploading')) { e.preventDefault(); e.returnValue = ''; } }

function addFiles(files) {
  for (const file of files) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!allowed.value.includes(ext)) { ui.toast(`${file.name}: формат .${ext} не поддерживается`, { type: 'error' }); continue; }
    if (maxMb.value && file.size > maxMb.value * 1024 * 1024) { ui.toast(`${file.name}: больше ${maxMb.value} МБ`, { type: 'error' }); continue; }
    const up = new ChunkedUpload(file, { visibility: defaults.value.visibility, categoryId: defaults.value.categoryId || undefined });
    const item = ref({ up: markRaw(up), key: `${file.name}-${file.size}-${Date.now()}`, name: file.name, size: file.size, videoId: null, shortId: null, st: { status: 'pending', progress: 0, speed: 0, eta: 0, error: null, detail: '' }, form: { title: file.name.replace(/\.[^.]+$/, ''), description: '', visibility: defaults.value.visibility, categoryId: defaults.value.categoryId, tags: '' }, saved: false, processing: 0, stage: '', ready: false, failed: '' });
    up.onChange = (u) => { item.value.videoId = u.videoId; item.value.shortId = u.shortId; item.value.st = { status: u.status, progress: u.progress, speed: u.speed, eta: u.eta, error: u.error, detail: u.detail || '' }; };
    uploads.value.push(item.value);
    up.start();
  }
}
function onDrop(e) { dragging.value = false; addFiles([...e.dataTransfer.files]); }
function onPick(e) { addFiles([...e.target.files]); e.target.value = ''; }
async function saveMeta(item) {
  if (!item.videoId) return;
  try {
    await patch(`/api/videos/${item.videoId}`, { title: item.form.title, description: item.form.description, visibility: item.form.visibility, categoryId: item.form.categoryId || null, tags: item.form.tags });
    item.saved = true; ui.toast('Сведения сохранены', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function cancel(item) { if (item.up) await item.up.cancel(); uploads.value = uploads.value.filter((x) => x !== item); }
const STAGES = { probe: 'анализ', hls: 'кодирование', thumbnails: 'миниатюры', storyboard: 'раскадровка', mp4: 'подготовка mp4', import: 'скачивание' };

// Самопроверка загрузки: проходим тот же путь, что и настоящий файл, и называем сломанный шаг.
// Нужна, когда между браузером и порталом стоит прокси, VPN или антивирус: они подменяют ответ,
// и без такой проверки видно только невнятную ошибку в карточке файла.
const testOpen = ref(false);
const testRunning = ref(false);
const testSteps = ref([]);
const testVerdict = ref('');

function step(name, ok, note) { testSteps.value.push({ name, ok, note }); }

async function runSelfTest() {
  testOpen.value = true; testRunning.value = true; testSteps.value = []; testVerdict.value = '';
  let uploadId = null, chunk = 8 * 1024 * 1024, ok = false;
  try {
    // 1. Связь. Спрашиваем портал трижды: если отвечают разные экземпляры, загрузка по частям невозможна
    try {
      const probes = [];
      for (let i = 0; i < 3; i++) probes.push(await get('/api/health'));
      const instances = [...new Set(probes.map((h) => h.instance || 'без метки'))];
      const versions = [...new Set(probes.map((h) => h.version))];
      if (instances.length > 1) {
        step('Связь с порталом', false, `на один адрес отвечают ${instances.length} разных экземпляра портала (версии: ${versions.join(', ')}). Загрузка по частям так работать не может: часть уходит не туда, где загрузка создана. Оставьте один экземпляр или настройте «липкие» сессии на балансировщике.`);
        throw new Error('несколько экземпляров');
      }
      step('Связь с порталом', true, `версия ${versions[0]}, экземпляр один`);
    } catch (e) { if (!testSteps.value.length) step('Связь с порталом', false, e.message); throw e; }

    // 2. Создание загрузки
    try {
      const r = await post('/api/uploads/', { filename: 'проверка-связи.mp4', size: chunk, mime: 'video/mp4', visibility: 'private' });
      uploadId = r.uploadId; chunk = Math.min(r.chunkSize || chunk, chunk);
      step('Создание загрузки', true, `идентификатор …${String(uploadId).slice(-12)}, часть ${fmtBytes(chunk)}`);
    } catch (e) { step('Создание загрузки', false, e.message); throw e; }

    // Отдельно проверяем адрес без косой черты: именно на нём nginx отвечает 301,
    // а браузер на 301 превращает POST в GET и теряет тело запроса
    try {
      const probe = await fetch(withBase('/api/uploads'), { method: 'POST', credentials: 'same-origin', headers: { 'content-type': 'application/json' }, body: '{}' });
      if (probe.redirected) step('Адрес без косой черты', false, `веб-сервер перенаправляет на ${probe.url}. Добавьте в nginx точный блок «location = ${withBase('/api/uploads')}» и перезапустите его (или выполните обновление портала).`);
      else step('Адрес без косой черты', true, 'перенаправления нет');
    } catch (e) { step('Адрес без косой черты', false, e.message); }

    // 3. Чтение только что созданной загрузки — отделяет «запись пропала» от «часть не доходит»
    try {
      let found = 0, lastErr = '';
      for (let i = 0; i < 3; i++) {
        try { await get(`/api/uploads/${uploadId}`); found++; } catch (e) { lastErr = e.message; }
      }
      if (found === 3) step('Чтение созданной загрузки', true, '3 из 3 — запись на месте');
      else if (found === 0) { step('Чтение созданной загрузки', false, `ни разу не нашлась (${lastErr}). Запись исчезает сразу после создания — похоже, запросы попадают на разные экземпляры портала или в разные базы.`); throw new Error('запись пропала'); }
      else { step('Чтение созданной загрузки', false, `нашлась ${found} раза из 3 (${lastErr}). Запросы попадают то на один, то на другой сервер — нужны «липкие» сессии или один экземпляр портала.`); throw new Error('через раз'); }
    } catch (e) { if (!testSteps.value.some((x) => x.name.startsWith('Чтение'))) step('Чтение созданной загрузки', false, e.message); throw e; }

    // 4. Отправка части — ровно тем же способом, что и настоящий файл (XHR)
    try {
      const body = new Blob([new Uint8Array(chunk)]);
      const res = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PATCH', withBase(`/api/uploads/${uploadId}`));
        xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');
        xhr.setRequestHeader('Upload-Offset', '0');
        xhr.onload = () => resolve({ status: xhr.status, type: xhr.getResponseHeader('content-type') || '', text: xhr.responseText });
        xhr.onerror = () => reject(new Error('Сетевая ошибка при отправке части'));
        xhr.send(body);
      });
      let data = null; try { data = JSON.parse(res.text); } catch { /* не JSON */ }
      const name = `Отправка части ${fmtBytes(chunk)}`;
      if (res.status >= 200 && res.status < 300 && typeof data?.offset === 'number') step(name, true, `принято ${fmtBytes(data.offset)}`);
      else if (res.status === 413) { step(name, false, 'часть не пропустил веб-сервер (413). Увеличьте client_max_body_size для /api/uploads/ в nginx и в промежуточных прокси'); throw new Error('413'); }
      else if (res.status === 401) { step(name, false, 'запрос ушёл без входа (401). Cookie не доходит — проверьте прокси и антивирус, которые режут заголовки'); throw new Error('401'); }
      else if (res.status === 404) { step(name, false, 'загрузка не найдена (404), хотя минуту назад читалась. Часть уходит не на тот сервер, где загрузка создана'); throw new Error('404'); }
      else if (!data) { step(name, false, `код ${res.status}, ответ не от портала (${res.type.split(';')[0] || 'без типа'}) — похоже на прокси, VPN или антивирус`); throw new Error('не JSON'); }
      else { step(name, false, data.error || `код ${res.status}`); throw new Error('часть'); }
    } catch (e) { if (!testSteps.value.some((x) => x.name.startsWith('Отправка'))) step('Отправка части', false, e.message); throw e; }

    ok = true;
  } catch { /* шаг уже записан */ } finally {
    if (uploadId) { try { await del(`/api/uploads/${uploadId}`); step('Уборка за проверкой', true, 'временная запись удалена'); } catch { step('Уборка за проверкой', false, 'временную запись удалить не удалось'); } }
    const bad = testSteps.value.find((x) => !x.ok);
    testVerdict.value = ok && !bad
      ? 'Путь загрузки исправен. Если файл всё равно не грузится, дело в самом файле или в его размере.'
      : bad ? `Загрузка ломается на шаге «${bad.name}». Покажите этот список администратору портала.` : 'Проверка не завершилась.';
    testRunning.value = false;
  }
}

// Импорт по ссылке
const importOpen = ref(false);
const importForm = ref({ url: '', title: '' });
const importing = ref(false);
async function importUrl() {
  const url = importForm.value.url.trim(); if (!url) return;
  importing.value = true;
  try {
    const r = await post('/api/uploads/from-url', { url, title: importForm.value.title, visibility: defaults.value.visibility, categoryId: defaults.value.categoryId || undefined });
    const item = ref({ up: null, key: `url-${Date.now()}`, name: url, size: 0, videoId: r.videoId, shortId: r.shortId, st: { status: 'done', progress: 1, speed: 0, eta: 0, error: null, detail: '' }, form: { title: r.video.title, description: '', visibility: r.video.visibility, categoryId: defaults.value.categoryId, tags: '' }, saved: false, processing: 0, stage: 'import', ready: false, failed: '', imported: true });
    uploads.value.push(item.value);
    importForm.value = { url: '', title: '' }; importOpen.value = false;
    ui.toast('Импорт запущен — файл скачивается на сервер', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { importing.value = false; }
}
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Загрузка видео</h1><div class="sub">Поддерживаются {{ allowed.join(', ') }}<span v-if="maxMb"> • до {{ fmtBytes(maxMb * 1024 * 1024) }}</span> • загрузка возобновляется при обрыве связи</div></div></div>
    <div class="row wrap mb-16">
      <div class="field" style="width: 240px"><label>Видимость по умолчанию</label><select class="select" v-model="defaults.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select></div>
      <div class="field" style="width: 240px"><label>Категория по умолчанию</label><select class="select" v-model="defaults.categoryId"><option value="">Без категории</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
    </div>
    <label class="dropzone" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop">
      <Icon name="upload" :size="48" />
      <div class="h3" style="margin-top: 8px">Перетащите видеофайлы сюда</div>
      <div class="muted small">или нажмите, чтобы выбрать. Можно выбрать несколько файлов.</div>
      <input type="file" multiple :accept="allowed.map((e) => '.' + e).join(',')" class="hidden" @change="onPick" />
    </label>
    <div class="row wrap mt-12" style="justify-content: center; gap: 12px">
      <button class="btn ghost sm" @click="importOpen = !importOpen"><Icon name="importUrl" :size="18" /> Импорт по ссылке</button>
      <router-link v-if="auth.canUpload" to="/studio/record" class="btn ghost sm"><Icon name="screenShare" :size="18" /> Записать экран или камеру</router-link>
      <button class="btn ghost sm" :disabled="testRunning" @click="runSelfTest"><Icon name="stethoscope" :size="18" /> {{ testRunning ? 'Проверяем…' : 'Проверить загрузку' }}</button>
    </div>
    <div v-if="testOpen" class="panel mt-12">
      <div class="row" style="justify-content:space-between"><h4 class="mb-4">Проверка загрузки</h4><button class="btn ghost sm" @click="testOpen = false">Закрыть</button></div>
      <p class="small muted">Портал проходит тот же путь, что и настоящий файл: связь, создание загрузки, отправка части и уборка. Так видно, на каком именно шаге всё ломается.</p>
      <div class="col gap-8 mt-8">
        <div v-for="(st, i) in testSteps" :key="i" class="row small" style="align-items:flex-start">
          <Icon :name="st.ok ? 'check' : 'alertCircle'" :size="16" :class="st.ok ? 'chk-ok' : 'chk-bad'" />
          <b style="min-width: 190px">{{ st.name }}</b><span class="muted">{{ st.note }}</span>
        </div>
        <div v-if="testRunning" class="small muted">Идёт проверка…</div>
      </div>
      <div v-if="testVerdict" class="alert mt-12" :class="testSteps.some((x) => !x.ok) ? 'danger' : 'success'"><Icon :name="testSteps.some((x) => !x.ok) ? 'alertCircle' : 'check'" :size="18" /> {{ testVerdict }}</div>
    </div>
    <div v-if="importOpen" class="panel mt-12">
      <h4 class="mb-4">Импорт по ссылке</h4>
      <p class="small muted">Прямая ссылка на видеофайл (http/https), например с файлового сервера или облака. Если на сервере установлен yt-dlp, можно указать и страницу видеосервиса. Файл скачается на сервер и обработается как обычная загрузка.</p>
      <div class="row wrap" style="align-items:flex-end"><div class="field grow" style="min-width: 260px"><label>Ссылка</label><input class="input" v-model="importForm.url" placeholder="https://files.example.local/training/lesson1.mp4" @keydown.enter="importUrl" /></div><div class="field" style="width: 260px"><label>Название (необязательно)</label><input class="input" v-model="importForm.title" maxlength="150" /></div><button class="btn primary" :disabled="importing || !importForm.url.trim()" @click="importUrl">{{ importing ? 'Запуск…' : 'Импортировать' }}</button></div>
    </div>

    <div v-for="item in uploads" :key="item.key" class="panel upload-item mt-16">
      <div class="row wrap" style="align-items: flex-start">
        <div class="grow">
          <div class="row"><Icon :name="item.imported ? 'importUrl' : 'fileVideo'" :size="20" class="muted" /><b class="ellipsis">{{ item.name }}</b><span v-if="item.size" class="small muted nowrap">{{ fmtBytes(item.size) }}</span></div>
          <div class="up-bar mt-8"><i :style="{ width: (item.st.progress * 100) + '%' }" :class="{ err: item.st.status === 'error', done: item.st.status === 'done' }"></i></div>
          <div class="small muted mt-8 row wrap">
            <template v-if="item.st.status === 'uploading'"><span>Загрузка {{ Math.round(item.st.progress * 100) }}%</span><span v-if="item.st.speed">{{ fmtBytes(item.st.speed) }}/с</span><span v-if="item.st.eta > 1">осталось ~{{ Math.ceil(item.st.eta / 60) }} мин</span></template>
            <span v-else-if="item.st.status === 'paused'">Приостановлено ({{ Math.round(item.st.progress * 100) }}%)</span>
            <span v-else-if="item.st.status === 'error'" class="badge danger">Ошибка{{ item.st.detail ? ' (' + item.st.detail + ')' : '' }}: {{ item.st.error }}</span>
            <span v-else-if="item.st.status === 'done' && item.failed" class="badge danger">Ошибка обработки: {{ item.failed }}</span>
            <span v-else-if="item.st.status === 'done' && item.ready" class="badge success"><Icon name="check" :size="12" /> Готово</span>
            <span v-else-if="item.st.status === 'done'" class="badge warning">Обработка {{ item.processing || 0 }}%{{ item.stage ? ' — ' + (STAGES[item.stage] || item.stage) : '' }}</span>
            <span v-else>Подготовка…</span>
          </div>
        </div>
        <div class="row gap-4">
          <button v-if="item.st.status === 'uploading'" class="btn ghost sm" @click="item.up.pause()"><Icon name="pause" :size="16" /> Пауза</button>
          <button v-if="item.st.status === 'paused' || item.st.status === 'error'" class="btn sm" @click="item.up.resume()"><Icon name="play" :size="16" /> Продолжить</button>
          <button v-if="item.st.status !== 'done' && item.up" class="btn ghost sm danger" @click="cancel(item)"><Icon name="close" :size="16" /> Отменить</button>
          <router-link v-if="item.videoId && item.st.status === 'done'" :to="`/studio/videos/${item.videoId}`" class="btn ghost sm"><Icon name="edit" :size="16" /> Все настройки</router-link>
        </div>
      </div>
      <div v-if="item.videoId" class="form-grid mt-16">
        <div class="field" style="grid-column: 1 / -1"><label>Название</label><input class="input" v-model="item.form.title" maxlength="150" @input="item.saved = false" /></div>
        <div class="field" style="grid-column: 1 / -1"><label>Описание</label><textarea class="textarea" v-model="item.form.description" placeholder="Расскажите, о чём видео. Таймкоды вида 0:00 Введение станут главами." @input="item.saved = false"></textarea></div>
        <div class="field"><label>Видимость</label><select class="select" v-model="item.form.visibility" @change="item.saved = false"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select><div class="hint">{{ VISIBILITY[item.form.visibility]?.hint }}</div></div>
        <div class="field"><label>Категория</label><select class="select" v-model="item.form.categoryId" @change="item.saved = false"><option value="">Без категории</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div class="field" style="grid-column: 1 / -1"><label>Теги (через запятую)</label><input class="input" v-model="item.form.tags" placeholder="обучение, насосы, монтаж" @input="item.saved = false" /></div>
        <div class="form-actions" style="grid-column: 1 / -1"><button class="btn primary sm" @click="saveMeta(item)" :disabled="item.saved">{{ item.saved ? 'Сохранено' : 'Сохранить сведения' }}</button><router-link v-if="item.shortId" :to="`/watch/${item.shortId}`" class="btn ghost sm" target="_blank">Открыть страницу видео</router-link></div>
      </div>
    </div>
  </div>
</template>

<style>
.dropzone { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 24px; border: 2px dashed var(--brand-300); border-radius: var(--radius-xl); background: var(--brand-50); color: var(--brand); cursor: pointer; text-align: center; transition: background var(--t-fast), border-color var(--t-fast); }
.dropzone:hover, .dropzone.dragging { background: var(--brand-100); border-color: var(--brand); }
.chk-ok { color: var(--success); flex: none; }
.chk-bad { color: var(--danger); flex: none; }
</style>
