// Настройки системы: значения по умолчанию + хранение в таблице settings, кэш в памяти,
// инвалидация через шину событий (LISTEN/NOTIFY).
import { many, query, bus, publish, tx } from '../db.js';
import { FEATURE_KEYS } from './features.js';
import { mediaUrl } from './urls.js';
import { portalEnabled, portalLinks, FORCED_SETTINGS } from './portal.js';

export const DEFAULTS = {
  // Общие
  'site.name': 'Водокомфорт Видео',
  'site.tagline': 'Корпоративный видеохостинг',
  'site.description': 'Обучающие материалы, записи мероприятий, инструкции и трансляции компании.',
  'site.language': 'ru',
  'site.default_theme': 'light',           // light | dark | system
  'site.public_home': true,                // гостям доступна главная с публичными видео
  'site.footer_text': '© Водокомфорт',
  'site.announcement_enabled': false,
  'site.announcement_text': '',
  'site.announcement_level': 'info',       // info | warning | danger
  'site.support_email': '',
  'site.company_url': 'https://vodokomfort.ru',
  'site.logo_path': '',                    // пользовательский логотип (иначе встроенный)
  'site.logo_dark_path': '',
  'site.favicon_path': '',
  'site.accent_color': '',                 // переопределение фирменного цвета (#0F4382 по умолчанию)

  // Регистрация и безопасность
  'registration.enabled': true,
  'registration.require_email_verification': true,
  'registration.notify_admins': true,
  'registration.default_can_upload': true,
  'registration.default_can_stream': true,
  'security.session_days': 30,
  'security.password_min_length': 8,
  'security.max_login_attempts': 8,
  'security.lockout_minutes': 15,
  'security.allow_totp': true,
  'security.require_totp_admins': false,   // администраторы обязаны включить двухфакторную защиту
  // 1.4 — вовлечение и доступность
  'engage.reactions': true,             // реакции по таймкоду
  'engage.heatmap': true,               // тепловая карта «часто пересматривают»
  'engage.heatmap_min_views': 20,       // с какого числа просмотров показывать кривую зрителям
  'courses.enabled': true,              // учебные программы
  // 1.11 — разделы, которые раньше нельзя было выключить
  'shorts.enabled': true,               // раздел «Короткие»
  'trending.enabled': true,             // раздел «Тренды»
  'subscriptions.enabled': true,        // подписки на каналы и лента подписок
  'playlists.enabled': true,            // плейлисты и «Смотреть позже»
  'notes.enabled': true,                // личные заметки к секундам видео
  'history.enabled': true,              // история просмотров и продолжение с места остановки
  'likes.enabled': true,                // лайки и раздел «Понравившиеся»
  'assignments.enabled': true,          // обязательные просмотры и раздел «Назначено мне»
  'quiz.enabled': true,                 // тесты внутри видео
  'attachments.enabled': true,          // материалы к видео
  'share_links.enabled': true,          // защищённые ссылки для внешних зрителей
  'calendar.enabled': true,             // календарь публикаций в студии
  'courses.self_enroll': true,          // запись на курс по своей инициативе
  'player.logo': false,                 // логотип портала поверх видео
  'player.skip_intro': true,            // кнопка «Пропустить вступление»
  'a11y.high_contrast_default': false,  // режим повышенной контрастности по умолчанию
  // 1.5 — вебинары, ИИ-поиск, качество воспроизведения
  'search.ask_enabled': true,           // «Спросите видеотеку» (нужен ИИ-помощник)
  'qoe.enabled': true,                  // сбор метрик качества воспроизведения
  'qoe.retention_days': 90,             // сколько хранить сеансы воспроизведения
  'webinar.attendance_min_percent': 50, // с какой доли эфира считать участие засчитанным
  // 1.6 — премьеры, совместный просмотр, живые субтитры, экраны, видео-SEO
  'premiere.enabled': true,             // премьеры видео (показ по расписанию с чатом)
  'premiere.chat_default': true,        // чат премьеры включён по умолчанию
  'party.enabled': true,                // совместный просмотр («Смотрим вместе»)
  'party.everyone_controls': true,      // по умолчанию управлять просмотром может любой участник
  'live.captions': false,               // живые субтитры эфира (нужен сервер распознавания речи)
  'screens.enabled': true,              // экраны-витрины для телевизоров
  'seo.video': true,                    // разметка schema.org и карта сайта для публичных видео
  'notify.video_watched': true,         // уведомлять автора о просмотре личной записи
  // 1.7 — итоги встречи, автоклипы, тренажёры, офлайн
  'meeting.notes_enabled': true,        // ИИ-конспект записи: решения, задачи, темы (нужен ИИ)
  'clips.ai_enabled': true,             // ИИ предлагает фрагменты для клипов (нужен ИИ)
  'scenario.enabled': true,             // тренажёры с ветвлением в видео
  'offline.enabled': true,              // скачивание видео для просмотра без сети (PWA)
  'offline.max_mb': 2048,               // сколько мегабайт можно держать офлайн на устройстве
  'offline.days': 30,                   // через сколько дней скачанное устаревает
  // 1.8 — согласование, календарь, мессенджеры, порядок в хранилище
  'review.enabled': true,               // согласование видео перед публикацией
  'chapters.auto_enabled': true,        // автоглавы по сменам кадра (слайдам)
  'storage.quota_mb': 0,                // квота на канал сотрудника, МБ (0 — без ограничений)
  'storage.originals_days': 0,          // через сколько дней удалять исходники (0 — хранить всегда)
  // 1.9 — приватность в кадре, монтаж по тексту, вещание наружу, сроки хранения
  'editor.blur_enabled': true,          // размытие лиц и областей в кадре
  'editor.blur_strength': 22,           // сила размытия по умолчанию (радиус)
  'editor.faces_enabled': true,         // автопоиск лиц (нужен python3 с opencv)
  'editor.text_edit': true,             // монтаж по расшифровке и удаление слов-паразитов
  'editor.filler_words': ['э', 'э-э', 'ээ', 'эм', 'м-м', 'мм', 'ну', 'вот', 'типа', 'как бы', 'значит', 'короче', 'в общем', 'собственно', 'так сказать', 'это самое', 'ага'],
  'live.restream_enabled': true,        // ретрансляция эфира на внешние площадки
  'live.dvr': true,                     // перемотка эфира назад и просмотр с начала
  'live.dvr_minutes': 120,              // насколько глубоко можно отматывать эфир
  'showcases.enabled': true,            // витрины-подборки со своим адресом
  'lifecycle.enabled': true,            // правила хранения, архив и пересмотр актуальности
  'lifecycle.freshness_months': 0,      // по умолчанию «актуально до» через N месяцев (0 — не заполнять)
  'lifecycle.freshness_remind_days': 7, // за сколько дней напомнить автору о пересмотре
  // 1.10 — вебинары и графический видеоредактор
  'webinars.enabled': true,             // раздел «Вебинары»: каталог, лендинги, регистрация
  'webinars.external_registration': true, // разрешать регистрацию внешних участников по почте
  'webinars.reminders': [1440, 60, 15], // за сколько минут до начала напоминать участникам (от 5 до 10080, до шести)
  'webinars.followup': true,            // письмо-послесловие с записью и материалами
  'editor.timeline': true,              // графический видеоредактор с таймлайном
  'editor.render_crf': 20,              // качество сборки (меньше — лучше и тяжелее)
  'editor.max_assets_mb': 200,          // ограничение на картинки и музыку проекта
  // Загрузка и обработка
  'upload.max_size_mb': 2097152,        // 2 ТБ; загрузка идёт частями, поэтому ограничение — это про диск и терпение
  'upload.allowed_extensions': ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v', 'mpg', 'mpeg', 'wmv', 'flv', 'ts', 'mts', '3gp', 'ogv'],
  'upload.who_can_upload': 'all',          // all | allowed | admins
  'upload.default_visibility': 'internal',
  'upload.keep_original': true,
  'upload.premoderation': false,           // новые видео проходят проверку модератором
  'upload.max_per_day': 0,                 // 0 — без ограничений
  'upload.attachment_max_mb': 200,         // вложения к видео (презентации, документы)
  'transcode.qualities': [2160, 1440, 1080, 720, 480, 360, 240],
  'transcode.preset': 'veryfast',          // ultrafast … slow
  'transcode.crf': 23,
  'transcode.segment_seconds': 6,
  'transcode.hwaccel': 'none',             // none | vaapi | nvenc | qsv
  'transcode.mp4_fallback': true,          // делать прогрессивный mp4 (для скачивания/старых браузеров)
  'transcode.mp4_max_height': 720,
  'transcode.audio_bitrate': 128,
  'transcode.storyboard': true,

  // Комментарии
  'comments.enabled': true,
  'comments.default_mode': 'open',         // open | held | disabled
  'comments.blocked_words': [],
  'comments.max_length': 5000,

  // Электронная почта
  'smtp.enabled': false,
  'smtp.host': '',
  'smtp.port': 587,
  'smtp.secure': false,
  'smtp.user': '',
  'smtp.password': '',
  'smtp.from': '',
  'smtp.reply_to': '',
  'smtp.reject_unauthorized': true,

  // LDAP / Active Directory
  'ldap.enabled': false,
  'ldap.url': 'ldap://dc.example.local:389',
  'ldap.bind_dn': '',
  'ldap.bind_password': '',
  'ldap.base_dn': 'DC=example,DC=local',
  'ldap.user_filter': '(&(objectClass=user)(|(sAMAccountName={login})(userPrincipalName={login})(mail={login})))',
  'ldap.attr_email': 'mail',
  'ldap.attr_name': 'displayName',
  'ldap.attr_login': 'sAMAccountName',
  'ldap.auto_approve': true,
  'ldap.tls_reject_unauthorized': true,
  'ldap.admin_group_dn': '',
  'ldap.required_group_dn': '',
  'ldap.timeout_ms': 8000,
  'ldap.nested_groups': false,             // учитывать вложенные группы AD (LDAP_MATCHING_RULE_IN_CHAIN)

  // Вход через SSO (OpenID Connect: Keycloak, Microsoft Entra ID, Яндекс ID, Google Workspace, Authentik, Okta…)
  'oidc.enabled': false,
  'oidc.issuer': '',                       // https://login.example.com/realms/corp — обнаружение по /.well-known/openid-configuration
  'oidc.client_id': '',
  'oidc.client_secret': '',
  'oidc.scopes': 'openid profile email',
  'oidc.button_text': 'Войти через корпоративный SSO',
  'oidc.auto_approve': true,               // активировать учётную запись сразу после первого входа
  'oidc.check_domain': true,               // e-mail должен быть из разрешённых доменов
  'oidc.email_claim': 'email',
  'oidc.name_claim': 'name',
  'oidc.groups_claim': 'groups',           // claim со списком групп (Keycloak: groups; Entra: groups; Яндекс: —)
  'oidc.admin_group': '',                  // группа из claim → роль администратора
  'oidc.required_group': '',               // без этой группы вход запрещён
  'oidc.sync_groups': true,                // группы из claim синхронизируются с группами портала по названию/DN
  'oidc.only': false,                      // скрыть форму пароля (вход только через SSO и LDAP)

  // Автосубтитры (ASR)
  'asr.enabled': false,
  'asr.url': 'http://127.0.0.1:8000/v1/audio/transcriptions',
  'asr.api_key': '',
  'asr.model': 'whisper-1',
  'asr.language': 'ru',
  'asr.auto_generate': false,
  'asr.timeout_sec': 3600,
  'asr.max_duration_min': 240,

  // Встраивание: разрешённые домены для iframe (пусто — любые); сам портал разрешён всегда
  'embed.allowed_domains': [],

  // Telegram-уведомления (бот)
  'telegram.enabled': false,
  'telegram.bot_token': '',
  'telegram.bot_username': '',
  'telegram.api_url': 'https://api.telegram.org', // можно указать прокси-адрес
  'telegram.types': ['assignment', 'assignment_reminder', 'live_started', 'video_ready', 'comment_reply', 'moderation'],

  // Интеграция с RAG (внешняя база знаний): документы из видео (описание, транскрипт, главы)
  'rag.enabled': false,
  'rag.url': '',                           // POST endpoint приёма документов
  'rag.api_key': '',
  'rag.auth_header': 'Authorization',      // имя заголовка; значение — «Bearer <ключ>»
  'rag.format': 'json',                    // json | markdown (multipart)
  'rag.auto': true,                        // выгружать автоматически после обработки и субтитров
  'rag.include_transcript': true,
  'rag.min_visibility': 'internal',        // какие видео выгружать: public | internal | all
  'rag.pull_token': '',                    // токен для GET /api/rag/documents (RAG забирает сама)
  'rag.describe_with_ai': false,           // перед выгрузкой описывать видео без описания через ИИ (нужен ИИ-помощник)

  // Сертификаты о прохождении
  'certificates.enabled': true,
  'certificates.issuer_name': '',           // ФИО подписанта (пусто — название портала)
  'certificates.issuer_title': 'Руководитель обучения',
  'certificates.text': 'успешно прошёл(ла) обучение',

  // Трансляции
  'live.enabled': true,
  'live.who_can_stream': 'all',            // all | allowed | admins
  'live.record_default': true,
  'live.chat_enabled': true,
  'live.chat_slow_mode_sec': 0,
  'live.whip_enabled': true,
  'live.default_visibility': 'internal',

  // Аналитика и обучение
  'analytics.show_viewers': true,          // автор и модераторы видят список зрителей видео
  'assignments.default_percent': 90,       // процент просмотра, при котором назначение считается выполненным
  'assignments.remind_days': 3,            // за сколько дней до срока напоминать
  'assignments.who_can_assign': 'staff',   // staff | all — кто может назначать видео к просмотру

  // ИИ по транскрипту (любой OpenAI-совместимый сервер: /v1/chat/completions)
  'ai.enabled': false,
  'ai.url': 'http://127.0.0.1:11434/v1/chat/completions',
  'ai.api_key': '',
  'ai.model': 'qwen2.5:7b',
  'ai.auto_after_subtitles': false,        // предлагать описание/главы/теги автоматически после автосубтитров
  'ai.max_chars': 24000,                   // сколько символов транскрипта отправлять
  'ai.timeout_sec': 300,
  'ai.auto_describe': false,               // автоматически заполнять пустое описание видео конспектом от ИИ (после субтитров)

  // Вебхуки и xAPI (1.3)
  'webhooks.enabled': true,
  'xapi.enabled': false,
  'xapi.endpoint': '',                     // https://lrs.example.com/xapi/ (POST statements)
  'xapi.username': '',
  'xapi.password': '',
  'xapi.auth_header': '',                  // готовый заголовок Authorization (если не Basic)
  'xapi.actor_by': 'email',                // email (mbox) | account (homePage + name)
  'xapi.homepage': '',                     // homePage для account (пусто — BASE_URL)

  // Редактор видео и ИИ-инструменты (1.3)
  'editor.enabled': true,                  // обрезка, клипы, удаление пауз в студии
  'editor.silence_db': -35,                // порог тишины, дБ
  'editor.silence_min_sec': 1.5,           // минимальная длительность паузы для удаления
  'editor.silence_keep_sec': 0.3,          // сколько тишины оставлять по краям
  'ocr.enabled': false,                    // распознавать текст на экране (нужен tesseract)
  'ocr.languages': 'rus+eng',
  'ocr.interval_sec': 10,                  // шаг кадров
  'ocr.auto': false,                       // автоматически после обработки видео
  'ocr.max_duration_min': 240,
  'subtitles.translate_enabled': true,     // перевод субтитров через ИИ-помощник
  'subtitles.translate_languages': ['en', 'de', 'fr', 'es', 'zh', 'kk', 'uz', 'tr', 'ar'],

  // Ленты RSS и подкасты (1.3)
  'feeds.enabled': true,                   // RSS/Atom-ленты каналов, категорий, плейлистов, новых видео
  'feeds.audio': true,                     // отдельная аудиодорожка (m4a) для подкаст-лент и режима «только звук»
  'feeds.items': 30,
  'feeds.token_days': 365,                 // срок жизни личного токена лент, дней (0 — бессрочно)

  // Дайджест по почте (1.3)
  'digest.enabled': false,
  'digest.weekday': 1,                     // 1 — понедельник … 7 — воскресенье
  'digest.hour': 9,

  // Папка автоимпорта (1.3)
  'import.watch_enabled': false,
  'import.watch_dir': '',                  // каталог на сервере, например /var/lib/corpvideo/inbox
  'import.watch_owner': '',                // e-mail владельца импортированных видео (пусто — первый администратор)
  'import.watch_visibility': 'private',
  'import.watch_delete': true,             // удалять файл после импорта (иначе переносить в done/)

  // Хранение и очистка
  'retention.history_days': 365,
  'retention.raw_views_days': 400,
  'retention.upload_tmp_hours': 48,
  'retention.audit_days': 730,
  'retention.trash_days': 30,              // удалённые видео хранятся в корзине (можно восстановить), затем файлы стираются
};

const SECRET_KEYS = new Set(['smtp.password', 'ldap.bind_password', 'asr.api_key', 'ai.api_key', 'oidc.client_secret', 'telegram.bot_token', 'rag.api_key', 'rag.pull_token', 'xapi.password', 'xapi.auth_header']);

let cache = null;
let loadedAt = 0;

export async function loadSettings(force = false) {
  if (cache && !force) return cache;
  const rows = await many('SELECT key, value FROM settings');
  const next = { ...DEFAULTS };
  for (const r of rows) next[r.key] = r.value;
  // Встроенный в портал документации видеопортал: вход, регистрация и права загрузки задаёт портал.
  if (portalEnabled()) Object.assign(next, FORCED_SETTINGS);
  cache = next;
  loadedAt = Date.now();
  return cache;
}

export function getSettingSync(key) {
  const v = cache ? cache[key] : DEFAULTS[key];
  return v === undefined ? DEFAULTS[key] : v;
}

export async function getSetting(key) {
  await loadSettings();
  return getSettingSync(key);
}

export async function getSettings(prefix) {
  const all = await loadSettings();
  const out = {};
  for (const [k, v] of Object.entries(all)) {
    if (!prefix || k.startsWith(prefix)) out[k] = v;
  }
  return out;
}

/** Массовое сохранение настроек. */
/** Привести значение к типу значения по умолчанию. */
function coerceSetting(key, value) {
  const def = DEFAULTS[key];
  let v = value;
  if (typeof def === 'boolean') v = v === true || v === 'true' || v === 1 || v === '1';
  else if (typeof def === 'number') { v = Number(v); if (!Number.isFinite(v)) v = def; }
  else if (Array.isArray(def)) {
    if (typeof v === 'string') v = v.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
    if (!Array.isArray(v)) v = def;
    if (typeof def[0] === 'number') v = v.map(Number).filter(Number.isFinite);
  } else if (typeof def === 'string') v = v == null ? '' : String(v);
  return v;
}

/**
 * Настройки «как если бы» переданные значения были сохранены — для кнопок «Проверить».
 * Раньше проверка сначала сохраняла форму: неудачная проверка необратимо меняла рабочую конфигурацию.
 */
export async function previewSettings(entries = {}) {
  const out = { ...(await loadSettings(true)) };
  for (const [key, value] of Object.entries(entries || {})) {
    if (!(key in DEFAULTS)) continue;
    const v = coerceSetting(key, value);
    if (SECRET_KEYS.has(key) && v === '••••••••') continue; // маска — значит, секрет не меняли
    out[key] = v;
  }
  return out;
}

export async function setSettings(entries, userId = null) {
  // Одной транзакцией: половина применённых настроек хуже, чем ни одной
  await tx(async (c) => {
  for (const [key, value] of Object.entries(entries)) {
    if (!(key in DEFAULTS)) continue;
    if (portalEnabled() && key in FORCED_SETTINGS) continue; // в режиме портала эти настройки задаёт портал
    const v = coerceSetting(key, value);
    if (SECRET_KEYS.has(key) && v === '••••••••') continue; // маска не перезаписывает секрет
    await c.query(
      `INSERT INTO settings(key, value, updated_by) VALUES ($1, $2::jsonb, $3)
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now(), updated_by = EXCLUDED.updated_by`,
      [key, JSON.stringify(v), userId],
    );
  }
  });
  await loadSettings(true);
  await publish({ type: 'settings.changed' });
}

/** Настройки для админки: секреты маскируются. */
export async function settingsForAdmin() {
  const all = await loadSettings(true);
  const out = {};
  for (const [k, v] of Object.entries(all)) {
    out[k] = SECRET_KEYS.has(k) ? (v ? '••••••••' : '') : v;
  }
  return out;
}

/** Ключи настроек, которые в режиме портала задаёт портал (в панели они показываются заблокированными). */
export function lockedSettingKeys() {
  return portalEnabled() ? Object.keys(FORCED_SETTINGS) : [];
}

/** Публичная часть настроек для фронтенда. */
export async function publicSettings() {
  const s = await loadSettings();
  return {
    siteName: s['site.name'],
    tagline: s['site.tagline'],
    description: s['site.description'],
    language: s['site.language'],
    defaultTheme: s['site.default_theme'],
    publicHome: s['site.public_home'],
    footerText: s['site.footer_text'],
    announcement: s['site.announcement_enabled'] ? { text: s['site.announcement_text'], level: s['site.announcement_level'] } : null,
    supportEmail: s['site.support_email'],
    companyUrl: s['site.company_url'],
    logoUrl: mediaUrl(s['site.logo_path']),
    logoDarkUrl: mediaUrl(s['site.logo_dark_path']),
    faviconUrl: mediaUrl(s['site.favicon_path']),
    // Цвет попадает в <style> страницы — только шестнадцатеричный код, никакого произвольного CSS
    accentColor: /^#[0-9a-fA-F]{3,8}$/.test(String(s['site.accent_color'] || '')) ? s['site.accent_color'] : null,
    registrationEnabled: s['registration.enabled'],
    // Раздел «Видео» портала документации: вход и выход — через портал, ссылки на его страницы.
    portal: portalEnabled() ? portalLinks() : { enabled: false },
    ldapEnabled: s['ldap.enabled'],
    liveEnabled: s['live.enabled'],
    whipEnabled: s['live.whip_enabled'],
    commentsEnabled: s['comments.enabled'],
    uploadMaxSizeMb: s['upload.max_size_mb'],
    uploadAllowedExtensions: s['upload.allowed_extensions'],
    asrEnabled: s['asr.enabled'],
    totpAllowed: s['security.allow_totp'],
    emailEnabled: s['smtp.enabled'],
    aiEnabled: s['ai.enabled'],
    assignmentsWhoCanAssign: s['assignments.who_can_assign'],
    assignmentsDefaultPercent: s['assignments.default_percent'],
    uploadDefaultVisibility: s['upload.default_visibility'],
    attachmentMaxMb: s['upload.attachment_max_mb'],
    showViewers: s['analytics.show_viewers'],
    oidcEnabled: s['oidc.enabled'] && !!s['oidc.issuer'] && !!s['oidc.client_id'],
    oidcButtonText: s['oidc.button_text'],
    oidcOnly: s['oidc.only'],
    telegramEnabled: s['telegram.enabled'] && !!s['telegram.bot_token'],
    telegramBot: s['telegram.bot_username'],
    certificatesEnabled: s['certificates.enabled'],
    ragEnabled: s['rag.enabled'] && !!s['rag.url'],
    editorEnabled: s['editor.enabled'],
    ocrEnabled: s['ocr.enabled'],
    translateEnabled: s['subtitles.translate_enabled'] && s['ai.enabled'],
    translateLanguages: s['subtitles.translate_languages'],
    feedsEnabled: s['feeds.enabled'],
    requireTotpAdmins: s['security.require_totp_admins'],
    xapiEnabled: s['xapi.enabled'] && !!s['xapi.endpoint'],
    // 1.4
    coursesEnabled: s['courses.enabled'],
    coursesSelfEnroll: s['courses.self_enroll'],
    reactionsEnabled: s['engage.reactions'],
    heatmapEnabled: s['engage.heatmap'],
    playerLogo: s['player.logo'],
    skipIntro: s['player.skip_intro'],
    highContrastDefault: s['a11y.high_contrast_default'],
    // 1.5
    askEnabled: s['search.ask_enabled'] && s['ai.enabled'],
    qoeEnabled: s['qoe.enabled'],
    premiereEnabled: s['premiere.enabled'],
    partyEnabled: s['party.enabled'],
    liveCaptions: s['live.captions'] && s['asr.enabled'],
    screensEnabled: s['screens.enabled'],
    videoSeo: s['seo.video'],
    meetingNotes: s['meeting.notes_enabled'] && s['ai.enabled'],
    clipsAi: s['clips.ai_enabled'] && s['ai.enabled'],
    scenarioEnabled: s['scenario.enabled'],
    offlineEnabled: s['offline.enabled'],
    offlineMaxMb: s['offline.max_mb'],
    offlineDays: s['offline.days'],
    reviewEnabled: s['review.enabled'],
    autoChapters: s['chapters.auto_enabled'],
    storageQuotaMb: s['storage.quota_mb'],
    // 1.9
    blurEnabled: s['editor.blur_enabled'] && s['editor.enabled'],
    facesEnabled: s['editor.faces_enabled'] && s['editor.blur_enabled'] && s['editor.enabled'],
    textEditEnabled: s['editor.text_edit'] && s['editor.enabled'],
    restreamEnabled: s['live.restream_enabled'] && s['live.enabled'],
    dvrEnabled: s['live.dvr'] && s['live.enabled'],
    dvrMinutes: s['live.dvr_minutes'],
    showcasesEnabled: s['showcases.enabled'],
    lifecycleEnabled: s['lifecycle.enabled'],
    webinarsEnabled: s['webinars.enabled'],
    webinarsExternal: s['webinars.enabled'] && s['webinars.external_registration'],
    timelineEnabled: s['editor.timeline'] && s['editor.enabled'],
    // 1.12 — значения по умолчанию, которые интерфейс подставляет в формы
    premiereChatDefault: s['premiere.chat_default'] !== false,
    freshnessMonths: s['lifecycle.enabled'] === false ? 0 : Number(s['lifecycle.freshness_months']) || 0,
    // 1.11 — карта возможностей: по ней интерфейс прячет пункты меню и кнопки выключенных разделов
    features: Object.fromEntries(FEATURE_KEYS.map((k) => [k, s[k] !== false])),
  };
}

bus.on('settings.changed', () => { loadSettings(true).catch(() => {}); });

// Периодическое обновление кэша на случай пропущенного события
setInterval(() => { if (Date.now() - loadedAt > 60000) loadSettings(true).catch(() => {}); }, 30000).unref();
