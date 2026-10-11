<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import QRCode from 'qrcode';
import Modal from './Modal.vue';
import { useUi } from '../stores/ui.js';
import { fmtDuration, parseTime } from '../utils/format.js';
import { copyWithToast } from '../utils/clipboard.js';
import ShareLinks from './studio/ShareLinks.vue';
import { useAuth } from '../stores/auth.js';
import { post } from '../api.js';
import { absUrl } from '../base.js';

const props = defineProps({ video: { type: Object, default: null }, url: { type: String, default: '' }, title: { type: String, default: 'Поделиться' }, currentTime: { type: Number, default: 0 }, allowEmbed: { type: Boolean, default: false }, canManage: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const ui = useUi();
const auth = useAuth();
const withTime = ref(false);
// 1.3: сохранить фрагмент как отдельное видео (клип), в т. ч. вертикальный
const clipSaving = ref(false);
const clipVertical = ref(false);
const canClip = computed(() => !!props.video?.id && auth.isActive && auth.canUpload && auth.config?.editorEnabled !== false);
async function saveClip() {
  if (clipSaving.value) return;
  clipSaving.value = true;
  try {
    await post(`/api/videos/${props.video.id}/clips`, { start: clip.value.start, end: clip.value.end, vertical: clipVertical.value, visibility: 'private' });
    ui.toast(clipVertical.value ? 'Короткое видео создаётся — появится в вашей студии' : 'Клип создаётся — появится в вашей студии', { type: 'success', timeout: 6000 });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { clipSaving.value = false; }
}
const startAt = ref(Math.floor(props.currentTime));
const qr = ref('');
const tab = ref('link');

// Фрагмент: ссылка с началом и концом (t / end)
const clip = ref({ start: Math.max(0, Math.floor(props.currentTime)), end: Math.min(Math.floor((props.video?.duration || 0)), Math.floor(props.currentTime) + 30) });
const clipStartText = ref(fmtDuration(clip.value.start));
const clipEndText = ref(fmtDuration(clip.value.end));
function setClip(which, text) { const v = parseTime(text); if (v === null) return; const max = Math.floor(props.video?.duration || 1e9); clip.value[which] = Math.max(0, Math.min(max, v)); if (clip.value.end <= clip.value.start) clip.value.end = Math.min(max, clip.value.start + 5); clipStartText.value = fmtDuration(clip.value.start); clipEndText.value = fmtDuration(clip.value.end); }
function useNow(which) { const t = Math.floor(props.currentTime); setClip(which, fmtDuration(t)); }
// Адрес страницы: путь маршрутизатора ('/watch/…') или полный адрес; префикс портала (VITE_BASE) добавляет absUrl
const pageUrl = () => (props.url ? absUrl(props.url) : location.href.split('?')[0]);
const clipLink = computed(() => { const u = new URL(pageUrl(), location.origin); u.searchParams.set('t', String(clip.value.start)); u.searchParams.set('end', String(clip.value.end)); return u.toString(); });

const link = computed(() => {
  const u = new URL(pageUrl(), location.origin);
  if (withTime.value && startAt.value > 0) u.searchParams.set('t', String(startAt.value));
  return u.toString();
});
const embed = computed(() => {
  if (!props.video) return '';
  const u = new URL(absUrl(`/embed/${props.video.shortId}`), location.origin);
  if (withTime.value && startAt.value > 0) u.searchParams.set('t', String(startAt.value));
  return `<iframe width="1280" height="720" src="${u}" title="${props.video.title.replace(/"/g, '&quot;')}" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>`;
});
async function copy(text) {
  await copyWithToast(ui, text, 'Скопировано в буфер обмена');
}
async function makeQr() { try { qr.value = await QRCode.toDataURL(link.value, { width: 220, margin: 1, color: { dark: '#0F4382', light: '#ffffff' } }); } catch { qr.value = ''; } }
watch(link, makeQr);
onMounted(makeQr);
const canShare = typeof navigator !== 'undefined' && 'share' in navigator;
const shareNative = () => navigator.share?.({ title: props.video?.title || document.title, url: link.value }).catch(() => {});
</script>

<template>
  <Modal :title="title" :wide="tab === 'guest'" @close="$emit('close')">
    <div class="tabs mb-16">
      <button class="tab" :class="{ active: tab === 'link' }" @click="tab = 'link'">Ссылка</button>
      <button class="tab" :class="{ active: tab === 'qr' }" @click="tab = 'qr'">QR-код</button>
      <button v-if="video && video.duration" class="tab" :class="{ active: tab === 'clip' }" @click="tab = 'clip'">Фрагмент</button>
      <button v-if="allowEmbed" class="tab" :class="{ active: tab === 'embed' }" @click="tab = 'embed'">Встроить</button>
      <button v-if="canManage && video" class="tab" :class="{ active: tab === 'guest' }" @click="tab = 'guest'"><Icon name="linkLock" :size="14" style="vertical-align:-2px" /> Гостевая ссылка</button>
    </div>
    <div v-if="tab === 'link'" class="col gap-16">
      <div class="code-box"><span>{{ link }}</span><button class="btn primary sm" @click="copy(link)">Копировать</button></div>
      <label v-if="video && currentTime > 0" class="check"><input type="checkbox" v-model="withTime" /> Начать с <input class="input" style="width: 100px; height: 32px; display: inline-block; margin: 0 6px" :value="fmtDuration(startAt)" @change="startAt = Math.max(0, ($event.target.value.split(':').reduce((a, b) => a * 60 + +b, 0)) || 0)" /></label>
      <div class="row wrap">
        <button v-if="canShare" class="btn sm" @click="shareNative"><Icon name="share" :size="16" /> Поделиться…</button>
        <a class="btn sm" :href="`mailto:?subject=${encodeURIComponent(video?.title || '')}&body=${encodeURIComponent(link)}`"><Icon name="mail" :size="16" /> По почте</a>
      </div>
    </div>
    <div v-else-if="tab === 'clip'" class="col gap-12">
      <p class="small muted" style="margin:0">Ссылка на фрагмент: видео начнётся с указанного момента и остановится в конце фрагмента. Удобно, чтобы показать коллеге только нужное место.</p>
      <div class="row wrap gap-8" style="align-items:center">
        <span class="small">С</span><input class="input" style="width: 90px; height: 36px" v-model="clipStartText" @change="setClip('start', clipStartText)" /><button class="btn ghost sm" title="Текущее время плеера" @click="useNow('start')" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
        <span class="small">по</span><input class="input" style="width: 90px; height: 36px" v-model="clipEndText" @change="setClip('end', clipEndText)" /><button class="btn ghost sm" title="Текущее время плеера" @click="useNow('end')" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button>
        <span class="small muted">длительность {{ fmtDuration(Math.max(0, clip.end - clip.start)) }}</span>
      </div>
      <div class="code-box"><span>{{ clipLink }}</span><button class="btn primary sm" @click="copy(clipLink)">Копировать</button></div>
      <div v-if="canClip" class="row wrap gap-8 mt-4" style="align-items:center">
        <button class="btn sm" :disabled="clipSaving || clip.end - clip.start < 1" @click="saveClip"><Icon name="scissors" :size="16" /> Сохранить как отдельное видео</button>
        <label class="switch"><input type="checkbox" v-model="clipVertical" /><span class="track"></span><span>вертикальный 9:16 для ленты коротких</span></label>
      </div>
    </div>
    <div v-else-if="tab === 'guest'" class="col gap-8">
      <ShareLinks :video="video" compact />
    </div>
    <div v-else-if="tab === 'qr'" class="col" style="align-items: center">
      <img v-if="qr" :src="qr" alt="QR" style="border-radius: 12px; border: 1px solid var(--line-2)" />
      <p class="small muted">Отсканируйте камерой телефона, чтобы открыть видео</p>
    </div>
    <div v-else class="col gap-16">
      <pre class="code" style="white-space: pre-wrap">{{ embed }}</pre>
      <button class="btn primary sm" @click="copy(embed)">Копировать код</button>
      <p class="small muted" style="margin: 0">Встраивание работает для публичных видео и видео «по ссылке». Для видео «для сотрудников» зритель должен быть авторизован на портале.</p>
    </div>
  </Modal>
</template>
