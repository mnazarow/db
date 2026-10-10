<script setup>
import { ref, watch, onMounted } from 'vue';
import { get, post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import CommentItem from './CommentItem.vue';
import CommentForm from './CommentForm.vue';
import ChannelAvatar from '../ChannelAvatar.vue';
import { fmtNumber } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, highlight: { type: String, default: '' } });
const auth = useAuth();
const ui = useUi();
const comments = ref([]);
const total = ref(0);
const totalAll = ref(0);
const page = ref(1);
const sort = ref('top');
const loading = ref(false);
const mode = ref('open');

async function load(reset = true) {
  if (reset) { page.value = 1; comments.value = []; }
  loading.value = true;
  try {
    const r = await get(`/api/videos/${props.video.shortId}/comments?sort=${sort.value}&page=${page.value}&limit=20`);
    comments.value = reset ? r.comments : [...comments.value, ...r.comments];
    total.value = r.total; totalAll.value = r.totalAll; mode.value = r.commentsMode;
  } catch (e) { /* ignore */ } finally { loading.value = false; }
}
async function more() { page.value++; await load(false); }
async function submit(body) {
  const r = await post(`/api/videos/${props.video.shortId}/comments`, { body });
  if (r.held) ui.toast('Комментарий отправлен на проверку автору видео', { type: 'info' });
  comments.value.unshift(r.comment);
  if (!r.held) totalAll.value++;
}
function onDeleted(c) { comments.value = comments.value.filter((x) => x.id !== c.id); totalAll.value = Math.max(0, totalAll.value - 1); }
watch(sort, () => load(true));
watch(() => props.video.id, () => load(true));
onMounted(() => load(true));
</script>

<template>
  <section class="comments">
    <div class="row mb-16 wrap">
      <h3 class="comments-title">{{ fmtNumber(totalAll) }} комментариев</h3>
      <div class="row gap-4" style="margin-left: 8px">
        <button class="chip" :class="{ active: sort === 'top' }" @click="sort = 'top'"><Icon name="sort" :size="16" /> Популярные</button>
        <button class="chip" :class="{ active: sort === 'new' }" @click="sort = 'new'">Сначала новые</button>
      </div>
    </div>
    <div v-if="mode === 'disabled' && !video.viewer?.isOwner" class="alert info"><Icon name="info" :size="20" /> Комментарии к этому видео отключены.</div>
    <template v-else>
      <div v-if="auth.isActive" class="row" style="align-items: flex-start; margin-bottom: 24px">
        <ChannelAvatar :user="auth.user" />
        <CommentForm class="grow" placeholder="Оставьте комментарий…" @submit="submit" />
      </div>
      <div v-else-if="!auth.user" class="alert info mb-24"><Icon name="info" :size="20" /> <span><router-link :to="{ name: 'login', query: { next: $route.fullPath } }">Войдите</router-link>, чтобы оставлять комментарии.</span></div>
      <div v-if="mode === 'held' && auth.isActive && !video.viewer?.isOwner" class="small muted mb-16">Комментарии публикуются после проверки автором видео.</div>
    </template>
    <div class="col gap-16">
      <CommentItem v-for="c in comments" :key="c.id" :comment="c" :video="video" :highlight="highlight" @deleted="onDeleted" />
    </div>
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <div v-else-if="comments.length < total" class="row" style="justify-content: center; margin-top: 16px"><button class="btn sm" @click="more">Показать ещё</button></div>
  </section>
</template>

<style>
.comments-title { font-family: var(--font-body); text-transform: none; letter-spacing: 0; font-size: 18px; font-weight: 500; }
</style>
