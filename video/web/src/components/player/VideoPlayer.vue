<script setup>
// Собственный видеоплеер: HLS (hls.js/нативный), качество, скорость, субтитры, главы, раскадровка при наведении,
// горячие клавиши, театральный режим, «картинка в картинке», мини-плеер, отчёт о прогрессе просмотра.
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import Hls from 'hls.js';
import { fmtDuration } from '../../utils/format.js';
import { createQoe } from '../../utils/qoe.js';

const props = defineProps({
  src: { type: String, default: '' },           // HLS master.m3u8
  mp4: { type: String, default: '' },           // фолбэк
  poster: { type: String, default: '' },
  subtitles: { type: Array, default: () => [] }, // [{url,label,language,isDefault}]
  chapters: { type: Array, default: () => [] },  // [{start,title}]
  storyboard: { type: String, default: '' },     // VTT раскадровки
  duration: { type: Number, default: 0 },
  startAt: { type: Number, default: 0 },
  autoplay: { type: Boolean, default: false },
  live: { type: Boolean, default: false },
  title: { type: String, default: '' },
  hasNext: { type: Boolean, default: false },
  theater: { type: Boolean, default: false },
  allowTheater: { type: Boolean, default: true },
  allowMini: { type: Boolean, default: true },
  compact: { type: Boolean, default: false },   // embed / мини-режим — меньше элементов
  loopDefault: { type: Boolean, default: false },
  muted: { type: Boolean, default: false },
  audio: { type: String, default: '' },          // аудиодорожка (m4a) для режима «только звук» (1.3)
  audioOnly: { type: Boolean, default: false },
  // 1.4
  heatmap: { type: Array, default: () => [] },       // 100 точек 0…1 — «часто пересматривают»
  reactionMarks: { type: Array, default: () => [] }, // [{t,total,kinds}] — реакции по таймкоду
  reactionKinds: { type: Array, default: () => [] }, // [{id,label}]
  audioTracks: { type: Array, default: () => [] },   // [{id,label,kind,url}] — дубляж и тифлокомментарий
  introEnd: { type: Number, default: 0 },
  outroStart: { type: Number, default: 0 },
  logo: { type: String, default: '' },
  // 1.6 — живые субтитры эфира (распознавание речи по ходу трансляции)
  captionLines: { type: Array, default: () => [] },   // [{seq,text}] — последние реплики
  // 1.5 — метрики качества воспроизведения
  qoeVideoId: { type: String, default: '' },
  qoeStreamId: { type: String, default: '' },
  qoeSource: { type: String, default: 'watch' },
  qoe: { type: Boolean, default: true },
});
const emit = defineEmits(['progress', 'ended', 'play', 'pause', 'next', 'theater', 'mini', 'error', 'ready', 'timeupdate', 'help', 'react']);

const root = ref(null);
const video = ref(null);
const bar = ref(null);
const state = ref({
  playing: false, waiting: false, ended: false, error: '', time: 0, duration: props.duration || 0, buffered: 0,
  volume: 1, mutedState: props.muted, speed: 1, fullscreen: false, pip: false, controlsVisible: true, seeking: false,
  captions: null, quality: -1, autoLevel: null, loop: props.loopDefault, settingsOpen: '', hoverTime: -1, hoverX: 0,
  bigIcon: '', touchLeft: 0, touchRight: 0,
  // 1.4
  audioTrack: '', remote: 'unavailable', capSize: 100, capBg: 75, reactOpen: false, skipHidden: false,
  // 1.12: восстановление после обрыва сети — номер попытки (0 — всё в порядке)
  reconnect: 0,
});
const REACTION_ICON = { like: 'thumbUp', love: 'heart', wow: 'sparkles', question: 'help' };
let hls = null;
// Восстановление после сетевых ошибок: один таймер, растущая пауза, ограниченное число попыток
let retryTimer = null;
let netRetries = 0;
let mediaRecoveries = 0;
let lastMediaErrorAt = 0;
const MAX_NET_RETRIES = 6;          // запись: ~1+2+4+8+10+10 с, дальше — сообщение и кнопка
function clearRetry() { clearTimeout(retryTimer); retryTimer = null; }
const qoe = createQoe({ videoId: props.qoeVideoId || null, streamId: props.qoeStreamId || null, source: props.qoeSource, enabled: props.qoe });
let hideTimer = null;
let progressTimer = null;
let lastTick = 0;
let watchedDelta = 0;
let bucketsSeen = new Set();
let bucketsPending = new Set();
const levels = ref([]);
const sbCues = ref([]);
const sbBase = ref('');

const SPEEDS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3];

const isLive = computed(() => props.live);
const heatPath = computed(() => {
  const pts = props.heatmap || [];
  if (pts.length < 4) return '';
  const w = 100, h = 22;
  const step = w / (pts.length - 1);
  // Сглаженная кривая по точкам удержания
  let d = `M 0 ${h}`;
  pts.forEach((v, i) => { d += ` L ${(i * step).toFixed(2)} ${(h - Math.max(0.03, v) * h).toFixed(2)}`; });
  return `${d} L ${w} ${h} Z`;
});
const showSkipIntro = computed(() => !isLive.value && props.introEnd > 1 && state.value.time < props.introEnd - 0.4 && state.value.time > 0.3 && !state.value.skipHidden);
const showSkipOutro = computed(() => !isLive.value && props.outroStart > 1 && dur.value && state.value.time >= props.outroStart && state.value.time < dur.value - 0.6 && !state.value.skipHidden);
const reactionDots = computed(() => {
  const d = dur.value;
  if (!d) return [];
  const max = (props.reactionMarks || []).reduce((m, x) => Math.max(m, x.total), 0) || 1;
  return (props.reactionMarks || []).map((m) => ({ ...m, left: Math.min(100, (m.t / d) * 100), weight: Math.max(0.35, m.total / max) }));
});
const dur = computed(() => state.value.duration || props.duration || 0);
const pct = computed(() => (dur.value ? Math.min(100, (state.value.time / dur.value) * 100) : 0));
const bufPct = computed(() => (dur.value ? Math.min(100, (state.value.buffered / dur.value) * 100) : 0));
const currentChapter = computed(() => {
  const ch = props.chapters || [];
  let cur = null;
  for (const c of ch) if (c.start <= state.value.time) cur = c; else break;
  return cur;
});
const chapterSegments = computed(() => {
  const ch = (props.chapters || []).filter((c) => c.start < dur.value);
  if (!dur.value || ch.length < 2) return [];
  return ch.map((c, i) => ({ left: (c.start / dur.value) * 100, width: (((ch[i + 1]?.start ?? dur.value) - c.start) / dur.value) * 100, title: c.title }));
});
const hoverCue = computed(() => {
  if (state.value.hoverTime < 0) return null;
  return sbCues.value.find((c) => state.value.hoverTime >= c.start && state.value.hoverTime < c.end) || null;
});
const hoverChapter = computed(() => {
  if (state.value.hoverTime < 0) return null;
  let cur = null;
  for (const c of props.chapters || []) if (c.start <= state.value.hoverTime) cur = c; else break;
  return cur;
});
const volumeIcon = computed(() => (state.value.mutedState || state.value.volume === 0 ? 'volumeOff' : state.value.volume < 0.5 ? 'volumeMed' : 'volumeHigh'));
const qualityLabel = computed(() => {
  if (!levels.value.length) return '';
  if (state.value.quality === -1) { const l = levels.value[state.value.autoLevel]; return `Авто${l ? ' (' + l.label + ')' : ''}`; }
  return levels.value[state.value.quality]?.label || '';
});

// --- 1.4: дополнительная звуковая дорожка (дубляж, тифлокомментарий) ---
let altAudio = null;
function applyAudioTrack(id) {
  const el = video.value;
  state.value.audioTrack = id || '';
  if (altAudio) { altAudio.pause(); altAudio.remove(); altAudio = null; }
  if (!el) return;
  if (!id) { el.muted = state.value.mutedState; return; }
  const t = (props.audioTracks || []).find((x) => x.id === id);
  if (!t?.url) { state.value.audioTrack = ''; return; }
  altAudio = document.createElement('audio');
  altAudio.src = t.url;
  altAudio.preload = 'auto';
  altAudio.crossOrigin = 'use-credentials';
  altAudio.volume = state.value.volume;
  altAudio.playbackRate = state.value.speed;
  altAudio.currentTime = el.currentTime || 0;
  el.muted = true;                       // звук идёт с выбранной дорожки
  if (!el.paused) altAudio.play().catch(() => {});
  syncAlt();
}
function syncAlt() {
  const el = video.value;
  if (!altAudio || !el) return;
  // Расхождение больше 0,3 с слышно — подтягиваем дорожку к видео
  if (Math.abs(altAudio.currentTime - el.currentTime) > 0.3) altAudio.currentTime = el.currentTime;
  if (altAudio.playbackRate !== el.playbackRate) altAudio.playbackRate = el.playbackRate;
  if (el.paused && !altAudio.paused) altAudio.pause();
  if (!el.paused && altAudio.paused) altAudio.play().catch(() => {});
}

// --- 1.4: вид субтитров (размер и подложка) ---
function applyCaptionStyle() {
  const el = root.value;
  if (!el) return;
  el.style.setProperty('--cue-size', `${state.value.capSize / 100}em`);
  el.style.setProperty('--cue-bg', `rgba(0,0,0,${state.value.capBg / 100})`);
}
function setCapSize(v) { state.value.capSize = v; lsSet('capSize', v); applyCaptionStyle(); }
function setCapBg(v) { state.value.capBg = v; lsSet('capBg', v); applyCaptionStyle(); }

// --- 1.4: трансляция на ТВ (Remote Playback API / AirPlay) ---
function setupRemote() {
  const el = video.value;
  if (!el) return;
  if (window.WebKitPlaybackTargetAvailabilityEvent) {
    el.addEventListener('webkitplaybacktargetavailabilitychanged', (e) => { state.value.remote = e.availability === 'available' ? 'available' : 'unavailable'; });
  }
  if (el.remote?.watchAvailability) {
    el.remote.watchAvailability((available) => { state.value.remote = available ? 'available' : 'unavailable'; }).catch(() => { state.value.remote = 'maybe'; });
    el.remote.addEventListener?.('connect', () => { state.value.remote = 'connected'; });
    el.remote.addEventListener?.('disconnect', () => { state.value.remote = 'available'; });
  }
}
function castToTv() {
  const el = video.value;
  if (!el) return;
  if (el.webkitShowPlaybackTargetPicker) { el.webkitShowPlaybackTargetPicker(); return; }
  el.remote?.prompt?.().catch(() => {});
}

// --- 1.4: реакции по таймкоду и пропуск вступления ---
function react(kind) {
  emit('react', { kind, t: Math.round((video.value?.currentTime || 0) * 10) / 10 });
  state.value.reactOpen = false;
}
function skipIntro() { seekTo(props.introEnd + 0.1); state.value.skipHidden = false; }
function skipOutro() { if (props.hasNext) emit('next'); else seekTo(dur.value); }

function ls(key, def) { try { const v = localStorage.getItem('cv:player:' + key); return v === null ? def : JSON.parse(v); } catch { return def; } }
function lsSet(key, v) { try { localStorage.setItem('cv:player:' + key, JSON.stringify(v)); } catch { /* ignore */ } }

// --- Инициализация источника ------------------------------------------------------
function destroySource() {
  clearRetry();
  if (hls) { hls.destroy(); hls = null; }
  levels.value = [];
}
/** «Повторить» после ошибки: всё с чистого листа. */
function retry() {
  netRetries = 0; mediaRecoveries = 0; state.value.reconnect = 0;
  loadSource({ autoplay: true });
}
/** Сетевая ошибка hls.js: ждём с растущей паузой; эфир ждём бесконечно, запись — ограниченно. */
function onNetworkError(data) {
  if (data.response?.code === 401 || data.response?.code === 403) { state.value.error = 'Нет доступа к видео. Войдите в систему.'; state.value.waiting = false; return; }
  if (retryTimer) return;                          // уже ждём — второй таймер не заводим
  netRetries += 1;
  if (!props.live && netRetries > MAX_NET_RETRIES) {
    state.value.waiting = false; state.value.reconnect = 0;
    state.value.error = navigator.onLine === false ? 'Нет подключения к сети. Проверьте связь и нажмите «Повторить».' : 'Не удаётся загрузить видео: сервер не отвечает. Нажмите «Повторить».';
    qoe.error(`network: ${data.details || ''}`);
    emit('error', data);
    return;
  }
  state.value.waiting = true; state.value.reconnect = netRetries;
  const delay = Math.min(10000, 1000 * 2 ** Math.min(netRetries - 1, 4));
  retryTimer = setTimeout(() => { retryTimer = null; if (hls) hls.startLoad(); }, delay);
}
/** Ошибка декодера: сначала обычное восстановление, при повторе — со сменой аудиокодека, потом — сообщение. */
function onMediaError(data) {
  const now = Date.now();
  if (now - lastMediaErrorAt > 10000) mediaRecoveries = 0;
  lastMediaErrorAt = now; mediaRecoveries += 1;
  if (mediaRecoveries === 1) hls.recoverMediaError();
  else if (mediaRecoveries === 2) { hls.swapAudioCodec(); hls.recoverMediaError(); }
  else { state.value.error = 'Не удалось воспроизвести видео в этом браузере'; qoe.error(`${data.type}: ${data.details || ''}`); emit('error', data); }
}
function onOnline() { if (state.value.error) retry(); else if (retryTimer) { clearRetry(); hls?.startLoad(); } }
function loadSource(opts = {}) {
  destroySource();
  const el = video.value;
  if (!el) return;
  state.value.error = ''; state.value.ended = false; state.value.reconnect = 0;
  qoe.loadStart();
  // Автозапуск можно переопределить: при смене режима «только звук» продолжаем ровно то, что было (пауза — значит пауза)
  const wantPlay = opts.autoplay !== undefined ? opts.autoplay : props.autoplay;
  const src = props.src;
  if (props.audioOnly && props.audio) {
    // Режим «только звук»: проигрываем аудиодорожку, экран остаётся с обложкой
    el.src = props.audio;
    el.addEventListener('loadedmetadata', () => { applyStart(); emit('ready'); if (wantPlay) tryPlay(); }, { once: true });
    return;
  }
  if (src && Hls.isSupported()) {
    hls = new Hls({
      lowLatencyMode: props.live, liveSyncDurationCount: 3, backBufferLength: 60, maxBufferLength: 40, enableWorker: true,
      xhrSetup: (xhr) => { xhr.withCredentials = true; },
      fetchSetup: (ctx, init) => new Request(ctx.url, { ...init, credentials: 'include' }),
    });
    hls.loadSource(src);
    hls.attachMedia(el);
    hls.on(Hls.Events.MANIFEST_PARSED, (_, data) => {
      levels.value = data.levels.map((l, i) => ({ index: i, height: l.height, label: l.height ? `${l.height}p` : `${Math.round((l.bitrate || 0) / 1000)} кбит/с`, bitrate: l.bitrate }));
      const preferred = ls('quality', -1);
      if (preferred !== -1) { const idx = levels.value.findIndex((l) => l.height === preferred); if (idx >= 0) { hls.currentLevel = idx; state.value.quality = idx; } }
      applyStart();
      emit('ready');
      if (wantPlay) tryPlay();
    });
    hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => { state.value.autoLevel = data.level; const l = levels.value[data.level]; if (l) qoe.quality(l.height, l.bitrate); });
    // Фрагмент пришёл — связь восстановлена
    hls.on(Hls.Events.FRAG_LOADED, () => { if (netRetries || state.value.reconnect) { netRetries = 0; state.value.reconnect = 0; } });
    hls.on(Hls.Events.ERROR, (_, data) => {
      // Нефатальные сбои загрузки фрагментов hls.js повторяет сам (до ~30 с) — показываем, что идёт
      // восстановление, чтобы зритель не смотрел на застывший кадр без объяснений
      if (!data.fatal) {
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR && /^frag(Load|LoadTimeOut)/.test(data.details || '')) {
          state.value.waiting = true; state.value.reconnect = Math.max(state.value.reconnect, 2);
        }
        return;
      }
      if (data.type === Hls.ErrorTypes.NETWORK_ERROR) onNetworkError(data);
      else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) onMediaError(data);
      else { state.value.error = 'Не удалось воспроизвести видео'; qoe.error(`${data.type}: ${data.details || ''}`); emit('error', data); }
    });
  } else if (src && el.canPlayType('application/vnd.apple.mpegurl')) {
    el.src = src;
    el.addEventListener('loadedmetadata', () => { applyStart(); emit('ready'); if (wantPlay) tryPlay(); }, { once: true });
  } else if (props.mp4) {
    el.src = props.mp4;
    el.addEventListener('loadedmetadata', () => { applyStart(); emit('ready'); if (wantPlay) tryPlay(); }, { once: true });
  } else {
    state.value.error = 'Видео недоступно для воспроизведения в этом браузере';
  }
}
function applyStart() {
  const el = video.value;
  if (props.startAt > 0 && !props.live && el) { try { el.currentTime = props.startAt; } catch { /* ignore */ } }
}
async function tryPlay() {
  try { await video.value.play(); } catch (e) {
    // автозапуск заблокирован — пробуем без звука
    if (!video.value.muted) { video.value.muted = true; state.value.mutedState = true; try { await video.value.play(); } catch { /* ignore */ } }
  }
}

// --- Раскадровка -----------------------------------------------------------------
async function loadStoryboard() {
  sbCues.value = [];
  if (!props.storyboard) return;
  try {
    const r = await fetch(props.storyboard, { credentials: 'include' });
    if (!r.ok) return;
    const text = await r.text();
    sbBase.value = props.storyboard.slice(0, props.storyboard.lastIndexOf('/') + 1);
    const cues = [];
    const re = /(\d+):(\d+):(\d+)\.(\d+)\s+-->\s+(\d+):(\d+):(\d+)\.(\d+)\s*\n([^\n]+)/g;
    let m;
    while ((m = re.exec(text))) {
      const start = +m[1] * 3600 + +m[2] * 60 + +m[3] + +m[4] / 1000;
      const end = +m[5] * 3600 + +m[6] * 60 + +m[7] + +m[8] / 1000;
      const [file, frag] = m[9].trim().split('#xywh=');
      const [x, y, w, h] = (frag || '0,0,160,90').split(',').map(Number);
      cues.push({ start, end, url: sbBase.value + file, x, y, w, h });
    }
    sbCues.value = cues;
  } catch { /* ignore */ }
}

// --- События видео ----------------------------------------------------------------
function onTime() {
  const el = video.value; if (!el) return;
  state.value.time = el.currentTime;
  if (!state.value.duration && Number.isFinite(el.duration)) state.value.duration = el.duration;
  const now = Date.now();
  if (state.value.playing && lastTick) {
    const d = (now - lastTick) / 1000;
    if (d > 0 && d < 3) { watchedDelta += d; qoe.watched(d); }
  }
  lastTick = now;
  if (dur.value) {
    const b = Math.min(99, Math.floor((el.currentTime / dur.value) * 100));
    if (!bucketsSeen.has(b)) { bucketsSeen.add(b); bucketsPending.add(b); }
  }
  emit('timeupdate', el.currentTime);
  try { const br = el.buffered; if (br.length) { for (let i = 0; i < br.length; i++) if (br.start(i) <= el.currentTime && el.currentTime <= br.end(i) + 0.5) { state.value.buffered = br.end(i); break; } } } catch { /* ignore */ }
}
function flushProgress(force = false) {
  if (props.live) return;
  if (!force && watchedDelta < 1) return;
  const el = video.value;
  emit('progress', { position: el ? el.currentTime : state.value.time, delta: Math.round(watchedDelta * 10) / 10, buckets: [...bucketsPending] });
  watchedDelta = 0; bucketsPending = new Set();
}
function onPlay() { state.value.playing = true; state.value.ended = false; lastTick = Date.now(); emit('play'); scheduleHide(); showBig('play'); syncAlt(); }
function onPause() { state.value.playing = false; lastTick = 0; emit('pause'); state.value.controlsVisible = true; flushProgress(true); showBig('pause'); altAudio?.pause(); }
function onEnded() { state.value.playing = false; state.value.ended = true; flushProgress(true); emit('ended'); state.value.controlsVisible = true; }
function onWaiting() { state.value.waiting = true; qoe.waiting(); }
function onPlaying() { state.value.waiting = false; qoe.firstFrame(); qoe.playing(); }
function onVolume() { const el = video.value; state.value.volume = el.volume; state.value.mutedState = el.muted; lsSet('volume', el.volume); lsSet('muted', el.muted); }
function onLoaded() { const el = video.value; if (Number.isFinite(el.duration)) state.value.duration = el.duration; }
function onError() { if (!hls) { state.value.error = 'Ошибка воспроизведения'; qoe.error(video.value?.error?.message || 'media error'); } }

// --- Управление --------------------------------------------------------------------
function togglePlay() { const el = video.value; if (!el) return; if (el.paused) tryPlay(); else el.pause(); }
function seekTo(t) { const el = video.value; if (!el || !Number.isFinite(t)) return; el.currentTime = Math.max(0, Math.min(dur.value || t, t)); state.value.time = el.currentTime; flushProgress(true); syncAlt(); }
function seekBy(d) { seekTo((video.value?.currentTime || 0) + d); showBig(d > 0 ? 'fwd10' : 'back10'); }
function setVolume(v) { const el = video.value; el.volume = Math.max(0, Math.min(1, v)); if (el.volume > 0 && !state.value.audioTrack) el.muted = false; if (altAudio) altAudio.volume = el.volume; }
function toggleMute() { const el = video.value; el.muted = !el.muted; if (!el.muted && el.volume === 0) el.volume = 0.5; }
function setSpeed(s) { state.value.speed = s; video.value.playbackRate = s; if (altAudio) altAudio.playbackRate = s; lsSet('speed', s); state.value.settingsOpen = ''; }
function setQuality(idx) { state.value.quality = idx; if (hls) hls.currentLevel = idx; lsSet('quality', idx === -1 ? -1 : levels.value[idx]?.height); state.value.settingsOpen = ''; }
function toggleLoop() { state.value.loop = !state.value.loop; video.value.loop = state.value.loop; }
function setCaptions(idx) {
  state.value.captions = idx;
  const tracks = video.value?.textTracks || [];
  for (let i = 0; i < tracks.length; i++) tracks[i].mode = i === idx ? 'showing' : 'hidden';
  lsSet('captions', idx === null ? null : props.subtitles[idx]?.language || null);
  state.value.settingsOpen = '';
}
function toggleCaptions() {
  if (!props.subtitles.length) return;
  if (state.value.captions === null) setCaptions(defaultCaptionIndex()); else setCaptions(null);
}
function defaultCaptionIndex() {
  const pref = ls('captions', null);
  let idx = props.subtitles.findIndex((s) => s.language === pref);
  if (idx < 0) idx = props.subtitles.findIndex((s) => s.isDefault);
  return idx < 0 ? 0 : idx;
}
async function toggleFullscreen() {
  const el = root.value;
  try {
    if (!document.fullscreenElement) { await (el.requestFullscreen?.() || video.value.webkitEnterFullscreen?.()); }
    else await document.exitFullscreen();
  } catch { /* ignore */ }
}
async function togglePip() {
  try {
    if (document.pictureInPictureElement) await document.exitPictureInPicture();
    else if (video.value.requestPictureInPicture) await video.value.requestPictureInPicture();
  } catch { /* ignore */ }
}
function onFsChange() { state.value.fullscreen = !!document.fullscreenElement; }
function showBig(icon) { state.value.bigIcon = icon; setTimeout(() => { if (state.value.bigIcon === icon) state.value.bigIcon = ''; }, 500); }

// Прогресс-бар: перетаскивание
function barTime(e) {
  const rect = bar.value.getBoundingClientRect();
  const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
  return Math.max(0, Math.min(1, x / rect.width)) * dur.value;
}
function onBarDown(e) {
  if (props.live && !dur.value) return;
  state.value.seeking = true;
  seekTo(barTime(e));
  const move = (ev) => { const t = barTime(ev); state.value.time = t; state.value.hoverTime = t; state.value.hoverX = ((ev.touches ? ev.touches[0].clientX : ev.clientX) - bar.value.getBoundingClientRect().left); };
  const up = (ev) => { state.value.seeking = false; seekTo(state.value.time); state.value.hoverTime = -1; window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); window.removeEventListener('touchmove', move); window.removeEventListener('touchend', up); };
  window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
  window.addEventListener('touchmove', move, { passive: true }); window.addEventListener('touchend', up);
}
function onBarHover(e) {
  if (!dur.value) return;
  const rect = bar.value.getBoundingClientRect();
  state.value.hoverX = e.clientX - rect.left;
  state.value.hoverTime = barTime(e);
}
function onBarLeave() { if (!state.value.seeking) state.value.hoverTime = -1; }

// Автоскрытие панели
function scheduleHide() {
  clearTimeout(hideTimer);
  state.value.controlsVisible = true;
  if (state.value.playing && !state.value.settingsOpen) hideTimer = setTimeout(() => { state.value.controlsVisible = false; }, 2600);
}
function onMouseLeave() { if (state.value.playing && !state.value.settingsOpen) state.value.controlsVisible = false; }

// Клики и касания
let clickTimer = null;
function onSurfaceClick(e) {
  if (state.value.settingsOpen) { state.value.settingsOpen = ''; return; }
  if (clickTimer) { clearTimeout(clickTimer); clickTimer = null; toggleFullscreen(); return; }
  clickTimer = setTimeout(() => { clickTimer = null; togglePlay(); }, 220);
}
let lastTap = 0;
function onTouch(e) {
  const now = Date.now();
  const rect = root.value.getBoundingClientRect();
  const x = e.changedTouches[0].clientX - rect.left;
  if (now - lastTap < 300) {
    e.preventDefault();
    if (x < rect.width / 3) { seekBy(-10); state.value.touchLeft++; } else if (x > (rect.width * 2) / 3) { seekBy(10); state.value.touchRight++; } else togglePlay();
    lastTap = 0;
  } else { lastTap = now; scheduleHide(); }
}

// Клавиатура
function onKey(e) {
  if (e.target.closest('input, textarea, select, [contenteditable]')) return;
  const el = video.value; if (!el) return;
  const k = e.key;
  const handled = true;
  switch (k) {
    case ' ': case 'k': case 'K': togglePlay(); break;
    case 'ArrowLeft': seekBy(-5); break;
    case 'ArrowRight': seekBy(5); break;
    case 'j': case 'J': case 'о': seekBy(-10); break;
    case 'l': case 'L': case 'д': seekBy(10); break;
    case 'ArrowUp': setVolume(el.volume + 0.05); break;
    case 'ArrowDown': setVolume(el.volume - 0.05); break;
    case 'm': case 'M': case 'ь': toggleMute(); break;
    case 'f': case 'F': case 'а': toggleFullscreen(); break;
    case 't': case 'T': case 'е': if (props.allowTheater) emit('theater'); break;
    case 'i': case 'I': case 'ш': if (props.allowMini) emit('mini'); break;
    case 'c': case 'C': case 'с': toggleCaptions(); break;
    case 'p': case 'P': case 'з': togglePip(); break;
    case 'n': case 'N': case 'т': if (e.shiftKey && props.hasNext) emit('next'); else return; break;
    case '?': emit('help'); break;
    case 'Home': seekTo(0); break;
    case 'End': seekTo(dur.value); break;
    case '<': case ',': if (e.shiftKey || k === '<') setSpeed(SPEEDS[Math.max(0, SPEEDS.indexOf(state.value.speed) - 1)]); else if (el.paused) seekBy(-1 / 25); break;
    case '>': case '.': if (e.shiftKey || k === '>') setSpeed(SPEEDS[Math.min(SPEEDS.length - 1, SPEEDS.indexOf(state.value.speed) + 1)]); else if (el.paused) seekBy(1 / 25); break;
    default:
      if (/^[0-9]$/.test(k) && dur.value) { seekTo((dur.value * Number(k)) / 10); break; }
      return;
  }
  if (handled) { e.preventDefault(); scheduleHide(); }
}
function focusIn() { root.value?.addEventListener('keydown', onKey); }

function currentTime() { return video.value?.currentTime || 0; }
function isPaused() { return video.value?.paused ?? true; }
defineExpose({ seekTo, togglePlay, currentTime, isPaused, play: tryPlay, pause: () => video.value?.pause(), flushProgress, el: () => video.value, handleKey: onKey });

function onUnload() { flushProgress(true); qoe.stop(); }

onMounted(() => {
  const el = video.value;
  el.volume = ls('volume', 1); el.muted = props.muted || ls('muted', false);
  state.value.volume = el.volume; state.value.mutedState = el.muted;
  const sp = ls('speed', 1); if (SPEEDS.includes(sp)) { state.value.speed = sp; el.playbackRate = sp; }
  el.loop = state.value.loop;
  // Служебный хук (диагностика/автотесты): прямой источник вместо HLS — event.detail = URL
  el.addEventListener('cv:direct-src', (e) => { destroySource(); state.value.error = ''; el.src = e.detail; });
  loadSource();
  loadStoryboard();
  state.value.capSize = Number(ls('capSize', 100)) || 100;
  state.value.capBg = Number(ls('capBg', 75));
  applyCaptionStyle();
  setupRemote();
  document.addEventListener('fullscreenchange', onFsChange);
  el.addEventListener('enterpictureinpicture', () => { state.value.pip = true; });
  el.addEventListener('leavepictureinpicture', () => { state.value.pip = false; });
  progressTimer = setInterval(() => { flushProgress(false); syncAlt(); }, 5000);
  qoe.start();
  window.addEventListener('beforeunload', onUnload);
  // pagehide надёжнее beforeunload на мобильных: там вкладку часто выгружают без него
  window.addEventListener('pagehide', onUnload);
  // Сеть вернулась — продолжаем без нажатия «Повторить»
  window.addEventListener('online', onOnline);
  root.value.addEventListener('keydown', onKey);
  nextTick(() => { if (props.subtitles.length && ls('captions', null)) setCaptions(defaultCaptionIndex()); else { const tracks = el.textTracks; for (let i = 0; i < tracks.length; i++) tracks[i].mode = 'hidden'; } });
});
onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline);
  clearTimeout(clickTimer);
  if (altAudio) { altAudio.pause(); altAudio.remove(); altAudio = null; }
  qoe.stop();
  flushProgress(true);
  clearInterval(progressTimer);
  clearTimeout(hideTimer);
  document.removeEventListener('fullscreenchange', onFsChange);
  window.removeEventListener('beforeunload', onUnload);
  window.removeEventListener('pagehide', onUnload);
  destroySource();
});
watch(() => props.src, () => { bucketsSeen = new Set(); bucketsPending = new Set(); watchedDelta = 0; state.value.time = 0; state.value.duration = props.duration || 0; loadSource(); loadStoryboard(); });
// Переключение «только звук» с сохранением позиции и состояния воспроизведения
watch(() => props.audioOnly, () => {
  const el = video.value; if (!el) return;
  const pos = el.currentTime || 0; const wasPlaying = !el.paused;
  flushProgress(true);
  loadSource({ autoplay: wasPlaying });
  const resume = () => { try { el.currentTime = pos; } catch { /* ignore */ } };
  if (hls) hls.once(Hls.Events.MANIFEST_PARSED, resume); else el.addEventListener('loadedmetadata', resume, { once: true });
});
watch(() => props.subtitles, () => nextTick(() => { const tracks = video.value?.textTracks || []; for (let i = 0; i < tracks.length; i++) tracks[i].mode = i === state.value.captions ? 'showing' : 'hidden'; }));
</script>

<template>
  <div ref="root" class="player" :class="{ playing: state.playing, 'hide-controls': !state.controlsVisible && state.playing, fullscreen: state.fullscreen, compact, live: isLive, 'has-error': !!state.error }" tabindex="0" @mousemove="scheduleHide" @mouseleave="onMouseLeave" @focus="focusIn">
    <video ref="video" class="player-video" :poster="poster" playsinline preload="metadata" crossorigin="use-credentials"
      @timeupdate="onTime" @play="onPlay" @pause="onPause" @ended="onEnded" @waiting="onWaiting" @playing="onPlaying" @canplay="onPlaying" @volumechange="onVolume" @loadedmetadata="onLoaded" @durationchange="onLoaded" @error="onError">
      <track v-for="(s, i) in subtitles" :key="s.url" kind="subtitles" :src="s.url" :srclang="s.language" :label="s.label" :default="false" />
    </video>

    <div v-if="audioOnly && audio" class="player-audio-cover" :style="poster ? { backgroundImage: `url(${poster})` } : {}"><div class="player-audio-badge"><Icon name="headphones" :size="22" /> Только звук</div></div>
    <div class="player-surface" @click="onSurfaceClick" @touchend="onTouch">
      <div v-if="state.waiting && !state.error" class="player-spinner"><div class="spin big"></div><div v-if="state.reconnect > 1" class="player-reconnect" role="status">{{ live ? 'Эфир прервался — ждём сигнал…' : 'Связь прервалась — восстанавливаем…' }}</div></div>
      <div v-if="state.bigIcon" class="player-big" :key="state.bigIcon"><Icon :name="state.bigIcon" :size="48" /></div>
      <div v-if="!state.playing && !state.ended && !state.error && !state.waiting" class="player-center"><button class="center-play" @click.stop="togglePlay" aria-label="Воспроизвести"><Icon name="play" :size="44" /></button></div>
      <div v-if="state.error" class="player-error"><Icon name="alertCircle" :size="40" /><p>{{ state.error }}</p><button class="btn sm" style="border-color:#fff;color:#fff" @click.stop="retry">Повторить</button></div>
      <div v-if="isLive" class="live-pill"><i></i> ЭФИР</div>
      <img v-if="logo" class="player-logo" :src="logo" alt="" aria-hidden="true" />
      <button v-if="showSkipIntro" class="skip-btn" @click.stop="skipIntro">Пропустить вступление <Icon name="next" :size="16" /></button>
      <button v-else-if="showSkipOutro" class="skip-btn" @click.stop="skipOutro">{{ hasNext ? 'Следующее видео' : 'В конец' }} <Icon name="next" :size="16" /></button>
      <div v-if="captionLines.length" class="live-captions" :class="{ up: state.controlsVisible }">
        <span v-for="l in captionLines.slice(-2)" :key="l.seq">{{ l.text }}</span>
      </div>
      <slot name="overlay" />
    </div>

    <div class="player-controls" @click.stop @mousemove.stop="scheduleHide">
      <div v-if="state.hoverTime >= 0" class="hover-tip" :style="{ left: state.hoverX + 'px' }">
        <div v-if="hoverCue" class="hover-thumb" :style="{ width: hoverCue.w + 'px', height: hoverCue.h + 'px', backgroundImage: `url(${hoverCue.url})`, backgroundPosition: `-${hoverCue.x}px -${hoverCue.y}px` }"></div>
        <div class="hover-time">{{ hoverChapter ? hoverChapter.title + ' · ' : '' }}{{ fmtDuration(state.hoverTime) }}</div>
      </div>
      <div ref="bar" class="bar" :class="{ seeking: state.seeking }" @mousedown.prevent="onBarDown" @touchstart.passive="onBarDown" @mousemove="onBarHover" @mouseleave="onBarLeave">
        <svg v-if="heatPath" class="heat" viewBox="0 0 100 22" preserveAspectRatio="none" aria-hidden="true"><path :d="heatPath" /></svg>
        <div v-if="reactionDots.length" class="react-marks" aria-hidden="true">
          <i v-for="(m, i) in reactionDots" :key="i" :style="{ left: m.left + '%', opacity: m.weight }" :title="`${m.total} реакц. на ${fmtDuration(m.t)}`"></i>
        </div>
        <div class="bar-track">
          <template v-if="chapterSegments.length">
            <div v-for="(seg, i) in chapterSegments" :key="i" class="seg" :style="{ left: seg.left + '%', width: seg.width + '%' }" :title="seg.title">
              <div class="seg-buf" :style="{ width: Math.max(0, Math.min(100, ((bufPct - seg.left) / seg.width) * 100)) + '%' }"></div>
              <div class="seg-play" :style="{ width: Math.max(0, Math.min(100, ((pct - seg.left) / seg.width) * 100)) + '%' }"></div>
            </div>
          </template>
          <template v-else>
            <div class="seg" style="left:0;width:100%"><div class="seg-buf" :style="{ width: bufPct + '%' }"></div><div class="seg-play" :style="{ width: pct + '%' }"></div></div>
          </template>
          <div class="knob" :style="{ left: pct + '%' }"></div>
        </div>
      </div>
      <div class="ctl-row">
        <button class="pbtn" @click="togglePlay" :title="state.playing ? 'Пауза (k)' : 'Смотреть (k)'" :aria-label="state.playing ? 'Пауза (k)' : 'Смотреть (k)'"><Icon :name="state.playing ? 'pause' : state.ended ? 'replay' : 'play'" :size="26" /></button>
        <button v-if="hasNext" class="pbtn" @click="$emit('next')" title="Следующее (Shift+N)" aria-label="Следующее (Shift+N)"><Icon name="next" :size="26" /></button>
        <div class="vol-wrap">
          <button class="pbtn" @click="toggleMute" :title="state.mutedState ? 'Включить звук (m)' : 'Выключить звук (m)'" :aria-label="state.mutedState ? 'Включить звук (m)' : 'Выключить звук (m)'"><Icon :name="volumeIcon" :size="24" /></button>
          <input class="vol" type="range" min="0" max="1" step="0.02" :value="state.mutedState ? 0 : state.volume" @input="setVolume(+$event.target.value)" aria-label="Громкость" />
        </div>
        <div class="time">
          <template v-if="isLive"><span class="live-dot"></span> Прямой эфир</template>
          <template v-else>{{ fmtDuration(state.time) }} <span class="sep">/</span> {{ fmtDuration(dur) }}</template>
          <span v-if="currentChapter && !compact" class="chapter"> • {{ currentChapter.title }}</span>
        </div>
        <div class="spacer"></div>
        <div v-if="reactionKinds.length" class="react-wrap">
          <button class="pbtn" :class="{ on: state.reactOpen }" @click="state.reactOpen = !state.reactOpen" title="Отметить момент" aria-label="Отметить момент"><Icon name="heartOutline" :size="24" /></button>
          <div v-if="state.reactOpen" class="react-menu">
            <button v-for="k in reactionKinds" :key="k.id" class="react-item" :title="k.label" @click="react(k.id)">
              <Icon :name="REACTION_ICON[k.id] || 'heart'" :size="20" /><span>{{ k.label }}</span>
            </button>
          </div>
        </div>
        <button v-if="state.remote !== 'unavailable'" class="pbtn hide-sm" :class="{ on: state.remote === 'connected' }" @click="castToTv" title="Смотреть на телевизоре" aria-label="Смотреть на телевизоре"><Icon name="castTv" :size="24" /></button>
        <button v-if="subtitles.length" class="pbtn" :class="{ on: state.captions !== null }" @click="toggleCaptions" title="Субтитры (c)" aria-label="Субтитры (c)"><Icon :name="state.captions !== null ? 'captionsFill' : 'captions'" :size="24" /></button>
        <div class="settings-wrap">
          <button class="pbtn" :class="{ on: state.settingsOpen }" @click="state.settingsOpen = state.settingsOpen ? '' : 'main'" title="Настройки" aria-label="Настройки"><Icon name="settings" :size="24" /></button>
          <div v-if="state.settingsOpen" class="pmenu">
            <template v-if="state.settingsOpen === 'main'">
              <button class="pm-item" @click="state.settingsOpen = 'speed'"><Icon name="speed" :size="18" /><span>Скорость</span><b>{{ state.speed === 1 ? 'Обычная' : state.speed + '×' }}</b><Icon name="chevronRight" :size="18" /></button>
              <button v-if="levels.length" class="pm-item" @click="state.settingsOpen = 'quality'"><Icon name="tune" :size="18" /><span>Качество</span><b>{{ qualityLabel }}</b><Icon name="chevronRight" :size="18" /></button>
              <button v-if="subtitles.length" class="pm-item" @click="state.settingsOpen = 'captions'"><Icon name="captions" :size="18" /><span>Субтитры</span><b>{{ state.captions === null ? 'Выкл.' : subtitles[state.captions]?.label }}</b><Icon name="chevronRight" :size="18" /></button>
              <button v-if="audioTracks.length" class="pm-item" @click="state.settingsOpen = 'audio'"><Icon name="headphones" :size="18" /><span>Звук</span><b>{{ state.audioTrack ? (audioTracks.find((t) => t.id === state.audioTrack)?.label || 'Дорожка') : 'Основной' }}</b><Icon name="chevronRight" :size="18" /></button>
              <button class="pm-item" @click="state.settingsOpen = 'capstyle'"><Icon name="tune" :size="18" /><span>Вид субтитров</span><b>{{ state.capSize }}%</b><Icon name="chevronRight" :size="18" /></button>
              <button v-if="!isLive" class="pm-item" @click="toggleLoop"><Icon name="loop" :size="18" /><span>Повтор</span><b>{{ state.loop ? 'Вкл.' : 'Выкл.' }}</b></button>
              <slot name="settings" />
            </template>
            <template v-else-if="state.settingsOpen === 'speed'">
              <button class="pm-item head" @click="state.settingsOpen = 'main'"><Icon name="chevronLeft" :size="18" /><span>Скорость</span></button>
              <button v-for="s in SPEEDS" :key="s" class="pm-item" :class="{ sel: s === state.speed }" @click="setSpeed(s)"><Icon :name="s === state.speed ? 'check' : 'dots'" :size="18" :style="s === state.speed ? '' : 'opacity:0'" /><span>{{ s === 1 ? 'Обычная' : s + '×' }}</span></button>
            </template>
            <template v-else-if="state.settingsOpen === 'quality'">
              <button class="pm-item head" @click="state.settingsOpen = 'main'"><Icon name="chevronLeft" :size="18" /><span>Качество</span></button>
              <button v-for="l in levels" :key="l.index" class="pm-item" :class="{ sel: l.index === state.quality }" @click="setQuality(l.index)"><Icon name="check" :size="18" :style="l.index === state.quality ? '' : 'opacity:0'" /><span>{{ l.label }}<small v-if="l.height >= 1080" class="hd">HD</small></span></button>
              <button class="pm-item" :class="{ sel: state.quality === -1 }" @click="setQuality(-1)"><Icon name="check" :size="18" :style="state.quality === -1 ? '' : 'opacity:0'" /><span>Авто</span></button>
            </template>
            <template v-else-if="state.settingsOpen === 'audio'">
              <button class="pm-item head" @click="state.settingsOpen = 'main'"><Icon name="chevronLeft" :size="18" /><span>Звуковая дорожка</span></button>
              <button class="pm-item" :class="{ sel: !state.audioTrack }" @click="applyAudioTrack('')"><Icon name="check" :size="18" :style="!state.audioTrack ? '' : 'opacity:0'" /><span>Основная</span></button>
              <button v-for="t in audioTracks" :key="t.id" class="pm-item" :class="{ sel: state.audioTrack === t.id }" @click="applyAudioTrack(t.id)">
                <Icon name="check" :size="18" :style="state.audioTrack === t.id ? '' : 'opacity:0'" /><span>{{ t.label }}<small v-if="t.kind === 'description'" class="hd">описание</small></span>
              </button>
            </template>
            <template v-else-if="state.settingsOpen === 'capstyle'">
              <button class="pm-item head" @click="state.settingsOpen = 'main'"><Icon name="chevronLeft" :size="18" /><span>Вид субтитров</span></button>
              <div class="pm-range"><span>Размер</span><input type="range" min="80" max="200" step="10" :value="state.capSize" @input="setCapSize(+$event.target.value)" aria-label="Размер субтитров" /><b>{{ state.capSize }}%</b></div>
              <div class="pm-range"><span>Подложка</span><input type="range" min="0" max="100" step="5" :value="state.capBg" @input="setCapBg(+$event.target.value)" aria-label="Непрозрачность подложки субтитров" /><b>{{ state.capBg }}%</b></div>
            </template>
            <template v-else-if="state.settingsOpen === 'captions'">
              <button class="pm-item head" @click="state.settingsOpen = 'main'"><Icon name="chevronLeft" :size="18" /><span>Субтитры</span></button>
              <button class="pm-item" :class="{ sel: state.captions === null }" @click="setCaptions(null)"><Icon name="check" :size="18" :style="state.captions === null ? '' : 'opacity:0'" /><span>Выключены</span></button>
              <button v-for="(s, i) in subtitles" :key="s.url" class="pm-item" :class="{ sel: state.captions === i }" @click="setCaptions(i)"><Icon name="check" :size="18" :style="state.captions === i ? '' : 'opacity:0'" /><span>{{ s.label }}</span></button>
            </template>
          </div>
        </div>
        <button v-if="allowMini && !compact" class="pbtn hide-sm" @click="$emit('mini')" title="Мини-плеер (i)" aria-label="Мини-плеер (i)"><Icon name="pip" :size="24" /></button>
        <button class="pbtn hide-sm" @click="togglePip" title="Картинка в картинке (p)" aria-label="Картинка в картинке (p)"><Icon name="openNew" :size="22" /></button>
        <button v-if="allowTheater && !compact" class="pbtn hide-sm" @click="$emit('theater')" title="Широкий экран (t)" aria-label="Широкий экран (t)"><Icon name="theater" :size="24" /></button>
        <button class="pbtn" @click="toggleFullscreen" title="Во весь экран (f)" aria-label="Во весь экран (f)"><Icon :name="state.fullscreen ? 'fullscreenExit' : 'fullscreen'" :size="26" /></button>
      </div>
    </div>
  </div>
</template>

<style>
.player { position: relative; width: 100%; aspect-ratio: 16 / 9; background: #000; border-radius: var(--radius); overflow: hidden; outline: none; user-select: none; font-family: var(--font-body); color: #fff; }
.player.fullscreen { border-radius: 0; aspect-ratio: auto; width: 100vw; height: 100vh; }
.player-video { width: 100%; height: 100%; object-fit: contain; background: #000; }
.player-video::cue { background: var(--cue-bg, rgba(0,0,0,0.75)); color: #fff; font-family: var(--font-body); font-size: var(--cue-size, 1.05em); line-height: 1.35; }
.live-captions { position: absolute; left: 50%; transform: translateX(-50%); bottom: 64px; max-width: min(90%, 900px); display: flex; flex-direction: column; gap: 4px; text-align: center; pointer-events: none; transition: bottom var(--t); }
.live-captions.up { bottom: 96px; }
.live-captions span { background: rgba(0,0,0,.78); color: #fff; padding: 4px 12px; border-radius: 6px; font-size: clamp(14px, 1.6vw, 20px); line-height: 1.35; display: inline-block; }
.player-logo { position: absolute; top: 12px; right: 12px; height: 26px; opacity: .75; pointer-events: none; filter: drop-shadow(0 1px 3px rgba(0,0,0,.6)); }
.skip-btn { position: absolute; right: 16px; bottom: 76px; display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px;
  background: rgba(0,0,0,0.75); color: #fff; border: 1px solid rgba(255,255,255,0.35); cursor: pointer; font-size: 14px; }
.skip-btn:hover { background: rgba(0,0,0,0.9); }
.heat { position: absolute; left: 0; right: 0; bottom: 6px; width: 100%; height: 22px; pointer-events: none; opacity: .55; }
.heat path { fill: rgba(255,255,255,0.55); }
.bar:hover .heat { opacity: .85; }
.react-marks { position: absolute; left: 0; right: 0; bottom: 1px; height: 12px; pointer-events: none; }
.react-marks i { position: absolute; bottom: 8px; width: 6px; height: 6px; margin-left: -3px; border-radius: 50%; background: var(--brand, #ffd54a); }
.react-wrap { position: relative; }
.react-menu { position: absolute; bottom: 46px; right: 0; background: rgba(28,28,28,0.96); border-radius: 10px; padding: 6px; min-width: 170px; display: flex; flex-direction: column; }
.react-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: none; border: 0; color: #fff; cursor: pointer; border-radius: 8px; font-size: 14px; text-align: left; }
.react-item:hover { background: rgba(255,255,255,0.12); }
.pm-range { display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: #fff; font-size: 14px; }
.pm-range input { flex: 1; }
.pm-range b { min-width: 42px; text-align: right; font-weight: 500; }
.player-surface { position: absolute; inset: 0; cursor: pointer; }
.player.hide-controls .player-surface { cursor: none; }
.player-spinner { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 12px; align-items: center; justify-content: center; pointer-events: none; }
.player-reconnect { color: #fff; font-size: 14px; background: rgba(0,0,0,.55); padding: 6px 12px; border-radius: 999px; }
.spin.big { width: 56px; height: 56px; border-width: 4px; border-color: rgba(255,255,255,0.25); border-top-color: #fff; }
.player-center { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.center-play { pointer-events: auto; width: 84px; height: 84px; border-radius: 50%; background: rgba(15, 67, 130, 0.85); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 30px rgba(0,0,0,0.4); transition: transform var(--t-fast), background var(--t-fast); }
.center-play:hover { transform: scale(1.06); background: var(--brand-500); }
.player.compact .center-play { width: 64px; height: 64px; }
.player-big { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.player-big .icon { padding: 18px; border-radius: 50%; background: rgba(0,0,0,0.6); animation: bigfade 0.5s ease forwards; }
@keyframes bigfade { from { opacity: 0.9; transform: scale(0.8); } to { opacity: 0; transform: scale(1.4); } }
.player-error { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; background: rgba(0,0,0,0.75); text-align: center; padding: 24px; }
.live-pill { position: absolute; top: 12px; left: 12px; display: flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: var(--pill); background: rgba(208,0,36,0.9); font-size: 12px; font-weight: 700; letter-spacing: 0.08em; }
.live-pill i { width: 8px; height: 8px; border-radius: 50%; background: #fff; animation: blink 1.2s infinite; }
@keyframes blink { 50% { opacity: 0.3; } }
.player-controls { position: absolute; left: 0; right: 0; bottom: 0; padding: 0 12px 4px; background: linear-gradient(to top, rgba(0,0,0,0.85), rgba(0,0,0,0.4) 60%, transparent); transition: opacity var(--t), transform var(--t); z-index: 2; }
.player.hide-controls .player-controls { opacity: 0; transform: translateY(6px); pointer-events: none; }
.bar { position: relative; height: 18px; display: flex; align-items: center; cursor: pointer; }
.bar-track { position: relative; width: 100%; height: 4px; transition: height var(--t-fast); }
.bar:hover .bar-track, .bar.seeking .bar-track { height: 6px; }
.seg { position: absolute; top: 0; bottom: 0; background: rgba(255,255,255,0.3); border-right: 2px solid rgba(0,0,0,0.6); overflow: hidden; }
.seg:last-child { border-right: 0; }
.seg-buf { position: absolute; left: 0; top: 0; bottom: 0; background: rgba(255,255,255,0.4); }
.seg-play { position: absolute; left: 0; top: 0; bottom: 0; background: var(--brand-300); }
.player .seg-play { background: #79A7C6; }
.knob { position: absolute; top: 50%; width: 14px; height: 14px; border-radius: 50%; background: #fff; transform: translate(-50%, -50%) scale(0); transition: transform var(--t-fast); box-shadow: 0 0 0 2px rgba(15,67,130,0.6); }
.bar:hover .knob, .bar.seeking .knob { transform: translate(-50%, -50%) scale(1); }
.hover-tip { position: absolute; bottom: 62px; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 4px; pointer-events: none; z-index: 3; }
.hover-thumb { border-radius: 6px; border: 2px solid #fff; background-repeat: no-repeat; box-shadow: 0 4px 16px rgba(0,0,0,0.5); }
.hover-time { padding: 2px 8px; border-radius: 4px; background: rgba(0,0,0,0.85); font-size: 12px; font-weight: 500; white-space: nowrap; }
.ctl-row { display: flex; align-items: center; gap: 2px; height: 44px; }
.pbtn { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; color: #fff; opacity: 0.92; transition: opacity var(--t-fast), background var(--t-fast); }
.pbtn:hover { opacity: 1; background: rgba(255,255,255,0.12); }
.pbtn.on { color: var(--brand-300); }
.pbtn.on::after { content: ''; display: block; position: absolute; width: 18px; height: 2px; background: var(--brand-300); margin-top: 30px; border-radius: 1px; }
.vol-wrap { display: flex; align-items: center; }
.vol { width: 0; opacity: 0; transition: width var(--t), opacity var(--t); accent-color: #fff; cursor: pointer; margin: 0 4px; }
.vol-wrap:hover .vol, .vol:focus { width: 80px; opacity: 1; }
.time { font-size: 13px; font-variant-numeric: tabular-nums; padding: 0 8px; white-space: nowrap; display: flex; align-items: center; gap: 4px; }
.time .sep { opacity: 0.6; margin: 0 2px; }
.time .chapter { opacity: 0.85; max-width: 240px; overflow: hidden; text-overflow: ellipsis; }
.live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--danger); display: inline-block; }
.settings-wrap { position: relative; }
.pmenu { position: absolute; right: 0; bottom: 48px; min-width: 240px; background: rgba(20, 22, 26, 0.96); border-radius: 12px; padding: 6px; box-shadow: 0 8px 30px rgba(0,0,0,0.5); z-index: 5; }
.pm-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 10px; border-radius: 8px; font-size: 13px; color: #fff; text-align: left; }
.pm-item:hover { background: rgba(255,255,255,0.1); }
.pm-item span { flex: 1; }
.pm-item b { font-weight: 400; opacity: 0.7; font-size: 12px; }
.pm-item.head { border-bottom: 1px solid rgba(255,255,255,0.15); border-radius: 0; margin-bottom: 4px; font-weight: 500; }
.pm-item .hd { margin-left: 4px; font-size: 9px; padding: 1px 4px; border: 1px solid #fff; border-radius: 3px; vertical-align: middle; }
.player.compact .ctl-row { height: 40px; }
.player.compact .pbtn { width: 34px; height: 34px; }
@media (max-width: 640px) {
  .hide-sm { display: none; }
  .time { font-size: 12px; }
  .time .chapter { display: none; }
  .vol { display: none; }
}
.player-audio-cover { position: absolute; inset: 0; background: #000 center/cover no-repeat; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.player-audio-cover::after { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,.35); }
.player-audio-badge { position: relative; z-index: 1; display: flex; align-items: center; gap: 8px; background: rgba(0,0,0,.7); color: #fff; padding: 8px 14px; border-radius: 20px; font-size: 14px; }
</style>
