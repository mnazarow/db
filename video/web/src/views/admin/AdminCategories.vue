<script setup>
import { ref, onMounted } from 'vue';
import { get, post, patch, del, put } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import Modal from '../../components/Modal.vue';

const ui = useUi(); const auth = useAuth();
const cats = ref([]);
const editing = ref(null);
const ICONS = ['folder', 'school', 'inventory', 'event', 'campaign', 'menu_book', 'videocam', 'star', 'wrench', 'lan', 'shield', 'accounts', 'chartBar', 'palette', 'help', 'broadcast', 'mic', 'earth'];
async function load() { cats.value = (await get('/api/admin/categories')).categories; }
onMounted(load);
function startNew() { editing.value = { name: '', description: '', icon: 'folder', isActive: true }; }
async function save() {
  try {
    if (editing.value.id) await patch(`/api/admin/categories/${editing.value.id}`, editing.value); else await post('/api/admin/categories', editing.value);
    editing.value = null; await load(); ui.toast('Сохранено', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function remove(c) { if (await ui.ask({ title: `Удалить категорию «${c.name}»?`, message: 'Видео останутся без категории.', okLabel: 'Удалить', danger: true })) { await del(`/api/admin/categories/${c.id}`); load(); } }
async function move(i, d) { const arr = [...cats.value]; const j = i + d; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; cats.value = arr; await put('/api/admin/categories/order', { ids: arr.map((c) => c.id) }); }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Категории</h1><div class="sub">Разделы каталога видео</div></div><div class="actions"><button v-if="auth.isAdmin" class="btn primary" @click="startNew"><Icon name="plus" :size="18" /> Добавить</button></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th></th><th>Категория</th><th>Адрес</th><th class="num">Видео</th><th>Активна</th><th></th></tr></thead><tbody>
      <tr v-for="(c, i) in cats" :key="c.id"><td><Icon :name="c.icon" :size="22" class="muted" /></td><td><b>{{ c.name }}</b><div class="tiny muted">{{ c.description }}</div></td><td class="mono">/category/{{ c.slug }}</td><td class="num">{{ c.videoCount }}</td><td><span class="badge" :class="c.isActive ? 'success' : ''">{{ c.isActive ? 'да' : 'нет' }}</span></td>
        <td class="actions" v-if="auth.isAdmin"><button class="ibtn sm" @click="move(i, -1)" :aria-label="`Выше: ${c.name}`"><Icon name="chevronUp" :size="18" /></button><button class="ibtn sm" @click="move(i, 1)" :aria-label="`Ниже: ${c.name}`"><Icon name="chevronDown" :size="18" /></button><button class="ibtn sm" @click="editing = { ...c }" :aria-label="`Изменить категорию ${c.name}`"><Icon name="edit" :size="18" /></button><button class="ibtn sm" @click="remove(c)" :aria-label="`Удалить категорию ${c.name}`"><Icon name="delete" :size="18" /></button></td><td v-else></td></tr>
    </tbody></table></div>
    <Modal v-if="editing" :title="editing.id ? 'Категория' : 'Новая категория'" narrow @close="editing = null">
      <div class="col gap-12"><div class="field"><label>Название</label><input class="input" v-model="editing.name" /></div><div class="field"><label>Описание</label><input class="input" v-model="editing.description" /></div>
        <div class="field"><label>Иконка</label><div class="row wrap gap-4"><button v-for="ic in ICONS" :key="ic" class="ibtn" :class="{ active: editing.icon === ic }" @click="editing.icon = ic" :aria-label="`Иконка ${ic}`"><Icon :name="ic" :size="20" /></button></div></div>
        <label class="switch"><input type="checkbox" v-model="editing.isActive" /><span class="track"></span><span>Показывать в меню</span></label></div>
      <template #footer><button class="btn" @click="editing = null">Отмена</button><button class="btn primary" @click="save">Сохранить</button></template>
    </Modal>
  </div>
</template>
