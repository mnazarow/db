// Учебные программы (курсы): каталог, прохождение, редактор и отчёты.
import { one, many, query, tx } from '../db.js';
import { badRequest, forbidden, notFound, paging, likeEscape } from '../lib/util.js';
import { canAssign, canEditVideo, isActive, isStaff } from '../lib/access.js';
import { courseOut, courseItems, courseItemOut, courseState, recountCourse, enroll, completeItem, courseReport, COURSE_KINDS } from '../lib/courses.js';
import { audit } from '../lib/audit.js';
import { toCsv, sendCsv } from '../lib/csv.js';

const C_SELECT = `c.*, u.display_name AS owner_name, u.handle AS owner_handle,
  (SELECT count(*)::int FROM course_enrollments e WHERE e.course_id = c.id) AS enrolled_count,
  (SELECT count(*)::int FROM course_enrollments e WHERE e.course_id = c.id AND e.completed_at IS NOT NULL) AS completed_count`;
const C_FROM = `courses c LEFT JOIN users u ON u.id = c.owner_id`;

async function loadCourse(idOrSlug) {
  return one(`SELECT ${C_SELECT} FROM ${C_FROM} WHERE c.id = cv_uuid($1) OR c.slug = $1`, [String(idOrSlug)]);
}
function canEditCourse(c, user) { return !!user && (c.owner_id === user.id || isStaff(user)); }

/** Курс виден: опубликован и открыт по видимости, либо пользователь записан/назначен, либо это автор. */
async function canSeeCourse(c, user) {
  if (!c) return false;
  if (canEditCourse(c, user)) return true;
  if (c.status !== 'published') return false;
  if (c.visibility === 'public') return true;
  if (c.visibility === 'internal' && isActive(user)) return true;
  if (!user) return false;
  const own = await one(
    `SELECT 1 WHERE EXISTS (SELECT 1 FROM course_enrollments WHERE course_id = $1 AND user_id = $2)
        OR EXISTS (SELECT 1 FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
                   WHERE a.course_id = $1 AND a.status = 'active'
                     AND (t.target_type = 'all' OR t.user_id = $2 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $2))))`,
    [c.id, user.id],
  );
  return !!own;
}

function slugify(title, id) {
  const base = String(title || '').toLowerCase()
    .replace(/[^a-zа-яё0-9]+/gi, '-')
    .replace(/^-+|-+$/g, '').slice(0, 60);
  const tr = { а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'c', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya' };
  const lat = base.replace(/[а-яё]/g, (ch) => tr[ch] ?? '');
  const clean = lat.replace(/-+/g, '-').replace(/^-+|-+$/g, '');
  return `${clean || 'course'}-${String(id).slice(0, 6)}`;
}

export default async function courseRoutes(app) {
  // --- Каталог -------------------------------------------------------------------------------
  app.get('/courses', async (req) => {
    if (!req.user && !req.settings['site.public_home']) throw forbidden('Для просмотра курсов необходимо войти');
    const { limit, offset, page } = paging(req.query, 24, 60);
    const mine = req.query.mine === '1';
    const params = [];
    const where = [];
    if (mine) {
      if (!req.user) throw forbidden('Необходимо войти');
      params.push(req.user.id);
      where.push(`EXISTS (SELECT 1 FROM course_enrollments e WHERE e.course_id = c.id AND e.user_id = $${params.length})`);
      where.push(`c.status = 'published'`);
    } else if (req.query.manage === '1') {
      if (!canAssign(req.user, req.settings)) throw forbidden('Управление курсами доступно модераторам и администраторам');
      if (!isStaff(req.user)) { params.push(req.user.id); where.push(`c.owner_id = $${params.length}`); }
    } else {
      where.push(`c.status = 'published'`);
      if (isActive(req.user)) {
        params.push(req.user.id);
        where.push(`(c.visibility IN ('public','internal')
          OR EXISTS (SELECT 1 FROM course_enrollments e WHERE e.course_id = c.id AND e.user_id = $${params.length})
          OR EXISTS (SELECT 1 FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id WHERE a.course_id = c.id AND a.status = 'active'
                     AND (t.target_type = 'all' OR t.user_id = $${params.length} OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $${params.length})))))`);
      } else where.push(`c.visibility = 'public'`);
    }
    if (req.query.q) { params.push(`%${likeEscape(String(req.query.q).slice(0, 100))}%`); where.push(`(c.title ILIKE $${params.length} OR c.description ILIKE $${params.length})`); }
    const w = where.length ? `WHERE ${where.join(' AND ')}` : '';
    const total = await one(`SELECT count(*)::int AS n FROM ${C_FROM} ${w}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT ${C_SELECT} FROM ${C_FROM} ${w} ORDER BY c.status = 'published' DESC, c.updated_at DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    const out = [];
    for (const c of rows) {
      let progress;
      if (req.user) {
        const st = await courseState(c, req.user.id);
        const e = await one('SELECT completed_at FROM course_enrollments WHERE course_id = $1 AND user_id = $2', [c.id, req.user.id]);
        progress = { percent: st.percent, completed: !!e?.completed_at, enrolled: !!e };
      }
      out.push(courseOut(c, { progress }));
    }
    return { courses: out, total: total.n, page, limit };
  });

  // --- Карточка курса -------------------------------------------------------------------------
  app.get('/courses/:id', async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!(await canSeeCourse(c, req.user))) throw forbidden('У вас нет доступа к этому курсу');
    const st = await courseState(c, req.user?.id || null);
    const e = req.user ? await one('SELECT * FROM course_enrollments WHERE course_id = $1 AND user_id = $2', [c.id, req.user.id]) : null;
    const assignment = req.user ? await one(
      `SELECT a.id, a.due_at, a.certificate FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
       WHERE a.course_id = $1 AND a.status = 'active'
         AND (t.target_type = 'all' OR t.user_id = $2 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $2)))
       ORDER BY a.due_at NULLS LAST LIMIT 1`, [c.id, req.user.id]) : null;
    return {
      course: courseOut(c, {
        canEdit: canEditCourse(c, req.user),
        enrolled: !!e, completedAt: e?.completed_at || null, certificateId: e?.certificate_id || null,
        percent: st.percent, completed: st.completed, doneCount: st.doneCount, requiredCount: st.requiredCount, nextItemId: st.nextItemId,
        assignment: assignment ? { id: assignment.id, dueAt: assignment.due_at, certificate: assignment.certificate } : null,
      }),
      items: st.items,
    };
  });

  // --- Запись на курс и отметки о прохождении ---------------------------------------------------
  app.post('/courses/:id/enroll', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (c.status !== 'published') throw badRequest('Курс ещё не опубликован');
    if (!(await canSeeCourse(c, req.user))) throw forbidden('У вас нет доступа к этому курсу');
    await enroll(c.id, req.user.id);
    return { ok: true };
  });

  app.post('/courses/:id/items/:itemId/complete', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!(await canSeeCourse(c, req.user))) throw forbidden('У вас нет доступа к этому курсу');
    const it = await one('SELECT * FROM course_items WHERE id = $1 AND course_id = $2', [Number(req.params.itemId), c.id]);
    if (!it) throw notFound('Шаг не найден');
    if (it.kind === 'video') throw badRequest('Шаг с видео засчитывается по просмотру');
    if (c.sequential) {
      // Последовательный курс: нельзя отметить шаг, до которого ещё не дошли
      const st = await courseState(c, req.user.id);
      const target = st.items.find((x) => x.id === Number(it.id));
      if (target?.locked) throw badRequest('Сначала пройдите предыдущие шаги курса');
    }
    await completeItem(c, req.user.id, it.id);
    const st = await courseState(c, req.user.id);
    return { ok: true, percent: st.percent, completed: st.completed };
  });

  // --- Редактор курса --------------------------------------------------------------------------
  app.post('/courses', { preHandler: app.requireActive }, async (req) => {
    if (!canAssign(req.user, req.settings)) throw forbidden('Создавать курсы могут модераторы и администраторы');
    const b = req.body || {};
    const title = String(b.title || '').trim().slice(0, 150);
    if (!title) throw badRequest('Укажите название курса');
    const c = await one(
      `INSERT INTO courses(title, description, owner_id, visibility, sequential, required_percent, issue_certificate)
       VALUES ($1,$2,$3,$4,$5,$6,$7) RETURNING *`,
      [title, String(b.description || '').slice(0, 5000), req.user.id,
        ['public', 'internal', 'private'].includes(b.visibility) ? b.visibility : 'internal',
        b.sequential !== false, Math.min(100, Math.max(10, Number(b.requiredPercent) || 90)),
        b.issueCertificate !== false && !!req.settings['certificates.enabled']],
    );
    await query('UPDATE courses SET slug = $2 WHERE id = $1', [c.id, slugify(title, c.id)]);
    await audit(req, 'course.create', { targetType: 'course', targetId: c.id, details: { title } });
    return { course: courseOut(await loadCourse(c.id), { canEdit: true }) };
  });

  app.patch('/courses/:id', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!canEditCourse(c, req.user)) throw forbidden('Редактировать курс может автор или модератор');
    const b = req.body || {};
    const sets = []; const params = [c.id];
    const add = (col, val) => { params.push(val); sets.push(`${col} = $${params.length}`); };
    if (b.title !== undefined) { const t = String(b.title).trim().slice(0, 150); if (!t) throw badRequest('Название не может быть пустым'); add('title', t); }
    if (b.description !== undefined) add('description', String(b.description).slice(0, 5000));
    if (b.visibility !== undefined && ['public', 'internal', 'private'].includes(b.visibility)) add('visibility', b.visibility);
    if (b.sequential !== undefined) add('sequential', !!b.sequential);
    if (b.requiredPercent !== undefined) add('required_percent', Math.min(100, Math.max(10, Number(b.requiredPercent) || 90)));
    if (b.issueCertificate !== undefined) add('issue_certificate', !!b.issueCertificate);
    if (b.status !== undefined && ['draft', 'published', 'archived'].includes(b.status)) {
      if (b.status === 'published') {
        const n = await one(`SELECT count(*)::int AS n FROM course_items WHERE course_id = $1`, [c.id]);
        if (!n.n) throw badRequest('Добавьте хотя бы один шаг перед публикацией');
        if (!c.published_at) add('published_at', new Date());
      }
      add('status', b.status);
    }
    if (!sets.length) return { course: courseOut(c, { canEdit: true }) };
    add('updated_at', new Date());
    await query(`UPDATE courses SET ${sets.join(', ')} WHERE id = $1`, params);
    await audit(req, 'course.update', { targetType: 'course', targetId: c.id, details: Object.keys(b) });
    return { course: courseOut(await loadCourse(c.id), { canEdit: true }) };
  });

  app.delete('/courses/:id', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!canEditCourse(c, req.user)) throw forbidden('Удалить курс может автор или модератор');
    await query('DELETE FROM courses WHERE id = $1', [c.id]);
    await audit(req, 'course.delete', { targetType: 'course', targetId: c.id, details: { title: c.title } });
    return { ok: true };
  });

  // --- Шаги курса ------------------------------------------------------------------------------
  app.get('/courses/:id/items', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!canEditCourse(c, req.user)) throw forbidden();
    return { items: (await courseItems(c.id)).map((it) => courseItemOut(it)) };
  });

  app.post('/courses/:id/items', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!canEditCourse(c, req.user)) throw forbidden('Редактировать курс может автор или модератор');
    const b = req.body || {};
    const kind = COURSE_KINDS.includes(b.kind) ? b.kind : 'video';
    let videoId = null; let title = String(b.title || '').trim().slice(0, 200);
    if (kind === 'video') {
      const v = await one('SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [String(b.videoId || '')]);
      if (!v) throw notFound('Видео не найдено');
      if (!canEditVideo(v, req.user) && !['public', 'internal'].includes(v.visibility)) throw forbidden('Можно добавлять только свои видео или доступные сотрудникам');
      videoId = v.id; title = title || v.title;
    } else if (kind === 'material') {
      if (!b.url && !title) throw badRequest('Укажите ссылку на материал и название');
    }
    const pos = await one('SELECT COALESCE(max(position), 0) + 1 AS p FROM course_items WHERE course_id = $1', [c.id]);
    const it = await one(
      `INSERT INTO course_items(course_id, position, section, kind, video_id, title, body, url, required, require_quiz)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING *`,
      [c.id, pos.p, String(b.section || '').slice(0, 120), kind, videoId, title, String(b.body || '').slice(0, 20000),
        b.url ? String(b.url).slice(0, 500) : null, b.required !== false, !!b.requireQuiz],
    );
    await recountCourse(c.id);
    return { item: courseItemOut(await one(`SELECT ci.*, v.short_id AS video_short_id, v.title AS video_title, v.duration AS video_duration, v.thumbnail_path AS video_thumb, v.status AS video_status, v.has_quiz AS video_has_quiz FROM course_items ci LEFT JOIN videos v ON v.id = ci.video_id WHERE ci.id = $1`, [it.id])) };
  });

  app.patch('/courses/:id/items/:itemId', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c || !canEditCourse(c, req.user)) throw forbidden('Редактировать курс может автор или модератор');
    const b = req.body || {};
    const sets = []; const params = [Number(req.params.itemId), c.id];
    const add = (col, val) => { params.push(val); sets.push(`${col} = $${params.length}`); };
    if (b.title !== undefined) add('title', String(b.title).slice(0, 200));
    if (b.section !== undefined) add('section', String(b.section).slice(0, 120));
    if (b.body !== undefined) add('body', String(b.body).slice(0, 20000));
    if (b.url !== undefined) add('url', b.url ? String(b.url).slice(0, 500) : null);
    if (b.required !== undefined) add('required', !!b.required);
    if (b.requireQuiz !== undefined) add('require_quiz', !!b.requireQuiz);
    if (!sets.length) throw badRequest('Нечего изменять');
    const r = await one(`UPDATE course_items SET ${sets.join(', ')} WHERE id = $1 AND course_id = $2 RETURNING *`, params);
    if (!r) throw notFound('Шаг не найден');
    await recountCourse(c.id);
    return { ok: true };
  });

  app.delete('/courses/:id/items/:itemId', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c || !canEditCourse(c, req.user)) throw forbidden('Редактировать курс может автор или модератор');
    await query('DELETE FROM course_items WHERE id = $1 AND course_id = $2', [Number(req.params.itemId), c.id]);
    await recountCourse(c.id);
    return { ok: true };
  });

  app.post('/courses/:id/items/reorder', { preHandler: app.requireActive }, async (req) => {
    const c = await loadCourse(req.params.id);
    if (!c || !canEditCourse(c, req.user)) throw forbidden('Редактировать курс может автор или модератор');
    const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(Number).filter(Number.isFinite).slice(0, 500) : [];
    if (!ids.length) throw badRequest('Укажите порядок шагов');
    await tx(async (t) => {
      for (let i = 0; i < ids.length; i++) await t.query('UPDATE course_items SET position = $3 WHERE id = $1 AND course_id = $2', [ids[i], c.id, i + 1]);
    });
    return { ok: true };
  });

  // --- Отчёт -----------------------------------------------------------------------------------
  app.get('/courses/:id/report', { preHandler: app.requireActive }, async (req, reply) => {
    const c = await loadCourse(req.params.id);
    if (!c) throw notFound('Курс не найден');
    if (!canEditCourse(c, req.user)) throw forbidden('Отчёт доступен автору курса и модераторам');
    const rep = await courseReport(c);
    if (req.query.format === 'csv') {
      const csv = toCsv(rep.people, [
        { title: 'Сотрудник', value: 'displayName' },
        { title: 'Почта', value: 'email' },
        { title: 'Прогресс', value: (p) => `${p.percent}%` },
        { title: 'Шагов пройдено', value: (p) => `${p.doneItems} из ${p.totalItems}` },
        { title: 'Курс пройден', value: (p) => (p.completed ? 'да' : 'нет') },
        { title: 'Дата завершения', value: (p) => (p.completedAt ? new Date(p.completedAt).toLocaleString('ru-RU') : '') },
      ]);
      return sendCsv(reply, `course-${c.slug || c.id}.csv`, csv);
    }
    return { course: courseOut(c, { canEdit: true }), report: rep };
  });
}
