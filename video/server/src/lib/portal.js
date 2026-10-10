// Общий вход с порталом документации (CorpVideo встроен в портал как раздел «Видео» по адресу /video).
//
// В этом режиме видеопортал не хранит паролей и не показывает своей формы входа. На запрос браузера
// он спрашивает портал, кто вошёл: передаёт cookie браузера (сессия портала, «Запомнить меня») на
// внутренний адрес портала /internal/video/session и подписывает запрос общим секретом. Портал отвечает
// так же, как ответил бы самому браузеру, — поэтому вход, выход, второй фактор, блокировка и роли общие.
//
// Учётная запись видеопортала заводится и обновляется по данным портала (portal_user_id). Роли:
//   администратор портала → admin; маркетолог (и модератор раздела) → moderator, может загружать и вести эфиры;
//   помощник маркетолога → user с правом загрузки (видео проходят премодерацию); пользователь → user без загрузки.
import crypto from 'node:crypto';
import http from 'node:http';
import https from 'node:https';
import { one, query } from '../db.js';
import { config } from '../config.js';

/** Роль портала → права видеопортала. */
export const POSITION_MAP = {
  admin: { role: 'admin', can_upload: true, can_stream: true },
  marketer: { role: 'moderator', can_upload: true, can_stream: true },
  assistant: { role: 'user', can_upload: true, can_stream: false },
  user: { role: 'user', can_upload: false, can_stream: false },
};

/** Настройки, которые в режиме портала задаёт портал (а не панель администратора видеопортала). */
export const FORCED_SETTINGS = {
  'registration.enabled': false,           // учётные записи заводятся в портале
  'upload.premoderation': true,            // видео помощника маркетолога публикуются после модерации
  'upload.who_can_upload': 'allowed',      // загружают маркетологи, помощники и администраторы
  'live.who_can_stream': 'allowed',        // эфиры — маркетологи и администраторы
  'ldap.enabled': false,                   // вход через домен и SSO — в портале
  'oidc.enabled': false,
  'security.allow_totp': false,            // второй фактор — в портале
  'security.require_totp_admins': false,
};

export const portalEnabled = () => config.portal.enabled;

/** Ссылки на портал для интерфейса (адреса от корня того же сайта). */
export function portalLinks(extra = {}) {
  const p = config.portal.publicPath;
  return {
    enabled: config.portal.enabled,
    name: extra.name || config.portal.name,
    home: extra.home ? p + extra.home : `${p}/`,
    login: extra.login ? p + extra.login : `${p}/login`,
    moderation: extra.moderation ? p + extra.moderation : `${p}/moderation`,
  };
}

// --- Запрос к порталу -------------------------------------------------------------------------------

const cache = new Map();     // ключ cookie → { at, result }
const inflight = new Map();  // ключ cookie → Promise (одновременные запросы страницы ждут один ответ портала)
const MAX_CACHE = 5000;
let lastWarnAt = 0;

/** Cookie браузера без собственных cookie видеопортала (cv_*): порталу они не нужны. */
export function portalCookieHeader(raw) {
  if (!raw) return '';
  return String(raw).split(';').map((c) => c.trim()).filter((c) => c && !/^cv_/i.test(c)).join('; ');
}

function cacheKey(cookie) {
  return crypto.createHash('sha256').update(cookie).digest('base64url');
}

/** GET <PORTAL_INTERNAL_URL>/internal/video/session с cookie браузера; ответ — JSON портала и его Set-Cookie. */
export function requestPortalSession(cookie, { forwardedFor, userAgent, proto } = {}) {
  const base = new URL(config.portal.url);
  const target = new URL(`${base.pathname.replace(/\/+$/, '')}/internal/video/session`, base);
  const lib = target.protocol === 'https:' ? https : http;
  const headers = {
    accept: 'application/json',
    'x-video-secret': config.portal.secret,
    'user-agent': String(userAgent || 'CorpVideo').slice(0, 300),
  };
  if (cookie) headers.cookie = cookie;
  if (config.portal.host) headers.host = config.portal.host;
  if (forwardedFor) headers['x-forwarded-for'] = String(forwardedFor);
  if (proto) headers['x-forwarded-proto'] = proto;
  return new Promise((resolve, reject) => {
    const req = lib.request(target, { method: 'GET', headers, timeout: config.portal.timeoutMs }, (res) => {
      const chunks = [];
      let size = 0;
      res.on('data', (c) => { size += c.length; if (size > 256 * 1024) { req.destroy(new Error('слишком большой ответ портала')); return; } chunks.push(c); });
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        let data = null;
        try { data = JSON.parse(text); } catch { /* не JSON */ }
        if (res.statusCode !== 200 || !data || typeof data !== 'object') {
          reject(new Error(res.statusCode === 404
            ? 'портал не принял запрос (404): проверьте, что VIDEO_ENABLED=1 и секреты PORTAL_SHARED_SECRET / VIDEO_SHARED_SECRET совпадают'
            : `портал ответил ${res.statusCode}${data ? '' : ' не в формате JSON'}`));
          return;
        }
        const setCookie = res.headers['set-cookie'];
        resolve({ data, setCookies: Array.isArray(setCookie) ? setCookie : (setCookie ? [setCookie] : []) });
      });
      res.on('error', reject);
    });
    req.on('timeout', () => req.destroy(new Error(`портал не ответил за ${config.portal.timeoutMs} мс`)));
    req.on('error', reject);
    req.end();
  });
}

/**
 * Кто вошёл, по мнению портала: { state, authenticated, portalUser, logout, links, setCookies, error }.
 * Ответы кэшируются на несколько секунд по набору cookie; Set-Cookie (сессия восстановлена по «Запомнить
 * меня») возвращается только тому запросу, который его получил, и в кэш не попадает.
 */
export async function portalSession(req) {
  const cookie = portalCookieHeader(req.headers?.cookie);
  if (!cookie) return { state: 'guest', authenticated: false, portalUser: null, logout: null, links: portalLinks(), setCookies: [] };
  const key = cacheKey(cookie);
  const hit = cache.get(key);
  if (hit && Date.now() - hit.at < config.portal.cacheMs) return { ...hit.result, setCookies: [] };
  if (inflight.has(key)) { const r = await inflight.get(key); return { ...r, setCookies: [] }; }
  const p = (async () => {
    try {
      const { data, setCookies } = await requestPortalSession(cookie, {
        forwardedFor: req.ip,
        userAgent: req.headers?.['user-agent'],
        proto: config.baseUrl.startsWith('https://') ? 'https' : 'http',
      });
      const result = {
        state: String(data.state || 'guest'),
        authenticated: data.authenticated === true && !!data.user,
        portalUser: data.authenticated === true ? data.user : null,
        logout: data.logout || null,
        links: portalLinks(data.portal || {}),
        setCookies,
      };
      // Восстановленную по «Запомнить меня» сессию браузер получит с новым cookie — старый ключ не кэшируем.
      if (!setCookies.length) {
        cache.set(key, { at: Date.now(), result: { ...result, setCookies: [] } });
        if (cache.size > MAX_CACHE) cache.delete(cache.keys().next().value);
      }
      return result;
    } catch (e) {
      if (Date.now() - lastWarnAt > 60000) {
        lastWarnAt = Date.now();
        req.log?.warn?.({ err: e.message }, 'Портал документации недоступен — посетитель считается гостем');
      }
      return { state: 'guest', authenticated: false, portalUser: null, logout: null, links: portalLinks(), setCookies: [], error: e.message };
    } finally {
      inflight.delete(key);
    }
  })();
  inflight.set(key, p);
  return p;
}

/** Сбросить кэш ответов портала (тесты, смена настроек). */
export function clearPortalCache() { cache.clear(); }

// --- Учётная запись видеопортала по данным портала ---------------------------------------------------

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Адрес-заглушка для сотрудника без почты: домен .invalid (RFC 2606), письма на него не отправляются. */
export function placeholderEmail(username, id, attempt = 0) {
  const local = String(username || 'user').toLowerCase().replace(/[^a-z0-9._-]/g, '').slice(0, 40) || 'user';
  return `${local}${attempt ? `+${id}` : ''}@portal.invalid`;
}

export const isPlaceholderEmail = (email) => /\.invalid$/i.test(String(email || '').trim());

function handleBase(username) {
  return String(username || '').toLowerCase().replace(/@.*$/, '').replace(/[^a-z0-9._-]/g, '').replace(/^[._-]+|[._-]+$/g, '').slice(0, 30) || 'user';
}

async function uniqueHandle(base) {
  let candidate = base;
  for (let i = 0; i < 50; i++) {
    if (!(await one('SELECT 1 FROM users WHERE handle = $1', [candidate]))) return candidate;
    candidate = `${base.slice(0, 25)}${Math.floor(Math.random() * 9000 + 1000)}`;
  }
  return `${base.slice(0, 20)}${Date.now()}`;
}

/**
 * Находит или заводит учётную запись видеопортала для сотрудника портала и приводит её роль, имя, почту и
 * права в соответствие с порталом. Возвращает id учётной записи видеопортала.
 *
 * Порядок поиска: по portal_user_id; затем — прежняя учётная запись видеопортала с той же почтой (её
 * каналы, видео и подписки сохраняются, она просто привязывается к порталу); иначе создаётся новая.
 */
export async function upsertPortalUser(pu) {
  const pid = Number(pu?.id);
  if (!Number.isInteger(pid) || pid <= 0) throw new Error('портал не передал идентификатор пользователя');
  const rights = POSITION_MAP[pu.position] || POSITION_MAP.user;
  const name = String(pu.displayName || pu.username || 'Сотрудник').trim().slice(0, 80) || 'Сотрудник';
  const email = String(pu.email || '').trim().toLowerCase();
  const realEmail = EMAIL_RE.test(email) && !isPlaceholderEmail(email) ? email : null;

  let u = await one('SELECT * FROM users WHERE portal_user_id = $1', [pid]);
  if (!u && realEmail) {
    const prev = await one('SELECT * FROM users WHERE email = $1 AND portal_user_id IS NULL', [realEmail]);
    if (prev) {
      u = await one('UPDATE users SET portal_user_id = $2, updated_at = now() WHERE id = $1 AND portal_user_id IS NULL RETURNING *', [prev.id, pid]) || await one('SELECT * FROM users WHERE portal_user_id = $1', [pid]);
    }
  }
  if (!u) {
    let mail = realEmail && !(await one('SELECT 1 FROM users WHERE email = $1', [realEmail])) ? realEmail : placeholderEmail(pu.username, pid);
    if (await one('SELECT 1 FROM users WHERE email = $1', [mail])) mail = placeholderEmail(pu.username, pid, 1);
    const handle = await uniqueHandle(handleBase(pu.username));
    u = await one(
      `INSERT INTO users(email, handle, display_name, password_hash, role, status, auth_provider, email_verified_at, approved_at, can_upload, can_stream, portal_user_id)
       VALUES ($1, $2, $3, NULL, $4, 'active', 'portal', now(), now(), $5, $6, $7)
       ON CONFLICT (portal_user_id) DO NOTHING RETURNING *`,
      [mail, handle, name, rights.role, rights.can_upload, rights.can_stream, pid],
    ) || await one('SELECT * FROM users WHERE portal_user_id = $1', [pid]);
    if (!u) throw new Error('не удалось завести учётную запись видеопортала');
    return { id: u.id, created: true, changed: true };
  }

  const sets = [];
  const params = [u.id];
  const add = (col, val) => { params.push(val); sets.push(`${col} = $${params.length}`); };
  if (u.display_name !== name) add('display_name', name);
  if (u.role !== rights.role) add('role', rights.role);
  if (u.can_upload !== rights.can_upload) add('can_upload', rights.can_upload);
  if (u.can_stream !== rights.can_stream) add('can_stream', rights.can_stream);
  // Блокировку в видеопортале (например, за комментарии) портал не снимает; заявки и отказы прежней
  // регистрации не нужны — сотрудник уже вошёл в портал.
  if (u.status !== 'active' && u.status !== 'blocked') { add('status', 'active'); sets.push('approved_at = COALESCE(approved_at, now())'); }
  if (u.deleted_at) sets.push('deleted_at = NULL');
  if (u.auth_provider !== 'portal') add('auth_provider', 'portal');
  if (realEmail && String(u.email).toLowerCase() !== realEmail && !(await one('SELECT 1 FROM users WHERE email = $1 AND id <> $2', [realEmail, u.id]))) add('email', realEmail);
  if (!sets.length) return { id: u.id, created: false, changed: false };
  sets.push('updated_at = now()');
  await query(`UPDATE users SET ${sets.join(', ')} WHERE id = $1`, params);
  return { id: u.id, created: false, changed: true };
}
