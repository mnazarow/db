<script setup>
// Согласование видео: рецензенты, замечания с таймкодами и решения (как review-страницы Frame.io и Vimeo).
import { ref, computed, onMounted } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import ChannelAvatar from '../ChannelAvatar.vue';
import Modal from '../Modal.vue';
import { fmtDuration, fmtDateTime } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const auth = useAuth();
const ui = useUi();
const data = ref(null);
const busy = ref(false);
const text = ref('');
const withTime = ref(true);
const dialog = ref(null);      // форма отправки на согласование
const people = ref([]);
const decision = ref(null);    // { kind, comment }

const review = computed(() => data.value?.review || null);
const isAuthor = computed(() => !!props.video.viewer?.isOwner || auth.isStaff);
const STATUS = {
  in_review: { label: 'На согласовании', cls: 'brand' },
  approved: { label: 'Согласовано', cls: 'success' },
  changes_requested: { label: 'Нужны правки', cls: 'warning' },
  cancelled: { label: 'Отозвано', cls: '' },
};

async function load() {
  try { data.value = await get(`/api/videos/${props.video.shortId}/review`); } catch { data.value = null; }
}
onMounted(load);

const search = ref('');
async function openDialog() {
  dialog.value = { reviewers: [], note: '', dueAt: '' };
  people.value = [];
  search.value = '';
}
let searchTimer = null;
function onSearch() {
  clearTimeout(searchTimer);
  const q = search.value.trim();
  if (q.length < 2) { people.value = []; return; }
  searchTimer = setTimeout(async () => {
    try { people.value = (await get(`/api/users/lookup?q=${encodeURIComponent(q)}`)).users || []; } catch { people.value = []; }
  }, 250);
}
const picked = ref([]);   // выбранные рецензенты целиком, чтобы показывать их после смены поиска
function rememberPick(u) {
  if (!picked.value.some((x) => x.id === u.id)) picked.value.push(u);
}
function toggleReviewer(u) {
  const list = dialog.value.reviewers;
  dialog.value.reviewers = list.includes(u.id) ? list.filter((x) => x !== u.id) : [...list, u.id];
  rememberPick(u);
}
async function send() {
  busy.value = true;
  try {
    await post(`/api/videos/${props.video.id}/review`, {
      reviewers: dialog.value.reviewers, note: dialog.value.note,
      dueAt: dialog.value.dueAt ? new Date(dialog.value.dueAt).toISOString() : null,
    });
    dialog.value = null;
    await load();
    ui.toast('Отправлено на согласование', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = false; }
}
async function addComment() {
  const body = text.value.trim();
  if (!body) return;
  busy.value = true;
  try {
    const at = withTime.value ? Math.round(props.player?.currentTime?.() || 0) : null;
    data.value.review = (await post(`/api/videos/${props.video.id}/review/comments`, { body, at })).review;
    text.value = '';
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = false; }
}
async function decide() {
  busy.value = true;
  try {
    data.value.review = (await post(`/api/videos/${props.video.id}/review/decision`, { decision: decision.value.kind, comment: decision.value.comment })).review;
    decision.value = null;
    ui.toast('Решение сохранено', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = false; }
}
async function resolve(c) {
  try { data.value.review = (await patch(`/api/videos/${props.video.id}/review/comments/${c.id}`, { resolved: !c.resolved })).review; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function cancel() {
  if (!(await ui.ask({ title: 'Отозвать согласование?', message: 'Рецензенты больше не увидят его в списке.', okLabel: 'Отозвать', danger: true }))) return;
  await del(`/api/videos/${props.video.id}/review`);
  await load();
}
function seek(at) { if (at !== null) { props.player?.seekTo?.(at); props.player?.play?.(); } }
</script>

<template>
  <div v-if="data && (review || (isAuthor && data.canRequest))" class="panel review-panel">
    <div class="row wrap gap-8 rv-head">
      <Icon name="checkAll" :size="18" />
      <b class="grow">Согласование</b>
      <span v-if="review" class="badge" :class="STATUS[review.status]?.cls">{{ STATUS[review.status]?.label }}</span>
      <button v-if="isAuthor && review?.status === 'in_review'" class="btn sm soft" @click="cancel">Отозвать</button>
      <button v-if="isAuthor && (!review || review.status !== 'in_review')" class="btn sm primary" @click="openDialog">
        <Icon name="send" :size="16" /> На согласование
      </button>
    </div>

    <div v-if="!review" class="rv-empty small muted">
      Отправьте видео коллегам на проверку: они оставят замечания с привязкой к секунде и примут решение — «согласовано» или «нужны правки».
    </div>

    <template v-else>
      <div class="rv-body">
        <p v-if="review.note" class="small"><b>Что проверить:</b> {{ review.note }}</p>
        <p v-if="review.dueAt" class="tiny muted">Срок: {{ fmtDateTime(review.dueAt) }}</p>

        <div class="row wrap gap-8 mt-8">
          <div v-for="r in review.reviewers" :key="r.id" class="rv-person" :class="r.decision">
            <ChannelAvatar :user="r" size="xs" />
            <span class="small">{{ r.displayName }}</span>
            <Icon v-if="r.decision === 'approved'" name="done" :size="16" style="color: var(--success)" />
            <Icon v-else-if="r.decision === 'changes_requested'" name="alertCircle" :size="16" style="color: var(--warning)" />
            <span v-else class="tiny muted">ждём</span>
          </div>
        </div>

        <div v-if="review.comments.length" class="col gap-4 mt-12">
          <div v-for="c in review.comments" :key="c.id" class="rv-comment" :class="{ done: c.resolved }">
            <button v-if="c.at !== null" class="rv-at" @click="seek(c.at)">{{ fmtDuration(c.at) }}</button>
            <span v-else class="rv-at muted">—</span>
            <div class="grow">
              <span class="rv-name">{{ c.user.displayName }}</span>
              <span class="rv-text">{{ c.body }}</span>
            </div>
            <button v-if="isAuthor" class="ibtn sm" :title="c.resolved ? 'Вернуть в работу' : 'Исправлено'" @click="resolve(c)" :aria-label="c.resolved ? 'Вернуть в работу' : 'Исправлено'">
              <Icon :name="c.resolved ? 'done' : 'doneOutline'" :size="16" />
            </button>
          </div>
        </div>

        <form v-if="review.status === 'in_review' && (review.isReviewer || isAuthor)" class="row gap-8 mt-12" @submit.prevent="addComment">
          <input v-model="text" class="input grow" maxlength="1000" placeholder="Замечание к этому моменту…" />
          <label class="switch sm" title="Привязать к текущей секунде"><input type="checkbox" v-model="withTime" /><span class="track"></span><span class="tiny">{{ fmtDuration(Math.round(player?.currentTime?.() || 0)) }}</span></label>
          <button class="btn sm" :disabled="!text.trim() || busy" type="submit">Добавить</button>
        </form>

        <div v-if="review.status === 'in_review' && review.isReviewer && !review.myDecision" class="row gap-8 mt-12">
          <button class="btn primary sm" @click="decision = { kind: 'approved', comment: '' }"><Icon name="done" :size="16" /> Согласовать</button>
          <button class="btn sm" @click="decision = { kind: 'changes_requested', comment: '' }"><Icon name="replay" :size="16" /> Вернуть на доработку</button>
        </div>
        <p v-else-if="review.myDecision" class="tiny muted mt-8">Ваше решение: {{ review.myDecision === 'approved' ? 'согласовано' : 'нужны правки' }}</p>
      </div>
    </template>

    <Modal v-if="dialog" title="Отправить на согласование" @close="dialog = null">
      <div class="col gap-12">
        <div class="field"><label>Кто проверяет</label>
          <input class="input" v-model="search" @input="onSearch" placeholder="Имя или почта сотрудника" />
          <div class="rv-people mt-8">
            <button v-for="u in people" :key="u.id" class="rv-pick" :class="{ on: dialog.reviewers.includes(u.id) }" @click="toggleReviewer(u)">
              <ChannelAvatar :user="u" size="xs" /><span class="ellipsis">{{ u.displayName }}</span>
              <Icon v-if="dialog.reviewers.includes(u.id)" name="check" :size="16" />
            </button>
          </div>
          <div v-if="dialog.reviewers.length" class="row wrap gap-4 mt-8">
            <span v-for="u in picked.filter((x) => dialog.reviewers.includes(x.id))" :key="u.id" class="badge brand">{{ u.displayName }}</span>
          </div>
        </div>
        <div class="field"><label>Что проверить</label><textarea class="input" rows="3" v-model="dialog.note" maxlength="1000" placeholder="На что обратить внимание"></textarea></div>
        <div class="field"><label>Срок (необязательно)</label><input class="input" type="datetime-local" v-model="dialog.dueAt" /></div>
      </div>
      <template #footer>
        <button class="btn" @click="dialog = null">Отмена</button>
        <button class="btn primary" :disabled="!dialog.reviewers.length || busy" @click="send">Отправить</button>
      </template>
    </Modal>

    <Modal v-if="decision" :title="decision.kind === 'approved' ? 'Согласовать видео' : 'Вернуть на доработку'" @close="decision = null">
      <div class="field">
        <label>{{ decision.kind === 'approved' ? 'Комментарий (необязательно)' : 'Что нужно исправить' }}</label>
        <textarea class="input" rows="3" v-model="decision.comment" maxlength="1000"></textarea>
      </div>
      <template #footer>
        <button class="btn" @click="decision = null">Отмена</button>
        <button class="btn primary" :disabled="busy || (decision.kind === 'changes_requested' && !decision.comment.trim())" @click="decide">
          {{ decision.kind === 'approved' ? 'Согласовать' : 'Вернуть' }}
        </button>
      </template>
    </Modal>
  </div>
</template>

<style>
.review-panel { padding: 0; overflow: hidden; }
.review-panel .rv-head { padding: 10px 12px; border-bottom: 1px solid var(--line-2); align-items: center; }
.review-panel .rv-empty, .review-panel .rv-body { padding: 12px; }
.review-panel .rv-person { display: inline-flex; gap: 6px; align-items: center; padding: 4px 10px 4px 4px; border-radius: 999px; background: var(--bg-2); }
.review-panel .rv-person.approved { background: rgba(30,142,78,.12); }
.review-panel .rv-person.changes_requested { background: rgba(192,57,43,.12); }
.review-panel .rv-comment { display: flex; gap: 8px; align-items: flex-start; padding: 6px 8px; border-radius: 8px; font-size: 14px; }
.review-panel .rv-comment:hover { background: var(--bg-2); }
.review-panel .rv-comment.done { opacity: .55; text-decoration: line-through; }
.review-panel .rv-at { color: var(--brand); font-variant-numeric: tabular-nums; font-size: 13px; flex-shrink: 0; padding-top: 1px; }
.review-panel .rv-name { font-weight: 600; margin-right: 6px; }
.review-panel .rv-text { overflow-wrap: anywhere; }
.rv-people { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 6px; max-height: 260px; overflow-y: auto; }
.rv-pick { display: flex; gap: 8px; align-items: center; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--line-2); text-align: left; }
.rv-pick.on { border-color: var(--brand); background: var(--brand-100); color: var(--brand); }
</style>
