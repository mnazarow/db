// Сроки хранения (retention как у Vbrick, VIDIZMO и Vimeo Enterprise): что и когда уходит в архив
// или в корзину, предупреждение автору заранее, юридическая блокировка и пересмотр актуальности.
import { one, many, query } from '../db.js';
import { loadSettings } from '../lib/settings.js';
import { notify } from '../lib/notify.js';

/** Условия области действия правила (категория, видимость, тег, записи эфиров). */
function scopeCond(rule, where, params) {
  if (rule.scope === 'category') { params.push(rule.category_id); where.push(`v.category_id = $${params.length}`); }
  else if (rule.scope === 'visibility') { params.push(rule.visibility); where.push(`v.visibility = $${params.length}`); }
  else if (rule.scope === 'tag') { params.push(rule.tag); where.push(`$${params.length} = ANY(v.tags)`); }
  else if (rule.scope === 'live') where.push('v.is_live_recording = true');
}

/** Условие выборки видео, у которых срок уже наступил. Возвращает {sql, params}. */
export function ruleWhere(rule) {
  const params = [String(rule.after_days)];
  const where = ['v.deleted_at IS NULL', 'v.legal_hold = false', `coalesce(v.published_at, v.created_at) < now() - ($1 || ' days')::interval`];
  if (rule.action === 'archive') where.push('v.archived_at IS NULL');
  scopeCond(rule, where, params);
  return { sql: where.join(' AND '), params };
}

/** Условие выборки видео, которым срок наступит через warn_days (кого предупредить). */
export function warnWhere(rule) {
  const params = [String(Math.max(0, rule.after_days - rule.warn_days)), String(rule.after_days)];
  const where = ['v.deleted_at IS NULL', 'v.legal_hold = false', 'v.retention_warned_at IS NULL',
    `coalesce(v.published_at, v.created_at) < now() - ($1 || ' days')::interval`,
    `coalesce(v.published_at, v.created_at) >= now() - ($2 || ' days')::interval`];
  if (rule.action === 'archive') where.push('v.archived_at IS NULL');
  scopeCond(rule, where, params);
  return { sql: where.join(' AND '), params };
}

/** Сколько видео попадёт под правило и первые из них (для предпросмотра в панели). */
export async function rulePreview(rule, limit = 10) {
  const { sql, params } = ruleWhere(rule);
  const total = await one(`SELECT count(*)::int AS n, coalesce(sum(v.storage_bytes),0)::bigint AS bytes FROM videos v WHERE ${sql}`, params);
  const items = await many(
    `SELECT v.id, v.short_id, v.title, v.storage_bytes, v.published_at, v.created_at, u.display_name AS owner_name
     FROM videos v JOIN users u ON u.id = v.owner_id WHERE ${sql} ORDER BY coalesce(v.published_at, v.created_at) LIMIT ${Number(limit) || 10}`, params);
  return {
    total: total?.n || 0, bytes: Number(total?.bytes || 0),
    items: items.map((x) => ({ id: x.id, shortId: x.short_id, title: x.title, bytes: Number(x.storage_bytes) || 0, publishedAt: x.published_at || x.created_at, owner: x.owner_name })),
  };
}

const fmtDate = (d) => new Date(d).toLocaleDateString('ru-RU');

/** Применить одно правило: предупредить заранее, затем выполнить действие. */
export async function applyRule(rule) {
  const { sql, params } = ruleWhere(rule);
  let warned = 0, done = 0;
  // 1. Предупреждение автору за warn_days до срока
  if (rule.warn_days > 0 && rule.action !== 'notify') {
    const w = warnWhere(rule);
    const soon = await many(
      `SELECT v.id, v.title, v.owner_id, v.short_id, coalesce(v.published_at, v.created_at) AS at FROM videos v
       WHERE ${w.sql} ORDER BY coalesce(v.published_at, v.created_at) LIMIT 200`, w.params);
    for (const v of soon) {
      const when = new Date(new Date(v.at).getTime() + rule.after_days * 86400000);
      await notify(v.owner_id, {
        type: 'video_ready',
        title: rule.action === 'delete' ? 'Видео скоро будет удалено по правилу хранения' : 'Видео скоро уйдёт в архив',
        body: `${v.title}: срок хранения «${rule.name}» истекает ${fmtDate(when)}. Продлите актуальность или отметьте видео как не подлежащее удалению.`,
        link: `/studio/videos/${v.id}`, data: { videoId: v.id, rule: rule.id },
      });
      await query('UPDATE videos SET retention_warned_at = now() WHERE id = $1', [v.id]);
      warned += 1;
    }
  }
  // 2. Само действие
  // retention_warned_at обязателен: по нему решается, не слали ли уже предупреждение (иначе письмо каждую ночь)
  const due = await many(`SELECT v.id, v.title, v.owner_id, v.short_id, v.retention_warned_at FROM videos v WHERE ${sql} ORDER BY coalesce(v.published_at, v.created_at) LIMIT 200`, params);
  for (const v of due) {
    if (rule.action === 'archive') {
      await query(`UPDATE videos SET archived_at = now(), archived_reason = $2, updated_at = now() WHERE id = $1 AND archived_at IS NULL`, [v.id, `Правило хранения «${rule.name}»`]);
      await notify(v.owner_id, { type: 'video_ready', title: 'Видео убрано в архив', body: `${v.title}: сработало правило хранения «${rule.name}». Видео осталось у вас в студии и может быть возвращено.`, link: `/studio/videos/${v.id}`, data: { videoId: v.id } });
    } else if (rule.action === 'delete') {
      await query(`UPDATE videos SET deleted_at = now(), updated_at = now() WHERE id = $1 AND deleted_at IS NULL`, [v.id]);
      await notify(v.owner_id, { type: 'video_ready', title: 'Видео удалено по правилу хранения', body: `${v.title}: сработало правило «${rule.name}». Пока видео в корзине, его может вернуть администратор.`, link: `/studio/videos`, data: { videoId: v.id } });
    } else {
      if (v.retention_warned_at) continue;
      await notify(v.owner_id, { type: 'video_ready', title: 'Истёк срок хранения видео', body: `${v.title}: правило «${rule.name}». Решите, оставить видео или убрать.`, link: `/studio/videos/${v.id}`, data: { videoId: v.id } });
      await query('UPDATE videos SET retention_warned_at = now() WHERE id = $1', [v.id]);
    }
    await query('INSERT INTO retention_log(video_id, rule_id, title, owner_id, action) VALUES ($1,$2,$3,$4,$5)', [v.id, rule.id, v.title, v.owner_id, rule.action]);
    done += 1;
  }
  if (done || warned) await query('UPDATE retention_rules SET applied_at = now(), applied_count = applied_count + $2 WHERE id = $1', [rule.id, done]);
  return { rule: rule.name, warned, done };
}

/** Задание: применить все включённые правила и напомнить о пересмотре актуальности. */
export async function runRetention(job) {
  const s = await loadSettings();
  if (!s['lifecycle.enabled']) return { skipped: 'disabled' };
  const rules = job?.payload?.ruleId
    ? await many('SELECT * FROM retention_rules WHERE id = $1', [job.payload.ruleId])
    : await many('SELECT * FROM retention_rules WHERE enabled = true ORDER BY created_at');
  const results = [];
  for (const r of rules) results.push(await applyRule(r));
  const freshness = await runFreshness(s);
  return { rules: results, freshness };
}

/** Пересмотр актуальности: напоминание автору до и после даты «актуально до». */
export async function runFreshness(settings = null) {
  const s = settings || await loadSettings();
  if (!s['lifecycle.enabled']) return { asked: 0 };
  const remind = Math.max(0, Number(s['lifecycle.freshness_remind_days']) || 7);
  const rows = await many(
    `SELECT v.id, v.title, v.owner_id, v.fresh_until FROM videos v
     WHERE v.deleted_at IS NULL AND v.archived_at IS NULL AND v.fresh_until IS NOT NULL
       AND v.fresh_until <= (current_date + ($1 || ' days')::interval)
       AND (v.fresh_asked_at IS NULL OR v.fresh_asked_at < now() - interval '30 days')
     ORDER BY v.fresh_until LIMIT 200`, [String(remind)]);
  let asked = 0;
  for (const v of rows) {
    const overdue = new Date(v.fresh_until) < new Date();
    await notify(v.owner_id, {
      type: 'video_ready',
      title: overdue ? 'Подтвердите актуальность видео' : 'Скоро истекает срок актуальности видео',
      body: `${v.title}: «актуально до ${fmtDate(v.fresh_until)}». Подтвердите, что материал не устарел, или обновите его.`,
      link: `/studio/videos/${v.id}`, data: { videoId: v.id },
    });
    await query('UPDATE videos SET fresh_asked_at = now() WHERE id = $1', [v.id]);
    asked += 1;
  }
  return { asked };
}
