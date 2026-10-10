<script setup>
// Витрина — страница-подборка с обложкой и разделами (как Wistia Channels и Brightcove Gallery).
import { ref, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../api.js';
import VideoGrid from '../components/VideoGrid.vue';
import VideoCard from '../components/VideoCard.vue';
import EmptyState from '../components/EmptyState.vue';
import { useAuth } from '../stores/auth.js';

const route = useRoute();
const auth = useAuth();
const sc = ref(null);
const loading = ref(true);
const error = ref(null);

async function load() {
  loading.value = true; error.value = null;
  try {
    sc.value = (await get(`/api/showcases/${route.params.slug}`)).showcase;
    document.title = `${sc.value.title} — ${auth.siteName}`;
  } catch (e) { error.value = e; } finally { loading.value = false; }
}
onMounted(load);
watch(() => route.params.slug, load);
</script>

<template>
  <div class="page">
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="error" icon="alertCircle" title="Витрина недоступна" :text="error.message" />
    <template v-else-if="sc">
      <header class="hub-head" :class="{ 'with-cover': !!sc.coverUrl }" :style="sc.coverUrl ? { backgroundImage: `linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.78)), url(${sc.coverUrl})` } : null">
        <h1>{{ sc.title }}</h1>
        <div v-if="sc.subtitle" class="sub">{{ sc.subtitle }}</div>
        <p v-if="sc.description" class="desc">{{ sc.description }}</p>
      </header>

      <section v-for="s in sc.sections" :key="s.id" class="hub-section">
        <div class="row" style="align-items:baseline"><h2 class="grow">{{ s.title }}</h2></div>
        <p v-if="s.note" class="small muted" style="margin:0 0 12px">{{ s.note }}</p>
        <div v-if="s.layout === 'row'" class="hub-row"><VideoCard v-for="v in s.videos" :key="v.id" :video="v" /></div>
        <div v-else-if="s.layout === 'hero' && s.videos.length" class="hub-hero">
          <VideoCard :video="s.videos[0]" class="hero-main" />
          <div class="hero-rest"><VideoCard v-for="v in s.videos.slice(1, 5)" :key="v.id" :video="v" layout="list" /></div>
        </div>
        <VideoGrid v-else :videos="s.videos" empty-title="В разделе пока нет видео" />
        <div v-if="s.layout === 'row' && !s.videos.length" class="small muted">В разделе пока нет видео</div>
      </section>

      <EmptyState v-if="!sc.sections.length" icon="apps" title="Витрина пока пустая" text="Разделы ещё не настроены." />
    </template>
  </div>
</template>

<style scoped>
.hub-head { border-radius: var(--radius-lg); padding: 28px; margin-bottom: 24px; background: var(--surface-2); background-size: cover; background-position: center; }
.hub-head.with-cover { color: #fff; min-height: 220px; display: flex; flex-direction: column; justify-content: flex-end; }
.hub-head h1 { margin: 0; }
.hub-head .sub { font-size: 18px; opacity: .9; margin-top: 6px; }
.hub-head .desc { max-width: 760px; margin: 10px 0 0; opacity: .85; }
.hub-section { margin-bottom: 32px; }
.hub-row { display: flex; gap: 16px; overflow-x: auto; padding-bottom: 8px; }
.hub-row > * { flex: 0 0 280px; }
.hub-hero { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
.hero-rest { display: flex; flex-direction: column; gap: 12px; }
@media (max-width: 860px) { .hub-hero { grid-template-columns: 1fr; } .hub-row > * { flex-basis: 220px; } }
</style>
