// Видео: просмотр, редактирование, миниатюры, субтитры, реакции, прогресс просмотра, аналитика, доступ.
import path from 'node:path';
import fsp from 'node:fs/promises';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import { one, query, many, tx } from '../db.js';
import { canViewVideo, canEditVideo, isStaff, listVisibilitySql } from '../lib/access.js';
import { videoFull, videoCard, subtitleOut, userPublic } from '../lib/serialize.js';
import { badRequest, forbidden, notFound, unauthorized, paging, normalizeTags, parseChapters, toVtt, vttToText, deviceFromUa, extOf, EMAIL_RE, intOrNull} from '../lib/util.js';
import { storage, ensureDir, removeDir, removeFile, exists } from '../lib/storage.js';
import { resizeImage, frameAt } from '../lib/ffmpeg.js';
import { enqueue, cancelJob } from '../lib/jobs.js';
import { audit } from '../lib/audit.js';
import { invalidateVideoCache } from './media.js';
import { notify, notifyAdmins, notifySubscribersNewVideo } from '../lib/notify.js';
import { randomToken, sign, unsign } from '../lib/crypto.js';
import { config } from '../config.js';
import { updateAssignmentProgress, assignmentsForUserVideo } from '../lib/assignments.js';
import { updateCourseProgressForVideo } from '../lib/courses.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { emitEvent, eventVideo } from '../lib/events.js';
import { mediaUrl, stripBasePath } from '../lib/urls.js';

export const VIDEO_SELECT = `v.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar, u.subscriber_count AS owner_subscribers, c.name AS category_name, c.slug AS category_slug`;
export const VIDEO_FROM = `videos v JOIN users u ON u.id = v.owner_id LEFT JOIN categories c ON c.id = v.category_id`;

export async function loadVideo(idOrShort) {
  return one(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE (v.id = cv_uuid($1) OR v.short_id = $1)`, [String(idOrShort)]);
}

export async function requireViewable(req, idOrShort) {
  const v = await loadVideo(idOrShort);
  if (!v || v.deleted_at) throw notFound('Видео не найдено');
  if (!(await canViewVideo(v, req.user, req.shareTokens))) {
    if (!req.user) throw unauthorized('Для просмотра этого видео необходимо войти');
    throw forbidden('У вас нет доступа к этому видео');
  }
  return v;
}

export async function requireEditable(req, idOrShort) {
  const v = await loadVideo(idOrShort);
  if (!v || v.deleted_at) throw notFound('Видео не найдено');
  if (!canEditVideo(v, req.user)) throw forbidden('Редактировать видео может только автор или модератор');
  return v;
}

const VIEW_THRESHOLD_SEC = 10;

/** Списки персонального и группового доступа к приватному видео (для редактора). */
async function accessLists(videoId) {
  const rows = await many('SELECT u.id, u.handle, u.display_name, u.avatar_path, u.email FROM video_access a JOIN users u ON u.id = a.user_id WHERE a.video_id = $1 ORDER BY u.display_name', [videoId]);
  const accessUsers = rows.map((r) => ({ ...userPublic(r), email: r.email }));
  const accessGroups = (await many('SELECT g.id, g.name, g.member_count FROM video_group_access a JOIN groups g ON g.id = a.group_id WHERE a.video_id = $1 ORDER BY g.name', [videoId]))
    .map((g) => ({ id: g.id, name: g.name, memberCount: g.member_count }));
  return { accessUsers, accessGroups };
}

export default async function videoRoutes(app) {
  // --- Просмотр -----------------------------------------------------------
  app.get('/:id', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const [renditions, subtitles] = await Promise.all([
      many('SELECT * FROM video_renditions WHERE video_id = $1 ORDER BY height DESC', [v.id]),
      many(`SELECT * FROM subtitles WHERE video_id = $1 AND (status = 'ready' OR $2) ORDER BY is_default DESC, language`, [v.id, canEditVideo(v, req.user)]),
    ]);
    let userLike = 0, subscribed = false, inWatchLater = false, position = 0, accessUsers, accessGroups;
    if (req.user) {
      const [like, sub, wl, prog] = await Promise.all([
        one('SELECT value FROM video_likes WHERE video_id = $1 AND user_id = $2', [v.id, req.user.id]),
        one('SELECT 1 FROM subscriptions WHERE subscriber_id = $1 AND channel_id = $2', [req.user.id, v.owner_id]),
        one(`SELECT 1 FROM playlist_items pi JOIN playlists p ON p.id = pi.playlist_id WHERE p.owner_id = $1 AND p.kind = 'watch_later' AND pi.video_id = $2`, [req.user.id, v.id]),
        one('SELECT position, completed FROM video_views WHERE video_id = $1 AND user_id = $2 ORDER BY last_at DESC LIMIT 1', [v.id, req.user.id]),
      ]);
      userLike = like?.value || 0; subscribed = !!sub; inWatchLater = !!wl;
      position = prog && !prog.completed ? Number(prog.position) : 0;
      if (canEditVideo(v, req.user) && v.visibility === 'private') ({ accessUsers, accessGroups } = await accessLists(v.id));
    }
    const out = videoFull(v, { renditions, subtitles, viewer: req.user, userLike, subscribed, inWatchLater, accessUsers, accessGroups });
    if (out.viewer) {
      out.viewer.position = position;
      // Назначение «к обязательному просмотру» для этого зрителя (ближайший срок)
      const list = (await assignmentsForUserVideo(req.user.id, v.id)).sort((a, b) => (a.due_at ? new Date(a.due_at) : Infinity) - (b.due_at ? new Date(b.due_at) : Infinity));
      if (list.length) {
        const a = list[0];
        const p = await one('SELECT percent, completed_at, quiz_passed FROM assignment_progress WHERE assignment_id = $1 AND user_id = $2 AND video_id = $3', [a.id, req.user.id, v.id]);
        const cert = a.certificate ? await one('SELECT id FROM certificates WHERE assignment_id = $1 AND user_id = $2 AND revoked_at IS NULL', [a.id, req.user.id]) : null;
        out.viewer.assignment = { id: a.id, title: a.title, note: a.note, dueAt: a.due_at, requiredPercent: a.required_percent, requireQuiz: a.require_quiz, percent: p?.percent || 0, completed: !!p?.completed_at, quizPassed: p?.quiz_passed ?? null, overdue: !!(a.due_at && new Date(a.due_at) < new Date() && !p?.completed_at), attentionCheckMin: a.attention_check_min || 0, certificate: !!a.certificate, certificateId: cert?.id || null };
      }
    }
    if (!req.user && req.shareTokens?.length) out.viewer = { guestLink: true };
    return { video: out };
  });

  // Похожие видео
  app.get('/:id/related', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const { limit } = paging(req.query, 20, 50);
    // Релевантность считаем не по всей библиотеке, а по кандидатам, которые находятся по индексам:
    // та же категория, тот же автор, общие теги, слова названия, плюс популярное и новое как запас.
    const rows = await many(
      `WITH q AS (SELECT plainto_tsquery('russian', unaccent($5)) AS aq),
       qo AS (SELECT CASE WHEN numnode(aq) = 0 THEN NULL ELSE replace(aq::text, ' & ', ' | ')::tsquery END AS oq FROM q),
       cand AS (
         (SELECT id FROM videos WHERE category_id = $2 AND deleted_at IS NULL AND id <> $1 ORDER BY published_at DESC NULLS LAST LIMIT 150)
         UNION (SELECT id FROM videos WHERE owner_id = $3 AND deleted_at IS NULL AND id <> $1 ORDER BY published_at DESC NULLS LAST LIMIT 100)
         UNION (SELECT id FROM videos WHERE cardinality($4::text[]) > 0 AND tags && $4::text[] AND deleted_at IS NULL AND id <> $1 LIMIT 200)
         UNION (SELECT x.id FROM videos x, qo WHERE qo.oq IS NOT NULL AND x.search_vector @@ qo.oq AND x.deleted_at IS NULL AND x.id <> $1 LIMIT 200)
         UNION (SELECT id FROM videos WHERE deleted_at IS NULL AND id <> $1 ORDER BY view_count DESC LIMIT 50)
         UNION (SELECT id FROM videos WHERE deleted_at IS NULL AND id <> $1 ORDER BY published_at DESC NULLS LAST LIMIT 50)
       )
       SELECT ${VIDEO_SELECT},
         (CASE WHEN v.category_id = $2 THEN 2 ELSE 0 END)
         + (CASE WHEN v.owner_id = $3 THEN 1.5 ELSE 0 END)
         + coalesce(array_length(array(SELECT unnest(v.tags) INTERSECT SELECT unnest($4::text[])), 1), 0) * 1.2
         + coalesce(ts_rank(v.search_vector, coalesce(qo.oq, (SELECT aq FROM q))), 0) * 3
         + least(log(greatest(v.view_count,1)), 6) * 0.2
         + (CASE WHEN v.published_at > now() - interval '30 days' THEN 0.5 ELSE 0 END) AS score
       FROM ${VIDEO_FROM} JOIN cand ON cand.id = v.id CROSS JOIN qo
       WHERE ${listVisibilitySql(req.user)}
       ORDER BY score DESC, v.published_at DESC LIMIT $6`,
      [v.id, v.category_id, v.owner_id, v.tags || [], String(v.title).slice(0, 200), limit],
    );
    return { videos: rows.map((r) => videoCard(r)) };
  });

  // --- Реакции --------------------------------------------------------------
  app.post('/:id/like', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    if (!v.allow_ratings && !canEditVideo(v, req.user)) throw forbidden('Оценки для этого видео отключены');
    const value = Number(req.body?.value);
    if (![1, -1, 0].includes(value)) throw badRequest();
    await tx(async (c) => {
      const prev = await c.one('SELECT value FROM video_likes WHERE video_id = $1 AND user_id = $2', [v.id, req.user.id]);
      if (value === 0) await c.query('DELETE FROM video_likes WHERE video_id = $1 AND user_id = $2', [v.id, req.user.id]);
      else await c.query(`INSERT INTO video_likes(video_id, user_id, value) VALUES ($1,$2,$3) ON CONFLICT (video_id, user_id) DO UPDATE SET value = EXCLUDED.value, created_at = now()`, [v.id, req.user.id, value]);
      await c.query(`UPDATE videos SET like_count = (SELECT count(*) FROM video_likes WHERE video_id = $1 AND value = 1), dislike_count = (SELECT count(*) FROM video_likes WHERE video_id = $1 AND value = -1) WHERE id = $1`, [v.id]);
      if (value === 1 && prev?.value !== 1) {
        await c.query(`INSERT INTO video_stats_daily(video_id, day, likes) VALUES ($1, current_date, 1) ON CONFLICT (video_id, day) DO UPDATE SET likes = video_stats_daily.likes + 1`, [v.id]);
      }
    });
    const fresh = await one('SELECT like_count, dislike_count FROM videos WHERE id = $1', [v.id]);
    return { ok: true, value, likeCount: fresh.like_count, dislikeCount: fresh.dislike_count };
  });

  // --- Прогресс просмотра и учёт просмотров ------------------------------------
  app.post('/:id/progress', async (req, reply) => {
    const v = await requireViewable(req, req.params.id);
    const body = req.body || {};
    const position = Math.max(0, Math.min(Number(v.duration) || 1e9, Number(body.position) || 0));
    const delta = Math.max(0, Math.min(60, Number(body.watchedDelta) || 0));
    const buckets = Array.isArray(body.buckets) ? body.buckets.map(Number).filter((b) => Number.isInteger(b) && b >= 0 && b < 100).slice(0, 100) : [];
    const source = ['home', 'search', 'subscriptions', 'channel', 'playlist', 'related', 'direct', 'embed', 'notification', 'trending', 'library', 'external', 'share'].includes(body.source) ? body.source : 'direct';
    const device = deviceFromUa(req.headers['user-agent']);
    // Ключ сессии: пользователь → id сессии; гость → cookie
    let key;
    if (req.user) key = `u:${req.user.id}`;
    else {
      // Cookie подписана: произвольное значение от клиента больше не даёт новый «сеанс» и новый просмотр
      const signed = req.cookies?.cv_guest;
      key = signed ? unsign(signed)?.g || null : null;
      if (!key) {
        key = `g:${randomToken(12)}`;
        reply.setCookie('cv_guest', sign({ g: key }), { path: '/', httpOnly: true, sameSite: 'lax', secure: config.cookieSecure, maxAge: 365 * 86400 });
      }
    }
    const pauseHistory = !!req.user?.prefs?.pauseHistory;
    const completed = Number(v.duration) > 0 && position >= Number(v.duration) * 0.95;
    const row = await one(
      `INSERT INTO video_views(video_id, user_id, session_key, source, device, watched_seconds, position, max_position, completed)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$7,$8)
       ON CONFLICT (video_id, session_key, day) DO UPDATE SET
         watched_seconds = video_views.watched_seconds + EXCLUDED.watched_seconds,
         position = EXCLUDED.position,
         max_position = greatest(video_views.max_position, EXCLUDED.position),
         completed = video_views.completed OR EXCLUDED.completed,
         last_at = now()
       RETURNING id, watched_seconds, counted`,
      [v.id, pauseHistory ? null : req.user?.id || null, pauseHistory ? `${key}:np` : key, source, device, delta, position, completed],
    );
    let counted = row.counted;
    const threshold = Math.min(VIEW_THRESHOLD_SEC, Math.max(2, Number(v.duration) * 0.3));
    if (!counted && Number(row.watched_seconds) >= threshold) {
      const upd = await one('UPDATE video_views SET counted = true WHERE id = $1 AND counted = false RETURNING id', [row.id]);
      if (upd) {
        counted = true;
        await query('UPDATE videos SET view_count = view_count + 1 WHERE id = $1', [v.id]);
        await query('UPDATE users SET total_views = total_views + 1 WHERE id = $1', [v.owner_id]);
        await query(
          `INSERT INTO video_stats_daily(video_id, day, views, sources, devices) VALUES ($1, current_date, 1, jsonb_build_object($2::text, 1), jsonb_build_object($3::text, 1))
           ON CONFLICT (video_id, day) DO UPDATE SET views = video_stats_daily.views + 1,
             sources = video_stats_daily.sources || jsonb_build_object($2::text, coalesce((video_stats_daily.sources->>$2)::int, 0) + 1),
             devices = video_stats_daily.devices || jsonb_build_object($3::text, coalesce((video_stats_daily.devices->>$3)::int, 0) + 1)`,
          [v.id, source, device],
        );
        // «Ваше видео посмотрели» (как в Loom): автор узнаёт о первом просмотре личной записи
        try {
          if (req.user && req.user.id !== v.owner_id && ['private', 'unlisted'].includes(v.visibility) && req.settings['notify.video_watched']) {
            const seenBefore = await one('SELECT 1 FROM video_views WHERE video_id = $1 AND user_id = $2 AND counted = true AND id <> $3 LIMIT 1', [v.id, req.user.id, row.id]);
            if (!seenBefore) {
              await notify(v.owner_id, {
                type: 'video_watched', actorId: req.user.id,
                title: `${req.user.display_name} посмотрел ваше видео`,
                body: v.title, link: `/watch/${v.short_id}`,
                image: v.thumbnail_path ? `/media/${v.thumbnail_path}` : null,
                data: { videoId: v.id },
              });
            }
          }
        } catch { /* уведомление не должно мешать просмотру */ }
      }
    }
    if (delta > 0) {
      await query(`INSERT INTO video_stats_daily(video_id, day, watch_seconds) VALUES ($1, current_date, $2) ON CONFLICT (video_id, day) DO UPDATE SET watch_seconds = video_stats_daily.watch_seconds + $2`, [v.id, Math.round(delta)]);
      await query('UPDATE videos SET watch_seconds = watch_seconds + $2 WHERE id = $1', [v.id, Math.round(delta)]);
    }
    if (buckets.length) {
      await query(
        `INSERT INTO video_retention(video_id, bucket, count) SELECT $1, b, 1 FROM unnest($2::smallint[]) AS b
         ON CONFLICT (video_id, bucket) DO UPDATE SET count = video_retention.count + 1`,
        [v.id, buckets],
      );
    }
    if (req.user && !pauseHistory) {
      const maxPos = await one('SELECT max(max_position) AS m FROM video_views WHERE video_id = $1 AND user_id = $2', [v.id, req.user.id]);
      await updateAssignmentProgress(req.user.id, v, Number(maxPos?.m || position)).catch((e) => req.log.warn({ err: e.message }, 'assignment progress'));
      await updateCourseProgressForVideo(req.user.id, v, Number(maxPos?.m || position)).catch((e) => req.log.warn({ err: e.message }, 'course progress'));
    }
    return { ok: true, counted, position };
  });

  // --- Жалоба ---------------------------------------------------------------
  app.post('/:id/report', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    const reason = String(req.body?.reason || '').slice(0, 100);
    if (!reason) throw badRequest('Укажите причину');
    const dup = await one(`SELECT 1 FROM reports WHERE reporter_id = $1 AND target_type = 'video' AND target_id = $2 AND status = 'open'`, [req.user.id, v.id]);
    if (dup) return { ok: true, duplicate: true };
    await query(`INSERT INTO reports(reporter_id, target_type, target_id, reason, details) VALUES ($1,'video',$2,$3,$4)`, [req.user.id, v.id, reason, String(req.body?.details || '').slice(0, 2000)]);
    await notifyAdmins({ type: 'report', title: 'Новая жалоба на видео', body: `${v.title}: ${reason}`, link: '/admin/reports', actorId: req.user.id, data: { videoId: v.id } }, { moderators: true });
    return { ok: true };
  });

  // --- Редактирование ---------------------------------------------------------
  app.patch('/:id', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const b = req.body || {};
    const sets = [];
    const params = [v.id];
    const add = (col, val) => { params.push(val); sets.push(`${col} = $${params.length}`); };
    if (b.title !== undefined) { const t = String(b.title).trim().slice(0, 150); if (!t) throw badRequest('Название не может быть пустым'); add('title', t); }
    if (b.description !== undefined) add('description', String(b.description).slice(0, 10000));
    if (b.categoryId !== undefined) add('category_id', intOrNull(b.categoryId));
    if (b.tags !== undefined) add('tags', normalizeTags(b.tags));
    if (b.language !== undefined) add('language', String(b.language).slice(0, 8) || 'ru');
    if (b.visibility !== undefined) {
      if (!['public', 'internal', 'unlisted', 'private'].includes(b.visibility)) throw badRequest('Недопустимая видимость');
      add('visibility', b.visibility);
      // Возврат видимости после истечения срока публикации снимает отметку об истечении
      if (v.expired_at && b.visibility !== 'private' && b.expiresAt === undefined) { add('expired_at', null); add('expires_at', null); }
    }
    if (b.expiresAt !== undefined) {
      const d = b.expiresAt ? new Date(b.expiresAt) : null;
      if (d && Number.isNaN(d.getTime())) throw badRequest('Некорректная дата окончания публикации');
      add('expires_at', d);
      add('expired_at', null);
    }
    if (b.commentsMode !== undefined) { if (!['open', 'held', 'disabled'].includes(b.commentsMode)) throw badRequest(); add('comments_mode', b.commentsMode); }
    if (b.allowDownload !== undefined) add('allow_download', !!b.allowDownload);
    // Границы вступления и финальной заставки — для кнопки «Пропустить» в плеере
    if (b.introEnd !== undefined) add('intro_end', b.introEnd === null || b.introEnd === '' ? null : Math.max(0, Number(b.introEnd) || 0));
    if (b.outroStart !== undefined) add('outro_start', b.outroStart === null || b.outroStart === '' ? null : Math.max(0, Number(b.outroStart) || 0));
    if (b.viewerWatermark !== undefined) add('viewer_watermark', !!b.viewerWatermark);
    if (b.allowEmbed !== undefined) add('allow_embed', !!b.allowEmbed);
    if (b.allowRatings !== undefined) add('allow_ratings', !!b.allowRatings);
    if (b.scheduledAt !== undefined) {
      const d = b.scheduledAt ? new Date(b.scheduledAt) : null;
      if (d && Number.isNaN(d.getTime())) throw badRequest('Некорректная дата публикации');
      add('scheduled_at', d && d > new Date() ? d : null);
      if (d && d > new Date()) add('published_at', null);
      if (!(d && d > new Date()) && b.premiere === undefined) add('premiere', false);
    }
    // Премьера: тот же scheduled_at, но анонс виден заранее, а показ идёт синхронно у всех (1.6)
    if (b.premiere !== undefined) {
      const wants = !!b.premiere;
      const when = b.scheduledAt !== undefined ? (b.scheduledAt ? new Date(b.scheduledAt) : null) : (v.scheduled_at ? new Date(v.scheduled_at) : null);
      if (wants && !(when && when > new Date())) throw badRequest('Премьера возможна только на будущее время — укажите дату и время показа');
      if (wants && Number(v.duration) <= 0) throw badRequest('Премьеру можно назначить, когда видео обработано');
      add('premiere', wants);
      // Новая премьера без явного выбора — чат по умолчанию из настроек портала
      if (wants && !v.premiere && b.premiereChat === undefined) add('premiere_chat', req.settings['premiere.chat_default'] !== false);
    }
    if (b.premiereChat !== undefined) add('premiere_chat', !!b.premiereChat);
    // «Актуально до» — пересмотр актуальности материала (1.9)
    if (b.freshUntil !== undefined) {
      const d = b.freshUntil ? new Date(b.freshUntil) : null;
      if (d && Number.isNaN(d.getTime())) throw badRequest('Некорректная дата актуальности');
      add('fresh_until', d ? d.toISOString().slice(0, 10) : null);
      add('fresh_confirmed_at', d ? new Date() : null);
      add('fresh_asked_at', null);
    }
    if (b.chapters !== undefined) {
      let ch = [];
      if (Array.isArray(b.chapters)) ch = b.chapters.map((c) => ({ start: Math.max(0, Number(c.start) || 0), title: String(c.title || '').trim().slice(0, 100) })).filter((c) => c.title).sort((a, c) => a.start - c.start).slice(0, 200);
      else if (b.chapters === 'auto') ch = parseChapters(b.description ?? v.description, Number(v.duration));
      add('chapters', JSON.stringify(ch));
    } else if (b.description !== undefined && (!v.chapters || !v.chapters.length)) {
      const ch = parseChapters(b.description, Number(v.duration));
      if (ch.length) add('chapters', JSON.stringify(ch));
    }
    // Подсказки (карточки по таймкодам) и конечная заставка — как на YouTube
    if (b.cards !== undefined) {
      const cards = Array.isArray(b.cards) ? b.cards.slice(0, 20).map((c) => ({
        id: String(c.id || crypto.randomUUID()).slice(0, 40), start: Math.max(0, Math.round(Number(c.start) || 0)),
        type: ['video', 'playlist', 'url'].includes(c.type) ? c.type : 'video', target: String(c.target || '').trim().slice(0, 500),
        title: String(c.title || '').trim().slice(0, 100), text: String(c.text || '').trim().slice(0, 200), duration: Math.min(60, Math.max(5, Number(c.duration) || 15)),
      })).filter((c) => c.target && (c.type !== 'url' || /^https?:\/\//i.test(c.target))).sort((a, c) => a.start - c.start) : [];
      add('cards', JSON.stringify(cards));
    }
    if (b.endScreen !== undefined) {
      let es = null;
      if (b.endScreen && typeof b.endScreen === 'object') {
        const items = (Array.isArray(b.endScreen.items) ? b.endScreen.items : []).slice(0, 4).map((it) => ({
          type: ['video', 'playlist', 'subscribe', 'url', 'latest'].includes(it.type) ? it.type : 'video', target: String(it.target || '').trim().slice(0, 500), title: String(it.title || '').trim().slice(0, 100),
        })).filter((it) => ['subscribe', 'latest'].includes(it.type) || (it.target && (it.type !== 'url' || /^https?:\/\//i.test(it.target))));
        es = items.length ? { seconds: Math.min(30, Math.max(5, Number(b.endScreen.seconds) || 10)), items } : null;
      }
      add('end_screen', es ? JSON.stringify(es) : null);
    }
    if (b.thumbnailUrl !== undefined && b.thumbnailUrl) {
      // Нормализуем путь: «videos/<id>/thumbs/../../<чужое>/original.mp4» раньше проходил проверку префикса
      // Адрес приходит из браузера — с префиксом пути портала (BASE_PATH), если он задан
      const rel = path.posix.normalize(stripBasePath(String(b.thumbnailUrl)).replace(/^\/media\//, ''));
      const cands = v.thumbnail_candidates || [];
      const own = /^videos\/[0-9a-f-]{36}\/thumbs\/[\w.-]+\.(jpg|jpeg|png|webp)$/i.test(rel) && rel.startsWith(`videos/${v.id}/thumbs/`);
      if (!cands.includes(rel) && !own) throw badRequest('Недопустимая миниатюра');
      add('thumbnail_path', rel);
    }
    if (b.keepOriginal === false && v.original_kept && v.original_path && v.status === 'ready') {
      await removeFile(storage.abs(v.original_path));
      add('original_kept', false);
    }
    if (sets.length) {
      sets.push('updated_at = now()');
      await query(`UPDATE videos SET ${sets.join(', ')} WHERE id = $1`, params);
    }
    // Персональный доступ (для private): список e-mail или @handle
    if (Array.isArray(b.accessUsers)) {
      const ids = new Set();
      for (const item of b.accessUsers.slice(0, 500)) {
        const val = String(item?.id || item).trim().toLowerCase().replace(/^@/, '');
        if (!val) continue;
        const u = await one(`SELECT id FROM users WHERE (id = cv_uuid($1) OR email = $1 OR handle = $1) AND deleted_at IS NULL`, [val]);
        if (u && u.id !== v.owner_id) ids.add(u.id);
      }
      await tx(async (c) => {
        await c.query('DELETE FROM video_access WHERE video_id = $1', [v.id]);
        for (const uid of ids) await c.query('INSERT INTO video_access(video_id, user_id, granted_by) VALUES ($1,$2,$3)', [v.id, uid, req.user.id]);
      });
    }
    // Доступ группам (для private)
    if (Array.isArray(b.accessGroups)) {
      const ids = b.accessGroups.map((g) => String(g?.id || g)).filter((x) => /^[0-9a-f-]{36}$/i.test(x)).slice(0, 200);
      await tx(async (c) => {
        await c.query('DELETE FROM video_group_access WHERE video_id = $1', [v.id]);
        for (const gid of ids) await c.query('INSERT INTO video_group_access(video_id, group_id, granted_by) SELECT $1, id, $3 FROM groups WHERE id = $2 ON CONFLICT DO NOTHING', [v.id, gid, req.user.id]);
      });
    }
    // Переиндексация категорий при смене
    if (b.categoryId !== undefined) {
      await query('UPDATE categories c SET video_count = (SELECT count(*) FROM videos v WHERE v.category_id = c.id AND v.deleted_at IS NULL AND v.status = $1) WHERE c.id IN ($2, $3)', ['ready', v.category_id || 0, Number(b.categoryId) || 0]);
    }
    invalidateVideoCache(v.id);
    await audit(req, 'video.update', { targetType: 'video', targetId: v.id, details: Object.keys(b) });
    // Уведомить подписчиков, если видео стало видимым впервые (private/unlisted → public/internal)
    if (b.visibility && ['public', 'internal'].includes(b.visibility) && !['public', 'internal'].includes(v.visibility) && v.status === 'ready' && v.moderation_status === 'approved') {
      const owner = await one('SELECT id, display_name FROM users WHERE id = $1', [v.owner_id]);
      const fresh = await loadVideo(v.id);
      if (fresh.published_at && Date.now() - new Date(fresh.published_at).getTime() < 7 * 86400000) await notifySubscribersNewVideo(fresh, owner).catch(() => {});
    }
    const updated = await loadVideo(v.id);
    const lists = updated.visibility === 'private' ? await accessLists(updated.id) : {};
    await emitEvent('video.updated', { video: eventVideo(updated), fields: Object.keys(req.body || {}), byUserId: req.user.id });
    return { video: videoFull(updated, { viewer: req.user, ...lists }) };
  });

  app.delete('/:id', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    await query('UPDATE videos SET deleted_at = now(), updated_at = now() WHERE id = $1', [v.id]);
    // Файлы НЕ удаляем: видео лежит в корзине retention.trash_days дней и должно восстанавливаться.
    // Окончательное удаление вместе с каталогом делает суточная уборка (jobs/misc.js).
    await query(`UPDATE jobs SET status = 'cancelled' WHERE video_id = $1 AND status = 'queued'`, [v.id]);
    for (const j of await many(`SELECT id FROM jobs WHERE video_id = $1 AND status = 'running'`, [v.id])) await cancelJob(j.id).catch(() => {});
    await query('UPDATE users SET video_count = greatest(video_count - 1, 0) WHERE id = $1 AND $2 = $3', [v.owner_id, v.status, 'ready']);
    if (v.category_id) await query('UPDATE categories SET video_count = greatest(video_count - 1, 0) WHERE id = $1', [v.category_id]);
    invalidateVideoCache(v.id);
    await audit(req, 'video.delete', { targetType: 'video', targetId: v.id, details: { title: v.title, byStaff: req.user.id !== v.owner_id } });
    if (req.user.id !== v.owner_id) {
      await notify(v.owner_id, { type: 'video_removed', title: 'Видео удалено модератором', body: v.title, data: { reason: req.body?.reason || '' } });
    }
    await emitEvent('video.deleted', { video: eventVideo(v), byUserId: req.user.id });
    return { ok: true };
  });

  app.post('/:id/reprocess', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!v.original_path || !v.original_kept || !(await exists(storage.abs(v.original_path)))) throw badRequest('Оригинальный файл не сохранён — повторная обработка невозможна');
    await query(`UPDATE videos SET status = 'queued', processing_progress = 0, processing_error = NULL WHERE id = $1`, [v.id]);
    await enqueue('transcode', { videoId: v.id }, { videoId: v.id, priority: 1 });
    await audit(req, 'video.reprocess', { targetType: 'video', targetId: v.id });
    return { ok: true };
  });

  // --- ИИ-подсказки по транскрипту (название, конспект, главы, теги) --------------------
  app.post('/:id/ai', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['ai.enabled']) throw badRequest('ИИ-подсказки отключены администратором');
    const allowed = ['title', 'summary', 'chapters', 'tags'];
    const fields = (Array.isArray(req.body?.fields) ? req.body.fields : allowed).filter((f) => allowed.includes(f));
    if (!fields.length) throw badRequest('Не выбрано, что сгенерировать');
    const hasSub = await one(`SELECT 1 FROM subtitles WHERE video_id = $1 AND status = 'ready'`, [v.id]);
    if (!hasSub && !v.transcript) throw badRequest('Нет транскрипта: сначала добавьте субтитры или создайте автосубтитры');
    const pending = await one(`SELECT 1 FROM jobs WHERE video_id = $1 AND type = 'ai_enrich' AND status IN ('queued','running')`, [v.id]);
    if (pending) return { ok: true, queued: true, already: true };
    await enqueue('ai_enrich', { videoId: v.id, fields, userId: req.user.id }, { videoId: v.id, priority: 0, maxAttempts: 2 });
    await audit(req, 'video.ai', { targetType: 'video', targetId: v.id, details: { fields } });
    return { ok: true, queued: true };
  });
  app.get('/:id/ai', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const pending = await one(`SELECT 1 FROM jobs WHERE video_id = $1 AND type = 'ai_enrich' AND status IN ('queued','running')`, [v.id]);
    return { suggestions: v.ai_suggestions || null, pending: !!pending };
  });
  app.delete('/:id/ai', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    await query('UPDATE videos SET ai_suggestions = NULL WHERE id = $1', [v.id]);
    return { ok: true };
  });

  // --- Миниатюры ----------------------------------------------------------------
  app.post('/:id/thumbnail', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const part = await req.file({ limits: { fileSize: 20 * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл изображения');
    const ext = extOf(part.filename);
    if (!['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'].includes(ext)) throw badRequest('Поддерживаются JPG, PNG, WEBP');
    const dir = storage.thumbsDir(v.id);
    await ensureDir(dir);
    const tmp = path.join(dir, `upload_tmp.${ext}`);
    await pipeline(part.file, fs.createWriteStream(tmp));
    if (part.file.truncated) { await removeFile(tmp); throw badRequest('Файл слишком большой (макс. 20 МБ)'); }
    const out = path.join(dir, `custom_${Date.now()}.jpg`);
    try { await resizeImage(tmp, out, { width: 1280, height: 720, mode: 'contain' }); } catch (e) { throw badRequest('Не удалось обработать изображение'); } finally { await removeFile(tmp); }
    // удаляем старые пользовательские миниатюры
    for (const f of await fsp.readdir(dir)) if (f.startsWith('custom_') && path.join(dir, f) !== out) await removeFile(path.join(dir, f));
    await query('UPDATE videos SET thumbnail_path = $2 WHERE id = $1', [v.id, storage.rel(out)]);
    return { thumbnailUrl: mediaUrl(storage.rel(out)) };
  });

  app.post('/:id/thumbnail/frame', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const t = Math.max(0, Math.min(Number(v.duration) || 0, Number(req.body?.time) || 0));
    const src = v.original_kept && v.original_path && (await exists(storage.abs(v.original_path))) ? storage.abs(v.original_path) : v.mp4_path ? storage.abs(v.mp4_path) : storage.abs(v.hls_path);
    const dir = storage.thumbsDir(v.id);
    const out = path.join(dir, `custom_${Date.now()}.jpg`);
    await frameAt(src, t, out);
    for (const f of await fsp.readdir(dir)) if (f.startsWith('custom_') && path.join(dir, f) !== out) await removeFile(path.join(dir, f));
    await query('UPDATE videos SET thumbnail_path = $2 WHERE id = $1', [v.id, storage.rel(out)]);
    return { thumbnailUrl: mediaUrl(storage.rel(out)) };
  });

  // --- Субтитры ----------------------------------------------------------------
  app.get('/:id/subtitles', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const rows = await many(`SELECT * FROM subtitles WHERE video_id = $1 AND (status = 'ready' OR $2) ORDER BY is_default DESC, language`, [v.id, canEditVideo(v, req.user)]);
    return { subtitles: rows.map(subtitleOut) };
  });

  app.post('/:id/subtitles', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const part = await req.file({ limits: { fileSize: 5 * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл субтитров (.vtt или .srt)');
    const chunks = [];
    for await (const c of part.file) chunks.push(c);
    const text = Buffer.concat(chunks).toString('utf8');
    const ext = extOf(part.filename);
    if (!['vtt', 'srt'].includes(ext)) throw badRequest('Поддерживаются форматы .vtt и .srt');
    const vtt = toVtt(text);
    if (!/-->/.test(vtt)) throw badRequest('Файл не похож на субтитры');
    const language = String(part.fields?.language?.value || 'ru').slice(0, 8);
    const label = String(part.fields?.label?.value || language).slice(0, 60);
    const dir = storage.subsDir(v.id);
    await ensureDir(dir);
    const sub = await one(`INSERT INTO subtitles(video_id, language, label, kind, status) VALUES ($1,$2,$3,'manual','ready') RETURNING *`, [v.id, language, label]);
    const file = path.join(dir, `${sub.id}.vtt`);
    await fsp.writeFile(file, vtt);
    const hasDefault = await one('SELECT 1 FROM subtitles WHERE video_id = $1 AND is_default AND id <> $2', [v.id, sub.id]);
    const updated = await one('UPDATE subtitles SET path = $2, is_default = $3 WHERE id = $1 RETURNING *', [sub.id, storage.rel(file), !hasDefault]);
    if (!v.transcript) await query('UPDATE videos SET transcript = $2 WHERE id = $1', [v.id, vttToText(vtt).slice(0, 500000)]);
    return { subtitle: subtitleOut(updated) };
  });

  /** Автоглавы по сменам кадра (слайдам) — как «умные главы» Panopto (1.8). */
  app.post('/:id/chapters/auto', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['chapters.auto_enabled']) throw badRequest('Автоглавы отключены администратором');
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    if (Number(v.duration) < 60) throw badRequest('Для коротких видео главы не нужны');
    const job = await enqueue('auto_chapters', { videoId: v.id, threshold: Number(req.body?.threshold) || 0.4 }, { videoId: v.id, dedupe: true });
    await audit(req, 'chapters.auto', { targetType: 'video', targetId: v.id });
    return { jobId: job?.id || null, status: 'queued' };
  });

  app.post('/:id/subtitles/auto', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['asr.enabled']) throw forbidden('Автоматические субтитры отключены администратором');
    if (v.status !== 'ready') throw badRequest('Дождитесь окончания обработки видео');
    const language = String(req.body?.language || req.settings['asr.language'] || 'ru').slice(0, 8);
    const existing = await one(`SELECT * FROM subtitles WHERE video_id = $1 AND kind = 'auto' AND language = $2`, [v.id, language]);
    let sub = existing;
    if (existing) await query(`UPDATE subtitles SET status = 'processing', error = NULL WHERE id = $1`, [existing.id]);
    else sub = await one(`INSERT INTO subtitles(video_id, language, label, kind, status) VALUES ($1,$2,$3,'auto','processing') RETURNING *`, [v.id, language, `Авто (${language})`]);
    await enqueue('subtitles_asr', { videoId: v.id, language, subtitleId: sub.id }, { videoId: v.id, dedupe: true });
    return { subtitle: subtitleOut(sub) };
  });

  app.patch('/:id/subtitles/:subId', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const sub = await one('SELECT * FROM subtitles WHERE id = $1 AND video_id = $2', [req.params.subId, v.id]);
    if (!sub) throw notFound();
    const b = req.body || {};
    if (b.isDefault) await query('UPDATE subtitles SET is_default = false WHERE video_id = $1', [v.id]);
    const updated = await one('UPDATE subtitles SET label = COALESCE($2, label), language = COALESCE($3, language), is_default = COALESCE($4, is_default) WHERE id = $1 RETURNING *',
      [sub.id, b.label ? String(b.label).slice(0, 60) : null, b.language ? String(b.language).slice(0, 8) : null, b.isDefault === undefined ? null : !!b.isDefault]);
    return { subtitle: subtitleOut(updated) };
  });

  app.delete('/:id/subtitles/:subId', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const sub = await one('DELETE FROM subtitles WHERE id = $1 AND video_id = $2 RETURNING *', [req.params.subId, v.id]);
    if (sub?.path) await removeFile(storage.abs(sub.path));
    return { ok: true };
  });

  // --- Аналитика видео ---------------------------------------------------------
  app.get('/:id/analytics', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await requireEditable(req, req.params.id);
    const analytics = await videoAnalytics(v, req.query.range);
    if (req.query.format === 'csv') {
      const csv = toCsv(analytics.daily || [], [
        { title: 'Дата', value: 'day' }, { title: 'Просмотры', value: 'views' }, { title: 'Время просмотра, мин', value: (d) => Math.round(Number(d.watchSeconds || d.watch_seconds || 0) / 60) },
        { title: 'Лайки', value: 'likes' }, { title: 'Комментарии', value: 'comments' },
      ]);
      return sendCsv(reply, `analytics-${v.short_id}.csv`, csv);
    }
    return { analytics };
  });

  // --- Кто посмотрел (для автора и модераторов) ------------------------------------------
  app.get('/:id/viewers', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['analytics.show_viewers'] && !isStaff(req.user)) throw forbidden('Список зрителей отключён администратором');
    const rows = await many(
      `SELECT u.id, u.handle, u.display_name, u.email, u.avatar_path,
              max(vv.max_position)::float AS max_position, sum(vv.watched_seconds)::float AS watched, max(vv.last_at) AS last_at, min(vv.first_at) AS first_at,
              bool_or(vv.completed) AS completed, count(*)::int AS sessions
       FROM video_views vv JOIN users u ON u.id = vv.user_id
       WHERE vv.video_id = $1 GROUP BY u.id ORDER BY max(vv.last_at) DESC LIMIT 2000`,
      [v.id],
    );
    const dur = Number(v.duration) || 0;
    const viewers = rows.map((r) => ({
      ...userPublic(r), email: r.email, percent: dur ? Math.min(100, Math.round((r.max_position / dur) * 100)) : 0,
      watchedSeconds: Math.round(r.watched), completed: r.completed, firstAt: r.first_at, lastAt: r.last_at, sessions: r.sessions,
    }));
    if (req.query.format === 'csv') {
      return sendCsv(reply, `viewers-${v.short_id}.csv`, toCsv(viewers, [
        { title: 'Сотрудник', value: 'displayName' }, { title: 'E-mail', value: 'email' }, { title: 'Просмотрено, %', value: 'percent' },
        { title: 'Время просмотра, мин', value: (r) => Math.round(r.watchedSeconds / 60) }, { title: 'Досмотрел', value: (r) => (r.completed ? 'да' : 'нет') },
        { title: 'Первый просмотр', value: 'firstAt' }, { title: 'Последний просмотр', value: 'lastAt' },
      ]));
    }
    return { viewers, total: viewers.length };
  });
}

export function rangeDays(range) {
  return { '7d': 7, '28d': 28, '90d': 90, '365d': 365, all: 3650 }[range] || 28;
}

export async function videoAnalytics(v, range) {
  const days = rangeDays(range);
  const daily = await many(
    `SELECT day, views, watch_seconds, likes, dislikes, comments, sources, devices FROM video_stats_daily WHERE video_id = $1 AND day >= current_date - ($2 || ' days')::interval ORDER BY day`,
    [v.id, String(days)],
  );
  const totals = daily.reduce((a, d) => ({ views: a.views + d.views, watch: a.watch + Number(d.watch_seconds), likes: a.likes + d.likes, comments: a.comments + d.comments }), { views: 0, watch: 0, likes: 0, comments: 0 });
  const sources = {}, devices = {};
  for (const d of daily) {
    for (const [k, n] of Object.entries(d.sources || {})) sources[k] = (sources[k] || 0) + Number(n);
    for (const [k, n] of Object.entries(d.devices || {})) devices[k] = (devices[k] || 0) + Number(n);
  }
  const retention = await many('SELECT bucket, count FROM video_retention WHERE video_id = $1 ORDER BY bucket', [v.id]);
  const maxRet = retention.length ? Math.max(...retention.map((r) => r.count), 1) : 1;
  const curve = Array.from({ length: 100 }, (_, i) => { const r = retention.find((x) => x.bucket === i); return r ? Math.round((r.count / maxRet) * 1000) / 10 : 0; });
  const uniq = await one(`SELECT count(DISTINCT session_key)::int AS n, count(*) FILTER (WHERE completed)::int AS completed, count(*) FILTER (WHERE counted)::int AS counted FROM video_views WHERE video_id = $1 AND first_at >= now() - ($2 || ' days')::interval`, [v.id, String(days)]);
  const avgView = totals.views ? totals.watch / totals.views : 0;
  return {
    range: days,
    totals: { ...totals, uniqueViewers: uniq?.n || 0, completed: uniq?.completed || 0, avgViewSeconds: Math.round(avgView), avgViewPercent: v.duration ? Math.min(100, Math.round((avgView / Number(v.duration)) * 100)) : 0 },
    lifetime: { views: v.view_count, likes: v.like_count, dislikes: v.dislike_count, comments: v.comment_count, watchSeconds: v.watch_seconds },
    daily: daily.map((d) => ({ day: d.day, views: d.views, watchSeconds: Number(d.watch_seconds), likes: d.likes, comments: d.comments })),
    sources, devices, retention: curve,
  };
}
