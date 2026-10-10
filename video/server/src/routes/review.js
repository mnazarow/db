// Согласование видео: отправка рецензентам, замечания с таймкодами, решения.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, notFound } from '../lib/util.js';
import { canViewVideo, isStaff } from '../lib/access.js';
import { videoCard, userPublic } from '../lib/serialize.js';
import { notify } from '../lib/notify.js';
import { audit } from '../lib/audit.js';
import { emitEvent } from '../lib/events.js';
import { mediaUrl } from '../lib/urls.js';

async function loadVideo(idOrShort) {
  return one(`SELECT v.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar
              FROM videos v JOIN users u ON u.id = v.owner_id
              WHERE (v.id = cv_uuid($1) OR v.short_id = $1) AND v.deleted_at IS NULL`, [String(idOrShort)]);
}
const canManage = (v, user) => !!user && (v.owner_id === user.id || isStaff(user));

async function reviewOut(review, { viewer = null } = {}) {
  const reviewers = await many(
    `SELECT r.*, u.id AS uid, u.handle, u.display_name, u.avatar_path, u.role
     FROM review_reviewers r JOIN users u ON u.id = r.user_id WHERE r.review_id = $1 ORDER BY u.display_name`, [review.id]);
  const comments = await many(
    `SELECT c.*, u.handle, u.display_name, u.avatar_path FROM review_comments c JOIN users u ON u.id = c.user_id
     WHERE c.review_id = $1 ORDER BY c.at_sec NULLS LAST, c.id`, [review.id]);
  return {
    id: review.id,
    videoId: review.video_id,
    status: review.status,
    note: review.note || '',
    dueAt: review.due_at,
    requestedBy: review.requested_by,
    decidedAt: review.decided_at,
    createdAt: review.created_at,
    isReviewer: !!viewer && reviewers.some((r) => r.user_id === viewer.id),
    myDecision: viewer ? (reviewers.find((r) => r.user_id === viewer.id)?.decision || null) : null,
    reviewers: reviewers.map((r) => ({
      ...userPublic({ id: r.uid, handle: r.handle, display_name: r.display_name, avatar_path: r.avatar_path, role: r.role }),
      decision: r.decision, comment: r.comment || '', decidedAt: r.decided_at,
    })),
    comments: comments.map((c) => ({
      id: String(c.id), at: c.at_sec === null ? null : Number(c.at_sec), body: c.body, resolved: !!c.resolved,
      createdAt: c.created_at,
      user: { id: c.user_id, handle: c.handle, displayName: c.display_name, avatarUrl: mediaUrl(c.avatar_path) },
    })),
  };
}

/** Пересчитать итог: приняли все — «принято», хоть один вернул — «на доработке». */
async function recalcStatus(review) {
  const rows = await many('SELECT decision FROM review_reviewers WHERE review_id = $1', [review.id]);
  if (!rows.length) return review.status;
  if (rows.some((r) => r.decision === 'changes_requested')) return 'changes_requested';
  if (rows.every((r) => r.decision === 'approved')) return 'approved';
  return 'in_review';
}

export default async function reviewRoutes(app) {
  /** Текущее согласование видео (и история решений). */
  app.get('/videos/:id/review', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user))) throw forbidden('Нет доступа к этому видео');
    const review = await one('SELECT * FROM video_reviews WHERE video_id = $1 ORDER BY created_at DESC LIMIT 1', [v.id]);
    if (!review) return { review: null, canRequest: canManage(v, req.user), enabled: !!req.settings['review.enabled'] };
    const out = await reviewOut(review, { viewer: req.user });
    if (!out.isReviewer && !canManage(v, req.user)) throw forbidden('Согласование видно автору и рецензентам');
    return { review: out, canRequest: canManage(v, req.user), enabled: !!req.settings['review.enabled'] };
  });

  /** Отправить на согласование. */
  app.post('/videos/:id/review', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canManage(v, req.user)) throw forbidden('Отправить на согласование может автор видео или модератор');
    if (!req.settings['review.enabled']) throw badRequest('Согласование отключено администратором');
    const ids = [...new Set((Array.isArray(req.body?.reviewers) ? req.body.reviewers : []).map(String))].slice(0, 20);
    if (!ids.length) throw badRequest('Выберите хотя бы одного рецензента');
    const people = await many(`SELECT id, display_name, email FROM users WHERE id = ANY($1::uuid[]) AND status='active' AND deleted_at IS NULL`, [ids]);
    if (!people.length) throw badRequest('Рецензенты не найдены');
    const due = req.body?.dueAt ? new Date(req.body.dueAt) : null;
    await query(`UPDATE video_reviews SET status = 'cancelled', updated_at = now() WHERE video_id = $1 AND status = 'in_review'`, [v.id]);
    const review = await one(
      `INSERT INTO video_reviews(video_id, note, due_at, requested_by) VALUES ($1,$2,$3,$4) RETURNING *`,
      [v.id, String(req.body?.note || '').slice(0, 1000), due && !Number.isNaN(due.getTime()) ? due : null, req.user.id]);
    for (const p of people) {
      await query('INSERT INTO review_reviewers(review_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [review.id, p.id]);
      if (p.id !== req.user.id) {
        await notify(p.id, {
          type: 'review_requested', actorId: req.user.id,
          title: `${req.user.display_name} просит согласовать видео`,
          body: v.title, link: `/watch/${v.short_id}?panel=review`,
          image: v.thumbnail_path ? `/media/${v.thumbnail_path}` : null,
          data: { videoId: v.id, reviewId: review.id },
        });
      }
    }
    await query('UPDATE videos SET review_status = $2 WHERE id = $1', [v.id, 'in_review']);
    await audit(req, 'review.requested', { targetType: 'video', targetId: v.id, meta: { reviewers: people.length } });
    await emitEvent('review.requested', { video: { id: v.id, shortId: v.short_id, title: v.title, url: `/watch/${v.short_id}` }, reviewers: people.length });
    return { review: await reviewOut(review, { viewer: req.user }) };
  });

  /** Решение рецензента. */
  app.post('/videos/:id/review/decision', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    const review = await one(`SELECT * FROM video_reviews WHERE video_id = $1 AND status = 'in_review' ORDER BY created_at DESC LIMIT 1`, [v.id]);
    if (!review) throw badRequest('Видео не на согласовании');
    const mine = await one('SELECT * FROM review_reviewers WHERE review_id = $1 AND user_id = $2', [review.id, req.user.id]);
    if (!mine) throw forbidden('Вы не в списке рецензентов');
    const decision = req.body?.decision === 'approved' ? 'approved' : 'changes_requested';
    const comment = String(req.body?.comment || '').slice(0, 1000);
    if (decision === 'changes_requested' && !comment) throw badRequest('Напишите, что нужно исправить');
    await query('UPDATE review_reviewers SET decision = $3, comment = $4, decided_at = now() WHERE review_id = $1 AND user_id = $2',
      [review.id, req.user.id, decision, comment]);
    const status = await recalcStatus(review);
    const updated = await one(
      `UPDATE video_reviews SET status = $2, decided_at = CASE WHEN $2 <> 'in_review' THEN now() ELSE NULL END, updated_at = now()
       WHERE id = $1 RETURNING *`, [review.id, status]);
    await query('UPDATE videos SET review_status = $2 WHERE id = $1', [v.id, status === 'cancelled' ? null : status]);
    if (status !== 'in_review') {
      await notify(v.owner_id, {
        type: status === 'approved' ? 'review_approved' : 'review_changes', actorId: req.user.id,
        title: status === 'approved' ? 'Видео согласовано' : 'Видео вернули на доработку',
        body: `${v.title}${comment ? ` — ${comment}` : ''}`, link: `/watch/${v.short_id}?panel=review`,
        data: { videoId: v.id, reviewId: review.id },
      });
      await emitEvent(status === 'approved' ? 'review.approved' : 'review.changes_requested', {
        video: { id: v.id, shortId: v.short_id, title: v.title, url: `/watch/${v.short_id}` },
        by: req.user.display_name, comment,
      });
    }
    await audit(req, 'review.decision', { targetType: 'video', targetId: v.id, meta: { decision } });
    return { review: await reviewOut(updated, { viewer: req.user }) };
  });

  /** Замечание с привязкой к секунде. */
  app.post('/videos/:id/review/comments', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    const review = await one('SELECT * FROM video_reviews WHERE video_id = $1 ORDER BY created_at DESC LIMIT 1', [v.id]);
    if (!review) throw badRequest('Видео не на согласовании');
    const isReviewer = await one('SELECT 1 FROM review_reviewers WHERE review_id = $1 AND user_id = $2', [review.id, req.user.id]);
    if (!isReviewer && !canManage(v, req.user)) throw forbidden('Замечания оставляют рецензенты и автор');
    const body = String(req.body?.body || '').trim().slice(0, 1000);
    if (!body) throw badRequest('Пустое замечание');
    const at = req.body?.at === null || req.body?.at === undefined ? null : Math.max(0, Math.min(Number(v.duration) || 1e9, Number(req.body.at) || 0));
    await one('INSERT INTO review_comments(review_id, user_id, at_sec, body) VALUES ($1,$2,$3,$4) RETURNING id', [review.id, req.user.id, at, body]);
    if (req.user.id !== v.owner_id) {
      await notify(v.owner_id, {
        type: 'review_comment', actorId: req.user.id, title: 'Замечание при согласовании',
        body: `${v.title}: ${body.slice(0, 120)}`, link: `/watch/${v.short_id}?panel=review`, data: { videoId: v.id },
      });
    }
    return { review: await reviewOut(review, { viewer: req.user }) };
  });

  /** Отметить замечание исправленным (автор) или снять отметку. */
  app.patch('/videos/:id/review/comments/:cid', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canManage(v, req.user)) throw forbidden('Отмечать исправленным может автор видео');
    const review = await one('SELECT * FROM video_reviews WHERE video_id = $1 ORDER BY created_at DESC LIMIT 1', [v.id]);
    if (!review) throw badRequest('Видео не на согласовании');
    await query('UPDATE review_comments SET resolved = $3 WHERE id = $1 AND review_id = $2', [Number(req.params.cid), review.id, req.body?.resolved !== false]);
    return { review: await reviewOut(review, { viewer: req.user }) };
  });

  /** Отозвать согласование (автор). */
  app.delete('/videos/:id/review', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canManage(v, req.user)) throw forbidden();
    await query(`UPDATE video_reviews SET status = 'cancelled', updated_at = now() WHERE video_id = $1 AND status = 'in_review'`, [v.id]);
    await query('UPDATE videos SET review_status = NULL WHERE id = $1', [v.id]);
    reply.code(204);
  });

  /** Мои согласования: что я отправил и что просят проверить меня. */
  app.get('/studio/reviews', { preHandler: app.requireActive }, async (req) => {
    const incoming = await many(
      `SELECT r.*, v.short_id, v.title, v.thumbnail_path, v.duration, u.display_name AS author
       FROM video_reviews r JOIN videos v ON v.id = r.video_id JOIN review_reviewers rr ON rr.review_id = r.id
       LEFT JOIN users u ON u.id = v.owner_id
       WHERE rr.user_id = $1 AND v.deleted_at IS NULL AND r.status = 'in_review' ORDER BY r.due_at NULLS LAST, r.created_at`, [req.user.id]);
    const outgoing = await many(
      `SELECT r.*, v.short_id, v.title, v.thumbnail_path, v.duration,
              (SELECT count(*)::int FROM review_reviewers x WHERE x.review_id = r.id) AS reviewers,
              (SELECT count(*)::int FROM review_reviewers x WHERE x.review_id = r.id AND x.decision IS NOT NULL) AS decided
       FROM video_reviews r JOIN videos v ON v.id = r.video_id
       WHERE v.owner_id = $1 AND v.deleted_at IS NULL AND r.status IN ('in_review','changes_requested')
       ORDER BY r.created_at DESC LIMIT 50`, [req.user.id]);
    const map = (r) => ({
      id: r.id, videoId: r.video_id, shortId: r.short_id, title: r.title,
      thumbnailUrl: mediaUrl(r.thumbnail_path), duration: Number(r.duration) || 0,
      status: r.status, note: r.note || '', dueAt: r.due_at, author: r.author || null,
      reviewers: r.reviewers, decided: r.decided, createdAt: r.created_at,
    });
    return { incoming: incoming.map(map), outgoing: outgoing.map(map) };
  });

  /** Календарь публикаций: что и когда выходит (как контент-план в YouTube Studio). */
  app.get('/studio/calendar', { preHandler: app.requireActive }, async (req) => {
    const from = req.query.from ? new Date(req.query.from) : new Date(Date.now() - 7 * 86400000);
    const to = req.query.to ? new Date(req.query.to) : new Date(Date.now() + 60 * 86400000);
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) throw badRequest('Некорректный период');
    const mine = !isStaff(req.user) || req.query.scope === 'mine';
    const ownerSql = mine ? 'AND v.owner_id = $3' : '';
    const params = mine ? [from, to, req.user.id] : [from, to];

    const videos = await many(
      `SELECT v.id, v.short_id, v.title, v.thumbnail_path, v.scheduled_at, v.premiere, v.visibility, v.owner_id, u.display_name AS author
       FROM videos v JOIN users u ON u.id = v.owner_id
       WHERE v.deleted_at IS NULL AND v.scheduled_at IS NOT NULL AND v.scheduled_at BETWEEN $1 AND $2 ${ownerSql}
       ORDER BY v.scheduled_at`, params);
    const streams = await many(
      `SELECT s.id, s.short_id, s.title, s.scheduled_at, s.status, s.registration, s.owner_id, u.display_name AS author
       FROM live_streams s JOIN users u ON u.id = s.owner_id
       WHERE s.scheduled_at IS NOT NULL AND s.scheduled_at BETWEEN $1 AND $2 ${mine ? 'AND s.owner_id = $3' : ''}
       ORDER BY s.scheduled_at`, params);
    const assignments = await many(
      `SELECT a.id, a.title, a.due_at, a.kind, a.created_by, u.display_name AS author
       FROM assignments a LEFT JOIN users u ON u.id = a.created_by
       WHERE a.due_at IS NOT NULL AND a.due_at BETWEEN $1 AND $2 AND a.status = 'active' ${mine ? 'AND a.created_by = $3' : ''}
       ORDER BY a.due_at`, params);
    const reviews = await many(
      `SELECT r.id, r.due_at, v.short_id, v.title, v.owner_id, u.display_name AS author
       FROM video_reviews r JOIN videos v ON v.id = r.video_id LEFT JOIN users u ON u.id = v.owner_id
       WHERE r.status = 'in_review' AND r.due_at IS NOT NULL AND r.due_at BETWEEN $1 AND $2
         ${mine ? 'AND (v.owner_id = $3 OR EXISTS (SELECT 1 FROM review_reviewers x WHERE x.review_id = r.id AND x.user_id = $3))' : ''}
       ORDER BY r.due_at`, params);

    const events = [
      ...videos.map((v) => ({
        kind: v.premiere ? 'premiere' : 'publish', at: v.scheduled_at, title: v.title,
        url: `/watch/${v.short_id}`, thumbnailUrl: mediaUrl(v.thumbnail_path),
        author: v.author, meta: v.visibility,
      })),
      ...streams.map((s) => ({ kind: 'live', at: s.scheduled_at, title: s.title, url: `/live/${s.short_id}`, author: s.author, meta: s.registration ? 'вебинар' : 'эфир' })),
      ...assignments.map((a) => ({ kind: 'assignment', at: a.due_at, title: a.title || 'Обязательный просмотр', url: `/studio/assignments/${a.id}`, author: a.author, meta: 'срок' })),
      ...reviews.map((r) => ({ kind: 'review', at: r.due_at, title: r.title, url: `/watch/${r.short_id}?panel=review`, author: r.author, meta: 'согласование' })),
    ].sort((a, b) => new Date(a.at) - new Date(b.at));
    return { from, to, events };
  });
}
