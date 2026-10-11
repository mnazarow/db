// Редактор видео (обрезка, вырезание, удаление пауз), клипы как отдельные видео, перевод субтитров,
// текст на экране (OCR) и повтор чата для записей эфиров.
import { one, many, query, withLock } from '../db.js';
import { badRequest, forbidden, notFound } from '../lib/util.js';
import { enqueue } from '../lib/jobs.js';
import { audit } from '../lib/audit.js';
import { canUpload, canEditVideo, listVisibilitySql, canViewVideo } from '../lib/access.js';
import { videoCard } from '../lib/serialize.js';
import { requireEditable, requireViewable } from './videos.js';
import { screenTextOf, ocrAvailable } from '../lib/ocr.js';
import { languageLabel } from '../jobs/translate.js';
import { normalizeSegments, invertCuts } from '../jobs/editor.js';
import { normalizeRegions } from '../jobs/blur.js';
import { findFillers, cutsFromSelection } from '../lib/textedit.js';
import { videoSource } from '../lib/ocr.js';
import { ffmpegToBuffer, withPreviewSlot } from '../lib/ffmpeg.js';
import fsp from 'node:fs/promises';
import { storage } from '../lib/storage.js';
import { vttToSegments } from '../lib/util.js';
import { mediaUrl } from '../lib/urls.js';

const EDITOR_JOBS = ['video_edit', 'remove_silence', 'clip_create', 'ocr', 'subtitle_translate', 'video_blur', 'face_detect', 'video_render'];

function jobOut(j) {
  return { id: Number(j.id), type: j.type, status: j.status, progress: j.progress || 0, stage: j.stage, error: j.error, result: j.result, payload: { start: j.payload?.start, end: j.payload?.end, apply: j.payload?.apply, vertical: j.payload?.vertical, language: j.payload?.language, op: j.payload?.op }, createdAt: j.created_at, finishedAt: j.finished_at };
}

function num(v, name, { min = 0, max = Infinity } = {}) {
  const n = Number(v);
  if (!Number.isFinite(n) || n < min || n > max) throw badRequest(`Некорректное значение: ${name}`);
  return n;
}

// Разрушающие операции (меняют файл видео). Два таких задания одновременно перезаписали бы работу друг друга.
const DESTRUCTIVE_SQL = `(j.type IN ('video_edit','video_blur','video_render') OR (j.type = 'remove_silence' AND j.payload->>'apply' = 'true'))`;

/**
 * Поставить разрушающее задание, только если другого такого нет.
 * Проверка и вставка идут под межпроцессной блокировкой: два одновременных запроса
 * (двойной клик, повтор) иначе создали бы два монтажа, которые перезапишут файл друг друга.
 */
async function enqueueExclusiveEdit(videoId, type, payload, opts = {}) {
  const res = await withLock(`corpvideo:video-edit:${videoId}`, async () => {
    const busy = await one(`SELECT j.id FROM jobs j WHERE j.video_id = $1 AND j.status IN ('queued','running') AND ${DESTRUCTIVE_SQL} ORDER BY j.id LIMIT 1`, [videoId]);
    if (busy) return { busy: Number(busy.id) };
    return { job: await enqueue(type, payload, { videoId, priority: 1, maxAttempts: 1, dedupe: false, ...opts }) };
  });
  if (!res.ok) throw badRequest('Над этим видео уже выполняется операция — повторите через несколько секунд');
  if (res.value.busy) throw badRequest('Над этим видео уже выполняется монтаж — дождитесь его завершения (задание №' + res.value.busy + ')');
  return res.value.job;
}

/** Клипы читают исходный файл, поэтому во время монтажа их создавать нельзя. */
async function ensureNoActiveEdit(videoId) {
  const busy = await one(`SELECT j.id FROM jobs j WHERE j.video_id = $1 AND j.status IN ('queued','running') AND ${DESTRUCTIVE_SQL} ORDER BY j.id LIMIT 1`, [videoId]);
  if (busy) throw badRequest('Над этим видео уже выполняется монтаж — дождитесь его завершения (задание №' + Number(busy.id) + ')');
}

async function ensureEditable(req, id) {
  if (!req.settings['editor.enabled']) throw forbidden('Редактор видео отключён администратором');
  const v = await requireEditable(req, id);
  if (!['ready', 'failed'].includes(v.status)) throw badRequest('Дождитесь окончания обработки видео');
  return v;
}

export default async function editorRoutes(app) {
  // --- Состояние редактора ------------------------------------------------------------------
  app.get('/videos/:id/editor', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const jobs = await many(`SELECT * FROM jobs WHERE video_id = $1 AND type = ANY($2) ORDER BY id DESC LIMIT 20`, [v.id, EDITOR_JOBS]);
    const clips = await many(`SELECT v.*, u.display_name AS owner_name, u.handle AS owner_handle FROM videos v JOIN users u ON u.id = v.owner_id WHERE v.clip_of = $1 AND v.deleted_at IS NULL ORDER BY v.created_at DESC LIMIT 50`, [v.id]);
    const s = req.settings;
    return {
      enabled: !!s['editor.enabled'], duration: Number(v.duration) || 0, status: v.status,
      sourceAvailable: !!(v.original_path && v.original_kept) || !!v.mp4_path || !!v.hls_path,
      silence: { noiseDb: s['editor.silence_db'], minSec: s['editor.silence_min_sec'], keepSec: s['editor.silence_keep_sec'] },
      history: v.edit_history || [], jobs: jobs.map(jobOut), clips: clips.map(videoCard),
      ocr: { enabled: !!s['ocr.enabled'], status: v.ocr_status, at: v.ocr_at },
      blur: { enabled: !!s['editor.blur_enabled'], faces: !!s['editor.faces_enabled'], strength: s['editor.blur_strength'], regions: v.blur_regions || [] },
      textEdit: { enabled: !!s['editor.text_edit'], fillerWords: s['editor.filler_words'] || [] },
    };
  });

  app.get('/videos/:id/editor/jobs/:jobId', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    const j = await one('SELECT * FROM jobs WHERE id = $1 AND video_id = $2', [Number(req.params.jobId), v.id]);
    if (!j) throw notFound('Задание не найдено');
    if (!canEditVideo(v, req.user) && j.payload?.byUserId !== req.user.id) throw forbidden();
    return { job: jobOut(j) };
  });

  // --- Обрезка начала/конца и вырезание фрагментов → новая версия ------------------------------
  app.post('/videos/:id/editor/trim', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    const duration = Number(v.duration) || 0;
    const start = num(req.body?.start ?? 0, 'start', { min: 0, max: duration });
    const end = num(req.body?.end ?? duration, 'end', { min: 0, max: duration + 0.5 });
    if (end - start < 1) throw badRequest('Оставшаяся часть короче 1 секунды');
    if (start < 0.05 && end >= duration - 0.05) throw badRequest('Границы совпадают с началом и концом видео — нечего обрезать');
    const j = await enqueueExclusiveEdit(v.id, 'video_edit', { videoId: v.id, keep: [{ start, end }], byUserId: req.user.id, op: 'trim' });
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: 'trim', start, end } });
    return { ok: true, jobId: Number(j.id) };
  });

  app.post('/videos/:id/editor/cut', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    const duration = Number(v.duration) || 0;
    const raw = (Array.isArray(req.body?.cuts) ? req.body.cuts : []).slice(0, 100);
    if (raw.some((c) => !c || typeof c !== 'object' || Array.isArray(c))) throw badRequest('Каждый фрагмент должен быть объектом {start, end}');
    const cuts = normalizeSegments(raw.map((c) => ({ start: num(c.start, 'start', { min: 0, max: duration }), end: num(c.end, 'end', { min: 0, max: duration + 0.5 }) })), duration);
    if (!cuts.length) throw badRequest('Укажите хотя бы один фрагмент для вырезания');
    const keep = invertCuts(cuts, duration);
    if (!keep.length) throw badRequest('После вырезания не остаётся ни одного фрагмента');
    const j = await enqueueExclusiveEdit(v.id, 'video_edit', { videoId: v.id, cuts, byUserId: req.user.id, op: 'cut' });
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: 'cut', cuts } });
    return { ok: true, jobId: Number(j.id), keep };
  });

  // --- Удаление пауз: поиск (apply=false) или применение ---------------------------------------
  app.post('/videos/:id/editor/silence', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    const b = req.body || {};
    const payload = {
      videoId: v.id, apply: !!b.apply, byUserId: req.user.id,
      noiseDb: b.noiseDb !== undefined ? num(b.noiseDb, 'noiseDb', { min: -90, max: 0 }) : undefined,
      minSec: b.minSec !== undefined ? num(b.minSec, 'minSec', { min: 0.3, max: 30 }) : undefined,
      keepSec: b.keepSec !== undefined ? num(b.keepSec, 'keepSec', { min: 0, max: 5 }) : undefined,
    };
    // Поиск пауз (apply=false) схлопываем по dedupe — иначе один пользователь набьёт очередь тяжёлыми заданиями
    const j = payload.apply
      ? await enqueueExclusiveEdit(v.id, 'remove_silence', payload)
      : await enqueue('remove_silence', payload, { videoId: v.id, priority: 1, maxAttempts: 1, dedupe: true });
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: b.apply ? 'remove_silence' : 'detect_silence' } });
    return { ok: true, jobId: Number(j.id) };
  });

  // --- Размытие лиц и областей в кадре (1.9) ---------------------------------------------------
  app.post('/videos/:id/editor/blur', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    if (!req.settings['editor.blur_enabled']) throw forbidden('Размытие отключено администратором');
    const regions = normalizeRegions(req.body?.regions, Number(v.duration) || 0);
    if (!regions.length) throw badRequest('Укажите хотя бы одну область размытия');
    const j = await enqueueExclusiveEdit(v.id, 'video_blur', { videoId: v.id, regions, byUserId: req.user.id });
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: 'blur', regions: regions.length } });
    return { ok: true, jobId: Number(j.id), regions };
  });

  // Автопоиск лиц: отдельное задание, результат забирается через /editor/jobs/:jobId
  app.post('/videos/:id/editor/faces', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    if (!req.settings['editor.blur_enabled'] || !req.settings['editor.faces_enabled']) throw forbidden('Автопоиск лиц отключён администратором');
    const j = await enqueue('face_detect', { videoId: v.id, byUserId: req.user.id, step: 2 }, { videoId: v.id, priority: 1, maxAttempts: 1, dedupe: true });
    return { ok: true, jobId: Number(j.id) };
  });

  // Кадр видео для рисования рамок (jpeg)
  app.get('/videos/:id/editor/frame', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await ensureEditable(req, req.params.id);
    const t = Math.max(0, Math.min(Number(v.duration) || 0, Number(req.query.t) || 0));
    const src = await videoSource(v);
    const buf = await withPreviewSlot(() => ffmpegToBuffer(['-ss', String(t), '-i', src, '-frames:v', '1', '-vf', "scale='min(960,iw)':-2", '-f', 'image2', '-c:v', 'mjpeg', '-q:v', '4', 'pipe:1'], { label: 'кадр' }))
      .catch((e) => { if (e.statusCode === 429) throw e; throw badRequest('Не удалось получить кадр: ' + e.message); });
    reply.header('Content-Type', 'image/jpeg').header('Cache-Control', 'private, max-age=600');
    return reply.send(buf);
  });

  // --- Монтаж по расшифровке и слова-паразиты (1.9) ---------------------------------------------
  async function transcriptOf(video, trackId) {
    const sub = trackId
      ? await one(`SELECT * FROM subtitles WHERE video_id = $1 AND id = cv_uuid($2) AND status = 'ready'`, [video.id, String(trackId)])
      : await one(`SELECT * FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, (kind = 'manual') DESC, created_at LIMIT 1`, [video.id]);
    if (!sub?.path) return { sub: null, segments: [] };
    const vtt = await fsp.readFile(storage.abs(sub.path), 'utf8').catch(() => '');
    return { sub, segments: vttToSegments(vtt) };
  }

  app.get('/videos/:id/editor/transcript', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    const tracks = await many(`SELECT id, language, label, kind, is_default FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, created_at`, [v.id]);
    const { sub, segments } = await transcriptOf(v, req.query.track);
    return {
      enabled: !!req.settings['editor.text_edit'], duration: Number(v.duration) || 0,
      track: sub ? { id: sub.id, language: sub.language, label: sub.label, kind: sub.kind } : null,
      tracks: tracks.map((t) => ({ id: t.id, language: t.language, label: t.label, kind: t.kind })),
      segments: segments.map((x, i) => ({ index: i, start: Math.round(x.start * 100) / 100, end: Math.round(x.end * 100) / 100, text: x.text })),
    };
  });

  // Слова-паразиты: поиск (apply=false) и вырезание выбранных
  app.post('/videos/:id/editor/fillers', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    if (!req.settings['editor.text_edit']) throw forbidden('Монтаж по расшифровке отключён администратором');
    const duration = Number(v.duration) || 0;
    const b = req.body || {};
    const custom = Array.isArray(b.words) ? b.words.slice(0, 50).map((w) => String(w).slice(0, 40)) : null;
    const { sub, segments } = await transcriptOf(v, b.track);
    if (!sub) throw badRequest('У видео нет расшифровки — включите распознавание речи или загрузите субтитры');
    const items = findFillers(segments, custom && custom.length ? custom : req.settings['editor.filler_words']);
    if (!b.apply) return { items, total: items.length, totalSec: Math.round(items.reduce((n, x) => n + (x.end - x.start), 0) * 10) / 10, track: { id: sub.id, label: sub.label } };
    const chosen = Array.isArray(b.items) && b.items.length
      ? b.items.map((x) => ({ start: Number(x.start), end: Number(x.end) })).filter((x) => Number.isFinite(x.start) && Number.isFinite(x.end))
      : items;
    const cuts = cutsFromSelection(chosen, duration);
    if (!cuts.length) throw badRequest('Слова-паразиты не выбраны');
    const keep = invertCuts(cuts, duration);
    if (!keep.length) throw badRequest('После вырезания не остаётся ни одного фрагмента');
    const j = await enqueueExclusiveEdit(v.id, 'video_edit', { videoId: v.id, cuts, byUserId: req.user.id, op: 'fillers' });
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: 'fillers', cuts: cuts.length } });
    return { ok: true, jobId: Number(j.id), cuts, removedSec: Math.round(cuts.reduce((n, c) => n + (c.end - c.start), 0) * 10) / 10 };
  });

  // Вырезание выбранных фраз расшифровки
  app.post('/videos/:id/editor/text-cut', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditable(req, req.params.id);
    if (!req.settings['editor.text_edit']) throw forbidden('Монтаж по расшифровке отключён администратором');
    const duration = Number(v.duration) || 0;
    const { sub, segments } = await transcriptOf(v, req.body?.track);
    if (!sub) throw badRequest('У видео нет расшифровки');
    const idx = Array.isArray(req.body?.segments) ? req.body.segments.map(Number).filter((n) => Number.isInteger(n) && n >= 0 && n < segments.length) : [];
    const chosen = idx.length ? idx.map((i) => segments[i]) : [];
    const cuts = cutsFromSelection(chosen, duration, { pad: 0.02, gap: 0.3 });
    if (!cuts.length) throw badRequest('Выберите фразы, которые нужно вырезать');
    const keep = invertCuts(cuts, duration);
    if (!keep.length) throw badRequest('После вырезания не остаётся ни одного фрагмента');
    const j = await enqueueExclusiveEdit(v.id, 'video_edit', { videoId: v.id, cuts, byUserId: req.user.id, op: 'transcript_cut' });
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: 'transcript_cut', phrases: idx.length } });
    return { ok: true, jobId: Number(j.id), cuts, removedSec: Math.round(cuts.reduce((n, c) => n + (c.end - c.start), 0) * 10) / 10 };
  });

  // --- Клип как отдельное видео (в т. ч. вертикальный для ленты коротких) ----------------------
  app.post('/videos/:id/clips', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    if (!req.settings['editor.enabled']) throw forbidden('Редактор видео отключён администратором');
    if (!canUpload(req.user, req.settings)) throw forbidden('Создание клипов доступно тем, кому разрешена загрузка видео');
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    const duration = Number(v.duration) || 0;
    const start = num(req.body?.start, 'start', { min: 0, max: duration });
    const end = num(req.body?.end, 'end', { min: 0, max: duration + 0.5 });
    if (end - start < 1) throw badRequest('Клип должен быть не короче 1 секунды');
    if (end - start > 3600) throw badRequest('Клип не может быть длиннее часа');
    const editable = canEditVideo(v, req.user);
    // Клип — это копия исходного материала: по временной ссылке-приглашению и в обход запрета
    // на скачивание его создавать нельзя, иначе ограничения автора обходятся одним запросом.
    if (!editable) {
      if (!(await canViewVideo(v, req.user, null))) throw forbidden('Клип можно создать только из видео, к которому у вас есть постоянный доступ');
      if (!v.allow_download) throw forbidden('Автор запретил скачивание этого видео — создание клипов из него недоступно');
    }
    await ensureNoActiveEdit(v.id);
    const visibility = ['public', 'internal', 'unlisted', 'private'].includes(req.body?.visibility) ? req.body.visibility : 'private';
    // Клип чужого видео не может быть доступнее исходника (public > internal > unlisted > private)
    const rank = { public: 3, internal: 2, unlisted: 1, private: 0 };
    if (!editable && rank[visibility] > rank[v.visibility]) throw forbidden('Клип не может быть доступнее исходного видео');
    const payload = { videoId: v.id, start, end, title: String(req.body?.title || '').slice(0, 150), description: req.body?.description, vertical: !!req.body?.vertical, visibility, byUserId: req.user.id, byRole: req.user.role };
    const j = await enqueue('clip_create', payload, { videoId: v.id, priority: 1, maxAttempts: 1, dedupe: false });
    await audit(req, 'video.clip', { targetType: 'video', targetId: v.id, details: { start, end, vertical: payload.vertical } });
    return { ok: true, jobId: Number(j.id) };
  });

  app.get('/videos/:id/clips', async (req) => {
    const v = await requireViewable(req, req.params.id);
    // видимые клипы + собственные клипы зрителя (в т. ч. приватные)
    const own = req.user ? `OR (v.owner_id = $2 AND v.deleted_at IS NULL AND v.status = 'ready')` : '';
    const rows = await many(`SELECT v.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar FROM videos v JOIN users u ON u.id = v.owner_id WHERE v.clip_of = $1 AND ((${listVisibilitySql(req.user)}) ${own}) ORDER BY v.created_at DESC LIMIT 50`, req.user ? [v.id, req.user.id] : [v.id]);
    return { clips: rows.map(videoCard) };
  });

  // --- Перевод субтитров через ИИ ---------------------------------------------------------------
  app.post('/videos/:id/subtitles/:sid/translate', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['subtitles.translate_enabled']) throw forbidden('Перевод субтитров отключён администратором');
    if (!req.settings['ai.enabled']) throw badRequest('Для перевода нужен ИИ-помощник (Настройки → ИИ-помощник)');
    const src = await one(`SELECT * FROM subtitles WHERE id = $1 AND video_id = $2 AND status = 'ready'`, [req.params.sid, v.id]);
    if (!src) throw notFound('Субтитры не найдены или ещё не готовы');
    const language = String(req.body?.language || '').toLowerCase().trim();
    if (!/^[a-z]{2,3}(-[a-z]{2,4})?$/.test(language)) throw badRequest('Укажите код языка (например, en)');
    if (language === src.language) throw badRequest('Язык перевода совпадает с языком источника');
    const existing = await one(`SELECT * FROM subtitles WHERE video_id = $1 AND language = $2 AND translated_from IS NOT NULL`, [v.id, language]);
    if (existing && existing.status === 'processing') throw badRequest('Перевод на этот язык уже выполняется');
    let target = existing;
    if (target) await query(`UPDATE subtitles SET status = 'processing', error = NULL, translated_from = $2 WHERE id = $1`, [target.id, src.id]);
    else target = await one(`INSERT INTO subtitles(video_id, language, label, kind, status, translated_from) VALUES ($1,$2,$3,'auto','processing',$4) RETURNING *`, [v.id, language, `${languageLabel(language)} (перевод)`, src.id]);
    const j = await enqueue('subtitle_translate', { videoId: v.id, subtitleId: src.id, targetSubtitleId: target.id, language, byUserId: req.user.id }, { videoId: v.id, priority: 0, maxAttempts: 2, dedupe: false });
    await audit(req, 'subtitles.translate', { targetType: 'video', targetId: v.id, details: { from: src.language, to: language } });
    return { ok: true, jobId: Number(j.id), subtitleId: target.id };
  });

  // --- Текст на экране (OCR) ------------------------------------------------------------------
  app.post('/videos/:id/ocr', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    if (!req.settings['ocr.enabled']) throw forbidden('Распознавание текста на экране отключено администратором');
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    const avail = await ocrAvailable();
    if (!avail.available) throw badRequest('На сервере не установлен tesseract');
    await query(`UPDATE videos SET ocr_status = 'queued' WHERE id = $1`, [v.id]);
    const j = await enqueue('ocr', { videoId: v.id, byUserId: req.user.id, intervalSec: req.body?.intervalSec ? num(req.body.intervalSec, 'intervalSec', { min: 2, max: 120 }) : undefined }, { videoId: v.id, priority: -1, maxAttempts: 1 });
    await audit(req, 'video.ocr', { targetType: 'video', targetId: v.id });
    return { ok: true, jobId: Number(j.id) };
  });

  app.get('/videos/:id/screen-text', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const items = await screenTextOf(v.id, req.query.q);
    return { status: v.ocr_status, at: v.ocr_at, items, total: items.length };
  });

  // --- Повтор чата для записи эфира --------------------------------------------------------------
  app.get('/videos/:id/chat-replay', async (req) => {
    const v = await requireViewable(req, req.params.id);
    if (!v.live_stream_id) return { messages: [], available: false };
    const stream = await one('SELECT id, started_at, ended_at, title FROM live_streams WHERE id = $1', [v.live_stream_id]);
    const startedAt = v.recording_started_at || stream?.started_at;
    if (!startedAt) return { messages: [], available: false };
    const from = new Date(startedAt);
    const to = new Date(from.getTime() + ((Number(v.duration) || 0) + 120) * 1000);
    const rows = await many(
      `SELECT m.id, m.body, m.created_at, m.is_deleted, u.display_name, u.handle, u.avatar_path, u.role FROM live_chat_messages m JOIN users u ON u.id = m.user_id
       WHERE m.stream_id = $1 AND m.created_at >= $2 AND m.created_at <= $3 AND NOT m.is_deleted ORDER BY m.id LIMIT 5000`,
      [v.live_stream_id, from, to],
    );
    return {
      available: true, startedAt: from,
      messages: rows.map((m) => ({ id: Number(m.id), offset: Math.max(0, Math.round((new Date(m.created_at).getTime() - from.getTime()) / 100) / 10), body: m.body, user: { displayName: m.display_name, handle: m.handle, avatarUrl: mediaUrl(m.avatar_path), role: m.role } })),
    };
  });
}
