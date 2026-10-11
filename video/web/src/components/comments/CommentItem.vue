<script setup>
import { ref, computed, onMounted } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import ChannelAvatar from '../ChannelAvatar.vue';
import CommentForm from './CommentForm.vue';
import Dropdown from '../Dropdown.vue';
import ReportDialog from '../ReportDialog.vue';
import { timeAgo, fmtNumber } from '../../utils/format.js';
import { BASE } from '../../base.js';

const props = defineProps({ comment: { type: Object, required: true }, video: { type: Object, required: true }, isReply: Boolean, highlight: { type: String, default: '' } });
const emit = defineEmits(['deleted', 'reply']);
const auth = useAuth();
const ui = useUi();
const c = ref({ ...props.comment });
const replying = ref(false);
const editing = ref(false);
const showReplies = ref(false);
const replies = ref([]);
const repliesLoaded = ref(false);
const reporting = ref(false);
const isOwnerOfVideo = computed(() => auth.user && (auth.user.id === props.video.owner?.id || auth.isStaff));
const mine = computed(() => auth.user && auth.user.id === c.value.author.id);

/** Разметка: таймкоды → ссылки, URL → ссылки, @упоминания. */
const html = computed(() => {
  const esc = (s) => s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  let t = esc(c.value.body || '');
  t = t.replace(/(^|\s)((?:\d{1,2}:)?\d{1,2}:\d{2})(?=\s|$|[.,!?)])/g, (m, pre, ts) => `${pre}<a href="#" data-ts="${ts}" class="ts">${ts}</a>`);
  t = t.replace(/(https?:\/\/[^\s<]+)/g, (m) => `<a href="${m}" target="_blank" rel="noopener">${m}</a>`);
  // Ссылка мимо маршрутизатора — с префиксом пути портала (VITE_BASE)
  t = t.replace(/(^|\s)@([\w.-]{2,30})/g, (m, pre, h) => `${pre}<a href="${BASE}/@${h}">@${h}</a>`);
  return t.replace(/\n/g, '<br>');
});
function onBodyClick(e) {
  const a = e.target.closest('a[data-ts]');
  if (a) { e.preventDefault(); const parts = a.dataset.ts.split(':').map(Number); const s = parts.reduce((x, y) => x * 60 + y, 0); window.dispatchEvent(new CustomEvent('cv:seek', { detail: s })); }
}
async function like(value) {
  if (!auth.isActive) return ui.toast('Войдите, чтобы оценивать комментарии');
  const v = c.value.viewerLike === value ? 0 : value;
  try { const r = await post(`/api/comments/${c.value.id}/like`, { value: v }); c.value.viewerLike = v; c.value.likeCount = r.likeCount; c.value.dislikeCount = r.dislikeCount; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function reply(body) {
  const r = await post(`/api/videos/${props.video.shortId}/comments`, { body, parentId: c.value.id });
  if (r.held) ui.toast('Ответ отправлен на проверку', { type: 'info' });
  replying.value = false;
  if (!repliesLoaded.value) await loadReplies();
  else replies.value.push(r.comment);
  showReplies.value = true;
  c.value.replyCount++;
}
async function loadReplies() {
  const r = await get(`/api/comments/${c.value.id}/replies`);
  replies.value = r.comments; repliesLoaded.value = true; showReplies.value = true;
}
async function save(body) {
  const r = await patch(`/api/comments/${c.value.id}`, { body });
  c.value = { ...c.value, ...r.comment }; editing.value = false;
  // Правка с запрещёнными словами (или при премодерации) уходит на проверку автору видео
  if (r.held) ui.toast('Изменённый комментарий отправлен на проверку', { type: 'info' });
}
async function remove() {
  if (!(await ui.ask({ title: 'Удалить комментарий?', message: 'Это действие нельзя отменить.', okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/comments/${c.value.id}`);
  emit('deleted', c.value);
}
async function pin() { const r = await post(`/api/comments/${c.value.id}/pin`, { pinned: !c.value.isPinned }); c.value.isPinned = r.pinned; }
async function heart() { const r = await post(`/api/comments/${c.value.id}/heart`, { hearted: !c.value.isHearted }); c.value.isHearted = r.hearted; }
async function moderate(action) { const r = await post(`/api/comments/${c.value.id}/moderate`, { action }); c.value.status = r.status; ui.toast(action === 'approve' ? 'Комментарий опубликован' : 'Комментарий скрыт'); }
onMounted(() => { if (props.highlight && (props.highlight === c.value.id) && !props.isReply) setTimeout(() => document.getElementById('c-' + c.value.id)?.scrollIntoView({ block: 'center' }), 300); });
</script>

<template>
  <div class="comment" :id="'c-' + c.id" :class="{ reply: isReply, held: c.status === 'held', hidden: c.status === 'hidden', hl: highlight === c.id }">
    <router-link :to="`/@${c.author.handle}`"><ChannelAvatar :user="c.author" :size="isReply ? 'sm' : ''" /></router-link>
    <div class="grow" style="min-width: 0">
      <div class="row gap-8 wrap small">
        <span v-if="c.isPinned" class="faint tiny row gap-4"><Icon name="pin" :size="12" /> Закреплено</span>
        <router-link :to="`/@${c.author.handle}`" class="c-author" :class="{ owner: c.isVideoOwner }">{{ c.author.displayName }}</router-link>
        <span v-if="c.author.role === 'admin'" class="badge brand" style="font-size: 9px">админ</span>
        <span class="faint">{{ timeAgo(c.createdAt) }}<template v-if="c.editedAt"> (изменено)</template></span>
        <span v-if="c.status === 'held'" class="badge warning">На проверке</span>
        <span v-if="c.status === 'hidden'" class="badge danger">Скрыт</span>
      </div>
      <CommentForm v-if="editing" :initial="c.body" autofocus submit-label="Сохранить" @submit="save" @cancel="editing = false" />
      <div v-else-if="c.status === 'deleted'" class="muted small" style="font-style: italic">Комментарий удалён</div>
      <div v-else class="c-body" v-html="html" @click="onBodyClick"></div>
      <div class="row gap-4 mt-8 c-actions" v-if="c.status !== 'deleted'">
        <button class="ibtn sm" :class="{ active: c.viewerLike === 1 }" @click="like(1)" title="Нравится" aria-label="Нравится"><Icon :name="c.viewerLike === 1 ? 'thumbUpFill' : 'thumbUp'" :size="18" /></button>
        <span class="small muted" v-if="c.likeCount">{{ fmtNumber(c.likeCount) }}</span>
        <button class="ibtn sm" :class="{ active: c.viewerLike === -1 }" @click="like(-1)" title="Не нравится" aria-label="Не нравится"><Icon :name="c.viewerLike === -1 ? 'thumbDownFill' : 'thumbDown'" :size="18" /></button>
        <span v-if="c.isHearted" class="hearted" title="Отмечено автором"><Icon name="heart" :size="16" /></span>
        <button v-if="auth.isActive" class="btn ghost sm" style="text-transform: none; letter-spacing: 0" @click="replying = !replying">Ответить</button>
        <template v-if="isOwnerOfVideo && c.status === 'held'">
          <button class="btn sm primary" @click="moderate('approve')">Одобрить</button>
          <button class="btn sm danger" @click="moderate('hide')">Скрыть</button>
        </template>
        <Dropdown v-if="auth.user" align="left" class="c-more">
          <template #trigger><button class="ibtn sm" aria-label="Действия с комментарием"><Icon name="more" :size="18" /></button></template>
          <button v-if="mine" class="item" @click="editing = true"><Icon class="ic" name="edit" :size="18" /> Изменить</button>
          <button v-if="mine || isOwnerOfVideo" class="item danger" @click="remove"><Icon class="ic" name="delete" :size="18" /> Удалить</button>
          <template v-if="isOwnerOfVideo && !isReply"><button class="item" @click="pin"><Icon class="ic" name="pin" :size="18" /> {{ c.isPinned ? 'Открепить' : 'Закрепить' }}</button></template>
          <button v-if="isOwnerOfVideo" class="item" @click="heart"><Icon class="ic" name="heart" :size="18" /> {{ c.isHearted ? 'Убрать сердечко' : 'Отметить сердечком' }}</button>
          <button v-if="isOwnerOfVideo && c.status === 'visible'" class="item" @click="moderate('hide')"><Icon class="ic" name="eyeOff" :size="18" /> Скрыть</button>
          <button v-if="isOwnerOfVideo && c.status === 'hidden'" class="item" @click="moderate('approve')"><Icon class="ic" name="eye" :size="18" /> Показать</button>
          <button v-if="!mine" class="item" @click="reporting = true"><Icon class="ic" name="flag" :size="18" /> Пожаловаться</button>
        </Dropdown>
      </div>
      <div v-if="replying" class="row mt-8" style="align-items: flex-start">
        <ChannelAvatar :user="auth.user" size="sm" />
        <CommentForm class="grow" :initial="isReply ? '@' + c.author.handle + ' ' : ''" autofocus submit-label="Ответить" @submit="reply" @cancel="replying = false" />
      </div>
      <template v-if="!isReply && c.replyCount > 0">
        <button class="btn ghost sm replies-toggle" @click="showReplies ? (showReplies = false) : (repliesLoaded ? (showReplies = true) : loadReplies())">
          <Icon :name="showReplies ? 'chevronUp' : 'chevronDown'" :size="18" /> {{ c.replyCount }} {{ c.replyCount === 1 ? 'ответ' : c.replyCount < 5 ? 'ответа' : 'ответов' }}
        </button>
        <div v-if="showReplies" class="col gap-12 mt-8">
          <CommentItem v-for="r in replies" :key="r.id" :comment="r" :video="video" is-reply :highlight="highlight" @deleted="replies = replies.filter((x) => x.id !== $event.id); c.replyCount--" />
        </div>
      </template>
    </div>
    <ReportDialog v-if="reporting" target-type="comment" :target-id="c.id" @close="reporting = false" />
  </div>
</template>

<style>
.comment { display: flex; gap: 12px; align-items: flex-start; padding: 4px; border-radius: var(--radius-sm); }
.comment.hl { background: var(--brand-50); }
.comment.held { opacity: 0.85; }
.comment.hidden { opacity: 0.6; }
.c-author { color: var(--text); font-weight: 500; }
.c-author.owner { background: var(--brand-100); color: var(--brand); padding: 0 8px; border-radius: var(--pill); }
.c-body { margin-top: 2px; white-space: pre-wrap; word-break: break-word; font-size: 14px; line-height: 1.5; }
.c-body a.ts { font-weight: 500; }
.c-actions .btn.ghost.sm { height: 28px; padding: 0 10px; font-size: 13px; }
.replies-toggle { color: var(--brand); text-transform: none; letter-spacing: 0; margin-top: 6px; }
.hearted { color: var(--danger); display: inline-flex; margin: 0 4px; }
</style>
