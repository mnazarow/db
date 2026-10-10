// Назначения «к обязательному просмотру»: создание авторами/модераторами, отчёты, раздел «Назначено вам».
import { one, many, query, tx } from '../db.js';
import { badRequest, forbidden, notFound, paging } from '../lib/util.js';
import { canAssign, canEditVideo, isStaff } from '../lib/access.js';
import { assignmentOut, assignmentReport, assignmentSummaries, assignmentTargets, assignmentVideos, notifyAssigned, remindAssignment, seedProgress } from '../lib/assignments.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { audit } from '../lib/audit.js';
import { videoCard } from '../lib/serialize.js';
import { config } from '../config.js';
import { emitEvent } from '../lib/events.js';

const A_SELECT = `a.*, u.display_name AS creator_name, v.short_id AS video_short_id, v.thumbnail_path, c.title AS course_title, c.slug AS course_slug, c.item_count AS course_item_count`;
const A_FROM = `assignments a LEFT JOIN users u ON u.id = a.created_by LEFT JOIN videos v ON v.id = a.video_id LEFT JOIN courses c ON c.id = a.course_id`;

async function loadAssignment(id) {
  return one(`SELECT ${A_SELECT} FROM ${A_FROM} WHERE a.id = $1`, [id]);
}
function canManage(a, user) { return isStaff(user) || (user && a.created_by === user.id); }

export default async function assignmentRoutes(app) {
  // --- Мои назначения (для сотрудника) ---------------------------------------------------
  app.get('/assignments/mine', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(
      `SELECT DISTINCT ${A_SELECT} FROM ${A_FROM} JOIN assignment_targets t ON t.assignment_id = a.id
       WHERE a.status = 'active' AND (a.kind <> 'video' OR v.deleted_at IS NULL)
         AND (t.target_type = 'all' OR t.user_id = $1 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $1)))
       ORDER BY a.due_at NULLS LAST, a.created_at DESC LIMIT 200`,
      [req.user.id],
    );
    const out = [];
    for (const a of rows) {
      const videos = await assignmentVideos(a);
      const prog = await many('SELECT video_id, percent, completed_at, quiz_passed FROM assignment_progress WHERE assignment_id = $1 AND user_id = $2', [a.id, req.user.id]);
      const byVideo = new Map(prog.map((p) => [p.video_id, p]));
      const items = videos.map((v) => ({ id: v.id, shortId: v.short_id, title: v.title, duration: Number(v.duration), percent: byVideo.get(v.id)?.percent || 0, completed: !!byVideo.get(v.id)?.completed_at, quizPassed: byVideo.get(v.id)?.quiz_passed ?? null }));
      const percent = items.length ? Math.round(items.reduce((s, i) => s + i.percent, 0) / items.length) : 0;
      const completed = items.length > 0 && items.every((i) => i.completed);
      out.push(assignmentOut(a, { items, percent, completed, overdue: !completed && a.due_at && new Date(a.due_at) < new Date() }));
    }
    return { assignments: out, pending: out.filter((a) => !a.completed).length };
  });

  // --- Список созданных назначений (автор видит свои, модераторы — все) ----------------
  app.get('/assignments', { preHandler: app.requireActive }, async (req) => {
    if (!canAssign(req.user, req.settings)) throw forbidden('Назначения вам недоступны');
    const { limit, offset, page } = paging(req.query, 30, 100);
    const mineOnly = !isStaff(req.user) || req.query.mine === '1';
    const status = ['active', 'closed'].includes(req.query.status) ? req.query.status : null;
    const where = [];
    const params = [];
    if (mineOnly) { params.push(req.user.id); where.push(`a.created_by = $${params.length}`); }
    if (status) { params.push(status); where.push(`a.status = $${params.length}`); }
    if (req.query.videoId) { params.push(String(req.query.videoId)); where.push(`a.video_id = cv_uuid($${params.length})`); }
    where.push(`(a.kind <> 'video' OR v.deleted_at IS NULL)`);
    const w = where.length ? `WHERE ${where.join(' AND ')}` : '';
    const total = await one(`SELECT count(*)::int AS n FROM ${A_FROM} ${w}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT ${A_SELECT} FROM ${A_FROM} ${w} ORDER BY a.created_at DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    // Сводка и адресаты — пачкой на всю страницу (раньше было по три запроса на каждую строку)
    const ids = rows.map((a) => a.id);
    const [sum, tmap] = await Promise.all([assignmentSummaries(rows), assignmentTargets(ids)]);
    const out = rows.map((a) => {
      const r = sum.get(a.id) || { total: 0, completed: 0, overdue: 0 };
      return assignmentOut(a, { total: r.total, completed: r.completed, overdue: r.overdue, targets: (tmap.get(String(a.id)) || []).map(targetOut) });
    });
    return { assignments: out, total: total.n, page, limit };
  });

  // --- Создать ---------------------------------------------------------------------------
  app.post('/assignments', { preHandler: app.requireActive }, async (req) => {
    if (!canAssign(req.user, req.settings)) throw forbidden('Назначать видео к просмотру могут модераторы и администраторы');
    const b = req.body || {};
    const kind = b.courseId ? 'course' : (b.playlistId ? 'playlist' : 'video');
    let title = '';
    if (kind === 'course') {
      const c = await one('SELECT * FROM courses WHERE id = cv_uuid($1) OR slug = $1', [String(b.courseId)]);
      if (!c) throw notFound('Курс не найден');
      if (c.status !== 'published') throw badRequest('Сначала опубликуйте курс');
      if (c.owner_id !== req.user.id && !isStaff(req.user) && c.visibility === 'private') throw forbidden('Курс недоступен для назначения');
      b.courseId = c.id; title = c.title;
    } else if (kind === 'video') {
      const v = await one('SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [String(b.videoId || '')]);
      if (!v) throw notFound('Видео не найдено');
      if (!canEditVideo(v, req.user) && !['public', 'internal'].includes(v.visibility)) throw forbidden('Можно назначать только свои видео или видео, доступные сотрудникам');
      b.videoId = v.id; title = v.title;
    } else {
      const p = await one('SELECT * FROM playlists WHERE id = cv_uuid($1)', [String(b.playlistId)]);
      if (!p) throw notFound('Плейлист не найден');
      if (p.owner_id !== req.user.id && !isStaff(req.user) && !['public', 'internal'].includes(p.visibility)) throw forbidden('Плейлист недоступен для назначения');
      // Видео внутри плейлиста получают доступ по назначению, поэтому проверяем каждое:
      // чужие приватные видео назначать нельзя (иначе назначение — обход доступа к ним)
      if (!isStaff(req.user)) {
        const hidden = await many(
          `SELECT v.id, v.title FROM playlist_items pi JOIN videos v ON v.id = pi.video_id
           WHERE pi.playlist_id = $1 AND v.deleted_at IS NULL AND v.owner_id <> $2 AND v.visibility NOT IN ('public','internal') LIMIT 5`,
          [p.id, req.user.id],
        );
        if (hidden.length) throw forbidden(`В плейлисте есть чужие видео с ограниченным доступом (${hidden.map((x) => x.title).join(', ')}) — назначить его нельзя`);
      }
      title = p.title;
    }
    const targets = Array.isArray(b.targets) ? b.targets.slice(0, 500) : [];
    if (!targets.length) throw badRequest('Укажите, кому назначить: сотрудников, группы или всех');
    if (targets.some((t) => t.type === 'all') && !isStaff(req.user)) throw forbidden('Назначить всем сотрудникам может только модератор или администратор');
    const due = b.dueAt ? new Date(b.dueAt) : null;
    if (due && Number.isNaN(due.getTime())) throw badRequest('Некорректная дата срока');
    const percent = Math.min(100, Math.max(10, Number(b.requiredPercent) || req.settings['assignments.default_percent'] || 90));
    const remind = Math.min(30, Math.max(0, Number(b.remindDays ?? req.settings['assignments.remind_days'] ?? 3)));
    const a = await tx(async (c) => {
      const row = await c.one(
        `INSERT INTO assignments(kind, video_id, playlist_id, course_id, title, note, created_by, due_at, required_percent, require_quiz, remind_days, attention_check_min, certificate)
         VALUES ($1,$2,$3,$13,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING *`,
        [kind, kind === 'video' ? b.videoId : null, kind === 'playlist' ? b.playlistId : null, title, String(b.note || '').slice(0, 2000), req.user.id, due, percent, !!b.requireQuiz, remind,
          Math.min(60, Math.max(0, Number(b.attentionCheckMin) || 0)), !!b.certificate && !!req.settings['certificates.enabled'], kind === 'course' ? b.courseId : null],
      );
      for (const t of targets) {
        if (t.type === 'all') await c.query(`INSERT INTO assignment_targets(assignment_id, target_type) VALUES ($1,'all')`, [row.id]);
        else if (t.type === 'group' && t.id) await c.query(`INSERT INTO assignment_targets(assignment_id, target_type, group_id) SELECT $1, 'group', id FROM groups WHERE id = cv_uuid($2)`, [row.id, String(t.id)]);
        else if (t.type === 'user' && t.id) {
          const val = String(t.id).trim().toLowerCase().replace(/^@/, '');
          await c.query(`INSERT INTO assignment_targets(assignment_id, target_type, user_id) SELECT $1, 'user', id FROM users WHERE (id = cv_uuid($2) OR email = $2 OR handle = $2) AND deleted_at IS NULL LIMIT 1`, [row.id, val]);
        }
      }
      return row;
    });
    const n = await one('SELECT count(*)::int AS n FROM assignment_targets WHERE assignment_id = $1', [a.id]);
    if (!n.n) { await query('DELETE FROM assignments WHERE id = $1', [a.id]); throw badRequest('Ни один адресат не найден'); }
    await seedProgress(a); // уже посмотревшим засчитывается
    const notified = await notifyAssigned(a, req.user.id);
    await audit(req, 'assignment.create', { targetType: 'assignment', targetId: a.id, details: { title, targets: n.n, notified } });
    const full = await loadAssignment(a.id);
    await emitEvent('assignment.created', { assignment: { id: a.id, title, kind: a.kind, videoId: a.video_id, playlistId: a.playlist_id, dueAt: a.due_at, requiredPercent: a.required_percent, requireQuiz: a.require_quiz, targets: n.n, notified }, byUserId: req.user.id });
    return { assignment: assignmentOut(full, { notified }) };
  });

  // --- Карточка и отчёт --------------------------------------------------------------------
  app.get('/assignments/:id', { preHandler: app.requireActive }, async (req, reply) => {
    const a = await loadAssignment(req.params.id);
    if (!a) throw notFound('Назначение не найдено');
    if (!canManage(a, req.user)) throw forbidden();
    const report = await assignmentReport(a);
    const targets = await many('SELECT t.*, g.name AS group_name, u.display_name AS user_name FROM assignment_targets t LEFT JOIN groups g ON g.id = t.group_id LEFT JOIN users u ON u.id = t.user_id WHERE t.assignment_id = $1', [a.id]);
    if (req.query.format === 'csv') {
      return sendCsv(reply, `assignment-${a.id.slice(0, 8)}.csv`, toCsv(report.people, [
        { title: 'Сотрудник', value: 'displayName' }, { title: 'E-mail', value: 'email' }, { title: 'Просмотрено, %', value: 'percent' },
        { title: 'Видео просмотрено', value: (p) => `${p.completedVideos} из ${p.totalVideos}` }, { title: 'Выполнено', value: (p) => (p.completed ? 'да' : 'нет') },
        { title: 'Тест', value: (p) => (p.quizPassed === null ? '' : p.quizPassed ? 'сдан' : 'не сдан') }, { title: 'Последняя активность', value: 'lastAt' },
      ]));
    }
    return { assignment: assignmentOut(a, { targets: targets.map(targetOut) }), report };
  });

  app.patch('/assignments/:id', { preHandler: app.requireActive }, async (req) => {
    const a = await loadAssignment(req.params.id);
    if (!a) throw notFound('Назначение не найдено');
    if (!canManage(a, req.user)) throw forbidden();
    const b = req.body || {};
    const due = b.dueAt === undefined ? a.due_at : (b.dueAt ? new Date(b.dueAt) : null);
    const status = ['active', 'closed'].includes(b.status) ? b.status : a.status;
    await query('UPDATE assignments SET note = $2, due_at = $3, status = $4, required_percent = $5, require_quiz = $6, attention_check_min = $7, certificate = $8, reminded_at = CASE WHEN $3 IS DISTINCT FROM due_at THEN NULL ELSE reminded_at END, updated_at = now() WHERE id = $1',
      [a.id, b.note !== undefined ? String(b.note).slice(0, 2000) : a.note, due, status, Math.min(100, Math.max(10, Number(b.requiredPercent) || a.required_percent)), b.requireQuiz !== undefined ? !!b.requireQuiz : a.require_quiz,
        b.attentionCheckMin !== undefined ? Math.min(60, Math.max(0, Number(b.attentionCheckMin) || 0)) : a.attention_check_min, b.certificate !== undefined ? !!b.certificate : a.certificate]);
    await audit(req, 'assignment.update', { targetType: 'assignment', targetId: a.id, details: Object.keys(b) });
    return { assignment: assignmentOut(await loadAssignment(a.id)) };
  });

  app.delete('/assignments/:id', { preHandler: app.requireActive }, async (req) => {
    const a = await loadAssignment(req.params.id);
    if (!a) throw notFound('Назначение не найдено');
    if (!canManage(a, req.user)) throw forbidden();
    await query('DELETE FROM assignments WHERE id = $1', [a.id]);
    await audit(req, 'assignment.delete', { targetType: 'assignment', targetId: a.id });
    return { ok: true };
  });

  // Напомнить сейчас (всем, кто ещё не выполнил)
  app.post('/assignments/:id/remind', { preHandler: app.requireActive }, async (req) => {
    const a = await loadAssignment(req.params.id);
    if (!a) throw notFound('Назначение не найдено');
    if (!canManage(a, req.user)) throw forbidden();
    const sent = await remindAssignment(a);
    await audit(req, 'assignment.remind', { targetType: 'assignment', targetId: a.id, details: { sent } });
    return { ok: true, sent };
  });

  // --- Сертификаты ---------------------------------------------------------------------------
  app.get('/me/certificates', { preHandler: app.requireActive }, async (req) => {
    const { certificateOut } = await import('../lib/certificates.js');
    const rows = await many('SELECT c.*, v.short_id AS video_short_id FROM certificates c LEFT JOIN videos v ON v.id = c.video_id WHERE c.user_id = $1 ORDER BY c.issued_at DESC', [req.user.id]);
    return { certificates: rows.map((c) => certificateOut(c)) };
  });
  // Проверка подлинности по ссылке (без входа): номер, кому и когда выдан
  app.get('/certificates/:id', async (req) => {
    const { certificateOut } = await import('../lib/certificates.js');
    const c = await one('SELECT c.*, u.display_name AS user_name, u.email AS user_email, v.short_id AS video_short_id FROM certificates c JOIN users u ON u.id = c.user_id LEFT JOIN videos v ON v.id = c.video_id WHERE c.id = cv_uuid($1)', [String(req.params.id)]);
    if (!c) throw notFound('Сертификат не найден');
    const isOwner = req.user && (req.user.id === c.user_id || isStaff(req.user));
    return { certificate: certificateOut(c, { user: { displayName: c.user_name, email: isOwner ? c.user_email : undefined }, extra: { valid: !c.revoked_at, canPrint: !!isOwner } }) };
  });
  app.get('/assignments/:id/certificates', { preHandler: app.requireActive }, async (req, reply) => {
    const a = await loadAssignment(req.params.id);
    if (!a) throw notFound('Назначение не найдено');
    if (!canManage(a, req.user)) throw forbidden();
    const { certificateOut } = await import('../lib/certificates.js');
    const rows = await many('SELECT c.*, u.display_name AS user_name, u.email AS user_email FROM certificates c JOIN users u ON u.id = c.user_id WHERE c.assignment_id = $1 ORDER BY c.issued_at', [a.id]);
    if (req.query.format === 'csv') return sendCsv(reply, `certificates-${a.id.slice(0, 8)}.csv`, toCsv(rows, [{ title: 'Номер', value: 'number' }, { title: 'Сотрудник', value: 'user_name' }, { title: 'E-mail', value: 'user_email' }, { title: 'Выдан', value: (c) => new Date(c.issued_at).toLocaleString('ru-RU') }, { title: 'Ссылка', value: (c) => `${config.baseUrl}/cert/${c.id}` }]));
    return { certificates: rows.map((c) => certificateOut(c)) };
  });
  app.post('/certificates/:id/revoke', { preHandler: app.requireStaff }, async (req) => {
    const c = await one('UPDATE certificates SET revoked_at = now() WHERE id = cv_uuid($1) RETURNING id', [String(req.params.id)]);
    if (!c) throw notFound('Сертификат не найден');
    await audit(req, 'certificate.revoke', { targetType: 'certificate', targetId: c.id });
    return { ok: true };
  });

  // Видео, назначенные пользователю, в виде карточек (для главной)
  app.get('/assignments/mine/videos', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(
      `SELECT DISTINCT v.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar, u.subscriber_count AS owner_subscribers, c.name AS category_name, c.slug AS category_slug,
              a.due_at, a.id AS assignment_id, a.title AS assignment_title, p.percent AS progress_percent, p.completed_at
       FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
       JOIN videos v ON (v.id = a.video_id OR v.id IN (SELECT video_id FROM playlist_items WHERE playlist_id = a.playlist_id))
       JOIN users u ON u.id = v.owner_id LEFT JOIN categories c ON c.id = v.category_id
       LEFT JOIN assignment_progress p ON p.assignment_id = a.id AND p.user_id = $1 AND p.video_id = v.id
       WHERE a.status = 'active' AND v.deleted_at IS NULL AND v.status = 'ready'
         AND (t.target_type = 'all' OR t.user_id = $1 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $1)))
         AND p.completed_at IS NULL
       ORDER BY a.due_at NULLS LAST LIMIT 12`,
      [req.user.id],
    );
    return { videos: rows.map((r) => videoCard(r, { dueAt: r.due_at, assignmentId: r.assignment_id, assignmentTitle: r.assignment_title, progressPercent: r.progress_percent || 0 })) };
  });
}

function targetOut(t) {
  return { type: t.target_type, userId: t.user_id, groupId: t.group_id, name: t.target_type === 'all' ? 'Все сотрудники' : t.target_type === 'group' ? t.group_name : t.user_name };
}
