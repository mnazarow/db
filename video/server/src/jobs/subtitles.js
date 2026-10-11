// Задание: автоматические субтитры через сервер распознавания речи.
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, query, publish } from '../db.js';
import { storage, ensureDir, exists, removeFile } from '../lib/storage.js';
import { extractAudio } from '../lib/ffmpeg.js';
import { transcribe } from '../lib/asr.js';
import { loadSettings } from '../lib/settings.js';
import { notify } from '../lib/notify.js';
import { vttToText } from '../lib/util.js';
import { enqueue } from '../lib/jobs.js';

const LANG_LABELS = { ru: 'Русский', en: 'English', de: 'Deutsch', fr: 'Français', es: 'Español', zh: '中文', uz: 'Oʻzbek', kk: 'Қазақ' };

export async function runSubtitlesAsr(job, ctx) {
  const { log, signal, heartbeat } = ctx;
  const s = await loadSettings(true);
  if (!s['asr.enabled']) throw Object.assign(new Error('ASR отключён в настройках'), { noRetry: true });
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  if (s['asr.max_duration_min'] && Number(video.duration) > s['asr.max_duration_min'] * 60) {
    throw Object.assign(new Error(`Видео длиннее лимита ASR (${s['asr.max_duration_min']} мин)`), { noRetry: true });
  }
  const language = job.payload.language || s['asr.language'] || 'ru';

  // Запись субтитров со статусом processing
  const autoLabel = `${LANG_LABELS[language] || language} (авто)`;
  let sub = job.payload.subtitleId ? await one('SELECT * FROM subtitles WHERE id = $1', [job.payload.subtitleId]) : null;
  // Переиспользуем прежнюю автодорожку этого языка (в т. ч. помеченную «(устарели)» после замены файла),
  // иначе в плеере появлялись бы две одинаковые дорожки
  if (!sub) sub = await one(`SELECT * FROM subtitles WHERE video_id = $1 AND language = $2 AND kind = 'auto' AND translated_from IS NULL ORDER BY created_at LIMIT 1`, [video.id, language]);
  if (!sub) {
    sub = await one(
      `INSERT INTO subtitles(video_id, language, label, kind, status) VALUES ($1,$2,$3,'auto','processing') RETURNING *`,
      [video.id, language, autoLabel],
    );
  } else {
    await query(`UPDATE subtitles SET status = 'processing', error = NULL, label = $2 WHERE id = $1`, [sub.id, autoLabel]);
  }
  if (!sub) throw new Error('Не удалось создать запись субтитров');
  await publish({ type: 'video.subtitles', videoId: video.id, ownerId: video.owner_id, status: 'processing' });

  // Источник аудио: оригинал → mp4 → HLS
  let src = null;
  if (video.original_path && (await exists(storage.abs(video.original_path)))) src = storage.abs(video.original_path);
  else if (video.mp4_path && (await exists(storage.abs(video.mp4_path)))) src = storage.abs(video.mp4_path);
  else if (video.hls_path) {
    const rend = await one('SELECT path FROM video_renditions WHERE video_id = $1 ORDER BY height ASC LIMIT 1', [video.id]);
    src = storage.abs(rend?.path || video.hls_path);
  }
  if (!src) throw Object.assign(new Error('Нет источника аудио'), { noRetry: true });

  const tmpAudio = path.join(storage.videoDir(video.id), 'asr_audio.mp3');
  try {
    await heartbeat(10, 'audio');
    await extractAudio(src, tmpAudio, { signal });
    await heartbeat(30, 'asr');
    log.info({ videoId: video.id }, 'отправка на ASR');
    const { vtt, text, noTimestamps } = await transcribe(tmpAudio, { signal, settings: s });
    await heartbeat(90, 'save');
    const subsDir = storage.subsDir(video.id);
    await ensureDir(subsDir);
    const file = path.join(subsDir, `${sub.id}.vtt`);
    await fsp.writeFile(file, vtt);
    const hasManualDefault = await one(`SELECT 1 FROM subtitles WHERE video_id = $1 AND is_default AND id <> $2`, [video.id, sub.id]);
    await query(`UPDATE subtitles SET status = 'ready', path = $2, is_default = $3, error = NULL WHERE id = $1`, [sub.id, storage.rel(file), !hasManualDefault]);
    await query('UPDATE videos SET transcript = $2 WHERE id = $1', [video.id, (text || vttToText(vtt)).slice(0, 500000)]);
    await publish({ type: 'video.subtitles', videoId: video.id, ownerId: video.owner_id, status: 'ready' });
    await notify(video.owner_id, { type: 'subtitles_ready', title: 'Субтитры готовы', body: video.title, link: `/studio/videos/${video.id}/subtitles`, data: { videoId: video.id } });
    if (s['ai.enabled'] && s['ai.auto_after_subtitles']) await enqueue('ai_enrich', { videoId: video.id }, { videoId: video.id, priority: -1, maxAttempts: 2 });
    else if (s['rag.enabled'] && s['rag.auto'] && s['rag.url']) await enqueue('rag_push', { videoId: video.id }, { videoId: video.id, priority: -1 });
    return { chars: text?.length || 0, noTimestamps: !!noTimestamps };
  } catch (e) {
    await query(`UPDATE subtitles SET status = 'failed', error = $2 WHERE id = $1`, [sub.id, String(e.message).slice(0, 1000)]);
    await publish({ type: 'video.subtitles', videoId: video.id, ownerId: video.owner_id, status: 'failed', error: e.message });
    throw e;
  } finally {
    await removeFile(tmpAudio);
  }
}
