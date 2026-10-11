<script setup>
// «Мои сертификаты»: список выданных сертификатов о прохождении.
import { ref, onMounted } from 'vue';
import { get } from '../api.js';
import EmptyState from '../components/EmptyState.vue';
import { fmtDate } from '../utils/format.js';
const list = ref([]);
const loading = ref(true);
onMounted(async () => { try { list.value = (await get('/api/me/certificates')).certificates; } finally { loading.value = false; } });
</script>

<template>
  <div class="page">
    <div class="page-head"><div><h1>Мои сертификаты</h1><div class="sub">Выдаются за выполнение обязательных просмотров с сертификатом</div></div></div>
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!list.length" icon="verified" title="Сертификатов пока нет" text="Сертификат появится здесь после выполнения назначения, для которого он предусмотрен." />
    <div v-else class="cert-grid">
      <router-link v-for="c in list" :key="c.id" :to="c.url" class="cert-card" :class="{ revoked: c.revokedAt }">
        <div class="cert-card-top"><Icon name="verified" :size="28" /><span class="mono small">{{ c.number }}</span></div>
        <div class="cert-card-title clamp-2">{{ c.title }}</div>
        <div class="tiny muted">Выдан {{ fmtDate(c.issuedAt) }}<span v-if="c.details?.quizPercent != null"> · тест {{ c.details.quizPercent }}%</span><span v-if="c.revokedAt" style="color: var(--danger)"> · отозван</span></div>
      </router-link>
    </div>
  </div>
</template>

<style>
.cert-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.cert-card { display: flex; flex-direction: column; gap: 8px; padding: 18px; border-radius: var(--radius); border: 2px solid var(--brand-100); background: var(--surface); color: var(--text); transition: border-color .15s, transform .15s; }
.cert-card:hover { border-color: var(--brand); transform: translateY(-2px); }
.cert-card.revoked { opacity: .6; }
.cert-card-top { display: flex; justify-content: space-between; align-items: center; color: var(--brand); }
.cert-card-title { font-weight: 500; font-size: 16px; }
</style>
