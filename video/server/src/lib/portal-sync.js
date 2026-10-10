// Справочник сотрудников из портала документации (раздел «Видео»).
//
// Без синхронизации учётная запись видеопортала появляется только при первом входе сотрудника в раздел —
// тому, кто ещё не заходил, нельзя назначить обязательный просмотр или открыть приватное видео, а
// уволенные остаются в списках. Поэтому видеопортал по расписанию (обслуживание воркера, раз в
// PORTAL_SYNC_MINUTES минут) и по команде `node src/cli.js portal-sync` забирает у портала список
// сотрудников (/internal/video/users) и:
//   - заводит и обновляет учётные записи всех активных сотрудников (имя, почта, роль, подразделение);
//   - блокирует учётные записи сотрудников, заблокированных или удалённых в портале (portal_blocked);
//     вернувшихся — разблокирует. Блокировку модератором видео (за нарушения) синхронизация не трогает;
//   - ведёт группы по подразделениям портала: состав обновляется сам, добавленные вручную участники остаются.
import { one, many, query } from '../db.js';
import { config } from '../config.js';
import { upsertPortalUser, portalApi, portalError, portalEnabled } from './portal.js';

export const syncEnabled = () => portalEnabled() && config.portal.syncMinutes > 0;

/** Список сотрудников от портала. */
export async function fetchPortalUsers() {
  const { status, data } = await portalApi('GET', '/internal/video/users', null, { timeoutMs: 120000 });
  if (status !== 200 || !data || !Array.isArray(data.users)) throw new Error(status === 200 ? 'портал ответил не списком сотрудников' : portalError(status, data));
  return data.users;
}

const normDepartment = (d) => String(d || '').trim().replace(/\s+/g, ' ').slice(0, 200);

/**
 * Применить список сотрудников портала. Возвращает статистику:
 * { users, active, created, updated, blocked, unblocked, groups, memberships }.
 */
export async function applyDirectory(users) {
  if (!Array.isArray(users)) throw new Error('портал не передал список сотрудников');
  const active = users.filter((u) => u && u.active === true && Number.isInteger(Number(u.id)) && Number(u.id) > 0);
  const stats = { users: users.length, active: active.length, created: 0, updated: 0, blocked: 0, unblocked: 0, groups: 0, memberships: 0 };
  // Защита от сбоя портала: пустой список при уже заведённых сотрудниках заблокировал бы всех разом.
  if (!active.length) {
    const known = await one(`SELECT count(*)::int AS n FROM users WHERE portal_user_id IS NOT NULL AND status = 'active' AND deleted_at IS NULL`);
    if (known.n > 0) throw new Error('портал сообщил, что активных сотрудников нет, — синхронизация пропущена, чтобы не заблокировать всех');
  }

  const idByPortal = new Map();
  for (const pu of active) {
    const r = await upsertPortalUser(pu);
    idByPortal.set(Number(pu.id), r.id);
    if (r.created) stats.created += 1;
    else if (r.changed) stats.updated += 1;
    if (r.unblocked) stats.unblocked += 1;
  }

  // Заблокированные в портале и удалённые из него. Блокировку модератором видео (status = 'blocked',
  // portal_blocked = false) не трогаем и не помечаем — её снимает только модератор.
  const blocked = await many(
    `UPDATE users SET status = 'blocked', portal_blocked = true, updated_at = now()
     WHERE portal_user_id IS NOT NULL AND NOT (portal_user_id = ANY($1::int[])) AND status <> 'blocked' AND deleted_at IS NULL
     RETURNING id`,
    [[...idByPortal.keys()]],
  );
  stats.blocked = blocked.length;

  // Подразделения → группы (имя подразделения без учёта регистра).
  const byDepartment = new Map();
  for (const pu of active) {
    const name = normDepartment(pu.department);
    if (!name) continue;
    const key = name.toLowerCase();
    if (!byDepartment.has(key)) byDepartment.set(key, { name, ids: new Set() });
    byDepartment.get(key).ids.add(idByPortal.get(Number(pu.id)));
  }
  const touched = new Set();
  for (const { name, ids } of byDepartment.values()) {
    let g = await one('SELECT id FROM groups WHERE portal_department = $1', [name]);
    if (!g) {
      // Группа с таким именем уже заведена вручную — подразделение получает отдельную, с пометкой.
      const taken = await one('SELECT 1 FROM groups WHERE name = $1', [name]);
      g = await one(
        `INSERT INTO groups(name, description, portal_department) VALUES ($1, $2, $3) ON CONFLICT DO NOTHING RETURNING id`,
        [taken ? `${name} (подразделение)` : name, 'Подразделение из портала документации: состав обновляется автоматически', name],
      ) || await one('SELECT id FROM groups WHERE portal_department = $1', [name]);
      if (!g) continue;
      stats.groups += 1;
    }
    const list = [...ids];
    const added = await many(
      `INSERT INTO group_members(group_id, user_id, via_portal) SELECT $1, u, true FROM unnest($2::uuid[]) AS u ON CONFLICT DO NOTHING RETURNING user_id`,
      [g.id, list],
    );
    const removed = await many(
      `DELETE FROM group_members WHERE group_id = $1 AND via_portal AND NOT (user_id = ANY($2::uuid[])) RETURNING user_id`,
      [g.id, list],
    );
    stats.memberships += added.length + removed.length;
    touched.add(g.id);
  }
  // Подразделения, которых больше нет: участники от портала выходят из групп; сами группы остаются —
  // на них могут ссылаться назначения и доступ к видео.
  const stale = await many(
    `DELETE FROM group_members m USING groups g
     WHERE g.id = m.group_id AND g.portal_department IS NOT NULL AND m.via_portal AND NOT (g.id = ANY($1::uuid[]))
     RETURNING m.group_id`,
    [[...touched]],
  );
  stats.memberships += stale.length;
  for (const r of stale) touched.add(r.group_id);
  if (touched.size) {
    await query(
      `UPDATE groups g SET member_count = (SELECT count(*) FROM group_members m WHERE m.group_id = g.id), updated_at = now() WHERE g.id = ANY($1::uuid[])`,
      [[...touched]],
    );
  }
  return stats;
}

/** Синхронизация целиком: запрос к порталу, применение, итог в portal_sync_state. */
export async function syncPortalDirectory({ fetchUsers = fetchPortalUsers } = {}) {
  if (!portalEnabled()) return { skipped: 'видеопортал работает без портала документации' };
  const started = Date.now();
  await query('INSERT INTO portal_sync_state(id, last_run_at) VALUES (1, now()) ON CONFLICT (id) DO UPDATE SET last_run_at = now()');
  try {
    const stats = await applyDirectory(await fetchUsers());
    stats.ms = Date.now() - started;
    await query('UPDATE portal_sync_state SET last_ok_at = now(), last_error = NULL, stats = $1::jsonb WHERE id = 1', [JSON.stringify(stats)]);
    return stats;
  } catch (e) {
    await query('UPDATE portal_sync_state SET last_error = $1 WHERE id = 1', [String(e.message || e).slice(0, 500)]).catch(() => {});
    throw e;
  }
}

/** Пора ли синхронизировать (после включения раздела — сразу, дальше — раз в PORTAL_SYNC_MINUTES). */
export async function portalSyncDue() {
  if (!syncEnabled()) return false;
  const s = await one('SELECT last_run_at FROM portal_sync_state WHERE id = 1');
  return !s?.last_run_at || Date.now() - new Date(s.last_run_at).getTime() >= config.portal.syncMinutes * 60000 - 5000;
}

/** Итог последней синхронизации — для «Мониторинга» портала и docportal video status. */
export async function portalSyncStatus() {
  const enabled = syncEnabled();
  const s = await one(`SELECT last_run_at, last_ok_at, last_error, stats, extract(epoch FROM now() - last_ok_at)::int AS age FROM portal_sync_state WHERE id = 1`).catch(() => null);
  return {
    enabled,
    intervalMinutes: config.portal.syncMinutes,
    lastRunAt: s?.last_run_at || null,
    lastOkAt: s?.last_ok_at || null,
    ageSeconds: s?.last_ok_at ? s.age : null,
    lastError: s?.last_error || null,
    users: s?.stats?.active ?? null,
    stats: s?.stats || {},
  };
}
