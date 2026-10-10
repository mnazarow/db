// Раздача медиафайлов: проверка прав для nginx (auth_request), резервная раздача из Node, скачивание оригинала.
import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { one } from '../db.js';
import { config } from '../config.js';
import { storage } from '../lib/storage.js';
import { canViewVideo, canPlayVideo, canViewLive, isStaff, shareLinkFor, canSeeWebinar, canSeeShowcase } from '../lib/access.js';
import { notFound, forbidden, unauthorized } from '../lib/util.js';
import { stripBasePath } from '../lib/urls.js';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// Кэш сведений о видео для быстрых проверок при раздаче сегментов
const videoCache = new Map();
const TTL = 15000;
export function invalidateVideoCache(id) { if (id) videoCache.delete(id); else videoCache.clear(); }

async function videoForAuth(id) {
  const c = videoCache.get(id);
  if (c && Date.now() - c.at < TTL) return c.v;
  const v = await one('SELECT id, owner_id, visibility, status, moderation_status, is_blocked, deleted_at, scheduled_at, premiere, allow_download, original_kept FROM videos WHERE id = $1', [id]);
  videoCache.set(id, { v, at: Date.now() });
  if (videoCache.size > 5000) videoCache.delete(videoCache.keys().next().value);
  return v;
}

const MIME = {
  m3u8: 'application/vnd.apple.mpegurl', ts: 'video/mp2t', mp4: 'video/mp4', m4s: 'video/iso.segment', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png',
  webp: 'image/webp', vtt: 'text/vtt; charset=utf-8', mkv: 'video/x-matroska', webm: 'video/webm', mov: 'video/quicktime', avi: 'video/x-msvideo', mp3: 'audio/mpeg',
};

/**
 * Проверка доступа к относительному пути в media. Возвращает { ok, status, message }.
 */
export async function checkMediaAccess(rel, user, shareTokens = null) {
  const parts = rel.split('/').filter(Boolean);
  if (parts.some((p) => p === '..' || p === '.')) return { ok: false, status: 400 };
  const [kind, id, ...rest] = parts;
  if (kind === 'avatars' || kind === 'banners' || kind === 'branding') return { ok: true };
  // Личный токен лент открывает только то, что нужно читалке и подкаст-клиенту:
  // обложки и файл-вложение (video.mp4 / audio.m4a). Ни оригиналов, ни потоков, ни эфиров.
  if (user?.viaFeedToken) {
    const file = rest.join('/');
    const feedFile = kind === 'videos' && (/^thumbs\/[\w.-]+\.(jpg|jpeg|png|webp)$/.test(file) || /^(video\.mp4|audio\.m4a|thumbnail\.(jpg|jpeg|png|webp))$/.test(file));
    if (!feedFile) return { ok: false, status: 401 };
  }
  if (kind === 'live') {
    // Только миниатюры эфиров и только тем, кому виден сам эфир:
    // кадр из закрытой трансляции — такое же содержимое, как и она сама.
    if (!rest[0] || !/\.(jpg|png|webp)$/.test(rest[0])) return { ok: false, status: 403 };
    if (!id || !UUID_RE.test(id)) return { ok: false, status: 404 };
    const st = await one('SELECT id, owner_id, visibility FROM live_streams WHERE id = $1', [id]);
    if (!st) return { ok: false, status: 404 };
    return canViewLive(st, user) ? { ok: true } : { ok: false, status: user ? 403 : 401 };
  }
  // Обложки вебинаров: видны тем, кому видна страница вебинара. Материалы через /media/ не отдаются —
  // у них свой адрес с проверкой регистрации и безопасными заголовками (/api/webinars/…/materials/…).
  if (kind === 'webinars') {
    if (!id || !UUID_RE.test(id) || rest.length !== 1 || !/^cover-[\w]+\.(jpg|jpeg|png|webp)$/.test(rest[0])) return { ok: false, status: 403 };
    const w = await one('SELECT id, owner_id, visibility, webinar, registration, reg_external, cohosts FROM live_streams WHERE id = $1', [id]);
    if (!w) return { ok: false, status: 404 };
    return canSeeWebinar(w, user) ? { ok: true } : { ok: false, status: user ? 403 : 401 };
  }
  // Обложки витрин: showcases/<id>_<время>.<ext>
  if (kind === 'showcases') {
    const m = /^([0-9a-f-]{36})_\d+\.(jpg|jpeg|png|webp)$/i.exec(id || '');
    if (!m || rest.length) return { ok: false, status: 403 };
    const sc = await one('SELECT id, visibility, enabled FROM showcases WHERE id = $1', [m[1]]);
    if (!sc) return { ok: false, status: 404 };
    return canSeeShowcase(sc, user) ? { ok: true } : { ok: false, status: user ? 403 : 401 };
  }
  if (kind !== 'videos' || !id || !UUID_RE.test(id)) return { ok: false, status: 404 };
  const v = await videoForAuth(id);
  if (!v) return { ok: false, status: 404 };
  const file = rest.join('/');
  const isOriginal = file.startsWith('original.') || file === 'asr_audio.mp3';
  // Пока видео обрабатывается, файлы доступны только владельцу/модераторам.
  // Премьера до назначенного часа не отдаёт медиа никому, кроме автора и модераторов (кроме обложки).
  const isPoster = /\.(jpg|jpeg|png|webp)$/.test(file);
  const allowed = isPoster ? await canViewVideo(v, user, shareTokens) : await canPlayVideo(v, user, shareTokens);
  if (!allowed) return { ok: false, status: user ? 403 : 401 };
  if (isOriginal) {
    const owner = user && user.id === v.owner_id;
    if (!(owner || isStaff(user) || (v.allow_download && user))) {
      const link = !user && shareTokens?.length ? await shareLinkFor(v.id, shareTokens) : null;
      if (!(link && link.allow_download)) return { ok: false, status: user ? 403 : 401 };
    }
  }
  return { ok: true, video: v };
}

/**
 * Пользователь по личному токену RSS-ленты (?ft=…) — для проигрывателей подкастов и RSS-читалок без cookie.
 * Такой «пользователь» помечен viaFeedToken: он видит ленты и вложенные в них файлы, но не больше
 * (см. checkMediaAccess), и токен живёт не дольше feeds.token_days.
 */
export async function feedTokenUser(req, uri = '') {
  if (req.user) return req.user;
  let ft = req.query?.ft;
  if (!ft && uri) { const m = String(uri).match(/[?&]ft=([A-Za-z0-9_-]{16,80})/); if (m) ft = m[1]; }
  if (!ft || !/^[A-Za-z0-9_-]{16,80}$/.test(String(ft))) return null;
  const days = Math.max(0, Number(req.settings?.['feeds.token_days'] ?? 365) || 0);
  const u = await one(
    `SELECT * FROM users WHERE feed_token = $1 AND status = 'active' AND deleted_at IS NULL
       AND ($2::int = 0 OR feed_token_created_at IS NULL OR feed_token_created_at > now() - make_interval(days => $2::int))`,
    [String(ft), days]);
  return u ? { ...u, viaFeedToken: true } : null;
}

export default async function mediaRoutes(app) {
  // nginx auth_request: GET /api/media/auth с заголовком X-Original-URI
  app.get('/api/media/auth', async (req, reply) => {
    // nginx передаёт $request_uri целиком — вместе с префиксом пути портала (BASE_PATH): срезаем его
    const uri = stripBasePath(String(req.headers['x-original-uri'] || req.query.uri || ''));
    // nginx auth_request принимает только 2xx / 401 / 403 (иное трактуется как ошибка 500),
    // поэтому «не найдено» тоже отвечаем как отказ в доступе.
    const m = uri.match(/^\/media\/([^?]+)/);
    if (!m) { reply.code(req.user ? 403 : 401); return ''; }
    let rel;
    try { rel = decodeURIComponent(m[1]); } catch { reply.code(403); return ''; }
    const res = await checkMediaAccess(rel, await feedTokenUser(req, uri), req.shareTokens);
    if (res.ok) { reply.code(200); return ''; }
    reply.code(res.status === 401 || res.status === 403 ? res.status : (req.user ? 403 : 401));
    return '';
  });

  // Резервная раздача (если nginx не перехватывает /media/). Поддерживает Range.
  app.get('/media/*', async (req, reply) => {
    const rel = decodeURIComponent(req.params['*'] || '');
    const res = await checkMediaAccess(rel, await feedTokenUser(req), req.shareTokens);
    if (!res.ok) {
      if (res.status === 401) throw unauthorized();
      if (res.status === 403) throw forbidden();
      throw notFound();
    }
    const abs = path.resolve(config.mediaDir, rel);
    if (!abs.startsWith(path.resolve(config.mediaDir) + path.sep)) throw notFound();
    let st;
    try { st = await fsp.stat(abs); } catch { throw notFound(); }
    if (!st.isFile()) throw notFound();
    const ext = path.extname(abs).slice(1).toLowerCase();
    reply.header('Content-Type', MIME[ext] || 'application/octet-stream');
    reply.header('Accept-Ranges', 'bytes');
    reply.header('Cache-Control', ext === 'm3u8' ? 'no-cache' : 'private, max-age=86400');
    if (req.query.download !== undefined) {
      reply.header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(path.basename(abs))}`);
    }
    const range = req.headers.range;
    if (range) {
      const mm = range.match(/bytes=(\d*)-(\d*)/);
      if (mm) {
        let start = mm[1] ? parseInt(mm[1], 10) : 0;
        let end = mm[2] ? parseInt(mm[2], 10) : st.size - 1;
        if (!mm[1] && mm[2]) { start = Math.max(0, st.size - parseInt(mm[2], 10)); end = st.size - 1; }
        if (start >= st.size || end >= st.size || start > end) { reply.code(416).header('Content-Range', `bytes */${st.size}`); return ''; }
        reply.code(206).header('Content-Range', `bytes ${start}-${end}/${st.size}`).header('Content-Length', end - start + 1);
        return reply.send(fs.createReadStream(abs, { start, end }));
      }
    }
    reply.header('Content-Length', st.size);
    return reply.send(fs.createReadStream(abs));
  });

  // Скачивание оригинала/mp4 с человеческим именем файла
  app.get('/api/videos/:id/download', async (req, reply) => {
    const v = await one('SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [req.params.id]);
    if (!v) throw notFound('Видео не найдено');
    // Скачивание (в том числе оригинала) — только из сеанса: личный токен лент сюда не пускает
    const user = req.user;
    if (!(await canPlayVideo(v, user, req.shareTokens))) throw user ? forbidden() : unauthorized();
    const owner = user && user.id === v.owner_id;
    if (!(owner || isStaff(user) || (v.allow_download && user))) {
      const link = !req.user && req.shareTokens?.length ? await shareLinkFor(v.id, req.shareTokens) : null;
      if (!(link && link.allow_download)) throw forbidden('Скачивание этого видео запрещено автором');
    }
    const wantOriginal = req.query.type !== 'mp4';
    let rel = wantOriginal && v.original_kept && v.original_path ? v.original_path : v.mp4_path;
    if (!rel) rel = v.original_kept ? v.original_path : null;
    if (!rel) throw notFound('Файл для скачивания недоступен');
    const abs = path.resolve(config.mediaDir, rel);
    const ext = path.extname(abs).slice(1).toLowerCase();
    const safeTitle = String(v.title).replace(/[\\/:*?"<>|\r\n]+/g, '_').slice(0, 120) || 'video';
    const filename = `${safeTitle}.${ext}`;
    reply.header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(filename)}`);
    reply.header('Content-Type', MIME[ext] || 'application/octet-stream');
    // За nginx файл отдаёт он сам (location /__corpvideo_internal_media/ { internal; alias …; })
    const accel = storage.accel(req, rel);
    if (accel) { reply.header('X-Accel-Redirect', accel); return ''; }
    let st;
    try { st = await fsp.stat(abs); } catch { throw notFound('Файл не найден'); }
    reply.header('Content-Length', st.size);
    reply.header('Accept-Ranges', 'bytes');
    return reply.send(fs.createReadStream(abs));
  });
}
