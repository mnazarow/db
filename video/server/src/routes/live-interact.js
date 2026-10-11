// Интерактив трансляций: опросы, вопросы спикеру (Q&A), напоминания о запланированных эфирах, календарь .ics.
import { one, many, query } from '../db.js';
import { canViewLive, isStaff } from '../lib/access.js';
import { badRequest, forbidden, notFound, unauthorized, escapeHtml } from '../lib/util.js';
import { toChannel } from '../lib/realtime.js';
import { notify } from '../lib/notify.js';
import { config } from '../config.js';
import { loadStream } from './live.js';
import { audit } from '../lib/audit.js';
import { mediaUrl } from '../lib/urls.js';

async function viewable(req, id) {
  const s = await loadStream(id);
  if (!s) throw notFound('Трансляция не найдена');
  if (!canViewLive(s, req.user)) throw req.user ? forbidden('Нет доступа к трансляции') : unauthorized('Войдите, чтобы смотреть трансляцию');
  return s;
}
const canManage = (s, user) => !!user && (user.id === s.owner_id || isStaff(user));

function pollOut(p, { votes = [], mine = null, manage = false } = {}) {
  const total = votes.reduce((n, v) => n + Number(v.n), 0);
  const counts = Object.fromEntries(votes.map((v) => [v.option_id, Number(v.n)]));
  const showResults = manage || p.show_results || p.status === 'closed';
  return {
    id: p.id, question: p.question, multiple: p.multiple, status: p.status, showResults: p.show_results, createdAt: p.created_at, openedAt: p.opened_at, closedAt: p.closed_at,
    total: showResults ? total : undefined,
    options: p.options.map((o) => ({ id: o.id, text: o.text, votes: showResults ? (counts[o.id] || 0) : undefined, percent: showResults && total ? Math.round(((counts[o.id] || 0) / total) * 100) : undefined })),
    myVote: mine,
  };
}
async function pollVotes(pollId) {
  return many(`SELECT o AS option_id, count(*)::int AS n FROM live_poll_votes v, jsonb_array_elements_text(v.option_ids) o WHERE v.poll_id = $1 GROUP BY o`, [pollId]);
}
async function pollFull(p, user, manage) {
  const votes = await pollVotes(p.id);
  const mine = user ? (await one('SELECT option_ids FROM live_poll_votes WHERE poll_id = $1 AND user_id = $2', [p.id, user.id]))?.option_ids || null : null;
  return pollOut(p, { votes, mine, manage });
}

function questionOut(q, user) {
  return {
    id: q.id, body: q.body, upvotes: q.upvotes, status: q.status, answer: q.answer, answeredAt: q.answered_at, createdAt: q.created_at,
    author: q.anonymous && !(user && (user.id === q.user_id)) ? { displayName: 'Анонимно', anonymous: true } : { id: q.user_id, displayName: q.display_name, handle: q.handle, avatarUrl: mediaUrl(q.avatar_path) },
    mine: !!user && user.id === q.user_id, voted: !!q.voted,
  };
}
const Q_SELECT = `q.*, u.display_name, u.handle, u.avatar_path`;

/** Событие календаря (.ics) для запланированного эфира. */
export function streamIcs(s, baseUrl) {
  const fmt = (d) => new Date(d).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
  const start = s.scheduled_at || s.started_at || new Date();
  const end = new Date(new Date(start).getTime() + 3600000);
  const esc = (t) => String(t || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
  return ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CorpVideo//RU', 'METHOD:PUBLISH', 'BEGIN:VEVENT', `UID:live-${s.id}@corpvideo`, `DTSTAMP:${fmt(new Date())}`, `DTSTART:${fmt(start)}`, `DTEND:${fmt(end)}`,
    `SUMMARY:${esc(s.title)}`, `DESCRIPTION:${esc((s.description || '') + '\n' + baseUrl + '/live/' + s.short_id)}`, `URL:${baseUrl}/live/${s.short_id}`, 'BEGIN:VALARM', 'TRIGGER:-PT15M', 'ACTION:DISPLAY', `DESCRIPTION:${esc(s.title)}`, 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
}

export default async function liveInteractRoutes(app) {
  // --- Живые субтитры эфира (1.6) ----------------------------------------------------------------
  /** История реплик: зритель, открывший страницу позже, видит последние строки. */
  app.get('/live/:id/captions', async (req) => {
    const s = await viewable(req, req.params.id);
    const limit = Math.min(200, Math.max(1, Number(req.query.limit) || 40));
    const rows = await many(
      'SELECT seq, offset_sec, text, created_at FROM live_captions WHERE stream_id = $1 ORDER BY seq DESC LIMIT $2', [s.id, limit]);
    return {
      enabled: !!s.captions,
      captions: rows.reverse().map((r) => ({ seq: r.seq, offsetSec: Number(r.offset_sec) || 0, text: r.text, at: r.created_at })),
    };
  });

  // --- Напоминания и календарь -------------------------------------------------------------
  app.post('/live/:id/remind', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    if (!s.scheduled_at || s.status === 'live') throw badRequest('Эфир не запланирован или уже идёт');
    const on = req.body?.on !== false;
    if (on) await query('INSERT INTO live_reminders(stream_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [s.id, req.user.id]);
    else await query('DELETE FROM live_reminders WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]);
    const n = await one('SELECT count(*)::int AS n FROM live_reminders WHERE stream_id = $1', [s.id]);
    return { ok: true, reminder: on, count: n.n };
  });
  app.get('/live/:id/calendar.ics', async (req, reply) => {
    const s = await viewable(req, req.params.id);
    reply.header('Content-Type', 'text/calendar; charset=utf-8').header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent('efir-' + s.short_id + '.ics')}`);
    return streamIcs(s, config.baseUrl);
  });

  // --- Опросы -------------------------------------------------------------------------------
  app.get('/live/:id/polls', async (req) => {
    const s = await viewable(req, req.params.id);
    const manage = canManage(s, req.user);
    const rows = await many(`SELECT * FROM live_polls WHERE stream_id = $1 ${manage ? '' : "AND status <> 'draft'"} ORDER BY created_at DESC LIMIT 50`, [s.id]);
    if (!rows.length) return { polls: [] };
    // Голоса и «мой голос» — двумя запросами на все опросы сразу, а не двумя на каждый:
    // страницу эфира открывает каждый зритель, и 50 опросов давали сотню запросов к базе.
    const ids = rows.map((p) => p.id);
    const votes = await many(
      `SELECT v.poll_id, o AS option_id, count(*)::int AS n FROM live_poll_votes v, jsonb_array_elements_text(v.option_ids) o
       WHERE v.poll_id = ANY($1::uuid[]) GROUP BY v.poll_id, o`, [ids]);
    const mine = req.user ? await many('SELECT poll_id, option_ids FROM live_poll_votes WHERE poll_id = ANY($1::uuid[]) AND user_id = $2', [ids, req.user.id]) : [];
    const byPoll = new Map();
    for (const v of votes) { if (!byPoll.has(v.poll_id)) byPoll.set(v.poll_id, []); byPoll.get(v.poll_id).push(v); }
    const myVote = new Map(mine.map((m) => [m.poll_id, m.option_ids]));
    return { polls: rows.map((p) => pollOut(p, { votes: byPoll.get(p.id) || [], mine: myVote.get(p.id) || null, manage })) };
  });
  app.post('/live/:id/polls', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    if (!canManage(s, req.user)) throw forbidden('Опросы создаёт ведущий или модератор');
    const b = req.body || {};
    const question = String(b.question || '').trim().slice(0, 300);
    const options = (Array.isArray(b.options) ? b.options : []).map((o, i) => ({ id: `o${i + 1}`, text: String(typeof o === 'string' ? o : o?.text || '').trim().slice(0, 120) })).filter((o) => o.text).slice(0, 10);
    if (!question || options.length < 2) throw badRequest('Нужен вопрос и минимум два варианта');
    const status = b.open === false ? 'draft' : 'open';
    const p = await one(`INSERT INTO live_polls(stream_id, question, options, multiple, status, show_results, created_by, opened_at) VALUES ($1,$2,$3::jsonb,$4,$5,$6,$7, CASE WHEN $5 = 'open' THEN now() END) RETURNING *`,
      [s.id, question, JSON.stringify(options), !!b.multiple, status, b.showResults !== false, req.user.id]);
    const out = await pollFull(p, req.user, true);
    if (status === 'open') await toChannel(`live:${s.id}`, { type: 'poll', streamId: s.id, poll: pollOut(p, { votes: [] }) });
    return { poll: out };
  });
  app.patch('/live/:id/polls/:pid', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    if (!canManage(s, req.user)) throw forbidden();
    const p0 = await one('SELECT * FROM live_polls WHERE id = $1 AND stream_id = $2', [req.params.pid, s.id]);
    if (!p0) throw notFound('Опрос не найден');
    const b = req.body || {};
    const status = ['draft', 'open', 'closed'].includes(b.status) ? b.status : p0.status;
    const p = await one(`UPDATE live_polls SET status = $2, show_results = $3, opened_at = CASE WHEN $2 = 'open' AND opened_at IS NULL THEN now() ELSE opened_at END, closed_at = CASE WHEN $2 = 'closed' THEN now() ELSE NULL END WHERE id = $1 RETURNING *`,
      [p0.id, status, b.showResults !== undefined ? !!b.showResults : p0.show_results]);
    const votes = await pollVotes(p.id);
    await toChannel(`live:${s.id}`, { type: 'poll', streamId: s.id, poll: pollOut(p, { votes }) });
    return { poll: pollOut(p, { votes, manage: true }) };
  });
  app.delete('/live/:id/polls/:pid', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    if (!canManage(s, req.user)) throw forbidden();
    await query('DELETE FROM live_polls WHERE id = $1 AND stream_id = $2', [req.params.pid, s.id]);
    await toChannel(`live:${s.id}`, { type: 'poll_delete', streamId: s.id, pollId: req.params.pid });
    return { ok: true };
  });
  app.post('/live/:id/polls/:pid/vote', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    const p = await one('SELECT * FROM live_polls WHERE id = $1 AND stream_id = $2', [req.params.pid, s.id]);
    if (!p) throw notFound('Опрос не найден');
    if (p.status !== 'open') throw badRequest('Опрос закрыт');
    let ids = Array.isArray(req.body?.optionIds) ? req.body.optionIds.map(String) : [String(req.body?.optionId || '')];
    ids = [...new Set(ids.filter((id) => p.options.some((o) => o.id === id)))];
    if (!ids.length) throw badRequest('Выберите вариант');
    if (!p.multiple) ids = ids.slice(0, 1);
    await query('INSERT INTO live_poll_votes(poll_id, user_id, option_ids) VALUES ($1,$2,$3::jsonb) ON CONFLICT (poll_id, user_id) DO UPDATE SET option_ids = EXCLUDED.option_ids, created_at = now()', [p.id, req.user.id, JSON.stringify(ids)]);
    const votes = await pollVotes(p.id);
    await toChannel(`live:${s.id}`, { type: 'poll', streamId: s.id, poll: pollOut(p, { votes }) });
    return { poll: pollOut(p, { votes, mine: ids }) };
  });

  // --- Вопросы спикеру (Q&A) ----------------------------------------------------------------
  app.get('/live/:id/questions', async (req) => {
    const s = await viewable(req, req.params.id);
    const manage = canManage(s, req.user);
    const rows = await many(
      `SELECT ${Q_SELECT}, (v.user_id IS NOT NULL) AS voted FROM live_questions q JOIN users u ON u.id = q.user_id
       LEFT JOIN live_question_votes v ON v.question_id = q.id AND v.user_id = $2
       WHERE q.stream_id = $1 ${manage ? '' : "AND (q.status <> 'hidden' OR q.user_id = $2)"} ORDER BY (q.status = 'answered'), q.upvotes DESC, q.created_at DESC LIMIT 200`,
      [s.id, req.user?.id || null],
    );
    return { questions: rows.map((q) => questionOut(q, req.user)), qaEnabled: s.qa_enabled };
  });
  app.post('/live/:id/questions', { preHandler: app.requireActive, config: { rateLimit: { max: 20, timeWindow: '1 minute' } } }, async (req) => {
    const s = await viewable(req, req.params.id);
    if (!s.qa_enabled) throw forbidden('Вопросы отключены ведущим');
    const body = String(req.body?.body || '').trim().slice(0, 1000);
    if (body.length < 3) throw badRequest('Слишком короткий вопрос');
    const q = await one('INSERT INTO live_questions(stream_id, user_id, body, anonymous) VALUES ($1,$2,$3,$4) RETURNING *', [s.id, req.user.id, body, !!req.body?.anonymous]);
    const full = await one(`SELECT ${Q_SELECT}, false AS voted FROM live_questions q JOIN users u ON u.id = q.user_id WHERE q.id = $1`, [q.id]);
    await toChannel(`live:${s.id}`, { type: 'question', streamId: s.id, question: questionOut(full, null) });
    return { question: questionOut(full, req.user) };
  });
  app.post('/live/:id/questions/:qid/upvote', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    const q = await one('SELECT * FROM live_questions WHERE id = $1 AND stream_id = $2', [Number(req.params.qid), s.id]);
    if (!q) throw notFound('Вопрос не найден');
    const existing = await one('SELECT 1 FROM live_question_votes WHERE question_id = $1 AND user_id = $2', [q.id, req.user.id]);
    if (existing) await query('DELETE FROM live_question_votes WHERE question_id = $1 AND user_id = $2', [q.id, req.user.id]);
    else await query('INSERT INTO live_question_votes(question_id, user_id) VALUES ($1,$2)', [q.id, req.user.id]);
    const upd = await one('UPDATE live_questions SET upvotes = (SELECT count(*) FROM live_question_votes WHERE question_id = $1) WHERE id = $1 RETURNING upvotes', [q.id]);
    await toChannel(`live:${s.id}`, { type: 'question_votes', streamId: s.id, questionId: q.id, upvotes: upd.upvotes });
    return { upvotes: upd.upvotes, voted: !existing };
  });
  app.patch('/live/:id/questions/:qid', { preHandler: app.requireActive }, async (req) => {
    const s = await viewable(req, req.params.id);
    const q0 = await one('SELECT * FROM live_questions WHERE id = $1 AND stream_id = $2', [Number(req.params.qid), s.id]);
    if (!q0) throw notFound('Вопрос не найден');
    const manage = canManage(s, req.user);
    if (!manage && q0.user_id !== req.user.id) throw forbidden();
    const b = req.body || {};
    // Автор может только удалить свой вопрос (скрыть); ведущий — отметить отвеченным, скрыть, вернуть, написать ответ
    const status = manage && ['new', 'answered', 'hidden'].includes(b.status) ? b.status : (!manage && b.status === 'hidden' ? 'hidden' : q0.status);
    const answer = manage && b.answer !== undefined ? String(b.answer).trim().slice(0, 2000) || null : q0.answer;
    const q = await one(`UPDATE live_questions SET status = $2, answer = $3, answered_by = CASE WHEN $2 = 'answered' THEN $4 ELSE answered_by END, answered_at = CASE WHEN $2 = 'answered' AND answered_at IS NULL THEN now() WHEN $2 <> 'answered' THEN NULL ELSE answered_at END WHERE id = $1 RETURNING *`, [q0.id, status, answer, req.user.id]);
    const full = await one(`SELECT ${Q_SELECT}, false AS voted FROM live_questions q JOIN users u ON u.id = q.user_id WHERE q.id = $1`, [q.id]);
    await toChannel(`live:${s.id}`, { type: 'question', streamId: s.id, question: questionOut(full, null) });
    return { question: questionOut(full, req.user) };
  });
  app.patch('/studio/live/:id/interact', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (!canManage(s, req.user)) throw forbidden();
    const b = req.body || {};
    await query('UPDATE live_streams SET qa_enabled = $2, polls_enabled = $3, updated_at = now() WHERE id = $1', [s.id, b.qaEnabled !== undefined ? !!b.qaEnabled : s.qa_enabled, b.pollsEnabled !== undefined ? !!b.pollsEnabled : s.polls_enabled]);
    await audit(req, 'live.interact', { targetType: 'live', targetId: s.id, details: Object.keys(b) });
    return { ok: true };
  });
  // Экспорт вопросов (после эфира) — CSV
  app.get('/live/:id/questions/export', { preHandler: app.requireActive }, async (req, reply) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (!canManage(s, req.user)) throw forbidden();
    const rows = await many(`SELECT ${Q_SELECT} FROM live_questions q JOIN users u ON u.id = q.user_id WHERE q.stream_id = $1 ORDER BY q.upvotes DESC, q.created_at`, [s.id]);
    const { toCsv, sendCsv } = await import('../lib/csv.js');
    return sendCsv(reply, `questions-${s.short_id}.csv`, toCsv(rows, [
      { title: 'Вопрос', value: 'body' }, { title: 'Автор', value: (q) => (q.anonymous ? 'Анонимно' : q.display_name) }, { title: 'Голосов', value: 'upvotes' },
      { title: 'Статус', value: (q) => ({ new: 'новый', answered: 'отвечен', hidden: 'скрыт' }[q.status]) }, { title: 'Ответ', value: (q) => q.answer || '' }, { title: 'Задан', value: (q) => new Date(q.created_at).toLocaleString('ru-RU') },
    ]));
  });
}

/** Напоминания о запланированных эфирах (вызывается обслуживанием раз в минуту): за 15 минут до начала. */
export async function sendLiveReminders() {
  const due = await many(`SELECT s.*, u.display_name AS owner_name FROM live_streams s JOIN users u ON u.id = s.owner_id WHERE s.scheduled_at IS NOT NULL AND s.reminded_at IS NULL AND s.status <> 'ended' AND s.scheduled_at <= now() + interval '15 minutes' AND s.scheduled_at > now() - interval '30 minutes'`);
  let sent = 0;
  for (const s of due) {
    // Напоминание получают все, кто нажал «напомнить», и все зарегистрированные на вебинар
    const users = await many(
      `SELECT user_id FROM live_reminders WHERE stream_id = $1
       UNION SELECT user_id FROM live_registrations WHERE stream_id = $1 AND user_id IS NOT NULL`, [s.id]);
    for (const u of users) {
      await notify(u.user_id, { type: 'live_reminder', title: `Скоро эфир: ${s.title}`, body: `Начало ${new Date(s.scheduled_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })} · ${s.owner_name}`, link: `/live/${s.short_id}`, actorId: s.owner_id, data: { streamId: s.id } });
      sent++;
    }
    await query('UPDATE live_streams SET reminded_at = now() WHERE id = $1', [s.id]);
  }
  return sent;
}
