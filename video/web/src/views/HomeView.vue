<script setup>
import { ref, onMounted, watch } from 'vue';
import { get } from '../api.js';
import { useAuth } from '../stores/auth.js';
import VideoCard from '../components/VideoCard.vue';
import LiveCard from '../components/LiveCard.vue';
import EmptyState from '../components/EmptyState.vue';
import BrandLogo from '../components/BrandLogo.vue';

const auth = useAuth();
const sections = ref([]);
const loading = ref(true);
const error = ref(null);

async function load() {
  loading.value = true; error.value = null;
  try { sections.value = (await get('/api/feed/home')).sections; } catch (e) { error.value = e; } finally { loading.value = false; }
}
onMounted(load);
watch(() => auth.user?.id, load);
</script>

<template>
  <div class="page">
    <div v-if="!auth.user" class="hero panel brand">
      <div class="hero-text">
        <h1>{{ auth.config?.siteName }}</h1>
        <p class="muted" style="font-size: 16px; max-width: 640px">{{ auth.config?.description }}</p>
        <div class="row wrap mt-16">
          <router-link to="/login" class="btn primary">Войти</router-link>
          <router-link v-if="auth.config?.registrationEnabled" to="/register" class="btn">Зарегистрироваться</router-link>
        </div>
      </div>
      <BrandLogo :height="44" light class="hero-logo" />
    </div>
    <div v-else-if="auth.user.status !== 'active'" class="alert warning mb-24"><Icon name="accountClock" :size="20" /><span>Ваша учётная запись ожидает {{ auth.user.status === 'pending_email' ? 'подтверждения адреса электронной почты' : 'одобрения администратором' }}. <router-link to="/account-status">Подробнее</router-link></span></div>

    <div v-if="loading" class="col gap-24">
      <div v-for="i in 2" :key="i"><div class="skeleton" style="width: 220px; height: 26px; margin-bottom: 16px"></div><div class="video-grid"><div v-for="j in 4" :key="j" class="skeleton thumb" style="border-radius: var(--radius)"></div></div></div>
    </div>
    <EmptyState v-else-if="error" icon="lock" :title="error.status === 403 ? 'Для просмотра необходимо войти' : 'Не удалось загрузить'" :text="error.message">
      <router-link v-if="error.status === 403" to="/login" class="btn primary">Войти</router-link>
    </EmptyState>
    <EmptyState v-else-if="!sections.length" title="Пока нет видео" text="Загрузите первое видео — оно появится здесь.">
      <router-link v-if="auth.canUpload" to="/studio/upload" class="btn primary">Загрузить видео</router-link>
    </EmptyState>
    <section v-for="s in sections" :key="s.key" class="home-section">
      <div class="section-title">
        <h2>{{ s.title }}</h2>
        <router-link v-if="s.link" :to="s.link" class="link">Все <Icon name="chevronRight" :size="14" /></router-link>
      </div>
      <div v-if="s.streams" class="video-grid"><LiveCard v-for="st in s.streams" :key="st.id" :stream="st" /></div>
      <div v-else class="video-grid" :class="{ shorts: s.layout === 'shorts' }">
        <VideoCard v-for="v in s.videos" :key="v.id" :video="v" :layout="s.layout === 'shorts' ? 'shorts' : 'grid'" />
      </div>
    </section>
  </div>
</template>

<style>
.hero { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 32px; padding: 36px 40px; }
.hero h1 { font-size: 48px; margin-bottom: 8px; }
.hero-logo { flex-shrink: 0; opacity: 0.9; }
.home-section { margin-bottom: 40px; }
@media (max-width: 767px) { .hero { padding: 24px; } .hero h1 { font-size: 34px; } .hero-logo { display: none; } }
</style>
