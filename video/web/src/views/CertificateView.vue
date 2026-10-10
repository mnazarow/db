<script setup>
// Сертификат о прохождении: страница для печати/сохранения в PDF и проверки подлинности по ссылке.
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../api.js';
import { useAuth } from '../stores/auth.js';
import BrandLogo from '../components/BrandLogo.vue';
import { fmtDate, fmtDurationLong } from '../utils/format.js';

const route = useRoute();
const auth = useAuth();
const cert = ref(null);
const error = ref('');
onMounted(async () => {
  try { cert.value = (await get(`/api/certificates/${route.params.id}`)).certificate; document.title = `Сертификат ${cert.value.number} — ${auth.siteName}`; } catch (e) { error.value = e.message; }
});
function print() { window.print(); }
</script>

<template>
  <div class="cert-page">
    <div v-if="error" class="page narrow"><div class="panel" style="text-align:center"><Icon name="alertCircle" :size="40" class="muted" /><h2 style="font-size:22px">Сертификат не найден</h2><p class="muted">{{ error }}</p><router-link to="/" class="btn">На главную</router-link></div></div>
    <template v-else-if="cert">
      <div class="cert-actions no-print">
        <router-link to="/certificates" class="btn ghost sm" v-if="auth.user"><Icon name="back" :size="18" /> Мои сертификаты</router-link>
        <span class="grow"></span>
        <span v-if="!cert.valid" class="badge danger">Отозван</span>
        <span v-else class="badge success"><Icon name="verified" :size="12" /> Подлинный · выдан {{ fmtDate(cert.issuedAt) }}</span>
        <button class="btn primary sm" @click="print"><Icon name="download" :size="16" /> Сохранить в PDF / печать</button>
      </div>
      <div class="cert" :class="{ revoked: !cert.valid }">
        <div class="cert-border">
          <div class="cert-head"><BrandLogo :height="34" /><div class="cert-site">{{ cert.details.siteName || auth.siteName }}</div></div>
          <div class="cert-title">Сертификат</div>
          <div class="cert-sub">о прохождении обучения</div>
          <div class="cert-text">Настоящим подтверждается, что</div>
          <div class="cert-name">{{ cert.user?.displayName }}</div>
          <div class="cert-text">{{ cert.details.text || 'успешно прошёл(ла) обучение' }}</div>
          <div class="cert-course">«{{ cert.title }}»</div>
          <div class="cert-meta">
            <span v-if="cert.details.videos?.length > 1">{{ cert.details.videos.length }} видео</span>
            <span v-if="cert.details.durationSec">общая длительность {{ fmtDurationLong(cert.details.durationSec) }}</span>
            <span v-if="cert.details.quizPercent != null">тест сдан на {{ cert.details.quizPercent }}%</span>
            <span v-else-if="cert.details.requireQuiz">с проверкой знаний</span>
          </div>
          <div class="cert-foot">
            <div><div class="cert-num">№ {{ cert.number }}</div><div class="tiny muted">Выдан {{ fmtDate(cert.issuedAt) }}</div></div>
            <div class="cert-sign"><div class="cert-line"></div><div class="small">{{ cert.details.issuerName }}</div><div class="tiny muted">{{ cert.details.issuerTitle }}</div></div>
          </div>
          <div class="cert-verify tiny muted">Проверить подлинность: {{ auth.config?.baseUrl || '' }}{{ cert.url }}</div>
          <div v-if="!cert.valid" class="cert-stamp">ОТОЗВАН</div>
        </div>
      </div>
      <div v-if="cert.details.videos?.length" class="no-print page narrow" style="padding-top: 8px"><div class="small muted">Содержание: <span v-for="(v, i) in cert.details.videos" :key="v.id">{{ i ? ' · ' : '' }}{{ v.title }}</span></div></div>
    </template>
    <div v-else class="loading-block"><div class="spin"></div></div>
  </div>
</template>

<style>
.cert-page { max-width: 1000px; margin: 0 auto; padding: 24px var(--gutter) 48px; }
.cert-actions { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; }
.cert { background: #fff; color: #1E2A3A; border-radius: var(--radius); box-shadow: var(--shadow); padding: 18px; aspect-ratio: 1.414; display: flex; }
.cert-border { flex: 1; border: 3px double var(--brand, #0F4382); padding: 36px 48px; display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; background: radial-gradient(circle at 50% 0%, #f5f8fc, #fff 60%); }
.cert-head { width: 100%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.cert-site { font-family: var(--font-display); font-size: 20px; letter-spacing: .06em; text-transform: uppercase; color: var(--brand); }
.cert-title { font-family: var(--font-display); font-size: 64px; letter-spacing: .12em; text-transform: uppercase; color: var(--brand); line-height: 1; }
.cert-sub { font-size: 16px; color: #5A6B7F; letter-spacing: .08em; text-transform: uppercase; margin: 8px 0 28px; }
.cert-text { font-size: 15px; color: #5A6B7F; }
.cert-name { font-family: var(--font-display); font-size: 44px; color: #1E2A3A; margin: 8px 0; line-height: 1.1; }
.cert-course { font-size: 22px; font-weight: 500; margin: 10px 0 12px; max-width: 80%; }
.cert-meta { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; font-size: 13px; color: #5A6B7F; }
.cert-foot { margin-top: auto; width: 100%; display: flex; justify-content: space-between; align-items: flex-end; padding-top: 24px; }
.cert-num { font-family: var(--font-mono, ui-monospace, monospace); font-size: 16px; font-weight: 600; color: var(--brand); text-align: left; }
.cert-sign { text-align: center; min-width: 220px; }
.cert-line { border-top: 1px solid #1E2A3A; margin-bottom: 4px; }
.cert-verify { margin-top: 12px; }
.cert.revoked .cert-border { filter: grayscale(1); }
.cert-stamp { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-family: var(--font-display); font-size: 72px; color: rgba(208,0,36,.35); border: 6px solid rgba(208,0,36,.35); padding: 4px 24px; letter-spacing: .2em; }
@media print {
  @page { size: A4 landscape; margin: 10mm; }
  body, .app-main, .cert-page { background: #fff !important; padding: 0 !important; margin: 0 !important; }
  .app-header, .app-sidebar, .no-print, .toasts { display: none !important; }
  .cert { box-shadow: none; border-radius: 0; aspect-ratio: auto; height: 190mm; }
}
@media (max-width: 720px) { .cert-border { padding: 20px 16px; } .cert-title { font-size: 40px; } .cert-name { font-size: 30px; } .cert { aspect-ratio: auto; } }
</style>
