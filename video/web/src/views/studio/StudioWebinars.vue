<script setup>
// Список вебинаров организатора и создание нового.
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import { fmtDateTime, toLocalInput } from '../../utils/format.js';

const router = useRouter();
const ui = useUi();
const list = ref([]);
const loading = ref(true);
const dialog = ref(null);

async function load() {
  loading.value = true;
  try { list.value = (await get('/api/studio/webinars')).webinars; }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);

function openNew() {
  const d = new Date(Date.now() + 7 * 86400000); d.setMinutes(0, 0, 0);
  dialog.value = { title: '', description: '', scheduledAt: toLocalInput(d.toISOString()), visibility: 'internal', registrationLimit: null, regExternal: false, regModeration: false };
}
async function create() {
  try {
    const r = await post('/api/studio/webinars', { ...dialog.value, scheduledAt: dialog.value.scheduledAt ? new Date(dialog.value.scheduledAt).toISOString() : null });
    dialog.value = null;
    ui.toast('Вебинар создан — заполните программу и спикеров', { type: 'success' });
    router.push(`/studio/webinars/${r.webinar.id}`);
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const STATUS = { idle: 'запланирован', live: 'идёт', ended: 'завершён' };
</script>

<template>
  <div>
    <div class="row wrap mb-16" style="align-items:center">
      <div class="grow"><h2>Вебинары</h2><p class="small muted" style="margin:4px 0 0">Онлайн-встречи со страницей регистрации, программой, напоминаниями и отчётом по участникам.</p></div>
      <button class="btn primary" @click="openNew"><Icon name="plus" :size="16" /> Создать вебинар</button>
    </div>
    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!list.length" icon="campaign" title="Вебинаров пока нет" text="Создайте первый — портал сам сделает страницу регистрации и разошлёт напоминания." />
    <div v-else class="table-wrap"><table class="table"><thead><tr><th>Вебинар</th><th>Когда</th><th>Записались</th><th>Состояние</th><th></th></tr></thead><tbody>
      <tr v-for="w in list" :key="w.id">
        <td><router-link :to="`/studio/webinars/${w.id}`"><b>{{ w.title }}</b></router-link><div class="tiny muted">/webinar/{{ w.slug }}</div></td>
        <td class="small">{{ fmtDateTime(w.scheduledAt) || '—' }}</td>
        <td class="small">{{ w.registrations }}<span v-if="w.limit"> из {{ w.limit }}</span><span v-if="w.pending" class="badge warning" style="margin-left:6px">{{ w.pending }} на проверке</span></td>
        <td><span class="badge" :class="w.status === 'live' ? 'live' : w.status === 'ended' ? '' : 'brand'">{{ STATUS[w.status] }}</span></td>
        <td class="actions nowrap">
          <router-link class="ibtn sm" title="Страница вебинара" :to="`/webinar/${w.slug}`" aria-label="Страница вебинара"><Icon name="openNew" :size="16" /></router-link>
          <router-link class="ibtn sm" title="Управление" :to="`/studio/webinars/${w.id}`" aria-label="Управление"><Icon name="settings" :size="16" /></router-link>
        </td>
      </tr>
    </tbody></table></div>

    <Modal v-if="dialog" title="Новый вебинар" @close="dialog = null">
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1"><label>Название</label><input class="input" v-model="dialog.title" maxlength="150" placeholder="Например, «Как работать с заявками: разбор»" /></div>
        <div class="field" style="grid-column:1/-1"><label>Описание</label><textarea class="textarea" rows="3" v-model="dialog.description"></textarea></div>
        <div class="field"><label>Дата и время</label><input class="input" type="datetime-local" v-model="dialog.scheduledAt" /></div>
        <div class="field"><label>Доступ</label><select class="select" v-model="dialog.visibility"><option value="internal">Сотрудникам</option><option value="public">Всем</option><option value="unlisted">По ссылке</option></select></div>
        <div class="field"><label>Ограничение мест</label><input class="input" type="number" min="1" v-model.number="dialog.registrationLimit" placeholder="без ограничения" /></div>
        <div class="field" style="justify-content:flex-end"><label class="switch"><input type="checkbox" v-model="dialog.regExternal" /><span class="track"></span><span>Пускать внешних участников</span></label><label class="switch"><input type="checkbox" v-model="dialog.regModeration" /><span class="track"></span><span>Подтверждать заявки вручную</span></label></div>
      </div>
      <template #footer><button class="btn ghost" @click="dialog = null">Отмена</button><button class="btn primary" :disabled="!dialog.title" @click="create">Создать</button></template>
    </Modal>
  </div>
</template>
