<script setup>
// Вложения к видео: загрузка презентаций и документов, переименование, удаление.
import { ref, onMounted } from 'vue';
import { get, patch, del, uploadFile } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { fmtBytes, fmtDateTime } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true } });
const ui = useUi();
const auth = useAuth();
const list = ref([]);
const loading = ref(true);
const uploading = ref(null); // { name, progress }
const title = ref('');
const dragging = ref(false);
const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.md,.rtf,.csv,.zip,.7z,.png,.jpg,.jpeg,.gif,.svg,.dwg,.dxf,.json,.xml';

async function load() { loading.value = true; try { list.value = (await get(`/api/videos/${props.video.id}/attachments`)).attachments; } finally { loading.value = false; } }
onMounted(load);
async function addFiles(files) {
  for (const f of files) {
    uploading.value = { name: f.name, progress: 0 };
    try {
      const r = await uploadFile(`/api/videos/${props.video.id}/attachments`, f, { fields: title.value.trim() ? { title: title.value.trim() } : {}, onProgress: (p) => { uploading.value = { name: f.name, progress: p }; } });
      list.value.push(r.attachment); title.value = '';
    } catch (e) { ui.toast(`${f.name}: ${e.message}`, { type: 'error' }); }
  }
  uploading.value = null;
}
function onPick(e) { addFiles([...e.target.files]); e.target.value = ''; }
function onDrop(e) { dragging.value = false; addFiles([...e.dataTransfer.files]); }
async function rename(a) {
  const t = window.prompt('Название материала', a.title); if (t === null || !t.trim()) return;
  try { const r = await patch(`/api/videos/${props.video.id}/attachments/${a.id}`, { title: t.trim() }); Object.assign(a, r.attachment); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function remove(a) {
  if (!(await ui.ask({ title: `Удалить «${a.title}»?`, okLabel: 'Удалить', danger: true }))) return;
  await del(`/api/videos/${props.video.id}/attachments/${a.id}`); list.value = list.value.filter((x) => x.id !== a.id);
}
</script>

<template>
  <div>
    <h3 class="mb-8">Материалы к видео</h3>
    <p class="muted small">Презентации, инструкции, чек-листы и другие файлы, которые зрители смогут скачать со страницы видео. Доступ к материалам — такой же, как к видео (в том числе по защищённым ссылкам). Материалы попадают в документ для RAG-системы, если интеграция включена.</p>
    <label class="dropzone att-drop" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop">
      <Icon name="inventory" :size="32" />
      <div style="margin-top: 6px">Перетащите файлы или нажмите, чтобы выбрать</div>
      <div class="tiny" style="opacity:.8">PDF, Word, Excel, PowerPoint, изображения, архивы · до {{ auth.config?.attachmentMaxMb || 200 }} МБ</div>
      <input type="file" multiple :accept="ACCEPT" class="hidden" @change="onPick" />
    </label>
    <div class="row wrap mt-8" style="align-items:center"><input class="input sm" v-model="title" placeholder="Название для следующего файла (необязательно)" style="max-width: 360px" /><span v-if="uploading" class="small muted">Загрузка {{ uploading.name }} — {{ Math.round(uploading.progress * 100) }}%</span></div>
    <div v-if="loading" class="muted small mt-16">Загрузка…</div>
    <div v-else class="table-wrap mt-16"><table class="table"><thead><tr><th>Материал</th><th>Файл</th><th>Размер</th><th>Скачиваний</th><th>Добавлен</th><th></th></tr></thead><tbody>
      <tr v-for="a in list" :key="a.id"><td><a :href="a.url" style="font-weight:500">{{ a.title }}</a></td><td class="small mono">{{ a.filename }}</td><td class="small">{{ fmtBytes(a.size) }}</td><td class="small">{{ a.downloads }}</td><td class="small muted">{{ fmtDateTime(a.createdAt) }}</td><td class="actions"><button class="ibtn sm" title="Переименовать" @click="rename(a)" aria-label="Переименовать"><Icon name="edit" :size="16" /></button><button class="ibtn sm" title="Удалить" @click="remove(a)" aria-label="Удалить"><Icon name="delete" :size="16" /></button></td></tr>
      <tr v-if="!list.length"><td colspan="6" class="muted">Материалов пока нет</td></tr>
    </tbody></table></div>
  </div>
</template>

<style>
.att-drop { padding: 24px; }
</style>
