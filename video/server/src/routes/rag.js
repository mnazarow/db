// Интеграция с RAG: выдача документов наружу (pull) и управление выгрузкой (push) из панели/студии.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, notFound, unauthorized, paging } from '../lib/util.js';
import { isStaff } from '../lib/access.js';
import { buildDocument, ragVisibilitySql, ragStatus } from '../lib/rag.js';
import { timingSafeEqualStr } from '../lib/crypto.js';
import { enqueue } from '../lib/jobs.js';
import { requireEditable } from './videos.js';
import { audit } from '../lib/audit.js';
import { browserPath } from '../lib/urls.js';

const V_SELECT = `v.*, u.display_name AS owner_name, u.email AS owner_email, c.name AS category_name`;
const V_FROM = `videos v JOIN users u ON u.id = v.owner_id LEFT JOIN categories c ON c.id = v.category_id`;

/** Доступ к pull-API: токен интеграции (Bearer <rag.pull_token>) или сессия/токен модератора. */
function pullAuth(req) {
  const s = req.settings;
  const authz = String(req.headers.authorization || '');
  const token = authz.startsWith('Bearer ') ? authz.slice(7).trim() : String(req.query.token || '');
  if (s['rag.pull_token'] && token && timingSafeEqualStr(token, s['rag.pull_token'])) return true;
  if (isStaff(req.user)) return true;
  throw req.user ? forbidden('Доступ к документам RAG только для модераторов или по токену интеграции') : unauthorized('Нужен токен интеграции (Bearer)');
}

export default async function ragRoutes(app) {
  // --- Pull-API: RAG-система забирает документы сама -----------------------------------------
  app.get('/rag/documents', async (req) => {
    pullAuth(req);
    const s = req.settings;
    const { limit, offset, page } = paging(req.query, 50, 200);
    const params = [];
    let where = `v.deleted_at IS NULL AND v.status = 'ready' AND v.moderation_status = 'approved' AND ${ragVisibilitySql(s)}`;
    if (req.query.since) { params.push(new Date(String(req.query.since))); where += ` AND v.updated_at > $${params.length}`; }
    const total = await one(`SELECT count(*)::int AS n FROM ${V_FROM} WHERE ${where}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT ${V_SELECT} FROM ${V_FROM} WHERE ${where} ORDER BY v.updated_at DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    const full = req.query.full === '1';
    const documents = [];
    for (const v of rows) {
      const doc = await buildDocument(v, s);
      documents.push(full ? doc : { id: doc.id, shortId: doc.shortId, title: doc.title, url: doc.url, description: doc.description, tags: doc.tags, category: doc.category, author: doc.author, duration: doc.duration, publishedAt: doc.publishedAt, updatedAt: doc.updatedAt, hash: doc.hash, documentUrl: browserPath(`/api/rag/documents/${doc.id}`) });
    }
    return { documents, total: total.n, page, limit };
  });
  app.get('/rag/documents/:id', async (req, reply) => {
    pullAuth(req);
    const s = req.settings;
    const v = await one(
      `SELECT ${V_SELECT} FROM ${V_FROM} WHERE (v.id = cv_uuid($1) OR v.short_id = $1)
         AND v.deleted_at IS NULL AND v.status = 'ready' AND v.moderation_status = 'approved' AND ${ragVisibilitySql(s)}`,
      [String(req.params.id)],
    );
    if (!v) throw notFound('Видео не найдено');
    const doc = await buildDocument(v, s);
    if (req.query.format === 'markdown' || req.query.format === 'md') { reply.type('text/markdown; charset=utf-8'); return doc.markdown; }
    return { document: doc };
  });

  // --- Управление: студия и панель ----------------------------------------------------------
  app.post('/videos/:id/rag', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['rag.enabled'] || !req.settings['rag.url']) throw badRequest('Интеграция с RAG не настроена администратором');
    await enqueue('rag_push', { videoId: v.id, force: true }, { videoId: v.id, priority: 0, maxAttempts: 2 });
    await audit(req, 'video.rag_push', { targetType: 'video', targetId: v.id });
    return { ok: true, queued: true };
  });
  app.get('/videos/:id/rag', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const doc = await buildDocument(v, req.settings);
    return { syncedAt: v.rag_synced_at, error: v.rag_error, changed: v.rag_hash !== doc.hash, preview: doc.markdown.slice(0, 4000), chars: doc.markdown.length };
  });
  app.get('/admin/rag/status', { preHandler: app.requireStaff }, async (req) => ragStatus(req.settings));
  // Массовая выгрузка всех подходящих видео (force — даже не изменившихся)
  app.post('/admin/rag/push-all', { preHandler: app.requireAdmin }, async (req) => {
    const s = req.settings;
    if (!s['rag.enabled'] || !s['rag.url']) throw badRequest('Интеграция с RAG не настроена');
    const force = !!req.body?.force;
    const rows = await many(`SELECT id FROM videos v WHERE v.deleted_at IS NULL AND v.status = 'ready' AND v.moderation_status = 'approved' AND ${ragVisibilitySql(s)} ${force ? '' : 'AND (rag_synced_at IS NULL OR rag_error IS NOT NULL OR updated_at > rag_synced_at)'} ORDER BY updated_at DESC LIMIT 5000`);
    for (const r of rows) await enqueue('rag_push', { videoId: r.id, force }, { videoId: r.id, priority: -2, maxAttempts: 2 });
    await audit(req, 'admin.rag_push_all', { details: { count: rows.length, force } });
    return { queued: rows.length };
  });
  // Описать все видео без описания через ИИ (задания ai_enrich; при ai.auto_describe описание заполняется автоматически)
  app.post('/admin/rag/describe-all', { preHandler: app.requireAdmin }, async (req) => {
    const s = req.settings;
    if (!s['ai.enabled']) throw badRequest('ИИ-помощник отключён (Настройки → ИИ-помощник)');
    const onlyEmpty = req.body?.all !== true;
    const rows = await many(`SELECT v.id FROM videos v WHERE v.deleted_at IS NULL AND v.status = 'ready'
       AND (EXISTS (SELECT 1 FROM subtitles st WHERE st.video_id = v.id AND st.status = 'ready') OR (v.transcript IS NOT NULL AND v.transcript <> ''))
       ${onlyEmpty ? "AND (v.description IS NULL OR btrim(v.description) = '')" : ''} ORDER BY v.created_at DESC LIMIT 5000`);
    for (const r of rows) await enqueue('ai_enrich', { videoId: r.id, fields: ['summary', 'tags', 'chapters'] }, { videoId: r.id, priority: -2, maxAttempts: 2 });
    if (!s['ai.auto_describe']) await query(`INSERT INTO settings(key, value, updated_by) VALUES ('ai.auto_describe', 'true'::jsonb, $1) ON CONFLICT (key) DO UPDATE SET value = 'true'::jsonb`, [req.user.id]);
    const { loadSettings } = await import('../lib/settings.js'); await loadSettings(true);
    await audit(req, 'admin.ai_describe_all', { details: { count: rows.length, onlyEmpty } });
    return { queued: rows.length };
  });
}
