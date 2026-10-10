<script setup>
// Страница вебинара: программа, спикеры, материалы, форма регистрации и комната ожидания.
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get, post } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import EmptyState from '../components/EmptyState.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import { fmtDateTime, fmtBytes, fmtNumber } from '../utils/format.js';

const route = useRoute();
const auth = useAuth();
const ui = useUi();
const w = ref(null);
const error = ref(null);
const loading = ref(true);
const form = ref({ name: '', email: '', company: '', answers: {} });
const sending = ref(false);
const now = ref(Date.now());
let timer = null;
let poll = null;

async function load() {
  try {
    const r = await get(`/api/webinars/${route.params.slug}${route.query.t ? `?t=${route.query.t}` : ''}`);
    w.value = r.webinar;
    if (auth.user) { form.value.name = auth.user.displayName; form.value.email = auth.user.email; }
    document.title = `${r.webinar.title} — ${auth.siteName}`;
  } catch (e) { error.value = e; } finally { loading.value = false; }
}
onMounted(() => {
  load();
  timer = setInterval(() => { now.value = Date.now(); }, 1000);
  poll = setInterval(load, 30000);
});
onBeforeUnmount(() => { clearInterval(timer); clearInterval(poll); });
watch(() => route.params.slug, load);

const my = computed(() => w.value?.registration?.my || null);
const startsIn = computed(() => (w.value?.scheduledAt ? new Date(w.value.scheduledAt).getTime() - now.value : null));
const countdown = computed(() => {
  const ms = startsIn.value;
  if (ms == null || ms <= 0) return null;
  const s = Math.floor(ms / 1000);
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  return d > 0 ? `${d} дн ${h} ч ${m} мин` : h > 0 ? `${h} ч ${m} мин ${String(sec).padStart(2, '0')} с` : `${m} мин ${String(sec).padStart(2, '0')} с`;
});
const isLive = computed(() => w.value?.status === 'live');
const canJoin = computed(() => my.value?.status === 'approved' || w.value?.canManage || !w.value?.registration?.enabled);

async function register() {
  sending.value = true;
  try {
    const r = await post(`/api/webinars/${route.params.slug}/register`, { ...form.value });
    await load();
    ui.toast(r.status === 'approved' ? 'Вы записаны — ссылка отправлена на почту' : r.status === 'pending' ? 'Заявка отправлена, ждите подтверждения' : 'Вы в листе ожидания', { type: 'success', timeout: 7000 });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { sending.value = false; }
}
async function cancel() {
  if (!(await ui.ask({ title: 'Отменить регистрацию?', okLabel: 'Отменить участие', danger: true }))) return;
  try { await post(`/api/webinars/${route.params.slug}/cancel`, {}); await load(); ui.toast('Регистрация отменена'); }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
</script>

<template>
  <div class="page">
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="error" icon="campaign" title="Вебинар недоступен" :text="error.message" />
    <template v-else-if="w">
      <div class="web-head" :style="w.coverUrl ? { backgroundImage: `linear-gradient(180deg, rgba(0,0,0,.35), rgba(0,0,0,.8)), url(${w.coverUrl})` } : null" :class="{ cover: !!w.coverUrl }">
        <div class="row wrap gap-8 mb-8">
          <span v-if="isLive" class="badge live"><Icon name="live" :size="12" /> Идёт сейчас</span>
          <span v-else-if="w.status === 'ended'" class="badge">Завершён</span>
          <span v-else class="badge brand"><Icon name="calendar" :size="12" /> {{ fmtDateTime(w.scheduledAt) || 'Дата уточняется' }}</span>
          <span v-if="w.autoWebinar" class="badge"><Icon name="replay" :size="12" /> Показ записи</span>
        </div>
        <h1>{{ w.title }}</h1>
        <p class="lead">{{ w.description }}</p>
        <div class="row wrap gap-8 mt-8">
          <router-link v-if="isLive && canJoin" class="btn primary lg" :to="w.liveUrl"><Icon name="play" :size="18" /> Войти в комнату</router-link>
          <router-link v-else-if="w.status === 'ended' && w.recordingShortId" class="btn primary lg" :to="`/watch/${w.recordingShortId}`"><Icon name="play" :size="18" /> Смотреть запись</router-link>
          <a v-if="w.scheduledAt && w.status !== 'ended'" class="btn" :href="$url(`/api/webinars/${w.slug}/calendar.ics`)"><Icon name="calendar" :size="16" /> В календарь</a>
          <router-link v-if="w.canManage" class="btn" :to="`/studio/webinars/${w.id}`"><Icon name="settings" :size="16" /> Управление</router-link>
        </div>
      </div>

      <div class="web-body">
        <div class="web-main">
          <!-- Комната ожидания -->
          <div v-if="countdown && my?.status === 'approved'" class="panel waiting">
            <div class="wait-title">До начала</div>
            <div class="wait-clock">{{ countdown }}</div>
            <div class="small muted">Страница обновится сама, как только начнётся эфир. Ссылка для входа отправлена на {{ my.email }}.</div>
          </div>

          <div v-if="w.agenda?.length" class="panel">
            <h3 class="mb-8">Программа</h3>
            <div v-for="(a, i) in w.agenda" :key="i" class="agenda-row">
              <div class="ag-time">{{ a.time || '—' }}</div>
              <div><b>{{ a.title }}</b><div v-if="a.note" class="small muted">{{ a.note }}</div></div>
            </div>
          </div>

          <div v-if="w.speakers?.length" class="panel">
            <h3 class="mb-8">Спикеры</h3>
            <div class="speakers">
              <div v-for="(s, i) in w.speakers" :key="i" class="speaker">
                <div class="sp-photo"><img v-if="s.photo" :src="s.photo" alt="" /><Icon v-else name="person" :size="28" /></div>
                <div><b>{{ s.name }}</b><div class="small muted">{{ s.role }}</div><div v-if="s.bio" class="small mt-4">{{ s.bio }}</div></div>
              </div>
            </div>
          </div>

          <div v-if="w.materials?.length" class="panel">
            <h3 class="mb-8">Материалы</h3>
            <a v-for="m in w.materials" :key="m.id" class="material" :href="m.url" target="_blank" rel="noopener"><Icon name="doc" :size="18" /><span class="grow">{{ m.name }}</span><span class="tiny muted">{{ fmtBytes(m.bytes) }}</span></a>
          </div>

          <div v-if="w.cta" class="panel cta-panel">
            <div class="grow"><b>{{ w.cta.text || w.cta.label }}</b></div>
            <a class="btn primary" :href="w.cta.url" target="_blank" rel="noopener">{{ w.cta.label }}</a>
          </div>
        </div>

        <!-- Регистрация -->
        <aside class="web-side">
          <div class="panel reg-panel">
            <template v-if="!w.registration.enabled">
              <h3>Регистрация не нужна</h3>
              <p class="small muted">Приходите к началу — ссылка на комнату будет активна на этой странице.</p>
            </template>
            <template v-else-if="my && my.status !== 'cancelled'">
              <h3><Icon name="checkAll" :size="20" style="vertical-align:-4px" />
                {{ my.status === 'approved' ? 'Вы участвуете' : my.status === 'pending' ? 'Заявка на проверке' : my.status === 'waitlist' ? 'Лист ожидания' : 'Заявка отклонена' }}</h3>
              <p class="small muted">
                <template v-if="my.status === 'approved'">Мы напомним за сутки, за час и за 15 минут до начала.</template>
                <template v-else-if="my.status === 'pending'">Организатор проверит заявку и пришлёт подтверждение на {{ my.email }}.</template>
                <template v-else-if="my.status === 'waitlist'">Мест пока нет. Как только место освободится, мы пришлём ссылку.</template>
              </p>
              <div v-if="w.certUrl" class="alert success mt-8"><Icon name="verified" :size="18" /><router-link :to="w.certUrl">Ваш сертификат участника</router-link></div>
              <button class="btn ghost sm mt-8" @click="cancel">Отменить участие</button>
            </template>
            <template v-else-if="!w.registration.open">
              <h3>Регистрация закрыта</h3>
              <p class="small muted">Приём заявок на этот вебинар завершён.</p>
            </template>
            <template v-else>
              <h3>Записаться на вебинар</h3>
              <p v-if="w.registration.note" class="small muted">{{ w.registration.note }}</p>
              <p v-if="w.registration.seatsLeft !== null" class="small">Свободных мест: <b>{{ w.registration.seatsLeft }}</b><span v-if="!w.registration.seatsLeft && w.registration.waitlist"> — запишем в лист ожидания</span></p>
              <div class="form-grid one">
                <div class="field"><label>Имя</label><input class="input" v-model="form.name" :disabled="!!auth.user" placeholder="Как к вам обращаться" /></div>
                <div class="field"><label>Электронная почта</label><input class="input" v-model="form.email" :disabled="!!auth.user" placeholder="name@company.ru" /></div>
                <div v-if="!auth.user" class="field"><label>Организация</label><input class="input" v-model="form.company" /></div>
                <div v-for="f in w.registration.fields" :key="f.id" class="field">
                  <label>{{ f.label }}<span v-if="f.required" style="color:var(--danger)"> *</span></label>
                  <select v-if="f.type === 'select'" class="select" v-model="form.answers[f.id]"><option value="">—</option><option v-for="o in f.options" :key="o" :value="o">{{ o }}</option></select>
                  <textarea v-else-if="f.type === 'textarea'" class="textarea" rows="3" v-model="form.answers[f.id]"></textarea>
                  <label v-else-if="f.type === 'checkbox'" class="switch"><input type="checkbox" v-model="form.answers[f.id]" /><span class="track"></span><span>Да</span></label>
                  <input v-else class="input" v-model="form.answers[f.id]" />
                </div>
              </div>
              <button class="btn primary w-full mt-8" :disabled="sending || !form.name || !form.email" @click="register">{{ sending ? 'Отправляем…' : 'Записаться' }}</button>
              <p v-if="!auth.user" class="tiny muted mt-8">Ссылка для входа придёт на указанную почту — учётная запись на портале не нужна.</p>
            </template>
            <div class="tiny muted mt-8" v-if="w.registration.count">Уже записались: {{ fmtNumber(w.registration.count) }}</div>
          </div>

          <div class="panel">
            <div class="row gap-8" style="align-items:center">
              <ChannelAvatar :user="{ displayName: w.owner.displayName, handle: w.owner.handle }" />
              <div><div class="small muted">Организатор</div><b>{{ w.owner.displayName }}</b></div>
            </div>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.web-head { border-radius: var(--radius-lg); padding: 28px; background: var(--surface-2); background-size: cover; background-position: center; margin-bottom: 24px; }
.web-head.cover { color: #fff; }
.web-head h1 { margin: 0; }
.web-head .lead { max-width: 760px; opacity: .9; }
.web-body { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 24px; align-items: start; }
.web-main { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.web-side { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 80px; }
.waiting { text-align: center; background: linear-gradient(135deg, var(--brand-700), var(--brand-400)); color: #fff; }
.wait-title { text-transform: uppercase; letter-spacing: .08em; font-size: 12px; opacity: .85; }
.wait-clock { font-size: 40px; font-weight: 700; font-family: var(--font-display, inherit); margin: 6px 0; }
.waiting .muted { color: rgba(255,255,255,.8); }
.agenda-row { display: grid; grid-template-columns: 90px 1fr; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.agenda-row:last-child { border-bottom: 0; }
.ag-time { color: var(--brand); font-weight: 600; font-variant-numeric: tabular-nums; }
.speakers { display: grid; gap: 14px; }
.speaker { display: flex; gap: 12px; align-items: flex-start; }
.sp-photo { width: 56px; height: 56px; border-radius: 50%; background: var(--surface-2); display: flex; align-items: center; justify-content: center; overflow: hidden; flex: 0 0 auto; }
.sp-photo img { width: 100%; height: 100%; object-fit: cover; }
.material { display: flex; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); color: inherit; text-decoration: none; }
.material:last-child { border-bottom: 0; }
.cta-panel { display: flex; gap: 16px; align-items: center; }
.form-grid.one { grid-template-columns: 1fr; }
.w-full { width: 100%; justify-content: center; }
@media (max-width: 980px) { .web-body { grid-template-columns: 1fr; } .web-side { position: static; } }
</style>
