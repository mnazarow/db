// Комментарии: ветки ответов, лайки, закрепление, «сердечко» автора, модерация, жалобы.
import { one, query, many, tx } from '../db.js';
import { requireViewable, requireEditable, loadVideo } from './videos.js';
import { canEditVideo, isStaff } from '../lib/access.js';
import { commentOut } from '../lib/serialize.js';
import { badRequest, forbidden, notFound, paging } from '../lib/util.js';
import { notify, notifyAdmins, notifPrefs } from '../lib/notify.js';
import { templates } from '../lib/mailer.js';
import { audit } from '../lib/audit.js';
import { config } from '../config.js';
import { emitEvent } from '../lib/events.js';

const COMMENT_SELECT = (viewerId) => `c.*, u.display_name AS author_name, u.handle AS author_handle, u.avatar_path AS author_avatar, u.role AS author_role,
  v.owner_id AS video_owner_id, v.short_id AS video_short_id,
  ${viewerId ? `(SELECT value FROM comment_likes cl WHERE cl.comment_id = c.id AND cl.user_id = '${viewerId}')` : 'NULL'} AS viewer_like`;
const COMMENT_FROM = `comments c JOIN users u ON u.id = c.user_id JOIN videos v ON v.id = c.video_id`;

function visibleSql(user, alias = 'c') {
  // Скрытые/удалённые видны модераторам; «на проверке» — автору комментария, владельцу видео и модераторам
  if (isStaff(user)) return `${alias}.status <> 'deleted' OR ${alias}.reply_count > 0`;
  if (user) return `(${alias}.status = 'visible' OR (${alias}.status = 'held' AND (${alias}.user_id = '${user.id}' OR v.owner_id = '${user.id}')) OR (${alias}.status = 'deleted' AND ${alias}.reply_count > 0))`;
  return `(${alias}.status = 'visible' OR (${alias}.status = 'deleted' AND ${alias}.reply_count > 0))`;
}

function containsBlockedWords(text, words) {
  const t = String(text).toLowerCase();
  return (words || []).some((w) => w && t.includes(String(w).toLowerCase()));
}

async function loadComment(id, user) {
  return one(`SELECT ${COMMENT_SELECT(user?.id)} FROM ${COMMENT_FROM} WHERE c.id = $1`, [id]);
}

export default async function commentRoutes(app) {
  // Список корневых комментариев
  app.get('/videos/:id/comments', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const { limit, offset, page } = paging(req.query, 20, 100);
    const sort = req.query.sort === 'new' ? 'c.is_pinned DESC, c.created_at DESC' : 'c.is_pinned DESC, (c.like_count - c.dislike_count + least(c.reply_count, 5)) DESC, c.created_at DESC';
    const rows = await many(
      `SELECT ${COMMENT_SELECT(req.user?.id)} FROM ${COMMENT_FROM} WHERE c.video_id = $1 AND c.parent_id IS NULL AND (${visibleSql(req.user)}) ORDER BY ${sort} LIMIT $2 OFFSET $3`,
      [v.id, limit, offset],
    );
    const total = await one(`SELECT count(*)::int AS n FROM ${COMMENT_FROM} WHERE c.video_id = $1 AND c.parent_id IS NULL AND (${visibleSql(req.user)})`, [v.id]);
    return { comments: rows.map((r) => commentOut(r, req.user)), total: total.n, page, limit, commentsMode: v.comments_mode, totalAll: v.comment_count };
  });

  app.get('/comments/:id/replies', async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    await requireViewable(req, c.video_id);
    const { limit, offset } = paging(req.query, 50, 200);
    const rows = await many(
      `SELECT ${COMMENT_SELECT(req.user?.id)} FROM ${COMMENT_FROM} WHERE c.root_id = $1 AND c.parent_id IS NOT NULL AND (${visibleSql(req.user)}) ORDER BY c.created_at ASC LIMIT $2 OFFSET $3`,
      [c.id, limit, offset],
    );
    return { comments: rows.map((r) => commentOut(r, req.user)) };
  });

  // Создать комментарий / ответ
  app.post('/videos/:id/comments', { preHandler: app.requireActive, config: { rateLimit: { max: 30, timeWindow: '1 minute' } } }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    if (!req.settings['comments.enabled']) throw forbidden('Комментарии отключены администратором');
    if (v.comments_mode === 'disabled' && !canEditVideo(v, req.user)) throw forbidden('Комментарии к этому видео отключены');
    const body = String(req.body?.body || '').trim();
    const maxLen = req.settings['comments.max_length'] || 5000;
    if (!body) throw badRequest('Пустой комментарий');
    if (body.length > maxLen) throw badRequest(`Комментарий длиннее ${maxLen} символов`);
    let parent = null;
    if (req.body?.parentId) {
      parent = await one('SELECT * FROM comments WHERE id = $1 AND video_id = $2', [req.body.parentId, v.id]);
      if (!parent || parent.status === 'deleted') throw notFound('Комментарий, на который вы отвечаете, не найден');
    }
    const held = (v.comments_mode === 'held' && !canEditVideo(v, req.user)) || containsBlockedWords(body, req.settings['comments.blocked_words']);
    const status = held ? 'held' : 'visible';
    const rootId = parent ? (parent.root_id || parent.id) : null;
    const created = await tx(async (c) => {
      const row = await c.one(
        `INSERT INTO comments(video_id, user_id, parent_id, root_id, body, status) VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
        [v.id, req.user.id, parent?.id || null, rootId, body, status],
      );
      if (rootId) await c.query('UPDATE comments SET reply_count = reply_count + 1 WHERE id = $1', [rootId]);
      if (status === 'visible') {
        await c.query('UPDATE videos SET comment_count = comment_count + 1 WHERE id = $1', [v.id]);
        await c.query(`INSERT INTO video_stats_daily(video_id, day, comments) VALUES ($1, current_date, 1) ON CONFLICT (video_id, day) DO UPDATE SET comments = video_stats_daily.comments + 1`, [v.id]);
      }
      return row;
    });
    const link = `/watch/${v.short_id}?comment=${created.id}`;
    await emitEvent('comment.created', { comment: { id: created.id, videoId: v.id, videoShortId: v.short_id, userId: req.user.id, userName: req.user.display_name, parentId: created.parent_id, body: created.body, status, url: link } });
    // Уведомления
    if (status === 'visible') {
      const targets = new Map();
      if (parent && parent.user_id !== req.user.id) targets.set(parent.user_id, 'reply');
      if (v.owner_id !== req.user.id && !targets.has(v.owner_id)) targets.set(v.owner_id, 'comment');
      for (const [uid, kind] of targets) {
        const u = await one('SELECT id, email, prefs FROM users WHERE id = $1 AND status = $2', [uid, 'active']);
        if (!u) continue;
        const prefs = notifPrefs(u);
        if (!prefs.replies) continue;
        await notify(uid, {
          type: kind === 'reply' ? 'comment_reply' : 'comment',
          title: kind === 'reply' ? `${req.user.display_name} ответил(а) на ваш комментарий` : `${req.user.display_name} прокомментировал(а) ваше видео`,
          body: body.slice(0, 200), link, actorId: req.user.id, data: { videoId: v.id, commentId: created.id },
        }, { email: prefs.email && kind === 'reply' ? () => templates.commentReply({ to: u.email, actorName: req.user.display_name, videoTitle: v.title, text: body.slice(0, 500), url: config.baseUrl + link }) : null });
      }
    } else {
      // На проверке: уведомить владельца видео (он модерирует свои комментарии)
      if (v.owner_id !== req.user.id) await notify(v.owner_id, { type: 'comment_held', title: 'Комментарий ожидает проверки', body: body.slice(0, 200), link: `/studio/comments?status=held`, actorId: req.user.id, data: { videoId: v.id, commentId: created.id } });
    }
    const full = await loadComment(created.id, req.user);
    return { comment: commentOut(full, req.user), held };
  });

  app.patch('/comments/:id', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    if (c.user_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    if (c.status === 'deleted' || c.status === 'hidden') throw badRequest('Этот комментарий нельзя изменить');
    const body = String(req.body?.body || '').trim();
    if (!body) throw badRequest('Пустой комментарий');
    const maxLen = req.settings['comments.max_length'] || 5000;
    if (body.length > maxLen) throw badRequest(`Комментарий длиннее ${maxLen} символов`);
    // Правка проходит ту же проверку, что и новый комментарий: раньше в одобренный комментарий
    // можно было дописать стоп-слова (или обойти премодерацию), и он оставался на виду
    const v = await loadVideo(c.video_id);
    const trusted = isStaff(req.user) || canEditVideo(v, req.user);
    const hold = !trusted && c.status === 'visible' && (v.comments_mode === 'held' || containsBlockedWords(body, req.settings['comments.blocked_words']));
    await tx(async (t) => {
      await t.query(`UPDATE comments SET body = $2, edited_at = now(), updated_at = now()${hold ? ", status = 'held'" : ''} WHERE id = $1`, [c.id, body]);
      if (hold) await t.query('UPDATE videos SET comment_count = greatest(comment_count - 1, 0) WHERE id = $1', [v.id]);
    });
    if (hold && v.owner_id !== req.user.id) {
      await notify(v.owner_id, { type: 'comment_held', title: 'Изменённый комментарий ожидает проверки', body: body.slice(0, 200), link: '/studio/comments?status=held', actorId: req.user.id, data: { videoId: v.id, commentId: c.id } });
    }
    return { comment: commentOut(await loadComment(c.id, req.user), req.user), held: hold };
  });

  app.delete('/comments/:id', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    const v = await loadVideo(c.video_id);
    if (c.user_id !== req.user.id && !canEditVideo(v, req.user)) throw forbidden();
    await tx(async (t) => {
      const wasVisible = c.status === 'visible';
      if (c.reply_count > 0) await t.query(`UPDATE comments SET status = 'deleted', body = '', updated_at = now() WHERE id = $1`, [c.id]);
      else {
        await t.query('DELETE FROM comments WHERE id = $1', [c.id]);
        if (c.root_id) await t.query('UPDATE comments SET reply_count = greatest(reply_count - 1, 0) WHERE id = $1', [c.root_id]);
      }
      if (wasVisible) await t.query('UPDATE videos SET comment_count = greatest(comment_count - 1, 0) WHERE id = $1', [c.video_id]);
    });
    await audit(req, 'comment.delete', { targetType: 'comment', targetId: c.id, details: { byOther: c.user_id !== req.user.id } });
    return { ok: true };
  });

  app.post('/comments/:id/like', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    await requireViewable(req, c.video_id);
    const value = Number(req.body?.value);
    if (![1, -1, 0].includes(value)) throw badRequest();
    if (value === 0) await query('DELETE FROM comment_likes WHERE comment_id = $1 AND user_id = $2', [c.id, req.user.id]);
    else await query(`INSERT INTO comment_likes(comment_id, user_id, value) VALUES ($1,$2,$3) ON CONFLICT (comment_id, user_id) DO UPDATE SET value = EXCLUDED.value`, [c.id, req.user.id, value]);
    const upd = await one(`UPDATE comments SET like_count = (SELECT count(*) FROM comment_likes WHERE comment_id = $1 AND value = 1), dislike_count = (SELECT count(*) FROM comment_likes WHERE comment_id = $1 AND value = -1) WHERE id = $1 RETURNING like_count, dislike_count`, [c.id]);
    return { ok: true, value, likeCount: upd.like_count, dislikeCount: upd.dislike_count };
  });

  // Закрепить / «сердечко» — только владелец видео (или модератор)
  app.post('/comments/:id/pin', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    const v = await loadVideo(c.video_id);
    if (!canEditVideo(v, req.user)) throw forbidden();
    const pin = req.body?.pinned !== false;
    if (pin) await query('UPDATE comments SET is_pinned = false WHERE video_id = $1', [v.id]);
    await query('UPDATE comments SET is_pinned = $2 WHERE id = $1', [c.id, pin]);
    return { ok: true, pinned: pin };
  });
  app.post('/comments/:id/heart', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    const v = await loadVideo(c.video_id);
    if (!canEditVideo(v, req.user)) throw forbidden();
    const heart = req.body?.hearted !== false;
    await query('UPDATE comments SET is_hearted = $2 WHERE id = $1', [c.id, heart]);
    if (heart && c.user_id !== req.user.id) await notify(c.user_id, { type: 'comment_heart', title: `${req.user.display_name} отметил(а) ваш комментарий`, body: c.body.slice(0, 120), link: `/watch/${v.short_id}?comment=${c.id}`, actorId: req.user.id });
    return { ok: true, hearted: heart };
  });

  // Модерация комментария (владелец видео или модератор): approve | hide
  app.post('/comments/:id/moderate', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    const v = await loadVideo(c.video_id);
    if (!canEditVideo(v, req.user)) throw forbidden();
    const action = req.body?.action;
    if (!['approve', 'hide'].includes(action)) throw badRequest();
    const next = action === 'approve' ? 'visible' : 'hidden';
    if (next !== c.status) {
      await tx(async (t) => {
        await t.query('UPDATE comments SET status = $2, updated_at = now() WHERE id = $1', [c.id, next]);
        if (next === 'visible' && c.status !== 'visible') await t.query('UPDATE videos SET comment_count = comment_count + 1 WHERE id = $1', [v.id]);
        if (next !== 'visible' && c.status === 'visible') await t.query('UPDATE videos SET comment_count = greatest(comment_count - 1, 0) WHERE id = $1', [v.id]);
      });
    }
    await audit(req, 'comment.moderate', { targetType: 'comment', targetId: c.id, details: { action } });
    return { ok: true, status: next };
  });

  app.post('/comments/:id/report', { preHandler: app.requireActive }, async (req) => {
    const c = await loadComment(req.params.id, req.user);
    if (!c) throw notFound();
    const reason = String(req.body?.reason || '').slice(0, 100);
    if (!reason) throw badRequest('Укажите причину');
    const dup = await one(`SELECT 1 FROM reports WHERE reporter_id = $1 AND target_type = 'comment' AND target_id = $2 AND status = 'open'`, [req.user.id, c.id]);
    if (!dup) {
      await query(`INSERT INTO reports(reporter_id, target_type, target_id, reason, details) VALUES ($1,'comment',$2,$3,$4)`, [req.user.id, c.id, reason, String(req.body?.details || '').slice(0, 2000)]);
      await notifyAdmins({ type: 'report', title: 'Жалоба на комментарий', body: `${reason}: ${c.body.slice(0, 100)}`, link: '/admin/reports', actorId: req.user.id }, { moderators: true });
    }
    return { ok: true };
  });

  // Комментарии к моим видео (студия)
  app.get('/studio/comments', { preHandler: app.requireActive }, async (req) => {
    const { limit, offset, page } = paging(req.query, 30, 100);
    const status = ['held', 'visible', 'hidden'].includes(req.query.status) ? req.query.status : null;
    const params = [req.user.id];
    let where = `v.owner_id = $1 AND c.status <> 'deleted'`;
    if (status) { params.push(status); where += ` AND c.status = $${params.length}`; }
    if (req.query.videoId) { params.push(req.query.videoId); where += ` AND c.video_id = cv_uuid($${params.length})`; }
    const total = await one(`SELECT count(*)::int AS n FROM ${COMMENT_FROM} WHERE ${where}`, params);
    const rows = await many(`SELECT ${COMMENT_SELECT(req.user.id)}, v.title AS video_title FROM ${COMMENT_FROM} WHERE ${where} ORDER BY c.created_at DESC LIMIT $${params.length + 1} OFFSET $${params.length + 2}`, [...params, limit, offset]);
    return { comments: rows.map((r) => ({ ...commentOut(r, req.user), videoTitle: r.video_title, videoShortId: r.video_short_id })), total: total?.n || 0, page, limit };
  });
}
