<script setup>
// Опросы и вопросы спикеру в трансляции: зрителю — голосование и вопросы; ведущему — создание и управление.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { get, post, patch, del } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import * as ws from '../ws.js';
import ChannelAvatar from './ChannelAvatar.vue';

const props = defineProps({ stream: { type: Object, required: true }, manage: Boolean });
const auth = useAuth();
const ui = useUi();
const tab = ref('qa');
const polls = ref([]);
const questions = ref([]);
const qText = ref('');
const qAnon = ref(false);
const newPoll = ref({ question: '', options: ['', ''], multiple: false });
const showNewPoll = ref(false);
const answering = ref(null); // { id, text }
let off = [];

const sid = computed(() => props.stream.shortId);
const canManage = computed(() => props.manage || (auth.user && (auth.user.id === props.stream.owner?.id || auth.isStaff)));
const openPolls = computed(() => polls.value.filter((p) => p.status === 'open'));
const visibleQuestions = computed(() => questions.value.filter((q) => q.status !== 'hidden' || canManage.value || q.mine));
const newCount = computed(() => questions.value.filter((q) => q.status === 'new').length);

async function load() {
  try { const [p, q] = await Promise.all([get(`/api/live/${sid.value}/polls`), get(`/api/live/${sid.value}/questions`)]); polls.value = p.polls; questions.value = q.questions; } catch { /* ignore */ }
}
onMounted(() => {
  load();
  off.push(
    ws.on('poll', (m) => { if (m.streamId !== props.stream.id) return; const i = polls.value.findIndex((p) => p.id === m.poll.id); const mine = i >= 0 ? polls.value[i].myVote : null; const merged = { ...m.poll, myVote: mine }; if (i >= 0) polls.value.splice(i, 1, merged); else { polls.value.unshift(merged); if (m.poll.status === 'open' && !canManage.value) { tab.value = 'polls'; ui.toast('Новый опрос от ведущего'); } } }),
    ws.on('poll_delete', (m) => { if (m.streamId === props.stream.id) polls.value = polls.value.filter((p) => p.id !== m.pollId); }),
    ws.on('question', (m) => { if (m.streamId !== props.stream.id) return; const i = questions.value.findIndex((q) => q.id === m.question.id); const keep = i >= 0 ? { mine: questions.value[i].mine, voted: questions.value[i].voted } : {}; const merged = { ...m.question, ...keep }; if (i >= 0) questions.value.splice(i, 1, merged); else questions.value.push(merged); sortQ(); }),
    ws.on('question_votes', (m) => { if (m.streamId !== props.stream.id) return; const q = questions.value.find((x) => x.id === m.questionId); if (q) q.upvotes = m.upvotes; sortQ(); }),
  );
});
onBeforeUnmount(() => off.forEach((f) => f()));
function sortQ() { questions.value.sort((a, b) => (a.status === 'answered') - (b.status === 'answered') || b.upvotes - a.upvotes || new Date(b.createdAt) - new Date(a.createdAt)); }

async function vote(p, oid) {
  if (!auth.isActive) return ui.toast('Войдите, чтобы голосовать');
  let ids = p.multiple ? [...(p.myVote || [])] : [oid];
  if (p.multiple) ids = ids.includes(oid) ? ids.filter((x) => x !== oid) : [...ids, oid];
  if (!ids.length) return;
  try { const r = await post(`/api/live/${sid.value}/polls/${p.id}/vote`, { optionIds: ids }); const i = polls.value.findIndex((x) => x.id === p.id); if (i >= 0) polls.value.splice(i, 1, r.poll); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function ask() {
  const body = qText.value.trim(); if (body.length < 3) return;
  try { await post(`/api/live/${sid.value}/questions`, { body, anonymous: qAnon.value }); qText.value = ''; ui.toast('Вопрос отправлен ведущему', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function upvote(q) {
  if (!auth.isActive) return ui.toast('Войдите, чтобы голосовать');
  try { const r = await post(`/api/live/${sid.value}/questions/${q.id}/upvote`, {}); q.upvotes = r.upvotes; q.voted = r.voted; sortQ(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function setStatus(q, status, answer) {
  try { const r = await patch(`/api/live/${sid.value}/questions/${q.id}`, { status, ...(answer !== undefined ? { answer } : {}) }); Object.assign(q, r.question); answering.value = null; sortQ(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function createPoll() {
  const options = newPoll.value.options.map((o) => o.trim()).filter(Boolean);
  if (!newPoll.value.question.trim() || options.length < 2) return ui.toast('Нужен вопрос и минимум два варианта', { type: 'error' });
  try { const r = await post(`/api/live/${sid.value}/polls`, { question: newPoll.value.question, options, multiple: newPoll.value.multiple }); polls.value.unshift(r.poll); newPoll.value = { question: '', options: ['', ''], multiple: false }; showNewPoll.value = false; tab.value = 'polls'; } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function setPoll(p, body) { try { const r = await patch(`/api/live/${sid.value}/polls/${p.id}`, body); const i = polls.value.findIndex((x) => x.id === p.id); if (i >= 0) polls.value.splice(i, 1, r.poll); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function removePoll(p) { if (!(await ui.ask({ title: 'Удалить опрос?', okLabel: 'Удалить', danger: true }))) return; await del(`/api/live/${sid.value}/polls/${p.id}`); polls.value = polls.value.filter((x) => x.id !== p.id); }
</script>

<template>
  <div class="li-panel">
    <div class="tabs li-tabs">
      <button class="tab" :class="{ active: tab === 'qa' }" @click="tab = 'qa'">Вопросы<span v-if="newCount" class="badge brand" style="margin-left:6px">{{ newCount }}</span></button>
      <button class="tab" :class="{ active: tab === 'polls' }" @click="tab = 'polls'">Опросы<span v-if="openPolls.length" class="badge live" style="margin-left:6px">{{ openPolls.length }}</span></button>
    </div>

    <!-- Вопросы -->
    <div v-if="tab === 'qa'" class="li-body">
      <div v-if="!stream.qaEnabled && !canManage" class="muted small">Ведущий отключил вопросы.</div>
      <template v-else>
        <div v-if="auth.isActive && stream.qaEnabled" class="li-ask">
          <textarea class="textarea sm" v-model="qText" rows="2" maxlength="1000" placeholder="Задайте вопрос ведущему" @keydown.enter.ctrl="ask"></textarea>
          <div class="row" style="justify-content: space-between"><label class="check small"><input type="checkbox" v-model="qAnon" /> Анонимно</label><button class="btn primary sm" :disabled="qText.trim().length < 3" @click="ask"><Icon name="send" :size="16" /> Спросить</button></div>
        </div>
        <div v-if="!visibleQuestions.length" class="muted small">Вопросов пока нет. Популярные вопросы поднимаются выше — голосуйте за важные.</div>
        <div v-for="q in visibleQuestions" :key="q.id" class="li-q" :class="{ answered: q.status === 'answered', hidden: q.status === 'hidden' }">
          <button class="li-vote" :class="{ on: q.voted }" :title="q.voted ? 'Убрать голос' : 'Тоже интересует'" @click="upvote(q)"><Icon name="chevronUp" :size="18" /><span>{{ q.upvotes }}</span></button>
          <div class="grow" style="min-width:0">
            <div class="row gap-6 tiny muted"><ChannelAvatar v-if="!q.author.anonymous" :user="q.author" size="xs" /><span>{{ q.author.displayName }}</span><span v-if="q.status === 'answered'" class="badge success">отвечен</span><span v-if="q.status === 'hidden'" class="badge">скрыт</span></div>
            <div class="small" style="white-space: pre-wrap">{{ q.body }}</div>
            <div v-if="q.answer" class="li-answer small"><b>Ответ:</b> {{ q.answer }}</div>
            <div v-if="canManage" class="row gap-4 mt-4">
              <button v-if="q.status !== 'answered'" class="btn ghost sm" @click="answering = { id: q.id, text: '' }">Ответить</button>
              <button v-if="q.status !== 'answered'" class="btn ghost sm" @click="setStatus(q, 'answered')">Отвечено</button>
              <button v-if="q.status !== 'hidden'" class="btn ghost sm" @click="setStatus(q, 'hidden')">Скрыть</button>
              <button v-else class="btn ghost sm" @click="setStatus(q, 'new')">Вернуть</button>
            </div>
            <div v-else-if="q.mine && q.status !== 'answered'" class="row mt-4"><button class="btn ghost sm" @click="setStatus(q, 'hidden')">Удалить вопрос</button></div>
            <div v-if="answering && answering.id === q.id" class="col gap-4 mt-4"><textarea class="textarea sm" v-model="answering.text" rows="2" placeholder="Текст ответа (будет виден всем)"></textarea><div class="row gap-4"><button class="btn primary sm" @click="setStatus(q, 'answered', answering.text)">Сохранить ответ</button><button class="btn ghost sm" @click="answering = null">Отмена</button></div></div>
          </div>
        </div>
      </template>
    </div>

    <!-- Опросы -->
    <div v-else class="li-body">
      <div v-if="canManage" class="mb-8">
        <button v-if="!showNewPoll" class="btn primary sm" @click="showNewPoll = true"><Icon name="plus" :size="16" /> Новый опрос</button>
        <div v-else class="panel soft col gap-8" style="padding: 10px">
          <input class="input sm" v-model="newPoll.question" placeholder="Вопрос" maxlength="300" />
          <div v-for="(o, i) in newPoll.options" :key="i" class="row gap-4"><input class="input sm grow" v-model="newPoll.options[i]" :placeholder="`Вариант ${i + 1}`" maxlength="120" /><button v-if="newPoll.options.length > 2" class="ibtn sm" @click="newPoll.options.splice(i, 1)"><Icon name="close" :size="14" /></button></div>
          <div class="row wrap gap-8"><button class="btn ghost sm" :disabled="newPoll.options.length >= 10" @click="newPoll.options.push('')"><Icon name="plus" :size="14" /> Вариант</button><label class="check small"><input type="checkbox" v-model="newPoll.multiple" /> Несколько ответов</label><span class="grow"></span><button class="btn ghost sm" @click="showNewPoll = false">Отмена</button><button class="btn primary sm" @click="createPoll">Запустить</button></div>
        </div>
      </div>
      <div v-if="!polls.length" class="muted small">Опросов пока нет.</div>
      <div v-for="p in polls" :key="p.id" class="li-poll" :class="{ closed: p.status === 'closed' }">
        <div class="row gap-6" style="align-items:flex-start"><b class="grow small" style="font-size: 14px">{{ p.question }}</b><span class="badge" :class="p.status === 'open' ? 'live' : ''">{{ { open: 'идёт', closed: 'завершён', draft: 'черновик' }[p.status] }}</span></div>
        <div class="col gap-4 mt-8">
          <button v-for="o in p.options" :key="o.id" class="li-opt" :class="{ on: (p.myVote || []).includes(o.id), disabled: p.status !== 'open' }" :disabled="p.status !== 'open'" @click="vote(p, o.id)">
            <span class="li-bar" v-if="o.percent !== undefined" :style="{ width: o.percent + '%' }"></span>
            <span class="li-opt-text"><Icon v-if="(p.myVote || []).includes(o.id)" name="check" :size="14" /> {{ o.text }}</span>
            <span v-if="o.percent !== undefined" class="li-pct">{{ o.percent }}%<span class="tiny muted"> · {{ o.votes }}</span></span>
          </button>
        </div>
        <div class="row wrap gap-6 mt-6 tiny muted" style="align-items:center">
          <span v-if="p.total !== undefined">Голосов: {{ p.total }}</span><span v-else>Результаты покажет ведущий</span><span v-if="p.multiple">· можно выбрать несколько</span>
          <template v-if="canManage"><span class="grow"></span>
            <button v-if="p.status === 'open'" class="btn ghost sm" @click="setPoll(p, { status: 'closed' })">Завершить</button>
            <button v-else-if="p.status !== 'open'" class="btn ghost sm" @click="setPoll(p, { status: 'open' })">{{ p.status === 'draft' ? 'Запустить' : 'Открыть снова' }}</button>
            <button class="btn ghost sm" @click="setPoll(p, { showResults: !p.showResults })">{{ p.showResults ? 'Скрыть результаты' : 'Показать результаты' }}</button>
            <button class="ibtn sm" title="Удалить" @click="removePoll(p)" aria-label="Удалить"><Icon name="delete" :size="14" /></button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.li-panel { display: flex; flex-direction: column; min-height: 0; height: 100%; }
.li-tabs { flex-shrink: 0; margin-bottom: 8px; }
.li-body { overflow: auto; min-height: 0; display: flex; flex-direction: column; gap: 8px; padding-right: 4px; }
.li-ask { display: flex; flex-direction: column; gap: 6px; padding-bottom: 8px; border-bottom: 1px solid var(--line-2); }
.li-q { display: flex; gap: 10px; padding: 8px; border-radius: var(--radius-sm); background: var(--bg-2); }
.li-q.answered { opacity: .8; } .li-q.hidden { opacity: .5; }
.li-vote { display: flex; flex-direction: column; align-items: center; min-width: 40px; padding: 4px 2px; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--text-2); font-size: 13px; font-weight: 500; cursor: pointer; flex-shrink: 0; height: fit-content; }
.li-vote.on { background: var(--brand-100); color: var(--brand); border-color: var(--brand-300); }
.li-answer { margin-top: 4px; padding: 6px 8px; border-left: 3px solid var(--success); background: var(--surface); border-radius: 0 6px 6px 0; }
.li-poll { padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--line-2); background: var(--surface); }
.li-poll.closed { background: var(--bg-2); }
.li-opt { position: relative; overflow: hidden; display: flex; justify-content: space-between; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-size: 13px; text-align: left; cursor: pointer; }
.li-opt.on { border-color: var(--brand); }
.li-opt.disabled { cursor: default; }
.li-bar { position: absolute; left: 0; top: 0; bottom: 0; background: var(--brand-100); transition: width .4s; z-index: 0; }
.li-opt-text, .li-pct { position: relative; z-index: 1; }
.li-pct { font-weight: 500; white-space: nowrap; }
</style>
