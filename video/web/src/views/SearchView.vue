<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePaged } from '../utils/paged.js';
import VideoGrid from '../components/VideoGrid.vue';
import Pagination from '../components/Pagination.vue';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import PlaylistCard from '../components/PlaylistCard.vue';
import LiveCard from '../components/LiveCard.vue';
import EmptyState from '../components/EmptyState.vue';
import { fmtSubs, fmtNumber } from '../utils/format.js';
import { post } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import ListError from '../components/ListError.vue';

const route = useRoute();
const router = useRouter();
const showFilters = ref(false);
const q = computed(() => String(route.query.q || ''));
const type = computed(() => String(route.query.type || 'video'));
const f = computed(() => ({ sort: route.query.sort || '', date: route.query.date || '', duration: route.query.duration || '', category: route.query.category || '', subtitles: route.query.subtitles || '', hd: route.query.hd || '' }));
// key — функция: вкладка меняется через query, компонент не перемонтируется,
// и вычисленный один раз ключ оставлял «Каналы не найдены» при непустом ответе
const list = usePaged('/api/search', { key: () => (type.value === 'video' ? 'videos' : type.value === 'channel' ? 'channels' : type.value === 'playlist' ? 'playlists' : 'streams'), limit: 20, extraQuery: () => ({ q: q.value, type: type.value, ...f.value }) });
const results = computed(() => list.extra.value || {});
function setQuery(patch) { router.push({ query: { ...route.query, ...patch, page: undefined } }); }
const activeFilters = computed(() => Object.values(f.value).filter(Boolean).length);
watch(type, () => { list.items.value = []; });
const TYPES = [['video', 'Видео'], ['channel', 'Каналы'], ['playlist', 'Плейлисты'], ['live', 'Трансляции']];

// 1.5: «Спросите видеотеку» — ответ ИИ с ссылками на моменты видео
const auth = useAuth();
const ui = useUi();
const ask = ref({ open: false, loading: false, answer: '', sources: [], empty: false, asked: '' });
const canAsk = computed(() => auth.isActive && auth.config?.askEnabled !== false && q.value.trim().length >= 5);
async function askLibrary() {
  if (!canAsk.value || ask.value.loading) return;
  ask.value = { open: true, loading: true, answer: '', sources: [], empty: false, asked: q.value };
  try {
    const r = await post('/api/search/ask', { q: q.value });
    ask.value = { open: true, loading: false, answer: r.answer || '', sources: r.sources || [], empty: !!r.empty, asked: q.value };
  } catch (e) {
    ask.value.loading = false;
    ui.toast(e.message, { type: 'error' });
  }
}
watch(q, () => { ask.value = { open: false, loading: false, answer: '', sources: [], empty: false, asked: '' }; });
</script>

<template>
  <div class="page medium">
    <div class="page-head">
      <div><h1>Поиск</h1><div class="sub" v-if="q">Результаты по запросу «{{ q }}»<span v-if="type === 'video' && results.total !== undefined"> — {{ results.total }}</span></div></div>
      <div class="actions"><button class="btn sm" :class="{ primary: showFilters }" @click="showFilters = !showFilters"><Icon name="filter" :size="16" /> Фильтры<span v-if="activeFilters" class="badge" style="background:#fff;color:var(--brand)">{{ activeFilters }}</span></button></div>
    </div>
    <div class="tabs mb-16">
      <button v-for="[k, l] in TYPES" :key="k" class="tab" :class="{ active: type === k }" @click="setQuery({ type: k })">{{ l }}</button>
    </div>
    <div v-if="type === 'video' && q && auth.isActive && auth.config?.askEnabled !== false" class="panel ask-panel mb-16">
      <div v-if="!ask.open" class="row gap-8" style="align-items:center">
        <Icon name="sparkles" :size="20" style="color:var(--brand)" />
        <div class="grow small">Не нашли нужное? Спросите видеотеку — ИИ ответит по расшифровкам речи и покажет момент в видео.</div>
        <button class="btn sm primary" :disabled="!canAsk" @click="askLibrary">Спросить о «{{ q.length > 30 ? q.slice(0, 30) + '…' : q }}»</button>
      </div>
      <template v-else>
        <div class="row gap-8 mb-8" style="align-items:center">
          <Icon name="sparkles" :size="20" style="color:var(--brand)" />
          <b class="grow">Ответ по видеотеке</b>
          <button class="ibtn sm" title="Скрыть" @click="ask.open = false" aria-label="Скрыть"><Icon name="close" :size="16" /></button>
        </div>
        <div v-if="ask.loading" class="row gap-8" style="align-items:center"><div class="spin"></div><span class="small muted">Читаю расшифровки видео…</span></div>
        <template v-else>
          <p v-if="ask.empty && !ask.answer" class="small muted" style="margin:0">В видеотеке не нашлось ответа на этот вопрос. Попробуйте переформулировать или уточнить термины.</p>
          <p v-else class="ask-answer" style="white-space:pre-line">{{ ask.answer }}</p>
          <div v-if="ask.sources.length" class="col gap-4 mt-8">
            <div class="tiny muted">Источники — нажмите, чтобы открыть видео с нужной секунды:</div>
            <router-link v-for="src in ask.sources" :key="src.n + src.shortId" class="ask-src" :to="src.url">
              <span class="ask-num">[{{ src.n }}]</span>
              <span class="grow ellipsis">{{ src.title }}</span>
              <span class="tiny muted nowrap">{{ src.kind === 'screen' ? 'текст на экране' : 'расшифровка' }} · {{ src.timeLabel }}</span>
            </router-link>
          </div>
          <div class="tiny muted mt-8">Ответ составлен ИИ по фрагментам видео — проверяйте по источникам.</div>
        </template>
      </template>
    </div>

    <div v-if="showFilters && type === 'video'" class="panel soft mb-24 filters">
      <div class="form-grid">
        <div class="field"><label>Сортировка</label><select class="select" :value="f.sort" @change="setQuery({ sort: $event.target.value })"><option value="">По релевантности</option><option value="date">По дате</option><option value="views">По просмотрам</option><option value="rating">По оценкам</option></select></div>
        <div class="field"><label>Дата загрузки</label><select class="select" :value="f.date" @change="setQuery({ date: $event.target.value })"><option value="">Любая</option><option value="hour">Последний час</option><option value="day">Сегодня</option><option value="week">Неделя</option><option value="month">Месяц</option><option value="year">Год</option></select></div>
        <div class="field"><label>Длительность</label><select class="select" :value="f.duration" @change="setQuery({ duration: $event.target.value })"><option value="">Любая</option><option value="short">До 4 минут</option><option value="medium">4–20 минут</option><option value="long">Более 20 минут</option></select></div>
        <div class="field"><label>Особенности</label><div class="row wrap" style="height: 42px"><label class="check"><input type="checkbox" :checked="f.subtitles === '1'" @change="setQuery({ subtitles: $event.target.checked ? '1' : '' })" /> Субтитры</label><label class="check"><input type="checkbox" :checked="f.hd === '1'" @change="setQuery({ hd: $event.target.checked ? '1' : '' })" /> HD</label></div></div>
      </div>
      <button v-if="activeFilters" class="btn ghost sm mt-8" @click="setQuery({ sort: '', date: '', duration: '', category: '', subtitles: '', hd: '' })"><Icon name="close" :size="16" /> Сбросить фильтры</button>
    </div>

    <div v-if="list.loading.value" class="loading-block"><div class="spin"></div></div>
    <template v-else-if="type === 'video'">
      <VideoGrid :error="list.error.value" @retry="list.load()" :videos="list.items.value" layout="list" :search-query="q" empty-icon="search" :empty-title="q ? 'Ничего не найдено' : 'Введите запрос'" :empty-text="q ? 'Попробуйте изменить запрос или снять фильтры. Поиск ведётся по названию, тегам, описанию и расшифровке речи.' : ''" />
      <Pagination :page="list.page.value" :total="list.total.value" :limit="20" @change="list.setPage" />
    </template>
    <template v-else-if="type === 'channel'">
      <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
      <EmptyState v-else-if="!list.items.value.length" icon="accounts" title="Каналы не найдены" />
      <div v-else class="col gap-8">
        <router-link v-for="c in list.items.value" :key="c.id" :to="`/@${c.handle}`" class="channel-row card">
          <ChannelAvatar :user="c" size="xl" />
          <div class="grow"><div class="h3" style="text-transform:none; font-family: var(--font-body); font-size: 18px">{{ c.displayName }}</div><div class="small muted">@{{ c.handle }} • {{ fmtSubs(c.subscriberCount) }} • {{ c.videoCount }} видео</div><p v-if="c.bio" class="small muted clamp-2" style="margin: 6px 0 0">{{ c.bio }}</p></div>
        </router-link>
      </div>
      <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
    </template>
    <template v-else-if="type === 'playlist'">
      <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
      <EmptyState v-else-if="!list.items.value.length" icon="playlist" title="Плейлисты не найдены" />
      <div v-else class="video-grid"><PlaylistCard v-for="p in list.items.value" :key="p.id" :playlist="p" /></div>
      <Pagination :page="list.page.value" :has-more="list.hasMore.value" @change="list.setPage" />
    </template>
    <template v-else>
      <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
      <EmptyState v-else-if="!list.items.value.length" icon="live" title="Трансляции не найдены" />
      <div v-else class="video-grid"><LiveCard v-for="s in list.items.value" :key="s.id" :stream="s" /></div>
    </template>
  </div>
</template>

<style scoped>
.ask-panel { border-left: 3px solid var(--brand); }
.ask-answer { margin: 0; font-size: 15px; line-height: 1.5; }
.ask-src { display: flex; gap: 8px; align-items: center; padding: 6px 8px; border-radius: 8px; color: inherit; text-decoration: none; }
.ask-src:hover { background: var(--surface-2); }
.ask-num { color: var(--brand); font-weight: 600; }
</style>

<style>
.channel-row { display: flex; gap: 20px; align-items: center; color: var(--text); }
.channel-row:hover { border-color: var(--brand-300); }
</style>
