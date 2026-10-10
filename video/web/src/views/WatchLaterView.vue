<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { get } from '../api.js';
import { useAuth } from '../stores/auth.js';
const router = useRouter();
const auth = useAuth();
onMounted(async () => {
  let id = auth.watchLaterId;
  if (!id) { const r = await get('/api/playlists'); id = r.playlists.find((p) => p.kind === 'watch_later')?.id; }
  if (id) router.replace(`/playlist/${id}`);
});
</script>
<template><div class="page"><div class="loading-block"><div class="spin"></div></div></div></template>
