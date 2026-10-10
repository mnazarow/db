// Администрирование вебхуков: список, создание, проверка, журнал доставок, повтор.
import { one, many, query } from '../../db.js';
import { badRequest, notFound } from '../../lib/util.js';
import { audit } from '../../lib/audit.js';
import { randomToken } from '../../lib/crypto.js';
import { enqueue } from '../../lib/jobs.js';
import { WEBHOOK_EVENTS, isWebhookEvent } from '../../lib/webhooks.js';
import { config } from '../../config.js';
import { assertOutboundUrl, POLICY } from '../../lib/net.js';

/** Секрет подписи наружу не отдаём: в списке — только подсказка, целиком — отдельным запросом (с записью в журнал). */
function hookOut(h, { secret = false } = {}) {
  const out = { id: h.id, name: h.name, url: h.url, secretHint: h.secret ? `${String(h.secret).slice(0, 6)}…` : '', events: h.events || [], enabled: h.enabled, createdAt: h.created_at, lastStatus: h.last_status, lastAt: h.last_at, failCount: h.fail_count, format: h.format || 'json', deliveries: h.deliveries ?? undefined, failed: h.failed ?? undefined };
  if (secret) out.secret = h.secret;
  return out;
}
function deliveryOut(d) {
  return { id: Number(d.id), webhookId: d.webhook_id, event: d.event, status: d.status, httpStatus: d.http_status, attempts: d.attempts, response: d.response, createdAt: d.created_at, deliveredAt: d.delivered_at, payload: d.payload };
}
function validUrl(u) {
  try { const x = new URL(String(u)); return ['http:', 'https:'].includes(x.protocol) ? x.toString() : null; } catch { return null; }
}
/** Адрес вебхука: http(s), не на сам сервер и не на служебные адреса (метаданные облака и т. п.). */
async function checkedUrl(u) {
  const url = validUrl(u);
  if (!url) throw badRequest('Укажите адрес http(s)://');
  try { await assertOutboundUrl(url, POLICY.webhook); } catch (e) { throw badRequest(e.message); }
  return url;
}

const FORMATS = ['json', 'slack', 'mattermost', 'teams'];

export default async function adminWebhookRoutes(app) {
  app.get('/webhooks/events', { preHandler: app.requireAdmin }, async () => ({ events: WEBHOOK_EVENTS.map(([id, label]) => ({ id, label })) }));

  app.get('/webhooks', { preHandler: app.requireAdmin }, async () => {
    const rows = await many(`SELECT w.*, (SELECT count(*)::int FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS deliveries, (SELECT count(*)::int FROM webhook_deliveries d WHERE d.webhook_id = w.id AND d.status = 'failed') AS failed FROM webhooks w ORDER BY w.created_at`);
    return { webhooks: rows.map(hookOut) };
  });

  app.post('/webhooks', { preHandler: app.requireAdmin }, async (req) => {
    const b = req.body || {};
    const url = await checkedUrl(b.url);
    const name = String(b.name || '').trim().slice(0, 100) || new URL(url).host;
    const events = Array.isArray(b.events) ? b.events.filter(isWebhookEvent) : [];
    const secret = String(b.secret || '').trim() || randomToken(24);
    const format = FORMATS.includes(b.format) ? b.format : 'json';
    const h = await one('INSERT INTO webhooks(name, url, secret, events, enabled, created_by, format) VALUES ($1,$2,$3,$4,$5,$6,$7) RETURNING *', [name, url, secret, events, b.enabled !== false, req.user.id, format]);
    await audit(req, 'webhook.create', { targetType: 'webhook', targetId: h.id, details: { name, url, events } });
    return { webhook: hookOut(h, { secret: true }) };
  });

  app.patch('/webhooks/:id', { preHandler: app.requireAdmin }, async (req) => {
    const h = await one('SELECT * FROM webhooks WHERE id = $1', [req.params.id]);
    if (!h) throw notFound('Вебхук не найден');
    const b = req.body || {};
    const sets = []; const params = [h.id];
    const add = (col, val) => { params.push(val); sets.push(`${col} = $${params.length}`); };
    if (b.url !== undefined) add('url', await checkedUrl(b.url));
    if (b.name !== undefined) add('name', String(b.name).trim().slice(0, 100) || h.name);
    if (b.secret !== undefined) add('secret', String(b.secret).trim() || randomToken(24));
    if (b.events !== undefined) add('events', Array.isArray(b.events) ? b.events.filter(isWebhookEvent) : []);
    if (b.enabled !== undefined) { add('enabled', !!b.enabled); if (b.enabled) add('fail_count', 0); }
    if (b.format !== undefined && FORMATS.includes(b.format)) add('format', b.format);
    if (sets.length) await query(`UPDATE webhooks SET ${sets.join(', ')} WHERE id = $1`, params);
    await audit(req, 'webhook.update', { targetType: 'webhook', targetId: h.id, details: Object.keys(b) });
    return { webhook: hookOut(await one('SELECT * FROM webhooks WHERE id = $1', [h.id])) };
  });

  app.get('/webhooks/:id/secret', { preHandler: app.requireAdmin }, async (req) => {
    const h = await one('SELECT * FROM webhooks WHERE id = $1', [req.params.id]);
    if (!h) throw notFound('Вебхук не найден');
    await audit(req, 'webhook.secret_view', { targetType: 'webhook', targetId: h.id, details: { name: h.name } });
    return { secret: h.secret };
  });

  app.delete('/webhooks/:id', { preHandler: app.requireAdmin }, async (req) => {
    const h = await one('DELETE FROM webhooks WHERE id = $1 RETURNING *', [req.params.id]);
    if (!h) throw notFound('Вебхук не найден');
    await audit(req, 'webhook.delete', { targetType: 'webhook', targetId: h.id, details: { name: h.name } });
    return { ok: true };
  });

  // Проверочное событие — только этому вебхуку
  app.post('/webhooks/:id/test', { preHandler: app.requireAdmin }, async (req) => {
    const h = await one('SELECT * FROM webhooks WHERE id = $1', [req.params.id]);
    if (!h) throw notFound('Вебхук не найден');
    const payload = { event: 'webhook.test', at: new Date().toISOString(), portal: config.baseUrl, data: { message: 'Проверка вебхука CorpVideo', webhookId: h.id, byUserId: req.user.id } };
    const d = await one('INSERT INTO webhook_deliveries(webhook_id, event, payload) VALUES ($1,$2,$3::jsonb) RETURNING id', [h.id, 'webhook.test', JSON.stringify(payload)]);
    await enqueue('webhook_deliver', { deliveryId: d.id }, { dedupe: false, maxAttempts: 1, priority: 2 });
    return { ok: true, deliveryId: Number(d.id) };
  });

  app.get('/webhooks/:id/deliveries', { preHandler: app.requireAdmin }, async (req) => {
    const limit = Math.min(200, Math.max(1, Number(req.query.limit) || 50));
    const rows = await many('SELECT * FROM webhook_deliveries WHERE webhook_id = $1 ORDER BY id DESC LIMIT $2', [req.params.id, limit]);
    return { deliveries: rows.map(deliveryOut) };
  });

  app.post('/webhooks/deliveries/:id/retry', { preHandler: app.requireAdmin }, async (req) => {
    const d = await one('SELECT * FROM webhook_deliveries WHERE id = $1', [Number(req.params.id)]);
    if (!d) throw notFound('Доставка не найдена');
    await query(`UPDATE webhook_deliveries SET status = 'queued' WHERE id = $1`, [d.id]);
    await enqueue('webhook_deliver', { deliveryId: Number(d.id) }, { dedupe: false, maxAttempts: 3, priority: 1 });
    return { ok: true };
  });

  // xAPI: журнал выражений
  app.get('/xapi/statements', { preHandler: app.requireAdmin }, async (req) => {
    const limit = Math.min(200, Math.max(1, Number(req.query.limit) || 50));
    const rows = await many(`SELECT x.*, u.display_name, u.email, v.title AS video_title FROM xapi_statements x LEFT JOIN users u ON u.id = x.user_id LEFT JOIN videos v ON v.id = x.video_id ORDER BY x.id DESC LIMIT $1`, [limit]);
    const stats = await one(`SELECT count(*)::int AS total, count(*) FILTER (WHERE status = 'ok')::int AS ok, count(*) FILTER (WHERE status = 'failed')::int AS failed, count(*) FILTER (WHERE status = 'queued')::int AS queued FROM xapi_statements`);
    return { statements: rows.map((r) => ({ id: Number(r.id), verb: r.verb, status: r.status, error: r.error, createdAt: r.created_at, sentAt: r.sent_at, user: r.display_name ? { displayName: r.display_name, email: r.email } : null, videoTitle: r.video_title, statement: r.statement })), stats };
  });
  app.post('/xapi/statements/:id/retry', { preHandler: app.requireAdmin }, async (req) => {
    const r = await one(`UPDATE xapi_statements SET status = 'queued', error = NULL WHERE id = $1 RETURNING id`, [Number(req.params.id)]);
    if (!r) throw notFound('Выражение не найдено');
    await enqueue('xapi_send', { statementId: Number(r.id) }, { dedupe: false, maxAttempts: 3 });
    return { ok: true };
  });
}
