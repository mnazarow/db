<script setup>
// «Назначено вам»: обязательные к просмотру видео и плейлисты с прогрессом и сроками.
import { ref, computed, onMounted } from 'vue';
import { get } from '../api.js';
import { useAuth } from '../stores/auth.js';
import EmptyState from '../components/EmptyState.vue';
import { fmtDuration, fmtDate } from '../utils/format.js';

const auth = useAuth();
const items = ref([]);
const loading = ref(true);
const filter = ref('pending');

async function load() {
  loading.value = true;
  try { const r = await get('/api/assignments/mine'); items.value = r.assignments; auth.assignmentsPending = r.pending; } finally { loading.value = false; }
}
onMounted(load);
const shown = computed(() => items.value.filter((a) => filter.value === 'all' ? true : filter.value === 'done' ? a.completed : !a.completed));
const pendingCount = computed(() => items.value.filter((a) => !a.completed).length);
function dueInfo(a) {
  if (!a.dueAt) return { label: 'Без срока', cls: '' };
  const d = new Date(a.dueAt); const days = Math.ceil((d - Date.now()) / 86400000);
  if (a.completed) return { label: `до ${fmtDate(d)}`, cls: '' };
  if (days < 0) return { label: `Просрочено — ${fmtDate(d)}`, cls: 'overdue' };
  if (days === 0) return { label: 'Срок — сегодня', cls: 'soon' };
  if (days <= 3) return { label: `Осталось ${days} дн. — до ${fmtDate(d)}`, cls: 'soon' };
  return { label: `До ${fmtDate(d)}`, cls: '' };
}
function link(a) {
  if (a.kind === 'playlist') return `/playlist/${a.playlistId}`;
  const next = a.items.find((i) => !i.completed) || a.items[0];
  return next ? `/watch/${next.shortId}?src=library` : '#';
}
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Назначено вам</h1><div class="sub">Видео и курсы, которые нужно посмотреть<span v-if="pendingCount"> — осталось {{ pendingCount }}</span></div></div>
      <div class="chips"><button v-for="[k, l] in [['pending', 'К просмотру'], ['done', 'Выполнено'], ['all', 'Все']]" :key="k" class="chip" :class="{ active: filter === k }" @click="filter = k">{{ l }}</button></div>
    </div>
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!shown.length" icon="assignment" :title="filter === 'pending' ? 'Всё просмотрено' : 'Пока ничего нет'" :text="filter === 'pending' ? 'Новые назначения появятся здесь и в уведомлениях.' : 'Назначенные к просмотру материалы появятся здесь.'" />
    <div v-else class="col gap-16">
      <div v-for="a in shown" :key="a.id" class="panel asg" :class="{ done: a.completed }">
        <div class="asg-head">
          <router-link :to="link(a)" class="asg-thumb"><img v-if="a.thumbnailUrl" :src="a.thumbnailUrl" alt="" /><Icon v-else :name="a.kind === 'playlist' ? 'playlist' : 'movie'" :size="28" /><span v-if="a.completed" class="asg-done"><Icon name="done" :size="28" /></span></router-link>
          <div class="grow" style="min-width:0">
            <div class="row wrap gap-8"><router-link :to="link(a)" class="asg-title clamp-2">{{ a.title }}</router-link><span v-if="a.kind === 'playlist'" class="badge brand">Плейлист · {{ a.items.length }} видео</span></div>
            <div class="row wrap gap-12 small mt-4"><span class="due" :class="dueInfo(a).cls"><Icon :name="a.completed ? 'done' : 'clock'" :size="14" /> {{ a.completed ? 'Выполнено' : dueInfo(a).label }}</span><span class="muted">Назначил: {{ a.creatorName }}</span><span v-if="a.requireQuiz" class="muted"><Icon name="quiz" :size="14" style="vertical-align:-3px" /> нужно сдать тест</span></div>
            <p v-if="a.note" class="small mt-8" style="margin:0">{{ a.note }}</p>
            <div class="row gap-12 mt-8" style="align-items:center"><div class="meter grow"><i :class="a.completed ? 'ok' : dueInfo(a).cls === 'overdue' ? 'bad' : ''" :style="{ width: a.percent + '%' }"></i></div><span class="small nowrap">{{ a.percent }}% · засчитывается от {{ a.requiredPercent }}%</span></div>
          </div>
          <router-link :to="link(a)" class="btn" :class="{ primary: !a.completed }"><Icon name="play" :size="18" /> {{ a.completed ? 'Пересмотреть' : a.percent ? 'Продолжить' : 'Смотреть' }}</router-link>
        </div>
        <div v-if="a.kind === 'playlist' && a.items.length" class="asg-items">
          <router-link v-for="it in a.items" :key="it.id" :to="`/watch/${it.shortId}?list=${a.playlistId}`" class="asg-item"><Icon :name="it.completed ? 'doneOutline' : 'playCircle'" :size="18" :class="{ ok: it.completed }" /><span class="grow ellipsis">{{ it.title }}</span><span class="tiny muted">{{ fmtDuration(it.duration) }}</span><span class="tiny" style="width: 40px; text-align:right">{{ it.percent }}%</span></router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.asg-head { display: flex; gap: 16px; align-items: center; }
.asg.done { opacity: 0.85; }
.asg-thumb { position: relative; width: 200px; aspect-ratio: 16/9; border-radius: var(--radius-sm); background: var(--bg-3); overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: var(--text-3); }
.asg-thumb img { width: 100%; height: 100%; object-fit: cover; }
.asg-done { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(30,142,90,.55); color: #fff; }
.asg-title { color: var(--text); font-weight: 500; font-size: 17px; }
.asg-items { display: flex; flex-direction: column; gap: 2px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line-2); }
.asg-item { display: flex; gap: 10px; align-items: center; padding: 6px 8px; border-radius: var(--radius-sm); color: var(--text); font-size: 14px; }
.asg-item:hover { background: var(--bg-2); }
.asg-item .icon.ok { color: var(--success); }
@media (max-width: 720px) { .asg-head { flex-wrap: wrap; } .asg-thumb { width: 100%; } }
</style>
