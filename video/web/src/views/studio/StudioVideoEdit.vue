<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { get, patch, post, del, uploadFile } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import * as ws from '../../ws.js';
import VideoPlayer from '../../components/player/VideoPlayer.vue';
import LineChart from '../../components/charts/LineChart.vue';
import BarList from '../../components/charts/BarList.vue';
import RetentionChart from '../../components/charts/RetentionChart.vue';
import EmptyState from '../../components/EmptyState.vue';
import ChannelAvatar from '../../components/ChannelAvatar.vue';
import { copyWithToast } from '../../utils/clipboard.js';
import QuizEditor from '../../components/studio/QuizEditor.vue';
import ScenarioEditor from '../../components/studio/ScenarioEditor.vue';
import ShareLinks from '../../components/studio/ShareLinks.vue';
import AiAssistant from '../../components/studio/AiAssistant.vue';
import AttachmentsEditor from '../../components/studio/AttachmentsEditor.vue';
import VideoEditor from '../../components/studio/VideoEditor.vue';
import BlurEditor from '../../components/studio/BlurEditor.vue';
import TextEditor from '../../components/studio/TextEditor.vue';
import TimelineEditor from '../../components/studio/TimelineEditor.vue';
import CardsEditor from '../../components/studio/CardsEditor.vue';
import AssignDialog from '../../components/AssignDialog.vue';
import { ChunkedUpload } from '../../utils/upload.js';
import { fmtDuration, fmtNumber, fmtBytes, fmtDurationLong, fmtDateTime, fmtDateShort, toLocalInput, VISIBILITY, STATUS, parseTime } from '../../utils/format.js';
import { url as withBase, absUrl } from '../../base.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const video = ref(null);
const error = ref(null);
const categories = ref([]);
const form = ref({});
const saving = ref(false);
const dirty = ref(false);
const player = ref(null);
const tab = computed(() => route.params.tab || 'details');
// Новая премьера: чат по умолчанию — как настроил администратор (у уже назначенной премьеры не трогаем)
watch(() => form.value.premiere, (on, was) => {
  if (on && was === false && video.value && !video.value.premiere) form.value.premiereChat = auth.config?.premiereChatDefault !== false;
});
const TABS = [['details', 'Сведения', 'edit'], ['thumbnail', 'Миниатюра', 'image'], ['chapters', 'Главы', 'listStatus'], ['subtitles', 'Субтитры', 'subtitles'], ['editor', 'Редактор', 'scissors'], ['montage', 'Монтаж', 'movieEdit'], ['blur', 'Размытие', 'eyeOff'], ['text', 'Монтаж по тексту', 'transcript'], ['cards', 'Подсказки', 'cards'], ['attachments', 'Материалы', 'inventory'], ['quiz', 'Тест', 'quizOutline'], ['scenario', 'Тренажёр', 'dots'], ['access', 'Доступ', 'lock'], ['analytics', 'Аналитика', 'analytics']];
const SOURCES = { home: 'Главная', search: 'Поиск', subscriptions: 'Подписки', channel: 'Канал', playlist: 'Плейлист', related: 'Похожие', direct: 'Прямая ссылка', embed: 'Встраивание', notification: 'Уведомление', trending: 'Тренды', library: 'Библиотека', external: 'Внешние сайты', share: 'Поделиться' };
const DEVICES = { desktop: 'Компьютер', mobile: 'Телефон', tablet: 'Планшет', tv: 'ТВ' };
let off = [];
// Пока форма заполняется данными с сервера, отметку «есть изменения» не ставим
let formFilling = false;

const freshOverdue = computed(() => video.value?.freshUntil && new Date(video.value.freshUntil) < new Date() && !video.value.archivedAt);
async function unarchive() {
  try { await post(`/api/admin/lifecycle/videos/${video.value.id}/archive`, { archived: false }); ui.toast('Видео возвращено из архива', { type: 'success' }); await load(); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function archiveVideo() {
  if (!(await ui.ask({ title: 'Убрать видео в архив?', message: 'Видео исчезнет из каталога, поиска и подборок, но останется у вас в студии и вернётся одной кнопкой.', okLabel: 'В архив' }))) return;
  try { await post(`/api/admin/lifecycle/videos/${video.value.id}/archive`, { reason: 'Убрано автором' }); ui.toast('Видео в архиве', { type: 'success' }); await load(); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function confirmFresh(months) {
  try { const r = await post(`/api/admin/lifecycle/videos/${video.value.id}/freshness`, { months }); ui.toast(`Актуально до ${r.freshUntil}`, { type: 'success' }); await load(); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function load() {
  try {
    video.value = (await get(`/api/videos/${route.params.id}`)).video;
    formFilling = true;
    const v = video.value;
    form.value = { title: v.title, description: v.description, categoryId: v.categoryId || '', tags: (v.tags || []).join(', '), visibility: v.visibility, commentsMode: v.commentsMode, allowDownload: v.allowDownload, allowEmbed: v.allowEmbed, allowRatings: v.allowRatings, scheduledAt: toLocalInput(v.scheduledAt), language: v.language || 'ru', viewerWatermark: !!v.viewerWatermark, expiresAt: toLocalInput(v.expiresAt), introEnd: v.introEnd ?? null, outroStart: v.outroStart ?? null, premiere: !!v.premiere, premiereChat: v.premiereChat !== false, freshUntil: v.freshUntil ? String(v.freshUntil).slice(0, 10) : '' };
    chapters.value = (v.chapters || []).map((c) => ({ ...c }));
    accessUsers.value = v.accessUsers || [];
    accessGroups.value = v.accessGroups || [];
    await nextTick();
    dirty.value = false;
  } catch (e) { error.value = e; } finally { formFilling = false; }
}
onMounted(async () => {
  categories.value = (await get('/api/feed/categories')).categories;
  await load();
  off.push(
    ws.on('video.progress', (m) => { if (video.value && m.videoId === video.value.id) { video.value.status = 'processing'; video.value.processingProgress = m.progress; video.value.processingStage = m.stage; } }),
    ws.on('video.ready', (m) => { if (video.value && m.videoId === video.value.id) load(); }),
    ws.on('video.failed', (m) => { if (video.value && m.videoId === video.value.id) load(); }),
    ws.on('video.subtitles', (m) => { if (video.value && m.videoId === video.value.id) loadSubs(); }),
  );
});
onBeforeUnmount(() => { off.forEach((f) => f()); clearInterval(slidesTimer); clearTimeout(ut); });
watch(() => route.params.id, load);
watch(form, () => { if (!formFilling) dirty.value = true; }, { deep: true });

// Не теряем несохранённые сведения при уходе со страницы (переключение вкладок того же видео — не уход)
function warnUnsaved(e) { if (dirty.value) { e.preventDefault(); e.returnValue = ''; } }
window.addEventListener('beforeunload', warnUnsaved);
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved));
async function confirmLeave(to, from) {
  // Смена вкладки того же видео — не уход со страницы
  if (!dirty.value || to.params.id === from.params.id) return true;
  return ui.ask({ title: 'Уйти без сохранения?', message: 'Изменения в сведениях о видео не сохранены.', okLabel: 'Уйти', danger: true });
}
onBeforeRouteLeave(confirmLeave);
// Переход на другое видео остаётся в той же записи маршрута (/studio/videos/:id/:tab?),
// поэтому onBeforeRouteLeave там не срабатывает — нужен именно onBeforeRouteUpdate
onBeforeRouteUpdate(confirmLeave);

async function save(extra = {}) {
  saving.value = true;
  try {
    const body = { ...form.value, categoryId: form.value.categoryId || null, scheduledAt: form.value.scheduledAt ? new Date(form.value.scheduledAt).toISOString() : null, expiresAt: form.value.expiresAt ? new Date(form.value.expiresAt).toISOString() : null, ...extra };
    const r = await patch(`/api/videos/${video.value.id}`, body);
    video.value = { ...video.value, ...r.video, accessUsers: video.value.accessUsers };
    dirty.value = false;
    ui.toast('Сохранено', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
async function remove() {
  if (!(await ui.ask({ title: 'Удалить видео?', message: 'Видео, статистика и комментарии будут удалены безвозвратно.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/videos/${video.value.id}`); ui.toast('Видео удалено'); router.push('/studio/videos');
}
async function pushRag() { try { await post(`/api/videos/${video.value.id}/rag`, {}); ui.toast('Документ поставлен в очередь на выгрузку в базу знаний', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function reprocess() { try { await post(`/api/videos/${video.value.id}/reprocess`, {}); ui.toast('Поставлено в очередь на обработку'); load(); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function dropOriginal() { if (await ui.ask({ title: 'Удалить оригинальный файл?', message: 'Освободит место, но повторная обработка и скачивание оригинала станут невозможны.', okLabel: 'Удалить', danger: true })) { await patch(`/api/videos/${video.value.id}`, { keepOriginal: false }); load(); } }

// Миниатюра
async function selectThumb(url) { await patch(`/api/videos/${video.value.id}`, { thumbnailUrl: url }); video.value.thumbnailUrl = url; ui.toast('Миниатюра выбрана', { type: 'success' }); }
async function uploadThumb(e) { const f = e.target.files[0]; if (!f) return; try { const r = await uploadFile(`/api/videos/${video.value.id}/thumbnail`, f); video.value.thumbnailUrl = r.thumbnailUrl; ui.toast('Миниатюра загружена', { type: 'success' }); } catch (err) { ui.toast(err.message, { type: 'error' }); } e.target.value = ''; }
async function frameThumb() { const t = player.value?.currentTime() || 0; try { const r = await post(`/api/videos/${video.value.id}/thumbnail/frame`, { time: t }); video.value.thumbnailUrl = r.thumbnailUrl; ui.toast('Кадр сохранён как миниатюра', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }

// Главы
const chapters = ref([]);
const newChapter = ref({ start: '0:00', title: '' });
function addChapter() {
  const s = parseTime(newChapter.value.start); if (s === null || !newChapter.value.title.trim()) return ui.toast('Укажите время и название', { type: 'error' });
  chapters.value.push({ start: s, title: newChapter.value.title.trim() }); chapters.value.sort((a, b) => a.start - b.start); newChapter.value = { start: fmtDuration(Math.floor(player.value?.currentTime() || 0)), title: '' };
}
function useCurrent() { newChapter.value.start = fmtDuration(Math.floor(player.value?.currentTime() || 0)); }
async function saveChapters() { await save({ chapters: chapters.value }); }
async function autoChapters() { await save({ chapters: 'auto', description: form.value.description }); chapters.value = (video.value.chapters || []).map((c) => ({ ...c })); if (!chapters.value.length) ui.toast('В описании не найдено таймкодов вида «0:00 Название» (нужно минимум два, первый — 0:00)', { type: 'info', timeout: 7000 }); }

// Субтитры
const subs = ref([]);
const subForm = ref({ language: 'ru', label: 'Русский' });
async function loadSubs() { subs.value = (await get(`/api/videos/${video.value.id}/subtitles`)).subtitles; }
watch(tab, (t) => { if (t === 'subtitles' && video.value) { loadSubs(); loadTracks(); } if (t === 'analytics' && video.value) { loadAnalytics(); loadViewers(); } if (t === 'access') loadGroups(); }, { immediate: false });
watch(video, (v, old) => { if (v && !old) { if (tab.value === 'subtitles') { loadSubs(); loadTracks(); } if (tab.value === 'analytics') { loadAnalytics(); loadViewers(); } if (tab.value === 'access') loadGroups(); } });
async function uploadSub(e) { const f = e.target.files[0]; if (!f) return; try { await uploadFile(`/api/videos/${video.value.id}/subtitles`, f, { fields: { language: subForm.value.language, label: subForm.value.label } }); await loadSubs(); ui.toast('Субтитры добавлены', { type: 'success' }); } catch (err) { ui.toast(err.message, { type: 'error' }); } e.target.value = ''; }
async function autoSub() { try { await post(`/api/videos/${video.value.id}/subtitles/auto`, { language: subForm.value.language }); await loadSubs(); ui.toast('Распознавание речи запущено — это может занять несколько минут'); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function removeSub(s) { await del(`/api/videos/${video.value.id}/subtitles/${s.id}`); loadSubs(); }
async function defaultSub(s) { await patch(`/api/videos/${video.value.id}/subtitles/${s.id}`, { isDefault: true }); loadSubs(); }
const translateLang = ref('en');
const LANG_NAMES = { ru: 'Русский', en: 'English', de: 'Deutsch', fr: 'Français', es: 'Español', it: 'Italiano', pt: 'Português', pl: 'Polski', tr: 'Türkçe', ar: 'العربية', zh: '中文', ja: '日本語', ko: '한국어', kk: 'Қазақ', uz: 'Oʻzbek', ky: 'Кыргызча', tg: 'Тоҷикӣ', hy: 'Հայերեն', az: 'Azərbaycan', ka: 'ქართული', be: 'Беларуская', uk: 'Українська', vi: 'Tiếng Việt', hi: 'हिन्दी' };
const translateLanguages = computed(() => (auth.config?.translateLanguages || ['en']).map((c) => [c, LANG_NAMES[c] || c]));
async function translateSub(s) {
  try { await post(`/api/videos/${video.value.id}/subtitles/${s.id}/translate`, { language: translateLang.value }); await loadSubs(); ui.toast(`Перевод на «${LANG_NAMES[translateLang.value] || translateLang.value}» запущен`); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
// SCORM
const scorm = ref({ version: '1.2', percent: auth.config?.assignmentsDefaultPercent || 90, share: true });
const scormUrl = computed(() => withBase(`/api/videos/${video.value?.id}/scorm.zip?version=${scorm.value.version}&percent=${scorm.value.percent}${scorm.value.share ? '&share=1' : ''}`));

// Доступ
const accessUsers = ref([]);
const userQuery = ref(''); const userResults = ref([]);
let ut = null;
watch(userQuery, (q) => { clearTimeout(ut); if (q.trim().length < 2) { userResults.value = []; return; } ut = setTimeout(async () => { try { userResults.value = (await get(`/api/users/lookup?q=${encodeURIComponent(q.trim())}`)).users.filter((u) => !accessUsers.value.some((a) => a.id === u.id)); } catch { userResults.value = []; } }, 250); });
function addAccess(u) { accessUsers.value.push(u); userQuery.value = ''; userResults.value = []; }
const accessGroups = ref([]);
const allGroups = ref([]);
async function loadGroups() { if (!allGroups.value.length) { try { allGroups.value = (await get('/api/groups')).groups; } catch { allGroups.value = []; } } }
function toggleGroup(g) { if (accessGroups.value.some((x) => x.id === g.id)) accessGroups.value = accessGroups.value.filter((x) => x.id !== g.id); else accessGroups.value.push(g); }
async function saveAccess() { await save({ accessUsers: accessUsers.value.map((u) => u.id), accessGroups: accessGroups.value.map((g) => g.id) }); }

// Назначение к просмотру, замена файла, ИИ, зрители
const assignOpen = ref(false);
const replacing = ref(null); // { progress, status, error }
async function replaceFile(e) {
  const f = e.target.files[0]; e.target.value = ''; if (!f) return;
  if (!(await ui.ask({ title: 'Заменить видеофайл?', message: `Файл «${f.name}» заменит текущий. Ссылка, статистика, комментарии и настройки сохранятся; видео будет обработано заново (версия ${(video.value.version || 1) + 1}).`, okLabel: 'Заменить' }))) return;
  const up = new ChunkedUpload(f, { replaceVideoId: video.value.id });
  replacing.value = { progress: 0, status: 'uploading', error: null };
  up.onChange = (u) => { replacing.value = { progress: u.progress, status: u.status, error: u.error }; if (u.status === 'done') { ui.toast('Файл загружен — идёт обработка', { type: 'success' }); setTimeout(() => { replacing.value = null; load(); }, 800); } };
  up.start();
}
function applyAi({ kind, value }) {
  if (kind === 'title') form.value.title = value;
  else if (kind === 'summary') form.value.description = form.value.description?.trim() ? `${value}\n\n${form.value.description}` : value;
  else if (kind === 'tags') { const cur = form.value.tags.split(',').map((t) => t.trim()).filter(Boolean); form.value.tags = [...new Set([...cur, ...value])].join(', '); }
  else if (kind === 'chapters') { const lines = value.map((c) => `${fmtDuration(c.start)} ${c.title}`).join('\n'); form.value.description = `${form.value.description?.trim() || ''}\n\n${lines}`.trim(); chapters.value = value.map((c) => ({ ...c })); }
  dirty.value = true;
}
const viewers = ref(null);
async function loadViewers() { try { viewers.value = (await get(`/api/videos/${video.value.id}/viewers`)).viewers; } catch (e) { viewers.value = []; if (e.status !== 403) ui.toast(e.message, { type: 'error' }); } }

// Аналитика
const analytics = ref(null); const range = ref('28d');
async function loadAnalytics() { analytics.value = (await get(`/api/videos/${video.value.id}/analytics?range=${range.value}`)).analytics; }
watch(range, loadAnalytics);
// Полные адреса — с префиксом пути портала (VITE_BASE)
const shareUrl = computed(() => absUrl(`/watch/${video.value?.shortId}`));
const embedUrl = computed(() => absUrl(`/embed/${video.value?.shortId}`));
async function copy(t) { await copyWithToast(ui, t); }

// 1.4: дополнительные звуковые дорожки (дубляж, тифлокомментарий)
const audioTracks = ref([]);
const trackForm = ref({ kind: 'dub', language: 'en', label: '' });
const slidesBusy = ref(false);
let slidesTimer = null;   // опрос результата автоглав: останавливаем и при уходе со страницы
/** Автоглавы по сменам кадра: задание считает сцены, затем подтягиваем результат (1.8). */
async function chaptersBySlides() {
  slidesBusy.value = true;
  try {
    await post(`/api/videos/${route.params.id}/chapters/auto`, {});
    ui.toast('Ищу смены слайдов — главы появятся здесь');
    let tries = 0;
    clearInterval(slidesTimer);
    const timer = slidesTimer = setInterval(async () => {
      tries += 1;
      try {
        const v = (await get(`/api/videos/${route.params.id}`)).video;
        if ((v.chapters || []).length) {
          chapters.value = v.chapters.map((c) => ({ ...c }));
          video.value = v;
          clearInterval(timer); slidesBusy.value = false;
          ui.toast(`Расставлено глав: ${v.chapters.length}`, { type: 'success' });
        }
      } catch { /* ignore */ }
      if (tries > 40) { clearInterval(timer); slidesBusy.value = false; }
    }, 3000);
  } catch (e) { ui.toast(e.message, { type: 'error' }); slidesBusy.value = false; }
}

async function loadTracks() {
  try { audioTracks.value = (await get(`/api/videos/${route.params.id}/audio-tracks`)).tracks; } catch { audioTracks.value = []; }
}
async function uploadTrack(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  try {
    await uploadFile(`/api/videos/${route.params.id}/audio-tracks`, file, {
      fields: { kind: trackForm.value.kind, language: trackForm.value.language || 'ru', ...(trackForm.value.label ? { label: trackForm.value.label } : {}) },
    });
    await loadTracks();
    ui.toast('Дорожка загружена', { type: 'success' });
  } catch (err) { ui.toast(err.message, { type: 'error' }); }
}
async function removeTrack(t) {
  if (!(await ui.ask({ title: `Удалить дорожку «${t.label}»?`, okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/videos/${route.params.id}/audio-tracks/${t.id}`); await loadTracks(); } catch (err) { ui.toast(err.message, { type: 'error' }); }
}
</script>

<template>
  <div v-if="error"><EmptyState icon="alertCircle" title="Видео не найдено" :text="error.message" /></div>
  <div v-else-if="video">
    <div class="row mb-16"><router-link to="/studio/videos" class="btn ghost sm"><Icon name="back" :size="18" /> Мои видео</router-link></div>
    <div class="edit-head">
      <div class="edit-preview">
        <VideoPlayer v-if="video.status === 'ready'" ref="player" :src="video.hlsUrl" :mp4="video.mp4Url" :poster="video.thumbnailUrl" :subtitles="video.subtitles" :chapters="video.chapters" :storyboard="video.storyboardUrl" :duration="video.duration" compact :allow-theater="false" :allow-mini="false" />
        <div v-else class="player processing-card"><div class="col" style="align-items:center; text-align:center; padding:16px"><template v-if="video.status === 'failed'"><Icon name="alertCircle" :size="36" /><div>Ошибка обработки</div><div class="small" style="opacity:.8">{{ video.processingError }}</div></template><template v-else><div class="spin big"></div><div class="mt-8">{{ STATUS[video.status] }} {{ video.processingProgress ? video.processingProgress + '%' : '' }}</div></template></div></div>
        <div class="code-box mt-8"><span>{{ shareUrl }}</span><button class="ibtn sm" @click="copy(shareUrl)" aria-label="Скопировать ссылку на видео"><Icon name="copy" :size="16" /></button></div>
        <div class="small muted mt-8">{{ video.originalFilename }} • {{ fmtBytes(video.originalSize) }} • {{ video.width }}×{{ video.height }} • {{ fmtDuration(video.duration) }}<br>Хранилище: {{ fmtBytes(video.storageBytes) }}<span v-if="video.renditions?.length"> • {{ video.renditions.map((r) => r.label).join(', ') }}</span></div>
        <div v-if="replacing" class="mt-8"><div class="up-bar"><i :style="{ width: (replacing.progress * 100) + '%' }" :class="{ err: replacing.status === 'error' }"></i></div><div class="tiny muted mt-4">{{ replacing.status === 'error' ? 'Ошибка: ' + replacing.error : replacing.status === 'done' ? 'Загружено' : `Загрузка нового файла ${Math.round(replacing.progress * 100)}%` }}</div></div>
        <div class="row wrap mt-8"><router-link :to="`/watch/${video.shortId}`" class="btn sm"><Icon name="play" :size="16" /> Открыть</router-link><button v-if="auth.canAssign && video.status === 'ready'" class="btn sm" @click="assignOpen = true"><Icon name="assignment" :size="16" /> Назначить к просмотру</button><label v-if="['ready', 'failed'].includes(video.status) && !replacing" class="btn ghost sm" title="Загрузить новый файл вместо текущего"><Icon name="swap" :size="16" /> Заменить файл<input type="file" class="hidden" :accept="(auth.config?.uploadAllowedExtensions || []).map((e) => '.' + e).join(',')" @change="replaceFile" /></label><button v-if="video.originalKept" class="btn ghost sm" @click="reprocess"><Icon name="refresh" :size="16" /> Обработать заново</button><button v-if="auth.config?.ragEnabled && video.status === 'ready'" class="btn ghost sm" title="Отправить документ видео в базу знаний (RAG)" @click="pushRag"><Icon name="database" :size="16" /> В базу знаний</button><button v-if="video.originalKept && video.status === 'ready'" class="btn ghost sm" @click="dropOriginal">Удалить оригинал</button><button v-if="!video.archivedAt && auth.config?.lifecycleEnabled !== false" class="btn ghost sm" title="Убрать из каталога, сохранив видео" @click="archiveVideo"><Icon name="database" :size="16" /> В архив</button><button class="btn ghost sm danger" @click="remove"><Icon name="delete" :size="16" /> Удалить</button></div>
      </div>
      <div class="grow" style="min-width: 0">
        <h1 style="font-family: var(--font-body); text-transform: none; font-size: 24px; letter-spacing: 0" class="clamp-2">{{ video.title }}</h1>
        <div class="row wrap gap-8 mt-8">
          <span class="badge" :class="video.status === 'ready' ? 'success' : video.status === 'failed' ? 'danger' : 'warning'">{{ STATUS[video.status] }}</span>
          <span class="badge brand"><Icon :name="VISIBILITY[video.visibility]?.icon" :size="12" /> {{ VISIBILITY[video.visibility]?.label }}</span>
          <span v-if="video.moderationStatus === 'pending'" class="badge warning">На проверке модератором</span>
          <span v-if="video.moderationStatus === 'rejected'" class="badge danger">Отклонено: {{ video.moderationNote }}</span>
          <span v-if="video.isBlocked" class="badge danger">Заблокировано: {{ video.blockReason }}</span>
          <span v-if="video.version > 1" class="badge" :title="'Файл заменён ' + fmtDateTime(video.replacedAt)">Версия {{ video.version }}</span>
          <router-link v-if="video.clipOf" class="badge" :to="`/studio/videos/${video.clipOf}`" title="Клип из другого видео"><Icon name="scissors" :size="12" /> Клип{{ video.clipRange?.vertical ? ' 9:16' : '' }}</router-link>
          <span v-if="video.hasScreenText" class="badge" title="Текст на экране распознан"><Icon name="ocr" :size="12" /> OCR</span>
          <span v-if="video.hasQuiz" class="badge"><Icon name="quiz" :size="12" /> С тестом</span>
          <span v-if="video.viewerWatermark" class="badge"><Icon name="watermark" :size="12" /> Водяной знак</span>
          <a v-if="video.sourceUrl" class="badge" :href="video.sourceUrl" target="_blank" rel="noopener" title="Импортировано по ссылке"><Icon name="importUrl" :size="12" /> Импорт</a>
          <span v-if="video.expiresAt && !video.expiredAt" class="badge warning" :title="'Срок публикации до ' + fmtDateTime(video.expiresAt)"><Icon name="clock" :size="12" /> до {{ fmtDateShort(video.expiresAt) }}</span>
          <span v-if="video.expiredAt" class="badge danger">Срок публикации истёк</span>
          <span v-if="video.archivedAt" class="badge warning"><Icon name="database" :size="12" /> В архиве</span>
          <span v-if="video.legalHold" class="badge"><Icon name="gavel" :size="12" /> Не удалять</span>
          <span v-if="video.freshUntil" class="badge" :class="freshOverdue ? 'danger' : ''" :title="'Актуально до ' + fmtDateShort(video.freshUntil)"><Icon name="clock" :size="12" /> актуально до {{ fmtDateShort(video.freshUntil) }}</span>
          <span v-if="auth.config?.ragEnabled && video.ragSyncedAt" class="badge" :title="'Выгружено в базу знаний ' + fmtDateTime(video.ragSyncedAt)"><Icon name="database" :size="12" /> RAG</span>
        </div>
        <div class="stat-tiles mt-16">
          <div class="stat-tile"><div class="v">{{ fmtNumber(video.viewCount) }}</div><div class="l">Просмотров</div></div>
          <div class="stat-tile"><div class="v">{{ fmtNumber(video.likeCount) }}</div><div class="l">Нравится</div><div class="d">{{ video.dislikeCount }} не нравится</div></div>
          <div class="stat-tile"><div class="v">{{ fmtNumber(video.commentCount) }}</div><div class="l">Комментариев</div></div>
          <div class="stat-tile"><div class="v" style="font-size:24px">{{ fmtDurationLong(video.watchSeconds) }}</div><div class="l">Время просмотра</div></div>
        </div>
      </div>
    </div>

    <div v-if="video.archivedAt" class="alert warning mb-16"><Icon name="database" :size="20" /><span class="grow">Видео в архиве{{ video.archivedReason ? ` — ${video.archivedReason}` : '' }}: оно не показывается в каталоге и поиске, но доступно вам и администратору.</span><button class="btn sm" @click="unarchive">Вернуть из архива</button></div>
    <div v-else-if="video.legalHold" class="alert mb-16"><Icon name="gavel" :size="20" /><span class="grow">На видео стоит отметка «не удалять»: правила хранения его не тронут. Снять отметку может администратор.</span></div>
    <div v-else-if="freshOverdue" class="alert warning mb-16"><Icon name="clock" :size="20" /><span class="grow">Срок актуальности истёк {{ fmtDateShort(video.freshUntil) }} — подтвердите, что материал не устарел.</span><button class="btn sm" @click="confirmFresh(12)">Актуально ещё год</button></div>
    <div class="tabs studio-tabs mt-24 mb-24">
      <router-link v-for="[k, l, ic] in TABS" :key="k" :to="`/studio/videos/${video.id}/${k}`" class="tab" :class="{ active: tab === k }"><Icon :name="ic" :size="16" style="vertical-align:-3px; margin-right:6px" />{{ l }}</router-link>
    </div>

    <!-- Сведения -->
    <div v-if="tab === 'details'" class="panel">
      <div class="form-grid">
        <div class="field" style="grid-column: 1 / -1"><label>Название</label><input class="input" v-model="form.title" maxlength="150" /></div>
        <div class="field" style="grid-column: 1 / -1"><label>Описание</label><textarea class="textarea" v-model="form.description" rows="8" placeholder="Таймкоды вида «0:00 Введение» автоматически становятся главами. #Хештеги и ссылки кликабельны."></textarea></div>
        <div class="field"><label>Видимость</label><select class="select" v-model="form.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select><div class="hint">{{ VISIBILITY[form.visibility]?.hint }}</div></div>
        <div class="field"><label>Категория</label><select class="select" v-model="form.categoryId"><option value="">Без категории</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div class="field" style="grid-column: 1 / -1"><label>Теги (через запятую)</label><input class="input" v-model="form.tags" /></div>
        <div class="field"><label>Язык</label><select class="select" v-model="form.language"><option value="ru">Русский</option><option value="en">English</option><option value="uz">Oʻzbek</option><option value="kk">Қазақ</option><option value="de">Deutsch</option></select></div>
        <div class="field"><label>Отложенная публикация</label><input class="input" type="datetime-local" v-model="form.scheduledAt" /><div class="hint">До указанного времени видео видно только вам</div></div>
        <!-- Премьера: показ по расписанию с обратным отсчётом и чатом (1.6) -->
        <div class="field" v-if="auth.config?.premiereEnabled !== false && form.scheduledAt">
          <label class="switch"><input type="checkbox" v-model="form.premiere" /><span></span> Премьера: показать всем одновременно</label>
          <div class="hint">Анонс с обратным отсчётом появится сразу, а видео откроется в назначенное время. Подписчики получат напоминание за 30 минут.</div>
          <label v-if="form.premiere" class="switch mt-8"><input type="checkbox" v-model="form.premiereChat" /><span></span> Чат премьеры</label>
        </div>
        <div class="field"><label>Срок публикации</label><input class="input" type="datetime-local" v-model="form.expiresAt" /><div class="hint">После этой даты видео станет приватным (ссылка и статистика сохранятся)<span v-if="video.expiredAt"> · срок истёк {{ fmtDateTime(video.expiredAt) }}</span></div></div>
        <div class="field" v-if="auth.config?.lifecycleEnabled !== false"><label>Актуально до</label><input class="input" type="date" v-model="form.freshUntil" /><div class="hint">Портал напомнит вам пересмотреть материал<span v-if="video.freshConfirmedAt"> · подтверждено {{ fmtDateShort(video.freshConfirmedAt) }}</span></div></div>
        <div class="field"><label>Комментарии</label><select class="select" v-model="form.commentsMode"><option value="open">Разрешены</option><option value="held">Публиковать после проверки</option><option value="disabled">Отключены</option></select></div>
        <div class="field"><label>Конец вступления, с</label><input class="input" type="number" min="0" step="0.5" v-model.number="form.introEnd" placeholder="например 12" /></div>
        <div class="field"><label>Начало финальной заставки, с</label><input class="input" type="number" min="0" step="0.5" v-model.number="form.outroStart" placeholder="например 540" /></div>
        <div class="field" style="justify-content: flex-end"><label class="switch"><input type="checkbox" v-model="form.allowDownload" /><span class="track"></span><span>Разрешить скачивание</span></label><label class="switch"><input type="checkbox" v-model="form.allowEmbed" /><span class="track"></span><span>Разрешить встраивание</span></label><label class="switch"><input type="checkbox" v-model="form.allowRatings" /><span class="track"></span><span>Показывать оценки</span></label></div>
      </div>
      <div class="form-actions"><button class="btn primary" :disabled="saving || !dirty" @click="save()">{{ saving ? 'Сохранение…' : 'Сохранить' }}</button><span v-if="dirty" class="small muted">Есть несохранённые изменения</span></div>
      <AiAssistant v-if="auth.config?.aiEnabled" :video="video" class="mt-16" @apply="applyAi" />
    </div>

    <!-- Материалы -->
    <div v-else-if="tab === 'attachments'" class="panel">
      <AttachmentsEditor :video="video" />
    </div>

    <!-- Редактор -->
    <div v-else-if="tab === 'editor'">
      <VideoEditor :video="video" :player="player" @changed="load" />
    </div>

    <!-- Графический видеоредактор (1.10) -->
    <div v-else-if="tab === 'montage'">
      <TimelineEditor :video="video" @changed="load" />
    </div>

    <!-- Размытие лиц и областей (1.9) -->
    <div v-else-if="tab === 'blur'">
      <BlurEditor :video="video" :player="player" @changed="load" />
    </div>

    <!-- Монтаж по расшифровке (1.9) -->
    <div v-else-if="tab === 'text'">
      <TextEditor :video="video" :player="player" @changed="load" />
    </div>

    <!-- Подсказки и конечная заставка -->
    <div v-else-if="tab === 'cards'">
      <CardsEditor :video="video" :player="player" @saved="video = { ...video, ...$event }" />
    </div>

    <!-- Тест -->
    <div v-else-if="tab === 'quiz'" class="panel">
      <QuizEditor :video="video" :player="player" @changed="video.hasQuiz = $event" />
    </div>

    <!-- Тренажёр с ветвлением (1.7) -->
    <div v-else-if="tab === 'scenario'">
      <ScenarioEditor :video="video" :player="player" @changed="video.hasScenario = $event" />
    </div>

    <!-- Миниатюра -->
    <div v-else-if="tab === 'thumbnail'" class="panel">
      <h3 class="mb-8">Миниатюра</h3><p class="muted small">Выберите один из автоматических кадров, загрузите свою картинку (16:9, JPG/PNG) или используйте текущий кадр плеера.</p>
      <div class="thumb-grid">
        <button v-for="u in video.thumbnailCandidates" :key="u" class="thumb-opt" :class="{ active: video.thumbnailUrl === u }" @click="selectThumb(u)"><img :src="u" alt="" /><Icon v-if="video.thumbnailUrl === u" name="check" class="chk" /></button>
        <div v-if="video.thumbnailUrl && !video.thumbnailCandidates.includes(video.thumbnailUrl)" class="thumb-opt active"><img :src="video.thumbnailUrl" alt="" /><Icon name="check" class="chk" /><span class="badge brand tag">Своя</span></div>
      </div>
      <div class="row wrap mt-16"><label class="btn sm"><Icon name="upload" :size="16" /> Загрузить картинку<input type="file" accept="image/*" class="hidden" @change="uploadThumb" /></label><button v-if="video.status === 'ready'" class="btn sm" @click="frameThumb"><Icon name="camera" :size="16" /> Текущий кадр плеера</button></div>
    </div>

    <!-- Главы -->
    <div v-else-if="tab === 'chapters'" class="panel">
      <h3 class="mb-8">Главы</h3><p class="muted small">Главы отображаются на шкале плеера и в описании. Также их можно задать таймкодами в описании («0:00 Введение»).</p>
      <div class="row wrap mb-16"><input class="input" v-model="newChapter.start" style="width: 110px" placeholder="0:00" /><button class="btn ghost sm" @click="useCurrent" title="Взять текущее время плеера" aria-label="Взять текущее время плеера"><Icon name="timer" :size="16" /></button><input class="input grow" v-model="newChapter.title" placeholder="Название главы" maxlength="100" @keydown.enter="addChapter" /><button class="btn primary sm" @click="addChapter"><Icon name="plus" :size="16" /> Добавить</button></div>
      <div v-if="chapters.length" class="col gap-4 mb-16">
        <div v-for="(c, i) in chapters" :key="i" class="row chapter-edit"><span class="mono" style="width: 70px; color: var(--brand); cursor:pointer" @click="player?.seekTo(c.start)">{{ fmtDuration(c.start) }}</span><input class="input" v-model="c.title" /><button class="ibtn sm" @click="chapters.splice(i, 1)" aria-label="Удалить главу"><Icon name="close" :size="16" /></button></div>
      </div>
      <p v-else class="muted small">Глав пока нет.</p>
      <div class="form-actions">
        <button class="btn primary" @click="saveChapters">Сохранить главы</button>
        <button class="btn ghost" @click="autoChapters">Взять из описания</button>
        <button v-if="auth.config?.autoChapters !== false" class="btn ghost" :disabled="slidesBusy || video.status !== 'ready' || (video.duration || 0) < 60" @click="chaptersBySlides">
          <template v-if="slidesBusy"><span class="spin sm"></span> Ищу смены слайдов…</template>
          <template v-else><Icon name="image" :size="16" /> По сменам слайдов</template>
        </button>
        <button v-if="chapters.length" class="btn ghost danger" @click="chapters = []">Очистить</button>
      </div>
      <p v-if="auth.config?.autoChapters !== false" class="tiny muted mt-8">«По сменам слайдов» находит моменты, где заметно меняется картинка (новый слайд, другой экран), и подписывает главы первой фразой из расшифровки — как «умные главы» Panopto.</p>
    </div>

    <!-- Субтитры -->
    <div v-else-if="tab === 'subtitles'" class="panel">
      <h3 class="mb-8">Субтитры</h3><p class="muted small">Загрузите файл .vtt или .srt либо создайте субтитры автоматически распознаванием речи{{ auth.config?.asrEnabled ? '' : ' (функция отключена администратором)' }}. Текст субтитров участвует в поиске.</p>
      <div class="row wrap mb-16"><select class="select" v-model="subForm.language" style="width: 160px" @change="subForm.label = { ru: 'Русский', en: 'English', uz: 'Oʻzbek', kk: 'Қазақ', de: 'Deutsch' }[subForm.language]"><option value="ru">Русский</option><option value="en">English</option><option value="uz">Oʻzbek</option><option value="kk">Қазақ</option><option value="de">Deutsch</option></select><input class="input" v-model="subForm.label" style="width: 200px" placeholder="Подпись" /><label class="btn sm"><Icon name="upload" :size="16" /> Загрузить .vtt/.srt<input type="file" accept=".vtt,.srt" class="hidden" @change="uploadSub" /></label><button v-if="auth.config?.asrEnabled" class="btn primary sm" :disabled="video.status !== 'ready'" @click="autoSub"><Icon name="robot" :size="16" /> Распознать речь</button></div>
      <div v-if="auth.config?.translateEnabled" class="row wrap gap-8 mb-16" style="align-items:center"><Icon name="translate" :size="18" style="color:var(--brand)" /><span class="small">Перевод субтитров через ИИ на:</span><select class="select sm" v-model="translateLang" style="width:170px"><option v-for="[c, n] in translateLanguages" :key="c" :value="c">{{ n }}</option></select><span class="small muted">— затем нажмите «Перевести» у нужной дорожки (таймкоды сохраняются)</span></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Язык</th><th>Подпись</th><th>Тип</th><th>Статус</th><th></th></tr></thead><tbody>
        <tr v-for="s in subs" :key="s.id"><td>{{ s.language }}</td><td>{{ s.label }} <span v-if="s.isDefault" class="badge brand">по умолчанию</span></td><td>{{ s.translatedFrom ? 'Перевод (ИИ)' : s.kind === 'auto' ? 'Автоматические' : 'Загружены' }}</td><td><span class="badge" :class="s.status === 'ready' ? 'success' : s.status === 'failed' ? 'danger' : 'warning'">{{ { ready: 'Готово', processing: 'Распознавание…', failed: 'Ошибка' }[s.status] }}</span><div v-if="s.error" class="tiny danger" style="color:var(--danger)">{{ s.error }}</div></td><td class="actions"><button v-if="!s.isDefault && s.status === 'ready'" class="btn ghost sm" @click="defaultSub(s)">По умолчанию</button><button v-if="auth.config?.translateEnabled && s.status === 'ready' && s.language !== translateLang" class="btn ghost sm" :title="`Перевести на ${LANG_NAMES[translateLang] || translateLang} через ИИ`" @click="translateSub(s)"><Icon name="translate" :size="14" /> Перевести</button><a v-if="s.url" :href="s.url" class="btn ghost sm" download>VTT</a><button class="btn ghost sm danger" @click="removeSub(s)">Удалить</button></td></tr>
        <tr v-if="!subs.length"><td colspan="5" class="muted">Субтитров нет</td></tr>
      </tbody></table></div>

      <h3 class="mt-16 mb-8">Звуковые дорожки</h3>
      <p class="muted small">Дубляж на другой язык или тифлокомментарий (аудиоописание происходящего для незрячих). Зритель выбирает дорожку в меню плеера: «Настройки → Звук».</p>
      <div class="row wrap gap-8 mb-16">
        <select class="select sm" v-model="trackForm.kind" style="width:190px"><option value="dub">Дубляж</option><option value="description">Тифлокомментарий</option></select>
        <input class="input sm" v-model="trackForm.language" style="width:90px" placeholder="язык" />
        <input class="input sm" v-model="trackForm.label" style="width:220px" placeholder="Подпись (например: Английский)" />
        <label class="btn sm"><Icon name="upload" :size="16" /> Загрузить .m4a/.mp3<input type="file" accept=".m4a,.mp3,.aac,.ogg,.opus,.wav" class="hidden" @change="uploadTrack" /></label>
      </div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Подпись</th><th>Тип</th><th>Язык</th><th>Размер</th><th></th></tr></thead><tbody>
        <tr v-for="t in audioTracks" :key="t.id">
          <td>{{ t.label }}</td>
          <td class="small muted">{{ t.kind === 'description' ? 'Тифлокомментарий' : 'Дубляж' }}</td>
          <td class="small">{{ t.language }}</td>
          <td class="small muted">{{ fmtBytes(t.size) }}</td>
          <td class="actions"><a v-if="t.url" class="btn ghost sm" :href="t.url" download>Скачать</a><button class="btn ghost sm danger" @click="removeTrack(t)">Удалить</button></td>
        </tr>
        <tr v-if="!audioTracks.length"><td colspan="5" class="muted">Дополнительных дорожек нет</td></tr>
      </tbody></table></div>
    </div>

    <!-- Доступ -->
    <div v-else-if="tab === 'access'" class="panel">
      <h3 class="mb-8">Доступ к видео</h3>
      <div class="field mb-16"><label>Видимость</label><select class="select" v-model="form.visibility" style="max-width: 360px"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }} — {{ v.hint }}</option></select></div>
      <template v-if="form.visibility === 'private'">
        <div class="label mb-8">Пользователи с доступом</div>
        <div class="row wrap mb-8"><span v-for="u in accessUsers" :key="u.id" class="chip"><ChannelAvatar :user="u" size="xs" /> {{ u.displayName }} <button class="ibtn sm" style="width:20px;height:20px" @click="accessUsers = accessUsers.filter((x) => x.id !== u.id)"><Icon name="close" :size="14" /></button></span><span v-if="!accessUsers.length" class="small muted">Пока никого — видео видите только вы и модераторы.</span></div>
        <div style="position: relative; max-width: 420px"><input class="input" v-model="userQuery" placeholder="Найти сотрудника по имени или почте" />
          <div v-if="userResults.length" class="menu" style="left:0;right:0;top:100%"><button v-for="u in userResults" :key="u.id" class="item" @click="addAccess(u)"><ChannelAvatar :user="u" size="xs" /> {{ u.displayName }} <span class="tiny muted">{{ u.email }}</span></button></div>
        </div>
        <div class="label mt-16 mb-8">Группы с доступом</div>
        <div class="row wrap gap-8"><button v-for="g in allGroups" :key="g.id" class="chip" :class="{ active: accessGroups.some((x) => x.id === g.id) }" @click="toggleGroup(g)"><Icon name="groups" :size="14" /> {{ g.name }} <span class="tiny" style="opacity:.7">{{ g.memberCount }}</span></button><span v-if="!allGroups.length" class="small muted">Групп пока нет — их создаёт администратор (Администрирование → Группы).</span></div>
        <p class="small muted mt-8">Также доступ автоматически получают сотрудники, которым видео назначено к обязательному просмотру.</p>
      </template>
      <div class="divider"></div>
      <label class="switch"><input type="checkbox" v-model="form.viewerWatermark" /><span class="track"></span><span>Водяной знак зрителя</span></label>
      <p class="small muted" style="margin: 4px 0 0 0">Поверх видео полупрозрачно показываются имя и почта зрителя и время просмотра — снижает риск утечки записей экрана. Для гостей по ссылке — пометка «Гостевой доступ».</p>
      <div class="form-actions"><button class="btn primary" @click="saveAccess">Сохранить</button></div>
      <div class="divider"></div>
      <h4 class="mb-8"><Icon name="linkLock" :size="18" style="vertical-align:-3px" /> Защищённые ссылки для внешних зрителей</h4>
      <ShareLinks :video="video" />
      <div class="divider"></div>
      <h4 class="mb-8">Встраивание</h4>
      <p class="small muted">Код для вставки на внутренние сайты (работает для публичных видео и видео «по ссылке»; для видео «для сотрудников» зрителю нужно войти на портал).</p>
      <pre class="code" style="white-space: pre-wrap">&lt;iframe width="1280" height="720" src="{{ embedUrl }}" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen&gt;&lt;/iframe&gt;</pre>
      <p class="small muted">Плеер поддерживает API для страницы-родителя (`?api=1`): события play, pause, timeupdate, progress, ended через postMessage и команды play/pause/seek — см. руководство.</p>
      <div class="divider"></div>
      <h4 class="mb-8"><Icon name="scorm" :size="18" style="vertical-align:-3px" /> Экспорт для LMS (SCORM)</h4>
      <p class="small muted">Пакет для Moodle, iSpring Learn, WebTutor, Mirapolis и других систем обучения: плеер открывается с портала, а зачёт выставляется в LMS при просмотре нужной доли видео.</p>
      <div class="row wrap gap-12 mt-8" style="align-items:flex-end">
        <div class="field"><label>Версия</label><select class="select sm" v-model="scorm.version"><option value="1.2">SCORM 1.2</option><option value="2004">SCORM 2004</option></select></div>
        <div class="field"><label>Зачёт при просмотре</label><select class="select sm" v-model.number="scorm.percent"><option v-for="p in [50, 70, 80, 90, 95, 100]" :key="p" :value="p">{{ p }}%</option></select></div>
        <label class="switch"><input type="checkbox" v-model="scorm.share" /><span class="track"></span><span>Гостевой доступ (без входа на портал)</span></label>
        <a class="btn sm" :href="scormUrl" :class="{ disabled: video.status !== 'ready' }"><Icon name="download" :size="16" /> Скачать SCORM-пакет</a>
      </div>
    </div>

    <!-- Аналитика -->
    <div v-else-if="tab === 'analytics'">
      <div class="row mb-16"><h3>Аналитика</h3><div class="chips" style="margin-left:auto"><button v-for="[k, l] in [['7d', '7 дней'], ['28d', '28 дней'], ['90d', '90 дней'], ['365d', 'Год'], ['all', 'Всё время']]" :key="k" class="chip" :class="{ active: range === k }" @click="range = k">{{ l }}</button></div></div>
      <div v-if="!analytics" class="loading-block"><div class="spin"></div></div>
      <template v-else>
        <div class="stat-tiles mb-24">
          <div class="stat-tile"><div class="v">{{ fmtNumber(analytics.totals.views) }}</div><div class="l">Просмотров</div><div class="d">{{ analytics.totals.uniqueViewers }} уникальных</div></div>
          <div class="stat-tile"><div class="v" style="font-size:24px">{{ fmtDurationLong(analytics.totals.watch) }}</div><div class="l">Время просмотра</div></div>
          <div class="stat-tile"><div class="v">{{ fmtDuration(analytics.totals.avgViewSeconds) }}</div><div class="l">Средняя длительность</div><div class="d">{{ analytics.totals.avgViewPercent }}% видео</div></div>
          <div class="stat-tile"><div class="v">{{ analytics.totals.completed }}</div><div class="l">Досмотрели до конца</div></div>
          <div class="stat-tile"><div class="v">{{ analytics.totals.likes }}</div><div class="l">Лайков</div></div>
          <div class="stat-tile"><div class="v">{{ analytics.totals.comments }}</div><div class="l">Комментариев</div></div>
        </div>
        <div class="panel mb-24"><h4 class="mb-8">Просмотры по дням</h4><LineChart :data="analytics.daily.map((d) => ({ x: d.day, y: d.views }))" label="просмотров" /></div>
        <div class="panel mb-24"><h4 class="mb-8">Удержание аудитории</h4><p class="small muted">Какая доля зрителей досматривает до каждого момента видео.</p><RetentionChart :data="analytics.retention" :duration="video.duration" /></div>
        <div class="two-col"><div class="panel"><h4 class="mb-16">Источники просмотров</h4><BarList :data="analytics.sources" :labels="SOURCES" /></div><div class="panel"><h4 class="mb-16">Устройства</h4><BarList :data="analytics.devices" :labels="DEVICES" /></div></div>
        <div v-if="viewers" class="panel mt-24">
          <div class="row wrap mb-8"><h4 class="grow"><Icon name="people" :size="18" style="vertical-align:-3px" /> Кто смотрел</h4><a v-if="viewers.length" class="btn sm" :href="$url(`/api/videos/${video.id}/viewers?format=csv`)"><Icon name="csv" :size="16" /> CSV</a></div>
          <p class="small muted">Сотрудники, открывавшие видео (за период хранения истории просмотров). Анонимные и гостевые просмотры не показываются.</p>
          <div class="table-wrap"><table class="table"><thead><tr><th>Сотрудник</th><th style="width: 28%">Просмотрено</th><th>Время</th><th>Сеансов</th><th>Последний просмотр</th></tr></thead><tbody>
            <tr v-for="u in viewers" :key="u.id"><td><div class="row gap-8"><ChannelAvatar :user="u" size="xs" /><span>{{ u.displayName }}<div class="tiny muted">{{ u.email }}</div></span></div></td><td><div class="row gap-8" style="align-items:center"><div class="meter grow"><i :class="u.completed ? 'ok' : ''" :style="{ width: u.percent + '%' }"></i></div><span class="small" style="width: 40px">{{ u.percent }}%</span></div></td><td class="small">{{ fmtDurationLong(u.watchedSeconds) }}</td><td class="small">{{ u.sessions }}</td><td class="small muted">{{ fmtDateTime(u.lastAt) }}</td></tr>
            <tr v-if="!viewers.length"><td colspan="5" class="muted">Пока никто из сотрудников не смотрел это видео (или список зрителей отключён администратором)</td></tr>
          </tbody></table></div>
        </div>
      </template>
    </div>
    <AssignDialog v-if="assignOpen" :video-id="video.id" :title="video.title" :has-quiz="video.hasQuiz" @close="assignOpen = false" />
  </div>
  <div v-else class="loading-block"><div class="spin"></div></div>
</template>

<style>
.edit-head { display: grid; grid-template-columns: 380px 1fr; gap: 24px; align-items: start; }
.edit-preview { min-width: 0; }
.thumb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.thumb-opt { position: relative; aspect-ratio: 16/9; border-radius: var(--radius-sm); overflow: hidden; border: 3px solid transparent; background: var(--bg-3); }
.thumb-opt img { width: 100%; height: 100%; object-fit: cover; }
.thumb-opt.active { border-color: var(--brand); }
.thumb-opt .chk { position: absolute; right: 8px; bottom: 8px; background: var(--brand); color: #fff; border-radius: 50%; padding: 3px; }
.thumb-opt .tag { position: absolute; left: 8px; top: 8px; }
.chapter-edit .input { height: 36px; }
.studio-tabs { flex-wrap: wrap; overflow: visible; }
.studio-tabs .tab { padding: 10px 12px; }
@media (max-width: 900px) { .edit-head { grid-template-columns: 1fr; } }
</style>
