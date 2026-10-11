<script setup>
// Витрины — страницы-подборки со своим адресом (как Wistia Channels и Brightcove Gallery).
import { ref, onMounted, computed } from 'vue';
import { get, post, patch, put, del, uploadFile } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import { fmtDateTime, fmtNumber } from '../../utils/format.js';

const ui = useUi();
const list = ref([]);
const loading = ref(true);
const dialog = ref(null);        // свойства витрины
const editor = ref(null);        // { showcase, sections }
const playlists = ref([]);
const categories = ref([]);
const search = ref('');
const found = ref([]);
const searching = ref(false);
const KINDS = [['videos', 'Выбранные видео'], ['playlist', 'Плейлист'], ['category', 'Категория'], ['tag', 'Тег'], ['latest', 'Свежие видео портала']];
const LAYOUTS = [['grid', 'Сетка'], ['row', 'Лента в строку'], ['hero', 'Крупно']];

async function load() {
  loading.value = true;
  try {
    const [s, p, c] = await Promise.all([
      get('/api/admin/showcases'),
      get('/api/playlists?limit=100&mine=0').catch(() => ({ playlists: [] })),
      get('/api/feed/categories').catch(() => ({ categories: [] })),
    ]);
    list.value = s.showcases; playlists.value = p.playlists || []; categories.value = c.categories || [];
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { loading.value = false; }
}
onMounted(load);

function openNew() { dialog.value = { id: null, title: '', slug: '', subtitle: '', description: '', visibility: 'internal', inMenu: true, enabled: true, position: 0 }; }
function openProps(s) { dialog.value = { ...s }; }

async function saveProps() {
  const d = dialog.value;
  const body = { title: d.title, slug: d.slug || undefined, subtitle: d.subtitle, description: d.description, visibility: d.visibility, inMenu: d.inMenu, enabled: d.enabled, position: Number(d.position) || 0 };
  try {
    if (d.id) await patch(`/api/admin/showcases/${d.id}`, body);
    else await post('/api/admin/showcases', body);
    dialog.value = null; await load();
    ui.toast('Витрина сохранена', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function remove(s) {
  if (!(await ui.ask({ title: `Удалить витрину «${s.title}»?`, message: 'Видео останутся на месте — удалится только страница-подборка.', okLabel: 'Удалить', danger: true }))) return;
  try { await del(`/api/admin/showcases/${s.id}`); await load(); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function openEditor(s) {
  try { const r = await get(`/api/admin/showcases/${s.id}/sections`); editor.value = { showcase: r.showcase, sections: r.sections.map((x) => ({ ...x })) }; }
  catch (e) { ui.toast(e.message, { type: 'error' }); }
}
function addSection() { editor.value.sections.push({ title: 'Новый раздел', note: '', kind: 'videos', layout: 'grid', maxItems: 12, videos: [], playlistId: '', categoryId: '', tag: '' }); }
function moveSection(i, d) { const a = editor.value.sections; const j = i + d; if (j < 0 || j >= a.length) return; [a[i], a[j]] = [a[j], a[i]]; }
async function doSearch() {
  if (!search.value.trim()) { found.value = []; return; }
  searching.value = true;
  try { found.value = (await get(`/api/search?q=${encodeURIComponent(search.value)}&limit=8`)).videos || []; }
  catch (e) { ui.toast(e.message, { type: 'error' }); } finally { searching.value = false; }
}
function addVideo(sec, v) { if (!sec.videos.some((x) => x.id === v.id)) sec.videos.push(v); }
function removeVideo(sec, v) { sec.videos = sec.videos.filter((x) => x.id !== v.id); }
async function saveSections() {
  try {
    const body = { sections: editor.value.sections.map((s) => ({ title: s.title, note: s.note, kind: s.kind, layout: s.layout, maxItems: Number(s.maxItems) || 12, playlistId: s.playlistId || null, categoryId: s.categoryId || null, tag: s.tag || null, videoIds: (s.videos || []).map((v) => v.id) })) };
    await put(`/api/admin/showcases/${editor.value.showcase.id}/sections`, body);
    ui.toast('Разделы сохранены', { type: 'success' });
    editor.value = null; await load();
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function uploadCover(s, e) {
  const file = e.target.files?.[0]; if (!file) return;
  try { await uploadFile(`/api/admin/showcases/${s.id}/cover`, file); ui.toast('Обложка загружена', { type: 'success' }); await load(); }
  catch (err) { ui.toast(err.message, { type: 'error' }); }
}
</script>

<template>
  <div>
    <div class="row wrap mb-16" style="align-items:center">
      <div class="grow"><h2>Витрины</h2><p class="small muted" style="margin:4px 0 0">Тематическая страница со своим адресом: обложка, описание и разделы с видео. Подходит для подборок «Новичку», «Охрана труда», «Продукция» — их можно закрепить в меню портала и давать ссылкой.</p></div>
      <button class="btn primary" @click="openNew"><Icon name="plus" :size="16" /> Создать витрину</button>
    </div>

    <div v-if="loading" class="loading-block"><div class="spin"></div></div>
    <EmptyState v-else-if="!list.length" icon="apps" title="Витрин пока нет" text="Создайте первую подборку — например, «Новому сотруднику»." />
    <div v-else class="table-wrap"><table class="table"><thead><tr><th>Витрина</th><th>Адрес</th><th>Разделы</th><th>Доступ</th><th>Открытий</th><th></th></tr></thead><tbody>
      <tr v-for="s in list" :key="s.id" :class="{ muted: !s.enabled }">
        <td>
          <div class="row gap-8" style="align-items:center">
            <img v-if="s.coverUrl" :src="s.coverUrl" alt="" style="width:64px;height:36px;object-fit:cover;border-radius:6px" />
            <div><b>{{ s.title }}</b><div class="tiny muted">{{ s.subtitle || '—' }}</div></div>
          </div>
        </td>
        <td><router-link class="mono small" :to="`/hub/${s.slug}`">/hub/{{ s.slug }}</router-link><div v-if="s.inMenu" class="tiny muted">в меню портала</div></td>
        <td class="small">{{ s.sections }}</td>
        <td class="small">{{ { public: 'Всем', internal: 'Сотрудникам', unlisted: 'По ссылке' }[s.visibility] }}</td>
        <td class="small">{{ fmtNumber(s.viewCount) }}</td>
        <td class="actions nowrap">
          <button class="ibtn sm" title="Разделы и видео" @click="openEditor(s)" aria-label="Разделы и видео"><Icon name="listStatus" :size="16" /></button>
          <button class="ibtn sm" title="Свойства" @click="openProps(s)" aria-label="Свойства"><Icon name="edit" :size="16" /></button>
          <label class="ibtn sm" title="Обложка"><Icon name="image" :size="16" /><input type="file" class="hidden" accept="image/*" @change="uploadCover(s, $event)" /></label>
          <button class="ibtn sm" title="Удалить" @click="remove(s)" aria-label="Удалить"><Icon name="delete" :size="16" /></button>
        </td>
      </tr>
    </tbody></table></div>

    <Modal v-if="dialog" :title="dialog.id ? 'Свойства витрины' : 'Новая витрина'" @close="dialog = null">
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1"><label>Название</label><input class="input" v-model="dialog.title" maxlength="120" placeholder="Например, «Новому сотруднику»" /></div>
        <div class="field"><label>Адрес страницы</label><div class="row gap-4" style="align-items:center"><span class="small muted">/hub/</span><input class="input" v-model="dialog.slug" placeholder="novomu-sotrudniku" /></div></div>
        <div class="field"><label>Доступ</label><select class="select" v-model="dialog.visibility"><option value="public">Всем, в том числе гостям</option><option value="internal">Сотрудникам портала</option><option value="unlisted">Только по прямой ссылке</option></select></div>
        <div class="field" style="grid-column:1/-1"><label>Подзаголовок</label><input class="input" v-model="dialog.subtitle" maxlength="200" /></div>
        <div class="field" style="grid-column:1/-1"><label>Описание</label><textarea class="textarea" v-model="dialog.description" rows="3"></textarea></div>
        <div class="field"><label>Порядок в меню</label><input class="input" type="number" v-model.number="dialog.position" /></div>
        <div class="field" style="justify-content:flex-end"><label class="switch"><input type="checkbox" v-model="dialog.inMenu" /><span class="track"></span><span>Показывать в меню портала</span></label><label class="switch"><input type="checkbox" v-model="dialog.enabled" /><span class="track"></span><span>Витрина включена</span></label></div>
      </div>
      <template #footer><button class="btn ghost" @click="dialog = null">Отмена</button><button class="btn primary" :disabled="!dialog.title" @click="saveProps">Сохранить</button></template>
    </Modal>

    <Modal v-if="editor" :title="`Разделы витрины «${editor.showcase.title}»`" wide @close="editor = null">
      <div class="col gap-16">
        <div v-for="(sec, i) in editor.sections" :key="i" class="panel soft">
          <div class="row wrap gap-8" style="align-items:center">
            <input class="input grow" v-model="sec.title" placeholder="Название раздела" />
            <select class="select" style="max-width:220px" v-model="sec.kind"><option v-for="[k, l] in KINDS" :key="k" :value="k">{{ l }}</option></select>
            <select class="select" style="max-width:160px" v-model="sec.layout"><option v-for="[k, l] in LAYOUTS" :key="k" :value="k">{{ l }}</option></select>
            <button class="ibtn sm" title="Выше" @click="moveSection(i, -1)" aria-label="Выше"><Icon name="chevronUp" :size="16" /></button>
            <button class="ibtn sm" title="Ниже" @click="moveSection(i, 1)" aria-label="Ниже"><Icon name="chevronDown" :size="16" /></button>
            <button class="ibtn sm" title="Убрать раздел" @click="editor.sections.splice(i, 1)" aria-label="Убрать раздел"><Icon name="delete" :size="16" /></button>
          </div>
          <input class="input mt-8" v-model="sec.note" placeholder="Пояснение под заголовком (необязательно)" />
          <div class="row wrap gap-8 mt-8" style="align-items:center">
            <template v-if="sec.kind === 'playlist'"><label class="small muted">Плейлист</label><select class="select" v-model="sec.playlistId"><option value="">—</option><option v-for="p in playlists" :key="p.id" :value="p.id">{{ p.title }}</option></select></template>
            <template v-else-if="sec.kind === 'category'"><label class="small muted">Категория</label><select class="select" v-model="sec.categoryId"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></template>
            <template v-else-if="sec.kind === 'tag'"><label class="small muted">Тег</label><input class="input" style="max-width:220px" v-model="sec.tag" placeholder="охрана труда" /></template>
            <template v-if="sec.kind !== 'videos'"><label class="small muted">Показывать</label><input class="input" type="number" min="1" max="48" style="width:90px" v-model.number="sec.maxItems" /></template>
          </div>
          <div v-if="sec.kind === 'videos'" class="mt-8">
            <div class="row wrap gap-4">
              <span v-for="v in sec.videos" :key="v.id" class="chip">{{ v.title }} <button class="ibtn sm" style="width:18px;height:18px" @click="removeVideo(sec, v)" :aria-label="`Убрать из раздела: ${v.title}`"><Icon name="close" :size="12" /></button></span>
              <span v-if="!sec.videos.length" class="small muted">Видео не выбраны — найдите их ниже</span>
            </div>
            <div class="row gap-8 mt-8"><input class="input grow" v-model="search" placeholder="Поиск видео по названию" @keyup.enter="doSearch" /><button class="btn sm" :disabled="searching" @click="doSearch">Найти</button></div>
            <div v-if="found.length" class="row wrap gap-4 mt-8"><button v-for="v in found" :key="v.id" class="chip" @click="addVideo(sec, v)"><Icon name="plus" :size="12" /> {{ v.title }}</button></div>
          </div>
        </div>
        <button class="btn" @click="addSection"><Icon name="plus" :size="16" /> Добавить раздел</button>
      </div>
      <template #footer><button class="btn ghost" @click="editor = null">Отмена</button><button class="btn primary" @click="saveSections">Сохранить разделы</button></template>
    </Modal>
  </div>
</template>
