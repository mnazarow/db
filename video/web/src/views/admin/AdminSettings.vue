<script setup>
import { fmtBytes } from '../../utils/format.js';
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get, put, post, del, uploadFile } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import WebhooksPanel from '../../components/admin/WebhooksPanel.vue';

const route = useRoute();
const ui = useUi();
const auth = useAuth();
const s = ref(null);
const saving = ref(false);
const testing = ref('');
const testResult = ref(null);
const tab = computed(() => route.params.tab || 'general');
const TABS = [['general', 'Общие', 'settings'], ['registration', 'Регистрация и безопасность', 'shield'], ['upload', 'Загрузка и обработка', 'upload'], ['comments', 'Комментарии', 'comment'], ['smtp', 'Почта (SMTP)', 'mail'], ['ldap', 'Active Directory / LDAP', 'lan'], ['sso', 'Вход через SSO (OIDC)', 'key'], ['asr', 'Автосубтитры (ASR)', 'robot'], ['ai', 'ИИ-помощник', 'sparkles'], ['learning', 'Обучение и аналитика', 'assignment'], ['engage', 'Курсы и вовлечение', 'school'], ['integrations', 'Telegram и RAG', 'send'], ['webhooks', 'Вебхуки и xAPI', 'webhook'], ['tools', 'Редактор, OCR, ленты', 'scissors'], ['live', 'Трансляции', 'broadcast'], ['branding', 'Оформление', 'palette'], ['retention', 'Хранение данных', 'database']];

const loadError = ref('');
async function load() {
  try { const r = await get('/api/admin/settings'); s.value = r.settings; loadError.value = ''; }
  catch (e) { loadError.value = e.message || 'Не удалось загрузить настройки'; ui.toast(loadError.value, { type: 'error' }); }
}
onMounted(load);
async function save(keys) {
  saving.value = true;
  try {
    const entries = {};
    for (const k of keys) entries[k] = s.value[k];
    const r = await put('/api/admin/settings', { settings: entries });
    s.value = r.settings; await auth.refreshConfig();
    ui.toast('Настройки сохранены', { type: 'success' });
  } catch (e) { ui.toast(e.message, { type: 'error' }); } finally { saving.value = false; }
}
const prefix = (p) => Object.keys(s.value).filter((k) => k.startsWith(p));
async function test(kind, body = {}) {
  testing.value = kind; testResult.value = null;
  try {
    const keys = { smtp: prefix('smtp.'), ldap: prefix('ldap.'), asr: prefix('asr.'), ai: prefix('ai.'), oidc: prefix('oidc.'), telegram: prefix('telegram.'), rag: prefix('rag.'), xapi: prefix('xapi.') }[kind] || [];
    const entries = {}; for (const k of keys) entries[k] = s.value[k];
    const r = await post(`/api/admin/settings/test-${kind}`, { settings: entries, ...body });
    testResult.value = { ok: true, text: kind === 'smtp' ? 'Письмо отправлено — проверьте почту.' : kind === 'ldap' ? `Подключение успешно (найдено записей: ${r.entries})` : kind === 'ai' ? `Модель ответила: «${r.answer}»` : kind === 'oidc' ? `Провайдер найден: ${r.issuer}. Авторизация: ${r.authorization}. Redirect URI для регистрации клиента: ${r.redirectUri}` : kind === 'telegram' ? `Бот @${r.username} (${r.name}) отвечает; привязано пользователей: ${r.linked}` : kind === 'rag' ? `RAG приняла тестовый документ (HTTP ${r.status})` : kind === 'xapi' ? `LRS приняла тестовое выражение (HTTP ${r.status})` : kind === 'ocr' ? `tesseract ${r.version}; языки: ${r.languages.join(', ')}` : `Сервер ответил ${r.status}` };
    // Проверка не сохраняет форму: значения применятся только после «Сохранить»
    if (kind === 'telegram' && r.username) s.value['telegram.bot_username'] = r.username;
    if (kind !== 'ocr') testResult.value.text += ' Проверены значения из формы — чтобы применить их, нажмите «Сохранить».';
  } catch (e) { testResult.value = { ok: false, text: e.message }; } finally { testing.value = ''; }
}
const ragStatus = ref(null);
async function ragAction(kind) {
  try {
    if (kind === 'status') ragStatus.value = await get('/api/admin/rag/status');
    else if (kind === 'push' || kind === 'push-force') { const r = await post('/api/admin/rag/push-all', { force: kind === 'push-force' }); ui.toast(`Поставлено в очередь: ${r.queued}`, { type: 'success' }); ragStatus.value = await get('/api/admin/rag/status'); }
    else if (kind === 'describe') { if (!(await ui.ask({ title: 'Описать все видео через ИИ?', message: 'Для всех видео с транскриптом и пустым описанием будут созданы конспект, теги и главы; описание и теги заполнятся автоматически (включается «Автоописание» в настройках ИИ).', okLabel: 'Запустить' }))) return; const r = await post('/api/admin/rag/describe-all', {}); ui.toast(`Поставлено в очередь: ${r.queued}`, { type: 'success' }); await load(); ragStatus.value = await get('/api/admin/rag/status'); }
  } catch (e) { ui.toast(e.message, { type: 'error' }); }
}
const sysResult = ref('');
async function sysAction(kind) {
  sysResult.value = '';
  try {
    if (kind === 'audio') { const r = await post('/api/admin/system/audio-backfill', {}); sysResult.value = `Аудиодорожки поставлены в очередь: ${r.queued}`; }
    else if (kind === 'ocr-all') { if (!(await ui.ask({ title: 'Распознать текст во всех видео?', message: 'Для всех обработанных видео без OCR будут созданы задания распознавания (низкий приоритет).', okLabel: 'Запустить' }))) return; const r = await post('/api/admin/system/ocr-all', {}); sysResult.value = `Поставлено в очередь: ${r.queued}`; }
    else if (kind === 'digest') { const r = await post('/api/admin/system/digest-test', {}); sysResult.value = r.sent ? 'Пробный дайджест отправлен на вашу почту' : 'Дайджест не отправлен (нет новых видео и назначений?)'; }
    else if (kind === 'watch') { const r = await post('/api/admin/system/watch-scan', {}); sysResult.value = `Файлов в папке: ${r.scanned || 0}, импортировано: ${r.imported || 0}${r.error ? ' — ' + r.error : ''}`; watchLog.value = r.log || []; }
  } catch (e) { sysResult.value = e.message; }
}
const watchLog = ref([]);
const xapi = ref(null);
async function loadXapi() { xapi.value = await get('/api/admin/xapi/statements?limit=50'); }
async function retryXapi(x) { await post(`/api/admin/xapi/statements/${x.id}/retry`, {}); ui.toast('Поставлено в очередь'); setTimeout(loadXapi, 2000); }
const ldapLogin = ref({ login: '', password: '' }); const ldapUser = ref(null);
async function testLdapLogin() { testing.value = 'ldap-login'; ldapUser.value = null; try { const settings = {}; for (const k of prefix('ldap.')) settings[k] = s.value[k]; const r = await post('/api/admin/settings/test-ldap-login', { ...ldapLogin.value, settings }); ldapUser.value = r.user; } catch (e) { ui.toast(e.message, { type: 'error', timeout: 8000 }); } finally { testing.value = ''; } }
async function uploadBrand(kind, e) { const f = e.target.files[0]; if (!f) return; try { await uploadFile(`/api/admin/branding/${kind}`, f); await load(); await auth.refreshConfig(); ui.toast('Загружено', { type: 'success' }); } catch (err) { ui.toast(err.message, { type: 'error' }); } e.target.value = ''; }
async function removeBrand(kind) { await del(`/api/admin/branding/${kind}`); await load(); await auth.refreshConfig(); }
const qualities = [2160, 1440, 1080, 720, 480, 360, 240];
function toggleQuality(q) { const arr = s.value['transcode.qualities'] || []; s.value['transcode.qualities'] = arr.includes(q) ? arr.filter((x) => x !== q) : [...arr, q].sort((a, b) => b - a); }
const listField = (key) => ({ get: () => (s.value[key] || []).join(', '), set: (v) => { s.value[key] = v.split(/[\n,]+/).map((x) => x.trim()).filter(Boolean); } });
const allowedExt = computed(listField('upload.allowed_extensions'));
const blockedWords = computed({ get: () => (s.value['comments.blocked_words'] || []).join('\n'), set: (v) => { s.value['comments.blocked_words'] = v.split(/\n+/).map((x) => x.trim()).filter(Boolean); } });
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Настройки</h1><div class="sub">Параметры портала. Изменения применяются сразу.</div></div></div>
    <div v-if="auth.isPortal" class="panel mb-16"><p><b>Раздел «Видео» портала документации.</b> Вход, регистрация, домен (LDAP), SSO и второй фактор настраиваются в портале документации; загружают видео маркетологи и их помощники (видео помощников проходят модерацию), эфиры ведут маркетологи. Эти настройки здесь не действуют.</p></div>
    <div v-if="!s && loadError" class="panel"><p class="muted">{{ loadError }}</p><button class="btn" @click="load()"><Icon name="refresh" :size="16" /> Повторить</button></div>
    <div v-else-if="!s" class="loading-block"><div class="spin"></div></div>
    <div v-else class="layout-with-nav">
      <nav class="side-nav"><router-link v-for="[k, l, ic] in TABS" :key="k" :to="`/admin/settings/${k}`" :class="{ 'router-link-active': tab === k }" active-class=""><Icon :name="ic" :size="20" /> {{ l }}</router-link></nav>
      <div class="col gap-24" style="min-width:0">

        <div v-if="tab === 'general'" class="panel">
          <h3 class="mb-16">Общие</h3>
          <div class="form-grid">
            <div class="field"><label>Название портала</label><input class="input" v-model="s['site.name']" /></div>
            <div class="field"><label>Подзаголовок</label><input class="input" v-model="s['site.tagline']" /></div>
            <div class="field" style="grid-column: 1 / -1"><label>Описание (для гостей и превью ссылок)</label><textarea class="textarea" v-model="s['site.description']" style="min-height:72px"></textarea></div>
            <div class="field"><label>Тема по умолчанию</label><select class="select" v-model="s['site.default_theme']"><option value="light">Светлая</option><option value="dark">Тёмная</option><option value="system">Как в системе</option></select></div>
            <div class="field"><label>Текст в подвале</label><input class="input" v-model="s['site.footer_text']" /></div>
            <div class="field"><label>Сайт компании</label><input class="input" v-model="s['site.company_url']" /></div>
            <div class="field"><label>E-mail поддержки</label><input class="input" v-model="s['site.support_email']" /></div>
          </div>
          <div class="col gap-8 mt-16">
            <label class="switch"><input type="checkbox" v-model="s['site.public_home']" /><span class="track"></span><span>Гости (без входа) видят главную страницу, поиск и публичные видео</span></label>
          </div>
          <div class="divider"></div>
          <h4 class="mb-8">Встраивание на другие сайты</h4>
          <div class="field"><label>Разрешённые домены для iframe (через запятую; пусто — любые)</label><input class="input" :value="(s['embed.allowed_domains'] || []).join(', ')" @change="s['embed.allowed_domains'] = $event.target.value.split(/[\n,]+/).map((x) => x.trim()).filter(Boolean)" placeholder="intranet.company.ru, portal.company.ru" /><div class="hint">Плеер (страницы /embed/…) можно будет вставить только на перечисленные сайты (заголовок Content-Security-Policy frame-ancestors). Сам портал разрешён всегда.</div></div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('embed.'))">Сохранить домены</button></div>
          <div class="divider"></div>
          <h4 class="mb-8">Объявление на всех страницах</h4>
          <div class="row wrap"><label class="switch"><input type="checkbox" v-model="s['site.announcement_enabled']" /><span class="track"></span><span>Показывать</span></label><select class="select" v-model="s['site.announcement_level']" style="max-width: 160px"><option value="info">Информация</option><option value="warning">Внимание</option><option value="danger">Важно</option></select></div>
          <input class="input mt-8" v-model="s['site.announcement_text']" placeholder="Текст объявления" />
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('site.'))">Сохранить</button></div>
        </div>

        <div v-else-if="tab === 'registration'" class="col gap-24">
          <div class="panel"><h3 class="mb-16">Регистрация</h3>
            <div class="col gap-12">
              <label class="switch"><input type="checkbox" v-model="s['registration.enabled']" /><span class="track"></span><span>Разрешить самостоятельную регистрацию с разрешённых доменов</span></label>
              <label class="switch"><input type="checkbox" v-model="s['registration.require_email_verification']" /><span class="track"></span><span>Требовать подтверждение e-mail (нужна настроенная почта)</span></label>
              <label class="switch"><input type="checkbox" v-model="s['registration.notify_admins']" /><span class="track"></span><span>Уведомлять администраторов о новых заявках</span></label>
              <label class="switch"><input type="checkbox" v-model="s['registration.default_can_upload']" /><span class="track"></span><span>Новые пользователи могут загружать видео</span></label>
              <label class="switch"><input type="checkbox" v-model="s['registration.default_can_stream']" /><span class="track"></span><span>Новые пользователи могут вести трансляции</span></label>
            </div>
            <p class="small muted mt-16">Разрешённые домены настраиваются в разделе <router-link to="/admin/domains">Домены и приглашения</router-link>.</p>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('registration.'))">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-16">Безопасность</h3>
            <div class="form-grid">
              <div class="field"><label>Срок сессии, дней</label><input class="input" type="number" min="1" max="365" v-model.number="s['security.session_days']" /></div>
              <div class="field"><label>Минимальная длина пароля</label><input class="input" type="number" min="6" max="64" v-model.number="s['security.password_min_length']" /></div>
              <div class="field"><label>Неудачных попыток входа до блокировки</label><input class="input" type="number" min="3" max="50" v-model.number="s['security.max_login_attempts']" /></div>
              <div class="field"><label>Длительность блокировки, минут</label><input class="input" type="number" min="1" max="1440" v-model.number="s['security.lockout_minutes']" /></div>
            </div>
            <label class="switch mt-16"><input type="checkbox" v-model="s['security.allow_totp']" /><span class="track"></span><span>Разрешить двухфакторную аутентификацию (TOTP)</span></label>
            <label class="switch mt-8"><input type="checkbox" v-model="s['security.require_totp_admins']" :disabled="!s['security.allow_totp']" /><span class="track"></span><span>Обязательная двухфакторная защита для администраторов</span></label>
            <div class="hint">Администратор без включённого TOTP не сможет открыть панель администратора, пока не включит защиту в своих настройках (Безопасность). Включите TOTP у себя до сохранения.</div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('security.'))">Сохранить</button></div>
          </div>
        </div>

        <div v-else-if="tab === 'upload'" class="col gap-24">
          <div class="panel"><h3 class="mb-16">Загрузка</h3>
            <div class="form-grid">
              <div class="field"><label>Кто может загружать видео</label><select class="select" v-model="s['upload.who_can_upload']"><option value="all">Все активные пользователи</option><option value="allowed">Только с разрешением в профиле</option><option value="admins">Только администраторы</option></select></div>
              <div class="field"><label>Максимальный размер файла, МБ</label><input class="input" type="number" min="10" max="4194304" v-model.number="s['upload.max_size_mb']" /><div class="hint">{{ fmtBytes((Number(s['upload.max_size_mb']) || 0) * 1024 * 1024) }} · файл загружается частями, поэтому большой размер не мешает докачке при обрыве связи</div></div>
              <div class="field"><label>Видимость по умолчанию</label><select class="select" v-model="s['upload.default_visibility']"><option value="internal">Для сотрудников</option><option value="public">Публичное</option><option value="unlisted">По ссылке</option><option value="private">Приватное</option></select></div>
              <div class="field"><label>Лимит загрузок в сутки на пользователя (0 — без лимита)</label><input class="input" type="number" min="0" v-model.number="s['upload.max_per_day']" /></div>
              <div class="field"><label>Макс. размер вложения к видео, МБ</label><input class="input" type="number" min="1" v-model.number="s['upload.attachment_max_mb']" /></div>
              <div class="field" style="grid-column: 1 / -1"><label>Разрешённые расширения</label><input class="input" v-model="allowedExt" /></div>
            </div>
            <div class="col gap-12 mt-16">
              <label class="switch"><input type="checkbox" v-model="s['upload.premoderation']" /><span class="track"></span><span>Премодерация: новые видео обычных пользователей публикуются после проверки модератором</span></label>
              <label class="switch"><input type="checkbox" v-model="s['upload.keep_original']" /><span class="track"></span><span>Хранить оригинальные файлы (нужны для повторной обработки и скачивания оригинала; занимают место)</span></label>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('upload.'))">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-16">Транскодирование</h3>
            <div class="field mb-16"><label>Качества HLS (создаются те, что не превышают исходное)</label><div class="row wrap gap-8"><button v-for="q in qualities" :key="q" class="chip" :class="{ active: (s['transcode.qualities'] || []).includes(q) }" @click="toggleQuality(q)">{{ q }}p</button></div></div>
            <div class="form-grid">
              <div class="field"><label>Кодер</label><select class="select" v-model="s['transcode.hwaccel']"><option value="none">Программный x264 (универсально)</option><option value="nvenc">NVIDIA NVENC</option><option value="qsv">Intel Quick Sync</option><option value="vaapi">VA-API (Intel/AMD)</option></select><div class="hint">Аппаратные кодеры требуют драйверов и сборки ffmpeg с их поддержкой</div></div>
              <div class="field"><label>Пресет x264</label><select class="select" v-model="s['transcode.preset']"><option v-for="p in ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow']" :key="p" :value="p">{{ p }}</option></select><div class="hint">Медленнее — лучше сжатие при том же качестве</div></div>
              <div class="field"><label>CRF (качество, 18–28)</label><input class="input" type="number" min="16" max="32" v-model.number="s['transcode.crf']" /></div>
              <div class="field"><label>Длина сегмента HLS, с</label><input class="input" type="number" min="2" max="10" v-model.number="s['transcode.segment_seconds']" /></div>
              <div class="field"><label>Битрейт аудио, кбит/с</label><input class="input" type="number" min="64" max="320" v-model.number="s['transcode.audio_bitrate']" /></div>
              <div class="field"><label>MP4 для скачивания: макс. высота</label><input class="input" type="number" min="240" max="2160" v-model.number="s['transcode.mp4_max_height']" /></div>
            </div>
            <div class="col gap-12 mt-16">
              <label class="switch"><input type="checkbox" v-model="s['transcode.mp4_fallback']" /><span class="track"></span><span>Создавать прогрессивный MP4 (скачивание, старые браузеры)</span></label>
              <label class="switch"><input type="checkbox" v-model="s['transcode.storyboard']" /><span class="track"></span><span>Создавать раскадровку для превью на шкале плеера</span></label>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('transcode.'))">Сохранить</button></div>
          </div>
        </div>

        <div v-else-if="tab === 'comments'" class="panel"><h3 class="mb-16">Комментарии</h3>
          <div class="col gap-12"><label class="switch"><input type="checkbox" v-model="s['comments.enabled']" /><span class="track"></span><span>Комментарии включены на портале</span></label></div>
          <div class="form-grid mt-16">
            <div class="field"><label>Режим по умолчанию для новых видео</label><select class="select" v-model="s['comments.default_mode']"><option value="open">Разрешены</option><option value="held">После проверки автором</option><option value="disabled">Отключены</option></select></div>
            <div class="field"><label>Максимальная длина</label><input class="input" type="number" min="100" max="20000" v-model.number="s['comments.max_length']" /></div>
            <div class="field" style="grid-column: 1 / -1"><label>Стоп-слова (по одному в строке) — комментарии с ними уходят на проверку</label><textarea class="textarea" v-model="blockedWords"></textarea></div>
          </div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('comments.'))">Сохранить</button></div>
        </div>

        <div v-else-if="tab === 'smtp'" class="panel"><h3 class="mb-8">Электронная почта (SMTP)</h3>
          <p class="small muted">Нужна для подтверждения адресов, приглашений, восстановления паролей и уведомлений. Без почты регистрация работает без подтверждения e-mail.</p>
          <label class="switch mb-16"><input type="checkbox" v-model="s['smtp.enabled']" /><span class="track"></span><span>Отправка почты включена</span></label>
          <div class="form-grid">
            <div class="field"><label>Сервер SMTP</label><input class="input" v-model="s['smtp.host']" placeholder="mail.vodokomfort.ru" /></div>
            <div class="field"><label>Порт</label><input class="input" type="number" v-model.number="s['smtp.port']" /></div>
            <div class="field"><label>Пользователь</label><input class="input" v-model="s['smtp.user']" autocomplete="off" /></div>
            <div class="field"><label>Пароль</label><input class="input" type="password" v-model="s['smtp.password']" autocomplete="new-password" /></div>
            <div class="field"><label>Адрес отправителя</label><input class="input" v-model="s['smtp.from']" placeholder="video@vodokomfort.ru" /></div>
            <div class="field"><label>Reply-To</label><input class="input" v-model="s['smtp.reply_to']" /></div>
          </div>
          <div class="col gap-8 mt-16"><label class="switch"><input type="checkbox" v-model="s['smtp.secure']" /><span class="track"></span><span>SSL/TLS с самого начала (порт 465); иначе STARTTLS</span></label><label class="switch"><input type="checkbox" v-model="s['smtp.reject_unauthorized']" /><span class="track"></span><span>Проверять сертификат сервера</span></label></div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('smtp.'))">Сохранить</button><button class="btn" :disabled="testing === 'smtp'" @click="test('smtp')"><Icon name="emailFast" :size="16" /> Отправить тестовое письмо</button></div>
          <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
        </div>

        <div v-else-if="tab === 'ldap'" class="panel"><h3 class="mb-8">Active Directory / LDAP</h3>
          <p class="small muted">Сотрудники входят доменным логином и паролем. Учётная запись создаётся при первом входе.</p>
          <label class="switch mb-16"><input type="checkbox" v-model="s['ldap.enabled']" /><span class="track"></span><span>Вход через LDAP включён</span></label>
          <div class="form-grid">
            <div class="field"><label>Адрес сервера</label><input class="input" v-model="s['ldap.url']" placeholder="ldap://dc1.corp.local:389 или ldaps://…:636" /></div>
            <div class="field"><label>Базовый DN</label><input class="input" v-model="s['ldap.base_dn']" placeholder="DC=corp,DC=local" /></div>
            <div class="field"><label>Служебная учётная запись (bind DN)</label><input class="input" v-model="s['ldap.bind_dn']" placeholder="CN=svc-video,OU=Service,DC=corp,DC=local" /></div>
            <div class="field"><label>Пароль служебной учётной записи</label><input class="input" type="password" v-model="s['ldap.bind_password']" autocomplete="new-password" /></div>
            <div class="field" style="grid-column: 1 / -1"><label>Фильтр поиска пользователя ({login} — введённый логин)</label><input class="input mono" v-model="s['ldap.user_filter']" /></div>
            <div class="field"><label>Атрибут e-mail</label><input class="input" v-model="s['ldap.attr_email']" /></div>
            <div class="field"><label>Атрибут имени</label><input class="input" v-model="s['ldap.attr_name']" /></div>
            <div class="field"><label>Атрибут логина</label><input class="input" v-model="s['ldap.attr_login']" /></div>
            <div class="field"><label>Таймаут, мс</label><input class="input" type="number" v-model.number="s['ldap.timeout_ms']" /></div>
            <div class="field"><label>Группа с правами администратора (DN, необязательно)</label><input class="input" v-model="s['ldap.admin_group_dn']" /></div>
            <div class="field"><label>Обязательная группа для входа (DN, необязательно)</label><input class="input" v-model="s['ldap.required_group_dn']" /></div>
          </div>
          <div class="col gap-8 mt-16"><label class="switch"><input type="checkbox" v-model="s['ldap.auto_approve']" /><span class="track"></span><span>Активировать доменных пользователей автоматически (без одобрения администратором)</span></label><label class="switch"><input type="checkbox" v-model="s['ldap.tls_reject_unauthorized']" /><span class="track"></span><span>Проверять сертификат LDAPS</span></label><label class="switch"><input type="checkbox" v-model="s['ldap.nested_groups']" /><span class="track"></span><span>Учитывать вложенные группы AD (транзитивное членство, правило LDAP_MATCHING_RULE_IN_CHAIN)</span></label></div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('ldap.'))">Сохранить</button><button class="btn" :disabled="testing === 'ldap'" @click="test('ldap')"><Icon name="lan" :size="16" /> Проверить подключение</button></div>
          <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
          <div class="divider"></div>
          <h4 class="mb-8">Проверка входа пользователя</h4>
          <div class="row wrap"><input class="input" v-model="ldapLogin.login" placeholder="логин" style="max-width: 200px" /><input class="input" type="password" v-model="ldapLogin.password" placeholder="пароль" style="max-width: 200px" /><button class="btn sm" :disabled="testing === 'ldap-login'" @click="testLdapLogin">Проверить</button></div>
          <pre v-if="ldapUser" class="code mt-8">{{ JSON.stringify(ldapUser, null, 2) }}</pre>
        </div>

        <div v-else-if="tab === 'sso'" class="panel"><h3 class="mb-8">Вход через SSO (OpenID Connect)</h3>
          <p class="small muted">Единый вход через корпоративного провайдера: Keycloak, Microsoft Entra ID (Azure AD), Яндекс ID / Yandex 360, Google Workspace, Authentik, Okta и любой другой OpenID Connect. Портал использует Authorization Code + PKCE, проверяет подпись id_token по JWKS и создаёт учётные записи при первом входе. Зарегистрируйте у провайдера клиент с Redirect URI <span class="mono">{{ auth.config?.baseUrl }}/api/auth/oidc/callback</span>.</p>
          <label class="switch mb-16"><input type="checkbox" v-model="s['oidc.enabled']" /><span class="track"></span><span>Вход через SSO включён</span></label>
          <div class="form-grid">
            <div class="field" style="grid-column: 1 / -1"><label>Issuer (адрес провайдера)</label><input class="input" v-model="s['oidc.issuer']" placeholder="https://login.company.ru/realms/corp" /><div class="hint">Конфигурация читается из {issuer}/.well-known/openid-configuration. Entra ID: https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0 · Яндекс: https://oauth.yandex.ru</div></div>
            <div class="field"><label>Client ID</label><input class="input" v-model="s['oidc.client_id']" /></div>
            <div class="field"><label>Client Secret</label><input class="input" type="password" v-model="s['oidc.client_secret']" autocomplete="new-password" /></div>
            <div class="field"><label>Scopes</label><input class="input" v-model="s['oidc.scopes']" /></div>
            <div class="field"><label>Текст кнопки входа</label><input class="input" v-model="s['oidc.button_text']" /></div>
            <div class="field"><label>Claim с e-mail</label><input class="input" v-model="s['oidc.email_claim']" /></div>
            <div class="field"><label>Claim с именем</label><input class="input" v-model="s['oidc.name_claim']" /></div>
            <div class="field"><label>Claim со списком групп</label><input class="input" v-model="s['oidc.groups_claim']" /><div class="hint">Keycloak: groups (mapper Group Membership); Entra ID: groups (включите в токен)</div></div>
            <div class="field"><label>Группа администраторов</label><input class="input" v-model="s['oidc.admin_group']" placeholder="video-admins" /></div>
            <div class="field"><label>Обязательная группа для входа</label><input class="input" v-model="s['oidc.required_group']" /></div>
          </div>
          <div class="col gap-8 mt-16">
            <label class="switch"><input type="checkbox" v-model="s['oidc.auto_approve']" /><span class="track"></span><span>Активировать учётные записи SSO автоматически (без одобрения администратором)</span></label>
            <label class="switch"><input type="checkbox" v-model="s['oidc.check_domain']" /><span class="track"></span><span>Проверять домен e-mail по списку разрешённых доменов</span></label>
            <label class="switch"><input type="checkbox" v-model="s['oidc.sync_groups']" /><span class="track"></span><span>Синхронизировать группы портала с группами из claim (по названию или внешнему идентификатору)</span></label>
            <label class="switch"><input type="checkbox" v-model="s['oidc.only']" /><span class="track"></span><span>Только SSO: скрыть форму входа по паролю (запасной вход — /login?local=1)</span></label>
          </div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('oidc.'))">Сохранить</button><button class="btn" :disabled="testing === 'oidc'" @click="test('oidc')"><Icon name="key" :size="16" /> Проверить провайдера</button></div>
          <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
        </div>

        <div v-else-if="tab === 'integrations'" class="col gap-24">
          <div class="panel"><h3 class="mb-8">Уведомления в Telegram</h3>
            <p class="small muted">Создайте бота через @BotFather, укажите токен. Сотрудники привязывают Telegram в «Настройки → Уведомления» (бот получает команду /start с кодом). Входящие команды портал читает сам (long polling) — входящий адрес и вебхук не нужны.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['telegram.enabled']" /><span class="track"></span><span>Уведомления в Telegram включены</span></label>
            <div class="form-grid">
              <div class="field"><label>Токен бота</label><input class="input" type="password" v-model="s['telegram.bot_token']" autocomplete="new-password" /></div>
              <div class="field"><label>Имя бота (без @)</label><input class="input" v-model="s['telegram.bot_username']" placeholder="определяется при проверке" /></div>
              <div class="field" style="grid-column: 1 / -1"><label>Адрес Bot API</label><input class="input" v-model="s['telegram.api_url']" /><div class="hint">Оставьте https://api.telegram.org; при ограничениях сети укажите адрес прокси Bot API</div></div>
              <div class="field" style="grid-column: 1 / -1"><label>Какие уведомления отправлять (через запятую; пусто — все)</label><input class="input" :value="(s['telegram.types'] || []).join(', ')" @change="s['telegram.types'] = $event.target.value.split(/[\n,]+/).map((x) => x.trim()).filter(Boolean)" /><div class="hint">Типы: assignment, assignment_reminder, live_started, live_reminder, video_ready, video_failed, comment_reply, comment, moderation, certificate, subscribe, new_video, ai_ready, video_expired</div></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('telegram.'))">Сохранить</button><button class="btn" :disabled="testing === 'telegram'" @click="test('telegram')"><Icon name="send" :size="16" /> Проверить бота</button></div>
            <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
          </div>
          <div class="panel"><h3 class="mb-8">Интеграция с RAG (корпоративная база знаний)</h3>
            <p class="small muted">Портал формирует из каждого видео документ (название, описание, теги, главы с таймкодами, транскрипт, ссылки на материалы) и отправляет его во внешнюю RAG-систему по HTTP — автоматически после обработки и субтитров или вручную. Также RAG может забирать документы сама: <span class="mono">GET /api/rag/documents</span> и <span class="mono">GET /api/rag/documents/&lt;id&gt;?format=markdown</span> с заголовком <span class="mono">Authorization: Bearer &lt;токен&gt;</span>.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['rag.enabled']" /><span class="track"></span><span>Выгрузка в RAG включена</span></label>
            <div class="form-grid">
              <div class="field" style="grid-column: 1 / -1"><label>Адрес приёма документов (POST)</label><input class="input" v-model="s['rag.url']" placeholder="https://rag.company.ru/api/ingest" /></div>
              <div class="field"><label>Ключ API</label><input class="input" type="password" v-model="s['rag.api_key']" autocomplete="new-password" /></div>
              <div class="field"><label>Заголовок авторизации</label><input class="input" v-model="s['rag.auth_header']" /><div class="hint">Authorization → «Bearer ключ»; иной заголовок → значение ключа как есть</div></div>
              <div class="field"><label>Формат</label><select class="select" v-model="s['rag.format']"><option value="json">JSON {id, title, url, text, metadata}</option><option value="markdown">multipart: файл .md + поля</option></select></div>
              <div class="field"><label>Какие видео выгружать</label><select class="select" v-model="s['rag.min_visibility']"><option value="public">Только публичные</option><option value="internal">Публичные и для сотрудников</option><option value="all">Все, включая приватные</option></select></div>
              <div class="field"><label>Токен для pull-API (RAG забирает сама)</label><input class="input" type="password" v-model="s['rag.pull_token']" autocomplete="new-password" placeholder="придумайте длинную строку" /></div>
            </div>
            <div class="col gap-8 mt-16">
              <label class="switch"><input type="checkbox" v-model="s['rag.auto']" /><span class="track"></span><span>Выгружать автоматически после обработки видео, субтитров и ИИ-подсказок</span></label>
              <label class="switch"><input type="checkbox" v-model="s['rag.include_transcript']" /><span class="track"></span><span>Включать транскрипт с таймкодами</span></label>
              <label class="switch"><input type="checkbox" v-model="s['rag.describe_with_ai']" /><span class="track"></span><span>Описывать видео без описания через ИИ-помощника перед выгрузкой (нужен включённый ИИ-помощник)</span></label>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('rag.'))">Сохранить</button><button class="btn" :disabled="testing === 'rag'" @click="test('rag')"><Icon name="database" :size="16" /> Отправить тестовый документ</button></div>
            <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
            <div class="divider"></div>
            <div class="row wrap gap-8" style="align-items:center">
              <button class="btn sm" @click="ragAction('status')"><Icon name="refresh" :size="16" /> Состояние</button>
              <button class="btn sm" :disabled="!s['rag.enabled']" @click="ragAction('push')"><Icon name="upload" :size="16" /> Выгрузить все видео</button>
              <button class="btn sm" :disabled="!s['rag.enabled']" @click="ragAction('push-force')"><Icon name="upload" :size="16" /> Выгрузить заново (все)</button>
              <button class="btn sm" :disabled="!s['ai.enabled']" @click="ragAction('describe')"><Icon name="sparkles" :size="16" /> Описать все видео без описания через ИИ</button>
            </div>
            <div v-if="ragStatus" class="stat-tiles mt-16" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr))">
              <div class="stat-tile"><div class="v">{{ ragStatus.total }}</div><div class="l">видео для выгрузки</div></div>
              <div class="stat-tile"><div class="v" style="color: var(--success)">{{ ragStatus.synced }}</div><div class="l">выгружено</div></div>
              <div class="stat-tile"><div class="v" :style="ragStatus.errors ? 'color: var(--danger)' : ''">{{ ragStatus.errors }}</div><div class="l">с ошибкой</div></div>
              <div class="stat-tile"><div class="v">{{ ragStatus.queued }}</div><div class="l">в очереди</div></div>
              <div class="stat-tile"><div class="v">{{ ragStatus.undescribed }}</div><div class="l">без описания</div></div>
              <div class="stat-tile"><div class="v">{{ ragStatus.withTranscript }}</div><div class="l">с транскриптом</div></div>
              <div class="stat-tile"><div class="v">{{ ragStatus.describing }}</div><div class="l">описывает ИИ</div></div>
            </div>
          </div>
        </div>

        <div v-else-if="tab === 'asr'" class="panel"><h3 class="mb-8">Автоматические субтитры (ASR)</h3>
          <p class="small muted">Сервер распознавания речи с API, совместимым с OpenAI Whisper (<span class="mono">POST /v1/audio/transcriptions</span>): whisper.cpp server, faster-whisper-server, GigaAM-хаб и т.п. Текст расшифровки индексируется для поиска.</p>
          <label class="switch mb-16"><input type="checkbox" v-model="s['asr.enabled']" /><span class="track"></span><span>Автосубтитры включены</span></label>
          <div class="form-grid">
            <div class="field" style="grid-column: 1 / -1"><label>Адрес API</label><input class="input" v-model="s['asr.url']" placeholder="http://asr.corp.local:8000/v1/audio/transcriptions" /></div>
            <div class="field"><label>Ключ API (Bearer, если требуется)</label><input class="input" type="password" v-model="s['asr.api_key']" autocomplete="new-password" /></div>
            <div class="field"><label>Модель</label><input class="input" v-model="s['asr.model']" placeholder="whisper-1 / large-v3 / gigaam" /></div>
            <div class="field"><label>Язык по умолчанию</label><input class="input" v-model="s['asr.language']" placeholder="ru" /></div>
            <div class="field"><label>Таймаут, с</label><input class="input" type="number" v-model.number="s['asr.timeout_sec']" /></div>
            <div class="field"><label>Макс. длительность видео, мин</label><input class="input" type="number" v-model.number="s['asr.max_duration_min']" /></div>
          </div>
          <label class="switch mt-16"><input type="checkbox" v-model="s['asr.auto_generate']" /><span class="track"></span><span>Автоматически распознавать речь для всех новых видео</span></label>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('asr.'))">Сохранить</button><button class="btn" :disabled="testing === 'asr'" @click="test('asr')"><Icon name="robot" :size="16" /> Проверить доступность</button></div>
          <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
        </div>

        <div v-else-if="tab === 'ai'" class="panel"><h3 class="mb-8">ИИ-помощник автора</h3>
          <p class="small muted">По транскрипту (субтитрам) языковая модель предлагает автору название, описание-конспект, главы и теги. Подходит любой сервер с OpenAI-совместимым API <span class="mono">POST /v1/chat/completions</span>: Ollama, LM Studio, vLLM, GigaChat/YandexGPT через шлюз, OpenAI. Данные не покидают ваш контур, если сервер развёрнут локально. Ничего не применяется без подтверждения автора.</p>
          <label class="switch mb-16"><input type="checkbox" v-model="s['ai.enabled']" /><span class="track"></span><span>ИИ-помощник включён</span></label>
          <div class="form-grid">
            <div class="field" style="grid-column: 1 / -1"><label>Адрес API</label><input class="input" v-model="s['ai.url']" placeholder="http://ollama.corp.local:11434/v1/chat/completions" /></div>
            <div class="field"><label>Ключ API (Bearer, если требуется)</label><input class="input" type="password" v-model="s['ai.api_key']" autocomplete="new-password" /></div>
            <div class="field"><label>Модель</label><input class="input" v-model="s['ai.model']" placeholder="qwen2.5:7b / gpt-4o-mini" /></div>
            <div class="field"><label>Символов транскрипта в запросе</label><input class="input" type="number" v-model.number="s['ai.max_chars']" /><div class="hint">Длинные транскрипты прореживаются равномерно</div></div>
            <div class="field"><label>Таймаут, с</label><input class="input" type="number" v-model.number="s['ai.timeout_sec']" /></div>
          </div>
          <div class="col gap-8 mt-16">
            <label class="switch"><input type="checkbox" v-model="s['ai.auto_after_subtitles']" /><span class="track"></span><span>Готовить подсказки автоматически после создания автосубтитров</span></label>
            <label class="switch"><input type="checkbox" v-model="s['ai.auto_describe']" /><span class="track"></span><span>Автоматически заполнять пустое описание и теги видео конспектом от ИИ (без участия автора)</span></label>
          </div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('ai.'))">Сохранить</button><button class="btn" :disabled="testing === 'ai'" @click="test('ai')"><Icon name="sparkles" :size="16" /> Проверить</button></div>
          <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
        </div>

        <div v-else-if="tab === 'learning'" class="panel"><h3 class="mb-8">Обучение и аналитика</h3>
          <h4 class="mb-8">Обязательные просмотры</h4>
          <div class="form-grid">
            <div class="field"><label>Кто может назначать видео к просмотру</label><select class="select" v-model="s['assignments.who_can_assign']"><option value="staff">Модераторы и администраторы</option><option value="all">Все сотрудники (свои видео и видео для сотрудников)</option></select><div class="hint">Назначить «всем сотрудникам» в любом случае могут только модераторы и администраторы</div></div>
            <div class="field"><label>Процент просмотра по умолчанию</label><select class="select" v-model.number="s['assignments.default_percent']"><option v-for="p in [50, 70, 80, 90, 95, 100]" :key="p" :value="p">{{ p }}%</option></select></div>
            <div class="field"><label>Напоминать за N дней до срока</label><input class="input" type="number" min="0" max="30" v-model.number="s['assignments.remind_days']" /></div>
          </div>
          <div class="divider"></div>
          <h4 class="mb-8">Список зрителей</h4>
          <label class="switch"><input type="checkbox" v-model="s['analytics.show_viewers']" /><span class="track"></span><span>Автор видео видит поимённый список сотрудников, смотревших его видео (модераторам список доступен всегда)</span></label>
          <p class="small muted mt-8">Сотрудник может приостановить сохранение истории просмотров в своих настройках — тогда его просмотры не попадают в список.</p>
          <div class="divider"></div>
          <h4 class="mb-8">Сертификаты о прохождении</h4>
          <label class="switch mb-8"><input type="checkbox" v-model="s['certificates.enabled']" /><span class="track"></span><span>Выдавать именные сертификаты за назначения с отметкой «сертификат»</span></label>
          <div class="form-grid">
            <div class="field"><label>Подписант (ФИО)</label><input class="input" v-model="s['certificates.issuer_name']" placeholder="Пусто — название портала" /></div>
            <div class="field"><label>Должность подписанта</label><input class="input" v-model="s['certificates.issuer_title']" /></div>
            <div class="field" style="grid-column: 1 / -1"><label>Формулировка</label><input class="input" v-model="s['certificates.text']" /><div class="hint">Например: «успешно прошёл(ла) обучение». Сертификат содержит номер и ссылку для проверки подлинности.</div></div>
          </div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save([...prefix('assignments.'), ...prefix('analytics.'), ...prefix('certificates.')])">Сохранить</button></div>
        </div>

        <div v-else-if="tab === 'live'" class="panel"><h3 class="mb-8">Трансляции</h3>
          <p class="small muted">Адреса RTMP/SRT/WHIP задаются при установке (переменные LIVE_RTMP_URL, LIVE_SRT_URL, LIVE_WHIP_URL в конфигурации). Текущие: RTMP {{ auth.config?.live?.rtmpUrl || '—' }}, SRT {{ auth.config?.live?.srtUrl || '—' }}.</p>
          <div class="col gap-12"><label class="switch"><input type="checkbox" v-model="s['live.enabled']" /><span class="track"></span><span>Трансляции включены</span></label><label class="switch"><input type="checkbox" v-model="s['live.whip_enabled']" /><span class="track"></span><span>Эфир из браузера (WebRTC/WHIP)</span></label><label class="switch"><input type="checkbox" v-model="s['live.chat_enabled']" /><span class="track"></span><span>Чат зрителей</span></label><label class="switch"><input type="checkbox" v-model="s['live.record_default']" /><span class="track"></span><span>Записывать эфиры по умолчанию</span></label></div>
          <div class="form-grid mt-16">
            <div class="field"><label>Кто может вести трансляции</label><select class="select" v-model="s['live.who_can_stream']"><option value="all">Все активные пользователи</option><option value="allowed">Только с разрешением в профиле</option><option value="admins">Только администраторы</option></select></div>
            <div class="field"><label>Видимость по умолчанию</label><select class="select" v-model="s['live.default_visibility']"><option value="internal">Для сотрудников</option><option value="public">Публичная</option><option value="unlisted">По ссылке</option><option value="private">Приватная</option></select></div>
            <div class="field"><label>Медленный режим чата, с (0 — выкл.)</label><input class="input" type="number" min="0" v-model.number="s['live.chat_slow_mode_sec']" /></div>
          </div>
          <div class="divider"></div>
          <h4 class="mb-4">Вебинары (1.10)</h4>
          <p class="small muted">Раздел «Вебинары» — каталог, страница мероприятия со своим адресом, анкета регистрации, напоминания участникам по расписанию ниже, письмо с записью после эфира и отчёт по участникам.</p>
          <div class="col gap-12"><label class="switch"><input type="checkbox" v-model="s['webinars.enabled']" /><span class="track"></span><span>Раздел «Вебинары» включён</span></label><label class="switch"><input type="checkbox" v-model="s['webinars.external_registration']" /><span class="track"></span><span>Разрешать регистрацию внешних участников (по почте, без учётной записи)</span></label><label class="switch"><input type="checkbox" v-model="s['webinars.followup']" /><span class="track"></span><span>Отправлять письмо с записью после вебинара</span></label></div>
          <div class="grid-2 mt-12">
            <div class="field"><label>Напоминать участникам за, минут (через запятую)</label><input class="input" :value="(s['webinars.reminders'] || []).join(', ')" @change="s['webinars.reminders'] = $event.target.value.split(/[\s,;]+/).map(Number).filter((x) => Number.isFinite(x) && x >= 5 && x <= 10080)" placeholder="1440, 60, 15" /><div class="hint">1440 — за сутки, 60 — за час. От 5 минут до недели (10080), не больше шести напоминаний; пусто — не напоминать</div></div>
            <div class="field"><label>Засчитывать участие от, % длительности</label><input class="input" type="number" min="1" max="100" v-model.number="s['webinar.attendance_min_percent']" /><div class="hint">С этой доли эфира участник считается «был на вебинаре» в отчёте и в письме-послесловии. Порог для сертификата задаётся у каждого вебинара отдельно</div></div>
          </div>

          <div class="divider"></div>
          <h4 class="mb-4">Ретрансляция и перемотка эфира (1.9)</h4>
          <p class="small muted">Ретрансляция отдаёт копию эфира на внешние площадки (VK Видео, YouTube, Rutube, свой RTMP) — адреса и ключи автор задаёт у своей трансляции. Перемотка позволяет зрителю отмотать идущий эфир назад или включить его с начала; нужна включённая запись.</p>
          <div class="col gap-12"><label class="switch"><input type="checkbox" v-model="s['live.restream_enabled']" /><span class="track"></span><span>Ретрансляция на внешние площадки</span></label><label class="switch"><input type="checkbox" v-model="s['live.dvr']" /><span class="track"></span><span>Перемотка эфира назад</span></label></div>
          <div class="form-grid mt-16">
            <div class="field"><label>Глубина перемотки, минут</label><input class="input" type="number" min="5" max="1440" v-model.number="s['live.dvr_minutes']" /></div>
          </div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save([...prefix('live.'), ...prefix('webinars.'), 'webinar.attendance_min_percent'])">Сохранить</button><router-link class="btn" to="/studio/webinars">Мои вебинары</router-link></div>
        </div>

        <div v-else-if="tab === 'branding'" class="panel"><h3 class="mb-8">Оформление</h3>
          <p class="small muted">По умолчанию используется фирменный логотип Водокомфорт. Можно загрузить свой (SVG/PNG, высота ~40 px) и версию для тёмной темы, а также иконку сайта.</p>
          <div class="form-grid">
            <div v-for="kind in ['logo', 'logo_dark', 'favicon']" :key="kind" class="card">
              <div class="label mb-8">{{ { logo: 'Логотип', logo_dark: 'Логотип для тёмной темы', favicon: 'Иконка сайта' }[kind] }}</div>
              <div class="brand-preview" :class="{ dark: kind === 'logo_dark' }"><img v-if="s[`site.${kind}_path`]" :src="$url(`/media/${s[`site.${kind}_path`]}`)" alt="" /><span v-else class="small muted">по умолчанию</span></div>
              <div class="row wrap mt-8"><label class="btn sm"><Icon name="upload" :size="16" /> Загрузить<input type="file" accept="image/*,.svg,.ico" class="hidden" @change="uploadBrand(kind, $event)" /></label><button v-if="s[`site.${kind}_path`]" class="btn ghost sm" @click="removeBrand(kind)">Сбросить</button></div>
            </div>
          </div>
          <div class="field mt-16" style="max-width: 320px"><label>Фирменный цвет (пусто — #0F4382)</label><div class="row"><input class="input" v-model="s['site.accent_color']" placeholder="#0F4382" /><input type="color" :value="s['site.accent_color'] || '#0F4382'" @input="s['site.accent_color'] = $event.target.value" style="width: 42px; height: 42px; border: 0; background: none" /></div></div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(['site.accent_color'])">Сохранить</button></div>
        </div>

        <div v-else-if="tab === 'webhooks'" class="col gap-24">
          <div class="panel"><h3 class="mb-8"><Icon name="webhook" :size="20" style="vertical-align:-4px" /> Вебхуки</h3>
            <label class="switch mb-16"><input type="checkbox" v-model="s['webhooks.enabled']" @change="save(['webhooks.enabled'])" /><span class="track"></span><span>Отправка вебхуков включена</span></label>
            <WebhooksPanel />
          </div>
          <div class="panel"><h3 class="mb-8">xAPI (LRS): результаты обучения в систему обучения</h3>
            <p class="small muted">При выполнении обязательного просмотра и прохождении теста портал отправляет выражения xAPI (completed, passed/failed) в LRS: Moodle, iSpring Learn, WebTutor, Mirapolis, Learning Locker и другие. Для встраивания уроков в LMS используйте экспорт SCORM в студии видео (вкладка «Доступ»).</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['xapi.enabled']" /><span class="track"></span><span>Отправка xAPI включена</span></label>
            <div class="form-grid">
              <div class="field" style="grid-column: 1 / -1"><label>Адрес LRS (endpoint)</label><input class="input" v-model="s['xapi.endpoint']" placeholder="https://lrs.company.ru/xapi/" /><div class="hint">К адресу добавляется /statements</div></div>
              <div class="field"><label>Логин (Basic)</label><input class="input" v-model="s['xapi.username']" /></div>
              <div class="field"><label>Пароль (Basic)</label><input class="input" type="password" v-model="s['xapi.password']" autocomplete="new-password" /></div>
              <div class="field" style="grid-column: 1 / -1"><label>Или готовый заголовок Authorization</label><input class="input" type="password" v-model="s['xapi.auth_header']" autocomplete="new-password" placeholder="Bearer … / Basic …" /></div>
              <div class="field"><label>Идентификатор сотрудника (actor)</label><select class="select" v-model="s['xapi.actor_by']"><option value="email">mbox (mailto:e-mail)</option><option value="account">account (homePage + логин)</option></select></div>
              <div class="field"><label>homePage для account</label><input class="input" v-model="s['xapi.homepage']" placeholder="пусто — адрес портала" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('xapi.'))">Сохранить</button><button class="btn" :disabled="testing === 'xapi'" @click="test('xapi')"><Icon name="send" :size="16" /> Отправить тестовое выражение</button><button class="btn ghost" @click="loadXapi">Журнал выражений</button></div>
            <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
            <div v-if="xapi" class="mt-16">
              <div class="row wrap gap-8 mb-8 small"><span class="badge">всего {{ xapi.stats.total }}</span><span class="badge success">отправлено {{ xapi.stats.ok }}</span><span class="badge danger">ошибок {{ xapi.stats.failed }}</span><span class="badge warning">в очереди {{ xapi.stats.queued }}</span></div>
              <div class="table-wrap"><table class="table"><thead><tr><th>Когда</th><th>Сотрудник</th><th>Видео</th><th>Глагол</th><th>Статус</th><th></th></tr></thead><tbody>
                <tr v-for="x in xapi.statements" :key="x.id"><td class="small muted">{{ new Date(x.createdAt).toLocaleString('ru-RU') }}</td><td class="small">{{ x.user?.displayName || '—' }}</td><td class="small ellipsis" style="max-width:240px">{{ x.videoTitle || '—' }}</td><td class="mono small">{{ x.verb }}</td><td><span class="badge" :class="x.status === 'ok' ? 'success' : x.status === 'failed' ? 'danger' : 'warning'">{{ x.status }}</span><div v-if="x.error" class="tiny muted">{{ x.error }}</div></td><td class="actions"><button v-if="x.status !== 'ok'" class="btn ghost sm" @click="retryXapi(x)">Повторить</button></td></tr>
                <tr v-if="!xapi.statements.length"><td colspan="6" class="muted">Выражений ещё не было</td></tr>
              </tbody></table></div>
            </div>
          </div>
        </div>

        <div v-else-if="tab === 'engage'" class="col gap-24">
          <div class="panel"><h3 class="mb-8"><Icon name="school" :size="20" style="vertical-align:-4px" /> Учебные программы (курсы)</h3>
            <p class="small muted">Курс — это последовательность шагов: видео, материалы и пояснения. Прогресс считается по просмотру и тестам, по завершении выдаётся сертификат. Создаются в студии: «Студия → Курсы».</p>
            <label class="switch"><input type="checkbox" v-model="s['courses.enabled']" /><span class="track"></span><span>Раздел «Курсы» включён</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['courses.self_enroll']" /><span class="track"></span><span>Сотрудники могут записываться на курсы сами</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('courses.'))">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="heartOutline" :size="20" style="vertical-align:-4px" /> Реакции и тепловая карта</h3>
            <p class="small muted">Зрители отмечают моменты (полезно, нравится, впечатляет, непонятно) — отметки видны на полосе перемотки и в аналитике автора. Тепловая карта показывает, какие места пересматривают чаще; она обезличена и появляется только при достаточном числе просмотров.</p>
            <label class="switch"><input type="checkbox" v-model="s['engage.reactions']" /><span class="track"></span><span>Реакции по таймкоду</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['engage.heatmap']" /><span class="track"></span><span>Тепловая карта «часто пересматривают»</span></label>
            <div class="form-grid">
              <div class="field"><label>Показывать кривую от, просмотров</label><input class="input" type="number" min="3" max="1000" v-model.number="s['engage.heatmap_min_views']" /><div class="hint">Автор видит кривую всегда</div></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('engage.'))">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="sparkles" :size="20" style="vertical-align:-4px" /> Спросите видеотеку и качество просмотра</h3>
            <p class="small muted">ИИ отвечает на вопрос сотрудника по расшифровкам речи и тексту с экрана и показывает ссылки на нужные секунды видео (нужен включённый ИИ-помощник). Метрики качества — время до первого кадра, буферизации и ошибки — собираются плеером и видны в разделе «Администрирование → Качество».</p>
            <label class="switch"><input type="checkbox" v-model="s['search.ask_enabled']" /><span class="track"></span><span>«Спросите видеотеку» (ИИ-поиск по расшифровкам)</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['qoe.enabled']" /><span class="track"></span><span>Собирать метрики качества воспроизведения</span></label>
            <div class="form-grid">
              <div class="field"><label>Хранить сеансы воспроизведения, дней</label><input class="input" type="number" min="7" max="730" v-model.number="s['qoe.retention_days']" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save([...prefix('search.'), ...prefix('qoe.')])">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="accounts" :size="20" style="vertical-align:-4px" /> Премьеры, совместный просмотр и экраны</h3>
            <p class="small muted">Премьера — показ загруженного видео в назначенный час с обратным отсчётом и чатом (как на YouTube и VK Видео). «Смотрим вместе» — комната с общей паузой и перемоткой. Экраны — витрина для телевизора в холле: раздел «Администрирование → Экраны».</p>
            <label class="switch"><input type="checkbox" v-model="s['premiere.enabled']" /><span class="track"></span><span>Премьеры видео</span></label>
            <label class="switch"><input type="checkbox" v-model="s['premiere.chat_default']" /><span class="track"></span><span>Чат премьеры включён по умолчанию</span></label>
            <label class="switch"><input type="checkbox" v-model="s['party.enabled']" /><span class="track"></span><span>Совместный просмотр («Смотрим вместе»)</span></label>
            <label class="switch"><input type="checkbox" v-model="s['party.everyone_controls']" /><span class="track"></span><span>Управлять просмотром может любой участник комнаты</span></label>
            <label class="switch"><input type="checkbox" v-model="s['screens.enabled']" /><span class="track"></span><span>Экраны-витрины</span></label>
            <label class="switch"><input type="checkbox" v-model="s['live.captions']" /><span class="track"></span><span>Живые субтитры эфира (нужен сервер распознавания речи)</span></label>
            <label class="switch"><input type="checkbox" v-model="s['notify.video_watched']" /><span class="track"></span><span>Сообщать автору, что его личное видео посмотрели</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['seo.video']" /><span class="track"></span><span>Разметка и карта сайта для публичных видео (видео-SEO)</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save([...prefix('premiere.'), ...prefix('party.'), ...prefix('screens.'), ...prefix('seo.'), 'live.captions', 'notify.video_watched'])">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="checkAll" :size="20" style="vertical-align:-4px" /> Согласование и главы по слайдам</h3>
            <p class="small muted">Согласование — отправка видео коллегам на проверку перед публикацией: замечания с привязкой к секунде и решение «согласовано» или «нужны правки». Главы по слайдам расставляются по сменам картинки в записи.</p>
            <label class="switch"><input type="checkbox" v-model="s['review.enabled']" /><span class="track"></span><span>Согласование видео</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['chapters.auto_enabled']" /><span class="track"></span><span>Главы по сменам слайдов</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(['review.enabled', 'chapters.auto_enabled'])">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="clipboardList" :size="20" style="vertical-align:-4px" /> Итоги встречи, автоклипы, тренажёры и офлайн</h3>
            <p class="small muted">Итоги встречи и автоклипы разбирают расшифровку записи через подключённую языковую модель (нужен включённый ИИ-помощник). Тренажёр с ветвлением останавливает видео и предлагает выбрать действие. Офлайн — скачивание видео на устройство для просмотра без сети.</p>
            <label class="switch"><input type="checkbox" v-model="s['meeting.notes_enabled']" /><span class="track"></span><span>Итоги встречи: решения, задачи, темы</span></label>
            <label class="switch"><input type="checkbox" v-model="s['clips.ai_enabled']" /><span class="track"></span><span>ИИ предлагает фрагменты для клипов</span></label>
            <label class="switch"><input type="checkbox" v-model="s['scenario.enabled']" /><span class="track"></span><span>Тренажёры с ветвлением</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['offline.enabled']" /><span class="track"></span><span>Офлайн-просмотр (скачивание на устройство)</span></label>
            <div class="form-grid">
              <div class="field"><label>Сколько мегабайт можно держать офлайн</label><input class="input" type="number" min="128" max="65536" v-model.number="s['offline.max_mb']" /></div>
              <div class="field"><label>Через сколько дней скачанное устаревает</label><input class="input" type="number" min="1" max="365" v-model.number="s['offline.days']" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save([...prefix('meeting.'), ...prefix('clips.'), ...prefix('scenario.'), ...prefix('offline.')])">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="tune" :size="20" style="vertical-align:-4px" /> Плеер и доступность</h3>
            <p class="small muted">Кнопка «Пропустить вступление» появляется, если у видео заданы границы заставки (студия → «Сведения»). Размер субтитров и подложку зритель настраивает сам в меню плеера.</p>
            <label class="switch"><input type="checkbox" v-model="s['player.skip_intro']" /><span class="track"></span><span>Кнопка «Пропустить вступление»</span></label>
            <label class="switch"><input type="checkbox" v-model="s['player.logo']" /><span class="track"></span><span>Логотип портала поверх видео</span></label>
            <label class="switch mb-16"><input type="checkbox" v-model="s['a11y.high_contrast_default']" /><span class="track"></span><span>Повышенная контрастность по умолчанию</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save([...prefix('player.'), ...prefix('a11y.')])">Сохранить</button></div>
          </div>
        </div>

        <div v-else-if="tab === 'tools'" class="col gap-24">
          <div class="panel"><h3 class="mb-8"><Icon name="scissors" :size="20" style="vertical-align:-4px" /> Редактор видео</h3>
            <p class="small muted">Обрезка, вырезание фрагментов, удаление пауз и клипы как отдельные видео — в студии, вкладка «Редактор». Клипы могут создавать все, кому разрешена загрузка.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['editor.enabled']" /><span class="track"></span><span>Редактор видео и клипы включены</span></label>
            <div class="form-grid">
              <div class="field"><label>Порог тишины по умолчанию, дБ</label><input class="input" type="number" min="-90" max="0" v-model.number="s['editor.silence_db']" /></div>
              <div class="field"><label>Минимальная пауза, с</label><input class="input" type="number" min="0.3" max="30" step="0.1" v-model.number="s['editor.silence_min_sec']" /></div>
              <div class="field"><label>Отступ по краям паузы, с</label><input class="input" type="number" min="0" max="5" step="0.1" v-model.number="s['editor.silence_keep_sec']" /></div>
            </div>
            <div class="divider"></div>
            <h4 class="mb-4">Размытие в кадре и монтаж по тексту (1.9)</h4>
            <p class="small muted">Размытие закрывает лица и документы прямо в записи: автор обводит область на кадре и задаёт отрезок времени. Автопоиск лиц требует пакета <span class="mono">python3-opencv</span> на сервере. Монтаж по расшифровке даёт вырезать фразы из текста и одной кнопкой убирать слова-паразиты.</p>
            <div class="col gap-12 mb-16">
              <label class="switch"><input type="checkbox" v-model="s['editor.blur_enabled']" /><span class="track"></span><span>Размытие лиц и областей</span></label>
              <label class="switch"><input type="checkbox" v-model="s['editor.faces_enabled']" /><span class="track"></span><span>Автоматический поиск лиц</span></label>
              <label class="switch"><input type="checkbox" v-model="s['editor.text_edit']" /><span class="track"></span><span>Монтаж по расшифровке и слова-паразиты</span></label>
            </div>
            <div class="form-grid">
              <div class="field"><label>Сила размытия по умолчанию</label><input class="input" type="number" min="4" max="60" v-model.number="s['editor.blur_strength']" /></div>
              <div class="field" style="grid-column: 1 / -1"><label>Слова-паразиты (через запятую)</label><input class="input" :value="(s['editor.filler_words'] || []).join(', ')" @change="s['editor.filler_words'] = $event.target.value.split(',').map((x) => x.trim()).filter(Boolean)" /><div class="hint">Портал ищет эти слова в расшифровке и предлагает вырезать их из записи</div></div>
            </div>
            <div class="divider"></div>
            <h4 class="mb-4">Графический видеоредактор (1.10)</h4>
            <p class="small muted">Вкладка «Монтаж» у видео: многодорожечный таймлайн с фрагментами, переходами, титрами, картинками и музыкой. Готовый ролик собирается на сервере — поверх этого видео или в отдельное.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['editor.timeline']" /><span class="track"></span><span>Графический редактор включён</span></label>
            <div class="form-grid">
              <div class="field"><label>Качество сборки (CRF, меньше — лучше)</label><input class="input" type="number" min="14" max="32" v-model.number="s['editor.render_crf']" /></div>
              <div class="field"><label>Максимальный размер картинки или музыки, МБ</label><input class="input" type="number" min="1" max="2048" v-model.number="s['editor.max_assets_mb']" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('editor.'))">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="ocr" :size="20" style="vertical-align:-4px" /> Текст на экране (OCR)</h3>
            <p class="small muted">Распознавание надписей на слайдах и в демонстрациях программ через tesseract (пакеты <span class="mono">tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng</span>). Текст участвует в поиске и показывается на странице видео.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['ocr.enabled']" /><span class="track"></span><span>Распознавание текста на экране включено</span></label>
            <div class="form-grid">
              <div class="field"><label>Языки tesseract</label><input class="input" v-model="s['ocr.languages']" placeholder="rus+eng" /></div>
              <div class="field"><label>Шаг кадров, с</label><input class="input" type="number" min="2" max="120" v-model.number="s['ocr.interval_sec']" /></div>
              <div class="field"><label>Максимальная длительность, мин</label><input class="input" type="number" min="1" v-model.number="s['ocr.max_duration_min']" /></div>
            </div>
            <label class="switch mt-8"><input type="checkbox" v-model="s['ocr.auto']" /><span class="track"></span><span>Распознавать автоматически после обработки каждого видео</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('ocr.'))">Сохранить</button><button class="btn" :disabled="testing === 'ocr'" @click="test('ocr')"><Icon name="ocr" :size="16" /> Проверить tesseract</button><button class="btn ghost" :disabled="!s['ocr.enabled']" @click="sysAction('ocr-all')">Распознать все видео</button></div>
            <div v-if="testResult" class="alert mt-16" :class="testResult.ok ? 'success' : 'danger'">{{ testResult.text }}</div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="translate" :size="20" style="vertical-align:-4px" /> Перевод субтитров</h3>
            <p class="small muted">Перевод дорожек субтитров через ИИ-помощника (нужен включённый ИИ-помощник). Автор выбирает язык в студии, вкладка «Субтитры».</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['subtitles.translate_enabled']" /><span class="track"></span><span>Перевод субтитров включён</span></label>
            <div class="field"><label>Языки для перевода (коды через запятую)</label><input class="input" :value="(s['subtitles.translate_languages'] || []).join(', ')" @change="s['subtitles.translate_languages'] = $event.target.value.split(/[\n,]+/).map((x) => x.trim().toLowerCase()).filter(Boolean)" /><div class="hint">Например: en, de, fr, es, zh, kk, uz, tr, ar</div></div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('subtitles.'))">Сохранить</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="rss" :size="20" style="vertical-align:-4px" /> RSS-ленты и подкасты</h3>
            <p class="small muted">Ленты новых видео, каналов, категорий, тегов и плейлистов: <span class="mono">/api/rss/latest</span>, <span class="mono">/api/rss/channel/&lt;handle&gt;</span>, <span class="mono">/api/rss/category/&lt;slug&gt;</span>, <span class="mono">/api/rss/playlist/&lt;id&gt;</span>, <span class="mono">?format=atom</span>, подкаст — <span class="mono">?audio=1</span>. Без токена — только публичные видео; личный токен сотрудника (Настройки → Токены API → RSS-ленты) открывает видео «для сотрудников», но только в лентах: оригиналы, потоки и остальной портал по нему недоступны.</p>
            <label class="switch"><input type="checkbox" v-model="s['feeds.enabled']" /><span class="track"></span><span>RSS/Atom-ленты включены</span></label>
            <label class="switch mt-8"><input type="checkbox" v-model="s['feeds.audio']" /><span class="track"></span><span>Создавать аудиодорожку (m4a) для подкаст-лент и режима «только звук»</span></label>
            <div class="grid-2 mt-8">
              <div class="field"><label>Элементов в ленте</label><input class="input" type="number" min="1" max="200" v-model.number="s['feeds.items']" /></div>
              <div class="field"><label>Срок жизни личного токена лент, дней</label><input class="input" type="number" min="0" max="3650" v-model.number="s['feeds.token_days']" /><div class="hint">0 — бессрочно. Токен открывает только ленты и файлы-вложения, но передаётся в адресе и оседает в журналах читалок</div></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('feeds.'))">Сохранить</button><button class="btn ghost" @click="sysAction('audio')">Создать аудиодорожки для старых видео</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="newsletter" :size="20" style="vertical-align:-4px" /> Еженедельный дайджест по почте</h3>
            <p class="small muted">Письмо сотрудникам с новыми видео (по подпискам и на портале), невыполненными назначениями и ближайшими эфирами. Требует настроенной почты; сотрудник может отключить дайджест в своих настройках уведомлений.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['digest.enabled']" /><span class="track"></span><span>Дайджест включён</span></label>
            <div class="form-grid">
              <div class="field"><label>День недели</label><select class="select" v-model.number="s['digest.weekday']"><option v-for="(d, i) in ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']" :key="i" :value="i + 1">{{ d }}</option></select></div>
              <div class="field"><label>Час отправки (по времени сервера)</label><input class="input" type="number" min="0" max="23" v-model.number="s['digest.hour']" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('digest.'))">Сохранить</button><button class="btn ghost" @click="sysAction('digest')">Отправить пробный дайджест мне</button></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="folderSync" :size="20" style="vertical-align:-4px" /> Папка автоимпорта</h3>
            <p class="small muted">Файлы, появившиеся в каталоге на сервере (например, записи Zoom/Teams или выгрузки с камер по SMB), автоматически становятся видео указанного владельца. Проверка раз в минуту; файл берётся в работу, когда перестаёт меняться.</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['import.watch_enabled']" /><span class="track"></span><span>Автоимпорт включён</span></label>
            <div class="form-grid">
              <div class="field" style="grid-column: 1 / -1"><label>Каталог на сервере</label><input class="input" v-model="s['import.watch_dir']" placeholder="/var/lib/corpvideo/inbox" /><div class="hint">Установка в Docker: только <span class="mono">/data/import</span> (на сервере — каталог <span class="mono">import</span> в каталоге данных); другие пути контейнеру не видны</div></div>
              <div class="field"><label>Владелец видео (e-mail)</label><input class="input" v-model="s['import.watch_owner']" placeholder="пусто — первый администратор" /></div>
              <div class="field"><label>Видимость</label><select class="select" v-model="s['import.watch_visibility']"><option value="private">Приватное</option><option value="unlisted">По ссылке</option><option value="internal">Для сотрудников</option><option value="public">Публичное</option></select></div>
            </div>
            <label class="switch mt-8"><input type="checkbox" v-model="s['import.watch_delete']" /><span class="track"></span><span>Удалять файл из папки после импорта (иначе переносить в подпапку done/)</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('import.'))">Сохранить</button><button class="btn ghost" :disabled="!s['import.watch_dir']" @click="sysAction('watch')">Проверить папку сейчас</button></div>
            <div v-if="watchLog.length" class="table-wrap mt-8"><table class="table"><thead><tr><th>Файл</th><th>Статус</th><th>Когда</th></tr></thead><tbody><tr v-for="l in watchLog.slice(0, 20)" :key="l.id"><td class="small">{{ l.filename }}</td><td><span class="badge" :class="l.status === 'imported' ? 'success' : 'danger'">{{ l.status === 'imported' ? 'импортирован' : 'ошибка' }}</span> <span v-if="l.error" class="tiny muted">{{ l.error }}</span></td><td class="small muted">{{ new Date(l.createdAt).toLocaleString('ru-RU') }}</td></tr></tbody></table></div>
          </div>
          <div v-if="sysResult" class="alert info">{{ sysResult }}</div>
        </div>

        <div v-else-if="tab === 'retention'" class="col gap-24">
          <div class="panel"><h3 class="mb-8"><Icon name="harddisk" :size="20" style="vertical-align:-4px" /> Место на диске</h3>
            <p class="small muted">Квота ограничивает, сколько места занимают видео одного сотрудника: при загрузке сверх лимита портал предложит удалить лишнее. Исходники можно удалять автоматически — видео останется доступным в обработанном виде (HLS и mp4), а место освободится. Сводка — в разделе «Хранилище».</p>
            <div class="form-grid">
              <div class="field"><label>Квота на канал сотрудника, МБ (0 — без ограничений)</label><input class="input" type="number" min="0" max="10485760" v-model.number="s['storage.quota_mb']" /></div>
              <div class="field"><label>Удалять исходники старше, дней (0 — хранить всегда)</label><input class="input" type="number" min="0" max="3650" v-model.number="s['storage.originals_days']" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('storage.'))">Сохранить</button><router-link class="btn" to="/admin/storage">Открыть «Хранилище»</router-link></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="clock" :size="20" style="vertical-align:-4px" /> Сроки хранения и актуальность (1.9)</h3>
            <p class="small muted">Правила хранения сами убирают в архив или в корзину материалы старше срока и предупреждают автора заранее. Видео с отметкой «не удалять» правила не трогают. Пересмотр актуальности напоминает автору подтвердить, что материал ещё не устарел. Сами правила — в разделе «Хранение».</p>
            <label class="switch mb-16"><input type="checkbox" v-model="s['lifecycle.enabled']" /><span class="track"></span><span>Правила хранения и пересмотр актуальности включены</span></label>
            <div class="form-grid">
              <div class="field"><label>Срок актуальности по умолчанию, месяцев (0 — не заполнять)</label><input class="input" type="number" min="0" max="120" v-model.number="s['lifecycle.freshness_months']" /></div>
              <div class="field"><label>Напомнить о пересмотре за, дней</label><input class="input" type="number" min="0" max="180" v-model.number="s['lifecycle.freshness_remind_days']" /></div>
            </div>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('lifecycle.'))">Сохранить</button><router-link class="btn" to="/admin/lifecycle">Открыть «Хранение»</router-link></div>
          </div>
          <div class="panel"><h3 class="mb-8"><Icon name="apps" :size="20" style="vertical-align:-4px" /> Витрины (1.9)</h3>
            <p class="small muted">Тематические страницы-подборки со своим адресом вида <span class="mono">/hub/адрес</span>: обложка, описание и разделы с видео. Их можно закрепить в меню портала.</p>
            <label class="switch"><input type="checkbox" v-model="s['showcases.enabled']" /><span class="track"></span><span>Витрины включены</span></label>
            <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('showcases.'))">Сохранить</button><router-link class="btn" to="/admin/showcases">Открыть «Витрины»</router-link></div>
          </div>
          <div class="panel"><h3 class="mb-8">Хранение данных</h3>
          <div class="form-grid">
            <div class="field"><label>История просмотров, дней</label><input class="input" type="number" min="7" v-model.number="s['retention.history_days']" /></div>
            <div class="field"><label>Сырые данные просмотров, дней</label><input class="input" type="number" min="7" v-model.number="s['retention.raw_views_days']" /><div class="hint">Суточная статистика хранится всегда</div></div>
            <div class="field"><label>Незавершённые загрузки, часов</label><input class="input" type="number" min="1" v-model.number="s['retention.upload_tmp_hours']" /></div>
            <div class="field"><label>Журнал действий, дней</label><input class="input" type="number" min="30" v-model.number="s['retention.audit_days']" /></div>
            <div class="field"><label>Корзина удалённых видео, дней</label><input class="input" type="number" min="1" v-model.number="s['retention.trash_days']" /><div class="hint">Удалённое видео можно восстановить в разделе «Все видео» в течение этого срока, затем файлы стираются</div></div>
          </div>
          <div class="form-actions"><button class="btn primary" :disabled="saving" @click="save(prefix('retention.'))">Сохранить</button></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.brand-preview { height: 64px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); background: var(--bg-2); padding: 8px; }
.brand-preview.dark { background: #0B1626; }
.brand-preview img { max-height: 48px; max-width: 100%; }
</style>
