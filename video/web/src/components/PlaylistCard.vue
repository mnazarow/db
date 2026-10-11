<script setup>
import { VISIBILITY } from '../utils/format.js';
defineProps({ playlist: { type: Object, required: true }, showOwner: { type: Boolean, default: true } });
</script>

<template>
  <article class="vcard">
    <router-link :to="`/playlist/${playlist.id}`" class="thumb pl-thumb">
      <img v-if="playlist.thumbnailUrl" :src="playlist.thumbnailUrl" :alt="playlist.title" loading="lazy" />
      <div v-else class="thumb-empty"><Icon name="playlist" :size="32" /></div>
      <div class="pl-count"><Icon name="playlist" :size="20" /><span>{{ playlist.itemCount }}</span></div>
    </router-link>
    <div class="vc-body">
      <div class="vc-text">
        <router-link :to="`/playlist/${playlist.id}`" class="vc-title clamp-2">{{ playlist.kind === 'watch_later' ? 'Смотреть позже' : playlist.title }}</router-link>
        <div class="vc-meta">
          <router-link v-if="showOwner && playlist.owner?.handle" :to="`/@${playlist.owner.handle}`" class="vc-channel">{{ playlist.owner.displayName }}</router-link>
          <span>{{ VISIBILITY[playlist.visibility]?.label }} • {{ playlist.itemCount }} видео</span>
        </div>
      </div>
    </div>
  </article>
</template>

<style>
.pl-count { position: absolute; right: 0; top: 0; bottom: 0; width: 38%; background: rgba(15, 67, 130, 0.82); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; font-weight: 500; }
</style>
