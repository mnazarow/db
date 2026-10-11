<script setup>
// Студия: календарь публикаций — что и когда выходит (контент-план, как в YouTube Studio).
import { ref, computed, onMounted, watch } from 'vue';
import { get } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import EmptyState from '../../components/EmptyState.vue';
import { fmtDateTime } from '../../utils/format.js';

const auth = useAuth();
const month = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const events = ref([]);
const loading = ref(true);
const scope = ref('mine');

const KINDS = {
  publish: { label: 'Публикация', icon: 'calendar', cls: 'k-publish' },
  premiere: { label: 'Премьера', icon: 'sparkles', cls: 'k-premiere' },
  live: { label: 'Эфир', icon: 'broadcast', cls: 'k-live' },
  assignment: { label: 'Срок просмотра', icon: 'assignment', cls: 'k-assign' },
  review: { label: 'Согласование', icon: 'checkAll', cls: 'k-review' },
};

const monthTitle = computed(() => month.value.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));
const days = computed(() => {
  const first = new Date(month.value);
  const start = new Date(first);
  const shift = (first.getDay() + 6) % 7;            // неделя с понедельника
  start.setDate(first.getDate() - shift);
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    const key = d.toISOString().slice(0, 10);
    return {
      date: d, key,
      inMonth: d.getMonth() === month.value.getMonth(),
      today: key === new Date().toISOString().slice(0, 10),
      items: events.value.filter((e) => String(e.at).slice(0, 10) === key),
    };
  });
});
const upcoming = computed(() => events.value.filter((e) => new Date(e.at) >= new Date()).slice(0, 8));

async function load() {
  loading.value = true;
  const from = new Date(month.value); from.setDate(from.getDate() - 7);
  const to = new Date(month.value); to.setMonth(to.getMonth() + 1); to.setDate(to.getDate() + 7);
  try {
    const r = await get(`/api/studio/calendar?from=${from.toISOString()}&to=${to.toISOString()}&scope=${scope.value}`);
    events.value = r.events;
  } catch { events.value = []; } finally { loading.value = false; }
}
onMounted(load);
watch([month, scope], load);
function shiftMonth(n) { month.value = new Date(month.value.getFullYear(), month.value.getMonth() + n, 1); }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>Календарь</h1>
        <p class="muted">Отложенные публикации, премьеры, запланированные эфиры, сроки обязательных просмотров и согласований.</p>
      </div>
      <div class="row gap-8" style="align-items:center">
        <select v-if="auth.isStaff" class="select sm" v-model="scope"><option value="mine">Только моё</option><option value="all">Весь портал</option></select>
        <button class="ibtn soft-round" @click="shiftMonth(-1)" aria-label="Предыдущий месяц"><Icon name="chevronLeft" /></button>
        <b style="min-width: 160px; text-align:center; text-transform: capitalize">{{ monthTitle }}</b>
        <button class="ibtn soft-round" @click="shiftMonth(1)" aria-label="Следующий месяц"><Icon name="chevronRight" /></button>
      </div>
    </div>

    <div class="panel cal">
      <div class="cal-grid cal-head">
        <div v-for="d in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']" :key="d" class="cal-dow">{{ d }}</div>
      </div>
      <div class="cal-grid">
        <div v-for="d in days" :key="d.key" class="cal-day" :class="{ out: !d.inMonth, today: d.today }">
          <div class="cal-num">{{ d.date.getDate() }}</div>
          <router-link v-for="(e, i) in d.items.slice(0, 3)" :key="i" class="cal-ev" :class="KINDS[e.kind]?.cls" :to="e.url" :title="`${KINDS[e.kind]?.label}: ${e.title}`">
            <Icon :name="KINDS[e.kind]?.icon" :size="12" /><span class="ellipsis">{{ e.title }}</span>
          </router-link>
          <span v-if="d.items.length > 3" class="tiny muted">ещё {{ d.items.length - 3 }}</span>
        </div>
      </div>
    </div>

    <div class="mt-16">
      <h3 class="mb-8">Ближайшее</h3>
      <EmptyState v-if="!loading && !upcoming.length" icon="calendar" title="Ничего не запланировано" text="Отложенная публикация, премьера или эфир появятся здесь автоматически." />
      <div v-else class="col gap-4">
        <router-link v-for="(e, i) in upcoming" :key="i" class="panel row gap-8 cal-up" :to="e.url">
          <span class="badge" :class="KINDS[e.kind]?.cls"><Icon :name="KINDS[e.kind]?.icon" :size="12" /> {{ KINDS[e.kind]?.label }}</span>
          <b class="grow ellipsis">{{ e.title }}</b>
          <span class="tiny muted nowrap">{{ fmtDateTime(e.at) }}</span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<style>
.cal { padding: 8px; }
.cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.cal-head { margin-bottom: 4px; }
.cal-dow { text-align: center; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); padding: 4px 0; }
.cal-day { min-height: 92px; min-width: 0; overflow: hidden; border: 1px solid var(--line-2); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px; }
.cal-day.out { opacity: .45; }
.cal-day.today { border-color: var(--brand); box-shadow: inset 0 0 0 1px var(--brand-200); }
.cal-num { font-size: 12px; color: var(--text-3); text-align: right; }
.cal-ev { display: flex; gap: 4px; align-items: center; min-width: 0; font-size: 11px; padding: 2px 4px; border-radius: 4px; color: #fff; overflow: hidden; }
.cal-ev.k-publish { background: var(--brand); }
.cal-ev.k-premiere { background: #7B4FB5; }
.cal-ev.k-live { background: var(--danger); }
.cal-ev.k-assign { background: #B5761F; }
.cal-ev.k-review { background: #1E8E4E; }
.badge.k-publish { background: var(--brand-100); color: var(--brand); }
.badge.k-premiere { background: rgba(123,79,181,.14); color: #7B4FB5; }
.badge.k-live { background: rgba(192,57,43,.12); color: var(--danger); }
.badge.k-assign { background: rgba(181,118,31,.14); color: #B5761F; }
.badge.k-review { background: rgba(30,142,78,.12); color: #1E8E4E; }
.cal-up { padding: 8px 12px; align-items: center; }
@media (max-width: 767px) { .cal-day { min-height: 64px; } .cal-ev span { display: none; } }
</style>
