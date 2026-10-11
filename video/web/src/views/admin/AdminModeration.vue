<script setup>
import { ref } from 'vue';
import { post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { usePaged } from '../../utils/paged.js';
import Pagination from '../../components/Pagination.vue';
import EmptyState from '../../components/EmptyState.vue';
import Modal from '../../components/Modal.vue';
import VideoPlayer from '../../components/player/VideoPlayer.vue';
import { fmtDuration, fmtDateTime, VISIBILITY } from '../../utils/format.js';
import ListError from '../../components/ListError.vue';

const ui = useUi();
const emit = defineEmits(['refresh']);
const list = usePaged('/api/admin/videos', { limit: 20, extraQuery: () => ({ moderation: 'pending', status: 'ready' }) });
const rejectDlg = ref(null); const note = ref('');
const preview = ref(null);
async function approve(v) { await post(`/api/admin/videos/${v.id}/approve`, {}); ui.toast('Видео одобрено', { type: 'success' }); list.load(); emit('refresh'); }
async function reject() { await post(`/api/admin/videos/${rejectDlg.value.id}/reject`, { note: note.value }); rejectDlg.value = null; note.value = ''; ui.toast('Видео отклонено'); list.load(); emit('refresh'); }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Модерация</h1><div class="sub">Видео, ожидающие проверки перед публикацией</div></div></div>
    <ListError v-if="list.error.value" :error="list.error.value" @retry="list.load()" />
    <EmptyState v-else-if="!list.loading.value && !list.items.value.length" icon="check" title="Очередь пуста" text="Все загруженные видео проверены." />
    <div class="col gap-16">
      <div v-for="v in list.items.value" :key="v.id" class="panel mod-card">
        <div class="mod-preview" @click="preview = v"><img v-if="v.thumbnailUrl" :src="v.thumbnailUrl" alt="" /><span class="dur">{{ fmtDuration(v.duration) }}</span><span class="play-ov"><Icon name="play" :size="36" /></span></div>
        <div class="grow" style="min-width:0">
          <h3 style="font-family:var(--font-body);text-transform:none;font-size:18px;letter-spacing:0">{{ v.title }}</h3>
          <div class="small muted mt-8"><router-link :to="`/admin/users/${v.owner.id}`">{{ v.owner.displayName }}</router-link> ({{ v.ownerEmail }}) • {{ fmtDateTime(v.createdAt) }} • {{ VISIBILITY[v.visibility]?.label }}</div>
          <p class="small clamp-3 mt-8" style="white-space: pre-wrap">{{ v.description || 'Без описания' }}</p>
          <div class="row wrap gap-4"><span v-for="t in v.tags" :key="t" class="badge">#{{ t }}</span></div>
        </div>
        <div class="col gap-8"><button class="btn primary" @click="approve(v)"><Icon name="check" :size="18" /> Одобрить</button><button class="btn danger" @click="rejectDlg = v"><Icon name="cancel" :size="18" /> Отклонить</button><router-link :to="`/studio/videos/${v.id}`" class="btn ghost sm">Редактировать</router-link></div>
      </div>
    </div>
    <Pagination :page="list.page.value" :total="list.total.value" :limit="20" @change="list.setPage" />
    <Modal v-if="rejectDlg" title="Отклонить видео" narrow @close="rejectDlg = null"><p class="small">{{ rejectDlg.title }}</p><div class="field"><label>Причина для автора</label><textarea class="textarea" v-model="note"></textarea></div><template #footer><button class="btn" @click="rejectDlg = null">Отмена</button><button class="btn danger primary" @click="reject">Отклонить</button></template></Modal>
    <Modal v-if="preview" :title="preview.title" wide @close="preview = null"><VideoPlayer :src="$url(`/media/videos/${preview.id}/hls/master.m3u8`)" :poster="preview.thumbnailUrl" :duration="preview.duration" compact :allow-theater="false" :allow-mini="false" autoplay /></Modal>
  </div>
</template>

<style>
.mod-card { display: flex; gap: 20px; align-items: flex-start; }
.mod-preview { position: relative; width: 280px; flex-shrink: 0; aspect-ratio: 16/9; border-radius: var(--radius-sm); overflow: hidden; background: var(--bg-3); cursor: pointer; }
.mod-preview img { width: 100%; height: 100%; object-fit: cover; }
.mod-preview .dur { position: absolute; right: 8px; bottom: 8px; padding: 2px 6px; border-radius: 4px; background: rgba(0,0,0,0.8); color: #fff; font-size: 12px; }
.play-ov { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; background: rgba(0,0,0,0.25); opacity: 0; transition: opacity var(--t-fast); }
.mod-preview:hover .play-ov { opacity: 1; }
@media (max-width: 800px) { .mod-card { flex-direction: column; } .mod-preview { width: 100%; } }
</style>
