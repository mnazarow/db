<script setup>
// Подсказки (карточки по таймкодам) и конечная заставка — как на YouTube.
import { ref, watch, onMounted } from 'vue';
import { get, patch } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDuration, parseTime } from '../../utils/format.js';

const props = defineProps({ video: { type: Object, required: true }, player: { type: Object, default: null } });
const emit = defineEmits(['saved']);
const ui = useUi();
const cards = ref([]);
const endScreen = ref({ seconds: 10, items: [] });
const saving = ref(false);
const newCard = ref({ start: '0:10', type: 'video', target: '', title: '', text: '', duration: 15 });
const newItem = ref({ type: 'video', target: '', title: '' });
const search = ref(''); const results = ref([]); let st = null;
const searchFor = ref('card'); // card | end

function reset() {
  cards.value = (props.video.cards || []).map((c) => ({ ...c }));
  endScreen.value = props.video.endScreen ? { seconds: props.video.endScreen.seconds, items: props.video.endScreen.items.map((i) => ({ ...i })) } : { seconds: 10, items: [] };
}
onMounted(reset);
watch(() => props.video.id, reset);
watch(search, (q) => {
  clearTimeout(st);
  if (q.trim().length < 2) { results.value = []; return; }
  st = setTimeout(async () => {
    try {
      const r = await get(`/api/search?q=${encodeURIComponent(q.trim())}&type=${searchFor.value === 'end' && newItem.value.type === 'playlist' ? 'playlist' : 'video'}&limit=8`);
      results.value = (r.videos || r.playlists || []).filter((x) => x.id !== props.video.id).map((x) => ({ id: x.id, key: x.shortId || x.id, title: x.title }));
    } catch { results.value = []; }
  }, 250);
});
function pick(r) {
  if (searchFor.value === 'card') { newCard.value.target = r.key; if (!newCard.value.title) newCard.value.title = r.title; }
  else { newItem.value.target = r.key; if (!newItem.value.title) newItem.value.title = r.title; }
  search.value = ''; results.value = [];
}
function addCard() {
  const start = parseTime(newCard.value.start);
  if (start === null) return ui.toast('Укажите время появления', { type: 'error' });
  if (!newCard.value.target.trim()) return ui.toast(newCard.value.type === 'url' ? 'Укажите ссылку' : 'Выберите видео или плейлист', { type: 'error' });
  if (newCard.value.type === 'url' && !/^https?:\/\//i.test(newCard.value.target)) return ui.toast('Ссылка должна начинаться с http(s)://', { type: 'error' });
  cards.value.push({ ...newCard.value, start, id: Math.random().toString(36).slice(2, 10) });
  cards.value.sort((a, b) => a.start - b.start);
  newCard.value = { start: fmtDuration(Math.floor(props.player?.currentTime() || 0)), type: 'video', target: '', title: '', text: '', duration: 15 };
}
function addItem() {
  if (endScreen.value.items.length >= 4) return ui.toast('Не больше четырёх элементов', { type: 'error' });
  if (['video', 'playlist', 'url'].includes(newItem.value.type) && !newItem.value.target.trim()) return ui.toast('Укажите цель', { type: 'error' });
  if (newItem.value.type === 'url' && !/^https?:\/\//i.test(newItem.value.target.trim())) return ui.toast('Ссылка должна начинаться с http(s)://', { type: 'error' });
  endScreen.value.items.push({ ...newItem.value });
  newItem.value = { type: 'video', target: '', title: '' };
}
async function save() {
  saving.value = true;
  try {
    const r = await patch(`/api/videos/${props.video.id}`, { cards: cards.value, endScreen: endScreen.value.items.length ? endScreen.value : null });
    emit('saved', r.video); reset(); ui.toast('Подсказки сохранены', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
const TYPES = { video: 'Видео', playlist: 'Плейлист', url: 'Ссылка' };
const END_TYPES = { video: 'Видео', playlist: 'Плейлист', latest: 'Последнее видео канала', subscribe: 'Кнопка «Подписаться»', url: 'Ссылка' };
</script>

<template>
  <div class="col gap-16">
    <section class="panel" style="margin:0">
      <h4 class="mb-4"><Icon name="cards" :size="18" style="vertical-align:-3px" /> Подсказки</h4>
      <p class="small muted">Небольшая карточка появляется в углу плеера в заданный момент — ссылка на другое видео, плейлист или документ.</p>
      <div class="cards-form mt-8">
        <div class="field"><label>Время</label><div class="row gap-4"><input class="input sm" style="width:90px" v-model="newCard.start" /><button class="ibtn sm" title="Текущее время плеера" @click="newCard.start = fmtDuration(Math.floor(player?.currentTime() || 0))" aria-label="Текущее время плеера"><Icon name="timer" :size="16" /></button></div></div>
        <div class="field"><label>Тип</label><select class="select sm" v-model="newCard.type"><option v-for="(l, k) in TYPES" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="field grow" style="position:relative"><label>{{ newCard.type === 'url' ? 'Ссылка' : 'Цель' }}</label>
          <input v-if="newCard.type === 'url'" class="input sm" v-model="newCard.target" placeholder="https://…" />
          <template v-else><input class="input sm" v-model="search" :placeholder="newCard.target ? `Выбрано: ${newCard.target}` : 'Найти по названию…'" @focus="searchFor = 'card'" />
            <div v-if="results.length && searchFor === 'card'" class="menu" style="left:0;right:0;top:100%;z-index:5"><button v-for="r in results" :key="r.id" class="item" @click="pick(r)">{{ r.title }}</button></div></template>
        </div>
        <div class="field grow"><label>Заголовок</label><input class="input sm" v-model="newCard.title" maxlength="100" placeholder="Смотрите также" /></div>
        <div class="field"><label>Показывать, с</label><input class="input sm" type="number" style="width:80px" v-model.number="newCard.duration" min="5" max="60" /></div>
        <div class="field" style="justify-content:flex-end"><button class="btn primary sm" @click="addCard"><Icon name="plus" :size="16" /> Добавить</button></div>
      </div>
      <div v-if="cards.length" class="table-wrap mt-8"><table class="table"><thead><tr><th>Время</th><th>Тип</th><th>Цель</th><th>Заголовок</th><th></th></tr></thead><tbody>
        <tr v-for="(c, i) in cards" :key="c.id"><td class="mono"><a href="#" @click.prevent="player?.seekTo(c.start)">{{ fmtDuration(c.start) }}</a></td><td>{{ TYPES[c.type] }}</td><td class="small mono ellipsis" style="max-width:220px">{{ c.target }}</td><td><input class="input sm" v-model="c.title" /></td><td class="actions"><button class="ibtn sm" @click="cards.splice(i, 1)" aria-label="Удалить подсказку"><Icon name="close" :size="16" /></button></td></tr>
      </tbody></table></div>
      <p v-else class="small muted mt-8">Подсказок пока нет.</p>
    </section>

    <section class="panel" style="margin:0">
      <h4 class="mb-4"><Icon name="endScreen" :size="18" style="vertical-align:-3px" /> Конечная заставка</h4>
      <p class="small muted">В последние секунды видео поверх плеера показываются до четырёх плиток: другие видео, плейлист, кнопка подписки или ссылка.</p>
      <div class="row wrap gap-8 mt-8" style="align-items:flex-end">
        <div class="field"><label>За сколько секунд до конца</label><input class="input sm" type="number" style="width:90px" v-model.number="endScreen.seconds" min="5" max="30" /></div>
        <div class="field"><label>Элемент</label><select class="select sm" v-model="newItem.type"><option v-for="(l, k) in END_TYPES" :key="k" :value="k">{{ l }}</option></select></div>
        <div v-if="['video', 'playlist'].includes(newItem.type)" class="field grow" style="position:relative; min-width:220px"><label>Цель</label><input class="input sm" v-model="search" :placeholder="newItem.target ? `Выбрано: ${newItem.target}` : 'Найти по названию…'" @focus="searchFor = 'end'" />
          <div v-if="results.length && searchFor === 'end'" class="menu" style="left:0;right:0;top:100%;z-index:5"><button v-for="r in results" :key="r.id" class="item" @click="pick(r)">{{ r.title }}</button></div></div>
        <div v-else-if="newItem.type === 'url'" class="field grow"><label>Ссылка</label><input class="input sm" v-model="newItem.target" placeholder="https://…" /></div>
        <div class="field grow"><label>Подпись</label><input class="input sm" v-model="newItem.title" maxlength="100" /></div>
        <button class="btn primary sm" @click="addItem"><Icon name="plus" :size="16" /> Добавить</button>
      </div>
      <div v-if="endScreen.items.length" class="row wrap gap-8 mt-8"><span v-for="(it, i) in endScreen.items" :key="i" class="chip"><Icon :name="it.type === 'subscribe' ? 'bell' : it.type === 'url' ? 'link' : it.type === 'playlist' ? 'playlist' : 'play'" :size="14" /> {{ it.title || END_TYPES[it.type] }}<button class="ibtn sm" style="width:20px;height:20px" @click="endScreen.items.splice(i, 1)" aria-label="Убрать элемент заставки"><Icon name="close" :size="14" /></button></span></div>
    </section>
    <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save">{{ saving ? 'Сохранение…' : 'Сохранить подсказки и заставку' }}</button></div>
  </div>
</template>

<style>
.cards-form { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: flex-end; }
.cards-form .field { margin: 0; }
</style>
