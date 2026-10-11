<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { fmtDuration, fmtViews, timeAgo, VISIBILITY, STATUS } from '../utils/format.js';
import ChannelAvatar from './ChannelAvatar.vue';
import { useRouter } from 'vue-router';
import { get } from '../api.js';
import { useUi } from '../stores/ui.js';
import { useAuth } from '../stores/auth.js';

const props = defineProps({
  video: { type: Object, required: true },
  layout: { type: String, default: 'grid' }, // grid | list | shorts
  showChannel: { type: Boolean, default: true },
  showStatus: { type: Boolean, default: false },
  searchQuery: { type: String, default: '' },   // 1.4: включает кнопку «Перейти к моменту»
  playlistId: { type: String, default: '' },
});
const to = computed(() => ({ name: 'watch', params: { id: props.video.shortId }, query: props.playlistId ? { list: props.playlistId } : {} }));

// 1.4: найденная фраза → открыть видео с нужной секунды
const router = useRouter();
const jumping = ref(false);
async function jumpToMoment() {
  if (!props.searchQuery) return;
  jumping.value = true;
  try {
    const r = await get(`/api/videos/${props.video.shortId}/moment?q=${encodeURIComponent(props.searchQuery)}`);
    const at = r.at;
    router.push({ name: 'watch', params: { id: props.video.shortId }, query: at != null ? { t: Math.max(0, Math.floor(at)) } : {} });
    if (at == null) ui.toast('Точный момент найти не удалось — открыли видео сначала');
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { jumping.value = false; }
}
const dueClass = computed(() => {
  if (!props.video.dueAt) return '';
  const d = new Date(props.video.dueAt) - Date.now();
  return d < 0 ? 'overdue' : d < 3 * 86400000 ? 'soon' : '';
});
const dueLabel = computed(() => {
  if (!props.video.dueAt) return 'Назначено к просмотру';
  const d = new Date(props.video.dueAt);
  const days = Math.ceil((d - Date.now()) / 86400000);
  if (days < 0) return `Просрочено (${d.toLocaleDateString('ru-RU')})`;
  if (days === 0) return 'Срок — сегодня';
  return `Посмотреть до ${d.toLocaleDateString('ru-RU')}`;
});
const progress = computed(() => {
  const p = props.video.progressPosition;
  if (!p || !props.video.duration) return 0;
  return Math.min(100, Math.round((p / props.video.duration) * 100));
});
// --- Анимированное превью при наведении (кадры раскадровки, как на YouTube) ---------------
const thumbEl = ref(null);
const sbFrame = ref(-1);
const sbScale = ref(1);
let sbDelay = null; let sbTimer = null;
const sb = computed(() => (props.video.storyboardMeta && props.video.storyboardUrl && props.video.storyboardMeta.frames > 1) ? props.video.storyboardMeta : null);
const sbBase = computed(() => props.video.storyboardUrl ? props.video.storyboardUrl.slice(0, props.video.storyboardUrl.lastIndexOf('/') + 1) : '');
const sbStyle = computed(() => {
  const m = sb.value; if (!m || sbFrame.value < 0) return null;
  const per = m.cols * m.rows; const sheet = Math.floor(sbFrame.value / per) + 1; const idx = sbFrame.value % per;
  const s = sbScale.value;
  return {
    backgroundImage: `url("${sbBase.value}sb_${sheet}.jpg")`,
    backgroundPosition: `-${(idx % m.cols) * m.tw * s}px -${Math.floor(idx / m.cols) * m.th * s}px`,
    backgroundSize: `${m.cols * m.tw * s}px ${m.rows * m.th * s}px`,
  };
});
const sbProgress = computed(() => (sb.value && sbFrame.value >= 0) ? Math.round(((sbFrame.value + 1) / sb.value.frames) * 100) : 0);
function previewStart(e) {
  if (!sb.value || (e && e.pointerType === 'touch') || window.matchMedia('(hover: none)').matches) return;
  previewStop();
  sbDelay = setTimeout(() => {
    const m = sb.value; if (!m || !thumbEl.value) return;
    sbScale.value = (thumbEl.value.clientWidth || m.tw) / m.tw;
    for (let i = 1; i <= Math.min(m.sheets || 1, 3); i++) { const img = new Image(); img.src = `${sbBase.value}sb_${i}.jpg`; }
    const step = Math.max(1, Math.round(m.frames / 30)); // полный проход примерно за 12 секунд
    sbFrame.value = Math.min(m.frames - 1, Math.floor(m.frames * 0.05));
    sbTimer = setInterval(() => { sbFrame.value = (sbFrame.value + step) % m.frames; }, 400);
  }, 600);
}
function previewStop() { clearTimeout(sbDelay); clearInterval(sbTimer); sbDelay = null; sbTimer = null; sbFrame.value = -1; }
onBeforeUnmount(previewStop);

// 1.3: очередь просмотра
const ui = useUi();
const auth = useAuth();
function toggleQueue() { if (ui.queue.some((q) => q.id === props.video.id)) { ui.queueRemove(props.video.id); ui.toast('Удалено из очереди'); } else { ui.queueAdd(props.video); ui.toast('Добавлено в очередь', { type: 'success' }); } }
const meta = computed(() => {
  const parts = [];
  if (props.video.status && props.video.status !== 'ready') parts.push(STATUS[props.video.status]);
  else parts.push(fmtViews(props.video.viewCount));
  if (props.video.publishedAt) parts.push(timeAgo(props.video.publishedAt));
  else if (props.video.createdAt) parts.push(timeAgo(props.video.createdAt));
  return parts.join(' • ');
});
</script>

<template>
  <article class="vcard" :class="layout" @pointerenter="previewStart" @pointerleave="previewStop">
    <router-link :to="to" class="thumb" :class="{ vertical: layout === 'shorts', previewing: sbFrame >= 0 }" @focus="previewStart" @blur="previewStop">
      <span ref="thumbEl" class="thumb-measure"></span>
      <img v-if="video.thumbnailUrl" :src="video.thumbnailUrl" :alt="video.title" loading="lazy" />
      <div v-else class="thumb-empty"><Icon name="movie" :size="32" /></div>
      <span v-if="sbStyle" class="sb-preview" :style="sbStyle"><i class="sb-bar" :style="{ width: sbProgress + '%' }"></i></span>
      <span v-if="video.duration" class="dur">{{ fmtDuration(video.duration) }}</span>
      <span v-if="video.isLiveRecording" class="badge tag brand">Запись эфира</span>
      <span v-else-if="video.status === 'processing' || video.status === 'queued'" class="badge tag warning">{{ STATUS[video.status] }} {{ video.processingProgress ? video.processingProgress + '%' : '' }}</span>
      <span v-else-if="video.status === 'failed'" class="badge tag danger">Ошибка</span>
      <span v-else-if="video.moderationStatus === 'pending'" class="badge tag warning">На проверке</span>
      <span v-else-if="video.isBlocked" class="badge tag danger">Заблокировано</span>
      <span v-if="video.hasQuiz && !video.isLiveRecording && video.status === 'ready'" class="badge tag quiz" title="Видео с проверкой знаний"><Icon name="quiz" :size="12" /> Тест</span>
      <span v-if="progress" class="progress"><i :style="{ width: progress + '%' }"></i></span>
      <span v-else-if="video.progressPercent" class="progress"><i :style="{ width: video.progressPercent + '%' }"></i></span>
      <button v-if="auth.isActive && video.status === 'ready' && layout !== 'shorts'" class="vc-queue" :title="ui.queue.some((q) => q.id === video.id) ? 'В очереди' : 'Добавить в очередь'" :class="{ on: ui.queue.some((q) => q.id === video.id) }" @click.prevent.stop="toggleQueue"><Icon :name="ui.queue.some((q) => q.id === video.id) ? 'check' : 'queue'" :size="18" /></button>
    </router-link>
    <div class="vc-body">
      <router-link v-if="showChannel && video.owner && layout === 'grid'" :to="`/@${video.owner.handle}`" class="vc-avatar"><ChannelAvatar :user="video.owner" /></router-link>
      <div class="vc-text">
        <router-link :to="to" class="vc-title clamp-2" :title="video.title">{{ video.title }}</router-link>
        <div class="vc-meta">
          <router-link v-if="showChannel && video.owner" :to="`/@${video.owner.handle}`" class="vc-channel ellipsis">{{ video.owner.displayName }}</router-link>
          <div class="ellipsis">{{ meta }}</div>
          <div v-if="video.dueAt !== undefined && video.assignmentId" class="due" :class="dueClass" style="margin-top:4px"><Icon name="assignment" :size="14" /> {{ dueLabel }}</div>
          <div v-if="showStatus" class="row gap-4 tiny" style="margin-top: 4px">
            <span class="badge" :class="{ brand: video.visibility === 'public' }"><Icon :name="VISIBILITY[video.visibility]?.icon" :size="12" /> {{ VISIBILITY[video.visibility]?.label }}</span>
          </div>
          <p v-if="layout === 'list' && video.description" class="vc-desc clamp-2">{{ video.description }}</p>
          <p v-if="video.transcriptHit" class="vc-hit small"><span v-html="'…' + video.transcriptHit + '…'"></span>
            <button v-if="searchQuery" class="hit-jump" :disabled="jumping" @click.prevent.stop="jumpToMoment">{{ jumping ? 'Ищу…' : 'Перейти к моменту' }}</button>
          </p>
          <p v-if="video.screenTextHit" class="vc-hit small" :title="'Текст на экране'"><Icon name="ocr" :size="12" style="vertical-align:-2px" /> <span v-html="'…' + video.screenTextHit + '…'"></span>
            <button v-if="searchQuery && !video.transcriptHit" class="hit-jump" :disabled="jumping" @click.prevent.stop="jumpToMoment">{{ jumping ? 'Ищу…' : 'Перейти к моменту' }}</button>
          </p>
        </div>
      </div>
      <slot name="menu" />
    </div>
  </article>
</template>

<style>
.vcard { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.vcard .thumb { display: block; }
.vc-queue { position: absolute; right: 8px; top: 8px; width: 30px; height: 30px; border-radius: 6px; border: 0; background: rgba(0,0,0,.7); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; opacity: 0; transition: opacity .15s; z-index: 2; }
.vcard:hover .vc-queue, .vc-queue.on { opacity: 1; }
.vcard .thumb .badge.tag.quiz ~ .vc-queue { top: 40px; }
@media (hover: none) { .vc-queue { display: none; } }
.vcard .thumb:hover img { transform: scale(1.03); }
.vcard .thumb img { transition: transform 300ms ease; }
.thumb-measure { position: absolute; inset: 0; pointer-events: none; }
.sb-preview { position: absolute; inset: 0; background-repeat: no-repeat; background-color: #000; animation: sbFade 200ms ease; }
.sb-bar { position: absolute; left: 0; bottom: 0; height: 3px; background: var(--brand-500, #2e7dbf); transition: width 400ms linear; }
.vcard .thumb.previewing .dur, .vcard .thumb.previewing .progress { opacity: 0; }
@keyframes sbFade { from { opacity: 0; } to { opacity: 1; } }
.badge.tag.quiz { background: rgba(15,67,130,.85); color: #fff; right: 8px; left: auto; top: 8px; }
.thumb-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--text-3); }
.vc-body { display: flex; gap: 12px; align-items: flex-start; min-width: 0; }
.vc-text { min-width: 0; flex: 1; }
.vc-title { display: block; color: var(--text); font-weight: 500; font-size: 15px; line-height: 1.35; margin-bottom: 4px; }
.vc-meta { font-size: 13px; color: var(--text-2); line-height: 1.4; }
.vc-channel { display: block; color: var(--text-2); }
.vc-channel:hover { color: var(--text); }
.vc-desc { margin: 6px 0 0; color: var(--text-3); font-size: 13px; }
.hit-jump { margin-left: 6px; border: 0; background: none; padding: 0; color: var(--brand); cursor: pointer; font: inherit; font-size: 12px; }
.hit-jump:hover { text-decoration: underline; }
.vc-hit { margin: 6px 0 0; color: var(--text-2); }
.vc-hit b { color: var(--brand); font-weight: 500; }
.vcard.list { flex-direction: row; gap: 16px; }
.vcard.list .thumb { width: 42%; max-width: 360px; min-width: 160px; flex-shrink: 0; }
.vcard.list .vc-title { font-size: 17px; }
.vcard.compact { flex-direction: row; gap: 10px; }
.vcard.compact .thumb { width: 168px; min-width: 168px; border-radius: var(--radius-sm); }
.vcard.compact .vc-title { font-size: 14px; }
.vcard.compact .vc-meta { font-size: 12px; }
.vcard.shorts .vc-title { font-size: 14px; }
@media (max-width: 640px) {
  .vcard.list { flex-direction: column; gap: 10px; }
  .vcard.list .thumb { width: 100%; max-width: none; }
  .vcard.compact .thumb { width: 140px; min-width: 140px; }
}
</style>
