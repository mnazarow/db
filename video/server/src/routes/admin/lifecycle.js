// Жизненный цикл видео: правила хранения, архив, юридическая блокировка и пересмотр актуальности.
import { one, many, query } from '../../db.js';
import { badRequest, notFound, forbidden, paging } from '../../lib/util.js';
import { audit } from '../../lib/audit.js';
import { enqueue } from '../../lib/jobs.js';
import { canEditVideo } from '../../lib/access.js';
import { rulePreview, applyRule } from '../../jobs/retention.js';
import { loadVideo } from '../videos.js';
import { toCsv, sendCsv } from '../../lib/csv.js';

const SCOPES = ['all', 'category', 'visibility', 'tag', 'live'];
const ACTIONS = ['archive', 'delete', 'notify'];

const ruleOut = (r) => ({
  id: r.id, name: r.name, scope: r.scope, categoryId: r.category_id, visibility: r.visibility, tag: r.tag,
  afterDays: r.after_days, action: r.action, warnDays: r.warn_days, enabled: !!r.enabled,
  appliedAt: r.applied_at, appliedCount: r.applied_count, createdAt: r.created_at,
});

export default async function lifecycleRoutes(app) {
  // --- Правила хранения --------------------------------------------------------------------
  app.get('/retention', { preHandler: app.requireAdmin }, async (req) => {
    const rules = await many('SELECT * FROM retention_rules ORDER BY created_at');
    const out = [];
    for (const r of rules) out.push({ ...ruleOut(r), preview: await rulePreview(r, 5) });
    const stats = await one(`SELECT count(*) FILTER (WHERE archived_at IS NOT NULL AND deleted_at IS NULL)::int AS archived,
                                    count(*) FILTER (WHERE legal_hold AND deleted_at IS NULL)::int AS held,
                                    count(*) FILTER (WHERE fresh_until IS NOT NULL AND fresh_until < current_date AND deleted_at IS NULL AND archived_at IS NULL)::int AS stale
                             FROM videos`);
    return { enabled: !!req.settings['lifecycle.enabled'], rules: out, stats };
  });

  app.post('/retention', { preHandler: app.requireAdmin }, async (req) => {
    const b = req.body || {};
    const name = String(b.name || '').trim().slice(0, 120);
    if (!name) throw badRequest('Укажите название правила');
    const afterDays = Math.max(1, Math.min(3650, Number(b.afterDays) || 0));
    if (!afterDays) throw badRequest('Укажите срок хранения в днях');
    const scope = SCOPES.includes(b.scope) ? b.scope : 'all';
    if (scope === 'category' && !b.categoryId) throw badRequest('Выберите категорию');
    if (scope === 'visibility' && !['public', 'internal', 'unlisted', 'private'].includes(b.visibility)) throw badRequest('Выберите видимость');
    if (scope === 'tag' && !String(b.tag || '').trim()) throw badRequest('Укажите тег');
    const row = await one(
      `INSERT INTO retention_rules(name, scope, category_id, visibility, tag, after_days, action, warn_days, enabled, created_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING *`,
      [name, scope, scope === 'category' ? Number(b.categoryId) : null, scope === 'visibility' ? b.visibility : null,
        scope === 'tag' ? String(b.tag).trim().slice(0, 60) : null, afterDays,
        ACTIONS.includes(b.action) ? b.action : 'archive', Math.max(0, Math.min(180, Number(b.warnDays) ?? 7)), b.enabled !== false, req.user.id],
    );
    await audit(req, 'retention.create', { targetType: 'retention', targetId: row.id, details: { name, afterDays, action: row.action } });
    return { rule: { ...ruleOut(row), preview: await rulePreview(row, 5) } };
  });

  app.patch('/retention/:id', { preHandler: app.requireAdmin }, async (req) => {
    const r = await one('SELECT * FROM retention_rules WHERE id = $1', [req.params.id]);
    if (!r) throw notFound('Правило не найдено');
    const b = req.body || {};
    const sets = []; const params = [r.id];
    const add = (c, v) => { params.push(v); sets.push(`${c} = $${params.length}`); };
    if (b.name !== undefined) add('name', String(b.name).trim().slice(0, 120) || r.name);
    if (b.afterDays !== undefined) add('after_days', Math.max(1, Math.min(3650, Number(b.afterDays) || r.after_days)));
    if (b.action !== undefined && ACTIONS.includes(b.action)) add('action', b.action);
    if (b.warnDays !== undefined) add('warn_days', Math.max(0, Math.min(180, Number(b.warnDays) || 0)));
    if (b.enabled !== undefined) add('enabled', !!b.enabled);
    if (b.scope !== undefined && SCOPES.includes(b.scope)) {
      add('scope', b.scope);
      add('category_id', b.scope === 'category' ? Number(b.categoryId) || null : null);
      add('visibility', b.scope === 'visibility' ? String(b.visibility || '') || null : null);
      add('tag', b.scope === 'tag' ? String(b.tag || '').trim().slice(0, 60) || null : null);
    }
    if (sets.length) { sets.push('updated_at = now()'); await query(`UPDATE retention_rules SET ${sets.join(', ')} WHERE id = $1`, params); }
    const upd = await one('SELECT * FROM retention_rules WHERE id = $1', [r.id]);
    await audit(req, 'retention.update', { targetType: 'retention', targetId: r.id, details: Object.keys(b) });
    return { rule: { ...ruleOut(upd), preview: await rulePreview(upd, 5) } };
  });

  app.delete('/retention/:id', { preHandler: app.requireAdmin }, async (req) => {
    const r = await one('SELECT * FROM retention_rules WHERE id = $1', [req.params.id]);
    if (!r) throw notFound('Правило не найдено');
    await query('DELETE FROM retention_rules WHERE id = $1', [r.id]);
    await audit(req, 'retention.delete', { targetType: 'retention', targetId: r.id, details: { name: r.name } });
    return { ok: true };
  });

  // Что затронет правило прямо сейчас (без применения)
  app.get('/retention/:id/preview', { preHandler: app.requireAdmin }, async (req) => {
    const r = await one('SELECT * FROM retention_rules WHERE id = $1', [req.params.id]);
    if (!r) throw notFound('Правило не найдено');
    return { rule: ruleOut(r), preview: await rulePreview(r, 50) };
  });

  // Применить правило немедленно (обычно это делает ночное обслуживание)
  app.post('/retention/:id/run', { preHandler: app.requireAdmin }, async (req) => {
    const r = await one('SELECT * FROM retention_rules WHERE id = $1', [req.params.id]);
    if (!r) throw notFound('Правило не найдено');
    if (!req.settings['lifecycle.enabled']) throw forbidden('Правила хранения отключены в настройках');
    const res = await applyRule(r);
    await audit(req, 'retention.run', { targetType: 'retention', targetId: r.id, details: res });
    return { ok: true, ...res };
  });

  app.get('/retention/log', { preHandler: app.requireStaff }, async (req, reply) => {
    const { limit, offset, page } = paging(req.query, 50, 200);
    const rows = await many(
      `SELECT l.*, r.name AS rule_name, u.display_name AS owner_name, v.short_id, (v.id IS NOT NULL AND v.deleted_at IS NULL) AS alive, v.archived_at
       FROM retention_log l LEFT JOIN retention_rules r ON r.id = l.rule_id LEFT JOIN users u ON u.id = l.owner_id
       LEFT JOIN videos v ON v.id = l.video_id ORDER BY l.at DESC LIMIT ${limit} OFFSET ${offset}`);
    const total = (await one('SELECT count(*)::int AS n FROM retention_log'))?.n || 0;
    const items = rows.map((x) => ({ id: Number(x.id), videoId: x.video_id, shortId: x.short_id, title: x.title, rule: x.rule_name, owner: x.owner_name, action: x.action, at: x.at, alive: !!x.alive, archived: !!x.archived_at }));
    if (req.query.format === 'csv') {
      const labels = { archive: 'в архив', delete: 'удалено', notify: 'уведомление', restore: 'возвращено' };
      const csv = toCsv(items, [
        { title: 'Когда', value: (r) => new Date(r.at).toLocaleString('ru-RU') },
        { title: 'Видео', value: 'title' },
        { title: 'Автор', value: 'owner' },
        { title: 'Правило', value: (r) => r.rule || 'вручную' },
        { title: 'Действие', value: (r) => labels[r.action] || r.action },
        { title: 'Ссылка', value: (r) => (r.shortId ? `/watch/${r.shortId}` : '') },
      ]);
      return sendCsv(reply, 'retention.csv', csv);
    }
    return { items, total, page, limit };
  });

  // --- Архив, юридическая блокировка, актуальность ------------------------------------------
  app.get('/lifecycle/videos', { preHandler: app.requireStaff }, async (req) => {
    const { limit, offset, page } = paging(req.query, 30, 100);
    const kind = ['archived', 'hold', 'stale'].includes(req.query.kind) ? req.query.kind : 'archived';
    const where = kind === 'archived' ? 'v.archived_at IS NOT NULL AND v.deleted_at IS NULL'
      : kind === 'hold' ? 'v.legal_hold AND v.deleted_at IS NULL'
        : 'v.fresh_until IS NOT NULL AND v.fresh_until < current_date AND v.deleted_at IS NULL AND v.archived_at IS NULL';
    const rows = await many(
      `SELECT v.id, v.short_id, v.title, v.archived_at, v.archived_reason, v.legal_hold, v.fresh_until, v.fresh_confirmed_at,
              v.storage_bytes, v.published_at, u.display_name AS owner_name
       FROM videos v JOIN users u ON u.id = v.owner_id WHERE ${where}
       ORDER BY ${kind === 'stale' ? 'v.fresh_until' : 'v.archived_at DESC NULLS LAST'} LIMIT ${limit} OFFSET ${offset}`);
    const total = (await one(`SELECT count(*)::int AS n FROM videos v WHERE ${where}`))?.n || 0;
    return {
      kind, total, page, limit,
      items: rows.map((v) => ({ id: v.id, shortId: v.short_id, title: v.title, owner: v.owner_name, archivedAt: v.archived_at, archivedReason: v.archived_reason, legalHold: v.legal_hold, freshUntil: v.fresh_until, freshConfirmedAt: v.fresh_confirmed_at, bytes: Number(v.storage_bytes) || 0, publishedAt: v.published_at })),
    };
  });

  app.post('/lifecycle/videos/:id/archive', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEditVideo(v, req.user)) throw forbidden();
    const on = req.body?.archived !== false;
    if (on && v.legal_hold) throw badRequest('У видео стоит отметка «не удалять» — снимите её, если убираете в архив');
    await query('UPDATE videos SET archived_at = $2, archived_reason = $3, updated_at = now() WHERE id = $1',
      [v.id, on ? new Date() : null, on ? String(req.body?.reason || 'Убрано вручную').slice(0, 300) : null]);
    await query('INSERT INTO retention_log(video_id, rule_id, title, owner_id, action) VALUES ($1,NULL,$2,$3,$4)', [v.id, v.title, v.owner_id, on ? 'archive' : 'restore']);
    await audit(req, on ? 'video.archive' : 'video.restore', { targetType: 'video', targetId: v.id });
    return { ok: true, archivedAt: on ? new Date().toISOString() : null };
  });

  app.post('/lifecycle/videos/:id/hold', { preHandler: app.requireAdmin }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    const on = req.body?.hold !== false;
    await query('UPDATE videos SET legal_hold = $2, updated_at = now() WHERE id = $1', [v.id, on]);
    await audit(req, on ? 'video.hold.on' : 'video.hold.off', { targetType: 'video', targetId: v.id, details: { reason: String(req.body?.reason || '').slice(0, 300) } });
    return { ok: true, legalHold: on };
  });

  // Пересмотр актуальности: подтверждение автором или администратором
  app.post('/lifecycle/videos/:id/freshness', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEditVideo(v, req.user)) throw forbidden();
    const b = req.body || {};
    let until = null;
    if (b.until) { const d = new Date(b.until); if (Number.isNaN(d.getTime())) throw badRequest('Некорректная дата'); until = d; }
    else if (b.months !== undefined) {
      const m = Math.max(1, Math.min(120, Number(b.months) || 12));
      until = new Date(); until.setMonth(until.getMonth() + m);
    } else if (b.clear) until = null;
    else throw badRequest('Укажите срок актуальности');
    await query('UPDATE videos SET fresh_until = $2, fresh_confirmed_at = now(), fresh_asked_at = NULL, updated_at = now() WHERE id = $1',
      [v.id, until ? until.toISOString().slice(0, 10) : null]);
    await audit(req, 'video.freshness', { targetType: 'video', targetId: v.id, details: { until: until ? until.toISOString().slice(0, 10) : null } });
    return { ok: true, freshUntil: until ? until.toISOString().slice(0, 10) : null };
  });

  // Ночное задание можно запустить вручную
  app.post('/retention/run-all', { preHandler: app.requireAdmin }, async (req) => {
    const j = await enqueue('retention', {}, { dedupe: true, maxAttempts: 1 });
    return { ok: true, jobId: Number(j.id) };
  });
}
