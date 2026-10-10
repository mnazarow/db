<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { get, post } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import * as ws from '../../ws.js';
import Modal from '../../components/Modal.vue';
import EmptyState from '../../components/EmptyState.vue';
import { VISIBILITY, fmtDateTime, timeAgo, fmtNumber } from '../../utils/format.js';

const router = useRouter();
const auth = useAuth();
const ui = useUi();
const streams = ref([]);
const creating = ref(false);
const categories = ref([]);
const form = ref({ title: '', description: '', visibility: 'internal', categoryId: '', chatEnabled: true, record: true, scheduledAt: '' });
let off = [];
async function load() { streams.value = (await get('/api/studio/live')).streams; }
onMounted(async () => { load(); categories.value = (await get('/api/feed/categories')).categories; off.push(ws.on('live.started', load), ws.on('live.ended', load)); });
onBeforeUnmount(() => off.forEach((f) => f()));
async function create() {
  if (!form.value.title.trim()) return ui.toast('Укажите название', { type: 'error' });
  try {
    const r = await post('/api/studio/live', { ...form.value, categoryId: form.value.categoryId || null, scheduledAt: form.value.scheduledAt ? new Date(form.value.scheduledAt).toISOString() : null });
    creating.value = false;
    router.push(`/studio/live/${r.stream.id}`);
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const STATUS = { idle: ['Готова к эфиру', 'brand'], live: ['В эфире', 'live'], ended: ['Завершена', ''] };
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Трансляции</h1><div class="sub">Прямые эфиры через OBS/RTMP, SRT или прямо из браузера</div></div><div class="actions"><button class="btn primary" @click="creating = true"><Icon name="broadcast" :size="18" /> Новая трансляция</button></div></div>
    <div v-if="!auth.canStream" class="alert warning mb-16"><Icon name="alert" :size="20" /> Проведение трансляций вам не разрешено. Обратитесь к администратору.</div>
    <EmptyState v-if="!streams.length" icon="broadcast" title="Трансляций пока нет" text="Создайте трансляцию, получите ключ и выходите в эфир из OBS или из браузера." />
    <div v-else class="table-wrap"><table class="table"><thead><tr><th>Трансляция</th><th>Статус</th><th>Видимость</th><th class="num">Зрители</th><th>Дата</th><th></th></tr></thead><tbody>
      <tr v-for="s in streams" :key="s.id">
        <td><router-link :to="`/studio/live/${s.id}`" style="color:var(--text);font-weight:500">{{ s.title }}</router-link><div v-if="s.recordingShortId" class="tiny"><router-link :to="`/watch/${s.recordingShortId}`">Запись эфира →</router-link></div></td>
        <td><span class="badge" :class="STATUS[s.status]?.[1]">{{ STATUS[s.status]?.[0] }}</span></td>
        <td class="small">{{ VISIBILITY[s.visibility]?.label }}</td>
        <td class="num">{{ s.status === 'live' ? fmtNumber(s.viewerCount) : s.viewerPeak ? `пик ${s.viewerPeak}` : '—' }}</td>
        <td class="small nowrap">{{ s.status === 'live' ? 'начало ' + timeAgo(s.startedAt) : s.scheduledAt ? fmtDateTime(s.scheduledAt) : s.endedAt ? fmtDateTime(s.endedAt) : fmtDateTime(s.createdAt) }}</td>
        <td class="actions"><router-link :to="`/studio/live/${s.id}`" class="btn sm">Управление</router-link> <router-link :to="`/live/${s.shortId}`" class="btn ghost sm">Смотреть</router-link></td>
      </tr>
    </tbody></table></div>
    <Modal v-if="creating" title="Новая трансляция" @close="creating = false">
      <div class="col gap-16">
        <div class="field"><label>Название</label><input class="input" v-model="form.title" maxlength="150" autofocus /></div>
        <div class="field"><label>Описание</label><textarea class="textarea" v-model="form.description" style="min-height:80px"></textarea></div>
        <div class="form-grid">
          <div class="field"><label>Видимость</label><select class="select" v-model="form.visibility"><option v-for="(v, k) in VISIBILITY" :key="k" :value="k">{{ v.label }}</option></select></div>
          <div class="field"><label>Категория</label><select class="select" v-model="form.categoryId"><option value="">Без категории</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
          <div class="field"><label>Запланировать на</label><input class="input" type="datetime-local" v-model="form.scheduledAt" /></div>
        </div>
        <label class="switch"><input type="checkbox" v-model="form.chatEnabled" /><span class="track"></span><span>Чат зрителей</span></label>
        <label class="switch"><input type="checkbox" v-model="form.record" /><span class="track"></span><span>Записывать эфир и опубликовать запись после завершения</span></label>
      </div>
      <template #footer><button class="btn" @click="creating = false">Отмена</button><button class="btn primary" @click="create">Создать</button></template>
    </Modal>
  </div>
</template>
