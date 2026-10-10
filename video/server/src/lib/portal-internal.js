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
