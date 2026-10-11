// Итоги встречи, предложения клипов и тренажёры с ветвлением.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, notFound } from '../lib/util.js';
import { canViewVideo, isStaff } from '../lib/access.js';
import { enqueue } from '../lib/jobs.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { audit } from '../lib/audit.js';
import { randomUUID } from 'node:crypto';

async function loadVideo(idOrShort) {
  return one(`SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL`, [String(idOrShort)]);
}
const canEdit = (v, user) => !!user && (v.owner_id === user.id || isStaff(user));

function notesOut(row) {
  if (!row) return null;
  return {
    status: row.status,
    summary: row.summary || '',
    topics: row.topics || [],
    decisions: row.decisions || [],
    tasks: row.tasks || [],
    questions: row.questions || [],
    model: row.model || null,
    error: row.error || null,
    updatedAt: row.updated_at,
  };
}

/** Итоги встречи обычным текстом — чтобы вставить в письмо или задачу. */
function notesToText(v, n) {
  const t = (sec) => `${String(Math.floor(sec / 60)).padStart(2, '0')}:${String(Math.round(sec % 60)).padStart(2, '0')}`;
  const lines = [v.title, ''];
  if (n.summary) lines.push('Кратко:', n.summary, '');
  if (n.decisions.length) { lines.push('Решения:'); for (const d of n.decisions) lines.push(`— [${t(d.at)}] ${d.text}`); lines.push(''); }
  if (n.tasks.length) { lines.push('Задачи:'); for (const x of n.tasks) lines.push(`— [${t(x.at)}] ${x.text}${x.who ? ` (${x.who}${x.due ? `, ${x.due}` : ''})` : ''}`); lines.push(''); }
  if (n.topics.length) { lines.push('Темы:'); for (const x of n.topics) lines.push(`— [${t(x.at)}] ${x.title}${x.text && x.text !== x.title ? ` — ${x.text}` : ''}`); lines.push(''); }
  if (n.questions.length) { lines.push('Остались вопросы:'); for (const q of n.questions) lines.push(`— [${t(q.at)}] ${q.text}`); }
  return lines.join('\n').trim();
}

export default async function meetingRoutes(app) {
  // --- Итоги встречи ----------------------------------------------------------------------------
  app.get('/videos/:id/meeting-notes', async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user, req.shareTokens))) throw forbidden('Нет доступа к этому видео');
    const row = await one('SELECT * FROM meeting_notes WHERE video_id = $1', [v.id]);
    return {
      notes: notesOut(row),
      canMake: canEdit(v, req.user) && !!req.settings['ai.enabled'] && !!req.settings['meeting.notes_enabled'],
      enabled: !!req.settings['meeting.notes_enabled'],
    };
  });

  app.post('/videos/:id/meeting-notes', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden('Итоги встречи делает автор видео или модератор');
    if (!req.settings['ai.enabled'] || !req.settings['meeting.notes_enabled']) throw badRequest('Итоги встречи отключены администратором');
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    const cur = await one('SELECT status FROM meeting_notes WHERE video_id = $1', [v.id]);
    if (cur && cur.status === 'processing' && !req.body?.force) throw badRequest('Итоги уже готовятся');
    const job = await enqueue('meeting_notes', { videoId: v.id, byUserId: req.user.id }, { videoId: v.id, dedupe: true });
    await audit(req, 'meeting.notes', { targetType: 'video', targetId: v.id });
    return { jobId: job?.id || null, status: 'queued' };
  });

  app.get('/videos/:id/meeting-notes/export', async (req, reply) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user, req.shareTokens))) throw forbidden();
    const row = await one('SELECT * FROM meeting_notes WHERE video_id = $1', [v.id]);
    if (!row || row.status !== 'ready') throw notFound('Итоги встречи ещё не готовы');
    const n = notesOut(row);
    if (req.query.format === 'csv') {
      const rows = [
        ...n.decisions.map((d) => ({ kind: 'Решение', at: d.at, text: d.text, who: '', due: '' })),
        ...n.tasks.map((t) => ({ kind: 'Задача', at: t.at, text: t.text, who: t.who || '', due: t.due || '' })),
        ...n.questions.map((q) => ({ kind: 'Вопрос', at: q.at, text: q.text, who: '', due: '' })),
      ];
      const csv = toCsv(rows, [
        { title: 'Тип', value: 'kind' },
        { title: 'Момент', value: (r) => `${String(Math.floor(r.at / 60)).padStart(2, '0')}:${String(Math.round(r.at % 60)).padStart(2, '0')}` },
        { title: 'Содержание', value: 'text' },
        { title: 'Кому', value: 'who' },
        { title: 'Срок', value: 'due' },
      ]);
      return sendCsv(reply, `notes-${v.short_id}.csv`, csv);
    }
    reply.type('text/plain; charset=utf-8');
    return notesToText(v, n);
  });

  app.delete('/videos/:id/meeting-notes', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden();
    await query('DELETE FROM meeting_notes WHERE video_id = $1', [v.id]);
    await query('UPDATE videos SET has_notes = false WHERE id = $1', [v.id]);
    reply.code(204);
  });

  // --- Предложения клипов -----------------------------------------------------------------------
  app.get('/videos/:id/clip-suggestions', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden('Предложения видны автору видео');
    const rows = await many(
      `SELECT c.*, cv.short_id AS created_short_id FROM clip_suggestions c
       LEFT JOIN videos cv ON cv.id = c.created_video_id
       WHERE c.video_id = $1 AND c.dismissed = false ORDER BY c.score DESC, c.start_sec`, [v.id]);
    return {
      enabled: !!(req.settings['ai.enabled'] && req.settings['clips.ai_enabled']),
      suggestedAt: v.clips_suggested_at,
      suggestions: rows.map((r) => ({
        id: String(r.id), start: Number(r.start_sec), end: Number(r.end_sec), title: r.title,
        reason: r.reason, score: r.score, createdVideoId: r.created_video_id, createdShortId: r.created_short_id,
      })),
    };
  });

  app.post('/videos/:id/clip-suggestions', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden();
    if (!req.settings['ai.enabled'] || !req.settings['clips.ai_enabled']) throw badRequest('Автоклипы отключены администратором');
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    if (Number(v.duration) < 60) throw badRequest('Для коротких видео клипы не нужны');
    const job = await enqueue('clips_ai', { videoId: v.id, byUserId: req.user.id }, { videoId: v.id, dedupe: true });
    await audit(req, 'clips.suggest', { targetType: 'video', targetId: v.id });
    return { jobId: job?.id || null, status: 'queued' };
  });

  app.delete('/videos/:id/clip-suggestions/:sid', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden();
    await query('UPDATE clip_suggestions SET dismissed = true WHERE id = $1 AND video_id = $2', [Number(req.params.sid), v.id]);
    reply.code(204);
  });

  // --- Тренажёр с ветвлением --------------------------------------------------------------------
  app.get('/videos/:id/scenario', async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user, req.shareTokens))) throw forbidden('Нет доступа к этому видео');
    const row = await one('SELECT * FROM video_scenarios WHERE video_id = $1', [v.id]);
    if (!row || (!row.enabled && !canEdit(v, req.user))) return { scenario: null, enabled: !!req.settings['scenario.enabled'] };
    const editor = canEdit(v, req.user);
    return {
      enabled: !!req.settings['scenario.enabled'],
      scenario: {
        title: row.title, active: row.enabled, showResult: row.show_result,
        points: (row.points || []).map((p) => ({
          id: p.id, at: p.at, text: p.text,
          options: (p.options || []).map((o) => ({
            id: o.id, text: o.text, goto: o.goto, ending: !!o.ending,
            // Правильность и пояснение зритель узнаёт только после выбора
            feedback: editor ? o.feedback : undefined,
            correct: editor ? o.correct : undefined,
          })),
        })),
      },
    };
  });

  app.put('/videos/:id/scenario', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden('Тренажёр настраивает автор видео или модератор');
    if (!req.settings['scenario.enabled']) throw badRequest('Тренажёры отключены администратором');
    const b = req.body || {};
    const dur = Number(v.duration) || 0;
    const points = (Array.isArray(b.points) ? b.points : []).slice(0, 30).map((p) => {
      const at = Math.max(0, Math.min(dur || 1e9, Number(p.at) || 0));
      const options = (Array.isArray(p.options) ? p.options : []).slice(0, 6).map((o) => ({
        id: String(o.id || randomUUID()).slice(0, 40),
        text: String(o.text || '').trim().slice(0, 200),
        goto: o.goto === null || o.goto === undefined || o.goto === '' ? null : Math.max(0, Math.min(dur || 1e9, Number(o.goto) || 0)),
        feedback: String(o.feedback || '').trim().slice(0, 300),
        correct: !!o.correct,
        ending: !!o.ending,
      })).filter((o) => o.text);
      return { id: String(p.id || randomUUID()).slice(0, 40), at, text: String(p.text || '').trim().slice(0, 300), options };
    }).filter((p) => p.text && p.options.length >= 2).sort((a, c) => a.at - c.at);
    const row = await one(
      `INSERT INTO video_scenarios(video_id, title, enabled, show_result, points, updated_at)
       VALUES ($1,$2,$3,$4,$5::jsonb, now())
       ON CONFLICT (video_id) DO UPDATE SET title = EXCLUDED.title, enabled = EXCLUDED.enabled,
         show_result = EXCLUDED.show_result, points = EXCLUDED.points, updated_at = now() RETURNING *`,
      [v.id, String(b.title || 'Тренажёр').slice(0, 150), b.active !== false, b.showResult !== false, JSON.stringify(points)]);
    await query('UPDATE videos SET has_scenario = $2 WHERE id = $1', [v.id, points.length > 0 && b.active !== false]);
    await audit(req, 'scenario.updated', { targetType: 'video', targetId: v.id, meta: { points: points.length } });
    return { scenario: { title: row.title, active: row.enabled, showResult: row.show_result, points: row.points } };
  });

  /** Выбор зрителя: записываем и отвечаем, куда перейти и что показать. */
  app.post('/videos/:id/scenario/choice', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user))) throw forbidden();
    const row = await one('SELECT * FROM video_scenarios WHERE video_id = $1 AND enabled = true', [v.id]);
    if (!row) throw badRequest('Тренажёр выключен');
    const point = (row.points || []).find((p) => p.id === String(req.body?.pointId || ''));
    const option = point?.options?.find((o) => o.id === String(req.body?.optionId || ''));
    if (!point || !option) throw badRequest('Такого варианта нет');
    await query('INSERT INTO scenario_choices(video_id, user_id, point_id, option_id, correct) VALUES ($1,$2,$3,$4,$5)',
      [v.id, req.user.id, point.id, option.id, option.correct === true ? true : (point.options.some((o) => o.correct) ? false : null)]);
    return { goto: option.goto ?? null, feedback: option.feedback || '', correct: !!option.correct, ending: !!option.ending };
  });

  /** Отчёт автору: какие развилки проходят и где ошибаются. */
  app.get('/videos/:id/scenario/report', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!canEdit(v, req.user)) throw forbidden();
    const row = await one('SELECT * FROM video_scenarios WHERE video_id = $1', [v.id]);
    const stats = await many(
      `SELECT point_id, option_id, count(*)::int AS n, count(*) FILTER (WHERE correct = true)::int AS ok
       FROM scenario_choices WHERE video_id = $1 GROUP BY point_id, option_id`, [v.id]);
    const byPoint = new Map();
    for (const s of stats) {
      if (!byPoint.has(s.point_id)) byPoint.set(s.point_id, []);
      byPoint.get(s.point_id).push({ optionId: s.option_id, count: s.n, correct: s.ok > 0 });
    }
    const points = (row?.points || []).map((p) => {
      const chosen = byPoint.get(p.id) || [];
      const total = chosen.reduce((n, c) => n + c.count, 0);
      return {
        id: p.id, at: p.at, text: p.text, total,
        options: p.options.map((o) => {
          const c = chosen.find((x) => x.optionId === o.id);
          return { id: o.id, text: o.text, correct: !!o.correct, count: c?.count || 0, percent: total ? Math.round(((c?.count || 0) / total) * 100) : 0 };
        }),
      };
    });
    const totals = await one(
      `SELECT count(*)::int AS choices, count(DISTINCT user_id)::int AS people,
              count(*) FILTER (WHERE correct = false)::int AS mistakes FROM scenario_choices WHERE video_id = $1`, [v.id]);
    return { points, totals: { choices: totals?.choices || 0, people: totals?.people || 0, mistakes: totals?.mistakes || 0 } };
  });
}
