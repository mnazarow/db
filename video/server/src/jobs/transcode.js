// Задание: обработка загруженного видео (анализ → HLS → миниатюры → раскадровка → mp4 → публикация).
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, query, many, publish, tx } from '../db.js';
import { storage, ensureDir, dirSize, removeDir, exists, removeFile } from '../lib/storage.js';
import { probe, transcodeHls, makeThumbnails, makeStoryboard, makeMp4, runFfmpeg } from '../lib/ffmpeg.js';
import { loadSettings } from '../lib/settings.js';
import { enqueue } from '../lib/jobs.js';
import { notify, notifyAdmins, notifySubscribersNewVideo, notifPrefs } from '../lib/notify.js';
import { templates } from '../lib/mailer.js';
import { config } from '../config.js';
import { emitEvent, eventVideo } from '../lib/events.js';

async function setProgress(video, progress, stage, log) {
  await query('UPDATE videos SET processing_progress = $2, processing_stage = $3 WHERE id = $1', [video.id, progress, stage]);
  await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress, stage, status: 'processing' });
  if (log) log.info({ videoId: video.id, progress, stage }, 'progress');
}

export async function runTranscode(job, ctx) {
  const { log, signal, heartbeat } = ctx;
  const s = await loadSettings(true);
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const input = video.original_path ? storage.abs(video.original_path) : null;
  if (!input || !(await exists(input))) throw Object.assign(new Error('Исходный файл отсутствует'), { noRetry: true });

  const dir = storage.videoDir(video.id);
  const hlsDir = storage.hlsDir(video.id);
  const thumbsDir = storage.thumbsDir(video.id);
  const sbDir = storage.storyboardDir(video.id);

  await query(`UPDATE videos SET status = 'processing', processing_error = NULL, processing_progress = 0 WHERE id = $1`, [video.id]);
  await setProgress(video, 1, 'probe', log);

  // 1. Анализ
  const meta = await probe(input);
  if (!meta.duration || meta.duration < 0.2) throw Object.assign(new Error('Не удалось определить длительность видео'), { noRetry: true });
  const isShort = meta.duration <= 60 && meta.height > meta.width;
  await query(
    `UPDATE videos SET duration = $2, width = $3, height = $4, fps = $5, bitrate = $6, codec = $7, is_short = $8 WHERE id = $1`,
    [video.id, meta.duration, meta.width, meta.height, meta.fps, meta.bitrate, meta.codec, isShort],
  );

  // 2. HLS-лестница (перезапись при повторной обработке)
  await removeDir(hlsDir).catch(() => {});
  await ensureDir(hlsDir);
  let lastBeat = 0;
  const { renditions } = await transcodeHls(input, hlsDir, meta, {
    qualities: s['transcode.qualities'],
    preset: s['transcode.preset'],
    crf: s['transcode.crf'],
    segmentSeconds: s['transcode.segment_seconds'],
    hwaccel: s['transcode.hwaccel'],
    audioBitrate: s['transcode.audio_bitrate'],
    signal,
    onProgress: (pct) => {
      const p = 3 + Math.round(pct * 0.72);
      if (Date.now() - lastBeat > 1500) {
        lastBeat = Date.now();
        setProgress(video, p, 'hls').catch(() => {});
        heartbeat(p, 'hls').catch(() => {});
      }
    },
  });
  // Удаление старых вариантов, вставка новых и путь к плейлисту — неделимо:
  // воркер, убитый между DELETE и INSERT, оставлял видео без лестницы качеств
  await tx(async (c) => {
    await c.query('DELETE FROM video_renditions WHERE video_id = $1', [video.id]);
    for (const r of renditions) {
      await c.query(
        'INSERT INTO video_renditions(video_id, label, width, height, bandwidth, path, bytes) VALUES ($1,$2,$3,$4,$5,$6,$7)',
        [video.id, r.label, r.width, r.height, r.bandwidth, `videos/${video.id}/hls/${r.path}`, r.bytes],
      );
    }
    await c.query('UPDATE videos SET hls_path = $2 WHERE id = $1', [video.id, `videos/${video.id}/hls/master.m3u8`]);
  });
  await setProgress(video, 78, 'thumbnails', log);

  // 3. Миниатюры-кандидаты (пользовательская миниатюра, если есть, сохраняется)
  const autoFiles = await makeThumbnails(input, meta.duration, thumbsDir);
  const candidates = autoFiles.map((f) => storage.rel(f));
  const fresh = await one('SELECT thumbnail_path FROM videos WHERE id = $1', [video.id]);
  const hasCustom = fresh?.thumbnail_path && /custom/.test(fresh.thumbnail_path);
  const poster = hasCustom ? fresh.thumbnail_path : (candidates[1] || candidates[0] || null);
  await query('UPDATE videos SET thumbnail_candidates = $2::jsonb, thumbnail_path = $3 WHERE id = $1', [video.id, JSON.stringify(candidates), poster]);
  await heartbeat(80, 'thumbnails');

  // 4. Раскадровка для превью на таймлайне
  if (s['transcode.storyboard']) {
    await setProgress(video, 82, 'storyboard', log);
    try {
      await removeDir(sbDir).catch(() => {});
      const sb = await makeStoryboard(input, meta.duration, sbDir);
      await query('UPDATE videos SET storyboard_path = $2, storyboard_meta = $3::jsonb WHERE id = $1', [video.id, `videos/${video.id}/storyboard/storyboard.vtt`, JSON.stringify(sb)]);
    } catch (e) {
      log.warn({ err: e.message }, 'storyboard failed');
    }
  }
  await heartbeat(86, 'storyboard');

  // 5. Прогрессивный mp4 (скачивание/фолбэк)
  if (s['transcode.mp4_fallback']) {
    await setProgress(video, 88, 'mp4', log);
    const mp4 = path.join(dir, 'video.mp4');
    try {
      await makeMp4(input, mp4, meta, {
        maxHeight: s['transcode.mp4_max_height'], preset: s['transcode.preset'], crf: s['transcode.crf'], signal,
        onProgress: (pct) => { const p = 88 + Math.round(pct * 0.1); if (Date.now() - lastBeat > 1500) { lastBeat = Date.now(); setProgress(video, p, 'mp4').catch(() => {}); heartbeat(p, 'mp4').catch(() => {}); } },
      });
      await query('UPDATE videos SET mp4_path = $2 WHERE id = $1', [video.id, `videos/${video.id}/video.mp4`]);
    } catch (e) {
      log.warn({ err: e.message }, 'mp4 fallback failed');
      await removeFile(mp4);
    }
  }

  // 5b. Аудиодорожка (режим «только звук», подкаст-ленты) — 1.3
  if (s['feeds.audio'] && meta.hasAudio) {
    try {
      const audioOut = path.join(dir, 'audio.m4a');
      await runFfmpeg(['-i', input, '-vn', '-c:a', 'aac', '-b:a', '96k', '-ac', '2', '-movflags', '+faststart', audioOut], { signal, label: 'audio-track' });
      await query('UPDATE videos SET audio_path = $2 WHERE id = $1', [video.id, `videos/${video.id}/audio.m4a`]);
    } catch (e) { log.warn({ err: e.message }, 'audio track failed'); }
  }

  // 6. Автосубтитры — до возможного удаления оригинала
  const current = await one('SELECT * FROM videos WHERE id = $1', [video.id]);
  if (s['asr.enabled'] && s['asr.auto_generate'] && !current.is_live_recording) {
    // Субтитры, помеченные «(устарели)» при замене файла, не считаем готовыми — иначе после замены
    // или монтажа расшифровка навсегда осталась бы от предыдущей версии видео
    const hasAuto = await one(`SELECT 1 FROM subtitles WHERE video_id = $1 AND kind = 'auto' AND label NOT LIKE '%(устарели)%'`, [video.id]);
    if (!hasAuto) await enqueue('subtitles_asr', { videoId: video.id, language: s['asr.language'] }, { videoId: video.id, priority: -1 });
  }

  // 7. Оригинал: удалить, если не требуется хранить
  const keepOriginal = current.original_kept && s['upload.keep_original'];
  if (!keepOriginal) {
    // Оригинал нужен для ASR: удалим только если ASR не в очереди
    const pendingAsr = await one(`SELECT 1 FROM jobs WHERE video_id = $1 AND type = 'subtitles_asr' AND status IN ('queued','running')`, [video.id]);
    if (!pendingAsr) {
      await removeFile(input);
      await query('UPDATE videos SET original_kept = false WHERE id = $1', [video.id]);
    }
  }

  // 8. Итоги: размер, статус, публикация
  const bytes = await dirSize(dir);
  const scheduledFuture = current.scheduled_at && new Date(current.scheduled_at) > new Date();
  const wasReadyBefore = video.published_at != null;
  await query(
    `UPDATE videos SET status = 'ready', processing_progress = 100, processing_stage = NULL, storage_bytes = $2,
       published_at = CASE WHEN published_at IS NULL AND $3 = false THEN now() ELSE published_at END WHERE id = $1`,
    [video.id, bytes, !!scheduledFuture],
  );
  if (!wasReadyBefore) {
    await query('UPDATE users SET video_count = (SELECT count(*) FROM videos WHERE owner_id = $1 AND deleted_at IS NULL AND status = $2) WHERE id = $1', [video.owner_id, 'ready']);
    if (current.category_id) await query('UPDATE categories SET video_count = (SELECT count(*) FROM videos WHERE category_id = $1 AND deleted_at IS NULL) WHERE id = $1', [current.category_id]);
  }
  const done = await one('SELECT v.*, u.display_name AS owner_name, u.email AS owner_email, u.prefs AS owner_prefs FROM videos v JOIN users u ON u.id = v.owner_id WHERE v.id = $1', [video.id]);
  await publish({ type: 'video.ready', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, status: 'ready', progress: 100 });

  const link = `/watch/${done.short_id}`;
  const ownerPrefs = notifPrefs({ prefs: done.owner_prefs });
  await notify(done.owner_id, {
    type: 'video_ready', title: 'Видео обработано', body: done.title, link, image: done.thumbnail_path ? `/media/${done.thumbnail_path}` : null, data: { videoId: done.id },
  }, { email: ownerPrefs.email ? () => templates.videoReady({ to: done.owner_email, videoTitle: done.title, url: config.baseUrl + link }) : null });

  if (done.moderation_status === 'pending') {
    await notifyAdmins({ type: 'moderation', title: 'Видео ожидает проверки', body: `${done.owner_name}: ${done.title}`, link: `/admin/moderation`, actorId: done.owner_id, data: { videoId: done.id } }, { moderators: true });
  } else if (!wasReadyBefore && !scheduledFuture && ['public', 'internal'].includes(done.visibility)) {
    await notifySubscribersNewVideo(done, { id: done.owner_id, display_name: done.owner_name });
  }
  if (s['rag.enabled'] && s['rag.auto'] && s['rag.url'] && done.moderation_status === 'approved' && !scheduledFuture) await enqueue('rag_push', { videoId: video.id }, { videoId: video.id, priority: -1 }).catch(() => {});
  if (s['ocr.enabled'] && s['ocr.auto'] && !current.is_live_recording) await enqueue('ocr', { videoId: video.id }, { videoId: video.id, priority: -2 }).catch(() => {});
  await emitEvent('video.ready', { video: eventVideo(done), replaced: wasReadyBefore, scheduled: !!scheduledFuture });
  return { renditions: renditions.map((r) => r.label), bytes };
}

/** Повторная генерация миниатюр (без транскодирования). */
export async function runThumbnails(job, ctx) {
  const video = await one('SELECT * FROM videos WHERE id = $1', [job.video_id]);
  if (!video) return null;
  const src = video.original_path && (await exists(storage.abs(video.original_path))) ? storage.abs(video.original_path)
    : video.mp4_path ? storage.abs(video.mp4_path) : storage.abs(video.hls_path);
  const files = await makeThumbnails(src, Number(video.duration), storage.thumbsDir(video.id));
  const candidates = files.map((f) => storage.rel(f));
  await query('UPDATE videos SET thumbnail_candidates = $2::jsonb, thumbnail_path = COALESCE(thumbnail_path, $3) WHERE id = $1', [video.id, JSON.stringify(candidates), candidates[1] || candidates[0] || null]);
  return { candidates };
}

/** Пересчёт занимаемого места по всем видео. */
export async function recomputeStorage() {
  const rows = await many(`SELECT id FROM videos WHERE deleted_at IS NULL`);
  for (const r of rows) {
    const bytes = await dirSize(storage.videoDir(r.id));
    await query('UPDATE videos SET storage_bytes = $2 WHERE id = $1', [r.id, bytes]);
  }
  return rows.length;
}
