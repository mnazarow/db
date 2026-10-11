// «Спросите видеотеку»: ответ на вопрос по расшифровкам речи и тексту с экрана
// с ссылками на конкретные моменты видео (как ИИ-поиск в Microsoft Stream и Vimeo RAG).
import fsp from 'node:fs/promises';
import { one, many, query } from '../db.js';
import { listVisibilitySql } from './access.js';
import { loadSettings } from './settings.js';
import { storage } from './storage.js';
import { vttToSegments, likeEscape } from './util.js';
import { askAi } from './ai.js';
import { mediaUrl } from './urls.js';

const MAX_VIDEOS = 6;
const MAX_FRAGMENTS = 18;

/** Найти подходящие фрагменты: реплики субтитров и текст с экрана у видео, доступных пользователю. */
export async function findFragments(question, user, { limit = MAX_VIDEOS } = {}) {
  const q = String(question || '').trim().slice(0, 300);
  if (q.length < 3) return [];
  // Вопрос на естественном языке нельзя искать как «все слова сразу» (websearch_to_tsquery соединяет
  // их через И) — строим запрос «любое из значимых слов» и ранжируем по числу совпадений.
  const lexemes = q.toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, ' ').split(/\s+/)
    .map((w) => w.replace(/[^\p{L}\p{N}-]/gu, '')).filter((w) => w.length >= 3).slice(0, 12);
  const orQuery = lexemes.join(' | ');
  const rows = orQuery
    ? await many(
      `SELECT v.id, v.short_id, v.title, v.duration, v.thumbnail_path,
              ts_rank_cd(v.search_vector, to_tsquery('russian', $2)) AS rank
       FROM videos v WHERE ${listVisibilitySql(user)}
         AND (v.search_vector @@ to_tsquery('russian', $2) OR v.title ILIKE '%' || cv_like($1) || '%')
       ORDER BY rank DESC, v.view_count DESC LIMIT $3`,
      [q, orQuery, limit],
    )
    : await many(
      `SELECT v.id, v.short_id, v.title, v.duration, v.thumbnail_path, 0 AS rank FROM videos v
       WHERE ${listVisibilitySql(user)} AND v.title ILIKE '%' || cv_like($1) || '%' ORDER BY v.view_count DESC LIMIT $2`,
      [q, limit],
    );
  const words = lexemes.filter((w) => w.length >= 4);
  const out = [];
  for (const v of rows) {
    const found = [];
    // 1. Текст с экрана — время известно точно
    const screens = await many(
      `SELECT t, text FROM video_screen_text WHERE video_id = $1 AND (text ILIKE $2 ${words.map((_, i) => `OR text ILIKE $${i + 3}`).join(' ')}) ORDER BY t LIMIT 3`,
      [v.id, `%${likeEscape(q)}%`, ...words.map((w) => `%${likeEscape(w)}%`)],
    );
    for (const sc of screens) found.push({ at: Number(sc.t), text: String(sc.text).replace(/\s+/g, ' ').slice(0, 400), kind: 'screen' });
    // 2. Реплики субтитров
    const sub = await one(`SELECT path FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY (kind = 'manual') DESC, created_at LIMIT 1`, [v.id]);
    if (sub?.path) {
      try {
        const cues = vttToSegments(await fsp.readFile(storage.abs(sub.path), 'utf8'));
        const score = (text) => {
          const t = text.toLowerCase();
          let n = t.includes(q.toLowerCase()) ? 10 : 0;
          for (const w of words) if (t.includes(w)) n += 1;
          return n;
        };
        const ranked = cues.map((c, i) => ({ c, i, s: score(c.text) })).filter((x) => x.s > 0).sort((a, b) => b.s - a.s).slice(0, 3);
        for (const r of ranked) {
          // Берём реплику вместе с соседними — иначе цитата обрывается на полуслове
          const around = cues.slice(Math.max(0, r.i - 1), r.i + 3).map((c) => c.text).join(' ');
          found.push({ at: Math.max(0, r.c.start), text: around.replace(/\s+/g, ' ').slice(0, 600), kind: 'transcript' });
        }
      } catch { /* файл субтитров мог быть удалён */ }
    }
    // 3. Если ни реплик, ни текста с экрана — берём начало расшифровки
    if (!found.length) {
      const tr = await one('SELECT transcript FROM videos WHERE id = $1', [v.id]);
      if (tr?.transcript) found.push({ at: 0, text: String(tr.transcript).replace(/\s+/g, ' ').slice(0, 600), kind: 'transcript' });
    }
    if (found.length) {
      out.push({
        videoId: v.id, shortId: v.short_id, title: v.title, duration: Number(v.duration) || 0,
        thumbnailUrl: mediaUrl(v.thumbnail_path),
        fragments: found.slice(0, 3),
      });
    }
  }
  return out.slice(0, limit);
}

const fmt = (sec) => { const s = Math.max(0, Math.round(sec)); const m = Math.floor(s / 60), x = s % 60; return `${m}:${String(x).padStart(2, '0')}`; };

/** Ответ на вопрос по найденным фрагментам. Возвращает { answer, sources, used } */
export async function askLibrary(question, user, { settings = null } = {}) {
  const s = settings || await loadSettings();
  const found = await findFragments(question, user);
  if (!found.length) return { answer: '', sources: [], used: 0, empty: true };

  const numbered = [];
  let i = 0;
  for (const v of found) {
    for (const f of v.fragments.slice(0, 3)) {
      if (numbered.length >= MAX_FRAGMENTS) break;
      i++;
      numbered.push({ n: i, videoId: v.videoId, shortId: v.shortId, title: v.title, at: f.at, text: f.text, kind: f.kind });
    }
  }
  const context = numbered.map((f) => `[${f.n}] «${f.title}», ${fmt(f.at)} (${f.kind === 'screen' ? 'текст на экране' : 'расшифровка речи'}): ${f.text}`).join('\n');
  const prompt = [
    { role: 'system', content: 'Ты помощник корпоративного видеопортала. Отвечай на русском, кратко (2–5 предложений), только по приведённым фрагментам видео. После утверждения ставь номер источника в квадратных скобках, например [2]. Если во фрагментах нет ответа, честно скажи, что в видеотеке ответа нет. Ответ верни в JSON: {"answer": "...", "used": [номера источников]}.' },
    { role: 'user', content: `Вопрос: ${question}\n\nФрагменты видео:\n${context}` },
  ];
  const res = await askAi(prompt, { settings: s, json: true });
  const answer = String(res?.answer || '').trim();
  const usedNums = Array.isArray(res?.used) ? res.used.map(Number).filter((n) => n > 0) : [];
  const sources = numbered
    .filter((f) => !usedNums.length || usedNums.includes(f.n))
    .map((f) => ({ n: f.n, videoId: f.videoId, shortId: f.shortId, title: f.title, at: Math.round(f.at), timeLabel: fmt(f.at), kind: f.kind, text: f.text.slice(0, 220), url: `/watch/${f.shortId}?t=${Math.floor(f.at)}` }));
  return { answer, sources, used: sources.length, empty: !answer };
}

/** Записать вопрос в журнал (для администратора: что ищут сотрудники). */
export async function logAsk({ userId, question, answer, sources, ms }) {
  await query('INSERT INTO ai_search_log(user_id, question, answer, sources, ms) VALUES ($1,$2,$3,$4::jsonb,$5)',
    [userId || null, String(question).slice(0, 500), String(answer || '').slice(0, 4000), JSON.stringify((sources || []).slice(0, 10)), ms || null]);
}
