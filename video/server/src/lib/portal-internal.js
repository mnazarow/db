// Сведения для портала документации (раздел «Видео»): состояние видеопортала для «Мониторинга», число
// видео на модерации для шапки портала, поиск видео для страницы поиска портала. Отдаются по адресам
// /api/internal/… (routes/internal.js) только с общим секретом; снаружи nginx их не пропускает.
import crypto from 'node:crypto';
import fsp from 'node:fs/promises';
import { one, many, pool } from '../db.js';
import { config } from '../config.js';
import { listVisibilitySql } from './access.js';
import { portalEnabled } from './portal.js';
import { portalSyncStatus } from './portal-sync.js';

/** Подпись запроса портала: секрет сравнивается за постоянное время. */
export function verifyPortalSecret(provided) {
  if (!portalEnabled() || typeof provided !== 'string' || !provided) return false;
  const a = Buffer.from(provided);
  const b = Buffer.from(config.portal.secret);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/** Сколько видео ждут модерации. */
export async function moderationSummary() {
  const r = await one(`SELECT count(*)::int AS n FROM videos WHERE moderation_status = 'pending' AND deleted_at IS NULL`);
  return { pending: r?.n || 0 };
}

/** Свободное место там, где лежат видеофайлы. */
async function storageInfo() {
  try {
    const s = await fsp.statfs(config.mediaDir);
    return { path: config.mediaDir, freeBytes: Number(s.bavail) * Number(s.bsize), totalBytes: Number(s.blocks) * Number(s.bsize) };
  } catch (e) {
    return { path: config.mediaDir, error: e.message };
  }
}

/**
 * Состояние: база, обработчик видео (worker; обслуживание он выполняет раз в минуту, так что давняя
 * последняя активность значит, что он остановлен), очередь заданий, место, синхронизация сотрудников.
 */
export async function statusReport() {
  let db = 'ok';
  try { await pool.query('SELECT 1'); } catch (e) { db = `error: ${e.message}`; }
  if (db !== 'ok') return { status: 'degraded', db, version: config.version };
  const q = await one(
    `SELECT count(*) FILTER (WHERE status = 'queued' AND run_at <= now())::int AS queued,
            count(*) FILTER (WHERE status = 'running')::int AS running,
            count(*) FILTER (WHERE status = 'failed' AND finished_at > now() - interval '1 day')::int AS failed24h,
            extract(epoch FROM now() - min(run_at) FILTER (WHERE status = 'queued' AND run_at <= now()))::int AS oldest
     FROM jobs WHERE status IN ('queued', 'running') OR finished_at > now() - interval '1 day'`,
  );
  const w = await one(
    `SELECT extract(epoch FROM now() - max(greatest(finished_at, heartbeat_at, started_at)))::int AS age
     FROM jobs WHERE started_at > now() - interval '7 days'`,
  );
  const age = w?.age ?? null;
  return {
    status: 'ok',
    db,
    version: config.version,
    worker: { ageSeconds: age, alive: age !== null && age < 600 },
    queue: { queued: q.queued, running: q.running, failed24h: q.failed24h, oldestQueuedSeconds: q.oldest ?? null },
    storage: await storageInfo(),
    moderation: await moderationSummary(),
    portalSync: await portalSyncStatus(),
  };
}

/**
 * Поиск видео для страницы поиска портала: audience 'employee' — сотрудник вошёл (видны открытые и
 * внутренние видео), иначе только открытые. Видео с ограниченным доступом (приватные, по группам) в
 * общий поиск портала не попадают — их найдут в самом разделе «Видео».
 */
export async function searchVideos({ q, audience = 'guest', limit = 6 }) {
  const text = String(q || '').trim().slice(0, 200);
  if (text.length < 2) return { total: 0, items: [] };
  const n = Math.max(1, Math.min(20, Number(limit) || 6));
  const viewer = audience === 'employee' ? { status: 'active', role: 'user' } : null;
  const rows = await many(
    `SELECT v.short_id, v.title, v.thumbnail_path, v.duration, v.published_at, u.display_name AS channel,
            CASE WHEN v.search_vector @@ websearch_to_tsquery('russian', unaccent($1)) AND coalesce(v.transcript, '') <> ''
                 THEN ts_headline('russian', v.transcript, websearch_to_tsquery('russian', unaccent($1)), 'MaxWords=18, MinWords=8, StartSel=«, StopSel=», MaxFragments=1')
                 ELSE left(coalesce(v.description, ''), 200) END AS snippet,
            count(*) OVER () AS total
     FROM videos v JOIN users u ON u.id = v.owner_id
     WHERE ${listVisibilitySql(viewer)}
       AND (v.search_vector @@ websearch_to_tsquery('russian', unaccent($1)) OR v.title ILIKE '%' || cv_like($1) || '%' OR $1 ILIKE ANY(v.tags))
     ORDER BY ts_rank_cd(v.search_vector, websearch_to_tsquery('russian', unaccent($1))) * 10
              + (CASE WHEN v.title ILIKE '%' || cv_like($1) || '%' THEN 2 ELSE 0 END) DESC, v.published_at DESC
     LIMIT $2`,
    [text, n],
  );
  return {
    total: rows.length ? Number(rows[0].total) : 0,
    items: rows.map((r) => ({
      shortId: r.short_id,
      title: r.title,
      thumbnail: r.thumbnail_path || null,
      duration: r.duration == null ? null : Math.round(Number(r.duration)),
      publishedAt: r.published_at,
      channel: r.channel,
      snippet: r.snippet || '',
    })),
  };
}

/**
 * Обязательные просмотры по сотрудникам портала — для единого отчёта портала «Документы и видео»:
 * по каждому сотруднику (portal_user_id) — сколько действующих назначений, сколько выполнено (просмотрены
 * все видео назначения), сколько просрочено. Логика выполнения — как в assignmentSummaries (lib/assignments.js).
 */
export async function assignmentsByPortalUser() {
  const rows = await many(
    `WITH active AS (SELECT id, kind, video_id, playlist_id, course_id, due_at FROM assignments WHERE status = 'active'),
     targets AS (
       SELECT DISTINCT t.assignment_id, u.id AS user_id FROM assignment_targets t
       JOIN active a ON a.id = t.assignment_id
       JOIN users u ON u.status = 'active' AND u.deleted_at IS NULL AND u.portal_user_id IS NOT NULL
         AND (t.target_type = 'all' OR t.user_id = u.id
              OR (t.target_type = 'group' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = t.group_id AND gm.user_id = u.id)))),
     vids AS (
       SELECT a.id AS assignment_id, v.id AS video_id FROM active a JOIN videos v ON v.id = a.video_id AND v.deleted_at IS NULL WHERE a.kind = 'video'
       UNION
       SELECT a.id, v.id FROM active a JOIN playlist_items pi ON pi.playlist_id = a.playlist_id
         JOIN videos v ON v.id = pi.video_id AND v.deleted_at IS NULL AND v.status = 'ready' WHERE a.kind = 'playlist'
       UNION
       SELECT a.id, v.id FROM active a JOIN course_items ci ON ci.course_id = a.course_id AND ci.kind = 'video' AND ci.required
         JOIN videos v ON v.id = ci.video_id AND v.deleted_at IS NULL AND v.status = 'ready' WHERE a.kind = 'course'),
     need AS (SELECT assignment_id, count(*) AS n FROM vids GROUP BY assignment_id),
     done AS (
       SELECT p.assignment_id, p.user_id, count(DISTINCT p.video_id) AS n FROM assignment_progress p
       JOIN vids v ON v.assignment_id = p.assignment_id AND v.video_id = p.video_id
       WHERE p.completed_at IS NOT NULL GROUP BY p.assignment_id, p.user_id),
     per AS (
       SELECT u.portal_user_id, a.due_at, (coalesce(need.n, 0) > 0 AND coalesce(done.n, 0) >= need.n) AS completed
       FROM targets t JOIN active a ON a.id = t.assignment_id JOIN users u ON u.id = t.user_id
       LEFT JOIN need ON need.assignment_id = t.assignment_id
       LEFT JOIN done ON done.assignment_id = t.assignment_id AND done.user_id = t.user_id)
     SELECT portal_user_id, count(*)::int AS assigned, count(*) FILTER (WHERE completed)::int AS completed,
            count(*) FILTER (WHERE NOT completed AND due_at IS NOT NULL AND due_at < now())::int AS overdue
     FROM per GROUP BY portal_user_id`,
  );
  return {
    users: rows.map((r) => ({ portalUserId: Number(r.portal_user_id), assigned: r.assigned, completed: r.completed, overdue: r.overdue })),
  };
}

/**
 * Фрагменты видео для ответа на вопрос (ответы ИИ на странице поиска портала): видео, где встречается хоть одно
 * слово вопроса, с выдержками из расшифровки речи вокруг совпадений (до трёх фрагментов) и описанием.
 * Видимость — как у поиска: сотруднику открытые и внутренние, гостю только открытые.
 */
export async function contextForQuestion({ q, audience = 'guest', limit = 3 }) {
  const words = String(q || '').toLowerCase().split(/[^\p{L}\p{N}]+/u).filter((w) => w.length >= 3).slice(0, 12);
  if (!words.length) return { items: [] };
  const n = Math.max(1, Math.min(5, Number(limit) || 3));
  const viewer = audience === 'employee' ? { status: 'active', role: 'user' } : null;
  const rows = await many(
    `SELECT v.short_id, v.title, left(coalesce(v.description, ''), 600) AS description, u.display_name AS channel,
            CASE WHEN coalesce(v.transcript, '') <> ''
                 THEN ts_headline('russian', v.transcript, websearch_to_tsquery('russian', unaccent($1)),
                                  'MaxWords=60, MinWords=25, MaxFragments=3, FragmentDelimiter=" … ", StartSel="", StopSel=""')
                 ELSE '' END AS excerpt
     FROM videos v JOIN users u ON u.id = v.owner_id
     WHERE ${listVisibilitySql(viewer)} AND v.search_vector @@ websearch_to_tsquery('russian', unaccent($1))
     ORDER BY ts_rank_cd(v.search_vector, websearch_to_tsquery('russian', unaccent($1))) DESC, v.published_at DESC
     LIMIT $2`,
    [words.join(' or '), n],
  );
  return { items: rows.map((r) => ({ shortId: r.short_id, title: r.title, channel: r.channel, description: r.description || '', excerpt: r.excerpt || '' })) };
}
