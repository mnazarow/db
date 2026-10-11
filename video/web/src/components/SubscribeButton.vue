<script setup>
import { ref, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { post, del, patch } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import Dropdown from './Dropdown.vue';

const props = defineProps({ handle: { type: String, required: true }, subscribed: Boolean, notify: { type: String, default: 'all' }, ownerId: { type: String, default: '' }, size: { type: String, default: '' } });
const emit = defineEmits(['change']);
const auth = useAuth();
const ui = useUi();
const router = useRouter();
const route = useRoute();
const sub = ref(props.subscribed);
const notifyMode = ref(props.notify);
const busy = ref(false);
watch(() => props.subscribed, (v) => { sub.value = v; });
watch(() => props.notify, (v) => { notifyMode.value = v; });

async function toggle() {
  if (!auth.user) return router.push({ name: 'login', query: { next: route.fullPath } });
  if (!auth.isActive) return ui.toast('Дождитесь одобрения учётной записи');
  busy.value = true;
  try {
    const r = sub.value ? await del(`/api/channels/${props.handle}/subscribe`) : await post(`/api/channels/${props.handle}/subscribe`, {});
    sub.value = r.subscribed;
    emit('change', { subscribed: r.subscribed, subscriberCount: r.subscriberCount });
    ui.toast(r.subscribed ? 'Вы подписались на канал' : 'Подписка отменена');
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = false; }
}
async function setNotify(mode) {
  await patch(`/api/channels/${props.handle}/subscribe`, { notify: mode });
  notifyMode.value = mode;
  ui.toast(mode === 'all' ? 'Уведомления о новых видео включены' : 'Уведомления отключены');
}
</script>

<template>
  <span v-if="auth.user && auth.user.id === ownerId" class="row"><router-link to="/studio/channel" class="btn" :class="size">Настроить канал</router-link></span>
  <span v-else class="row gap-4">
    <button class="btn" :class="[size, sub ? 'soft' : 'primary']" :disabled="busy" @click="toggle">
      <Icon v-if="sub" :name="notifyMode === 'all' ? 'bellRing' : 'bellOff'" :size="18" />{{ sub ? 'Вы подписаны' : 'Подписаться' }}
    </button>
    <Dropdown v-if="sub" width="240px">
      <template #trigger><button class="ibtn" title="Уведомления" aria-label="Уведомления"><Icon name="chevronDown" :size="20" /></button></template>
      <div class="title">Уведомления</div>
      <button class="item" @click="setNotify('all')"><Icon class="ic" name="bellRing" :size="18" /> Все новые видео <Icon v-if="notifyMode === 'all'" name="check" :size="16" style="margin-left:auto" /></button>
      <button class="item" @click="setNotify('none')"><Icon class="ic" name="bellOff" :size="18" /> Не уведомлять <Icon v-if="notifyMode === 'none'" name="check" :size="16" style="margin-left:auto" /></button>
      <div class="sep"></div>
      <button class="item danger" @click="toggle"><Icon class="ic" name="close" :size="18" /> Отписаться</button>
    </Dropdown>
  </span>
</template>
