<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { get } from '../api.js';
import { useAuth } from '../stores/auth.js';
import * as ws from '../ws.js';
import LiveCard from '../components/LiveCard.vue';
import EmptyState from '../components/EmptyState.vue';

const auth = useAuth();
const data = ref({ live: [], upcoming: [], recent: [] });
const loading = ref(true);
let off = [];
async function load() { try { data.value = await get('/api/live'); } finally { loading.value = false; } }
onMounted(() => { load(); off.push(ws.on('live.started', load), ws.on('live.ended', load)); });
onBeforeUnmount(() => off.forEach((f) => f()));
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div><h1>Трансляции</h1><div class="sub">Прямые эфиры, анонсы и записи прошедших трансляций</div></div>
      <div class="actions"><router-link v-if="auth.canStream && auth.config?.liveEnabled" to="/studio/live" class="btn primary"><Icon name="broadcast" :size="18" /> Начать эфир</router-link></div>
    </div>
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <template v-else>
      <section class="home-section">
        <div class="section-title"><h2><span class="badge live" style="vertical-align: middle; margin-right: 8px">●</span> Сейчас в эфире</h2></div>
        <div v-if="data.live.length" class="video-grid"><LiveCard v-for="s in data.live" :key="s.id" :stream="s" /></div>
        <EmptyState v-else icon="live" title="Сейчас никто не вещает" text="Когда коллеги выйдут в эфир, трансляции появятся здесь." />
      </section>
      <section v-if="data.upcoming.length" class="home-section">
        <div class="section-title"><h2>Запланированные</h2></div>
        <div class="video-grid"><LiveCard v-for="s in data.upcoming" :key="s.id" :stream="s" /></div>
      </section>
      <section v-if="data.recent.length" class="home-section">
        <div class="section-title"><h2>Записи эфиров</h2></div>
        <div class="video-grid"><LiveCard v-for="s in data.recent" :key="s.id" :stream="s" /></div>
      </section>
    </template>
  </div>
</template>
