// Панель администратора: статистика, пользователи и заявки, домены, приглашения, модерация, категории,
// настройки, задания, аудит, система, трансляции.
import path from 'node:path';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import os from 'node:os';
import { one, many, query, tx, pool } from '../../db.js';
import { config } from '../../config.js';
import { settingsForAdmin, setSettings, DEFAULTS, loadSettings, previewSettings, lockedSettingKeys } from '../../lib/settings.js';
import { portalEnabled } from '../../lib/portal.js';
import { userAdmin, userPublic, videoCard, commentOut, categoryOut, liveOut } from '../../lib/serialize.js';
import { badRequest, forbidden, notFound, conflict, paging, EMAIL_RE, slugify, extOf, likeEscape } from '../../lib/util.js';
import { hashPassword, randomToken } from '../../lib/crypto.js';
import { templates, sendMailNow, emailEnabled, sendTestMail } from '../../lib/mailer.js';
import { ldapTest } from '../../lib/ldap.js';
import { asrTest } from '../../lib/asr.js';
import { aiTest } from '../../lib/ai.js';
import { oidcTest } from '../../lib/oidc.js';
import { telegramTest, telegramStats } from '../../lib/telegram.js';
import { ragTest } from '../../lib/rag.js';
import { mediamtx } from '../../lib/mediamtx.js';
import { ffmpegVersion, listEncoders } from '../../lib/ffmpeg.js';
import { diskUsage, storage, ensureDir, removeDir, removeFile } from '../../lib/storage.js';
import { queueStats, retryJob, cancelJob, enqueue } from '../../lib/jobs.js';
import { audit } from '../../lib/audit.js';
import { notify, notifyAdmins, notifySubscribersNewVideo } from '../../lib/notify.js';
import { invalidateSessionCache } from '../../plugins/auth.js';
import { invalidateVideoCache } from '../media.js';
import { VIDEO_SELECT, VIDEO_FROM, loadVideo } from '../videos.js';
import { endStream } from '../live.js';
import { stats as wsStats } from '../../lib/realtime.js';
import { uniqueHandle } from '../auth.js';
import { handleFromEmail } from '../../lib/util.js';
import { emitEvent, eventUser } from '../../lib/events.js';
import { xapiTest } from '../../lib/xapi.js';
import { ocrAvailable } from '../../lib/ocr.js';
import { backfillAudioTracks, sendDigests, scanWatchFolder } from '../../jobs/platform.js';
import { mediaUrl } from '../../lib/urls.js';

export default async function adminRoutes(app) {
  app.addHook('preHandler', app.requireStaff);

  // --- Дашборд -----------------------------------------------------------------
  app.get('/stats', async () => {
    const users = await one(`SELECT count(*)::int AS total, count(*) FILTER (WHERE status = 'active')::int AS active, count(*) FILTER (WHERE status = 'pending_approval')::int AS pending,
      count(*) FILTER (WHERE status = 'pending_email')::int AS pending_email, count(*) FILTER (WHERE status = 'blocked')::int AS blocked,
      count(*) FILTER (WHERE last_seen_at > now() - interval '1 day')::int AS active_today, count(*) FILTER (WHERE created_at > now() - interval '7 days')::int AS new_week FROM users WHERE deleted_at IS NULL`);
    const videos = await one(`SELECT count(*)::int AS total, count(*) FILTER (WHERE status = 'ready')::int AS ready, count(*) FILTER (WHERE status IN ('queued','processing'))::int AS processing,
      count(*) FILTER (WHERE status = 'failed')::int AS failed, count(*) FILTER (WHERE moderation_status = 'pending')::int AS pending_moderation,
      coalesce(sum(storage_bytes),0)::bigint AS bytes, coalesce(sum(view_count),0)::bigint AS views, coalesce(sum(duration),0)::bigint AS duration,
      count(*) FILTER (WHERE created_at > now() - interval '7 days')::int AS new_week FROM videos WHERE deleted_at IS NULL`);
    const today = await one(`SELECT coalesce(sum(views),0)::int AS views, coalesce(sum(watch_seconds),0)::bigint AS watch FROM video_stats_daily WHERE day = current_date`);
    const week = await many(`SELECT day, sum(views)::int AS views, sum(watch_seconds)::bigint AS watch FROM video_stats_daily WHERE day >= current_date - 13 GROUP BY day ORDER BY day`);
    const reports = await one(`SELECT count(*)::int AS open FROM reports WHERE status = 'open'`);
    const comments = await one(`SELECT count(*)::int AS held FROM comments WHERE status = 'held'`);
    const live = await one(`SELECT count(*)::int AS n FROM live_streams WHERE status = 'live'`);
    const jobs = await queueStats();
    const disk = await diskUsage();
    const topVideos = await many(`SELECT ${VIDEO_SELECT}, coalesce((SELECT sum(views) FROM video_stats_daily d WHERE d.video_id = v.id AND d.day >= current_date - 7),0)::int AS week_views FROM ${VIDEO_FROM} WHERE v.deleted_at IS NULL AND v.status = 'ready' ORDER BY week_views DESC, v.view_count DESC LIMIT 5`);
    const recentUsers = await many(`SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5`);
    return {
      users: { total: users.total, active: users.active, pending: users.pending, pendingEmail: users.pending_email, blocked: users.blocked, activeToday: users.active_today, newWeek: users.new_week },
      videos: { total: videos.total, ready: videos.ready, processing: videos.processing, failed: videos.failed, pendingModeration: videos.pending_moderation, bytes: Number(videos.bytes), views: Number(videos.views), durationSeconds: Number(videos.duration), newWeek: videos.new_week },
      today: { views: today.views, watchSeconds: Number(today.watch) },
      daily: week.map((d) => ({ day: d.day, views: d.views, watchSeconds: Number(d.watch) })),
      reportsOpen: reports.open, commentsHeld: comments.held, liveNow: live.n, jobs, disk,
      topVideos: topVideos.map((v) => ({ ...videoCard(v), weekViews: v.week_views })),
      recentUsers: recentUsers.map(userAdmin),
      version: config.version,
    };
  });

  // --- Пользователи ---------------------------------------------------------------
  app.get('/users', async (req) => {
    const { limit, offset, page } = paging(req.query, 30, 200);
    const params = [];
    const where = ['u.deleted_at IS NULL'];
    if (req.query.status) { params.push(String(req.query.status)); where.push(`u.status = $${params.length}`); }
    if (req.query.role) { params.push(String(req.query.role)); where.push(`u.role = $${params.length}`); }
    if (req.query.provider) { params.push(String(req.query.provider)); where.push(`u.auth_provider = $${params.length}`); }
    if (req.query.q) { params.push(`%${likeEscape(String(req.query.q).trim())}%`); where.push(`(u.email ILIKE $${params.length} OR u.display_name ILIKE $${params.length} OR u.handle ILIKE $${params.length})`); }
    const sort = { name: 'u.display_name ASC', last_seen: 'u.last_seen_at DESC NULLS LAST', videos: 'u.video_count DESC', oldest: 'u.created_at ASC' }[req.query.sort] || 'u.created_at DESC';
    const total = await one(`SELECT count(*)::int AS n FROM users u WHERE ${where.join(' AND ')}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT u.*, (SELECT coalesce(sum(storage_bytes),0) FROM videos v WHERE v.owner_id = u.id AND v.deleted_at IS NULL)::bigint AS storage_bytes FROM users u WHERE ${where.join(' AND ')} ORDER BY ${sort} LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    const counts = await one(`SELECT count(*) FILTER (WHERE status = 'pending_approval')::int AS pending, count(*) FILTER (WHERE status = 'active')::int AS active, count(*) FILTER (WHERE status = 'blocked')::int AS blocked, count(*) FILTER (WHERE status = 'pending_email')::int AS pending_email, count(*) FILTER (WHERE status = 'rejected')::int AS rejected FROM users WHERE deleted_at IS NULL`);
    return { users: rows.map((u) => ({ ...userAdmin(u), storageBytes: Number(u.storage_bytes) })), total: total.n, page, limit, counts: { pending: counts.pending, active: counts.active, blocked: counts.blocked, pendingEmail: counts.pending_email, rejected: counts.rejected } };
  });

  app.get('/users/:id', async (req) => {
    const u = await one('SELECT * FROM users WHERE id = $1', [req.params.id]);
    if (!u) throw notFound('Пользователь не найден');
    const videos = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.owner_id = $1 AND v.deleted_at IS NULL ORDER BY v.created_at DESC LIMIT 20`, [u.id]);
    const sessions = await many('SELECT id, created_at, last_seen_at, ip, user_agent FROM sessions WHERE user_id = $1 AND expires_at > now() ORDER BY last_seen_at DESC', [u.id]);
    const log = await many('SELECT * FROM audit_log WHERE actor_id = $1 OR (target_type = $2 AND target_id = $3) ORDER BY created_at DESC LIMIT 30', [u.id, 'user', u.id]);
    return { user: userAdmin(u), videos: videos.map((v) => videoCard(v)), sessions: sessions.map((s) => ({ id: s.id.slice(0, 8), createdAt: s.created_at, lastSeenAt: s.last_seen_at, ip: s.ip, userAgent: s.user_agent })), audit: log };
  });

  app.patch('/users/:id', { preHandler: app.requireAdmin }, async (req) => {
    const u = await one('SELECT * FROM users WHERE id = $1', [req.params.id]);
    if (!u) throw notFound();
    const b = req.body || {};
    // Сотрудник портала документации: роль, имя, почта, права загрузки и вход задаются в портале.
    if (portalEnabled() && u.auth_provider === 'portal') {
      const managed = ['role', 'displayName', 'email', 'canUpload', 'canStream', 'password', 'totpReset'].filter((k) => b[k] !== undefined && b[k] !== '' && b[k] !== false);
      if (managed.length) throw badRequest('Роль, имя, почта, права загрузки и вход сотрудника задаются в портале документации: Панель администратора → Пользователи. Здесь можно сменить только имя канала.');
    }
    const sets = []; const params = [u.id];
    const add = (c, v) => { params.push(v); sets.push(`${c} = $${params.length}`); };
    if (b.role !== undefined) {
      if (!['user', 'moderator', 'admin'].includes(b.role)) throw badRequest('Недопустимая роль');
      if (u.id === req.user.id && b.role !== 'admin') throw badRequest('Нельзя снять права администратора с самого себя');
      add('role', b.role);
    }
    if (b.displayName !== undefined) add('display_name', String(b.displayName).trim().slice(0, 80));
    if (b.email !== undefined) { const em = String(b.email).trim().toLowerCase(); if (!EMAIL_RE.test(em)) throw badRequest('Некорректный e-mail'); const dup = await one('SELECT 1 FROM users WHERE email = $1 AND id <> $2', [em, u.id]); if (dup) throw conflict('E-mail уже используется'); add('email', em); }
    if (b.canUpload !== undefined) add('can_upload', !!b.canUpload);
    if (b.canStream !== undefined) add('can_stream', !!b.canStream);
    if (b.handle !== undefined) { const h = String(b.handle).trim().toLowerCase().replace(/^@/, ''); if (!/^[a-z0-9][a-z0-9._-]{1,29}$/.test(h)) throw badRequest('Некорректное имя канала'); const dup = await one('SELECT 1 FROM users WHERE handle = $1 AND id <> $2', [h, u.id]); if (dup) throw conflict('Имя канала занято'); add('handle', h); }
    if (b.password !== undefined && b.password) { if (String(b.password).length < 8) throw badRequest('Пароль слишком короткий'); add('password_hash', hashPassword(String(b.password))); add('auth_provider', 'local'); }
    if (b.totpReset) { add('totp_enabled', false); add('totp_secret', null); }
    if (!sets.length) return { user: userAdmin(u) };
    sets.push('updated_at = now()');
    await query(`UPDATE users SET ${sets.join(', ')} WHERE id = $1`, params);
    invalidateSessionCache(null, u.id);
    await audit(req, 'admin.user_update', { targetType: 'user', targetId: u.id, details: Object.keys(b) });
    return { user: userAdmin(await one('SELECT * FROM users WHERE id = $1', [u.id])) };
  });

  async function setStatus(req, id, status, extra = {}) {
    const u = await one('SELECT * FROM users WHERE id = $1', [id]);
    if (!u) throw notFound('Пользователь не найден');
    if (u.id === req.user.id && status !== 'active') throw badRequest('Нельзя изменить статус собственной учётной записи');
    await query('UPDATE users SET status = $2, approved_at = CASE WHEN $2 = $3 THEN now() ELSE approved_at END, approved_by = CASE WHEN $2 = $3 THEN $4 ELSE approved_by END, rejection_note = $5, updated_at = now() WHERE id = $1',
      [u.id, status, 'active', req.user.id, extra.note || null]);
    if (status === 'blocked' || status === 'rejected') { await query('DELETE FROM sessions WHERE user_id = $1', [u.id]); }
    invalidateSessionCache(null, u.id);
    await audit(req, `admin.user_${status}`, { targetType: 'user', targetId: u.id, details: extra });
    return u;
  }

  app.post('/users/:id/approve', async (req) => {
    const u = await setStatus(req, req.params.id, 'active');
    await notify(u.id, { type: 'account_approved', title: 'Учётная запись одобрена', body: 'Теперь вам доступны все возможности портала', link: '/' });
    await emitEvent('user.approved', { user: eventUser(u), byUserId: req.user.id });
    await templates.approved({ to: u.email, name: u.display_name, url: config.baseUrl });
    return { ok: true };
  });
  app.post('/users/:id/reject', async (req) => {
    const note = String(req.body?.note || '').slice(0, 500);
    const u = await setStatus(req, req.params.id, 'rejected', { note });
    await templates.rejected({ to: u.email, name: u.display_name, note });
    return { ok: true };
  });
  app.post('/users/:id/block', async (req) => {
    const note = String(req.body?.note || '').slice(0, 500);
    await setStatus(req, req.params.id, 'blocked', { note });
    return { ok: true };
  });
  app.post('/users/:id/unblock', async (req) => {
    await setStatus(req, req.params.id, 'active');
    return { ok: true };
  });
  app.post('/users/bulk', async (req) => {
    const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(String).slice(0, 500) : [];
    const action = req.body?.action;
    let n = 0;
    for (const id of ids) {
      try {
        if (action === 'approve') { const u = await setStatus(req, id, 'active'); await templates.approved({ to: u.email, name: u.display_name, url: config.baseUrl }); n++; }
        else if (action === 'reject') { const u = await setStatus(req, id, 'rejected'); await templates.rejected({ to: u.email, name: u.display_name }); n++; }
        else if (action === 'block') { await setStatus(req, id, 'blocked'); n++; }
        else if (action === 'unblock') { await setStatus(req, id, 'active'); n++; }
      } catch { /* пропускаем */ }
    }
    return { ok: true, processed: n };
  });

  app.delete('/users/:id', { preHandler: app.requireAdmin }, async (req) => {
    const u = await one('SELECT * FROM users WHERE id = $1', [req.params.id]);
    if (!u) throw notFound();
    if (u.id === req.user.id) throw badRequest('Нельзя удалить самого себя');
    const withVideos = req.query.videos === 'delete';
    await tx(async (c) => {
      if (withVideos) {
        const vids = await c.many('SELECT id FROM videos WHERE owner_id = $1 AND deleted_at IS NULL', [u.id]);
        for (const v of vids) { await c.query('UPDATE videos SET deleted_at = now() WHERE id = $1', [v.id]); await removeDir(storage.videoDir(v.id)).catch(() => {}); }
      }
      await c.query('DELETE FROM sessions WHERE user_id = $1', [u.id]);
      await c.query(`UPDATE users SET deleted_at = now(), status = 'blocked', email = email || '.deleted.' || extract(epoch from now())::bigint, handle = handle || '.deleted.' || extract(epoch from now())::bigint, password_hash = NULL, totp_secret = NULL WHERE id = $1`, [u.id]);
    });
    invalidateSessionCache(null, u.id);
    await audit(req, 'admin.user_delete', { targetType: 'user', targetId: u.id, details: { email: u.email, withVideos } });
    return { ok: true };
  });

  app.post('/users/:id/password-reset-link', { preHandler: app.requireAdmin }, async (req) => {
    if (portalEnabled()) throw badRequest('Пароли сотрудников — в портале документации.');
    const u = await one('SELECT * FROM users WHERE id = $1', [req.params.id]);
    if (!u) throw notFound();
    const token = randomToken(32);
    await query(`INSERT INTO tokens(token, type, user_id, email, expires_at, created_by) VALUES ($1,'reset_password',$2,$3, now() + interval '24 hours', $4)`, [token, u.id, u.email, req.user.id]);
    const url = `${config.baseUrl}/reset-password?token=${token}`;
    const sent = await templates.resetPassword({ to: u.email, name: u.display_name, url });
    await audit(req, 'admin.user_reset_link', { targetType: 'user', targetId: u.id });
    return { ok: true, url, sent };
  });

  app.post('/users/create', { preHandler: app.requireAdmin }, async (req) => {
    if (portalEnabled()) throw badRequest('Учётные записи заводятся в портале документации (Панель администратора → Пользователи) — в видео сотрудник появится при первом входе.');
    const b = req.body || {};
    const em = String(b.email || '').trim().toLowerCase();
    if (!EMAIL_RE.test(em)) throw badRequest('Некорректный e-mail');
    const dup = await one('SELECT 1 FROM users WHERE email = $1', [em]);
    if (dup) throw conflict('Пользователь уже существует');
    const name = String(b.displayName || em.split('@')[0]).trim().slice(0, 80);
    const password = String(b.password || '');
    if (password.length < 8) throw badRequest('Пароль не короче 8 символов');
    const role = ['user', 'moderator', 'admin'].includes(b.role) ? b.role : 'user';
    const handle = await uniqueHandle(handleFromEmail(em));
    const u = await one(`INSERT INTO users(email, handle, display_name, password_hash, role, status, email_verified_at, approved_at, approved_by) VALUES ($1,$2,$3,$4,$5,'active', now(), now(), $6) RETURNING *`, [em, handle, name, hashPassword(password), role, req.user.id]);
    await audit(req, 'admin.user_create', { targetType: 'user', targetId: u.id, details: { email: em, role } });
    return { user: userAdmin(u) };
  });

  // --- Приглашения ------------------------------------------------------------------
  app.get('/invites', { preHandler: app.requireAdmin }, async () => {
    const rows = await many(`SELECT t.*, u.display_name AS inviter FROM tokens t LEFT JOIN users u ON u.id = t.created_by WHERE t.type = 'invite' AND t.used_at IS NULL AND t.expires_at > now() ORDER BY t.created_at DESC`);
    return { invites: rows.map((t) => ({ token: t.token, email: t.email, role: t.payload?.role || 'user', inviter: t.inviter, createdAt: t.created_at, expiresAt: t.expires_at, url: `${config.baseUrl}/invite/${t.token}` })) };
  });
  app.post('/invites', { preHandler: app.requireAdmin }, async (req) => {
    const em = String(req.body?.email || '').trim().toLowerCase();
    if (!EMAIL_RE.test(em)) throw badRequest('Некорректный e-mail');
    const dup = await one('SELECT 1 FROM users WHERE email = $1', [em]);
    if (dup) throw conflict('Пользователь с таким адресом уже существует');
    const role = req.user.role === 'admin' && ['user', 'moderator', 'admin'].includes(req.body?.role) ? req.body.role : 'user';
    await query(`UPDATE tokens SET used_at = now() WHERE type = 'invite' AND email = $1 AND used_at IS NULL`, [em]);
    const token = randomToken(24);
    await query(`INSERT INTO tokens(token, type, email, payload, expires_at, created_by) VALUES ($1,'invite',$2,$3::jsonb, now() + interval '7 days', $4)`, [token, em, JSON.stringify({ role }), req.user.id]);
    const url = `${config.baseUrl}/invite/${token}`;
    const sent = await templates.invite({ to: em, url, inviterName: req.user.display_name });
    await audit(req, 'admin.invite', { targetType: 'invite', targetId: em, details: { role } });
    return { ok: true, url, sent };
  });
  app.delete('/invites/:token', { preHandler: app.requireAdmin }, async (req) => {
    await query(`UPDATE tokens SET used_at = now() WHERE token = $1 AND type = 'invite'`, [req.params.token]);
    return { ok: true };
  });

  // --- Домены ---------------------------------------------------------------------
  app.get('/domains', async () => {
    const rows = await many('SELECT d.*, (SELECT count(*)::int FROM users u WHERE u.email ILIKE \'%@\' || d.domain AND u.deleted_at IS NULL) AS users FROM allowed_domains d ORDER BY d.domain');
    return { domains: rows.map((d) => ({ id: d.id, domain: d.domain, autoApprove: d.auto_approve, note: d.note, users: d.users, createdAt: d.created_at })) };
  });
  app.post('/domains', { preHandler: app.requireAdmin }, async (req) => {
    const domain = String(req.body?.domain || '').trim().toLowerCase().replace(/^@/, '');
    if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(domain)) throw badRequest('Некорректный домен (пример: vodokomfort.ru)');
    const row = await one('INSERT INTO allowed_domains(domain, auto_approve, note, created_by) VALUES ($1,$2,$3,$4) ON CONFLICT (domain) DO UPDATE SET auto_approve = EXCLUDED.auto_approve, note = EXCLUDED.note RETURNING *', [domain, !!req.body?.autoApprove, String(req.body?.note || '').slice(0, 200), req.user.id]);
    await audit(req, 'admin.domain_add', { targetType: 'domain', targetId: domain, details: { autoApprove: !!req.body?.autoApprove } });
    return { domain: { id: row.id, domain: row.domain, autoApprove: row.auto_approve, note: row.note } };
  });
  app.patch('/domains/:id', { preHandler: app.requireAdmin }, async (req) => {
    const row = await one('UPDATE allowed_domains SET auto_approve = COALESCE($2, auto_approve), note = COALESCE($3, note) WHERE id = $1 RETURNING *', [Number(req.params.id), req.body?.autoApprove === undefined ? null : !!req.body.autoApprove, req.body?.note === undefined ? null : String(req.body.note).slice(0, 200)]);
    if (!row) throw notFound();
    return { domain: { id: row.id, domain: row.domain, autoApprove: row.auto_approve, note: row.note } };
  });
  app.delete('/domains/:id', { preHandler: app.requireAdmin }, async (req) => {
    const row = await one('DELETE FROM allowed_domains WHERE id = $1 RETURNING *', [Number(req.params.id)]);
    if (row) await audit(req, 'admin.domain_delete', { targetType: 'domain', targetId: row.domain });
    return { ok: true };
  });

  // --- Видео и модерация ----------------------------------------------------------------
  app.get('/videos', async (req) => {
    const { limit, offset, page } = paging(req.query, 30, 200);
    const params = [];
    const where = [req.query.deleted === '1' ? 'v.deleted_at IS NOT NULL' : 'v.deleted_at IS NULL'];
    if (req.query.status) { params.push(String(req.query.status)); where.push(`v.status = $${params.length}`); }
    if (req.query.visibility) { params.push(String(req.query.visibility)); where.push(`v.visibility = $${params.length}`); }
    if (req.query.moderation) { params.push(String(req.query.moderation)); where.push(`v.moderation_status = $${params.length}`); }
    if (req.query.blocked === '1') where.push('v.is_blocked = true');
    if (req.query.owner) { params.push(String(req.query.owner)); where.push(`(v.owner_id = cv_uuid($${params.length}) OR u.handle = $${params.length} OR u.email = $${params.length})`); }
    if (req.query.q) { params.push(`%${likeEscape(String(req.query.q).trim())}%`); where.push(`(v.title ILIKE $${params.length} OR u.display_name ILIKE $${params.length})`); }
    const sort = { views: 'v.view_count DESC', size: 'v.storage_bytes DESC', title: 'v.title ASC', oldest: 'v.created_at ASC', reports: '(SELECT count(*) FROM reports r WHERE r.target_type = \'video\' AND r.target_id = v.id AND r.status = \'open\') DESC' }[req.query.sort] || 'v.created_at DESC';
    const total = await one(`SELECT count(*)::int AS n FROM ${VIDEO_FROM} WHERE ${where.join(' AND ')}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT ${VIDEO_SELECT}, u.email AS owner_email, (SELECT count(*)::int FROM reports r WHERE r.target_type = 'video' AND r.target_id = v.id AND r.status = 'open') AS open_reports FROM ${VIDEO_FROM} WHERE ${where.join(' AND ')} ORDER BY ${sort} LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    return { videos: rows.map((v) => ({ ...videoCard(v), storageBytes: v.storage_bytes, ownerEmail: v.owner_email, openReports: v.open_reports, moderationNote: v.moderation_note, blockReason: v.block_reason, processingError: v.processing_error, deletedAt: v.deleted_at, tags: v.tags })), total: total.n, page, limit };
  });

  async function moderate(req, id, status, note) {
    const v = await loadVideo(id);
    if (!v) throw notFound('Видео не найдено');
    await query('UPDATE videos SET moderation_status = $2, moderation_note = $3, moderated_by = $4, moderated_at = now(), updated_at = now() WHERE id = $1', [v.id, status, note || null, req.user.id]);
    invalidateVideoCache(v.id);
    await audit(req, `admin.video_${status}`, { targetType: 'video', targetId: v.id, details: { note } });
    const owner = await one('SELECT * FROM users WHERE id = $1', [v.owner_id]);
    const link = `/watch/${v.short_id}`;
    await notify(v.owner_id, { type: 'moderation', title: status === 'approved' ? 'Видео одобрено' : 'Видео отклонено', body: v.title + (note ? `: ${note}` : ''), link, data: { videoId: v.id } },
      { email: () => templates.videoModerated({ to: owner.email, videoTitle: v.title, approved: status === 'approved', note, url: config.baseUrl + link }) });
    if (status === 'approved' && v.status === 'ready' && ['public', 'internal'].includes(v.visibility)) await notifySubscribersNewVideo(v, owner).catch(() => {});
    return v;
  }
  app.post('/videos/:id/approve', async (req) => { await moderate(req, req.params.id, 'approved', String(req.body?.note || '').slice(0, 500)); return { ok: true }; });
  app.post('/videos/:id/reject', async (req) => { await moderate(req, req.params.id, 'rejected', String(req.body?.note || '').slice(0, 500)); return { ok: true }; });
  app.post('/videos/:id/block', async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound();
    const reason = String(req.body?.reason || '').slice(0, 500);
    await query('UPDATE videos SET is_blocked = true, block_reason = $2, updated_at = now() WHERE id = $1', [v.id, reason]);
    invalidateVideoCache(v.id);
    await audit(req, 'admin.video_block', { targetType: 'video', targetId: v.id, details: { reason } });
    await notify(v.owner_id, { type: 'video_blocked', title: 'Видео заблокировано администратором', body: `${v.title}${reason ? `: ${reason}` : ''}`, link: `/studio/videos/${v.id}` });
    return { ok: true };
  });
  app.post('/videos/:id/unblock', async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound();
    await query('UPDATE videos SET is_blocked = false, block_reason = NULL, updated_at = now() WHERE id = $1', [v.id]);
    invalidateVideoCache(v.id);
    await audit(req, 'admin.video_unblock', { targetType: 'video', targetId: v.id });
    return { ok: true };
  });
  app.post('/videos/:id/restore', async (req) => {
    const v = await one('SELECT * FROM videos WHERE id = cv_uuid($1) OR short_id = $1', [req.params.id]);
    if (!v || !v.deleted_at) throw notFound();
    if (!v.hls_path) throw badRequest('Файлы видео были удалены — восстановление невозможно');
    await query('UPDATE videos SET deleted_at = NULL WHERE id = $1', [v.id]);
    invalidateVideoCache(v.id);
    return { ok: true };
  });
  app.post('/videos/:id/transfer', { preHandler: app.requireAdmin }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound();
    const target = await one('SELECT * FROM users WHERE (id = cv_uuid($1) OR email = $1 OR handle = $1) AND deleted_at IS NULL', [String(req.body?.user || '')]);
    if (!target) throw notFound('Пользователь не найден');
    await query('UPDATE videos SET owner_id = $2, updated_at = now() WHERE id = $1', [v.id, target.id]);
    await query('UPDATE users SET video_count = (SELECT count(*) FROM videos WHERE owner_id = users.id AND deleted_at IS NULL AND status = $2) WHERE id IN ($1, $3)', [v.owner_id, 'ready', target.id]);
    await audit(req, 'admin.video_transfer', { targetType: 'video', targetId: v.id, details: { from: v.owner_id, to: target.id } });
    return { ok: true };
  });
  app.post('/videos/bulk', async (req) => {
    const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(String).slice(0, 500) : [];
    const action = req.body?.action;
    let n = 0;
    for (const id of ids) {
      try {
        if (action === 'approve') { await moderate(req, id, 'approved', ''); n++; }
        else if (action === 'reject') { await moderate(req, id, 'rejected', String(req.body?.note || '')); n++; }
        else if (action === 'visibility' && ['public', 'internal', 'unlisted', 'private'].includes(req.body?.visibility)) { await query('UPDATE videos SET visibility = $2 WHERE id = cv_uuid($1)', [id, req.body.visibility]); invalidateVideoCache(id); n++; }
        else if (action === 'category') { await query('UPDATE videos SET category_id = $2 WHERE id = cv_uuid($1)', [id, req.body?.categoryId ? Number(req.body.categoryId) : null]); n++; }
        else if (action === 'reprocess') { const v = await one('SELECT id FROM videos WHERE id = cv_uuid($1) AND original_kept', [id]); if (v) { await query(`UPDATE videos SET status = 'queued', processing_progress = 0 WHERE id = $1`, [v.id]); await enqueue('transcode', { videoId: v.id }, { videoId: v.id }); n++; } }
      } catch { /* пропускаем */ }
    }
    return { ok: true, processed: n };
  });

  // --- Комментарии -------------------------------------------------------------------
  app.get('/comments', async (req) => {
    const { limit, offset, page } = paging(req.query, 30, 200);
    const params = [];
    const where = ["c.status <> 'deleted'"];
    if (req.query.status) { params.push(String(req.query.status)); where.push(`c.status = $${params.length}`); }
    if (req.query.q) { params.push(`%${likeEscape(String(req.query.q).trim())}%`); where.push(`(c.body ILIKE $${params.length} OR u.display_name ILIKE $${params.length})`); }
    const total = await one(`SELECT count(*)::int AS n FROM comments c JOIN users u ON u.id = c.user_id WHERE ${where.join(' AND ')}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT c.*, u.display_name AS author_name, u.handle AS author_handle, u.avatar_path AS author_avatar, u.role AS author_role, v.owner_id AS video_owner_id, v.title AS video_title, v.short_id AS video_short_id, NULL AS viewer_like
      FROM comments c JOIN users u ON u.id = c.user_id JOIN videos v ON v.id = c.video_id WHERE ${where.join(' AND ')} ORDER BY c.created_at DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    return { comments: rows.map((r) => ({ ...commentOut(r, req.user), videoTitle: r.video_title, videoShortId: r.video_short_id })), total: total.n, page, limit };
  });
  app.post('/comments/:id/status', async (req) => {
    const status = req.body?.status;
    if (!['visible', 'hidden', 'held'].includes(status)) throw badRequest();
    const c = await one('SELECT * FROM comments WHERE id = $1', [req.params.id]);
    if (!c) throw notFound();
    await tx(async (t) => {
      await t.query('UPDATE comments SET status = $2, updated_at = now() WHERE id = $1', [c.id, status]);
      if (status === 'visible' && c.status !== 'visible') await t.query('UPDATE videos SET comment_count = comment_count + 1 WHERE id = $1', [c.video_id]);
      if (status !== 'visible' && c.status === 'visible') await t.query('UPDATE videos SET comment_count = greatest(comment_count - 1, 0) WHERE id = $1', [c.video_id]);
    });
    await audit(req, 'admin.comment_status', { targetType: 'comment', targetId: c.id, details: { status } });
    return { ok: true };
  });
  app.delete('/comments/:id', async (req) => {
    const c = await one('SELECT * FROM comments WHERE id = $1', [req.params.id]);
    if (!c) throw notFound();
    await tx(async (t) => {
      if (c.reply_count > 0) await t.query(`UPDATE comments SET status = 'deleted', body = '' WHERE id = $1`, [c.id]);
      else { await t.query('DELETE FROM comments WHERE id = $1', [c.id]); if (c.root_id) await t.query('UPDATE comments SET reply_count = greatest(reply_count - 1, 0) WHERE id = $1', [c.root_id]); }
      if (c.status === 'visible') await t.query('UPDATE videos SET comment_count = greatest(comment_count - 1, 0) WHERE id = $1', [c.video_id]);
    });
    await audit(req, 'admin.comment_delete', { targetType: 'comment', targetId: c.id });
    return { ok: true };
  });

  // --- Жалобы -------------------------------------------------------------------------
  app.get('/reports', async (req) => {
    const { limit, offset, page } = paging(req.query, 30, 200);
    const status = ['open', 'resolved', 'dismissed'].includes(req.query.status) ? req.query.status : 'open';
    const rows = await many(`SELECT r.*, u.display_name AS reporter_name, u.handle AS reporter_handle FROM reports r LEFT JOIN users u ON u.id = r.reporter_id WHERE r.status = $1 ORDER BY r.created_at DESC LIMIT $2 OFFSET $3`, [status, limit, offset]);
    const total = await one('SELECT count(*)::int AS n FROM reports WHERE status = $1', [status]);
    const out = [];
    for (const r of rows) {
      let target = null;
      if (r.target_type === 'video') { const v = await loadVideo(r.target_id); target = v ? { title: v.title, shortId: v.short_id, owner: v.owner_name, deleted: !!v.deleted_at, blocked: v.is_blocked } : null; }
      else if (r.target_type === 'comment') { const c = await one('SELECT c.*, v.short_id, u.display_name FROM comments c JOIN videos v ON v.id = c.video_id JOIN users u ON u.id = c.user_id WHERE c.id = $1', [r.target_id]); target = c ? { body: c.body, status: c.status, videoShortId: c.short_id, author: c.display_name } : null; }
      else if (r.target_type === 'user') { const u = await one('SELECT * FROM users WHERE id = $1', [r.target_id]); target = u ? { displayName: u.display_name, handle: u.handle, status: u.status } : null; }
      else if (r.target_type === 'live') { const s = await one('SELECT * FROM live_streams WHERE id = $1', [r.target_id]); target = s ? { title: s.title, shortId: s.short_id, status: s.status } : null; }
      out.push({ id: r.id, targetType: r.target_type, targetId: r.target_id, reason: r.reason, details: r.details, status: r.status, createdAt: r.created_at, resolvedAt: r.resolved_at, resolution: r.resolution, reporter: r.reporter_name ? { displayName: r.reporter_name, handle: r.reporter_handle } : null, target });
    }
    return { reports: out, total: total.n, page, limit };
  });
  app.post('/reports/:id/resolve', async (req) => {
    const status = req.body?.dismiss ? 'dismissed' : 'resolved';
    await query('UPDATE reports SET status = $2, resolved_by = $3, resolved_at = now(), resolution = $4 WHERE id = $1', [req.params.id, status, req.user.id, String(req.body?.resolution || '').slice(0, 1000)]);
    await audit(req, 'admin.report_' + status, { targetType: 'report', targetId: req.params.id });
    return { ok: true };
  });

  // --- Категории ------------------------------------------------------------------------
  app.get('/categories', async () => ({ categories: (await many('SELECT * FROM categories ORDER BY sort_order, name')).map(categoryOut) }));
  app.post('/categories', { preHandler: app.requireAdmin }, async (req) => {
    const name = String(req.body?.name || '').trim().slice(0, 80);
    if (!name) throw badRequest('Укажите название');
    let slug = String(req.body?.slug || slugify(name)).toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/^-+|-+$/g, '') || 'cat';
    const dup = await one('SELECT 1 FROM categories WHERE slug = $1', [slug]);
    if (dup) slug = `${slug}-${Date.now().toString(36)}`;
    const max = await one('SELECT coalesce(max(sort_order), 0) + 10 AS s FROM categories');
    const c = await one('INSERT INTO categories(slug, name, description, icon, sort_order) VALUES ($1,$2,$3,$4,$5) RETURNING *', [slug, name, String(req.body?.description || '').slice(0, 500), String(req.body?.icon || 'folder').slice(0, 40), max.s]);
    return { category: categoryOut(c) };
  });
  app.patch('/categories/:id', { preHandler: app.requireAdmin }, async (req) => {
    const b = req.body || {};
    const c = await one('UPDATE categories SET name = COALESCE($2, name), description = COALESCE($3, description), icon = COALESCE($4, icon), is_active = COALESCE($5, is_active), sort_order = COALESCE($6, sort_order) WHERE id = $1 RETURNING *',
      [Number(req.params.id), b.name ? String(b.name).trim().slice(0, 80) : null, b.description === undefined ? null : String(b.description).slice(0, 500), b.icon ? String(b.icon).slice(0, 40) : null, b.isActive === undefined ? null : !!b.isActive, b.sortOrder === undefined ? null : Number(b.sortOrder)]);
    if (!c) throw notFound();
    return { category: categoryOut(c) };
  });
  app.delete('/categories/:id', { preHandler: app.requireAdmin }, async (req) => {
    await query('DELETE FROM categories WHERE id = $1', [Number(req.params.id)]);
    return { ok: true };
  });
  app.put('/categories/order', { preHandler: app.requireAdmin }, async (req) => {
    const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(Number) : [];
    for (let i = 0; i < ids.length; i++) await query('UPDATE categories SET sort_order = $2 WHERE id = $1', [ids[i], (i + 1) * 10]);
    return { ok: true };
  });

  // --- Настройки -------------------------------------------------------------------------
  app.get('/settings', { preHandler: app.requireAdmin }, async () => ({ settings: await settingsForAdmin(), defaults: DEFAULTS, locked: lockedSettingKeys() }));
  app.put('/settings', { preHandler: app.requireAdmin }, async (req) => {
    const entries = req.body?.settings || {};
    if (entries['site.accent_color'] && !/^#[0-9a-fA-F]{3,8}$/.test(String(entries['site.accent_color']).trim())) throw badRequest('Фирменный цвет — в виде #RRGGBB (например, #0F4382)');
    if (entries['site.accent_color']) entries['site.accent_color'] = String(entries['site.accent_color']).trim();
    await setSettings(entries, req.user.id);
    await audit(req, 'admin.settings', { details: { keys: Object.keys(entries) } });
    return { settings: await settingsForAdmin() };
  });
  // Кнопки «Проверить» работают с настройками из формы, не сохраняя их: раньше проверка сначала
  // записывала форму, и неудачная проверка необратимо меняла рабочую конфигурацию.
  app.post('/settings/test-smtp', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    const to = String(req.body?.to || req.user.email);
    try { const r = await sendTestMail(s, to); return { ok: true, messageId: r?.messageId }; } catch (e) { throw badRequest('Ошибка SMTP: ' + e.message); }
  });
  app.post('/settings/test-ldap', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try { const r = await ldapTest(s); return { ok: true, ...r }; } catch (e) { throw badRequest('Ошибка LDAP: ' + e.message); }
  });
  app.post('/settings/test-ldap-login', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    const { ldapAuthenticate } = await import('../../lib/ldap.js');
    try { const info = await ldapAuthenticate(String(req.body?.login || ''), String(req.body?.password || ''), s); return { ok: true, user: { dn: info.dn, email: info.email, name: info.name, login: info.login, groups: info.groups.slice(0, 20), isAdmin: info.isAdmin } }; } catch (e) { throw badRequest('Ошибка входа LDAP: ' + e.message); }
  });
  app.post('/settings/test-ai', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try { const r = await aiTest(s); return { ok: true, answer: r.answer }; } catch (e) { throw badRequest('Сервер ИИ недоступен: ' + e.message); }
  });
  app.post('/settings/test-oidc', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try { const r = await oidcTest(s); return { ok: true, ...r, redirectUri: `${config.baseUrl}/api/auth/oidc/callback` }; } catch (e) { throw badRequest('Провайдер SSO недоступен: ' + e.message); }
  });
  app.post('/settings/test-telegram', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try {
      const r = await telegramTest(s);
      // Имя бота запоминаем сразу, только если проверяли уже сохранённый токен; иначе его сохранит кнопка «Сохранить»
      const stored = await loadSettings(true);
      if (r.username && r.username !== stored['telegram.bot_username'] && stored['telegram.bot_token'] === s['telegram.bot_token']) await setSettings({ 'telegram.bot_username': r.username }, req.user.id);
      return { ok: true, username: r.username, name: r.name, ...(await telegramStats()) };
    } catch (e) { throw badRequest('Telegram: ' + e.message); }
  });
  app.post('/settings/test-rag', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try { const r = await ragTest(s); return { ok: true, status: r.status }; } catch (e) { throw badRequest('RAG: ' + e.message); }
  });
  app.post('/settings/test-xapi', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try { const r = await xapiTest(s, req.user); return { ok: true, status: r.status }; } catch (e) { throw badRequest('LRS: ' + e.message); }
  });
  app.post('/settings/test-ocr', { preHandler: app.requireAdmin }, async () => {
    const r = await ocrAvailable();
    if (!r.available) throw badRequest('tesseract не найден на сервере: установите пакет tesseract-ocr (и tesseract-ocr-rus)');
    return { ok: true, version: r.version, languages: r.languages };
  });
  app.post('/settings/test-asr', { preHandler: app.requireAdmin }, async (req) => {
    const s = await previewSettings(req.body?.settings);
    try { const r = await asrTest(s); return { ok: r.ok, status: r.status }; } catch (e) { throw badRequest('Сервер ASR недоступен: ' + e.message); }
  });

  // Логотип и иконка сайта
  app.post('/branding/:kind', { preHandler: app.requireAdmin }, async (req) => {
    const kind = req.params.kind;
    if (!['logo', 'favicon', 'logo_dark'].includes(kind)) throw badRequest();
    const part = await req.file({ limits: { fileSize: 4 * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл');
    const ext = extOf(part.filename);
    if (!['svg', 'png', 'jpg', 'jpeg', 'webp', 'ico'].includes(ext)) throw badRequest('Поддерживаются SVG, PNG, JPG, ICO');
    const dir = path.join(storage.root, 'branding');
    await ensureDir(dir);
    const file = path.join(dir, `${kind}_${Date.now()}.${ext}`);
    await pipeline(part.file, fs.createWriteStream(file));
    const rel = storage.rel(file);
    await setSettings({ [`site.${kind}_path`]: rel }, req.user.id);
    return { url: mediaUrl(rel) };
  });
  app.delete('/branding/:kind', { preHandler: app.requireAdmin }, async (req) => {
    const kind = req.params.kind;
    if (!['logo', 'favicon', 'logo_dark'].includes(kind)) throw badRequest();
    await setSettings({ [`site.${kind}_path`]: '' }, req.user.id);
    return { ok: true };
  });

  // --- Задания ----------------------------------------------------------------------------
  app.get('/jobs', async (req) => {
    const { limit, offset, page } = paging(req.query, 40, 200);
    const params = [];
    const where = ['1=1'];
    if (req.query.status) { params.push(String(req.query.status)); where.push(`j.status = $${params.length}`); }
    if (req.query.type) { params.push(String(req.query.type)); where.push(`j.type = $${params.length}`); }
    const total = await one(`SELECT count(*)::int AS n FROM jobs j WHERE ${where.join(' AND ')}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT j.*, v.title AS video_title, v.short_id AS video_short_id FROM jobs j LEFT JOIN videos v ON v.id = j.video_id WHERE ${where.join(' AND ')} ORDER BY j.status = 'running' DESC, j.status = 'queued' DESC, j.id DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    return {
      jobs: rows.map((j) => ({ id: j.id, type: j.type, status: j.status, progress: j.progress, stage: j.stage, attempts: j.attempts, maxAttempts: j.max_attempts, error: j.error, lockedBy: j.locked_by, createdAt: j.created_at, startedAt: j.started_at, finishedAt: j.finished_at, runAt: j.run_at, videoId: j.video_id, videoTitle: j.video_title, videoShortId: j.video_short_id, payload: j.type === 'email' ? { to: j.payload?.to, subject: j.payload?.subject } : j.payload, result: j.result })),
      total: total.n, page, limit, stats: await queueStats(),
    };
  });
  app.post('/jobs/:id/retry', async (req) => ({ ok: !!(await retryJob(Number(req.params.id))) }));
  app.post('/jobs/:id/cancel', async (req) => {
    const r = await cancelJob(Number(req.params.id));
    return { ok: !!r, outcome: r?.outcome || 'not-found' };
  });
  // Задания, которые меняют сам файл видео, повторять массово нельзя: монтаж применится второй раз
  // к уже смонтированному, появится лишняя версия и лишние клипы. Их перезапускают поштучно.
  const NOT_BULK_RETRYABLE = ['video_edit', 'clip_create', 'video_render', 'video_blur', 'remove_silence', 'audio_track'];
  app.post('/jobs/retry-failed', async () => {
    const r = await query(`UPDATE jobs SET status = 'queued', run_at = now(), error = NULL, attempts = 0 WHERE status = 'failed' AND NOT (type = ANY($1))`, [NOT_BULK_RETRYABLE]);
    const skipped = await one(`SELECT count(*)::int AS n FROM jobs WHERE status = 'failed' AND type = ANY($1)`, [NOT_BULK_RETRYABLE]);
    return { ok: true, count: r.rowCount, skipped: skipped.n };
  });

  // --- Аудит --------------------------------------------------------------------------------
  app.get('/audit', async (req) => {
    const { limit, offset, page } = paging(req.query, 50, 500);
    const params = [];
    const where = ['1=1'];
    if (req.query.action) { params.push(`${String(req.query.action)}%`); where.push(`a.action LIKE $${params.length}`); }
    if (req.query.actor) { params.push(String(req.query.actor)); where.push(`(a.actor_id = cv_uuid($${params.length}) OR u.email = $${params.length} OR u.handle = $${params.length})`); }
    if (req.query.target) { params.push(String(req.query.target)); where.push(`a.target_id = $${params.length}`); }
    if (req.query.from) { params.push(String(req.query.from)); where.push(`a.created_at >= $${params.length}::timestamptz`); }
    if (req.query.to) { params.push(String(req.query.to)); where.push(`a.created_at <= $${params.length}::timestamptz`); }
    const total = await one(`SELECT count(*)::int AS n FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE ${where.join(' AND ')}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT a.*, u.display_name AS actor_name, u.email AS actor_email FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE ${where.join(' AND ')} ORDER BY a.id DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    return { entries: rows.map((r) => ({ id: r.id, action: r.action, targetType: r.target_type, targetId: r.target_id, details: r.details, ip: r.ip, createdAt: r.created_at, actor: r.actor_id ? { id: r.actor_id, name: r.actor_name, email: r.actor_email } : null })), total: total.n, page, limit };
  });

  // --- Система --------------------------------------------------------------------------------
  app.get('/system', async () => {
    const pg = await one('SELECT version() AS v');
    const dbSize = await one('SELECT pg_database_size(current_database())::bigint AS s');
    const media = await one('SELECT coalesce(sum(storage_bytes),0)::bigint AS s FROM videos WHERE deleted_at IS NULL');
    const workers = await many(`SELECT locked_by, count(*)::int AS n, max(heartbeat_at) AS last FROM jobs WHERE status = 'running' GROUP BY locked_by`);
    const recentWorkers = await many(`SELECT locked_by, max(finished_at) AS last FROM jobs WHERE finished_at > now() - interval '1 day' AND locked_by IS NOT NULL GROUP BY locked_by`);
    return {
      version: config.version, node: process.version, platform: `${os.type()} ${os.release()} ${os.arch()}`, hostname: os.hostname(),
      uptime: Math.round(process.uptime()), memory: process.memoryUsage().rss, cpus: os.cpus().length, load: os.loadavg(),
      postgres: pg.v, dbSize: Number(dbSize.s), mediaBytes: Number(media.s), disk: await diskUsage(),
      ffmpeg: await ffmpegVersion(), encoders: await listEncoders(), mediamtx: await mediamtx.available(), ws: wsStats(), ocr: await ocrAvailable(),
      dataDir: config.dataDir, mediaDir: config.mediaDir, baseUrl: config.baseUrl, workersRunning: workers, workersRecent: recentWorkers,
      emailEnabled: await emailEnabled(),
    };
  });
  app.post('/system/recompute-storage', async () => { await enqueue('recompute_storage', {}, { dedupe: false }); return { ok: true }; });
  app.post('/system/reindex', async () => {
    const r = await query(`UPDATE videos SET title = title`);
    return { ok: true, count: r.rowCount };
  });
  app.post('/system/maintenance', async () => { await enqueue('maintenance', { hourly: true, daily: true }, { dedupe: false }); return { ok: true }; });
  // 1.3: аудиодорожки для старых видео, пробный дайджест, проверка папки автоимпорта, OCR для всех
  app.post('/system/audio-backfill', { preHandler: app.requireAdmin }, async () => ({ ok: true, queued: await backfillAudioTracks(500) }));
  app.post('/system/digest-test', { preHandler: app.requireAdmin }, async (req) => {
    const s = await loadSettings(true);
    if (!s['smtp.enabled']) throw badRequest('Почта (SMTP) не настроена');
    await query('UPDATE users SET digest_sent_at = NULL WHERE id = $1', [req.user.id]);
    const before = await one('SELECT count(*)::int AS n FROM users WHERE id = $1', [req.user.id]);
    const sent = await sendDigests(s, { force: true, onlyUserId: req.user.id });
    return { ok: true, sent, users: before.n };
  });
  app.post('/system/watch-scan', { preHandler: app.requireAdmin }, async (req) => {
    const s = await loadSettings(true);
    if (!s['import.watch_dir']) throw badRequest('Каталог автоимпорта не задан');
    const r = await scanWatchFolder({ ...s, 'import.watch_enabled': true }, req.log, { minAgeMs: 0, settleMs: 500 });
    const log = await many('SELECT * FROM import_watch_log ORDER BY id DESC LIMIT 50');
    return { ok: true, ...r, log: log.map((l) => ({ id: Number(l.id), filename: l.filename, size: Number(l.size), status: l.status, error: l.error, videoId: l.video_id, createdAt: l.created_at })) };
  });
  app.get('/system/watch-log', { preHandler: app.requireAdmin }, async () => {
    const log = await many('SELECT * FROM import_watch_log ORDER BY id DESC LIMIT 100');
    return { log: log.map((l) => ({ id: Number(l.id), filename: l.filename, size: Number(l.size), status: l.status, error: l.error, videoId: l.video_id, createdAt: l.created_at })) };
  });
  app.post('/system/ocr-all', { preHandler: app.requireAdmin }, async (req) => {
    const s = await loadSettings(true);
    if (!s['ocr.enabled']) throw badRequest('Распознавание текста на экране выключено');
    const rows = await many(`SELECT id FROM videos WHERE deleted_at IS NULL AND status = 'ready' AND (ocr_status IS NULL OR ($1 AND ocr_status = 'failed')) ORDER BY published_at DESC NULLS LAST LIMIT 500`, [!!req.body?.retryFailed]);
    for (const r of rows) { await query(`UPDATE videos SET ocr_status = 'queued' WHERE id = $1`, [r.id]); await enqueue('ocr', { videoId: r.id }, { videoId: r.id, priority: -3 }); }
    return { ok: true, queued: rows.length };
  });
  app.post('/system/announce', { preHandler: app.requireAdmin }, async (req) => {
    // Разослать уведомление всем активным пользователям
    const title = String(req.body?.title || '').slice(0, 150);
    const body = String(req.body?.body || '').slice(0, 1000);
    if (!title) throw badRequest('Укажите заголовок');
    // Рассылка — фоновым заданием: в запросе она обрывалась по таймауту на больших порталах
    const n = await one(`SELECT count(*)::int AS n FROM users WHERE status = 'active' AND deleted_at IS NULL`);
    const job = await enqueue('announce', { title, body, link: req.body?.link ? String(req.body.link).slice(0, 300) : null, actorId: req.user.id }, { dedupe: false, maxAttempts: 1, priority: 2 });
    await audit(req, 'admin.announce', { details: { title, recipients: n.n, jobId: job.id } });
    return { ok: true, recipients: n.n, queued: true, jobId: job.id };
  });

  // --- Трансляции ----------------------------------------------------------------------------
  app.get('/live', async (req) => {
    const rows = await many(`SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar, u.email AS owner_email FROM live_streams s JOIN users u ON u.id = s.owner_id ORDER BY s.status = 'live' DESC, s.created_at DESC LIMIT 200`);
    return { streams: rows.map((s) => ({ ...liveOut(s, { viewer: req.user }), ownerEmail: s.owner_email })) };
  });
  app.post('/live/:id/end', async (req) => {
    const s = await one('SELECT * FROM live_streams WHERE id = $1', [req.params.id]);
    if (!s) throw notFound();
    try { await mediamtx.kick(`live/${s.stream_key}`); } catch { /* ignore */ }
    // Тот же путь, что у кнопки в студии: событие и вебхук, остановка ретрансляций, импорт записи
    const ended = s.status === 'live' ? await endStream(s, 'admin') : false;
    await audit(req, 'admin.live_end', { targetType: 'live', targetId: s.id, details: { title: s.title, ended } });
    return { ok: true, ended };
  });
  app.delete('/live/:id', async (req) => {
    const s = await one('SELECT * FROM live_streams WHERE id = $1', [req.params.id]);
    if (!s) throw notFound();
    if (s.status === 'live') {
      try { await mediamtx.kick(`live/${s.stream_key}`); } catch { /* ignore */ }
      // Трансляцию удаляют — запись импортировать некуда
      await endStream(s, 'admin', { importRecording: false });
    }
    await query('DELETE FROM live_streams WHERE id = $1', [s.id]);
    await audit(req, 'admin.live_delete', { targetType: 'live', targetId: s.id, details: { title: s.title } });
    return { ok: true };
  });
}
