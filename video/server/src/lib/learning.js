// Сводка по обучению для руководителя: курсы, назначения и сотрудники в разрезе групп.
import { one, many } from '../db.js';

/** Прогресс по каждому назначению и сотруднику одним запросом (видео, плейлисты и курсы). */
async function assignmentMatrix(ids) {
  if (!ids.length) return [];
  return many(
    `WITH targets AS (
       SELECT t.assignment_id, u.id AS user_id FROM assignment_targets t
       JOIN users u ON u.status = 'active' AND u.deleted_at IS NULL
         AND (t.target_type = 'all' OR t.user_id = u.id OR (t.target_type = 'group' AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = t.group_id AND gm.user_id = u.id)))
       WHERE t.assignment_id = ANY($1::uuid[]) GROUP BY 1, 2
     ), vids AS (
       SELECT a.id AS assignment_id, v.id AS video_id FROM assignments a JOIN videos v ON v.id = a.video_id AND v.deleted_at IS NULL
         WHERE a.id = ANY($1::uuid[]) AND a.kind = 'video'
       UNION ALL
       SELECT a.id, v.id FROM assignments a JOIN playlist_items pi ON pi.playlist_id = a.playlist_id
         JOIN videos v ON v.id = pi.video_id AND v.deleted_at IS NULL AND v.status = 'ready'
         WHERE a.id = ANY($1::uuid[]) AND a.kind = 'playlist'
       UNION ALL
       SELECT a.id, v.id FROM assignments a JOIN course_items ci ON ci.course_id = a.course_id AND ci.kind = 'video' AND ci.required
         JOIN videos v ON v.id = ci.video_id AND v.deleted_at IS NULL AND v.status = 'ready'
         WHERE a.id = ANY($1::uuid[]) AND a.kind = 'course'
     ), need AS (SELECT assignment_id, count(DISTINCT video_id)::int AS n FROM vids GROUP BY 1),
     done AS (
       SELECT p.assignment_id, p.user_id, count(DISTINCT p.video_id)::int AS n FROM assignment_progress p
       JOIN vids ON vids.assignment_id = p.assignment_id AND vids.video_id = p.video_id
       WHERE p.completed_at IS NOT NULL GROUP BY 1, 2
     )
     SELECT t.assignment_id, t.user_id, COALESCE(d.n, 0) AS done, COALESCE(nd.n, 0) AS need
     FROM targets t LEFT JOIN done d ON d.assignment_id = t.assignment_id AND d.user_id = t.user_id
     LEFT JOIN need nd ON nd.assignment_id = t.assignment_id`,
    [ids],
  );
}

/** Прогресс по курсам: записавшиеся и число пройденных обязательных шагов. */
async function courseMatrix(ids) {
  if (!ids.length) return [];
  return many(
    `SELECT e.course_id, e.user_id, (e.completed_at IS NOT NULL) AS completed,
       (SELECT count(*)::int FROM course_items ci WHERE ci.course_id = e.course_id AND ci.required) AS need,
       (SELECT count(*)::int FROM course_progress cp JOIN course_items ci2 ON ci2.id = cp.item_id AND ci2.required
          WHERE cp.course_id = e.course_id AND cp.user_id = e.user_id AND cp.completed_at IS NOT NULL) AS done
     FROM course_enrollments e WHERE e.course_id = ANY($1::uuid[])`,
    [ids],
  );
}

/**
 * Сводка: курсы, назначения и сотрудники.
 * groupId — ограничить состав сотрудников одной группой.
 */
export async function learningOverview({ groupId = null, limit = 500 } = {}) {
  const courses = await many(
    `SELECT c.id, c.title, c.slug, c.status, c.item_count,
            (SELECT count(*)::int FROM course_enrollments e WHERE e.course_id = c.id) AS enrolled,
            (SELECT count(*)::int FROM course_enrollments e WHERE e.course_id = c.id AND e.completed_at IS NOT NULL) AS completed
     FROM courses c WHERE c.status = 'published' ORDER BY c.updated_at DESC LIMIT 200`);
  const assignments = await many(
    `SELECT a.id, a.title, a.kind, a.due_at, a.status, a.created_at FROM assignments a
     WHERE a.status = 'active' ORDER BY a.due_at NULLS LAST, a.created_at DESC LIMIT 200`);

  const [am, cm] = await Promise.all([
    assignmentMatrix(assignments.map((a) => a.id)),
    courseMatrix(courses.map((c) => c.id)),
  ]);

  const users = await many(
    `SELECT u.id, u.display_name, u.email,
            (SELECT string_agg(g.name, ', ' ORDER BY g.name) FROM group_members gm JOIN groups g ON g.id = gm.group_id WHERE gm.user_id = u.id) AS groups
     FROM users u WHERE u.status = 'active' AND u.deleted_at IS NULL
       AND ($1::uuid IS NULL OR EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id AND gm.group_id = $1))
     ORDER BY u.display_name LIMIT $2`, [groupId, limit]);
  const userSet = new Set(users.map((u) => String(u.id)));

  const now = Date.now();
  const dueBy = new Map(assignments.map((a) => [String(a.id), a.due_at]));
  const asgStats = new Map(assignments.map((a) => [String(a.id), { total: 0, completed: 0, overdue: 0 }]));
  const perUser = new Map(users.map((u) => [String(u.id), {
    id: u.id, displayName: u.display_name, email: u.email, groups: u.groups || '',
    assignmentsTotal: 0, assignmentsDone: 0, assignmentsOverdue: 0, coursesTotal: 0, coursesDone: 0, percentSum: 0, percentCount: 0,
  }]));

  for (const r of am) {
    const st = asgStats.get(String(r.assignment_id));
    const done = Number(r.need) > 0 && Number(r.done) >= Number(r.need);
    const due = dueBy.get(String(r.assignment_id));
    const overdue = !done && due && new Date(due).getTime() < now;
    if (st) { st.total++; if (done) st.completed++; if (overdue) st.overdue++; }
    const pu = perUser.get(String(r.user_id));
    if (pu && userSet.has(String(r.user_id))) {
      pu.assignmentsTotal++;
      if (done) pu.assignmentsDone++;
      if (overdue) pu.assignmentsOverdue++;
      pu.percentSum += Number(r.need) ? Math.round((Number(r.done) / Number(r.need)) * 100) : 0;
      pu.percentCount++;
    }
  }
  for (const r of cm) {
    const pu = perUser.get(String(r.user_id));
    if (!pu) continue;
    pu.coursesTotal++;
    if (r.completed) pu.coursesDone++;
    pu.percentSum += Number(r.need) ? Math.round((Number(r.done) / Number(r.need)) * 100) : 0;
    pu.percentCount++;
  }

  const people = [...perUser.values()]
    .map((p) => ({ ...p, percent: p.percentCount ? Math.round(p.percentSum / p.percentCount) : null }))
    .filter((p) => p.assignmentsTotal || p.coursesTotal)
    .sort((a, b) => (b.assignmentsOverdue - a.assignmentsOverdue) || (a.percent ?? 101) - (b.percent ?? 101) || a.displayName.localeCompare(b.displayName, 'ru'));

  const groups = await many('SELECT id, name, member_count FROM groups ORDER BY name LIMIT 200');
  const totals = {
    people: people.length,
    overdue: people.filter((p) => p.assignmentsOverdue > 0).length,
    coursesCompleted: cm.filter((r) => r.completed).length,
    assignments: assignments.length,
    courses: courses.length,
  };
  return {
    totals,
    groups: groups.map((g) => ({ id: g.id, name: g.name, memberCount: g.member_count })),
    courses: courses.map((c) => ({
      id: c.id, title: c.title, slug: c.slug, itemCount: c.item_count,
      enrolled: Number(c.enrolled), completed: Number(c.completed),
      percent: Number(c.enrolled) ? Math.round((Number(c.completed) / Number(c.enrolled)) * 100) : 0,
    })),
    assignments: assignments.map((a) => {
      const st = asgStats.get(String(a.id)) || { total: 0, completed: 0, overdue: 0 };
      return {
        id: a.id, title: a.title, kind: a.kind, dueAt: a.due_at,
        total: st.total, completed: st.completed, overdue: st.overdue,
        percent: st.total ? Math.round((st.completed / st.total) * 100) : 0,
      };
    }),
    people,
  };
}
