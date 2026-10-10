// Задание: перевод субтитров на другой язык через ИИ-помощник (как автоперевод субтитров YouTube).
// Реплики переводятся пакетами с сохранением таймкодов и порядка.
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, query, publish } from '../db.js';
import { storage, ensureDir, exists } from '../lib/storage.js';
import { loadSettings } from '../lib/settings.js';
import { notify } from '../lib/notify.js';
import { vttToSegments, segmentsToVtt } from '../lib/util.js';
import { translateLines } from '../lib/ai.js';

export const LANGUAGE_NAMES = {
  ru: 'Русский', en: 'English', de: 'Deutsch', fr: 'Français', es: 'Español', it: 'Italiano', pt: 'Português', pl: 'Polski', tr: 'Türkçe', ar: 'العربية',
  zh: '中文', ja: '日本語', ko: '한국어', kk: 'Қазақ', uz: 'Oʻzbek', ky: 'Кыргызча', tg: 'Тоҷикӣ', hy: 'Հայերեն', az: 'Azərbaycan', ka: 'ქართული', be: 'Беларуская', uk: 'Українська', vi: 'Tiếng Việt', hi: 'हिन्दी',
};
const LANG_EN = { ru: 'Russian', en: 'English', de: 'German', fr: 'French', es: 'Spanish', it: 'Italian', pt: 'Portuguese', pl: 'Polish', tr: 'Turkish', ar: 'Arabic', zh: 'Chinese (Simplified)', ja: 'Japanese', ko: 'Korean', kk: 'Kazakh', uz: 'Uzbek', ky: 'Kyrgyz', tg: 'Tajik', hy: 'Armenian', az: 'Azerbaijani', ka: 'Georgian', be: 'Belarusian', uk: 'Ukrainian', vi: 'Vietnamese', hi: 'Hindi' };

export function languageLabel(code) { return LANGUAGE_NAMES[code] || code; }

/** payload: {videoId, subtitleId (источник), language, targetSubtitleId, byUserId} */
export async function runSubtitleTranslate(job, ctx) {
  const { heartbeat, signal, log } = ctx;
  const s = await loadSettings(true);
  if (!s['ai.enabled']) throw Object.assign(new Error('ИИ-помощник отключён в настройках'), { noRetry: true });
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const src = await one('SELECT * FROM subtitles WHERE id = $1 AND video_id = $2', [job.payload.subtitleId, video.id]);
  if (!src || !src.path || !(await exists(storage.abs(src.path)))) throw Object.assign(new Error('Исходные субтитры не найдены'), { noRetry: true });
  const language = String(job.payload.language || '').toLowerCase().slice(0, 8);
  if (!language) throw Object.assign(new Error('Не указан язык перевода'), { noRetry: true });
  const target = await one('SELECT * FROM subtitles WHERE id = $1', [job.payload.targetSubtitleId]);
  if (!target) throw Object.assign(new Error('Запись субтитров перевода не найдена'), { noRetry: true });
  await query(`UPDATE subtitles SET status = 'processing', error = NULL WHERE id = $1`, [target.id]);
  await publish({ type: 'video.subtitles', videoId: video.id, ownerId: video.owner_id, status: 'processing' });
  try {
    const segments = vttToSegments(await fsp.readFile(storage.abs(src.path), 'utf8'));
    if (!segments.length) throw Object.assign(new Error('В субтитрах нет реплик'), { noRetry: true });
    const batch = 40;
    const translated = [];
    for (let i = 0; i < segments.length; i += batch) {
      if (signal?.aborted) throw new Error('Задание отменено');
      const lines = segments.slice(i, i + batch).map((c) => c.text);
      const out = await translateLines(lines, { from: src.language, to: language, toName: LANG_EN[language] || language, context: video.title }, s);
      for (let k = 0; k < lines.length; k++) translated.push({ ...segments[i + k], text: out[k] || lines[k] });
      await heartbeat(Math.round(((i + lines.length) / segments.length) * 95), 'translate');
      log?.info({ videoId: video.id, done: i + lines.length, total: segments.length }, 'перевод субтитров');
    }
    const subsDir = storage.subsDir(video.id);
    await ensureDir(subsDir);
    const file = path.join(subsDir, `${target.id}.vtt`);
    await fsp.writeFile(file, segmentsToVtt(translated));
    await query(`UPDATE subtitles SET status = 'ready', path = $2, error = NULL WHERE id = $1`, [target.id, storage.rel(file)]);
    await publish({ type: 'video.subtitles', videoId: video.id, ownerId: video.owner_id, status: 'ready' });
    if (job.payload.byUserId) await notify(job.payload.byUserId, { type: 'subtitles_ready', title: `Субтитры переведены: ${languageLabel(language)}`, body: video.title, link: `/studio/videos/${video.id}/subtitles`, data: { videoId: video.id } });
    return { cues: translated.length, language };
  } catch (e) {
    await query(`UPDATE subtitles SET status = 'failed', error = $2 WHERE id = $1`, [target.id, String(e.message).slice(0, 1000)]);
    await publish({ type: 'video.subtitles', videoId: video.id, ownerId: video.owner_id, status: 'failed', error: e.message });
    throw e;
  }
}
