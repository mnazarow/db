// Загрузка видео: возобновляемая по частям (chunked) и простая (multipart) для интеграций.
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import { pipeline } from 'node:stream/promises';
import { one, many, query, tx, withLock } from '../db.js';
import { storage, ensureDir, removeFile, removeDir } from '../lib/storage.js';
import { canUpload } from '../lib/access.js';
import { enqueue } from '../lib/jobs.js';
import { shortId } from '../lib/crypto.js';
import { badRequest, forbidden, notFound, conflict, extOf, safeFilename, HttpError, intOrNull, sizeOrNull, isUuid} from '../lib/util.js';
import { audit } from '../lib/audit.js';
import { videoCard } from '../lib/serialize.js';
import { canEditVideo } from '../lib/access.js';
import { invalidateVideoCache } from './media.js';
import { config } from '../config.js';
import dns from 'node:dns/promises';
import { applyFreshDefault } from '../lib/lifecycle.js';
import { assertQuota, lockQuota } from '../lib/quota.js';

export const CHUNK_SIZE = 8 * 1024 * 1024;

/** Размер части для докачки: у больших файлов части крупнее, иначе на терабайт уходят сотни тысяч запросов.
 *  Верхний предел — 64 МБ: столько пропускает nginx в поставке (client_max_body_size у /api/uploads/). */
export function chunkSizeFor(size) {
  const gb = (Number(size) || 0) / (1024 * 1024 * 1024);
  if (gb > 500) return 64 * 1024 * 1024;
  if (gb > 100) return 32 * 1024 * 1024;
  if (gb > 10) return 16 * 1024 * 1024;
  return CHUNK_SIZE;
}

async function checkUploadAllowed(req, filename, size) {
  const s = req.settings;
  if (!canUpload(req.user, s)) throw forbidden('Загрузка видео вам не разрешена. Обратитесь к администратору.');
  const ext = extOf(filename);
  const allowed = (s['upload.allowed_extensions'] || []).map((e) => String(e).toLowerCase().replace(/^\./, ''));
  if (!ext || !allowed.includes(ext)) throw badRequest(`Формат .${ext || '?'} не поддерживается. Разрешены: ${allowed.join(', ')}`);
  const max = (Number(s['upload.max_size_mb']) || 0) * 1024 * 1024;
  if (!Number.isFinite(size) || size <= 0) throw badRequest('Некорректный размер файла: браузер не сообщил размер или файл пустой');
  if (max && size > max) throw badRequest(`Файл больше допустимого размера (${s['upload.max_size_mb']} МБ)`);
  const perDay = s['upload.max_per_day'] || 0;
  if (perDay && req.user.role !== 'admin') {
    const n = await one(`SELECT count(*)::int AS n FROM videos WHERE owner_id = $1 AND created_at > now() - interval '1 day'`, [req.user.id]);
    if (n.n >= perDay) throw forbidden(`Достигнут дневной лимит загрузок (${perDay})`);
  }
  // Квота на канал (1.8): предварительная проверка — окончательная идёт под блокировкой при создании записи
  await assertQuota({ one }, req, size);
  return ext;
}

/** Создать запись видео + загрузки. */
async function createVideoAndUpload(req, { filename, size, mime, meta = {} }) {
  const s = req.settings;
  const ext = await checkUploadAllowed(req, filename, size);
  const title = String(meta.title || path.basename(filename, path.extname(filename))).trim().slice(0, 150) || 'Без названия';
  const visibility = ['public', 'internal', 'unlisted', 'private'].includes(meta.visibility) ? meta.visibility : s['upload.default_visibility'];
  const premod = s['upload.premoderation'] && req.user.role === 'user';
  return tx(async (c) => {
    // Проверка и запись — под блокировкой на сотрудника: параллельные загрузки не обходят квоту
    await lockQuota(c, req.user.id);
    await assertQuota(c, req, size);
    const video = await c.one(
      `INSERT INTO videos(short_id, owner_id, title, description, visibility, status, moderation_status, original_filename, original_size, comments_mode, category_id, tags)
       VALUES ($1,$2,$3,$4,$5,'uploading',$6,$7,$8,$9,$10,$11) RETURNING *`,
      [shortId(), req.user.id, title, String(meta.description || '').slice(0, 10000), visibility, premod ? 'pending' : 'approved',
        safeFilename(filename), size, s['comments.default_mode'], intOrNull(meta.categoryId), []],
    );
    await applyFreshDefault(video.id, s, c);
    await ensureDir(storage.videoDir(video.id));
    const tmp = storage.uploadTmp(video.id);
    const upload = await c.one(
      `INSERT INTO uploads(user_id, video_id, filename, size, mime, tmp_path) VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
      [req.user.id, video.id, safeFilename(filename), size, mime || null, tmp],
    );
    return { video, upload, ext };
  });
}

/** Загрузка для замены файла существующего видео (версия +1, повторная обработка). */
async function createReplaceUpload(req, video, { filename, size, mime }) {
  if (!canEditVideo(video, req.user)) throw forbidden('Заменить файл может только автор или модератор');
  if (!['ready', 'failed'].includes(video.status)) throw conflict('Дождитесь окончания обработки видео');
  await checkUploadAllowed(req, filename, size);
  const active = await one(`SELECT id FROM uploads WHERE video_id = $1 AND status = 'active'`, [video.id]);
  if (active) await query(`UPDATE uploads SET status = 'aborted', updated_at = now() WHERE id = $1`, [active.id]);
  const tmp = storage.uploadTmp(`${video.id}-r${Date.now()}`);
  const upload = await one(
    `INSERT INTO uploads(user_id, video_id, filename, size, mime, tmp_path, replace) VALUES ($1,$2,$3,$4,$5,$6,true) RETURNING *`,
    [req.user.id, video.id, safeFilename(filename), size, mime || null, tmp],
  );
  return { video, upload };
}

/**
 * Принятый файл → оригинал видео → очередь транскодирования.
 * Используется и загрузками, и импортом по ссылке (воркер), и заменой файла.
 */
export async function finalizeFile({ video, srcPath, filename, replace = false, priority = 0 }) {
  const ext = extOf(filename) || 'mp4';
  const dir = storage.videoDir(video.id);
  await ensureDir(dir);
  const dest = path.join(dir, `original.${ext}`);
  if (replace && video.original_path) {
    const oldAbs = storage.abs(video.original_path);
    if (path.resolve(oldAbs) !== path.resolve(dest)) await removeFile(oldAbs).catch(() => {});
  }
  await fsp.rename(srcPath, dest).catch(async () => { await fsp.copyFile(srcPath, dest); await fsp.unlink(srcPath); });
  const st = await fsp.stat(dest);
  if (replace) {
    await query(
      `UPDATE videos SET status = 'queued', original_path = $2, original_size = $3, original_kept = true, original_filename = $4,
         version = version + 1, replaced_at = now(), processing_progress = 0, processing_error = NULL, mp4_path = NULL, storyboard_path = NULL WHERE id = $1`,
      [video.id, storage.rel(dest), st.size, safeFilename(filename)],
    );
    // старые автосубтитры больше не соответствуют новому файлу — помечаем
    await query(`UPDATE subtitles SET label = CASE WHEN label LIKE '%(устарели)%' THEN label ELSE label || ' (устарели)' END WHERE video_id = $1 AND kind = 'auto'`, [video.id]);
    // распознанный текст с экрана и расшифровка речи привязаны к таймкодам старого файла —
    // сбрасываем, иначе поиск и ИИ продолжат работать по содержимому предыдущей версии
    await query('DELETE FROM video_screen_text WHERE video_id = $1', [video.id]);
    await query(`UPDATE videos SET screen_text = NULL, ocr_status = NULL, ocr_at = NULL, transcript = NULL WHERE id = $1`, [video.id]);
  } else {
    await query(`UPDATE videos SET status = 'queued', original_path = $2, original_size = $3, original_kept = true WHERE id = $1`, [video.id, storage.rel(dest), st.size]);
  }
  invalidateVideoCache(video.id);
  await enqueue('transcode', { videoId: video.id }, { videoId: video.id, priority });
  return one('SELECT * FROM videos WHERE id = $1', [video.id]);
}

async function finalizeUpload(req, upload, video) {
  const st = await fsp.stat(upload.tmp_path);
  await query(`UPDATE uploads SET status = 'completed', received_bytes = $2, updated_at = now() WHERE id = $1`, [upload.id, st.size]);
  const v = await finalizeFile({ video, srcPath: upload.tmp_path, filename: upload.filename, replace: !!upload.replace, priority: req.user.role === 'admin' ? 1 : 0 });
  await audit(req, upload.replace ? 'video.replace' : 'video.upload', { targetType: 'video', targetId: video.id, details: { filename: upload.filename, size: st.size } });
  return v;
}

const IMPORT_URL_RE = /^https?:\/\/[^\s]+$/i;

/** Адрес указывает на сам сервер (loopback, link-local, 0.0.0.0)? Корпоративные адреса LAN разрешены. */
async function isLocalHost(host) {
  const h = host.replace(/^\[|\]$/g, '').toLowerCase();
  const isLocalIp = (ip) => /^(127\.|0\.|169\.254\.)/.test(ip) || ip === '::1' || ip === '::' || /^::ffff:(127\.|0\.|169\.254\.)/i.test(ip) || /^fe80:/i.test(ip);
  if (h === 'localhost' || h.endsWith('.localhost') || isLocalIp(h)) return true;
  try {
    const addrs = await dns.lookup(h, { all: true });
    return addrs.some((a) => isLocalIp(a.address));
  } catch { return false; }
}

/** Найти загрузку пользователя. Промах пишем в журнал: по жалобе «Загрузка не найдена»
 *  без этой строки не понять, ушёл ли клиент по мусорному адресу (например /api/uploads/undefined). */
async function findUpload(req) {
  const id = req.params.id;
  if (!isUuid(id)) {
    req.log.warn({ uploadId: String(id).slice(0, 80), route: `${req.method} ${req.url}`, userId: req.user?.id }, 'обращение к загрузке по некорректному идентификатору');
    throw notFound('Загрузка не найдена');
  }
  const u = await one('SELECT * FROM uploads WHERE id = $1 AND user_id = $2', [id, req.user.id]);
  if (!u) {
    req.log.warn({ uploadId: id, route: `${req.method} ${req.url}`, userId: req.user?.id }, 'загрузка не найдена или принадлежит другому пользователю');
    throw notFound('Загрузка не найдена');
  }
  return u;
}

export default async function uploadRoutes(app) {
  // Сырой поток для чанков
  app.addContentTypeParser('application/offset+octet-stream', (req, payload, done) => done(null, payload));

  // 1. Создать загрузку
  app.post('/', { preHandler: app.requireActive }, async (req) => {
    const { filename, size, mime, title, description, visibility, categoryId, replaceVideoId } = req.body || {};
    let video, upload;
    if (replaceVideoId) {
      const existing = await one('SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [String(replaceVideoId)]);
      if (!existing) throw notFound('Видео не найдено');
      const bytes = sizeOrNull(size);
      if (bytes === null) throw badRequest('Некорректный размер файла: браузер не сообщил размер или файл пустой');
      ({ video, upload } = await createReplaceUpload(req, existing, { filename: String(filename || ''), size: bytes, mime }));
    } else {
      const bytes = sizeOrNull(size);
      if (bytes === null) throw badRequest('Некорректный размер файла: браузер не сообщил размер или файл пустой');
      ({ video, upload } = await createVideoAndUpload(req, { filename: String(filename || ''), size: bytes, mime, meta: { title, description, visibility, categoryId } }));
    }
    return { uploadId: upload.id, videoId: video.id, shortId: video.short_id, chunkSize: chunkSizeFor(upload.size), offset: 0, replace: !!upload.replace, video: videoCard(video) };
  });

  // Импорт по ссылке: прямая ссылка на файл (http/https) или, при установленном yt-dlp, страница видеосервиса
  app.post('/from-url', { preHandler: app.requireActive }, async (req) => {
    const s = req.settings;
    if (!canUpload(req.user, s)) throw forbidden('Загрузка видео вам не разрешена. Обратитесь к администратору.');
    const url = String(req.body?.url || '').trim();
    if (!IMPORT_URL_RE.test(url) || url.length > 2000) throw badRequest('Укажите корректную ссылку http(s)://…');
    let host;
    try { host = new URL(url).hostname; } catch { throw badRequest('Некорректная ссылка'); }
    if (!config.importAllowLocal && (await isLocalHost(host))) throw badRequest('Импорт с локальных адресов сервера запрещён');
    const { title, description, visibility, categoryId } = req.body || {};
    const guessName = decodeURIComponent((new URL(url).pathname.split('/').pop() || '').slice(0, 150)) || 'import.mp4';
    const premod = s['upload.premoderation'] && req.user.role === 'user';
    const vis = ['public', 'internal', 'unlisted', 'private'].includes(visibility) ? visibility : s['upload.default_visibility'];
    const video = await one(
      `INSERT INTO videos(short_id, owner_id, title, description, visibility, status, moderation_status, original_filename, original_size, comments_mode, category_id, tags, source_url, processing_stage)
       VALUES ($1,$2,$3,$4,$5,'uploading',$6,$7,0,$8,$9,$10,$11,'import') RETURNING *`,
      [shortId(), req.user.id, String(title || '').trim().slice(0, 150) || guessName.replace(/\.[a-z0-9]+$/i, '') || 'Импорт', String(description || '').slice(0, 10000), vis,
        premod ? 'pending' : 'approved', safeFilename(guessName), s['comments.default_mode'], intOrNull(categoryId), [], url],
    );
    await applyFreshDefault(video.id, s);
    await ensureDir(storage.videoDir(video.id));
    await enqueue('import_url', { videoId: video.id, url, userId: req.user.id, priority: req.user.role === 'admin' ? 1 : 0 }, { videoId: video.id, priority: 0, maxAttempts: 2 });
    await audit(req, 'video.import_url', { targetType: 'video', targetId: video.id, details: { url } });
    return { videoId: video.id, shortId: video.short_id, video: videoCard(video) };
  });

  // 2. Состояние (для возобновления)
  app.get('/:id', { preHandler: app.requireActive }, async (req) => {
    const u = await findUpload(req);
    let offset = u.received_bytes;
    try { offset = (await fsp.stat(u.tmp_path)).size; } catch { offset = u.status === 'completed' ? u.size : 0; }
    return { uploadId: u.id, videoId: u.video_id, offset, size: u.size, status: u.status, chunkSize: chunkSizeFor(u.size) };
  });

  // 3. Принять часть (PATCH, заголовок Upload-Offset)
  app.patch('/:id', { preHandler: app.requireActive, bodyLimit: 80 * 1024 * 1024 }, async (req, reply) => {
    const u = await findUpload(req);
    if (u.status !== 'active') throw conflict('Загрузка уже завершена или отменена');
    const offset = parseInt(req.headers['upload-offset'] ?? req.query.offset ?? '0', 10);
    // Проверка смещения и дозапись должны быть неделимы: два одновременных PATCH одной загрузки
    // (повтор медленного чанка, вторая вкладка) видели одинаковый размер и портили файл.
    const lock = await withLock(`upload:${u.id}`, async () => {
      let current = 0;
      try { current = (await fsp.stat(u.tmp_path)).size; } catch { current = 0; }
      if (offset !== current) return { conflict: true, offset: current };
      await ensureDir(path.dirname(u.tmp_path));
      let written = 0;
      const ws = fs.createWriteStream(u.tmp_path, { flags: 'a' });
      const counter = new (await import('node:stream')).Transform({
        transform(chunk, enc, cb) { written += chunk.length; if (current + written > u.size) return cb(new HttpError(400, 'Получено больше данных, чем заявленный размер файла')); cb(null, chunk); },
      });
      try {
        await pipeline(req.body, counter, ws);
      } catch (e) {
        throw e instanceof HttpError ? e : badRequest('Ошибка приёма данных: ' + e.message);
      }
      const newOffset = current + written;
      await query('UPDATE uploads SET received_bytes = $2, updated_at = now() WHERE id = $1', [u.id, newOffset]);
      return { offset: newOffset, size: u.size, complete: newOffset >= u.size };
    });
    if (!lock.ok) {
      let current = 0;
      try { current = (await fsp.stat(u.tmp_path)).size; } catch { current = 0; }
      reply.code(409);
      return { error: 'Эта часть уже принимается', offset: current };
    }
    if (lock.value.conflict) {
      reply.code(409);
      return { error: 'Смещение не совпадает', offset: lock.value.offset };
    }
    return lock.value;
  });

  // 4. Завершить
  app.post('/:id/complete', { preHandler: app.requireActive }, async (req) => {
    const u = await findUpload(req);
    if (u.status === 'completed') {
      const v = await one('SELECT * FROM videos WHERE id = $1', [u.video_id]);
      return { ok: true, video: videoCard(v) };
    }
    let size = 0;
    try { size = (await fsp.stat(u.tmp_path)).size; } catch { size = 0; }
    if (size !== u.size) throw badRequest(`Файл получен не полностью (${size} из ${u.size} байт)`);
    const video = await one('SELECT * FROM videos WHERE id = $1', [u.video_id]);
    if (!video) throw notFound('Видео не найдено');
    const v = await finalizeUpload(req, u, video);
    return { ok: true, video: videoCard(v) };
  });

  // 5. Отменить
  app.delete('/:id', { preHandler: app.requireActive }, async (req) => {
    const u = await findUpload(req);
    if (u.status === 'active') {
      await query(`UPDATE uploads SET status = 'aborted', updated_at = now() WHERE id = $1`, [u.id]);
      await removeFile(u.tmp_path);
      const v = u.replace ? null : await one(`SELECT id FROM videos WHERE id = $1 AND status = 'uploading'`, [u.video_id]);
      if (v) { await query('DELETE FROM videos WHERE id = $1', [v.id]); await removeDir(storage.videoDir(v.id)).catch(() => {}); }
    }
    return { ok: true };
  });

  // 6. Простая загрузка одним запросом (multipart) — для скриптов и интеграций через API-токен
  app.post('/simple', { preHandler: app.requireActive, bodyLimit: 2 * 1024 * 1024 * 1024 * 1024 }, async (req) => {
    const tmpDir = path.dirname(storage.uploadTmp('x'));
    await ensureDir(tmpDir);
    // Сначала принимаем файл во временный каталог, затем проверяем размер.
    // Идём по всем частям multipart (а не только по первой файловой): текстовые поля,
    // идущие ПОСЛЕ файла, иначе могут не успеть распарситься к моменту окончания файла.
    const tmp = path.join(tmpDir, `simple-${crypto.randomUUID()}.part`);
    const { Transform } = await import('node:stream');
    const fields = {};
    let part = null;
    let size = 0;
    try {
      for await (const p of req.parts({ limits: { fileSize: (Number(req.settings['upload.max_size_mb']) || 2097152) * 1024 * 1024 } })) {
        if (p.type === 'file') {
          if (part) { p.file.resume(); await new Promise((r) => p.file.once('end', r)); continue; } // второй файл игнорируем
          part = p;
          await pipeline(p.file, new Transform({ transform(c, e, cb) { size += c.length; cb(null, c); } }), fs.createWriteStream(tmp));
          if (p.file.truncated) throw badRequest('Файл больше допустимого размера');
        } else if (p.type === 'field' && fields[p.fieldname] === undefined && p.value !== undefined) fields[p.fieldname] = p.value;
      }
      if (!part) throw badRequest('Ожидается поле file');
      let video, upload;
      if (fields.replaceVideoId) {
        const existing = await one('SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [String(fields.replaceVideoId)]);
        if (!existing) throw notFound('Видео не найдено');
        ({ video, upload } = await createReplaceUpload(req, existing, { filename: part.filename, size, mime: part.mimetype }));
      } else ({ video, upload } = await createVideoAndUpload(req, { filename: part.filename, size, mime: part.mimetype, meta: fields }));
      await fsp.rename(tmp, upload.tmp_path);
      const v = await finalizeUpload(req, upload, video);
      return { ok: true, video: videoCard(v) };
    } catch (e) {
      await removeFile(tmp);
      throw e;
    }
  });
}
