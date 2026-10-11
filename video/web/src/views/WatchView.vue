<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import VideoPlayer from '../components/player/VideoPlayer.vue';
import CardsOverlay from '../components/watch/CardsOverlay.vue';
import ScreenTextPanel from '../components/watch/ScreenTextPanel.vue';
import ChatReplay from '../components/watch/ChatReplay.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import SubscribeButton from '../components/SubscribeButton.vue';
import VideoCard from '../components/VideoCard.vue';
import RichText from '../components/RichText.vue';
import Dropdown from '../components/Dropdown.vue';
import ShareDialog from '../components/ShareDialog.vue';
import PlaylistPicker from '../components/PlaylistPicker.vue';
import ReportDialog from '../components/ReportDialog.vue';
import CommentList from '../components/comments/CommentList.vue';
import EmptyState from '../components/EmptyState.vue';
import Modal from '../components/Modal.vue';
import QuizOverlay from '../components/watch/QuizOverlay.vue';
import NotesPanel from '../components/watch/NotesPanel.vue';
import PremiereChat from '../components/watch/PremiereChat.vue';
import MeetingNotes from '../components/watch/MeetingNotes.vue';
import ReviewPanel from '../components/watch/ReviewPanel.vue';
import ScenarioOverlay from '../components/watch/ScenarioOverlay.vue';
import ViewerWatermark from '../components/watch/ViewerWatermark.vue';
import AssignDialog from '../components/AssignDialog.vue';
import { fmtViews, fmtNumber, fmtDate, fmtDateTime, fmtSubs, fmtDuration, VISIBILITY, downloadBlob } from '../utils/format.js';
import { saveOffline, removeOffline, isOffline, offlineSupported } from '../utils/offline.js';
import { url, absUrl } from '../base.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();

const video = ref(null);
const related = ref([]);
const playlist = ref(null);
const loading = ref(true);
const error = ref(null);
const player = ref(null);
const descExpanded = ref(false);
const share = ref(false);
const save = ref(false);
const report = ref(false);
const helpOpen = ref(false);
const SHORTCUTS = [
  ['Пробел, K', 'пауза / воспроизведение'], ['← / →', 'перемотка на 5 с'], ['J / L', 'перемотка на 10 с'], ['↑ / ↓', 'громкость'], ['M', 'выключить звук'],
  ['F', 'полный экран'], ['T', 'театральный режим'], ['I', 'мини-плеер'], ['C', 'субтитры'], ['P', 'картинка в картинке'], ['Shift+N', 'следующее видео'],
  ['< / >', 'медленнее / быстрее'], [', / .', 'покадрово (на паузе)'], ['0–9', 'переход к 0–90 %'], ['Home / End', 'в начало / в конец'], ['?', 'эта подсказка'],
];

// --- Транскрипт (текст субтитров с таймкодами) --------------------------------------------
const transcript = ref({ open: false, loading: false, cues: [], query: '', trackId: null, active: -1, error: '' });
const transcriptTracks = computed(() => (video.value?.subtitles || []).filter((t) => t.url && t.status === 'ready'));
const transcriptFiltered = computed(() => {
  const q = transcript.value.query.trim().toLowerCase();
  return transcript.value.cues.map((c, i) => ({ ...c, i })).filter((c) => !q || c.text.toLowerCase().includes(q));
});
function parseVtt(text) {
  const cues = [];
  const toSec = (t) => { const [hms, ms] = t.trim().split(/[.,]/); const parts = hms.split(':').map(Number); return parts.reduce((a, b) => a * 60 + b, 0) + (Number(ms) || 0) / 1000; };
  for (const block of text.replace(/\r/g, '').split(/\n\n+/)) {
    const lines = block.split('\n').filter((l) => l.trim());
    const ti = lines.findIndex((l) => l.includes('-->'));
    if (ti < 0) continue;
    const [a, b] = lines[ti].split('-->');
    const body = lines.slice(ti + 1).join(' ').replace(/<[^>]+>/g, '').trim();
    if (!body) continue;
    const start = toSec(a), end = toSec(b.split(' ')[0] || b);
    const last = cues[cues.length - 1];
    // склеиваем очень короткие соседние реплики в абзацы по ~10 с для удобного чтения
    if (last && start - last.end < 1.5 && start - last.start < 6 && (last.text + ' ' + body).length < 160) { last.text += ' ' + body; last.end = end; } else cues.push({ start, end, text: body });
  }
  return cues;
}
async function loadTranscript(trackId) {
  const t = transcriptTracks.value.find((x) => x.id === trackId) || transcriptTracks.value[0];
  if (!t) return;
  transcript.value = { ...transcript.value, loading: true, trackId: t.id, cues: [], error: '', active: -1 };
  try {
    const r = await fetch(t.url, { credentials: 'include' });
    if (!r.ok) throw new Error('Не удалось загрузить субтитры');
    transcript.value.cues = parseVtt(await r.text());
  } catch (e) { transcript.value.error = e.message; } finally { transcript.value.loading = false; }
}
function toggleTranscript() {
  transcript.value.open = !transcript.value.open;
  if (transcript.value.open && !transcript.value.cues.length) loadTranscript(transcript.value.trackId);
}
let transcriptTimer = null;
function tickTranscript() {
  if (!transcript.value.open || !transcript.value.cues.length) return;
  const t = player.value?.currentTime() || 0;
  const idx = transcript.value.cues.findIndex((c, i) => t >= c.start && (i === transcript.value.cues.length - 1 || t < transcript.value.cues[i + 1].start));
  if (idx !== transcript.value.active) {
    transcript.value.active = idx;
    nextTick(() => { const el = document.querySelector('.tr-cue.active'); if (el && !transcript.value.query) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); });
  }
}
function seekCue(c) { player.value?.seekTo(c.start); player.value?.play(); }
watch(() => transcript.value.open, (open) => { clearInterval(transcriptTimer); if (open) transcriptTimer = setInterval(tickTranscript, 500); });
const endScreen = ref(false);
const countdown = ref(5);
let countdownTimer = null;
const startAt = ref(0);

// --- Премьера: обратный отсчёт, синхронная позиция, чат (1.6) ------------------------------
const premiere = ref(null);       // { state, at, startsInSec, position, chatEnabled }
const premiereLeft = ref(0);      // секунд до начала
let premiereTimer = null;
const premiereWaiting = computed(() => premiere.value?.state === 'scheduled');
const premiereLive = computed(() => premiere.value?.state === 'live');
const premiereClock = computed(() => {
  const s = Math.max(0, premiereLeft.value);
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), ss = Math.floor(s % 60);
  if (d > 0) return `${d} д ${h} ч`;
  return [h, m, ss].map((x) => String(x).padStart(2, '0')).join(':');
});
async function loadPremiere(seq = loadSeq) {
  clearInterval(premiereTimer);
  if (!video.value?.premiere) { premiere.value = null; return; }
  try {
    const r = await get(`/api/videos/${video.value.shortId}/premiere`);
    if (!isCurrentLoad(seq)) return;
    premiere.value = r.enabled ? r : null;
    premiereLeft.value = r.startsInSec || 0;
    if (r.state === 'live' && r.position > 0) startAt.value = r.position;
    if (r.state === 'scheduled') {
      premiereTimer = setInterval(() => {
        premiereLeft.value -= 1;
        if (premiereLeft.value <= 0) { clearInterval(premiereTimer); load(); }
      }, 1000);
    }
  } catch { premiere.value = null; }
}

// --- Офлайн-просмотр (1.7) ---------------------------------------------------------------------
const offline = ref({ saved: false, progress: 0, busy: false });
function refreshOffline() { offline.value.saved = !!video.value && isOffline(video.value.id); }
async function toggleOffline() {
  if (!video.value || offline.value.busy) return;
  if (offline.value.saved) {
    await removeOffline(video.value.id);
    refreshOffline();
    ui.toast('Видео удалено с устройства');
    return;
  }
  offline.value.busy = true;
  offline.value.progress = 0;
  try {
    await saveOffline(video.value, {
      days: Number(auth.config?.offlineDays) || 30,
      maxBytes: (Number(auth.config?.offlineMaxMb) || 0) * 1024 * 1024,
      onProgress: (p) => { offline.value.progress = p; },
    });
    refreshOffline();
    ui.toast('Видео доступно без сети — раздел «Скачанные»', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { offline.value.busy = false; }
}

// --- Совместный просмотр (1.6) ----------------------------------------------------------------
const partyBusy = ref(false);
async function startParty() {
  if (partyBusy.value) return;
  partyBusy.value = true;
  try {
    const r = await post(`/api/videos/${video.value.id}/party`, { position: player.value?.currentTime?.() || 0 });
    router.push(`/party/${r.party.code}`);
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { partyBusy.value = false; }
}
const source = ref('direct');
// --- 1.1: заметки, тест, водяной знак, назначение, фрагмент ------------------------------
const notesOpen = ref(false);
const notesCount = ref(0);
const quizRef = ref(null);
const quizState = ref(null);
const assign = ref(false);
const ended = ref(false);
const clipEnd = computed(() => { const e = Number(route.query.end); return Number.isFinite(e) && e > 0 ? e : 0; });
const clipDone = ref(false);
let clipTimer = null;
const watermarkText = computed(() => {
  if (!video.value?.viewerWatermark) return '';
  if (auth.user) return `${auth.user.displayName} · ${auth.user.email}`;
  return 'Гостевой доступ по ссылке';
});
const assignment = computed(() => video.value?.viewer?.assignment || null);
function dueText(a) {
  if (!a.dueAt) return 'без срока';
  const d = new Date(a.dueAt); const days = Math.ceil((d - Date.now()) / 86400000);
  if (days < 0) return `срок истёк ${d.toLocaleDateString('ru-RU')}`;
  if (days === 0) return 'срок — сегодня';
  return `до ${d.toLocaleDateString('ru-RU')}`;
}
function tickClip() {
  if (!clipEnd.value || clipDone.value || !player.value) return;
  if (player.value.currentTime() >= clipEnd.value) { player.value.pause(); clipDone.value = true; }
}
function replayClip() { clipDone.value = false; player.value?.seekTo(Number(route.query.t) || 0); player.value?.play(); }
function continueFull() { clipDone.value = false; router.replace({ query: { ...route.query, end: undefined, t: undefined } }); player.value?.play(); }
watch(clipEnd, (e) => { clearInterval(clipTimer); clipDone.value = false; if (e) clipTimer = setInterval(tickClip, 300); }, { immediate: true });
function onQuizPassed() { if (video.value?.viewer?.assignment) video.value.viewer.assignment.quizPassed = true; ui.toast('Тест сдан', { type: 'success' }); load(); }
// --- 1.2: вложения, контроль присутствия, календарь --------------------------------------------
const attachments = ref([]);
async function loadAttachments(seq = loadSeq) { try { const r = await get(`/api/videos/${video.value.shortId}/attachments`); if (isCurrentLoad(seq)) attachments.value = r.attachments; } catch { if (isCurrentLoad(seq)) attachments.value = []; } }
const attention = ref({ visible: false, watched: 0 });
let attentionTimer = null;
function tickAttention() {
  const a = assignment.value;
  if (!a || !a.attentionCheckMin || a.completed || !player.value || player.value.isPaused() || attention.value.visible) return;
  attention.value.watched += 1;
  if (attention.value.watched >= a.attentionCheckMin * 60) { attention.value.visible = true; player.value.pause(); }
}
function confirmAttention() { attention.value = { visible: false, watched: 0 }; player.value?.play(); }
watch(() => assignment.value?.attentionCheckMin, (m) => { clearInterval(attentionTimer); attention.value = { visible: false, watched: 0 }; if (m) attentionTimer = setInterval(tickAttention, 1000); }, { immediate: true });
function assignmentIcs() {
  const a = assignment.value; if (!a?.dueAt) return;
  const f = (d) => new Date(d).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
  const due = new Date(a.dueAt);
  const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CorpVideo//RU', 'BEGIN:VEVENT', `UID:asg-${a.id}@corpvideo`, `DTSTAMP:${f(new Date())}`, `DTSTART:${f(new Date(due.getTime() - 3600000))}`, `DTEND:${f(due)}`, `SUMMARY:Посмотреть: ${a.title.replace(/,/g, '\\,')}`, `DESCRIPTION:${absUrl(`/watch/${video.value.shortId}`)}`, `URL:${absUrl(`/watch/${video.value.shortId}`)}`, 'BEGIN:VALARM', 'TRIGGER:-P1D', 'ACTION:DISPLAY', 'DESCRIPTION:Срок обязательного просмотра', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
  downloadBlob(new Blob([ics], { type: 'text/calendar' }), 'assignment.ics');
}
// --- 1.3: текст на экране, повтор чата, очередь, клипы, режим «только звук» ----------------------
const screenOpen = ref(false);
const chatReplayOpen = ref(false);
const clips = ref([]);
const audioOnly = ref(false);
// 1.4: тепловая карта «часто пересматривают», реакции по таймкоду, дополнительные звуковые дорожки
const heatmap = ref([]);
const reactions = ref({ marks: [], kinds: [], totals: {}, canReact: false });
const audioTracks = ref([]);
async function loadEngage(seq = loadSeq) {
  const id = video.value?.shortId;
  if (!id) return;
  const [hm, rc, tr] = await Promise.all([
    auth.config?.heatmapEnabled === false ? Promise.resolve(null) : get(`/api/videos/${id}/heatmap`).catch(() => null),
    auth.config?.reactionsEnabled === false ? Promise.resolve(null) : get(`/api/videos/${id}/reactions`).catch(() => null),
    get(`/api/videos/${id}/audio-tracks`).catch(() => null),
  ]);
  if (!isCurrentLoad(seq)) return;
  heatmap.value = hm?.available ? hm.points : [];
  reactions.value = rc && !rc.disabled ? rc : { marks: [], kinds: [], totals: {}, canReact: false };
  audioTracks.value = tr?.tracks || [];
}
// 1.4: просмотр в рамках курса — показываем ленту возврата и следующий шаг
const courseCtx = ref(null);
async function loadCourseCtx(seq = loadSeq) {
  const id = route.query.course;
  if (!id) { courseCtx.value = null; return; }
  try {
    const r = await get(`/api/courses/${encodeURIComponent(String(id))}`);
    if (!isCurrentLoad(seq)) return;
    const idx = r.items.findIndex((it) => it.video?.id === video.value?.id);
    const next = r.items.slice(idx + 1).find((it) => it.kind === 'video' && it.video && !it.locked);
    courseCtx.value = { course: r.course, step: idx >= 0 ? idx + 1 : null, total: r.items.length, next };
  } catch { courseCtx.value = null; }
}

async function onReact(e) {
  try {
    await post(`/api/videos/${video.value.shortId}/reactions`, e);
    const rc = await get(`/api/videos/${video.value.shortId}/reactions`);
    reactions.value = rc;
    ui.toast(rc.mine?.length ? 'Момент отмечен' : 'Отметка снята');
  } catch (err) { ui.toast(err.message, { type: 'error' }); }
}
async function loadClips(seq = loadSeq) { try { const r = await get(`/api/videos/${video.value.shortId}/clips`); if (isCurrentLoad(seq)) clips.value = r.clips; } catch { if (isCurrentLoad(seq)) clips.value = []; } }
function queueThis() { if (!video.value) return; if (ui.queueAdd(video.value)) ui.toast('Добавлено в очередь', { type: 'success' }); else ui.toast('Уже в очереди'); }
function playQueued(q) { ui.queueRemove(q.id); router.push({ name: 'watch', params: { id: q.shortId } }); }
const ATT_ICONS = { pdf: 'doc', doc: 'doc', docx: 'doc', xls: 'csv', xlsx: 'csv', csv: 'csv', ppt: 'image', pptx: 'image', png: 'image', jpg: 'image', jpeg: 'image', gif: 'image', svg: 'image', zip: 'inventory', '7z': 'inventory' };

const theater = computed(() => ui.theater);
const nextVideo = computed(() => {
  // Текущее видео в очереди пропускаем: иначе после его окончания оно же и запустится заново
  const queued = ui.queue.find((x) => x.id !== video.value?.id);
  if (queued) return { ...queued, fromQueue: true };
  if (playlist.value) {
    const idx = playlist.value.videos.findIndex((v) => v.id === video.value?.id);
    const next = playlist.value.videos.slice(idx + 1).find((v) => !v.unavailable);
    if (next) return { ...next, list: playlist.value.playlist.id };
  }
  return related.value[0] || null;
});
const shareUrl = computed(() => absUrl(`/watch/${video.value?.shortId}`));
const playlistIndex = computed(() => (playlist.value ? playlist.value.videos.findIndex((v) => v.id === video.value?.id) + 1 : 0));

function detectSource() {
  const back = router.options.history.state?.back;
  if (route.query.src) return String(route.query.src);
  if (!back) return document.referrer && !document.referrer.startsWith(location.origin) ? 'external' : 'direct';
  if (back === '/') return 'home';
  if (back.startsWith('/search')) return 'search';
  if (back.startsWith('/subscriptions')) return 'subscriptions';
  if (back.startsWith('/@')) return 'channel';
  if (back.startsWith('/playlist') || route.query.list) return 'playlist';
  if (back.startsWith('/watch')) return 'related';
  if (back.startsWith('/trending')) return 'trending';
  if (back.startsWith('/history') || back.startsWith('/library') || back.startsWith('/watch-later') || back.startsWith('/liked')) return 'library';
  if (back.startsWith('/notifications')) return 'notification';
  return 'direct';
}

let loadSeq = 0;
/** Актуальна ли загрузка: при быстром переходе между видео ответы приходят вразнобой. */
const isCurrentLoad = (seq) => seq === loadSeq;
async function load() {
  const seq = ++loadSeq;
  loading.value = true; error.value = null; endScreen.value = false; clearInterval(countdownTimer); ended.value = false;
  descExpanded.value = false;
  try {
    const r = await get(`/api/videos/${route.params.id}`);
    if (!isCurrentLoad(seq)) return;
    video.value = r.video;
    ui.queueRemove(r.video.id); // открытое видео из очереди уходит — как «сейчас играет» у YouTube
    document.title = `${r.video.title} — ${auth.siteName}`;
    const t = Number(route.query.t);
    if (ui.miniPlayer?.returning && ui.miniPlayer.video?.id === r.video.id) { startAt.value = ui.miniPlayer.position; ui.closeMini(); }
    else startAt.value = Number.isFinite(t) && t > 0 ? t : (r.video.viewer?.position && r.video.viewer.position > 5 ? r.video.viewer.position : 0);
    source.value = detectSource();
    get(`/api/videos/${r.video.shortId}/related?limit=20`).then((x) => { if (isCurrentLoad(seq)) related.value = x.videos; }).catch(() => {});
    loadAttachments(seq); loadClips(seq); loadEngage(seq); loadCourseCtx(seq); loadPremiere(seq); refreshOffline();
    screenOpen.value = route.query.panel === 'screen' && !!r.video.hasScreenText;
    chatReplayOpen.value = route.query.panel === 'chat' && !!r.video.hasChatReplay;
    if (route.query.list) get(`/api/playlists/${route.query.list}?limit=200`).then((p) => { if (isCurrentLoad(seq)) playlist.value = p; }).catch(() => { if (isCurrentLoad(seq)) playlist.value = null; });
    else playlist.value = null;
  } catch (e) {
    if (!isCurrentLoad(seq)) return;
    error.value = e;
    document.title = auth.siteName;
  } finally { if (isCurrentLoad(seq)) loading.value = false; }
}

async function onProgress(p) {
  if (!video.value) return;
  try {
    const r = await post(`/api/videos/${video.value.shortId}/progress`, { position: p.position, watchedDelta: p.delta, buckets: p.buckets, source: source.value });
    if (r.counted && video.value.viewer && !video.value._counted) { video.value._counted = true; }
  } catch { /* ignore */ }
}
function onEnded() {
  ended.value = true;
  // Если есть несданный тест — сначала предлагаем его, автопереход не запускаем
  if (quizRef.value?.hasQuiz() && !quizRef.value.passed()) return;
  if (nextVideo.value && ui.autoplay) {
    endScreen.value = true; countdown.value = 5;
    countdownTimer = setInterval(() => { countdown.value--; if (countdown.value <= 0) { clearInterval(countdownTimer); goNext(); } }, 1000);
  } else if (nextVideo.value) endScreen.value = true;
}
function cancelAuto() { clearInterval(countdownTimer); endScreen.value = false; }
function goNext() {
  clearInterval(countdownTimer);
  const n = nextVideo.value;
  if (!n) return;
  if (n.fromQueue) ui.queueRemove(n.id);
  router.push({ name: 'watch', params: { id: n.shortId }, query: n.list ? { list: n.list } : {} });
}
async function like(value) {
  if (!auth.user) return router.push({ name: 'login', query: { next: route.fullPath } });
  if (!auth.isActive) return ui.toast('Дождитесь одобрения учётной записи');
  const v = video.value.viewer.like === value ? 0 : value;
  try {
    const r = await post(`/api/videos/${video.value.shortId}/like`, { value: v });
    video.value.viewer.like = v; video.value.likeCount = r.likeCount; video.value.dislikeCount = r.dislikeCount;
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function toggleWatchLater() {
  if (!auth.isActive) return ui.toast('Войдите, чтобы сохранять видео');
  const r = await post('/api/playlists/watch-later/toggle', { videoId: video.value.id });
  video.value.viewer.inWatchLater = r.inWatchLater;
  ui.toast(r.inWatchLater ? 'Добавлено в «Смотреть позже»' : 'Удалено из «Смотреть позже»', { type: 'success' });
}
function openMini() {
  const pos = player.value?.currentTime() || 0;
  const playing = player.value ? !player.value.isPaused() : false;
  ui.openMini(video.value, pos, playing);
  if (router.options.history.state?.back) router.back(); else router.push('/');
}
function toggleTheater() { ui.setTheater(!ui.theater); }
function onSeekEvent(e) { player.value?.seekTo(e.detail); player.value?.play(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
function onNavEvent(e) { router.push(e.detail); }
function onKey(e) {
  if (e.target.closest('input, textarea, select, [contenteditable]') || document.querySelector('.modal-backdrop')) return;
  if (e.key === 'Escape' && endScreen.value) return cancelAuto();
  player.value?.handleKey(e);
}
function downloadUrl(type) { return url(`/api/videos/${video.value.shortId}/download${type ? '?type=' + type : ''}`); }

watch(() => route.params.id, () => { if (route.name === 'watch') { transcript.value = { open: false, loading: false, cues: [], query: '', trackId: null, active: -1, error: '' }; notesOpen.value = false; notesCount.value = 0; screenOpen.value = false; chatReplayOpen.value = false; audioOnly.value = false; load(); } });
watch(() => route.query.list, () => { if (route.name === 'watch' && route.query.list && !playlist.value) load(); });
onMounted(() => { load(); window.addEventListener('cv:seek', onSeekEvent); window.addEventListener('cv:navigate', onNavEvent); document.addEventListener('keydown', onKey); });
onBeforeUnmount(() => { clearInterval(premiereTimer); clearInterval(countdownTimer); clearInterval(transcriptTimer); clearInterval(clipTimer); clearInterval(attentionTimer); window.removeEventListener('cv:seek', onSeekEvent); window.removeEventListener('cv:navigate', onNavEvent); document.removeEventListener('keydown', onKey); });
</script>

<template>
  <div class="watch" :class="{ theater }">
    <div v-if="loading && !video" class="watch-loading"><div class="skeleton" style="aspect-ratio: 16/9; border-radius: var(--radius)"></div></div>
    <div v-else-if="error" class="page narrow">
      <EmptyState :icon="error.status === 401 ? 'lock' : error.status === 403 ? 'accountOff' : 'alertCircle'" :title="error.status === 401 ? 'Требуется вход' : error.status === 403 ? 'Нет доступа' : 'Видео не найдено'" :text="error.message">
        <router-link v-if="error.status === 401" :to="{ name: 'login', query: { next: route.fullPath } }" class="btn primary">Войти</router-link>
        <router-link v-else to="/" class="btn">На главную</router-link>
      </EmptyState>
    </div>
    <template v-else-if="video">
      <div class="player-wrap" :class="{ theater }">
        <div class="player-inner">
          <div v-if="courseCtx" class="panel course-bar mb-8">
            <Icon name="school" :size="18" />
            <router-link class="grow ellipsis" :to="`/course/${courseCtx.course.slug || courseCtx.course.id}`"><b>{{ courseCtx.course.title }}</b></router-link>
            <span class="tiny muted nowrap" v-if="courseCtx.step">Шаг {{ courseCtx.step }} из {{ courseCtx.total }}</span>
            <router-link v-if="courseCtx.next" class="btn sm" :to="`/watch/${courseCtx.next.video.shortId}?course=${courseCtx.course.slug || courseCtx.course.id}`">Следующий шаг <Icon name="next" :size="16" /></router-link>
          </div>
          <div v-if="premiereWaiting" class="player premiere-card">
            <img v-if="video.thumbnailUrl" :src="video.thumbnailUrl" alt="" class="pr-poster" />
            <div class="pr-inner">
              <span class="badge brand"><Icon name="sparkles" :size="12" /> Премьера</span>
              <div class="pr-clock">{{ premiereClock }}</div>
              <div class="pr-when">Показ начнётся {{ fmtDateTime(premiere.at) }}</div>
              <div class="small" style="opacity:.85; max-width: 420px; text-align:center">
                Видео откроется у всех одновременно. Подпишитесь на канал — пришлём напоминание за 30 минут.
              </div>
            </div>
          </div>
          <div v-else-if="video.status !== 'ready'" class="player processing-card">
            <div class="col" style="align-items: center; text-align: center; padding: 24px">
              <div v-if="video.status === 'failed'" class="col" style="align-items:center"><Icon name="alertCircle" :size="40" /><h3 style="color:#fff">Не удалось обработать видео</h3><p class="small" style="opacity:.8">{{ video.processingError }}</p></div>
              <template v-else><div class="spin big"></div><h3 style="color:#fff; margin-top: 12px">Видео обрабатывается</h3><p class="small" style="opacity:.8">{{ video.processingProgress || 0 }}% — {{ ({ probe: 'анализ', hls: 'кодирование', thumbnails: 'миниатюры', storyboard: 'раскадровка', mp4: 'подготовка mp4' })[video.processingStage] || 'в очереди' }}</p></template>
            </div>
          </div>
          <VideoPlayer v-else ref="player" :key="video.id" :src="video.hlsUrl" :mp4="video.mp4Url" :audio="video.audioUrl" :audio-only="audioOnly" :poster="video.thumbnailUrl" :subtitles="video.subtitles" :chapters="video.chapters" :storyboard="video.storyboardUrl" :duration="video.duration" :start-at="startAt" :autoplay="true" :title="video.title" :has-next="!!nextVideo" :theater="theater"
            :heatmap="heatmap" :reaction-marks="reactions.marks" :reaction-kinds="reactions.canReact ? reactions.kinds : []" :audio-tracks="audioTracks"
            :intro-end="auth.config?.skipIntro === false ? 0 : (video.introEnd || 0)" :outro-start="auth.config?.skipIntro === false ? 0 : (video.outroStart || 0)"
            :logo="auth.config?.playerLogo ? (auth.config?.logoUrl || $url('/icons/icon-192.png')) : ''"
            :qoe-video-id="video.id" qoe-source="watch" :qoe="auth.config?.qoeEnabled !== false"
            @progress="onProgress" @ended="onEnded" @play="ended = false" @next="goNext" @theater="toggleTheater" @mini="openMini" @help="helpOpen = true" @react="onReact">
            <template #overlay>
              <ViewerWatermark v-if="watermarkText" :text="watermarkText" />
              <CardsOverlay v-if="(video.cards?.length || video.endScreen) && !endScreen && !attention.visible && !clipDone" :video="video" :player="player" :ended="ended" />
              <QuizOverlay v-if="video.hasQuiz && auth.isActive" ref="quizRef" :video="video" :player="player" :ended="ended" @passed="onQuizPassed" @state="quizState = $event" />
              <ScenarioOverlay v-if="video.hasScenario && auth.isActive && auth.config?.scenarioEnabled !== false" ref="scenarioRef" :video="video" :player="player" :active="!attention.visible" />
              <div v-if="attention.visible" class="end-screen" @click.stop>
                <div class="es-card" style="text-align:center"><Icon name="accountClock" :size="40" /><div class="es-title mt-8">Вы ещё смотрите?</div><div class="small" style="opacity:.8">Обязательный просмотр с контролем присутствия — подтвердите, чтобы продолжить</div><div class="row mt-16" style="justify-content:center"><button class="btn primary" @click="confirmAttention"><Icon name="play" :size="18" /> Да, продолжить</button></div></div>
              </div>
              <div v-else-if="clipDone" class="end-screen" @click.stop>
                <div class="es-card" style="text-align:center"><div class="es-title">Фрагмент закончился</div><div class="small" style="opacity:.8">{{ fmtDuration(Number(route.query.t) || 0) }} – {{ fmtDuration(clipEnd) }}</div><div class="row mt-16" style="justify-content:center"><button class="btn primary sm" @click="replayClip"><Icon name="replay" :size="18" /> Ещё раз</button><button class="btn sm" style="border-color:#fff;color:#fff" @click="continueFull"><Icon name="play" :size="18" /> Смотреть видео целиком</button></div></div>
              </div>
              <div v-else-if="endScreen && nextVideo" class="end-screen" @click.stop>
                <div class="es-card">
                  <div class="tiny" style="opacity:.8; text-transform: uppercase; letter-spacing: .08em">{{ ui.autoplay ? `Следующее видео через ${countdown} с` : 'Следующее видео' }}</div>
                  <div class="row gap-16 mt-8" style="align-items: flex-start">
                    <img v-if="nextVideo.thumbnailUrl" :src="nextVideo.thumbnailUrl" alt="" style="width: 160px; border-radius: 8px" />
                    <div class="grow"><div class="es-title clamp-2">{{ nextVideo.title }}</div><div class="small" style="opacity:.8">{{ nextVideo.owner?.displayName }}</div></div>
                  </div>
                  <div class="row mt-16"><button class="btn primary sm" @click="goNext"><Icon name="play" :size="18" /> Смотреть</button><button class="btn sm" style="border-color:#fff;color:#fff" @click="cancelAuto">Отмена</button><button class="btn sm" style="border-color:#fff;color:#fff" @click="cancelAuto(); player?.seekTo(0); player?.play()"><Icon name="replay" :size="18" /> Повторить</button></div>
                </div>
              </div>
            </template>
            <template #settings>
              <button class="pm-item" @click="ui.setAutoplay(!ui.autoplay)"><Icon name="motion" :size="18" /><span>Автовоспроизведение</span><b>{{ ui.autoplay ? 'Вкл.' : 'Выкл.' }}</b></button>
              <button v-if="video.audioUrl" class="pm-item" @click="audioOnly = !audioOnly"><Icon name="headphones" :size="18" /><span>Только звук</span><b>{{ audioOnly ? 'Вкл.' : 'Выкл.' }}</b></button>
            </template>
          </VideoPlayer>
        </div>
      </div>

      <div class="watch-body">
        <div class="watch-main">
          <h1 class="watch-title">
            <span v-if="premiereWaiting || premiereLive" class="badge brand" style="vertical-align: middle; margin-right: 8px"><Icon name="sparkles" :size="12" /> Премьера</span>{{ video.title }}
          </h1>
          <PremiereChat v-if="premiere?.chatEnabled && (premiereWaiting || premiereLive)" :video="video" :compact="premiereLive" class="mb-16" />
          <MeetingNotes v-if="auth.isActive && auth.config?.meetingNotes !== false && video.status === 'ready' && (video.hasNotes || video.viewer?.isOwner || auth.isStaff)" :video="video" :player="player" class="mb-16" />
          <ReviewPanel v-if="auth.isActive && auth.config?.reviewEnabled !== false && video.status === 'ready' && (video.viewer?.isOwner || auth.isStaff || video.reviewStatus)" :video="video" :player="player" class="mb-16" />
          <div class="watch-row">
            <div class="row gap-16 watch-owner">
              <router-link :to="`/@${video.owner.handle}`"><ChannelAvatar :user="video.owner" size="lg" /></router-link>
              <div class="grow" style="min-width:0">
                <router-link :to="`/@${video.owner.handle}`" class="owner-name ellipsis">{{ video.owner.displayName }}</router-link>
                <div class="small muted">{{ fmtSubs(video.owner.subscriberCount || 0) }}</div>
              </div>
              <SubscribeButton :handle="video.owner.handle" :subscribed="video.viewer?.subscribed" :owner-id="video.owner.id" @change="video.owner.subscriberCount = $event.subscriberCount" />
            </div>
            <div class="row gap-8 watch-actions">
              <div class="seg-pill" v-if="video.allowRatings">
                <button :class="{ on: video.viewer?.like === 1 }" @click="like(1)" title="Нравится"><Icon :name="video.viewer?.like === 1 ? 'thumbUpFill' : 'thumbUp'" :size="20" /><span>{{ fmtNumber(video.likeCount) }}</span></button>
                <i></i>
                <button :class="{ on: video.viewer?.like === -1 }" @click="like(-1)" title="Не нравится" aria-label="Не нравится"><Icon :name="video.viewer?.like === -1 ? 'thumbDownFill' : 'thumbDown'" :size="20" /></button>
              </div>
              <button class="btn soft" @click="share = true"><Icon name="share" :size="20" /> Поделиться</button>
              <button v-if="auth.isActive" class="btn soft" @click="save = true"><Icon name="playlistAdd" :size="20" /> Сохранить</button>
              <button v-if="auth.isActive && auth.config?.partyEnabled !== false && video.status === 'ready' && !premiereWaiting" class="btn soft hide-mobile" :disabled="partyBusy" @click="startParty"><Icon name="accounts" :size="20" /> Смотреть вместе</button>
              <button v-if="auth.isActive" class="btn soft hide-mobile" :class="{ active: notesOpen }" @click="notesOpen = !notesOpen"><Icon :name="notesCount ? 'noteFill' : 'note'" :size="20" /> Заметки<span v-if="notesCount" class="badge brand" style="margin-left:4px">{{ notesCount }}</span></button>
              <a v-if="video.allowDownload && auth.isActive && (video.originalAvailable || video.mp4Url)" class="btn soft hide-mobile" :href="downloadUrl(video.originalAvailable ? '' : 'mp4')"><Icon name="download" :size="20" /> Скачать</a>
              <Dropdown>
                <template #trigger><button class="ibtn soft-round" aria-label="Ещё действия"><Icon name="moreH" /></button></template>
                <button v-if="auth.isActive" class="item" @click="toggleWatchLater"><Icon class="ic" name="watchLater" :size="20" /> {{ video.viewer?.inWatchLater ? 'Убрать из «Смотреть позже»' : 'Смотреть позже' }}</button>
                <button v-if="auth.isActive && auth.config?.offlineEnabled !== false && video.mp4Url && offlineSupported()" class="item" :disabled="offline.busy" @click="toggleOffline">
                  <Icon class="ic" :name="offline.saved ? 'done' : 'install'" :size="20" />
                  <template v-if="offline.busy">Скачиваю… {{ offline.progress }}%</template>
                  <template v-else-if="offline.saved">Убрать с устройства</template>
                  <template v-else>Скачать для офлайна</template>
                </button>
                <a v-if="video.allowDownload && auth.isActive && video.mp4Url" class="item" :href="downloadUrl('mp4')"><Icon class="ic" name="download" :size="20" /> Скачать MP4 (сжатое)</a>
                <a v-if="video.allowDownload && auth.isActive && video.originalAvailable" class="item" :href="downloadUrl('')"><Icon class="ic" name="download" :size="20" /> Скачать оригинал</a>
                <button v-if="video.viewer?.isOwner || auth.isStaff" class="item" @click="router.push(`/studio/videos/${video.id}`)"><Icon class="ic" name="edit" :size="20" /> Редактировать</button>
                <button v-if="video.viewer?.isOwner || auth.isStaff" class="item" @click="router.push(`/studio/videos/${video.id}/analytics`)"><Icon class="ic" name="analytics" :size="20" /> Статистика</button>
                <button v-if="auth.isActive" class="item show-mobile-only" @click="notesOpen = !notesOpen"><Icon class="ic" name="note" :size="20" /> {{ notesOpen ? 'Скрыть заметки' : 'Мои заметки' }}</button>
                <button v-if="video.hasQuiz && auth.isActive && quizState?.quiz" class="item" @click="quizRef?.start()"><Icon class="ic" name="quiz" :size="20" /> {{ quizState.passed ? 'Пройти тест ещё раз' : 'Пройти тест' }}</button>
                <button v-if="auth.canAssign && video.status === 'ready'" class="item" @click="assign = true"><Icon class="ic" name="assignment" :size="20" /> Назначить к просмотру</button>
                <button v-if="auth.isActive && !video.viewer?.isOwner" class="item" @click="report = true"><Icon class="ic" name="flag" :size="20" /> Пожаловаться</button>
                <button v-if="auth.isActive" class="item" @click="queueThis"><Icon class="ic" name="queue" :size="20" /> Добавить в очередь</button>
                <div class="sep"></div>
                <button v-if="transcriptTracks.length" class="item" @click="toggleTranscript"><Icon class="ic" name="transcript" :size="20" /> {{ transcript.open ? 'Скрыть транскрипт' : 'Показать транскрипт' }}</button>
                <button v-if="video.hasScreenText" class="item" @click="screenOpen = !screenOpen"><Icon class="ic" name="ocr" :size="20" /> {{ screenOpen ? 'Скрыть текст на экране' : 'Текст на экране' }}</button>
                <button v-if="video.hasChatReplay" class="item" @click="chatReplayOpen = !chatReplayOpen"><Icon class="ic" name="chatReplay" :size="20" /> {{ chatReplayOpen ? 'Скрыть чат эфира' : 'Чат эфира' }}</button>
                <button v-if="video.audioUrl" class="item" @click="audioOnly = !audioOnly"><Icon class="ic" name="headphones" :size="20" /> Только звук: {{ audioOnly ? 'вкл.' : 'выкл.' }}</button>
                <button class="item" @click="helpOpen = true"><Icon class="ic" name="keyboard" :size="20" /> Горячие клавиши</button>
                <button class="item" @click="ui.setAutoplay(!ui.autoplay)"><Icon class="ic" name="motion" :size="20" /> Автовоспроизведение: {{ ui.autoplay ? 'вкл.' : 'выкл.' }}</button>
              </Dropdown>
            </div>
          </div>

          <div v-if="video.viewer?.guestLink" class="alert info"><Icon name="linkLock" :size="20" /><span>Вы смотрите это видео по гостевой ссылке. <router-link :to="{ name: 'login', query: { next: route.fullPath } }">Войти как сотрудник</router-link></span></div>
          <div v-if="assignment" class="alert asg-banner" :class="assignment.completed ? 'success' : assignment.overdue ? 'danger' : 'info'">
            <Icon :name="assignment.completed ? 'done' : 'assignment'" :size="20" />
            <span class="grow"><b>{{ assignment.completed ? 'Обязательный просмотр выполнен' : 'Назначено к обязательному просмотру' }}</b> — {{ assignment.title }}, {{ dueText(assignment) }}. <span v-if="!assignment.completed">Засчитывается при просмотре {{ assignment.requiredPercent }}%{{ assignment.requireQuiz ? ' и сдаче теста' : '' }}.</span><span v-if="assignment.note"> {{ assignment.note }}</span></span>
            <span v-if="!assignment.completed" class="nowrap small">{{ assignment.percent }}%</span>
            <router-link v-if="assignment.certificateId" :to="`/cert/${assignment.certificateId}`" class="btn sm nowrap"><Icon name="verified" :size="16" /> Сертификат</router-link>
            <button v-else-if="!assignment.completed && assignment.dueAt" class="btn ghost sm nowrap" title="Добавить срок в календарь" @click="assignmentIcs" aria-label="Добавить срок в календарь"><Icon name="calendar" :size="16" /></button>
            <router-link to="/assignments" class="btn ghost sm nowrap">Все назначения</router-link>
          </div>
          <div v-if="video.moderationStatus !== 'approved' || video.isBlocked" class="alert" :class="video.isBlocked || video.moderationStatus === 'rejected' ? 'danger' : 'warning'">
            <Icon name="alertCircle" :size="20" />
            <span v-if="video.isBlocked">Видео заблокировано администратором{{ video.blockReason ? ': ' + video.blockReason : '' }}. Его видите только вы и модераторы.</span>
            <span v-else-if="video.moderationStatus === 'pending'">Видео ожидает проверки модератором и пока видно только вам.</span>
            <span v-else>Видео отклонено модератором{{ video.moderationNote ? ': ' + video.moderationNote : '' }}.</span>
          </div>
          <div v-if="video.scheduledAt && new Date(video.scheduledAt) > new Date()" class="alert info"><Icon name="calendar" :size="20" /> Публикация запланирована на {{ new Date(video.scheduledAt).toLocaleString('ru-RU') }}. До этого видео видите только вы.</div>

          <div class="desc panel soft" :class="{ expanded: descExpanded }" @click="!descExpanded && (descExpanded = true)">
            <div class="row wrap gap-8 desc-meta">
              <b>{{ fmtViews(video.viewCount) }}</b>
              <b>{{ fmtDate(video.publishedAt || video.createdAt) }}</b>
              <span class="badge" :title="VISIBILITY[video.visibility]?.hint"><Icon :name="VISIBILITY[video.visibility]?.icon" :size="12" /> {{ VISIBILITY[video.visibility]?.label }}</span>
              <router-link v-if="video.categoryName" :to="`/category/${video.categorySlug}`" class="badge brand" @click.stop>{{ video.categoryName }}</router-link>
              <router-link v-for="t in video.tags" :key="t" :to="`/tag/${encodeURIComponent(t)}`" class="tag-link" @click.stop>#{{ t }}</router-link>
            </div>
            <RichText v-if="video.description" class="desc-text" :text="video.description" @click.stop="descExpanded = true" />
            <p v-else class="muted small">Описание отсутствует.</p>
            <div v-if="video.chapters?.length && descExpanded" class="chapters">
              <div class="label mb-8">Главы</div>
              <button v-for="ch in video.chapters" :key="ch.start" class="chapter-row" @click.stop="player?.seekTo(ch.start); player?.play()"><span class="ts">{{ fmtDuration(ch.start) }}</span><span>{{ ch.title }}</span></button>
            </div>
            <div v-if="descExpanded" class="desc-extra small muted">
              <span v-if="video.width">{{ video.width }}×{{ video.height }}</span><span v-if="video.fps"> • {{ Math.round(video.fps) }} к/с</span><span v-if="video.renditions?.length"> • качество: {{ video.renditions.map((r) => r.label).join(', ') }}</span>
              <span v-if="video.subtitles?.length"> • субтитры: {{ video.subtitles.map((s) => s.label).join(', ') }}</span>
            </div>
            <button v-if="transcriptTracks.length" class="btn sm soft mt-8" @click.stop="toggleTranscript"><Icon name="transcript" :size="18" /> {{ transcript.open ? 'Скрыть транскрипт' : 'Показать транскрипт' }}</button>
            <button v-if="video.hasScreenText" class="btn sm soft mt-8" @click.stop="screenOpen = !screenOpen"><Icon name="ocr" :size="18" /> {{ screenOpen ? 'Скрыть текст на экране' : 'Текст на экране' }}</button>
            <button v-if="video.hasChatReplay" class="btn sm soft mt-8" @click.stop="chatReplayOpen = !chatReplayOpen"><Icon name="chatReplay" :size="18" /> {{ chatReplayOpen ? 'Скрыть чат эфира' : 'Чат эфира' }}</button>
            <router-link v-if="video.clipOf" class="btn sm soft mt-8" :to="`/watch/${video.clipOf}${video.clipRange ? '?t=' + Math.floor(video.clipRange.start) : ''}`" @click.stop><Icon name="scissors" :size="18" /> Это фрагмент — открыть исходное видео</router-link>
            <button class="btn ghost sm desc-toggle" @click.stop="descExpanded = !descExpanded">{{ descExpanded ? 'Свернуть' : 'Ещё' }}</button>
          </div>

          <div v-if="clips.length" class="panel soft att-panel">
            <div class="row mb-8" style="align-items:center"><h4 class="grow"><Icon name="scissors" :size="18" style="vertical-align:-3px" /> Клипы из этого видео</h4></div>
            <div class="clips-row"><VideoCard v-for="c in clips" :key="c.id" :video="c" layout="grid" /></div>
          </div>
          <div v-if="attachments.length" class="panel soft att-panel">
            <div class="label mb-8"><Icon name="inventory" :size="16" style="vertical-align:-3px" /> Материалы к видео</div>
            <div class="att-list">
              <a v-for="a in attachments" :key="a.id" :href="a.url" class="att" :title="a.filename"><span class="att-ic"><Icon :name="ATT_ICONS[a.ext] || 'doc'" :size="22" /><b>{{ a.ext.toUpperCase() }}</b></span><span class="grow" style="min-width:0"><span class="ellipsis" style="display:block; font-weight:500">{{ a.title }}</span><span class="tiny muted">{{ (a.size / 1048576).toFixed(a.size > 1048576 ? 1 : 2) }} МБ<span v-if="a.downloads"> · скачиваний: {{ a.downloads }}</span></span></span><Icon name="download" :size="18" class="muted" /></a>
            </div>
          </div>
          <CommentList :video="video" :highlight="String(route.query.comment || '')" />
        </div>

        <aside class="watch-side">
          <div v-if="playlist" class="pl-panel">
            <div class="pl-head">
              <div class="grow" style="min-width:0"><router-link :to="`/playlist/${playlist.playlist.id}`" class="pl-title ellipsis">{{ playlist.playlist.kind === 'watch_later' ? 'Смотреть позже' : playlist.playlist.title }}</router-link><div class="tiny" style="opacity:.8">{{ playlist.playlist.owner?.displayName }} • {{ playlistIndex }} / {{ playlist.videos.length }}</div></div>
              <button class="ibtn" style="color:#fff" @click="router.replace({ query: {} }); playlist = null" title="Выйти из плейлиста" aria-label="Выйти из плейлиста"><Icon name="close" /></button>
            </div>
            <div class="pl-items">
              <router-link v-for="(v, i) in playlist.videos.filter((x) => x.shortId)" :key="v.id" :to="{ name: 'watch', params: { id: v.shortId }, query: { list: playlist.playlist.id } }" class="pl-item" :class="{ active: v.id === video.id, unavailable: v.unavailable }">
                <span class="pl-idx">{{ v.id === video.id ? '▶' : i + 1 }}</span>
                <img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" class="pl-thumb" /><span v-else class="pl-thumb"></span>
                <span class="grow" style="min-width:0"><span class="clamp-2 small" style="font-weight:500">{{ v.title }}</span><span class="tiny muted">{{ v.owner?.displayName }} • {{ fmtDuration(v.duration) }}</span></span>
              </router-link>
            </div>
          </div>
          <NotesPanel v-if="notesOpen && auth.isActive" :video="video" :player="player" @close="notesOpen = false" @seek="(t) => { player?.seekTo(t); player?.play() }" @count="notesCount = $event" />
          <ScreenTextPanel v-if="screenOpen" :video="video" :player="player" @close="screenOpen = false" />
          <ChatReplay v-if="chatReplayOpen" :video="video" :player="player" @close="chatReplayOpen = false" />
          <div v-if="ui.queue.length" class="panel soft queue-panel mb-16">
            <div class="row mb-8" style="align-items:center"><h4 class="grow"><Icon name="queueList" :size="18" style="vertical-align:-3px" /> Очередь ({{ ui.queue.length }})</h4><button class="btn ghost sm" @click="ui.queueClear()">Очистить</button></div>
            <div class="col gap-4">
              <div v-for="q in ui.queue" :key="q.id" class="row gap-8 queue-item" style="align-items:center"><img v-if="q.thumbnailUrl" :src="q.thumbnailUrl" alt="" style="width:72px;aspect-ratio:16/9;object-fit:cover;border-radius:6px;cursor:pointer" @click="playQueued(q)" /><div class="grow small clamp-2" style="cursor:pointer" @click="playQueued(q)">{{ q.title }}<div class="tiny muted">{{ q.owner?.displayName }}<span v-if="q.duration"> · {{ fmtDuration(q.duration) }}</span></div></div><button class="ibtn sm" @click="ui.queueRemove(q.id)" :aria-label="`Убрать из очереди: ${q.title}`"><Icon name="close" :size="16" /></button></div>
            </div>
            <p class="tiny muted mt-8" style="margin:8px 0 0">Следующим будет воспроизведено первое видео из очереди.</p>
          </div>
          <div v-if="transcript.open" class="transcript panel soft">
            <div class="row mb-8" style="justify-content: space-between; align-items: center">
              <span class="head" style="font-size: 18px">Транскрипт</span>
              <div class="row gap-4">
                <select v-if="transcriptTracks.length > 1" class="select sm" :value="transcript.trackId" @change="loadTranscript($event.target.value)"><option v-for="t in transcriptTracks" :key="t.id" :value="t.id">{{ t.label }}</option></select>
                <Dropdown>
                  <template #trigger><button class="ibtn" title="Скачать транскрипт" aria-label="Скачать транскрипт"><Icon name="download" :size="20" /></button></template>
                  <a class="item" :href="$url(`/api/videos/${video.shortId}/transcript?format=txt&download&track=${transcript.trackId || ''}`)"><Icon class="ic" name="doc" :size="18" /> Текст (.txt)</a>
                  <a class="item" :href="$url(`/api/videos/${video.shortId}/transcript?format=srt&track=${transcript.trackId || ''}`)"><Icon class="ic" name="subtitles" :size="18" /> Субтитры SRT</a>
                  <a class="item" :href="$url(`/api/videos/${video.shortId}/transcript?format=vtt&track=${transcript.trackId || ''}`)"><Icon class="ic" name="subtitles" :size="18" /> Субтитры VTT</a>
                </Dropdown>
                <button class="ibtn" title="Закрыть" @click="transcript.open = false" aria-label="Закрыть"><Icon name="close" /></button>
              </div>
            </div>
            <input class="input sm mb-8" v-model="transcript.query" placeholder="Поиск по тексту" />
            <div v-if="transcript.loading" class="muted small">Загрузка…</div>
            <div v-else-if="transcript.error" class="muted small">{{ transcript.error }}</div>
            <div v-else class="tr-list">
              <button v-for="c in transcriptFiltered" :key="c.i" class="tr-cue" :class="{ active: c.i === transcript.active }" @click="seekCue(c)"><span class="ts">{{ fmtDuration(c.start) }}</span><span>{{ c.text }}</span></button>
              <div v-if="!transcriptFiltered.length" class="muted small">Ничего не найдено</div>
            </div>
          </div>
          <div class="row mb-16" style="justify-content: space-between">
            <span class="small muted">Похожие видео</span>
            <label class="switch small"><span>Автовоспроизведение</span><input type="checkbox" :checked="ui.autoplay" @change="ui.setAutoplay($event.target.checked)" /><span class="track"></span></label>
          </div>
          <div class="col gap-12">
            <VideoCard v-for="v in related" :key="v.id" :video="v" layout="compact" />
          </div>
        </aside>
      </div>

      <Modal v-if="helpOpen" title="Горячие клавиши плеера" narrow @close="helpOpen = false">
        <div class="kbd-grid"><template v-for="[k, d] in SHORTCUTS" :key="k"><kbd>{{ k }}</kbd><span>{{ d }}</span></template></div>
        <p class="small muted mt-8">Работают, когда фокус на плеере или странице просмотра, в любой раскладке.</p>
      </Modal>
      <ShareDialog v-if="share" :video="video" :url="shareUrl" :current-time="player?.currentTime() || 0" :allow-embed="video.allowEmbed && ['public','unlisted'].includes(video.visibility)" :can-manage="video.viewer?.isOwner || auth.isStaff" @close="share = false" />
      <PlaylistPicker v-if="save" :video-id="video.id" @close="save = false" @changed="(p) => { if (p.kind === 'watch_later') video.viewer.inWatchLater = p.containsVideo; }" />
      <ReportDialog v-if="report" target-type="video" :target-id="video.shortId" @close="report = false" />
      <AssignDialog v-if="assign" :video-id="video.id" :title="video.title" :has-quiz="video.hasQuiz" @close="assign = false" />
    </template>
  </div>
</template>

<style>
/* Премьера: обратный отсчёт поверх затемнённой обложки (1.6) */
.premiere-card { position: relative; display: flex; align-items: center; justify-content: center; overflow: hidden; background: linear-gradient(135deg, var(--brand-700), var(--brand-400)); color: #fff; }
.premiere-card .pr-poster { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .28; filter: blur(2px); }
.premiere-card .pr-inner { position: relative; display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 24px; text-align: center; }
.premiere-card .pr-clock { font-family: var(--font-head); font-size: clamp(34px, 7vw, 64px); line-height: 1; letter-spacing: .04em; font-variant-numeric: tabular-nums; }
.premiere-card .pr-when { font-size: 15px; opacity: .92; }
@media (max-width: 767px) { .premiere-card { aspect-ratio: auto; min-height: 56.25vw; } }

.course-bar { display: flex; align-items: center; gap: 10px; padding: 10px 14px; }
.course-bar a { color: inherit; text-decoration: none; }
.course-bar a:hover b { text-decoration: underline; }
.transcript { margin-bottom: 16px; }
.clips-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.queue-item { padding: 4px; border-radius: 8px; } .queue-item:hover { background: var(--surface-2); }
.watch-main .alert { margin-bottom: 12px; }
.att-panel { padding: 14px 16px; margin-bottom: 24px; }
.att-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px; }
.att { display: flex; gap: 10px; align-items: center; padding: 8px 10px; border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--line-2); color: var(--text); font-size: 13px; }
.att:hover { border-color: var(--brand-300); }
.att-ic { display: flex; flex-direction: column; align-items: center; color: var(--brand); flex-shrink: 0; width: 40px; }
.att-ic b { font-size: 9px; letter-spacing: .04em; }
.asg-banner .grow { min-width: 0; }
.show-mobile-only { display: none; }
.btn.soft.active { background: var(--brand-100); color: var(--brand); }
@media (max-width: 767px) { .show-mobile-only { display: flex; } }
.tr-list { max-height: 420px; overflow: auto; display: flex; flex-direction: column; gap: 2px; padding-right: 4px; }
.tr-cue { display: flex; gap: 10px; align-items: flex-start; text-align: left; width: 100%; padding: 6px 8px; border-radius: 8px; background: none; border: 0; color: var(--text); font: inherit; font-size: 13px; line-height: 1.4; cursor: pointer; }
.tr-cue:hover { background: var(--surface-2); }
.tr-cue.active { background: rgba(15,67,130,.12); background: color-mix(in srgb, var(--brand) 14%, transparent); }
.tr-cue .ts { color: var(--brand); font-weight: 500; flex: 0 0 44px; font-variant-numeric: tabular-nums; }
.kbd-grid { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; align-items: center; }
.kbd-grid kbd { font: 500 12px/1.4 var(--font-mono, ui-monospace, Menlo, monospace); background: var(--surface-2, #eef2f7); border: 1px solid var(--border); border-bottom-width: 2px; border-radius: 6px; padding: 2px 8px; white-space: nowrap; }
.watch { padding: 20px var(--gutter) 64px; max-width: 1760px; margin: 0 auto; }
.watch-loading { max-width: 1200px; }
.player-wrap { margin: 0 auto; }
.player-wrap.theater { position: relative; margin: -20px calc(-1 * var(--gutter)) 16px; background: #000; }
.player-wrap.theater .player-inner { max-width: none; }
.player-wrap.theater .player { border-radius: 0; max-height: calc(100vh - var(--header-h) - 40px); aspect-ratio: auto; height: min(calc(100vh - var(--header-h) - 40px), calc(100vw * 9 / 16)); }
.watch-body { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: 24px; margin-top: 16px; }
.watch.theater .watch-body { max-width: 1760px; }
.watch:not(.theater) .player-wrap, .watch:not(.theater) .watch-body { max-width: 1760px; }
.watch:not(.theater) .player-inner { max-width: calc(100% - 424px); }
.processing-card { display: flex; align-items: center; justify-content: center; color: #fff; }
.watch-title { font-family: var(--font-body); text-transform: none; font-size: 20px; font-weight: 500; line-height: 1.3; letter-spacing: 0; margin: 0 0 12px; }
.watch-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
.watch-owner { flex: 1 1 340px; min-width: 0; }
.watch-owner .grow { min-width: 120px; }
.owner-name { display: block; color: var(--text); font-weight: 500; font-size: 16px; }
.watch-actions { flex-wrap: wrap; flex: 0 1 auto; }
.seg-pill { display: inline-flex; align-items: center; height: 40px; border-radius: var(--pill); background: var(--bg-3); overflow: hidden; }
.seg-pill button { display: inline-flex; align-items: center; gap: 8px; height: 100%; padding: 0 16px; font-weight: 500; font-size: 14px; }
.seg-pill button:hover { background: var(--line); }
:root[data-theme='dark'] .seg-pill button:hover { background: var(--surface-2); }
.seg-pill button.on { color: var(--brand); }
.seg-pill i { width: 1px; height: 24px; background: var(--line-strong); }
.soft-round { background: var(--bg-3); }
.desc { cursor: pointer; padding: 14px 16px; margin-bottom: 28px; }
.desc.expanded { cursor: default; }
.desc-meta { font-size: 14px; margin-bottom: 8px; }
.desc-text { font-size: 14px; max-height: 4.6em; overflow: hidden; }
.desc.expanded .desc-text { max-height: none; }
.tag-link { color: var(--brand); font-size: 14px; }
.chapters { margin-top: 14px; }
.chapter-row { display: flex; gap: 12px; width: 100%; padding: 6px 8px; border-radius: var(--radius-sm); text-align: left; font-size: 14px; }
.chapter-row:hover { background: var(--brand-100); }
.chapter-row .ts { color: var(--brand); font-weight: 500; font-variant-numeric: tabular-nums; min-width: 52px; }
.desc-extra { margin-top: 12px; }
.desc-toggle { margin-top: 6px; text-transform: none; letter-spacing: 0; font-weight: 500; }
.watch-side { min-width: 0; }
.pl-panel { border: 1px solid var(--line-2); border-radius: var(--radius); overflow: hidden; margin-bottom: 20px; }
.pl-head { display: flex; align-items: center; gap: 8px; padding: 12px 14px; background: var(--brand-500); color: #fff; }
.pl-title { color: #fff; font-weight: 500; display: block; }
.pl-items { max-height: 420px; overflow-y: auto; }
.pl-item { display: flex; gap: 8px; align-items: center; padding: 8px 10px; color: var(--text); }
.pl-item:hover { background: var(--bg-2); }
.pl-item.active { background: var(--brand-100); }
.pl-item.unavailable { opacity: 0.5; }
.pl-idx { width: 20px; text-align: center; font-size: 12px; color: var(--text-3); }
.pl-thumb { width: 96px; aspect-ratio: 16/9; object-fit: cover; border-radius: 6px; background: var(--bg-3); flex-shrink: 0; }
.end-screen { position: absolute; inset: 0; background: rgba(0,0,0,0.75); display: flex; align-items: center; justify-content: center; padding: 24px; }
.es-card { max-width: 520px; width: 100%; color: #fff; }
.es-title { font-weight: 500; font-size: 18px; }
@media (max-width: 1200px) {
  .watch-body { grid-template-columns: minmax(0, 1fr); }
  .watch:not(.theater) .player-inner { max-width: none; }
}
@media (max-width: 640px) {
  .watch { padding-top: 0; }
  .player-wrap { margin: 0 calc(-1 * var(--gutter)); }
  .player-wrap .player { border-radius: 0; }
  .watch-title { font-size: 17px; margin-top: 12px; }
}
</style>
