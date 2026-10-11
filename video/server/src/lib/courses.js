// Учебные программы (курсы): шаги (видео, материалы, текст), прохождение, сертификат.
// Курс можно назначить как видео или плейлист — через ту же таблицу assignments (kind = 'course').
import { one, many, query } from '../db.js';
import { loadSettings } from './settings.js';
import { notify } from './notify.js';
import { emitEvent, eventUser } from './events.js';
import { xapiStatement } from './xapi.js';
import { config } from '../config.js';
import { mediaUrl } from './urls.js';

export const COURSE_KINDS = ['video', 'material', 'text'];

export function courseOut(c, extra = {}) {
  return {
    id: c.id, slug: c.slug, title: c.title, description: c.description || '',
    coverUrl: mediaUrl(c.cover_path),
    visibility: c.visibility, status: c.status, sequential: !!c.sequential,
    requiredPercent: c.required_percent, issueCertificate: !!c.issue_certificate,
    itemCount: c.item_count, videoCount: c.video_count, durationSec: c.duration_sec,
    owner: c.owner_name ? { id: c.owner_id, displayName: c.owner_name, handle: c.owner_handle } : undefined,
    createdAt: c.created_at, updatedAt: c.updated_at, publishedAt: c.published_at,
    enrolledCount: c.enrolled_count !== undefined ? Number(c.enrolled_count) : undefined,
    completedCount: c.completed_count !== undefined ? Number(c.completed_count) : undefined,
    ...extra,
  };
}

export function courseItemOut(it, extra = {}) {
  return {
    id: Number(it.id), position: it.position, section: it.section || '', kind: it.kind,
    title: it.title || it.video_title || '', body: it.body || '', url: it.url || null,
    required: !!it.required, requireQuiz: !!it.require_quiz,
    video: it.video_id ? {
      id: it.video_id, shortId: it.video_short_id, title: it.video_title, duration: Number(it.video_duration) || 0,
      thumbnailUrl: mediaUrl(it.video_thumb), status: it.video_status, hasQuiz: !!it.video_has_quiz,
    } : null,
    ...extra,
  };
}

const ITEM_SELECT = `ci.*, v.short_id AS video_short_id, v.title AS video_title, v.duration AS video_duration,
  v.thumbnail_path AS video_thumb, v.status AS video_status, v.has_quiz AS video_has_quiz, v.deleted_at AS video_deleted`;

export async function courseItems(courseId) {
  return many(`SELECT ${ITEM_SELECT} FROM course_items ci LEFT JOIN videos v ON v.id = ci.video_id
               WHERE ci.course_id = $1 ORDER BY ci.position, ci.id`, [courseId]);
}

/** Пересчёт сводных полей курса (число шагов, видео, суммарная длительность). */
export async function recountCourse(courseId) {
  await query(
    `UPDATE courses SET item_count = x.n, video_count = x.nv, duration_sec = x.dur, updated_at = now() FROM (
       SELECT count(*)::int AS n,
              count(*) FILTER (WHERE ci.kind = 'video')::int AS nv,
              COALESCE(sum(CASE WHEN ci.kind = 'video' THEN COALESCE(v.duration, 0) ELSE 0 END), 0)::int AS dur
       FROM course_items ci LEFT JOIN videos v ON v.id = ci.video_id AND v.deleted_at IS NULL WHERE ci.course_id = $1
     ) x WHERE id = $1`, [courseId]);
}

/** Прогресс пользователя по курсу: шаги с отметками, блокировка следующих при последовательном прохождении. */
export async function courseState(course, userId) {
  const items = (await courseItems(course.id)).filter((it) => it.kind !== 'video' || (it.video_id && !it.video_deleted));
  const progress = userId ? await many('SELECT * FROM course_progress WHERE course_id = $1 AND user_id = $2', [course.id, userId]) : [];
  const byItem = new Map(progress.map((p) => [String(p.item_id), p]));
  let locked = false; let done = 0; let required = 0; let nextItemId = null; let sum = 0;
  const out = items.map((it) => {
    const p = byItem.get(String(it.id));
    const completed = !!p?.completed_at;
    const percent = completed ? 100 : Math.min(100, Number(p?.percent) || 0);
    const item = courseItemOut(it, { percent, completed, locked: locked && !completed });
    if (it.required) { required++; if (completed) done++; }
    sum += percent;
    if (!completed && !nextItemId && !item.locked) nextItemId = item.id;
    // Последовательное прохождение: следующий шаг закрыт, пока текущий обязательный не выполнен
    if (course.sequential && it.required && !completed) locked = true;
    return item;
  });
  const percent = items.length ? Math.round(sum / items.length) : 0;
  return { items: out, percent, completed: required > 0 && done >= required, doneCount: done, requiredCount: required, nextItemId };
}

/** Записать пользователя на курс (самостоятельно или при назначении). */
export async function enroll(courseId, userId) {
  await query('INSERT INTO course_enrollments(course_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [courseId, userId]);
  return one('SELECT * FROM course_enrollments WHERE course_id = $1 AND user_id = $2', [courseId, userId]);
}

async function saveItemProgress(courseId, userId, itemId, percent, completed) {
  await query(
    `INSERT INTO course_progress(course_id, user_id, item_id, percent, completed_at, updated_at)
     VALUES ($1,$2,$3,$4, CASE WHEN $5 THEN now() END, now())
     ON CONFLICT (course_id, user_id, item_id) DO UPDATE SET
       percent = greatest(course_progress.percent, EXCLUDED.percent),
       completed_at = COALESCE(course_progress.completed_at, EXCLUDED.completed_at),
       updated_at = now()`,
    [courseId, userId, itemId, Math.max(0, Math.min(100, Math.round(percent))), !!completed],
  );
}

/** Отметить пройденным шаг-материал или текст (кнопка «Изучил»). */
export async function completeItem(course, userId, itemId) {
  const it = await one('SELECT * FROM course_items WHERE id = $1 AND course_id = $2', [Number(itemId), course.id]);
  if (!it) return null;
  await enroll(course.id, userId);
  await saveItemProgress(course.id, userId, it.id, 100, true);
  await checkCourseCompleted(course, userId);
  return it;
}

/** Прогресс просмотра видео → прогресс по всем курсам, где это видео является шагом. */
export async function updateCourseProgressForVideo(userId, video, maxPosition) {
  const items = await many(
    `SELECT ci.*, c.id AS c_id, c.title AS c_title, c.sequential, c.required_percent, c.issue_certificate, c.status AS c_status
     FROM course_items ci JOIN courses c ON c.id = ci.course_id
     WHERE ci.video_id = $1 AND ci.kind = 'video' AND c.status = 'published'`, [video.id]);
  if (!items.length) return;
  const duration = Number(video.duration) || 0;
  const percent = duration > 0 ? Math.min(100, Math.round((Number(maxPosition) / duration) * 100)) : 0;
  for (const it of items) {
    const enrolled = await one('SELECT 1 FROM course_enrollments WHERE course_id = $1 AND user_id = $2', [it.c_id, userId]);
    if (!enrolled) continue; // считаем прогресс только тем, кто записан на курс или кому он назначен
    const quizOk = it.require_quiz ? await quizPassed(it.video_id, userId) : true;
    const done = percent >= (it.required_percent || 90) && quizOk;
    await saveItemProgress(it.c_id, userId, it.id, percent, done);
    if (done) {
      const course = await one('SELECT * FROM courses WHERE id = $1', [it.c_id]);
      await checkCourseCompleted(course, userId);
    }
  }
}

/** После сдачи теста: возможно, шаг курса теперь засчитан. */
export async function markCourseQuizPassed(userId, videoId, passed) {
  if (!passed) return;
  const items = await many(
    `SELECT ci.*, c.required_percent FROM course_items ci JOIN courses c ON c.id = ci.course_id
     WHERE ci.video_id = $1 AND ci.kind = 'video' AND ci.require_quiz AND c.status = 'published'`, [videoId]);
  for (const it of items) {
    const p = await one('SELECT * FROM course_progress WHERE course_id = $1 AND user_id = $2 AND item_id = $3', [it.course_id, userId, it.id]);
    if (!p) continue;
    if ((p.percent || 0) >= (it.required_percent || 90) && !p.completed_at) {
      await saveItemProgress(it.course_id, userId, it.id, p.percent || 0, true);
      const course = await one('SELECT * FROM courses WHERE id = $1', [it.course_id]);
      await checkCourseCompleted(course, userId);
    }
  }
}

async function quizPassed(videoId, userId) {
  const q = await one('SELECT id FROM video_quizzes WHERE video_id = $1 AND jsonb_array_length(questions) > 0', [videoId]);
  if (!q) return true;
  const ok = await one('SELECT 1 FROM quiz_attempts WHERE quiz_id = $1 AND user_id = $2 AND passed LIMIT 1', [q.id, userId]);
  return !!ok;
}

/** Все обязательные шаги пройдены → отметка о завершении, уведомление, сертификат, событие. */
export async function checkCourseCompleted(course, userId) {
  if (!course) return null;
  const st = await courseState(course, userId);
  if (!st.completed) return null;
  const enr = await one('SELECT * FROM course_enrollments WHERE course_id = $1 AND user_id = $2', [course.id, userId]);
  if (enr?.completed_at) return enr;
  await query('UPDATE course_enrollments SET completed_at = now() WHERE course_id = $1 AND user_id = $2 AND completed_at IS NULL', [course.id, userId]);
  const user = await one('SELECT id, email, display_name, role, status FROM users WHERE id = $1', [userId]);
  await notify(userId, { type: 'course_completed', title: 'Курс пройден', body: course.title, link: `/course/${course.slug || course.id}`, data: { courseId: course.id } });
  const cert = course.issue_certificate ? await issueCourseCertificate(course, userId, st) : null;
  try {
    await xapiStatement('completed', {
      user, object: { id: `${config.baseUrl}/course/${course.id}`, name: course.title, type: 'http://adlnet.gov/expapi/activities/course' },
      result: { completion: true, success: true },
    });
  } catch { /* xAPI необязателен */ }
  await emitEvent('course.completed', {
    course: { id: course.id, title: course.title, url: `/course/${course.slug || course.id}` },
    user: eventUser(user), certificateId: cert?.id || null,
  });
  return one('SELECT * FROM course_enrollments WHERE course_id = $1 AND user_id = $2', [course.id, userId]);
}

async function issueCourseCertificate(course, userId, st) {
  const s = await loadSettings();
  if (!s['certificates.enabled']) return null;
  const existing = await one('SELECT * FROM certificates WHERE course_id = $1 AND user_id = $2', [course.id, userId]);
  if (existing) return existing;
  const seq = await one("SELECT nextval('certificates_number_seq')::int AS n");
  const number = `CV-${new Date().getFullYear()}-${String(seq.n).padStart(5, '0')}`;
  const videos = st.items.filter((i) => i.kind === 'video' && i.video).map((i) => ({ id: i.video.id, title: i.video.title }));
  const details = {
    percent: st.percent, durationSec: course.duration_sec, videos, kind: 'course',
    issuerName: s['certificates.issuer_name'] || s['site.name'], issuerTitle: s['certificates.issuer_title'],
    text: s['certificates.text'], siteName: s['site.name'], courseTitle: course.title,
  };
  const c = await one(
    `INSERT INTO certificates(number, user_id, course_id, title, details) VALUES ($1,$2,$3,$4,$5::jsonb) RETURNING *`,
    [number, userId, course.id, course.title, JSON.stringify(details)],
  );
  await query('UPDATE course_enrollments SET certificate_id = $3 WHERE course_id = $1 AND user_id = $2', [course.id, userId, c.id]);
  await notify(userId, { type: 'certificate', title: 'Выдан сертификат о прохождении курса', body: `${course.title} — № ${number}`, link: `/cert/${c.id}`, data: { certificateId: c.id, courseId: course.id } });
  const holder = await one('SELECT id, email, display_name, role, status FROM users WHERE id = $1', [userId]);
  await emitEvent('certificate.issued', { certificate: { id: c.id, number, title: course.title, courseId: course.id, issuedAt: c.issued_at, url: `/cert/${c.id}`, details }, user: eventUser(holder) });
  return c;
}

/** Люди курса: записавшиеся сами + адресаты назначений этого курса. */
export async function courseAudience(courseId) {
  return many(
    `SELECT DISTINCT u.id, u.display_name, u.email FROM users u
     WHERE u.status = 'active' AND u.deleted_at IS NULL AND (
       EXISTS (SELECT 1 FROM course_enrollments e WHERE e.course_id = $1 AND e.user_id = u.id)
       OR EXISTS (
         SELECT 1 FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
         WHERE a.course_id = $1 AND a.status = 'active'
           AND (t.target_type = 'all' OR t.user_id = u.id OR (t.target_type = 'group' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = t.group_id AND gm.user_id = u.id)))
       ))
     ORDER BY u.display_name`, [courseId]);
}

/** Отчёт по курсу: строка на человека с процентом и числом пройденных шагов. */
export async function courseReport(course) {
  const users = await courseAudience(course.id);
  if (!users.length) return { people: [], total: 0, completed: 0 };
  const ids = users.map((u) => u.id);
  const items = (await courseItems(course.id)).filter((it) => it.kind !== 'video' || (it.video_id && !it.video_deleted));
  const requiredIds = items.filter((it) => it.required).map((it) => String(it.id));
  const rows = await many('SELECT * FROM course_progress WHERE course_id = $1 AND user_id = ANY($2::uuid[])', [course.id, ids]);
  const enr = await many('SELECT * FROM course_enrollments WHERE course_id = $1 AND user_id = ANY($2::uuid[])', [course.id, ids]);
  const enrBy = new Map(enr.map((e) => [String(e.user_id), e]));
  const byUser = new Map();
  for (const r of rows) {
    const k = String(r.user_id);
    if (!byUser.has(k)) byUser.set(k, []);
    byUser.get(k).push(r);
  }
  const people = users.map((u) => {
    const list = byUser.get(String(u.id)) || [];
    const doneIds = new Set(list.filter((r) => r.completed_at).map((r) => String(r.item_id)));
    const doneRequired = requiredIds.filter((id) => doneIds.has(id)).length;
    const sum = items.reduce((n, it) => {
      const r = list.find((x) => String(x.item_id) === String(it.id));
      return n + (r?.completed_at ? 100 : Number(r?.percent) || 0);
    }, 0);
    const e = enrBy.get(String(u.id));
    const lastAt = list.reduce((m, r) => (!m || new Date(r.updated_at) > new Date(m) ? r.updated_at : m), null);
    return {
      id: u.id, displayName: u.display_name, email: u.email,
      percent: items.length ? Math.round(sum / items.length) : 0,
      doneItems: doneRequired, totalItems: requiredIds.length,
      completed: !!e?.completed_at, completedAt: e?.completed_at || null,
      enrolled: !!e, certificateId: e?.certificate_id || null, lastAt,
    };
  }).sort((a, b) => Number(a.completed) - Number(b.completed) || b.percent - a.percent || a.displayName.localeCompare(b.displayName, 'ru'));
  return { people, total: people.length, completed: people.filter((p) => p.completed).length, items: items.length };
}
