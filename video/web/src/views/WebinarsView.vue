<script setup>
// Каталог вебинаров: ближайшие и прошедшие.
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../api.js';
import EmptyState from '../components/EmptyState.vue';
import { fmtDateTime, fmtNumber } from '../utils/format.js';
import { useAuth } from '../stores/auth.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const scope = ref(route.query.scope === 'past' ? 'past' : 'upcoming');
const list = ref([]);
const loading = ref(true);

async function load() {
  loading.value = true;
  try { list.value = (await get(`/api/webinars?scope=${scope.value}`)).webinars || []; }
  catch { list.value = []; } finally { loading.value = false; }
}
onMounted(load);
watch(scope, (s) => { router.replace({ query: { ...route.query, scope: s === 'past' ? 'past' : undefined } }); load(); });

function dayLabel(d) {
  if (!d) return 'Дата уточняется';
  const dt = new Date(d);
  return dt.toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div><h1><Icon name="campaign" :size="30" style="vertical-align:-4px" /> Вебинары</h1><div class="sub">Онлайн-встречи с регистрацией, программой и записью</div></div>
      <div class="actions">
        <div class="tabs">
          <button class="tab" :class="{ active: scope === 'upcoming' }" @click="scope = 'upcoming'">Ближайшие</button>
          <button class="tab" :class="{ active: scope === 'past' }" @click="scope = 'past'">Прошедшие</button>
        </div>
        <router-link v-if="auth.canStream" class="btn primary" to="/studio/webinars"><Icon name="plus" :size="16" /> Мои вебинары</router-link>
      </div>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!list.length" icon="campaign" :title="scope === 'past' ? 'Прошедших вебинаров нет' : 'Ближайших вебинаров нет'" text="Когда коллеги назначат вебинар, он появится здесь." />
    <div v-else class="web-grid">
      <router-link v-for="w in list" :key="w.id" class="web-card" :to="`/webinar/${w.slug}`">
        <div class="wc-cover" :style="w.coverUrl ? { backgroundImage: `url(${w.coverUrl})` } : null">
          <span v-if="w.status === 'live'" class="badge live"><Icon name="live" :size="12" /> Идёт сейчас</span>
          <span v-else-if="scope === 'past'" class="badge">Запись</span>
          <span v-else class="badge brand">{{ dayLabel(w.scheduledAt) }}</span>
        </div>
        <div class="wc-body">
          <h3 class="clamp-2">{{ w.title }}</h3>
          <div class="small muted clamp-2">{{ w.description }}</div>
          <div class="row wrap gap-8 mt-8 tiny muted">
            <span v-if="w.speakers?.length"><Icon name="person" :size="14" style="vertical-align:-3px" /> {{ w.speakers.map((s) => s.name).join(', ') }}</span>
            <span v-if="w.registrationCount"><Icon name="accountGroup" :size="14" style="vertical-align:-3px" /> записались: {{ fmtNumber(w.registrationCount) }}<span v-if="w.registrationLimit"> из {{ w.registrationLimit }}</span></span>
            <span v-if="w.autoWebinar"><Icon name="replay" :size="14" style="vertical-align:-3px" /> из записи</span>
          </div>
        </div>
      </router-link>
    </div>
  </div>
</template>

<style scoped>
.web-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
.web-card { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; background: var(--surface); color: inherit; text-decoration: none; transition: box-shadow .15s, transform .15s; }
.web-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
.wc-cover { aspect-ratio: 16/9; background: linear-gradient(135deg, var(--brand-700), var(--brand-400)); background-size: cover; background-position: center; display: flex; align-items: flex-end; padding: 10px; }
.wc-body { padding: 14px; }
.wc-body h3 { margin: 0 0 6px; font-family: var(--font-body); text-transform: none; font-size: 17px; letter-spacing: 0; }
</style>
