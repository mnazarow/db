// Уведомления в Telegram через бота: привязка учётной записи кодом (/start <код>), отправка сообщений,
// приём обновлений long-polling из воркера (входящий адрес не нужен).
import { one, query, many } from '../db.js';
import { loadSettings } from './settings.js';
import { config } from '../config.js';
import { escapeHtml } from './util.js';
import { randomToken } from './crypto.js';
import { safeFetch, POLICY } from './net.js';

function apiUrl(s, method) {
  const base = String(s['telegram.api_url'] || 'https://api.telegram.org').replace(/\/+$/, '');
  return `${base}/bot${s['telegram.bot_token']}/${method}`;
}

export async function tgCall(s, method, body, timeoutMs = 15000) {
  if (!s['telegram.bot_token']) throw new Error('Не задан токен бота');
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    // Адрес API настраивается (можно указать прокси) — проверяем его как любой исходящий адрес
    const res = await safeFetch(apiUrl(s, method), { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body || {}), signal: ctrl.signal }, POLICY.integration);
    const j = await res.json().catch(() => ({}));
    if (!res.ok || !j.ok) throw new Error(j.description || `Telegram API ${res.status}`);
    return j.result;
  } finally { clearTimeout(t); }
}

/** Проверка токена из панели: getMe → имя бота сохраняется в настройках. */
export async function telegramTest(s) {
  const me = await tgCall(s, 'getMe', {}, 10000);
  return { ok: true, username: me.username, name: me.first_name };
}

/** Отправить сообщение пользователю (если у него привязан Telegram). Ошибки не пробрасываются. */
export async function telegramNotify(userId, { title, body = '', link = null, type = '' }) {
  const s = await loadSettings();
  if (!s['telegram.enabled'] || !s['telegram.bot_token']) return false;
  const types = s['telegram.types'] || [];
  if (types.length && type && !types.includes(type)) return false;
  const u = await one('SELECT telegram_chat_id, prefs FROM users WHERE id = $1 AND telegram_chat_id IS NOT NULL AND deleted_at IS NULL', [userId]);
  if (!u) return false;
  if (u.prefs?.notifications?.telegram === false) return false;
  const url = link ? (link.startsWith('http') ? link : `${config.baseUrl}${link}`) : null;
  const text = `<b>${escapeHtml(title)}</b>${body ? '\n' + escapeHtml(String(body).slice(0, 800)) : ''}${url ? `\n<a href="${escapeHtml(url)}">Открыть на портале</a>` : ''}`;
  try {
    await tgCall(s, 'sendMessage', { chat_id: u.telegram_chat_id, text, parse_mode: 'HTML', disable_web_page_preview: false });
    return true;
  } catch (e) {
    // Пользователь заблокировал бота — отвязываем
    if (/blocked|chat not found|deactivated/i.test(e.message)) await query('UPDATE users SET telegram_chat_id = NULL, telegram_username = NULL WHERE id = $1', [userId]).catch(() => {});
    return false;
  }
}

/** Код привязки для пользователя и ссылка на бота. */
export async function createLinkCode(userId, s) {
  const code = randomToken(12).replace(/[^A-Za-z0-9]/g, "").slice(0, 8).toUpperCase();
  await query('UPDATE users SET telegram_link_code = $2 WHERE id = $1', [userId, code]);
  const bot = s['telegram.bot_username'] ? String(s['telegram.bot_username']).replace(/^@/, '') : '';
  return { code, botUsername: bot, url: bot ? `https://t.me/${bot}?start=${code}` : null };
}

/** Обработка входящих обновлений: /start <код> — привязка, /stop — отвязка. */
export async function handleUpdate(s, upd) {
  const msg = upd.message || upd.edited_message;
  if (!msg || !msg.chat || !msg.text) return;
  const chatId = msg.chat.id;
  const text = String(msg.text).trim();
  const reply = (t) => tgCall(s, 'sendMessage', { chat_id: chatId, text: t, parse_mode: 'HTML' }).catch(() => {});
  const m = text.match(/^\/start(?:@\w+)?\s+([A-Za-z0-9]{4,32})$/);
  if (m) {
    const code = m[1].toUpperCase();
    const u = await one('SELECT id, display_name FROM users WHERE upper(telegram_link_code) = $1 AND deleted_at IS NULL', [code]);
    if (!u) { await reply('Код привязки не найден или устарел. Получите новый код в настройках портала.'); return; }
    await query('UPDATE users SET telegram_chat_id = $2, telegram_username = $3, telegram_link_code = NULL, telegram_linked_at = now() WHERE id = $1', [u.id, chatId, msg.from?.username || null]);
    await query('UPDATE users SET telegram_chat_id = NULL WHERE telegram_chat_id = $1 AND id <> $2', [chatId, u.id]); // один чат — один пользователь
    await reply(`✅ Готово, ${escapeHtml(u.display_name)}! Уведомления портала «${escapeHtml(s['site.name'] || 'Видео')}» будут приходить сюда. Отключить — командой /stop.`);
    return;
  }
  if (/^\/stop(@\w+)?$/.test(text)) {
    await query('UPDATE users SET telegram_chat_id = NULL, telegram_username = NULL WHERE telegram_chat_id = $1', [chatId]);
    await reply('Уведомления отключены. Привязать снова можно в настройках портала.');
    return;
  }
  if (/^\/start/.test(text)) { await reply(`Чтобы получать уведомления, откройте настройки портала → «Уведомления» → «Привязать Telegram» и перейдите по ссылке из портала.`); return; }
}

let offset = 0;
let polling = false;
/** Один цикл long-polling (вызывается воркером). */
export async function pollUpdates() {
  if (polling) return 0;
  polling = true;
  try {
    const s = await loadSettings();
    if (!s['telegram.enabled'] || !s['telegram.bot_token']) return 0;
    if (!offset) { const row = await one(`SELECT value FROM settings WHERE key = 'telegram.update_offset'`); offset = Number(row?.value) || 0; }
    const updates = await tgCall(s, 'getUpdates', { offset, timeout: 20, allowed_updates: ['message'] }, 30000);
    for (const u of updates) {
      try { await handleUpdate(s, u); } catch { /* ignore */ }
      offset = u.update_id + 1;
    }
    if (updates.length) await query(`INSERT INTO settings(key, value) VALUES ('telegram.update_offset', $1::jsonb) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value`, [JSON.stringify(offset)]);
    return updates.length;
  } catch (e) {
    return -1;
  } finally { polling = false; }
}

/** Пользователи с привязанным Telegram (для статистики в панели). */
export async function telegramStats() {
  const r = await one('SELECT count(*)::int AS n FROM users WHERE telegram_chat_id IS NOT NULL');
  return { linked: r?.n || 0 };
}
