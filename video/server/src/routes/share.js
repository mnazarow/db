// Защищённые ссылки для внешних зрителей: пароль, срок действия, лимит открытий, разрешение скачивания.
// Открывшему ссылку выдаётся cookie cv_share с токенами; проверка доступа — в lib/access.js (shareLinkFor).
import { one, many, query } from '../db.js';
import { badRequest, notFound, forbidden, tooMany } from '../lib/util.js';
import { randomToken, hashPassword, verifyPassword } from '../lib/crypto.js';
import { requireEditable, loadVideo } from './videos.js';
import { videoCard } from '../lib/serialize.js';
import { audit } from '../lib/audit.js';
import { config } from '../config.js';
import { mediaUrl } from '../lib/urls.js';

const SHARE_COOKIE = 'cv_share';
const MAX_TOKENS = 20;
const unlockAttempts = new Map(); // ip → { n, at }

function linkOut(l) {
  return {
    id: l.id, token: l.token, url: `${config.baseUrl}/s/${l.token}`, note: l.note, hasPassword: !!l.password_hash, expiresAt: l.expires_at,
    maxViews: l.max_views, viewCount: l.view_count, allowDownload: l.allow_download, createdAt: l.created_at, lastUsedAt: l.last_used_at,
    revokedAt: l.revoked_at, creatorName: l.creator_name,
    active: !l.revoked_at && (!l.expires_at || new Date(l.expires_at) > new Date()) && (l.max_views == null || l.view_count < l.max_views),
  };
}

function parseExpires(v) {
  if (v === null || v === undefined || v === '') return null;
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) throw badRequest('Некорректная дата окончания действия');
  if (d < new Date()) throw badRequest('Дата окончания действия уже прошла');
  return d;
}

function setShareCookie(req, reply, token, expiresAt) {
  const have = (req.shareTokens || []).filter((t) => t !== token);
  const tokens = [token, ...have].slice(0, MAX_TOKENS);
  const maxAge = expiresAt ? Math.max(60, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000)) : 30 * 86400;
  // Для встраивания на сторонних сайтах (LMS, SCORM) cookie должна быть SameSite=None (только по HTTPS)
  const crossSite = String(req.query?.embed || '') === '1' && config.cookieSecure;
  reply.setCookie(SHARE_COOKIE, tokens.join(','), { path: '/', httpOnly: true, sameSite: crossSite ? 'none' : 'lax', secure: config.cookieSecure, maxAge });
}

/**
 * Засчитать открытие одним запросом: условие проверяется в той же UPDATE, поэтому одновременные
 * открытия последнего «слота» не проходят оба (раньше проверка и увеличение были разными шагами).
 */
async function bumpViews(l) {
  const r = await query(
    `UPDATE share_links SET view_count = view_count + 1, last_used_at = now()
     WHERE id = $1 AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()) AND (max_views IS NULL OR view_count < max_views)`, [l.id]);
  return r.rowCount > 0;
}

function linkState(l) {
  if (!l || l.revoked_at) return 'revoked';
  if (l.expires_at && new Date(l.expires_at) <= new Date()) return 'expired';
  if (l.max_views != null && l.view_count >= l.max_views) return 'exhausted';
  return 'ok';
}

export default async function shareRoutes(app) {
  // --- Студия: управление ссылками видео -------------------------------------------------
  app.get('/videos/:id/share-links', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const rows = await many('SELECT l.*, u.display_name AS creator_name FROM share_links l LEFT JOIN users u ON u.id = l.created_by WHERE l.video_id = $1 ORDER BY l.created_at DESC', [v.id]);
    return { links: rows.map(linkOut) };
  });

  app.post('/videos/:id/share-links', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const b = req.body || {};
    const count = await one('SELECT count(*)::int AS n FROM share_links WHERE video_id = $1 AND revoked_at IS NULL', [v.id]);
    if (count.n >= 50) throw badRequest('Слишком много активных ссылок для одного видео');
    const password = String(b.password || '');
    if (password && password.length < 4) throw badRequest('Пароль ссылки — минимум 4 символа');
    const maxViews = b.maxViews ? Math.max(1, Math.min(1000000, Number(b.maxViews) || 0)) : null;
    const l = await one(
      `INSERT INTO share_links(video_id, token, note, password_hash, expires_at, max_views, allow_download, created_by) VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING *`,
      [v.id, randomToken(24), String(b.note || '').trim().slice(0, 200), password ? hashPassword(password) : null, parseExpires(b.expiresAt), maxViews, !!b.allowDownload, req.user.id],
    );
    await audit(req, 'share.create', { targetType: 'video', targetId: v.id, details: { linkId: l.id, password: !!password, expiresAt: l.expires_at, maxViews } });
    return { link: linkOut({ ...l, creator_name: req.user.display_name }) };
  });

  app.patch('/videos/:id/share-links/:linkId', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const l0 = await one('SELECT * FROM share_links WHERE id = $1 AND video_id = $2', [req.params.linkId, v.id]);
    if (!l0) throw notFound('Ссылка не найдена');
    const b = req.body || {};
    let passwordHash = l0.password_hash;
    if (b.password !== undefined) {
      const p = String(b.password || '');
      if (p && p.length < 4) throw badRequest('Пароль ссылки — минимум 4 символа');
      passwordHash = p ? hashPassword(p) : null;
    }
    const l = await one(
      `UPDATE share_links SET note = $2, password_hash = $3, expires_at = $4, max_views = $5, allow_download = $6,
         revoked_at = CASE WHEN $7::text = 'revoke' THEN now() WHEN $7::text = 'restore' THEN NULL ELSE revoked_at END
       WHERE id = $1 RETURNING *`,
      [l0.id, b.note !== undefined ? String(b.note).trim().slice(0, 200) : l0.note, passwordHash,
        b.expiresAt !== undefined ? parseExpires(b.expiresAt) : l0.expires_at,
        b.maxViews !== undefined ? (b.maxViews ? Math.max(1, Math.min(1000000, Number(b.maxViews) || 0)) : null) : l0.max_views,
        b.allowDownload !== undefined ? !!b.allowDownload : l0.allow_download,
        b.revoked === true ? 'revoke' : b.revoked === false ? 'restore' : ''],
    );
    await audit(req, 'share.update', { targetType: 'video', targetId: v.id, details: { linkId: l.id, fields: Object.keys(b) } });
    return { link: linkOut(l) };
  });

  app.delete('/videos/:id/share-links/:linkId', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    await query('DELETE FROM share_links WHERE id = $1 AND video_id = $2', [req.params.linkId, v.id]);
    await audit(req, 'share.delete', { targetType: 'video', targetId: v.id, details: { linkId: req.params.linkId } });
    return { ok: true };
  });

  // --- Публичная часть: открыть ссылку ------------------------------------------------------
  // Возвращает состояние ссылки и, если пароль не нужен, сразу выдаёт cookie доступа.
  app.get('/share/:token', async (req, reply) => {
    const token = String(req.params.token || '');
    if (!/^[A-Za-z0-9_-]{8,64}$/.test(token)) throw notFound('Ссылка не найдена');
    const l = await one('SELECT * FROM share_links WHERE token = $1', [token]);
    if (!l) throw notFound('Ссылка не найдена или отозвана');
    const v = await loadVideo(l.video_id);
    if (!v || v.deleted_at || v.is_blocked) throw notFound('Видео недоступно');
    const state = linkState(l);
    const alreadyUnlocked = (req.shareTokens || []).includes(token);
    const base = { state, shortId: v.short_id, title: v.title, ownerName: v.owner_name, thumbnailUrl: mediaUrl(v.thumbnail_path), requiresPassword: !!l.password_hash, allowDownload: l.allow_download, expiresAt: l.expires_at };
    if (state !== 'ok' && !(state === 'exhausted' && alreadyUnlocked)) return { ...base, unlocked: false };
    if (alreadyUnlocked) return { ...base, unlocked: true, video: videoCard(v) };
    if (l.password_hash) return { ...base, unlocked: false };
    if (!(await bumpViews(l))) return { ...base, state: 'exhausted', unlocked: false };
    setShareCookie(req, reply, token, l.expires_at);
    return { ...base, unlocked: true, video: videoCard(v) };
  });

  app.post('/share/:token/unlock', async (req, reply) => {
    const token = String(req.params.token || '');
    if (!/^[A-Za-z0-9_-]{8,64}$/.test(token)) throw notFound('Ссылка не найдена');
    // защита от перебора пароля: 10 попыток в 10 минут с одного адреса
    const key = req.ip;
    const a = unlockAttempts.get(key) || { n: 0, at: Date.now() };
    if (Date.now() - a.at > 600000) { a.n = 0; a.at = Date.now(); }
    if (a.n >= 10) throw tooMany('Слишком много попыток, попробуйте позже');
    a.n++; unlockAttempts.set(key, a);
    if (unlockAttempts.size > 10000) unlockAttempts.delete(unlockAttempts.keys().next().value);

    const l = await one('SELECT * FROM share_links WHERE token = $1', [token]);
    if (!l) throw notFound('Ссылка не найдена или отозвана');
    const state = linkState(l);
    if (state !== 'ok') throw forbidden(state === 'expired' ? 'Срок действия ссылки истёк' : state === 'exhausted' ? 'Лимит открытий ссылки исчерпан' : 'Ссылка отозвана');
    if (l.password_hash && !verifyPassword(String(req.body?.password || ''), l.password_hash)) throw forbidden('Неверный пароль');
    const v = await loadVideo(l.video_id);
    if (!v || v.deleted_at || v.is_blocked) throw notFound('Видео недоступно');
    if (!(req.shareTokens || []).includes(token) && !(await bumpViews(l))) throw forbidden('Лимит открытий ссылки исчерпан');
    setShareCookie(req, reply, token, l.expires_at);
    unlockAttempts.delete(key);
    return { ok: true, shortId: v.short_id, video: videoCard(v), allowDownload: l.allow_download };
  });
}
