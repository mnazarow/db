// Интеграция с внешней RAG-системой (базой знаний): документ из видео и его отправка/выдача.
import crypto from 'node:crypto';
import fsp from 'node:fs/promises';
import { one, many, query } from '../db.js';
import { storage, exists } from './storage.js';
import { config } from '../config.js';
import { vttToSegments } from './util.js';
import { safeFetch, responseHint, POLICY } from './net.js';

const fmt = (sec) => { const s = Math.max(0, Math.round(sec)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return (h ? `${h}:${String(m).padStart(2, '0')}` : `${m}`) + ':' + String(x).padStart(2, '0'); };

/** Транскрипт видео сегментами (субтитры по умолчанию) или текстом. */
async function transcriptOf(video) {
  const sub = await one(`SELECT * FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, (kind = 'manual') DESC, created_at LIMIT 1`, [video.id]);
  if (sub) {
    const abs = storage.abs(sub.path);
    if (await exists(abs)) { const segs = vttToSegments(await fsp.readFile(abs, 'utf8')); if (segs.length) return { segments: segs, language: sub.language }; }
  }
  return video.transcript ? { text: video.transcript } : null;
}

/** Документ для RAG из видео: метаданные + текст (описание, главы, транскрипт с таймкодами, вложения). */
export async function buildDocument(video, s) {
  const v = video.owner_name !== undefined ? video : await one('SELECT v.*, u.display_name AS owner_name, u.email AS owner_email, c.name AS category_name FROM videos v JOIN users u ON u.id = v.owner_id LEFT JOIN categories c ON c.id = v.category_id WHERE v.id = $1', [video.id]);
  const url = `${config.baseUrl}/watch/${v.short_id}`;
  const attachments = await many('SELECT id, title, filename, size FROM video_attachments WHERE video_id = $1 ORDER BY created_at', [v.id]);
  const tr = s['rag.include_transcript'] !== false ? await transcriptOf(v) : null;
  const ai = v.ai_suggestions || {};
  const description = (v.description || '').trim() || (ai.summary || '').trim();
  const chapters = Array.isArray(v.chapters) ? v.chapters : [];
  const lines = [`# ${v.title}`, '', `Видео на корпоративном портале: ${url}`, `Автор: ${v.owner_name}${v.category_name ? ` · Категория: ${v.category_name}` : ''}${v.tags?.length ? ` · Теги: ${v.tags.join(', ')}` : ''}`, `Длительность: ${fmt(Number(v.duration) || 0)} · Опубликовано: ${new Date(v.published_at || v.created_at).toLocaleDateString('ru-RU')}`, ''];
  if (description) lines.push('## Описание', '', description, '');
  if (chapters.length) { lines.push('## Главы', ''); for (const c of chapters) lines.push(`- ${fmt(c.start)} — ${c.title} (${url}?t=${Math.floor(c.start)})`); lines.push(''); }
  if (attachments.length) { lines.push('## Материалы', ''); for (const a of attachments) lines.push(`- ${a.title} (${a.filename}): ${config.baseUrl}/api/videos/${v.id}/attachments/${a.id}/download`); lines.push(''); }
  let transcriptText = '';
  if (tr?.segments) {
    lines.push('## Транскрипт', '');
    let lastMark = -61; let para = '';
    for (const c of tr.segments) {
      if (c.start - lastMark >= 60) { if (para) lines.push(para.trim(), ''); para = `[${fmt(c.start)}](${url}?t=${Math.floor(c.start)}) `; lastMark = c.start; }
      para += c.text + ' ';
    }
    if (para) lines.push(para.trim(), '');
    transcriptText = tr.segments.map((c) => c.text).join(' ');
  } else if (tr?.text) { lines.push('## Транскрипт', '', tr.text, ''); transcriptText = tr.text; }
  const markdown = lines.join('\n');
  const doc = {
    id: v.id, shortId: v.short_id, title: v.title, url, description, tags: v.tags || [], category: v.category_name || null, author: v.owner_name, language: v.language || tr?.language || 'ru',
    duration: Number(v.duration) || 0, publishedAt: v.published_at || v.created_at, updatedAt: v.updated_at, visibility: v.visibility,
    chapters: chapters.map((c) => ({ start: c.start, title: c.title, url: `${url}?t=${Math.floor(c.start)}` })),
    attachments: attachments.map((a) => ({ title: a.title, filename: a.filename, url: `${config.baseUrl}/api/videos/${v.id}/attachments/${a.id}/download` })),
    transcript: transcriptText, text: markdown, markdown,
  };
  doc.hash = crypto.createHash('sha256').update(JSON.stringify([doc.title, doc.description, doc.tags, doc.chapters, doc.attachments, doc.transcript])).digest('hex').slice(0, 32);
  return doc;
}

/** Отправить документ во внешнюю RAG-систему (json или multipart с markdown-файлом). */
export async function pushDocument(doc, s) {
  const url = s['rag.url'];
  if (!url) throw new Error('Не задан адрес RAG');
  const headers = {};
  if (s['rag.api_key']) headers[String(s['rag.auth_header'] || 'Authorization')] = /^authorization$/i.test(s['rag.auth_header'] || 'Authorization') ? `Bearer ${s['rag.api_key']}` : s['rag.api_key'];
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 60000);
  try {
    let res;
    if ((s['rag.format'] || 'json') === 'markdown') {
      const form = new FormData();
      form.append('file', new Blob([doc.markdown], { type: 'text/markdown' }), `${doc.shortId}.md`);
      form.append('id', doc.id); form.append('title', doc.title); form.append('url', doc.url);
      form.append('metadata', JSON.stringify({ id: doc.id, title: doc.title, url: doc.url, tags: doc.tags, category: doc.category, author: doc.author, publishedAt: doc.publishedAt, duration: doc.duration, source: 'corpvideo' }));
      res = await safeFetch(url, { method: 'POST', headers, body: form, signal: ctrl.signal }, POLICY.integration);
    } else {
      const body = { id: doc.id, title: doc.title, url: doc.url, text: doc.markdown, source: 'corpvideo', metadata: { shortId: doc.shortId, tags: doc.tags, category: doc.category, author: doc.author, language: doc.language, duration: doc.duration, publishedAt: doc.publishedAt, updatedAt: doc.updatedAt, visibility: doc.visibility, chapters: doc.chapters, attachments: doc.attachments, hash: doc.hash } };
      res = await safeFetch(url, { method: 'POST', headers: { ...headers, 'content-type': 'application/json' }, body: JSON.stringify(body), signal: ctrl.signal }, POLICY.integration);
    }
    const hint = responseHint(res, await res.text().catch(() => ''));
    if (!res.ok) throw new Error(`RAG ответил ${res.status}${hint ? `: ${hint}` : ''}`);
    return { status: res.status };
  } finally { clearTimeout(t); }
}

/** Какие видео подлежат выгрузке (по настройке минимальной видимости). */
export function ragVisibilitySql(s, alias = 'v') {
  const min = s['rag.min_visibility'] || 'internal';
  if (min === 'public') return `${alias}.visibility = 'public'`;
  if (min === 'all') return 'true';
  return `${alias}.visibility IN ('public','internal')`;
}

export async function ragStatus(s) {
  const vis = ragVisibilitySql(s);
  const r = await one(`SELECT count(*)::int AS total, count(*) FILTER (WHERE rag_synced_at IS NOT NULL)::int AS synced, count(*) FILTER (WHERE rag_error IS NOT NULL)::int AS errors,
      count(*) FILTER (WHERE (description IS NULL OR description = '') AND (ai_suggestions->>'summary') IS NULL)::int AS undescribed, count(*) FILTER (WHERE transcript IS NOT NULL AND transcript <> '')::int AS with_transcript
    FROM videos v WHERE v.deleted_at IS NULL AND v.status = 'ready' AND v.moderation_status = 'approved' AND ${vis}`);
  const queued = await one(`SELECT count(*)::int AS n FROM jobs WHERE type = 'rag_push' AND status IN ('queued','running')`);
  const describing = await one(`SELECT count(*)::int AS n FROM jobs WHERE type = 'ai_enrich' AND status IN ('queued','running')`);
  return { total: r.total, synced: r.synced, errors: r.errors, undescribed: r.undescribed, withTranscript: r.with_transcript, queued: queued.n, describing: describing.n };
}

/** Проверка из панели: пробный документ. */
export async function ragTest(s) {
  const doc = { id: '00000000-0000-0000-0000-000000000000', shortId: 'test', title: 'Проверка связи с CorpVideo', url: `${config.baseUrl}/`, description: 'Тестовый документ', tags: ['test'], category: null, author: 'CorpVideo', language: 'ru', duration: 0, publishedAt: new Date().toISOString(), updatedAt: new Date().toISOString(), visibility: 'internal', chapters: [], attachments: [], transcript: '', markdown: '# Проверка связи с CorpVideo\n\nТестовый документ.', hash: 'test' };
  return pushDocument(doc, s);
}
