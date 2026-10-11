// Задание: ИИ-подсказки по транскрипту (название, конспект, главы, теги) — сохраняются как предложения автору.
import fsp from 'node:fs/promises';
import { one, query, publish } from '../db.js';
import { storage, exists } from '../lib/storage.js';
import { loadSettings } from '../lib/settings.js';
import { enrichVideo } from '../lib/ai.js';
import { notify } from '../lib/notify.js';
import { vttToSegments } from '../lib/util.js';
import { enqueue } from '../lib/jobs.js';

/** Транскрипт видео: сегменты из субтитров по умолчанию (или любых готовых), иначе текст из videos.transcript. */
export async function videoTranscript(video) {
  const sub = await one(`SELECT * FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, (kind = 'manual') DESC, created_at LIMIT 1`, [video.id]);
  if (sub) {
    const abs = storage.abs(sub.path);
    if (await exists(abs)) {
      const segs = vttToSegments(await fsp.readFile(abs, 'utf8'));
      if (segs.length) return { segments: segs, language: sub.language };
    }
  }
  if (video.transcript) return { text: video.transcript };
  return null;
}

export async function runAiEnrich(job, ctx) {
  const { log, heartbeat } = ctx;
  const s = await loadSettings(true);
  if (!s['ai.enabled']) throw Object.assign(new Error('ИИ отключён в настройках'), { noRetry: true });
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const fields = Array.isArray(job.payload.fields) && job.payload.fields.length ? job.payload.fields : ['title', 'summary', 'chapters', 'tags'];
  const tr = await videoTranscript(video);
  if (!tr) throw Object.assign(new Error('Нет транскрипта: сначала добавьте субтитры или включите автосубтитры'), { noRetry: true });
  await heartbeat(10, 'ai');
  await publish({ type: 'video.ai', videoId: video.id, ownerId: video.owner_id, status: 'processing' });
  let result;
  try {
    result = await enrichVideo({ title: video.title, transcript: tr.segments || tr.text, duration: Number(video.duration) || 0, fields }, s);
  } catch (e) {
    await publish({ type: 'video.ai', videoId: video.id, ownerId: video.owner_id, status: 'failed', error: e.message });
    const noRetry = /не JSON|Нет транскрипта|отключён/i.test(e.message);
    if (noRetry || job.attempts >= job.max_attempts) {
      await query(`UPDATE videos SET ai_suggestions = $2::jsonb WHERE id = $1`, [video.id, JSON.stringify({ error: String(e.message).slice(0, 500), generatedAt: new Date().toISOString() })]);
    }
    throw noRetry ? Object.assign(e, { noRetry: true }) : e;
  }
  const suggestions = { ...(video.ai_suggestions || {}), ...result, generatedAt: new Date().toISOString(), model: s['ai.model'], fields };
  await query('UPDATE videos SET ai_suggestions = $2::jsonb WHERE id = $1', [video.id, JSON.stringify(suggestions)]);
  // Автоописание: пустое описание заполняется конспектом, пустые теги — тегами (только если включено администратором)
  if (s['ai.auto_describe']) {
    if (result.summary) await query(`UPDATE videos SET description = $2 WHERE id = $1 AND (description IS NULL OR btrim(description) = '')`, [video.id, result.summary]);
    if (result.tags?.length) await query(`UPDATE videos SET tags = $2 WHERE id = $1 AND (tags IS NULL OR array_length(tags, 1) IS NULL)`, [video.id, result.tags]);
  }
  // Выгрузка обновлённого документа в RAG
  if (s['rag.enabled'] && s['rag.auto'] && s['rag.url']) await enqueue('rag_push', { videoId: video.id }, { videoId: video.id, priority: -1, maxAttempts: 3 }).catch(() => {});
  await publish({ type: 'video.ai', videoId: video.id, ownerId: video.owner_id, status: 'ready' });
  const to = job.payload.userId || video.owner_id;
  await notify(to, { type: 'ai_ready', title: 'ИИ подготовил подсказки по видео', body: video.title, link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
  log.info({ videoId: video.id, fields: Object.keys(result) }, 'ИИ-подсказки сохранены');
  return { fields: Object.keys(result) };
}
