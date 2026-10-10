// Тесты (проверка знаний) внутри видео: редактор для автора, прохождение для зрителя, результаты.
import { one, many, query } from '../db.js';
import { badRequest, notFound, forbidden, paging } from '../lib/util.js';
import { canEditVideo } from '../lib/access.js';
import { requireViewable, requireEditable } from './videos.js';
import { markQuizPassed } from '../lib/assignments.js';
import { markCourseQuizPassed } from '../lib/courses.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { audit } from '../lib/audit.js';
import { emitEvent, eventVideo, eventUser } from '../lib/events.js';
import { xapiStatement, xapiResultForQuiz } from '../lib/xapi.js';

const MAX_QUESTIONS = 100;
const MAX_OPTIONS = 10;

/** Нормализация вопросов из редактора: id, таймкод, тип, варианты, правильные ответы. */
function normalizeQuestions(input) {
  if (!Array.isArray(input)) throw badRequest('Ожидается массив вопросов');
  const out = [];
  for (const q of input.slice(0, MAX_QUESTIONS)) {
    const text = String(q?.text || '').trim().slice(0, 1000);
    if (!text) continue;
    const type = q?.type === 'multiple' ? 'multiple' : 'single';
    const options = (Array.isArray(q?.options) ? q.options : []).slice(0, MAX_OPTIONS)
      .map((o, i) => ({ id: String(o?.id || `o${i + 1}`).slice(0, 24), text: String(o?.text || '').trim().slice(0, 500), correct: !!o?.correct }))
      .filter((o) => o.text);
    if (options.length < 2) throw badRequest(`У вопроса «${text.slice(0, 40)}» должно быть минимум два варианта ответа`);
    const correct = options.filter((o) => o.correct).length;
    if (!correct) throw badRequest(`У вопроса «${text.slice(0, 40)}» не отмечен правильный ответ`);
    if (type === 'single' && correct > 1) throw badRequest(`У вопроса «${text.slice(0, 40)}» несколько правильных ответов — выберите тип «несколько ответов»`);
    let at = q?.at === null || q?.at === undefined || q?.at === '' ? null : Number(q.at);
    if (at !== null && (!Number.isFinite(at) || at < 0)) at = null;
    out.push({
      id: String(q?.id || `q${out.length + 1}`).slice(0, 24),
      at: at === null ? null : Math.round(at * 10) / 10,
      text, type, options,
      explanation: String(q?.explanation || '').trim().slice(0, 1000),
    });
  }
  // уникальные идентификаторы
  const seen = new Set();
  for (const q of out) { while (seen.has(q.id)) q.id = q.id + 'x'; seen.add(q.id); }
  return out;
}

/** Версия теста для зрителя: без флагов правильности. */
function quizForViewer(q) {
  return {
    id: q.id, title: q.title, passPercent: q.pass_percent, showAnswers: q.show_answers, allowRetry: q.allow_retry,
    questions: q.questions.map((x) => ({ id: x.id, at: x.at, text: x.text, type: x.type, options: x.options.map((o) => ({ id: o.id, text: o.text })) })),
    total: q.questions.length,
  };
}
function quizFull(q) {
  return { id: q.id, videoId: q.video_id, title: q.title, passPercent: q.pass_percent, showAnswers: q.show_answers, allowRetry: q.allow_retry, questions: q.questions, updatedAt: q.updated_at };
}

/** Проверка ответов: {questionId: [optionId…]} → {correct,total,percent,passed,details}. */
export function gradeQuiz(quiz, answers) {
  let correct = 0;
  const details = [];
  for (const q of quiz.questions) {
    const given = new Set((Array.isArray(answers?.[q.id]) ? answers[q.id] : [answers?.[q.id]]).filter(Boolean).map(String));
    const right = new Set(q.options.filter((o) => o.correct).map((o) => o.id));
    const ok = given.size === right.size && [...given].every((g) => right.has(g));
    if (ok) correct++;
    details.push({ id: q.id, correct: ok, given: [...given], right: quiz.show_answers ? [...right] : undefined, explanation: quiz.show_answers ? q.explanation : undefined });
  }
  const total = quiz.questions.length;
  const percent = total ? Math.round((correct / total) * 100) : 0;
  return { correct, total, percent, passed: total > 0 && percent >= quiz.pass_percent, details };
}

function attemptOut(a) {
  return { id: a.id, correct: a.correct, total: a.total, percent: a.percent, passed: a.passed, finishedAt: a.finished_at, createdAt: a.created_at };
}

export default async function quizRoutes(app) {
  // Тест для зрителя (+ состояние его попыток)
  app.get('/videos/:id/quiz', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const q = await one('SELECT * FROM video_quizzes WHERE video_id = $1', [v.id]);
    if (!q || !q.questions.length) return { quiz: null };
    let attempts = [];
    if (req.user) attempts = await many('SELECT * FROM quiz_attempts WHERE quiz_id = $1 AND user_id = $2 ORDER BY created_at DESC LIMIT 20', [q.id, req.user.id]);
    const best = attempts.reduce((b, a) => (!b || a.percent > b.percent ? a : b), null);
    return {
      quiz: quizForViewer(q),
      attempts: attempts.map(attemptOut),
      best: best ? attemptOut(best) : null,
      passed: attempts.some((a) => a.passed),
      canRetry: q.allow_retry || !attempts.length,
    };
  });

  // Полная версия для редактора
  app.get('/videos/:id/quiz/full', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const q = await one('SELECT * FROM video_quizzes WHERE video_id = $1', [v.id]);
    const stats = q ? await one(
      `SELECT count(*)::int AS attempts, count(DISTINCT user_id)::int AS people, count(*) FILTER (WHERE passed)::int AS passed, coalesce(round(avg(percent)),0)::int AS avg_percent FROM quiz_attempts WHERE quiz_id = $1`,
      [q.id]) : null;
    return { quiz: q ? quizFull(q) : null, stats: stats ? { attempts: stats.attempts, people: stats.people, passed: stats.passed, avgPercent: stats.avg_percent } : null };
  });

  // Сохранить тест (создание/обновление)
  app.put('/videos/:id/quiz', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const b = req.body || {};
    const questions = normalizeQuestions(b.questions || []);
    const passPercent = Math.min(100, Math.max(1, Number(b.passPercent) || 70));
    const title = String(b.title || 'Проверка знаний').trim().slice(0, 150) || 'Проверка знаний';
    const q = await one(
      `INSERT INTO video_quizzes(video_id, title, pass_percent, show_answers, allow_retry, questions)
       VALUES ($1,$2,$3,$4,$5,$6::jsonb)
       ON CONFLICT (video_id) DO UPDATE SET title = EXCLUDED.title, pass_percent = EXCLUDED.pass_percent, show_answers = EXCLUDED.show_answers,
         allow_retry = EXCLUDED.allow_retry, questions = EXCLUDED.questions, updated_at = now() RETURNING *`,
      [v.id, title, passPercent, b.showAnswers !== false, b.allowRetry !== false, JSON.stringify(questions)],
    );
    await query('UPDATE videos SET has_quiz = $2 WHERE id = $1', [v.id, questions.length > 0]);
    await audit(req, 'quiz.save', { targetType: 'video', targetId: v.id, details: { questions: questions.length } });
    return { quiz: quizFull(q) };
  });

  app.delete('/videos/:id/quiz', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    await query('DELETE FROM video_quizzes WHERE video_id = $1', [v.id]);
    await query('UPDATE videos SET has_quiz = false WHERE id = $1', [v.id]);
    await audit(req, 'quiz.delete', { targetType: 'video', targetId: v.id });
    return { ok: true };
  });

  // Проверить один ответ по ходу видео (без записи попытки) — для вопросов «в точке таймлайна»
  app.post('/videos/:id/quiz/check', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    const q = await one('SELECT * FROM video_quizzes WHERE video_id = $1', [v.id]);
    if (!q) throw notFound('Теста нет');
    const question = q.questions.find((x) => x.id === String(req.body?.questionId || ''));
    if (!question) throw notFound('Вопрос не найден');
    const r = gradeQuiz({ ...q, questions: [question] }, { [question.id]: req.body?.answer });
    // right/explanation уже вычислены с учётом show_answers в gradeQuiz — иначе правильные ответы
    // можно было собрать перебором questionId, не начиная попытку
    return { correct: r.details[0].correct, right: r.details[0].right, explanation: r.details[0].explanation };
  });

  // Завершить попытку: все ответы разом
  app.post('/videos/:id/quiz/finish', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    const q = await one('SELECT * FROM video_quizzes WHERE video_id = $1', [v.id]);
    if (!q || !q.questions.length) throw notFound('Теста нет');
    if (!q.allow_retry) {
      const prev = await one('SELECT 1 FROM quiz_attempts WHERE quiz_id = $1 AND user_id = $2', [q.id, req.user.id]);
      if (prev) throw forbidden('Повторное прохождение теста запрещено');
    }
    const answers = req.body?.answers && typeof req.body.answers === 'object' ? req.body.answers : {};
    const r = gradeQuiz(q, answers);
    const a = await one(
      `INSERT INTO quiz_attempts(quiz_id, video_id, user_id, answers, correct, total, percent, passed, finished_at) VALUES ($1,$2,$3,$4::jsonb,$5,$6,$7,$8,now()) RETURNING *`,
      [q.id, v.id, req.user.id, JSON.stringify(answers), r.correct, r.total, r.percent, r.passed],
    );
    if (r.passed) { await markQuizPassed(req.user.id, v.id, true); await markCourseQuizPassed(req.user.id, v.id, true).catch(() => {}); }
    await xapiStatement(r.passed ? 'passed' : 'failed', { user: req.user, video: v, result: xapiResultForQuiz(r.percent, r.passed, q.pass_percent) }).catch(() => {});
    if (r.passed) await emitEvent('quiz.passed', { video: eventVideo(v), user: eventUser(req.user), percent: r.percent, passPercent: q.pass_percent, attemptId: a.id });
    return { attempt: attemptOut(a), result: { correct: r.correct, total: r.total, percent: r.percent, passed: r.passed, passPercent: q.pass_percent, details: r.details } };
  });

  // Результаты по сотрудникам (автор/модератор) + CSV
  app.get('/videos/:id/quiz/results', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await requireEditable(req, req.params.id);
    const q = await one('SELECT * FROM video_quizzes WHERE video_id = $1', [v.id]);
    if (!q) return { results: [], total: 0 };
    const { limit, offset, page } = paging(req.query, 50, 1000);
    const rows = await many(
      `SELECT DISTINCT ON (a.user_id) a.*, u.display_name, u.email, u.handle,
              (SELECT count(*) FROM quiz_attempts x WHERE x.quiz_id = a.quiz_id AND x.user_id = a.user_id)::int AS attempts,
              (SELECT bool_or(passed) FROM quiz_attempts x WHERE x.quiz_id = a.quiz_id AND x.user_id = a.user_id) AS ever_passed
       FROM quiz_attempts a JOIN users u ON u.id = a.user_id WHERE a.quiz_id = $1
       ORDER BY a.user_id, a.percent DESC, a.created_at DESC`,
      [q.id],
    );
    rows.sort((x, y) => Number(y.ever_passed) - Number(x.ever_passed) || y.percent - x.percent || x.display_name.localeCompare(y.display_name, 'ru'));
    const results = rows.map((r) => ({ userId: r.user_id, displayName: r.display_name, email: r.email, handle: r.handle, bestPercent: r.percent, correct: r.correct, total: r.total, passed: r.ever_passed, attempts: r.attempts, lastAt: r.created_at }));
    if (req.query.format === 'csv') {
      const csv = toCsv(results, [
        { title: 'Сотрудник', value: 'displayName' }, { title: 'E-mail', value: 'email' }, { title: 'Лучший результат, %', value: 'bestPercent' },
        { title: 'Верно', value: 'correct' }, { title: 'Всего вопросов', value: 'total' }, { title: 'Сдан', value: (r) => (r.passed ? 'да' : 'нет') },
        { title: 'Попыток', value: 'attempts' }, { title: 'Последняя попытка', value: (r) => new Date(r.lastAt).toLocaleString('ru-RU') },
      ]);
      return sendCsv(reply, `quiz-${v.short_id}.csv`, csv);
    }
    return { results: results.slice(offset, offset + limit), total: results.length, page, limit, quiz: { title: q.title, passPercent: q.pass_percent, questions: q.questions.length } };
  });
}
