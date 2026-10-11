// Группы сотрудников: управление (модераторы/администраторы) и справочник для авторов.
import { one, many, query, tx } from '../db.js';
import { badRequest, notFound, conflict, paging, likeEscape } from '../lib/util.js';
import { userPublic } from '../lib/serialize.js';
import { audit } from '../lib/audit.js';

export function groupOut(g) {
  return { id: g.id, name: g.name, description: g.description, ldapDn: g.ldap_dn, memberCount: g.member_count, createdAt: g.created_at, updatedAt: g.updated_at };
}

async function recount(groupId) {
  await query('UPDATE groups SET member_count = (SELECT count(*) FROM group_members WHERE group_id = $1), updated_at = now() WHERE id = $1', [groupId]);
}

/**
 * Синхронизация членства по внешним группам (AD memberOf при входе через LDAP; claim groups при входе через SSO).
 * Группа портала считается связанной, если её «внешний идентификатор» (ldap_dn) совпадает с идентификатором из каталога
 * или, для SSO, если название группы совпадает с названием из claim (без учёта регистра и ведущего «/»).
 */
export async function syncExternalGroups(userId, identifiers = [], { byName = false } = {}) {
  const norm = (d) => String(d).toLowerCase().replace(/^\//, '');
  const ids = new Set((identifiers || []).map(norm));
  const linked = await many(`SELECT id, name, ldap_dn FROM groups WHERE (ldap_dn IS NOT NULL AND ldap_dn <> '') ${byName ? 'OR true' : ''}`);
  for (const g of linked) {
    const member = (g.ldap_dn && ids.has(norm(g.ldap_dn))) || (byName && ids.has(norm(g.name)));
    if (member) await query('INSERT INTO group_members(group_id, user_id, via_ldap) VALUES ($1,$2,true) ON CONFLICT DO NOTHING', [g.id, userId]);
    else await query('DELETE FROM group_members WHERE group_id = $1 AND user_id = $2 AND via_ldap', [g.id, userId]);
    await recount(g.id);
  }
}
export const syncLdapGroups = (userId, memberOf = []) => syncExternalGroups(userId, memberOf);

export default async function groupRoutes(app) {
  // Справочник для авторов: выбрать группу при назначении или выдаче доступа
  app.get('/groups', { preHandler: app.requireActive }, async (req) => {
    const q = String(req.query.q || '').trim();
    const rows = await many(`SELECT * FROM groups WHERE ($1 = '' OR name ILIKE $2) ORDER BY name LIMIT 200`, [q, `%${likeEscape(q)}%`]);
    return { groups: rows.map(groupOut) };
  });
  app.get('/groups/mine', { preHandler: app.requireActive }, async (req) => {
    const rows = await many('SELECT g.* FROM groups g JOIN group_members m ON m.group_id = g.id WHERE m.user_id = $1 ORDER BY g.name', [req.user.id]);
    return { groups: rows.map(groupOut) };
  });

  // --- Администрирование -----------------------------------------------------------------
  app.get('/admin/groups', { preHandler: app.requireStaff }, async (req) => {
    const rows = await many('SELECT g.*, u.display_name AS creator FROM groups g LEFT JOIN users u ON u.id = g.created_by ORDER BY g.name');
    return { groups: rows.map((g) => ({ ...groupOut(g), creator: g.creator })) };
  });
  app.post('/admin/groups', { preHandler: app.requireStaff }, async (req) => {
    const name = String(req.body?.name || '').trim().slice(0, 80);
    if (!name) throw badRequest('Укажите название группы');
    const dup = await one('SELECT 1 FROM groups WHERE name = $1', [name]);
    if (dup) throw conflict('Группа с таким названием уже есть');
    const g = await one('INSERT INTO groups(name, description, ldap_dn, created_by) VALUES ($1,$2,$3,$4) RETURNING *',
      [name, String(req.body?.description || '').slice(0, 500), String(req.body?.ldapDn || '').trim().slice(0, 300) || null, req.user.id]);
    await audit(req, 'group.create', { targetType: 'group', targetId: g.id, details: { name } });
    return { group: groupOut(g) };
  });
  app.get('/admin/groups/:id', { preHandler: app.requireStaff }, async (req) => {
    const g = await one('SELECT * FROM groups WHERE id = $1', [req.params.id]);
    if (!g) throw notFound('Группа не найдена');
    const { limit, offset, page } = paging(req.query, 50, 500);
    const q = String(req.query.q || '').trim();
    const rows = await many(
      `SELECT u.id, u.handle, u.display_name, u.email, u.avatar_path, u.status, m.via_ldap, m.created_at AS added_at FROM group_members m JOIN users u ON u.id = m.user_id
       WHERE m.group_id = $1 AND ($2 = '' OR u.display_name ILIKE $3 OR u.email ILIKE $3) ORDER BY u.display_name LIMIT $4 OFFSET $5`,
      [g.id, q, `%${likeEscape(q)}%`, limit, offset],
    );
    return { group: groupOut(g), members: rows.map((r) => ({ ...userPublic(r), email: r.email, status: r.status, viaLdap: r.via_ldap, addedAt: r.added_at })), page, limit, total: g.member_count };
  });
  app.patch('/admin/groups/:id', { preHandler: app.requireStaff }, async (req) => {
    const g = await one('SELECT * FROM groups WHERE id = $1', [req.params.id]);
    if (!g) throw notFound('Группа не найдена');
    const b = req.body || {};
    const name = b.name !== undefined ? String(b.name).trim().slice(0, 80) : g.name;
    if (!name) throw badRequest('Укажите название группы');
    const dup = await one('SELECT 1 FROM groups WHERE name = $1 AND id <> $2', [name, g.id]);
    if (dup) throw conflict('Группа с таким названием уже есть');
    const upd = await one('UPDATE groups SET name = $2, description = $3, ldap_dn = $4, updated_at = now() WHERE id = $1 RETURNING *',
      [g.id, name, b.description !== undefined ? String(b.description).slice(0, 500) : g.description, b.ldapDn !== undefined ? (String(b.ldapDn).trim().slice(0, 300) || null) : g.ldap_dn]);
    await audit(req, 'group.update', { targetType: 'group', targetId: g.id, details: Object.keys(b) });
    return { group: groupOut(upd) };
  });
  app.delete('/admin/groups/:id', { preHandler: app.requireAdmin }, async (req) => {
    const g = await one('DELETE FROM groups WHERE id = $1 RETURNING name', [req.params.id]);
    if (!g) throw notFound('Группа не найдена');
    await audit(req, 'group.delete', { targetType: 'group', targetId: req.params.id, details: { name: g.name } });
    return { ok: true };
  });
  // Участники: добавить список (id, e-mail или @handle), удалить одного
  app.post('/admin/groups/:id/members', { preHandler: app.requireStaff }, async (req) => {
    const g = await one('SELECT * FROM groups WHERE id = $1', [req.params.id]);
    if (!g) throw notFound('Группа не найдена');
    const items = Array.isArray(req.body?.users) ? req.body.users : [req.body?.user];
    let added = 0; const notFoundList = [];
    await tx(async (c) => {
      for (const item of items.slice(0, 1000)) {
        const val = String(item?.id || item || '').trim().toLowerCase().replace(/^@/, '');
        if (!val) continue;
        const u = await c.one(`SELECT id FROM users WHERE (id = cv_uuid($1) OR email = $1 OR handle = $1) AND deleted_at IS NULL`, [val]);
        if (!u) { notFoundList.push(val); continue; }
        const r = await c.one('INSERT INTO group_members(group_id, user_id, added_by) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING RETURNING user_id', [g.id, u.id, req.user.id]);
        if (r) added++;
      }
    });
    await recount(g.id);
    await audit(req, 'group.members_add', { targetType: 'group', targetId: g.id, details: { added } });
    return { ok: true, added, notFound: notFoundList, group: groupOut(await one('SELECT * FROM groups WHERE id = $1', [g.id])) };
  });
  app.delete('/admin/groups/:id/members/:userId', { preHandler: app.requireStaff }, async (req) => {
    await query('DELETE FROM group_members WHERE group_id = $1 AND user_id = $2', [req.params.id, req.params.userId]);
    await recount(req.params.id);
    await audit(req, 'group.members_remove', { targetType: 'group', targetId: req.params.id, details: { userId: req.params.userId } });
    return { ok: true };
  });
}
