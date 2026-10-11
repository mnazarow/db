<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import QRCode from 'qrcode';
import { get, post, patch, del, uploadFile } from '../api.js';
import { useAuth } from '../stores/auth.js';
import { useUi } from '../stores/ui.js';
import ChannelAvatar from '../components/ChannelAvatar.vue';
import Modal from '../components/Modal.vue';
import { fmtDateTime, fmtDateShort } from '../utils/format.js';
import { copyWithToast } from '../utils/clipboard.js';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const tab = computed(() => route.params.tab || 'profile');
const ALL_TABS = [['profile', 'Профиль', 'person'], ['security', 'Безопасность', 'shield'], ['notifications', 'Уведомления', 'bell'], ['playback', 'Воспроизведение и вид', 'play'], ['sessions', 'Сессии', 'desktop'], ['tokens', 'Токены API', 'key']];
// В разделе «Видео» портала документации пароль, второй фактор и сеансы — в портале
const TABS = computed(() => (auth.isPortal ? ALL_TABS.filter(([k]) => k !== 'security' && k !== 'sessions') : ALL_TABS));

// Профиль
const profile = ref({ displayName: '', handle: '', bio: '', links: [] });
onMounted(() => { profile.value = { displayName: auth.user.displayName, handle: auth.user.handle, bio: auth.user.bio, links: [...(auth.user.links || [])] }; });
async function saveProfile() {
  try { const r = await patch('/api/me', profile.value); auth.setUser(r.user); ui.toast('Профиль сохранён', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
async function uploadAvatar(e) {
  const f = e.target.files[0]; if (!f) return;
  try { await uploadFile('/api/me/avatar', f); await auth.load(); ui.toast('Аватар обновлён', { type: 'success' }); } catch (err) { ui.toast(err.message, { type: 'error' }); }
}
async function removeAvatar() { try { await del('/api/me/avatar'); await auth.load(); } catch (e) { ui.toast(e.message, { type: 'error' }); } }

// Безопасность
const pw = ref({ currentPassword: '', newPassword: '', newPassword2: '' });
async function changePassword() {
  if (pw.value.newPassword !== pw.value.newPassword2) return ui.toast('Пароли не совпадают', { type: 'error' });
  try { await post('/api/auth/change-password', pw.value); pw.value = { currentPassword: '', newPassword: '', newPassword2: '' }; ui.toast('Пароль изменён', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const totpSetup = ref(null);
const totpCode = ref('');
const totpQr = ref('');
async function startTotp() { try { totpSetup.value = await post('/api/auth/totp/setup', {}); totpQr.value = await QRCode.toDataURL(totpSetup.value.uri, { width: 200, margin: 1 }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function enableTotp() { try { await post('/api/auth/totp/enable', { code: totpCode.value }); totpSetup.value = null; await auth.load(); ui.toast('Двухфакторная аутентификация включена', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
const disableDlg = ref(false); const disablePass = ref(''); const disableCode = ref('');
async function disableTotp() { try { await post('/api/auth/totp/disable', { password: disablePass.value, code: disableCode.value }); disableDlg.value = false; await auth.load(); ui.toast('Двухфакторная аутентификация отключена'); } catch (e) { ui.toast(e.message, { type: 'error' }); } }

// Уведомления
const notif = ref({ newVideos: true, replies: true, likes: true, moderation: true, email: true, emailNewVideos: false });
onMounted(() => { notif.value = { ...notif.value, ...(auth.user.prefs?.notifications || {}) }; });
async function saveNotif() { try { const r = await patch('/api/me', { prefs: { notifications: notif.value } }); auth.setUser(r.user); ui.toast('Настройки уведомлений сохранены', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
// RSS (1.3)
const feed = ref(null);
async function loadFeed() { try { feed.value = await get('/api/me/feed-token'); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function resetFeed() { try { if (!(await ui.ask({ title: 'Сбросить токен лент?', message: 'Старые адреса лент перестанут работать.', okLabel: 'Сбросить' }))) return; await post('/api/me/feed-token/reset', {}); await loadFeed(); ui.toast('Токен обновлён', { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
// Telegram
const tg = ref(null); const tgLink = ref(null); let tgTimer = null;
async function loadTg() { if (!auth.config?.telegramEnabled) return; try { tg.value = await get('/api/me/telegram'); } catch { tg.value = null; } }
async function linkTg() { try { tgLink.value = await post('/api/me/telegram/link', {}); clearInterval(tgTimer); let polls = 0; tgTimer = setInterval(async () => { if (++polls > 200) { clearInterval(tgTimer); return; } await loadTg(); if (tg.value?.linked) { clearInterval(tgTimer); tgLink.value = null; ui.toast('Telegram привязан', { type: 'success' }); } }, 3000); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function unlinkTg() { try { await del('/api/me/telegram'); tgLink.value = null; await loadTg(); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
onMounted(loadTg);
onBeforeUnmount(() => clearInterval(tgTimer));

// Сессии
const sessions = ref([]);
async function loadSessions() { try { sessions.value = (await get('/api/auth/sessions')).sessions; } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function killSession(s) { try { await del(`/api/auth/sessions/${s.id}`); loadSessions(); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function killOthers() { try { await del('/api/auth/sessions/others'); loadSessions(); ui.toast('Остальные сессии завершены'); } catch (e) { ui.toast(e.message, { type: 'error' }); } }

// Токены
const tokens = ref([]); const newToken = ref(null); const tokenName = ref(''); const tokenDays = ref(0);
async function loadTokens() { try { tokens.value = (await get('/api/me/tokens')).tokens; } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function createToken() { try { newToken.value = await post('/api/me/tokens', { name: tokenName.value, expiresDays: tokenDays.value }); tokenName.value = ''; loadTokens(); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function removeToken(t) { try { if (await ui.ask({ title: 'Удалить токен?', message: `Интеграции, использующие «${t.name}», перестанут работать.`, okLabel: 'Удалить', danger: true })) { await del(`/api/me/tokens/${t.id}`); loadTokens(); } } catch (e) { ui.toast(e.message, { type: 'error' }); } }
watch(tab, (t) => { if (t === 'sessions') loadSessions(); if (t === 'tokens') loadTokens(); }, { immediate: true });
async function copy(t) { await copyWithToast(ui, t); }
</script>

<template>
  <div class="page medium">
    <div class="page-head"><div><h1>Настройки</h1><div class="sub">{{ auth.user.email }}</div></div></div>
    <div class="layout-with-nav">
      <nav class="side-nav">
        <router-link v-for="[k, l, ic] in TABS" :key="k" :to="`/settings/${k}`" :class="{ 'router-link-active': tab === k }"><Icon :name="ic" :size="20" /> {{ l }}</router-link>
      </nav>
      <div>
        <div v-if="tab === 'profile'" class="panel">
          <h3 class="mb-16">Профиль и канал</h3>
          <div class="row gap-16 mb-24">
            <ChannelAvatar :user="auth.user" size="xl" />
            <div class="col gap-8">
              <label class="btn sm"><Icon name="upload" :size="16" /> Загрузить фото<input type="file" accept="image/*" class="hidden" @change="uploadAvatar" /></label>
              <button v-if="auth.user.avatarUrl" class="btn ghost sm" @click="removeAvatar">Удалить</button>
            </div>
          </div>
          <div class="form-grid">
            <div class="field"><label>Имя</label><input class="input" v-model="profile.displayName" maxlength="80" :disabled="auth.isPortal" :title="auth.isPortal ? 'Имя берётся из портала документации' : undefined" /></div>
            <div class="field"><label>Имя канала</label><div class="input-group"><span class="input" style="width: 40px; flex: none; display:flex; align-items:center; color: var(--text-3)">@</span><input class="input" v-model="profile.handle" maxlength="30" style="border-radius: 0 var(--radius-sm) var(--radius-sm) 0" /></div><div class="hint">Адрес канала: /@{{ profile.handle }}</div></div>
          </div>
          <div class="field mt-16"><label>О себе / описание канала</label><textarea class="textarea" v-model="profile.bio" maxlength="2000"></textarea></div>
          <div class="field mt-16"><label>Ссылки</label>
            <div v-for="(l, i) in profile.links" :key="i" class="row mb-8"><input class="input" v-model="l.title" placeholder="Название" style="max-width: 200px" /><input class="input" v-model="l.url" placeholder="https://…" /><button class="ibtn" @click="profile.links.splice(i, 1)" aria-label="Удалить ссылку"><Icon name="close" :size="18" /></button></div>
            <button class="btn ghost sm" @click="profile.links.push({ title: '', url: '' })" :disabled="profile.links.length >= 10"><Icon name="plus" :size="16" /> Добавить ссылку</button>
          </div>
          <div class="form-actions"><button class="btn primary" @click="saveProfile">Сохранить</button><router-link to="/studio/channel" class="btn ghost">Обложка канала →</router-link></div>
        </div>

        <div v-else-if="tab === 'security'" class="col gap-24">
          <div class="panel">
            <h3 class="mb-16">Смена пароля</h3>
            <div v-if="auth.user.authProvider === 'ldap'" class="alert info"><Icon name="info" :size="18" /> Вы входите через доменную учётную запись — пароль меняется в Active Directory.</div>
            <div v-else class="form-grid">
              <div class="field"><label>Текущий пароль</label><input class="input" type="password" v-model="pw.currentPassword" autocomplete="current-password" /></div>
              <div class="field"><label>Новый пароль</label><input class="input" type="password" v-model="pw.newPassword" autocomplete="new-password" /></div>
              <div class="field"><label>Повторите</label><input class="input" type="password" v-model="pw.newPassword2" autocomplete="new-password" /></div>
            </div>
            <div v-if="auth.user.authProvider !== 'ldap'" class="form-actions"><button class="btn primary" @click="changePassword">Изменить пароль</button></div>
          </div>
          <div class="panel" v-if="auth.config?.totpAllowed">
            <h3 class="mb-8">Двухфакторная аутентификация</h3>
            <p class="muted small">Дополнительный код из приложения (Google Authenticator, Яндекс Ключ, Microsoft Authenticator) при каждом входе.</p>
            <div v-if="auth.user.totpEnabled" class="row wrap"><span class="badge success"><Icon name="check" :size="12" /> Включена</span><button class="btn sm danger" @click="disableDlg = true">Отключить</button></div>
            <div v-else-if="!totpSetup"><button class="btn primary sm" @click="startTotp">Включить</button></div>
            <div v-else class="row gap-24 wrap" style="align-items: flex-start">
              <img :src="totpQr" alt="QR" style="border-radius: 12px; border: 1px solid var(--line-2)" />
              <div class="col gap-8 grow">
                <p class="small" style="margin:0">1. Отсканируйте QR-код в приложении или введите ключ вручную:</p>
                <div class="code-box"><span>{{ totpSetup.secret }}</span><button class="ibtn sm" @click="copy(totpSetup.secret)" aria-label="Скопировать секретный ключ"><Icon name="copy" :size="16" /></button></div>
                <p class="small" style="margin:0">2. Введите код из приложения:</p>
                <div class="row"><input class="input" v-model="totpCode" maxlength="6" placeholder="000000" style="width: 140px" /><button class="btn primary sm" @click="enableTotp">Подтвердить</button><button class="btn ghost sm" @click="totpSetup = null">Отмена</button></div>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="tab === 'notifications'" class="panel">
          <h3 class="mb-16">Уведомления</h3>
          <div class="col gap-12">
            <label class="switch"><input type="checkbox" v-model="notif.newVideos" /><span class="track"></span><span>Новые видео на каналах, на которые я подписан</span></label>
            <label class="switch"><input type="checkbox" v-model="notif.replies" /><span class="track"></span><span>Комментарии к моим видео и ответы на мои комментарии</span></label>
            <label class="switch"><input type="checkbox" v-model="notif.moderation" /><span class="track"></span><span>Результаты проверки моих видео</span></label>
            <div v-if="auth.isPortal" class="small muted">Письма и сообщения в Telegram о задачах — назначенных просмотрах, сроках, модерации — присылает {{ auth.portalName }}: почта и Telegram настраиваются в профиле портала («Подписки и уведомления»).</div>
            <div class="divider"></div>
            <label class="switch"><input type="checkbox" v-model="notif.email" /><span class="track"></span><span>Дублировать важные уведомления на почту (ответы, модерация, обработка)</span></label>
            <label class="switch"><input type="checkbox" v-model="notif.emailNewVideos" :disabled="!notif.email" /><span class="track"></span><span>Письма о новых видео из подписок</span></label>
            <label class="switch"><input type="checkbox" :checked="notif.digest !== false" :disabled="!notif.email" @change="notif.digest = $event.target.checked" /><span class="track"></span><span>Еженедельный дайджест по почте: новые видео, назначения, ближайшие эфиры (если включён администратором)</span></label>
            <template v-if="auth.config?.telegramEnabled">
              <div class="divider"></div>
              <div class="row wrap gap-12" style="align-items:center">
                <div class="grow"><b>Telegram</b><div class="small muted">Назначения, напоминания, начало эфиров и другие уведомления — в личные сообщения бота <span v-if="auth.config.telegramBot" class="mono">@{{ auth.config.telegramBot }}</span>.</div></div>
                <template v-if="tg?.linked"><span class="badge success"><Icon name="check" :size="12" /> привязан{{ tg.username ? ' · @' + tg.username : '' }}</span><button class="btn ghost sm" @click="unlinkTg">Отвязать</button></template>
                <button v-else class="btn primary sm" @click="linkTg"><Icon name="send" :size="16" /> Привязать Telegram</button>
              </div>
              <div v-if="tgLink && !tg?.linked" class="alert info"><Icon name="info" :size="20" /><span>Откройте бота и нажмите «Start» — <a :href="tgLink.url" target="_blank" rel="noopener">{{ tgLink.url }}</a><br><span class="small">Или отправьте боту команду <span class="mono">/start {{ tgLink.code }}</span>. Ожидаем подтверждение…</span></span></div>
              <label v-if="tg?.linked" class="switch"><input type="checkbox" :checked="notif.telegram !== false" @change="notif.telegram = $event.target.checked" /><span class="track"></span><span>Получать уведомления в Telegram</span></label>
            </template>
          </div>
          <div class="form-actions"><button class="btn primary" @click="saveNotif">Сохранить</button></div>
        </div>

        <div v-else-if="tab === 'playback'" class="panel">
          <h3 class="mb-16">Воспроизведение и внешний вид</h3>
          <div class="col gap-12">
            <div class="field"><label>Тема оформления</label><div class="chips"><button class="chip" :class="{ active: ui.theme === 'light' }" @click="ui.setTheme('light')"><Icon name="sun" :size="16" /> Светлая</button><button class="chip" :class="{ active: ui.theme === 'dark' }" @click="ui.setTheme('dark')"><Icon name="moon" :size="16" /> Тёмная</button><button class="chip" :class="{ active: ui.theme === 'system' }" @click="ui.setTheme('system')"><Icon name="desktop" :size="16" /> Как в системе</button></div></div>
            <label class="switch"><input type="checkbox" :checked="ui.autoplay" @change="ui.setAutoplay($event.target.checked)" /><span class="track"></span><span>Автовоспроизведение следующего видео</span></label>
            <label class="switch"><input type="checkbox" :checked="ui.theater" @change="ui.setTheater($event.target.checked)" /><span class="track"></span><span>Широкий режим плеера по умолчанию</span></label>
            <label class="switch"><input type="checkbox" :checked="!!auth.user.prefs?.pauseHistory" @change="patch('/api/me', { prefs: { pauseHistory: $event.target.checked } }).then((r) => auth.setUser(r.user))" /><span class="track"></span><span>Не сохранять историю просмотров</span></label>
            <div class="divider"></div>
            <h4 class="mb-8">Доступность</h4>
            <label class="switch"><input type="checkbox" :checked="ui.highContrast" @change="ui.setHighContrast($event.target.checked)" /><span class="track"></span><span>Повышенная контрастность интерфейса</span></label>
            <p class="small muted" style="margin:0">Размер субтитров и плотность подложки настраиваются в самом плеере: «Настройки → Вид субтитров». Плеер управляется с клавиатуры, порядок обхода элементов соответствует их расположению.</p>
          </div>
          <div class="divider"></div>
          <h4 class="mb-8">Горячие клавиши плеера</h4>
          <div class="keys small muted">
            <div><span class="kbd">Пробел</span> / <span class="kbd">K</span> — пауза</div><div><span class="kbd">J</span> / <span class="kbd">L</span> — ±10 с</div><div><span class="kbd">←</span> / <span class="kbd">→</span> — ±5 с</div><div><span class="kbd">↑</span> / <span class="kbd">↓</span> — громкость</div>
            <div><span class="kbd">M</span> — звук</div><div><span class="kbd">F</span> — во весь экран</div><div><span class="kbd">T</span> — широкий режим</div><div><span class="kbd">I</span> — мини-плеер</div><div><span class="kbd">C</span> — субтитры</div><div><span class="kbd">0–9</span> — перейти к %</div><div><span class="kbd">Shift+,</span> / <span class="kbd">Shift+.</span> — скорость</div><div><span class="kbd">Shift+N</span> — следующее</div>
          </div>
        </div>

        <div v-else-if="tab === 'sessions'" class="panel">
          <div class="row mb-16"><h3>Активные сессии</h3><button class="btn sm" style="margin-left:auto" @click="killOthers">Завершить все, кроме текущей</button></div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Устройство</th><th>IP</th><th>Последняя активность</th><th></th></tr></thead><tbody>
            <tr v-for="s in sessions" :key="s.id"><td><div class="small">{{ s.userAgent }}</div><span v-if="s.current" class="badge success">Текущая</span></td><td class="mono">{{ s.ip }}</td><td>{{ fmtDateTime(s.lastSeenAt) }}</td><td class="actions"><button v-if="!s.current" class="btn ghost sm" @click="killSession(s)">Завершить</button></td></tr>
          </tbody></table></div>
        </div>

        <div v-else-if="tab === 'tokens'" class="panel">
          <h3 class="mb-8">Токены API</h3>
          <p class="muted small">Для загрузки видео из скриптов и интеграций. Передавайте заголовок <span class="kbd">Authorization: Bearer &lt;токен&gt;</span>. Пример: <span class="mono">curl -H "Authorization: Bearer cv_…" -F file=@video.mp4 -F title="Название" {{ auth.config?.baseUrl }}/api/uploads/simple</span></p>
          <div class="row wrap mb-16"><input class="input" v-model="tokenName" placeholder="Название токена" style="max-width: 260px" /><select class="select" v-model="tokenDays" style="max-width: 180px"><option :value="0">Бессрочный</option><option :value="30">30 дней</option><option :value="90">90 дней</option><option :value="365">1 год</option></select><button class="btn primary sm" @click="createToken">Создать</button></div>
          <div v-if="newToken" class="alert success mb-16"><Icon name="key" :size="18" /><div class="grow"><div>Скопируйте токен — он показывается только один раз:</div><div class="code-box mt-8"><span>{{ newToken.token }}</span><button class="ibtn sm" @click="copy(newToken.token)" aria-label="Скопировать токен"><Icon name="copy" :size="16" /></button></div></div></div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Название</th><th>Префикс</th><th>Использован</th><th>Истекает</th><th></th></tr></thead><tbody>
            <tr v-for="t in tokens" :key="t.id"><td>{{ t.name }}</td><td class="mono">{{ t.prefix }}…</td><td>{{ t.lastUsedAt ? fmtDateTime(t.lastUsedAt) : '—' }}</td><td>{{ t.expiresAt ? fmtDateTime(t.expiresAt) : 'никогда' }}</td><td class="actions"><button class="btn ghost sm danger" @click="removeToken(t)">Удалить</button></td></tr>
            <tr v-if="!tokens.length"><td colspan="5" class="muted">Токенов нет</td></tr>
          </tbody></table></div>
          <template v-if="auth.config?.feedsEnabled">
            <div class="divider"></div>
            <h3 class="mb-8"><Icon name="rss" :size="20" style="vertical-align:-4px" /> RSS-ленты и подкасты</h3>
            <p class="muted small">Личные ленты для RSS-читалок и приложений подкастов: ссылки содержат ваш секретный токен, поэтому в них видны и видео «для сотрудников». Токен открывает только ленты и вложенные в них файлы — войти с ним на портал или скачать оригинал нельзя. Не публикуйте эти адреса.</p>
            <div v-if="feed" class="col gap-8">
              <div class="code-box"><span class="small">Подписки: {{ feed.feeds.subscriptions }}</span><button class="ibtn sm" @click="copy(feed.feeds.subscriptions)" aria-label="Скопировать ленту подписок"><Icon name="copy" :size="16" /></button></div>
              <div class="code-box"><span class="small">Новые видео: {{ feed.feeds.latest }}</span><button class="ibtn sm" @click="copy(feed.feeds.latest)" aria-label="Скопировать ленту новых видео"><Icon name="copy" :size="16" /></button></div>
              <div class="code-box"><span class="small"><Icon name="podcast" :size="14" style="vertical-align:-2px" /> Подкаст (только звук): {{ feed.feeds.podcast }}</span><button class="ibtn sm" @click="copy(feed.feeds.podcast)" aria-label="Скопировать подкаст-ленту"><Icon name="copy" :size="16" /></button></div>
              <div class="row wrap gap-8" style="align-items:center"><button class="btn ghost sm" @click="resetFeed">Сбросить токен лент</button><span v-if="feed.expiresAt" class="small muted">Действует до {{ fmtDateShort(feed.expiresAt) }} — затем сбросьте токен и обновите ссылки в читалке</span></div>
            </div>
            <button v-else class="btn sm" @click="loadFeed"><Icon name="rss" :size="16" /> Показать мои ленты</button>
          </template>
        </div>
      </div>
    </div>
    <Modal v-if="disableDlg" title="Отключить 2FA" narrow @close="disableDlg = false">
      <div class="col gap-12">
        <div v-if="auth.user.authProvider === 'local'" class="field"><label>Пароль</label><input class="input" type="password" v-model="disablePass" autocomplete="current-password" /></div>
        <div class="field"><label>{{ auth.user.authProvider === 'local' ? 'или код из приложения' : 'Код из приложения-аутентификатора' }}</label><input class="input" v-model="disableCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" /></div>
      </div>
      <template #footer><button class="btn" @click="disableDlg = false">Отмена</button><button class="btn danger primary" @click="disableTotp">Отключить</button></template>
    </Modal>
  </div>
</template>

<style>
.keys { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }
</style>
