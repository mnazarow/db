<script setup>
import { ref } from 'vue';
import { post } from '../api.js';
import { useUi } from '../stores/ui.js';
import Modal from './Modal.vue';

const props = defineProps({ targetType: { type: String, required: true }, targetId: { type: String, required: true } });
const emit = defineEmits(['close']);
const ui = useUi();
const reason = ref('');
const details = ref('');
const busy = ref(false);
const REASONS = ['Конфиденциальная информация', 'Неактуальное или ошибочное содержание', 'Оскорбления или неприемлемое поведение', 'Нарушение авторских прав', 'Спам или реклама', 'Технические проблемы с видео', 'Другое'];

async function submit() {
  if (!reason.value) return;
  busy.value = true;
  try {
    const url = props.targetType === 'video' ? `/api/videos/${props.targetId}/report` : props.targetType === 'comment' ? `/api/comments/${props.targetId}/report` : '/api/reports';
    const body = props.targetType === 'video' || props.targetType === 'comment' ? { reason: reason.value, details: details.value } : { targetType: props.targetType, targetId: props.targetId, reason: reason.value, details: details.value };
    await post(url, body);
    ui.toast('Жалоба отправлена модераторам', { type: 'success' });
    emit('close');
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { busy.value = false; }
}
</script>

<template>
  <Modal title="Пожаловаться" narrow @close="$emit('close')">
    <div class="col gap-8">
      <label v-for="r in REASONS" :key="r" class="check"><input type="radio" name="reason" :value="r" v-model="reason" /> {{ r }}</label>
      <textarea class="textarea" v-model="details" placeholder="Подробности (необязательно)" maxlength="2000" style="min-height: 72px"></textarea>
    </div>
    <template #footer>
      <button class="btn" @click="$emit('close')">Отмена</button>
      <button class="btn primary" :disabled="!reason || busy" @click="submit">Отправить</button>
    </template>
  </Modal>
</template>
