<script setup>
import { ref, onMounted } from 'vue';
import { get, post, del } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { fmtDateTime, fmtNumber, VISIBILITY } from '../../utils/format.js';
const ui = useUi();
const streams = ref([]);
async function load() { streams.value = (await get('/api/admin/live')).streams; }
onMounted(load);
async function end(s) { if (await ui.ask({ title: 'Завершить эфир?', message: s.title, okLabel: 'Завершить', danger: true })) { await post(`/api/admin/live/${s.id}/end`, {}); load(); } }
async function remove(s) { if (await ui.ask({ title: 'Удалить трансляцию?', message: s.title, okLabel: 'Удалить', danger: true })) { await del(`/api/admin/live/${s.id}`); load(); } }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Трансляции</h1><div class="sub">Все трансляции портала</div></div><div class="actions"><button class="btn sm" @click="load"><Icon name="refresh" :size="16" /> Обновить</button></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Трансляция</th><th>Автор</th><th>Статус</th><th>Видимость</th><th class="num">Зрители</th><th>Дата</th><th></th></tr></thead><tbody>
      <tr v-for="s in streams" :key="s.id"><td><router-link :to="`/live/${s.shortId}`" style="color:var(--text);font-weight:500">{{ s.title }}</router-link></td><td class="small">{{ s.owner.displayName }}<div class="tiny muted">{{ s.ownerEmail }}</div></td><td><span class="badge" :class="s.status === 'live' ? 'live' : s.status === 'idle' ? 'brand' : ''">{{ { idle: 'Ожидание', live: 'В эфире', ended: 'Завершена' }[s.status] }}</span></td><td class="small">{{ VISIBILITY[s.visibility]?.label }}</td><td class="num">{{ s.status === 'live' ? fmtNumber(s.viewerCount) : `пик ${s.viewerPeak}` }}</td><td class="small nowrap">{{ fmtDateTime(s.startedAt || s.scheduledAt || s.createdAt) }}</td><td class="actions"><button v-if="s.status === 'live'" class="btn sm danger" @click="end(s)">Завершить</button> <button class="ibtn sm" @click="remove(s)" :aria-label="`Удалить трансляцию ${s.title}`"><Icon name="delete" :size="18" /></button></td></tr>
      <tr v-if="!streams.length"><td colspan="7" class="muted">Трансляций нет</td></tr>
    </tbody></table></div>
  </div>
</template>
