<script setup>
// Каталог учебных программ: курсы, доступные сотруднику, с прогрессом прохождения.
import { ref, computed, onMounted, watch } from 'vue';
import { get } from '../api.js';
import { useAuth } from '../stores/auth.js';
import EmptyState from '../components/EmptyState.vue';
import { fmtDurationLong, plural } from '../utils/format.js';

const auth = useAuth();
const courses = ref([]);
const loading = ref(true);
const filter = ref('all');
const q = ref('');

async function load() {
  loading.value = true;
  try {
    const r = await get(`/api/courses?${filter.value === 'mine' ? 'mine=1&' : ''}${q.value ? `q=${encodeURIComponent(q.value)}` : ''}`);
    courses.value = r.courses;
  } catch { courses.value = []; } finally { loading.value = false; }
}
onMounted(load);
watch(filter, load);
let t = null;
watch(q, () => { clearTimeout(t); t = setTimeout(load, 300); });

const shown = computed(() => courses.value);
function stepsLabel(c) { return `${c.itemCount} ${plural(c.itemCount, 'шаг', 'шага', 'шагов')}`; }
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div><h1>Курсы</h1><div class="sub">Учебные программы: видео, материалы и проверка знаний по шагам</div></div>
      <div class="row gap-8 wrap">
        <div class="chips">
          <button class="chip" :class="{ active: filter === 'all' }" @click="filter = 'all'">Все</button>
          <button v-if="auth.user" class="chip" :class="{ active: filter === 'mine' }" @click="filter = 'mine'">Мои</button>
        </div>
        <input v-model="q" class="input sm" style="max-width:220px" placeholder="Поиск курса" aria-label="Поиск курса" />
      </div>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!shown.length" icon="school" title="Курсов пока нет"
      :text="filter === 'mine' ? 'Здесь появятся курсы, на которые вы записались.' : 'Учебные программы появятся здесь, как только их опубликуют.'" />
    <div v-else class="course-grid">
      <router-link v-for="c in shown" :key="c.id" class="course-card panel" :to="`/course/${c.slug || c.id}`">
        <div class="course-cover" :style="c.coverUrl ? { backgroundImage: `url(${c.coverUrl})` } : {}">
          <Icon v-if="!c.coverUrl" name="school" :size="34" />
          <span v-if="c.progress?.completed" class="badge success course-flag">Пройден</span>
        </div>
        <div class="course-body">
          <div class="course-title clamp-2">{{ c.title }}</div>
          <div class="tiny muted">{{ stepsLabel(c) }}<span v-if="c.durationSec"> · {{ fmtDurationLong(c.durationSec) }}</span><span v-if="c.enrolledCount"> · {{ c.enrolledCount }} уч.</span></div>
          <p v-if="c.description" class="small muted clamp-2" style="margin:6px 0 0">{{ c.description }}</p>
          <div v-if="c.progress?.enrolled" class="course-progress" :title="`Пройдено ${c.progress.percent}%`">
            <i :style="{ width: c.progress.percent + '%' }"></i>
          </div>
        </div>
      </router-link>
    </div>
  </div>
</template>

<style scoped>
.course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.course-card { padding: 0; overflow: hidden; display: flex; flex-direction: column; color: inherit; text-decoration: none; transition: transform .12s ease; }
.course-card:hover { transform: translateY(-2px); }
.course-cover { position: relative; aspect-ratio: 16/9; background: var(--surface-2) center/cover no-repeat; display: flex; align-items: center; justify-content: center; color: var(--muted); }
.course-flag { position: absolute; top: 8px; right: 8px; }
.course-body { padding: 12px 14px 14px; }
.course-title { font-weight: 600; margin-bottom: 4px; }
.course-progress { margin-top: 10px; height: 6px; border-radius: 3px; background: var(--surface-2); overflow: hidden; }
.course-progress i { display: block; height: 100%; background: var(--brand); }
</style>
