<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, patch, post, del } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import * as ws from '../../ws.js';
import LiveChat from '../../components/LiveChat.vue';
import LiveInteract from '../../components/LiveInteract.vue';
import VideoPlayer from '../../components/player/VideoPlayer.vue';
import EmptyState from '../../components/EmptyState.vue';
import { VISIBILITY, toLocalInput, fmtNumber, timeAgo, fmtDateTime } from '../../utils/format.js';
import { copyWithToast } from '../../utils/clipboard.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const stream = ref(null);
const error = ref(null);
const showKey = ref(false);
const form = ref({});
const attendees = ref(null);
async function loadAttendees() {
  attendees.value = null;
  try { attendees.value = await get(`/api/live/${stream.value.id}/attendees`); }
  catch (e) { ui.toast(e.message, { type: 'error' }); attendees.value = { people: [], totals: { registered: 0, attended: 0, noShow: 0, avgPercent: 0 } }; }
}
const categories = ref([]);
const tab = ref('setup');
// 1.9: ретрансляция на внешние площадки (мультистриминг)
const restreams = ref({ enabled: true, restreams: [] });
const newTarget = ref({ name: '', url: '', streamKey: '' });
const PRESETS = [['VK Видео', 'rtmps://vsu.okcdn.ru/input'], ['YouTube', 'rtmp://a.rtmp.youtube.com/live2'], ['Rutube', 'rtmp://rtmp.rutube.ru/live'], ['Своя площадка', '']];
let restreamTimer = null;
const RESTREAM_STATUS = { idle: 'ожидает эфира', starting: 'запускается', live: 'идёт', error: 'ошибка', stopped: 'остановлена' };

async function loadRestreams() {
  try { restreams.value = await get(`/api/studio/live/${stream.value.id}/restreams`); } catch { /* ignore */ }
}
async function addTarget() {
  const t = newTarget.value;
  if (!t.name || !t.url) return ui.toast('Укажите название и адрес площадки', { type: 'error' });
  try { await post(`/api/studio/live/${stream.value.id}/restreams`, t); newTarget.value = { name: '', url: '', streamKey: '' }; await loadRestreams(); ui.toast('Площадка добавлена', { type: 'success' }); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function toggleTarget(r) {
  try { await patch(`/api/studio/live/restreams/${r.id}`, { enabled: !r.enabled }); await loadRestreams(); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function removeTarget(r) {
  if (!(await ui.ask({ title: `Убрать площадку «${r.name}»?`, okLabel: 'Убрать', danger: true }))) return;
  try { await del(`/api/studio/live/restreams/${r.id}`); await loadRestreams(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function usePreset(p) { newTarget.value.name = p[0]; newTarget.value.url = p[1]; }

let off = [];

async function load() {
  try {
    const s = await get(`/api/live/${route.params.id}`);
    stream.value = s.stream;
    form.value = { title: s.stream.title, description: s.stream.description, visibility: s.stream.visibility, categoryId: s.stream.categoryId || '', chatEnabled: s.stream.chatEnabled, record: s.stream.record, scheduledAt: toLocalInput(s.stream.scheduledAt), qaEnabled: s.stream.qaEnabled !== false, pollsEnabled: s.stream.pollsEnabled !== false, registration: !!s.stream.registration, registrationLimit: s.stream.registrationLimit || null, registrationNote: s.stream.registrationNote || '', captions: !!s.stream.captions, dvr: s.stream.dvr !== false };
  } catch (e) { error.value = e; }
}
onMounted(async () => {
  await load();
  categories.value = (await get('/api/feed/categories')).categories;
  off.push(ws.on('stream_status', (m) => { if (stream.value && m.streamId === stream.value.id) load(); }), ws.on('live.started', load), ws.on('live.ended', load));
});
watch(tab, (t) => {
  if (t === 'people' && stream.value) loadAttendees();
  clearInterval(restreamTimer);
  if (t === 'restream' && stream.value) { loadRestreams(); restreamTimer = setInterval(loadRestreams, 5000); }
});
onBeforeUnmount(() => { off.forEach((f) => f()); stopBrowser(); clearInterval(restreamTimer); });

async function save() { try { const r = await patch(`/api/studio/live/${stream.value.id}`, { ...form.value, categoryId: form.value.categoryId || null, scheduledAt: form.value.scheduledAt ? new Date(form.value.scheduledAt).toISOString() : null }); await patch(`/api/studio/live/${stream.value.id}/interact`, { qaEnabled: form.value.qaEnabled, pollsEnabled: form.value.pollsEnabled }); stream.value = { ...stream.value, ...r.stream, qaEnabled: form.value.qaEnabled, pollsEnabled: form.value.pollsEnabled }; ui.toast('Сохранено', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function resetKey() { if (!(await ui.ask({ title: 'Сменить ключ трансляции?', message: 'Старый ключ перестанет работать — обновите настройки в OBS.', okLabel: 'Сменить' }))) return; const r = await post(`/api/studio/live/${stream.value.id}/reset-key`, {}); await load(); ui.toast('Ключ обновлён'); }
async function endStream() { if (!(await ui.ask({ title: 'Завершить эфир?', message: 'Зрители будут отключены. Если включена запись, она будет обработана и опубликована.', okLabel: 'Завершить', danger: true }))) return; await post(`/api/studio/live/${stream.value.id}/end`, {}); stopBrowser(); load(); }
async function reopen() { await patch(`/api/studio/live/${stream.value.id}`, { status: 'idle' }); load(); }
async function remove() { if (!(await ui.ask({ title: 'Удалить трансляцию?', message: 'Запись эфира (если есть) останется на канале.', okLabel: 'Удалить', danger: true }))) return; await del(`/api/studio/live/${stream.value.id}`); router.push('/studio/live'); }
async function copy(t) { await copyWithToast(ui, t); }

// --- Эфир из браузера (WHIP / WebRTC) ---------------------------------------------
const browserState = ref('idle'); // idle | preview | live | error
const browserError = ref('');
const source = ref('camera');
const preview = ref(null);
const quality = ref('720');
let mediaStream = null; let pc = null; let whipLocation = null;
const devices = ref({ cams: [], mics: [] });
const sel = ref({ cam: '', mic: '' });
async function listDevices() { try { const d = await navigator.mediaDevices.enumerateDevices(); devices.value = { cams: d.filter((x) => x.kind === 'videoinput'), mics: d.filter((x) => x.kind === 'audioinput') }; } catch { /* ignore */ } }
async function startPreview() {
  browserError.value = '';
  try {
    stopMedia();
    const q = { '1080': { width: 1920, height: 1080 }, '720': { width: 1280, height: 720 }, '480': { width: 854, height: 480 } }[quality.value];
    if (source.value === 'screen') {
      mediaStream = await navigator.mediaDevices.getDisplayMedia({ video: { ...q, frameRate: 30 }, audio: true });
      try { const mic = await navigator.mediaDevices.getUserMedia({ audio: sel.value.mic ? { deviceId: { exact: sel.value.mic } } : true }); for (const t of mic.getAudioTracks()) mediaStream.addTrack(t); } catch { /* без микрофона */ }
    } else {
      mediaStream = await navigator.mediaDevices.getUserMedia({ video: { ...q, frameRate: 30, deviceId: sel.value.cam ? { exact: sel.value.cam } : undefined }, audio: sel.value.mic ? { deviceId: { exact: sel.value.mic } } : true });
    }
    mediaStream.getVideoTracks()[0]?.addEventListener('ended', () => { if (browserState.value === 'live') stopBrowser(); });
    preview.value.srcObject = mediaStream;
    browserState.value = 'preview';
    listDevices();
  } catch (e) { browserError.value = e.message || 'Не удалось получить доступ к камере/экрану'; browserState.value = 'error'; }
}
async function goLive() {
  if (!mediaStream) return;
  browserError.value = '';
  try {
    pc = new RTCPeerConnection({ iceServers: [] });
    for (const track of mediaStream.getTracks()) {
      const transceiver = pc.addTransceiver(track, { direction: 'sendonly' });
      const sender = transceiver.sender;
      if (track.kind === 'video') {
        // HLS для зрителей умеет H.264 / VP9 / AV1, но не VP8 (кодек WebRTC по умолчанию) —
        // просим браузер кодировать в H.264 (Chrome, Edge, Safari, Firefox), иначе VP9, затем AV1
        try {
          const caps = RTCRtpSender.getCapabilities?.('video');
          if (caps && transceiver.setCodecPreferences) {
            const rank = (c) => { const m = c.mimeType.toLowerCase(); if (m === 'video/h264') return /packetization-mode=1/.test(c.sdpFmtpLine || '') ? 0 : 1; if (m === 'video/vp9') return 2; if (m === 'video/av1') return 3; if (m === 'video/vp8') return 5; return 4; };
            transceiver.setCodecPreferences([...caps.codecs].sort((a, b) => rank(a) - rank(b)));
          }
        } catch { /* оставляем кодек по умолчанию */ }
        const p = sender.getParameters(); p.encodings = [{ maxBitrate: { '1080': 5_000_000, '720': 2_800_000, '480': 1_400_000 }[quality.value] }]; sender.setParameters(p).catch(() => {});
      }
    }
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await new Promise((r) => { if (pc.iceGatheringState === 'complete') return r(); const t = setTimeout(r, 2000); pc.addEventListener('icegatheringstatechange', () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); r(); } }); });
    const url = stream.value.ingest.whipUrl;
    const res = await fetch(url, { method: 'POST', headers: { 'content-type': 'application/sdp' }, body: pc.localDescription.sdp });
    if (!res.ok) throw new Error(`Сервер трансляций ответил ${res.status}. Проверьте, что WebRTC-порты открыты.`);
    whipLocation = res.headers.get('location');
    await pc.setRemoteDescription({ type: 'answer', sdp: await res.text() });
    pc.addEventListener('connectionstatechange', () => { if (['failed', 'disconnected', 'closed'].includes(pc.connectionState) && browserState.value === 'live') { browserError.value = 'Соединение с сервером потеряно'; browserState.value = 'preview'; } });
    browserState.value = 'live';
    ui.toast('Вы в эфире!', { type: 'success' });
  } catch (e) { browserError.value = e.message; try { pc?.close(); } catch { /* ignore */ } pc = null; }
}
function stopMedia() { if (mediaStream) { for (const t of mediaStream.getTracks()) t.stop(); mediaStream = null; } if (preview.value) preview.value.srcObject = null; }
async function stopBrowser() {
  if (pc) { try { pc.close(); } catch { /* ignore */ } pc = null; }
  if (whipLocation) { try { await fetch(new URL(whipLocation, stream.value?.ingest?.whipUrl || location.origin), { method: 'DELETE' }); } catch { /* ignore */ } whipLocation = null; }
  stopMedia();
  browserState.value = 'idle';
}
const whipSupported = computed(() => !!(navigator.mediaDevices && window.RTCPeerConnection) && auth.config?.whipEnabled);
</script>

<template>
  <div v-if="error"><EmptyState icon="broadcast" title="Трансляция не найдена" :text="error.message" /></div>
  <div v-else-if="stream">
    <div class="row mb-16"><router-link to="/studio/live" class="btn ghost sm"><Icon name="back" :size="18" /> Трансляции</router-link></div>
    <div class="page-head">
      <div><h1 style="font-family: var(--font-body); text-transform: none; font-size: 26px; letter-spacing: 0">{{ stream.title }}</h1>
        <div class="row wrap gap-8 mt-8"><span class="badge" :class="stream.status === 'live' ? 'live' : stream.status === 'ended' ? '' : 'brand'">{{ { idle: 'Готова к эфиру', live: 'В эфире', ended: 'Завершена' }[stream.status] }}</span><span v-if="stream.status === 'live'" class="small muted"><Icon name="eye" :size="14" style="vertical-align:-2px" /> {{ fmtNumber(stream.viewerCount) }} зрителей • {{ timeAgo(stream.startedAt) }}<span v-if="stream.sourceProtocol"> • {{ stream.sourceProtocol.toUpperCase() }}</span></span></div></div>
      <div class="actions"><router-link :to="`/live/${stream.shortId}`" class="btn" target="_blank"><Icon name="openNew" :size="16" /> Страница эфира</router-link><button v-if="stream.status === 'live'" class="btn danger primary" @click="endStream"><Icon name="stop" :size="18" /> Завершить эфир</button><button v-else-if="stream.status === 'ended'" class="btn" @click="reopen"><Icon name="replay" :size="18" /> Открыть заново</button></div>
    </div>
    <div class="tabs mb-24"><button class="tab" :class="{ active: tab === 'setup' }" @click="tab = 'setup'">Подключение</button><button class="tab" :class="{ active: tab === 'browser' }" @click="tab = 'browser'" v-if="whipSupported">Эфир из браузера</button><button class="tab" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">Настройки</button><button class="tab" :class="{ active: tab === 'chat' }" @click="tab = 'chat'">Чат и просмотр</button><button class="tab" :class="{ active: tab === 'interact' }" @click="tab = 'interact'">Опросы и вопросы</button><button class="tab" :class="{ active: tab === 'people' }" @click="tab = 'people'">Участники</button><button v-if="auth.config?.restreamEnabled !== false" class="tab" :class="{ active: tab === 'restream' }" @click="tab = 'restream'">Ретрансляция</button></div>

    <div v-if="tab === 'setup'" class="two-col">
      <div class="col gap-24">
        <div class="panel">
          <h3 class="mb-8">OBS Studio / RTMP</h3>
          <p class="small muted">В OBS: Настройки → Вещание → Сервис «Другой…». Рекомендуемые параметры: 1080p30, кодек x264/NVENC, битрейт 4000–6000 кбит/с, интервал ключевых кадров 2 с.</p>
          <div class="field mb-8"><label>Сервер</label><div class="code-box"><span>{{ stream.ingest?.rtmpUrl || 'Адрес RTMP не настроен администратором' }}</span><button class="ibtn sm" @click="copy(stream.ingest?.rtmpUrl)" aria-label="Скопировать адрес RTMP"><Icon name="copy" :size="16" /></button></div></div>
          <div class="field"><label>Ключ потока</label><div class="code-box"><span>{{ showKey ? stream.streamKey : '•'.repeat(24) }}</span><button class="ibtn sm" @click="showKey = !showKey" :aria-label="showKey ? 'Скрыть ключ трансляции' : 'Показать ключ трансляции'"><Icon :name="showKey ? 'eyeOff' : 'eye'" :size="16" /></button><button class="ibtn sm" @click="copy(stream.streamKey)" aria-label="Скопировать ключ трансляции"><Icon name="copy" :size="16" /></button></div><div class="hint">Никому не сообщайте ключ — любой, кто его знает, сможет вещать от вашего имени.</div></div>
          <div class="row wrap mt-16"><button class="btn sm" :disabled="stream.status === 'live'" @click="resetKey"><Icon name="refresh" :size="16" /> Сменить ключ</button></div>
        </div>
        <div class="panel" v-if="stream.ingest?.srtUrl">
          <h3 class="mb-8">SRT</h3><p class="small muted">Для профессиональных кодеров и OBS с нестабильными каналами (режим caller).</p>
          <div class="code-box"><span>{{ stream.ingest.srtUrl }}</span><button class="ibtn sm" @click="copy(stream.ingest.srtUrl)" aria-label="Скопировать адрес SRT"><Icon name="copy" :size="16" /></button></div>
        </div>
      </div>
      <div class="panel soft">
        <h3 class="mb-8">Как это работает</h3>
        <ol class="small" style="padding-left: 18px; line-height: 1.7">
          <li>Заполните название и описание, выберите видимость.</li>
          <li>Скопируйте сервер и ключ в OBS (или откройте вкладку «Эфир из браузера»).</li>
          <li>Нажмите «Начать трансляцию» в OBS — статус здесь изменится на «В эфире» автоматически, подписчики получат уведомление.</li>
          <li>Зрители смотрят эфир с задержкой 5–15 секунд и общаются в чате.</li>
          <li>После остановки вещания эфир завершится сам. Запись появится на канале через несколько минут.</li>
        </ol>
      </div>
    </div>

    <div v-else-if="tab === 'browser'" class="two-col">
      <div class="panel">
        <h3 class="mb-8">Эфир из браузера</h3>
        <p class="small muted">Без установки программ: камера с микрофоном или демонстрация экрана. Работает в Chrome, Edge, Firefox и Safari.</p>
        <div class="browser-preview"><video ref="preview" autoplay muted playsinline></video><div v-if="browserState === 'idle'" class="bp-empty"><Icon name="webcam" :size="40" /><div class="small">Выберите источник и нажмите «Проверить»</div></div><span v-if="browserState === 'live'" class="badge live" style="position:absolute;top:10px;left:10px">В эфире</span></div>
        <div class="row wrap mt-16">
          <select class="select" v-model="source" style="width: 200px" :disabled="browserState === 'live'"><option value="camera">Камера + микрофон</option><option value="screen">Экран + микрофон</option></select>
          <select class="select" v-model="quality" style="width: 140px" :disabled="browserState === 'live'"><option value="1080">1080p</option><option value="720">720p</option><option value="480">480p</option></select>
          <select v-if="devices.cams.length && source === 'camera'" class="select" v-model="sel.cam" style="width: 220px" :disabled="browserState === 'live'"><option value="">Камера по умолчанию</option><option v-for="d in devices.cams" :key="d.deviceId" :value="d.deviceId">{{ d.label || 'Камера' }}</option></select>
          <select v-if="devices.mics.length" class="select" v-model="sel.mic" style="width: 220px" :disabled="browserState === 'live'"><option value="">Микрофон по умолчанию</option><option v-for="d in devices.mics" :key="d.deviceId" :value="d.deviceId">{{ d.label || 'Микрофон' }}</option></select>
        </div>
        <div v-if="browserError" class="alert danger mt-16"><Icon name="alertCircle" :size="18" /> {{ browserError }}</div>
        <div class="row wrap mt-16">
          <button v-if="browserState !== 'live'" class="btn" @click="startPreview"><Icon name="camera" :size="18" /> {{ browserState === 'preview' ? 'Переподключить источник' : 'Проверить' }}</button>
          <button v-if="browserState === 'preview'" class="btn primary" @click="goLive"><Icon name="broadcast" :size="18" /> Выйти в эфир</button>
          <button v-if="browserState === 'live'" class="btn danger primary" @click="stopBrowser"><Icon name="stop" :size="18" /> Остановить</button>
          <button v-if="browserState === 'preview'" class="btn ghost" @click="stopBrowser">Отмена</button>
        </div>
      </div>
      <div class="panel soft small"><h3 class="mb-8">Советы</h3><p>Не закрывайте вкладку во время эфира. Для демонстрации экрана выберите окно или весь экран и включите «Поделиться звуком», если нужен звук приложения. Проводное подключение к сети даст стабильную картинку.</p></div>
    </div>

    <div v-else-if="tab === 'settings'" class="panel">
      <div class="form-grid">
        <div class="field" style="grid-column: 1 / -1"><label>Название</label><input class="input" v-model="form.title" maxlength="150" /></div>
        <div class="field" style="grid-column: 1 / -1"><label>Описание</label><textarea class="textarea" v-model="form.description"></textarea></div>
        <div class="field"><label>Видимость</label><select class="select" v-model="form.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select></div>
        <div class="field"><label>Категория</label><select class="select" v-model="form.categoryId"><option value="">Без категории</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div class="field"><label>Запланировано на</label><input class="input" type="datetime-local" v-model="form.scheduledAt" /></div>
        <div class="field" style="justify-content: flex-end; gap: 12px"><label class="switch"><input type="checkbox" v-model="form.chatEnabled" /><span class="track"></span><span>Чат зрителей</span></label><label class="switch"><input type="checkbox" v-model="form.record" /><span class="track"></span><span>Записывать эфир</span></label></div>
        <!-- Живые субтитры эфира (1.6): распознавание речи по ходу трансляции -->
        <div class="field" v-if="auth.config?.liveCaptions">
          <label class="switch"><input type="checkbox" v-model="form.captions" /><span class="track"></span><span>Живые субтитры</span></label>
          <div class="hint">Речь распознаётся по ходу эфира: зрители видят субтитры поверх видео, а у записи они сразу появятся отдельной дорожкой.</div>
        </div>
        <!-- Перемотка эфира назад (1.9) -->
        <div class="field" v-if="auth.config?.dvrEnabled !== false">
          <label class="switch"><input type="checkbox" v-model="form.dvr" /><span class="track"></span><span>Разрешить перемотку эфира назад</span></label>
          <div class="hint">Зритель сможет отмотать трансляцию назад или включить её с начала — глубина до {{ Math.round((auth.config?.dvrMinutes || 120) / 60) }} ч. Нужна включённая запись эфира.</div>
        </div>
        <div class="field" style="grid-column: 1 / -1; flex-direction: row; gap: 24px; flex-wrap: wrap"><label class="switch"><input type="checkbox" v-model="form.qaEnabled" /><span class="track"></span><span>Вопросы спикеру (Q&amp;A с голосованием)</span></label><label class="switch"><input type="checkbox" v-model="form.pollsEnabled" /><span class="track"></span><span>Опросы зрителей</span></label><span class="small muted">Запланированный эфир: зрители могут включить напоминание и добавить событие в календарь (.ics)</span></div>
        <div class="field" style="grid-column: 1 / -1"><label class="switch"><input type="checkbox" v-model="form.registration" /><span class="track"></span><span>Вебинар с регистрацией участников</span></label><div class="hint">Зрители записываются заранее, получают напоминание за 15 минут, а вы — список участников и отчёт о посещении на вкладке «Участники».</div></div>
        <template v-if="form.registration">
          <div class="field"><label>Ограничение мест</label><input class="input" type="number" min="1" v-model.number="form.registrationLimit" placeholder="без ограничения" /></div>
          <div class="field" style="grid-column: 2 / -1"><label>Пояснение при регистрации</label><input class="input" v-model="form.registrationNote" maxlength="200" placeholder="Например: только для отдела продаж" /></div>
        </template>
      </div>
      <div class="form-actions"><button class="btn primary" @click="save">Сохранить</button><button class="btn ghost danger" :disabled="stream.status === 'live'" @click="remove">Удалить трансляцию</button></div>
    </div>

    <!-- Ретрансляция на внешние площадки (1.9) -->
    <div v-else-if="tab === 'restream'" class="panel">
      <h3>Ретрансляция на площадки</h3>
      <p class="small muted">Один эфир уходит сразу в несколько мест: портал отдаёт копию потока на RTMP-адрес каждой площадки. Ключ трансляции площадки хранится в закрытом виде и в списке не показывается.</p>
      <div v-if="!restreams.enabled" class="alert warning mt-8"><Icon name="alertCircle" :size="20" /><span>Ретрансляция отключена администратором.</span></div>
      <template v-else>
        <div class="table-wrap mt-8"><table class="table"><thead><tr><th>Площадка</th><th>Адрес</th><th>Состояние</th><th></th></tr></thead><tbody>
          <tr v-for="r in restreams.restreams" :key="r.id" :class="{ muted: !r.enabled }">
            <td><b>{{ r.name }}</b><div v-if="r.keyHint" class="tiny muted">ключ: {{ r.keyHint }}</div></td>
            <td class="mono tiny ellipsis" style="max-width:260px">{{ r.url }}</td>
            <td class="small">
              <span class="badge" :class="r.status === 'live' ? 'success' : r.status === 'error' ? 'danger' : ''">{{ RESTREAM_STATUS[r.status] || r.status }}</span>
              <div v-if="r.startedAt" class="tiny muted">с {{ fmtDateTime(r.startedAt) }}</div>
              <div v-if="r.lastError" class="tiny" style="color:var(--danger)">{{ r.lastError }}</div>
            </td>
            <td class="actions nowrap"><button class="ibtn sm" :title="r.enabled ? 'Выключить' : 'Включить'" @click="toggleTarget(r)" :aria-label="r.enabled ? 'Выключить' : 'Включить'"><Icon :name="r.enabled ? 'eyeOff' : 'eye'" :size="16" /></button><button class="ibtn sm" title="Убрать" @click="removeTarget(r)" aria-label="Убрать"><Icon name="delete" :size="16" /></button></td>
          </tr>
          <tr v-if="!restreams.restreams.length"><td colspan="4" class="muted">Площадок пока нет</td></tr>
        </tbody></table></div>
        <div class="panel soft mt-16">
          <div class="row wrap gap-4 mb-8"><button v-for="p in PRESETS" :key="p[0]" class="chip" @click="usePreset(p)">{{ p[0] }}</button></div>
          <div class="form-grid">
            <div class="field"><label>Название</label><input class="input" v-model="newTarget.name" placeholder="VK Видео" /></div>
            <div class="field"><label>Адрес (RTMP/SRT)</label><input class="input" v-model="newTarget.url" placeholder="rtmps://vsu.okcdn.ru/input" /></div>
            <div class="field" style="grid-column: 1 / -1"><label>Ключ трансляции площадки</label><input class="input" v-model="newTarget.streamKey" placeholder="ключ из личного кабинета площадки" /></div>
          </div>
          <div class="form-actions"><button class="btn primary" @click="addTarget"><Icon name="plus" :size="16" /> Добавить площадку</button><span class="small muted">Ретрансляция запускается автоматически при старте эфира</span></div>
        </div>
      </template>
    </div>

    <div v-else-if="tab === 'people'" class="panel">
      <div class="row mb-8" style="align-items:center">
        <h3 class="grow">Участники вебинара</h3>
        <a class="btn ghost sm" :href="$url(`/api/live/${stream.id}/attendees?format=csv`)"><Icon name="csv" :size="16" /> CSV</a>
        <button class="btn ghost sm" @click="loadAttendees"><Icon name="refresh" :size="16" /> Обновить</button>
      </div>
      <p v-if="!form.registration" class="small muted">Регистрация выключена — в списке будут только те, кто смотрел эфир. Включить регистрацию можно на вкладке «Настройки».</p>
      <div v-if="!attendees" class="loading-block"><div class="spin"></div></div>
      <template v-else>
        <div class="row wrap gap-16 mb-8 small">
          <span>Зарегистрировано: <b>{{ attendees.totals.registered }}</b></span>
          <span>Были на эфире: <b>{{ attendees.totals.attended }}</b></span>
          <span v-if="attendees.totals.noShow">Не пришли: <b>{{ attendees.totals.noShow }}</b></span>
          <span v-if="attendees.totals.avgPercent">Средняя доля просмотра: <b>{{ attendees.totals.avgPercent }}%</b></span>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Участник</th><th>Регистрация</th><th>Был на эфире</th><th>Время</th><th>Доля</th></tr></thead><tbody>
          <tr v-for="p in attendees.people" :key="p.id">
            <td><b>{{ p.displayName }}</b><div class="tiny muted">{{ p.email }}</div></td>
            <td class="small muted">{{ p.registeredAt ? fmtDateTime(p.registeredAt) : '—' }}</td>
            <td><span class="badge" :class="p.attended ? 'success' : ''">{{ p.attended ? 'да' : 'нет' }}</span></td>
            <td class="small">{{ p.seconds ? Math.max(1, Math.round(p.seconds / 60)) + ' мин' : '—' }}</td>
            <td class="small">{{ p.percent === null ? '—' : p.percent + '%' }}</td>
          </tr>
          <tr v-if="!attendees.people.length"><td colspan="5" class="muted">Пока никто не записался и не смотрел</td></tr>
        </tbody></table></div>
      </template>
    </div>

    <div v-else-if="tab === 'chat'" class="two-col">
      <div><VideoPlayer v-if="stream.status === 'live'" :src="stream.hlsUrl" live compact :allow-theater="false" :allow-mini="false" muted /><div v-else class="player live-placeholder" style="display:flex;align-items:center;justify-content:center;color:#fff">Эфир не идёт</div></div>
      <div style="height: 520px"><LiveChat :stream="stream" /></div>
    </div>

    <div v-else class="two-col">
      <div class="panel" style="height: 560px; display:flex; flex-direction:column"><LiveInteract :stream="stream" manage /></div>
      <div class="panel soft small col gap-8">
        <h3 class="mb-4">Как вести интерактив</h3>
        <p><b>Вопросы.</b> Зрители задают вопросы (в том числе анонимно) и голосуют за чужие — самые важные поднимаются выше. Отмечайте отвеченные и при желании пишите ответ текстом, скрывайте неуместные. После эфира список можно выгрузить в CSV.</p>
        <p><b>Опросы.</b> Создайте вопрос с вариантами (один или несколько ответов) — он появится у всех зрителей мгновенно. Результаты видны всем (или только вам, если скрыть). Завершите опрос, чтобы зафиксировать итоги.</p>
        <a class="btn sm" :href="$url(`/api/live/${stream.shortId}/questions/export`)"><Icon name="csv" :size="16" /> Экспорт вопросов CSV</a>
        <div style="height: 300px"><LiveChat :stream="stream" /></div>
      </div>
    </div>
  </div>
  <div v-else class="loading-block"><div class="spin"></div></div>
</template>

<style>
.browser-preview { position: relative; aspect-ratio: 16/9; background: #000; border-radius: var(--radius); overflow: hidden; }
.browser-preview video { width: 100%; height: 100%; object-fit: contain; }
.bp-empty { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: rgba(255,255,255,0.7); }
</style>
