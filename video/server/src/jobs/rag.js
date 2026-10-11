// Задание: выгрузка документа видео во внешнюю RAG-систему (с опциональным описанием через ИИ).
import { one, query } from '../db.js';
import { loadSettings } from '../lib/settings.js';
import { buildDocument, pushDocument } from '../lib/rag.js';
import { enrichVideo } from '../lib/ai.js';

export async function runRagPush(job, ctx) {
  const s = await loadSettings(true);
  if (!s['rag.enabled'] || !s['rag.url']) throw Object.assign(new Error('Интеграция с RAG отключена'), { noRetry: true });
  const video = await one('SELECT v.*, u.display_name AS owner_name, u.email AS owner_email, c.name AS category_name FROM videos v JOIN users u ON u.id = v.owner_id LEFT JOIN categories c ON c.id = v.category_id WHERE v.id = $1 AND v.deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  // Описание через стороннюю LLM, если у видео нет описания и включена опция
  if (s['rag.describe_with_ai'] && s['ai.enabled'] && !(video.description || '').trim() && !video.ai_suggestions?.summary) {
    try {
      const { videoTranscript } = await import('./ai.js');
      const tr = await videoTranscript(video);
      if (tr) {
        const r = await enrichVideo({ title: video.title, transcript: tr.segments || tr.text, duration: Number(video.duration) || 0, fields: ['summary', 'tags'] }, s);
        const merged = { ...(video.ai_suggestions || {}), ...r, generatedAt: new Date().toISOString(), model: s['ai.model'] };
        await query('UPDATE videos SET ai_suggestions = $2::jsonb WHERE id = $1', [video.id, JSON.stringify(merged)]);
        video.ai_suggestions = merged;
        if (s['ai.auto_describe'] && r.summary) { await query(`UPDATE videos SET description = $2 WHERE id = $1 AND (description IS NULL OR description = '')`, [video.id, r.summary]); video.description = r.summary; }
      }
    } catch (e) { ctx.log.warn({ err: e.message }, 'ИИ-описание для RAG не получено'); }
  }
  const doc = await buildDocument(video, s);
  if (!job.payload.force && video.rag_hash === doc.hash && video.rag_synced_at) return { skipped: 'unchanged' };
  try {
    const r = await pushDocument(doc, s);
    await query('UPDATE videos SET rag_synced_at = now(), rag_hash = $2, rag_error = NULL WHERE id = $1', [video.id, doc.hash]);
    return { status: r.status, hash: doc.hash };
  } catch (e) {
    await query('UPDATE videos SET rag_error = $2 WHERE id = $1', [video.id, String(e.message).slice(0, 500)]);
    throw e;
  }
}
