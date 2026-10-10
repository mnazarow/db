// Итоги встречи и автоклипы: разбор записи по расшифровке через языковую модель.
// Образец — конспекты Otter, Fireflies и Copilot в Teams (решения, задачи, темы) и
// автонарезка OpusClip/Vizard (самостоятельные фрагменты, которые не стыдно отправить отдельно).
import { one, query } from '../db.js';
import { loadSettings } from '../lib/settings.js';
import { askAi } from '../lib/ai.js';
import { notify } from '../lib/notify.js';
import { videoTranscript } from './ai.js';

const MAX_CHARS = 24000;

/** Расшифровка с таймкодами в компактный текст для запроса к модели. */
export function transcriptText(tr, maxChars = MAX_CHARS) {
  const fmt = (s) => {
    const t = Math.max(0, Math.round(s));
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
  };
  if (!tr) return '';
  if (tr.segments?.length) {
    const total = tr.segments.reduce((n, c) => n + c.text.length + 12, 0);
    const keepEvery = total > maxChars ? Math.ceil(total / maxChars) : 1;
    return tr.segments.filter((_, i) => i % keepEvery === 0).map((c) => `[${fmt(c.start)}] ${c.text}`).join('\n');
  }
  return String(tr.text || '').slice(0, maxChars);
}

/** «MM:SS» или «HH:MM:SS» → секунды. Модель иногда отвечает числом — его тоже принимаем. */
export function toSeconds(v) {
  if (v === null || v === undefined) return 0;
  if (typeof v === 'number') return Math.max(0, Math.round(v));
  const m = String(v).trim().match(/^(?:(\d+):)?(\d{1,2}):(\d{1,2})$/);
  if (m) return (Number(m[1] || 0) * 3600) + (Number(m[2]) * 60) + Number(m[3]);
  const n = Number(String(v).replace(',', '.'));
  return Number.isFinite(n) ? Math.max(0, Math.round(n)) : 0;
}

const clean = (s, max = 400) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, max);

/** Нормализация ответа модели: таймкоды в секунды, обрезка длин и количества. */
export function normalizeNotes(raw, duration = 0) {
  const cap = (at) => (duration > 0 ? Math.min(Math.round(duration), toSeconds(at)) : toSeconds(at));
  const list = (arr, map, limit) => (Array.isArray(arr) ? arr : []).slice(0, limit).map(map).filter((x) => x.text);
  return {
    summary: clean(raw?.summary, 2000),
    // У темы достаточно названия: описание подставляем из него, если модель его не дала
    topics: (Array.isArray(raw?.topics) ? raw.topics : []).slice(0, 12)
      .map((t) => ({ at: cap(t.at), title: clean(t.title, 120), text: clean(t.text, 400) }))
      .filter((t) => t.title || t.text)
      .map((t) => ({ ...t, title: t.title || t.text.slice(0, 120), text: t.text || t.title })),
    decisions: list(raw?.decisions, (d) => ({ at: cap(d.at), text: clean(d.text, 300) }), 15),
    tasks: list(raw?.tasks, (t) => ({ at: cap(t.at), text: clean(t.text, 300), who: clean(t.who, 80), due: clean(t.due, 60) }), 20),
    questions: list(raw?.questions, (q) => ({ at: cap(q.at), text: clean(q.text, 300) }), 10),
  };
}

/** Задание: разобрать запись и сохранить итоги встречи. */
export async function runMeetingNotes(job, ctx) {
  const { heartbeat } = ctx;
  const s = await loadSettings(true);
  if (!s['ai.enabled'] || !s['meeting.notes_enabled']) throw Object.assign(new Error('Итоги встречи отключены в настройках'), { noRetry: true });
  const videoId = job.video_id || job.payload.videoId;
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  await query(`INSERT INTO meeting_notes(video_id, status, created_by) VALUES ($1,'processing',$2)
               ON CONFLICT (video_id) DO UPDATE SET status = 'processing', error = NULL, updated_at = now()`,
  [video.id, job.payload.byUserId || null]);
  const tr = await videoTranscript(video);
  const text = transcriptText(tr, Number(s['ai.max_chars']) || MAX_CHARS);
  if (!text.trim()) {
    await query(`UPDATE meeting_notes SET status='failed', error=$2, updated_at=now() WHERE video_id=$1`,
      [video.id, 'Нет расшифровки: добавьте субтитры или включите автосубтитры']);
    throw Object.assign(new Error('Нет расшифровки'), { noRetry: true });
  }
  await heartbeat(20, 'ai');
  const messages = [
    { role: 'system', content: 'Ты помощник, который разбирает записи рабочих совещаний на русском языке. Отвечай строго JSON-объектом без пояснений.' },
    { role: 'user', content:
`Ниже расшифровка записи «${video.title}» с таймкодами.

Составь итоги встречи в JSON:
{
 "summary": "5–8 предложений: о чём шла речь и к чему пришли",
 "topics": [{"at":"MM:SS","title":"тема","text":"1–2 предложения"}],
 "decisions": [{"at":"MM:SS","text":"что решили — одной фразой"}],
 "tasks": [{"at":"MM:SS","text":"что нужно сделать","who":"кому поручено или пусто","due":"срок словами или пусто"}],
 "questions": [{"at":"MM:SS","text":"вопрос, который остался без ответа"}]
}

Правила: бери только то, что реально прозвучало; не выдумывай исполнителей и сроки; таймкод — момент, где это обсуждали; если раздел пуст, верни пустой массив.

Расшифровка:
${text}` },
  ];
  let raw;
  try {
    raw = await askAi(messages, { settings: s, json: true });
  } catch (e) {
    await query(`UPDATE meeting_notes SET status='failed', error=$2, updated_at=now() WHERE video_id=$1`, [video.id, e.message.slice(0, 500)]);
    throw e;
  }
  const notes = normalizeNotes(raw, Number(video.duration) || 0);
  await query(
    `UPDATE meeting_notes SET status='ready', summary=$2, topics=$3::jsonb, decisions=$4::jsonb, tasks=$5::jsonb,
            questions=$6::jsonb, model=$7, error=NULL, updated_at=now() WHERE video_id=$1`,
    [video.id, notes.summary, JSON.stringify(notes.topics), JSON.stringify(notes.decisions),
      JSON.stringify(notes.tasks), JSON.stringify(notes.questions), s['ai.model'] || null]);
  await query('UPDATE videos SET has_notes = true WHERE id = $1', [video.id]);
  await notify(video.owner_id, {
    type: 'meeting_notes', title: 'Итоги встречи готовы', body: video.title,
    link: `/watch/${video.short_id}?panel=notes`, data: { videoId: video.id },
  });
  return { decisions: notes.decisions.length, tasks: notes.tasks.length, topics: notes.topics.length };
}

/** Нормализация предложений клипов. */
export function normalizeClips(raw, duration = 0) {
  const arr = Array.isArray(raw?.clips) ? raw.clips : (Array.isArray(raw) ? raw : []);
  const out = [];
  for (const c of arr.slice(0, 8)) {
    const start = toSeconds(c.start);
    let end = toSeconds(c.end);
    if (duration > 0) end = Math.min(end, Math.round(duration));
    if (!(end > start)) continue;
    if (end - start < 10) continue;                      // слишком короткий фрагмент бесполезен
    if (end - start > 300) end = start + 300;            // клип длиннее пяти минут — уже не клип
    out.push({
      start, end,
      title: clean(c.title, 120) || `Фрагмент ${Math.round(start)} с`,
      reason: clean(c.reason, 300),
      score: Math.max(0, Math.min(100, Math.round(Number(c.score) || 0))),
    });
  }
  return out.sort((a, b) => b.score - a.score).slice(0, 5);
}

/** Задание: предложить лучшие фрагменты записи для отдельных клипов. */
export async function runClipsAi(job, ctx) {
  const { heartbeat } = ctx;
  const s = await loadSettings(true);
  if (!s['ai.enabled'] || !s['clips.ai_enabled']) throw Object.assign(new Error('Автоклипы отключены в настройках'), { noRetry: true });
  const videoId = job.video_id || job.payload.videoId;
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const tr = await videoTranscript(video);
  const text = transcriptText(tr, Number(s['ai.max_chars']) || MAX_CHARS);
  if (!text.trim()) throw Object.assign(new Error('Нет расшифровки: добавьте субтитры или включите автосубтитры'), { noRetry: true });
  await heartbeat(20, 'ai');
  const messages = [
    { role: 'system', content: 'Ты монтажёр корпоративного видео. Отвечай строго JSON-объектом без пояснений.' },
    { role: 'user', content:
`Ниже расшифровка записи «${video.title}» (длительность ${Math.round(Number(video.duration) || 0)} с) с таймкодами.

Найди от 3 до 5 фрагментов, которые полезно сохранить отдельным коротким видео: законченная мысль, инструкция, объяснение или важное объявление. Фрагмент должен быть понятен без остального видео.

Ответ в JSON:
{"clips":[{"start":"MM:SS","end":"MM:SS","title":"короткое название","reason":"чем полезен","score":0-100}]}

Правила: длительность фрагмента от 20 до 180 секунд; фрагменты не должны пересекаться; начинай с начала фразы, а не с середины; score — насколько фрагмент самостоятелен.

Расшифровка:
${text}` },
  ];
  const raw = await askAi(messages, { settings: s, json: true });
  const clips = normalizeClips(raw, Number(video.duration) || 0);
  await query('DELETE FROM clip_suggestions WHERE video_id = $1 AND created_video_id IS NULL', [video.id]);
  for (const c of clips) {
    await query('INSERT INTO clip_suggestions(video_id, start_sec, end_sec, title, reason, score) VALUES ($1,$2,$3,$4,$5,$6)',
      [video.id, c.start, c.end, c.title, c.reason, c.score]);
  }
  await query('UPDATE videos SET clips_suggested_at = now() WHERE id = $1', [video.id]);
  if (clips.length) {
    await notify(video.owner_id, {
      type: 'clips_ready', title: 'ИИ предложил клипы', body: `${video.title}: ${clips.length}`,
      link: `/studio/videos/${video.id}/editor`, data: { videoId: video.id },
    });
  }
  return { clips: clips.length };
}
