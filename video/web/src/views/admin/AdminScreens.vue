<script setup>
// Администрирование: экраны-витрины — телевизор в холле крутит плейлист без входа и без интерфейса.
import { ref, onMounted, computed } from 'vue';
import { get, post, patch, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import Dropdown from '../../components/Dropdown.vue';
import { copyText } from '../../utils/clipboard.js';
import { fmtDateTime } from '../../utils/format.js';

const ui = useUi();
const screens = ref([]);
const loading = ref(true);
const playlists = ref([]);
const categories = ref([]);
const dialog = ref(null);      // редактируемый экран (или новый)
const preview = ref(null);     // { screen, items }
const SOURCES = [
  { id: 'latest', label: 'Свежие видео портала' },
  { id: 'playlist', label: 'Плейлист' },
  { id: 'category', label: 'Категория' },
];

async function load() {
  loading.value = true;
  try {
    const [s, p, c] = await Promise.all([
      get('/api/admin/screens'),
      get('/api/playlists?limit=100&mine=0').catch(() => ({ playlists: [] })),
      get('/api/feed/categories').catch(() => ({ categories: [] })),
    ]);
    screens.value = s.screens;
    playlists.value = p.playlists || [];
    categories.value = c.categories || [];
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);

function openNew() {
  dialog.value = { id: null, name: '', source: 'latest', playlistId: '', categoryId: '', shuffle: false, subtitles: true, showTitle: true, muted: true, isActive: true };
}
function openEdit(s) { dialog.value = { ...s, playlistId: s.playlistId || '', categoryId: s.categoryId || '' }; }

async function save() {
  const d = dialog.value;
  const body = {
    name: d.name, source: d.source, playlistId: d.source === 'playlist' ? d.playlistId || null : null,
    categoryId: d.source === 'category' ? Number(d.categoryId) || null : null,
    shuffle: d.shuffle, subtitles: d.subtitles, showTitle: d.showTitle, muted: d.muted, isActive: d.isActive,
  };
  try {
    if (d.id) await patch(`/api/admin/screens/${d.id}`, body);
    else await post('/api/admin/screens', body);
    dialog.value = null;
    await load();
    ui.toast('Экран сохранён', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function remove(s) {
  if (!(await ui.ask({ title: 'Удалить экран?', message: `«${s.name}» перестанет открываться по своей ссылке.`, okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/admin/screens/${s.id}`); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function rotate(s) {
  if (!(await ui.ask({ title: 'Сменить ссылку?', message: 'Старая ссылка перестанет работать — экран в холле нужно будет открыть заново.', okLabel: 'Сменить' }))) return;
  try { await patch(`/api/admin/screens/${s.id}`, { rotateToken: true }); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}

async function copyUrl(s) { if (await copyText(s.url)) ui.toast('Ссылка экрана скопирована'); }

async function showPreview(s) {
  try { const r = await get(`/api/admin/screens/${s.id}/preview`); preview.value = { screen: s, ...r }; }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}

function sourceLabel(s) {
  if (s.source === 'playlist') return `Плейлист: ${s.playlistTitle || '—'}`;
  if (s.source === 'category') return `Категория: ${s.categoryName || '—'}`;
  if (s.source === 'channel') return `Канал: ${s.channelName || '—'}`;
  return 'Свежие видео портала';
}
const online = (s) => s.lastSeenAt && Date.now() - new Date(s.lastSeenAt).getTime() < 30 * 60 * 1000;
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>Экраны</h1>
        <p class="muted">Телевизор в холле или переговорной показывает видео по кругу: откройте ссылку экрана один раз — дальше он работает сам.</p>
      </div>
      <button class="btn primary" @click="openNew"><Icon name="plus" :size="18" /> Новый экран</button>
    </div>

    <div v-if="loading" class="skeleton" style="height: 200px"></div>
    <EmptyState v-else-if="!screens.length" icon="tv" title="Экранов пока нет" text="Создайте экран, скопируйте его ссылку и откройте её на телевизоре или мини-компьютере в холле." />
    <div v-else class="panel table-scroll" style="padding:0">
      <table class="table">
        <thead><tr><th>Экран</th><th>Что показывает</th><th>Последний выход</th><th>Ссылка</th><th></th></tr></thead>
        <tbody>
          <tr v-for="s in screens" :key="s.id">
            <td>
              <b>{{ s.name }}</b>
              <div class="tiny muted">
                <span :class="online(s) ? 'dot-on' : 'dot-off'"></span>
                {{ s.isActive ? (online(s) ? 'на связи' : 'не выходил на связь') : 'выключен' }}
              </div>
            </td>
            <td>
              {{ sourceLabel(s) }}
              <div class="tiny muted">{{ [s.shuffle ? 'вперемешку' : 'по порядку', s.subtitles ? 'субтитры' : 'без субтитров', s.muted ? 'без звука' : 'со звуком'].join(' · ') }}</div>
            </td>
            <td class="nowrap tiny muted">{{ s.lastSeenAt ? fmtDateTime(s.lastSeenAt) : '—' }}<div v-if="s.lastIp">{{ s.lastIp }}</div></td>
            <td><button class="btn sm soft" @click="copyUrl(s)"><Icon name="copy" :size="16" /> Скопировать</button></td>
            <td class="nowrap" style="text-align:right">
              <div class="row gap-8" style="justify-content:flex-end">
                <button class="btn sm soft" @click="showPreview(s)"><Icon name="eye" :size="16" /> Что в показе</button>
                <a class="btn sm soft" :href="s.url" target="_blank" rel="noopener"><Icon name="openNew" :size="16" /> Открыть</a>
                <Dropdown>
                  <template #trigger><button class="ibtn soft-round" aria-label="Действия с экраном"><Icon name="moreH" /></button></template>
                  <button class="item" @click="openEdit(s)"><Icon class="ic" name="edit" :size="18" /> Изменить</button>
                  <button class="item" @click="rotate(s)"><Icon class="ic" name="refresh" :size="18" /> Сменить ссылку</button>
                  <button class="item danger" @click="remove(s)"><Icon class="ic" name="delete" :size="18" /> Удалить</button>
                </Dropdown>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal v-if="dialog" :title="dialog.id ? 'Экран' : 'Новый экран'" @close="dialog = null">
      <div class="col gap-12">
        <div class="field"><label>Название</label><input class="input" v-model="dialog.name" maxlength="120" placeholder="Холл, 1 этаж" /></div>
        <div class="field"><label>Что показывать</label>
          <select class="input" v-model="dialog.source"><option v-for="s in SOURCES" :key="s.id" :value="s.id">{{ s.label }}</option></select>
        </div>
        <div class="field" v-if="dialog.source === 'playlist'"><label>Плейлист</label>
          <select class="input" v-model="dialog.playlistId"><option value="">— выберите —</option><option v-for="p in playlists" :key="p.id" :value="p.id">{{ p.title }}</option></select>
        </div>
        <div class="field" v-if="dialog.source === 'category'"><label>Категория</label>
          <select class="input" v-model="dialog.categoryId"><option value="">— выберите —</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <label class="switch"><input type="checkbox" v-model="dialog.shuffle" /><span class="track"></span><span>Вперемешку</span></label>
        <label class="switch"><input type="checkbox" v-model="dialog.subtitles" /><span class="track"></span><span>Показывать субтитры</span></label>
        <label class="switch"><input type="checkbox" v-model="dialog.showTitle" /><span class="track"></span><span>Название видео на экране</span></label>
        <label class="switch"><input type="checkbox" v-model="dialog.muted" /><span class="track"></span><span>Без звука</span></label>
        <label class="switch"><input type="checkbox" v-model="dialog.isActive" /><span class="track"></span><span>Экран включён</span></label>
        <p class="hint">В показ попадают только готовые видео с доступом «Публичное» или «Для сотрудников» — личные записи на экран не выводятся.</p>
      </div>
      <template #footer>
        <button class="btn" @click="dialog = null">Отмена</button>
        <button class="btn primary" :disabled="!dialog.name.trim()" @click="save">Сохранить</button>
      </template>
    </Modal>

    <Modal v-if="preview" :title="`В показе: ${preview.screen.name}`" @close="preview = null">
      <p class="small muted">Всего видео: <b>{{ preview.count }}</b></p>
      <div class="col gap-8 mt-8">
        <div v-for="v in preview.items" :key="v.id" class="row gap-8" style="align-items:center">
          <img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" style="width:96px; border-radius:6px" />
          <div class="grow"><div class="ellipsis">{{ v.title }}</div><div class="tiny muted">{{ v.owner?.displayName }}</div></div>
        </div>
      </div>
    </Modal>
  </div>
</template>

<style>
.dot-on, .dot-off { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 4px; }
.dot-on { background: var(--success); }
.dot-off { background: var(--text-3); }
</style>
