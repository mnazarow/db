<script setup>
import { computed } from 'vue';
import { fmtNumber, timeAgo, fmtDateTime } from '../utils/format.js';
import ChannelAvatar from './ChannelAvatar.vue';

const props = defineProps({ stream: { type: Object, required: true } });
const thumb = computed(() => props.stream.thumbnailUrl ? `${props.stream.thumbnailUrl}?t=${Date.parse(props.stream.startedAt || props.stream.createdAt) || 0}` : null);
const to = computed(() => props.stream.status === 'ended' && props.stream.recordingShortId ? `/watch/${props.stream.recordingShortId}` : `/live/${props.stream.shortId}`);
</script>

<template>
  <article class="vcard">
    <router-link :to="to" class="thumb live-thumb">
      <img v-if="thumb" :src="thumb" :alt="stream.title" loading="lazy" />
      <div v-else class="thumb-empty live-empty"><Icon name="broadcast" :size="36" /></div>
      <span v-if="stream.status === 'live'" class="badge live tag"><Icon name="live" :size="12" /> В эфире</span>
      <span v-else-if="stream.status === 'idle'" class="badge tag brand">{{ stream.scheduledAt ? 'Запланирован' : 'Ожидание' }}</span>
      <span v-else class="badge tag">Завершён</span>
      <span v-if="stream.status === 'live'" class="dur"><Icon name="eye" :size="12" /> {{ fmtNumber(stream.viewerCount) }}</span>
    </router-link>
    <div class="vc-body">
      <router-link v-if="stream.owner?.handle" :to="`/@${stream.owner.handle}`" class="vc-avatar"><ChannelAvatar :user="stream.owner" /></router-link>
      <div class="vc-text">
        <router-link :to="to" class="vc-title clamp-2">{{ stream.title }}</router-link>
        <div class="vc-meta">
          <router-link v-if="stream.owner?.handle" :to="`/@${stream.owner.handle}`" class="vc-channel ellipsis">{{ stream.owner.displayName }}</router-link>
          <div v-if="stream.status === 'live'">Начало {{ timeAgo(stream.startedAt) }}</div>
          <div v-else-if="stream.status === 'idle' && stream.scheduledAt">{{ fmtDateTime(stream.scheduledAt) }}</div>
          <div v-else-if="stream.status === 'ended'">Завершён {{ timeAgo(stream.endedAt) }} • {{ fmtNumber(stream.viewerPeak) }} зрителей</div>
        </div>
      </div>
    </div>
  </article>
</template>

<style>
.live-empty { background: linear-gradient(135deg, var(--brand-600), var(--brand-300)); color: rgba(255,255,255,0.9); }
.live-thumb .dur { display: inline-flex; align-items: center; gap: 4px; }
</style>
