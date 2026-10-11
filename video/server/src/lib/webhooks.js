// Вебхуки: подписка внешних систем на события портала (как у Mux, Cloudflare Stream, Kinescope).
// Событие → записи в webhook_deliveries → задание воркера `webhook_deliver` с HMAC-подписью и повторами.
import crypto from 'node:crypto';
import { one, many, query } from '../db.js';
import { formatPayload } from './chatcards.js';
import { enqueue } from './jobs.js';
import { getSettingSync, getSettings } from './settings.js';
import { config } from '../config.js';
import { assertOutboundUrl, responseHint, POLICY } from './net.js';

export const WEBHOOK_EVENTS = [
  ['video.ready', 'Видео обработано'],
  ['video.published', 'Видео опубликовано (по расписанию)'],
  ['video.updated', 'Сведения о видео изменены'],
  ['video.deleted', 'Видео удалено'],
  ['video.expired', 'Срок публикации истёк'],
  ['live.started', 'Эфир начался'],
  ['live.ended', 'Эфир завершён'],
  ['comment.created', 'Новый комментарий'],
  ['assignment.created', 'Создано назначение'],
  ['assignment.completed', 'Сотрудник выполнил назначение'],
  ['quiz.passed', 'Тест сдан'],
  ['certificate.issued', 'Выдан сертификат'],
  ['course.completed', 'Курс пройден'],
  ['user.registered', 'Новая регистрация'],
  ['user.approved', 'Учётная запись одобрена'],
  ['webhook.test', 'Проверочное событие'],
  ['review.requested', 'Видео отправлено на согласование'],
  ['review.approved', 'Видео согласовано'],
  ['review.changes_requested', 'Видео вернули на доработку'],
];
const EVENT_SET = new Set(WEBHOOK_EVENTS.map((e) => e[0]));

export function isWebhookEvent(name) { return EVENT_SET.has(name); }

/** Создать доставки для всех подходящих вебхуков и поставить их в очередь. */
export async function emitWebhook(event, data = {}) {
  if (!EVENT_SET.has(event)) return 0;
  if (getSettingSync('webhooks.enabled') === false) return 0;
  let hooks;
  try {
    hooks = await many(`SELECT * FROM webhooks WHERE enabled AND (cardinality(events) = 0 OR $1 = ANY(events))`, [event]);
  } catch { return 0; }
  if (!hooks.length) return 0;
  const payload = { event, at: new Date().toISOString(), portal: config.baseUrl, data };
  let n = 0;
  for (const h of hooks) {
    const d = await one('INSERT INTO webhook_deliveries(webhook_id, event, payload) VALUES ($1,$2,$3::jsonb) RETURNING id', [h.id, event, JSON.stringify(payload)]);
    await enqueue('webhook_deliver', { deliveryId: d.id }, { dedupe: false, maxAttempts: 5 });
    n++;
  }
  return n;
}

export function signPayload(secret, timestamp, body) {
  return crypto.createHmac('sha256', String(secret || '')).update(`${timestamp}.${body}`).digest('hex');
}

/** Отправка одной доставки (задание воркера). */
export async function deliverWebhook(job) {
  const d = await one('SELECT d.*, w.url, w.secret, w.enabled, w.format FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.id = $1', [job.payload.deliveryId]);
  if (!d) return { skipped: 'missing' };
  if (!d.enabled) { await query(`UPDATE webhook_deliveries SET status = 'failed', response = 'вебхук отключён' WHERE id = $1`, [d.id]); return { skipped: 'disabled' }; }
  // Формат доставки: обычный JSON или карточка мессенджера (Slack, Mattermost, Teams)
  const format = d.format || 'json';
  const siteName = (await getSettings('site.'))['site.name'] || 'CorpVideo';
  const body = format === 'json'
    ? JSON.stringify({ ...d.payload, deliveryId: d.id, attempt: job.attempts })
    : JSON.stringify(formatPayload(format, d.event, d.payload, siteName));
  const ts = Math.floor(Date.now() / 1000);
  const headers = {
    'content-type': 'application/json',
    'user-agent': 'CorpVideo-Webhook/1.3',
    'x-corpvideo-event': d.event,
    'x-corpvideo-delivery': String(d.id),
    'x-corpvideo-timestamp': String(ts),
    'x-corpvideo-signature': `sha256=${signPayload(d.secret, ts, body)}`,
  };
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 20000);
  let status = 0; let text = '';
  try {
    // Адрес проверяется при каждой доставке (DNS мог измениться); перенаправлениям не следуем.
    // В журнал доставок пишем только статус и короткую подсказку — не тело ответа целиком,
    // иначе вебхук становится способом читать внутренние сервисы через панель.
    await assertOutboundUrl(d.url, POLICY.webhook);
    const res = await fetch(d.url, { method: 'POST', headers, body, signal: ctrl.signal, redirect: 'manual' });
    status = res.status; text = responseHint(res, await res.text().catch(() => ''), 300);
  } catch (e) {
    text = e.name === 'AbortError' ? 'таймаут 20 с' : e.message;
  } finally { clearTimeout(t); }
  const ok = status >= 200 && status < 300;
  await query(
    `UPDATE webhook_deliveries SET attempts = attempts + 1, http_status = $2, response = $3, status = $4, delivered_at = CASE WHEN $4 = 'ok' THEN now() ELSE delivered_at END WHERE id = $1`,
    [d.id, status || null, text, ok ? 'ok' : (job.attempts >= job.max_attempts ? 'failed' : 'queued')],
  );
  await query('UPDATE webhooks SET last_status = $2, last_at = now(), fail_count = CASE WHEN $3 THEN 0 ELSE fail_count + 1 END WHERE id = $1', [d.webhook_id, status || null, ok]);
  if (!ok) throw new Error(`Вебхук ответил ${status || 'ошибкой'}${text ? `: ${text.slice(0, 200)}` : ''}`);
  return { status };
}

/** Проверка подписи на стороне получателя (для документации и тестов). */
export function verifySignature(secret, timestamp, body, signature) {
  const expected = `sha256=${signPayload(secret, timestamp, body)}`;
  return expected.length === String(signature || '').length && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(String(signature)));
}
