<script setup>
import { ref, onMounted } from 'vue';
import { patch, del, uploadFile } from '../../api.js';
import { useAuth } from '../../stores/auth.js';
import { useUi } from '../../stores/ui.js';
import ChannelAvatar from '../../components/ChannelAvatar.vue';

const auth = useAuth();
const ui = useUi();
const form = ref({ displayName: '', bio: '', links: [] });
onMounted(() => { form.value = { displayName: auth.user.displayName, bio: auth.user.bio, links: [...(auth.user.links || [])] }; });
async function save() { try { const r = await patch('/api/me', form.value); auth.setUser(r.user); ui.toast('Сохранено', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function upload(kind, e) { const f = e.target.files[0]; if (!f) return; try { await uploadFile(`/api/me/${kind}`, f); await auth.load(); ui.toast('Изображение обновлено', { type: 'success' }); } catch (err) { ui.toast(err.message, { type: 'error' }); } e.target.value = ''; }
async function remove(kind) { await del(`/api/me/${kind}`); await auth.load(); }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Оформление канала</h1><div class="sub">Обложка, аватар и описание — то, что видят коллеги на странице канала</div></div><div class="actions"><router-link :to="`/@${auth.user.handle}`" class="btn">Открыть канал</router-link></div></div>
    <div class="panel mb-24">
      <div class="label mb-8">Обложка (рекомендуется 2048×512)</div>
      <div class="ch-banner" style="border-radius: var(--radius); height: 160px" :style="auth.user.bannerUrl ? { backgroundImage: `url(${auth.user.bannerUrl})` } : {}"></div>
      <div class="row wrap mt-8"><label class="btn sm"><Icon name="upload" :size="16" /> Загрузить обложку<input type="file" accept="image/*" class="hidden" @change="upload('banner', $event)" /></label><button v-if="auth.user.bannerUrl" class="btn ghost sm" @click="remove('banner')">Удалить</button></div>
    </div>
    <div class="panel mb-24">
      <div class="row gap-16"><ChannelAvatar :user="auth.user" size="xl" /><div class="col gap-8"><label class="btn sm"><Icon name="upload" :size="16" /> Загрузить аватар<input type="file" accept="image/*" class="hidden" @change="upload('avatar', $event)" /></label><button v-if="auth.user.avatarUrl" class="btn ghost sm" @click="remove('avatar')">Удалить</button></div></div>
    </div>
    <div class="panel">
      <div class="field mb-16"><label>Название канала</label><input class="input" v-model="form.displayName" maxlength="80" /></div>
      <div class="field mb-16"><label>Описание</label><textarea class="textarea" v-model="form.bio" maxlength="2000" placeholder="Чем занимается подразделение, какие видео публикует…"></textarea></div>
      <div class="field"><label>Ссылки</label><div v-for="(l, i) in form.links" :key="i" class="row mb-8"><input class="input" v-model="l.title" placeholder="Название" style="max-width:200px" /><input class="input" v-model="l.url" placeholder="https://…" /><button class="ibtn" @click="form.links.splice(i, 1)" aria-label="Удалить ссылку"><Icon name="close" :size="18" /></button></div><button class="btn ghost sm" @click="form.links.push({ title: '', url: '' })"><Icon name="plus" :size="16" /> Добавить ссылку</button></div>
      <div class="form-actions"><button class="btn primary" @click="save">Сохранить</button></div>
    </div>
  </div>
</template>
