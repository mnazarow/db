// Уведомления пользователям: запись в БД, push по WebSocket, письмо (по настройкам пользователя).
import { one, query, many } from '../db.js';
import { toUser } from './realtime.js';
import { templates } from './mailer.js';
import { config } from '../config.js';
import { enqueue } from './jobs.js';
import { getSettingSync } from './settings.js';

/**
 * Настройки уведомлений пользователя (prefs.notifications):
 *   { newVideos: true, replies: true, moderation: true, email: true, emailNewVideos: false }
 */
export function notifPrefs(user) {
  const p = user?.prefs?.notifications || {};
  return {
    newVideos: p.newVideos !== false,
    replies: p.replies !== false,
    likes: p.likes !== false,
    moderation: p.moderation !== false,
    system: true,
    email: p.email !== false,
    emailNewVideos: p.emailNewVideos === true,
  };
}

export async function notify(userId, { type, title, body = '', link = null, image = null, actorId = null, data = {} }, { email = null } = {}) {
  const row = await one(
    `INSERT INTO notifications(user_id, type, title, body, link, image, actor_id, data)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8::jsonb) RETURNING *`,
    [userId, type, title, body, link, image, actorId, JSON.stringify(data)],
  );
  const unread = await one('SELECT count(*)::int AS n FROM notifications WHERE user_id=$1 AND read_at IS NULL', [userId]);
  await toUser(userId, { type: 'notification', notification: row, unread: unread?.n || 0 });
  if (email) {
    try { await email(); } catch (e) { console.error('email notify failed', e.message); }
  }
  // Telegram — отдельным лёгким заданием воркера (не задерживает ответ API), только привязавшим бота
  if (getSettingSync('telegram.enabled')) {
    try {
      const tg = await one('SELECT 1 FROM users WHERE id = $1 AND telegram_chat_id IS NOT NULL', [userId]);
      if (tg) await enqueue('telegram', { userId, title, body, link, type }, { dedupe: false, maxAttempts: 2 });
    } catch { /* ignore */ }
  }
  return row;
}

/**
 * Уведомить многих сразу. Раньше рассылка шла по одному адресату: вставка, подсчёт непрочитанных
 * и проверка Telegram на каждого — три запроса на человека. Здесь — по три запроса на пачку.
 */
export async function notifyMany(userIds, { type, title, body = '', link = null, image = null, actorId = null, data = {} }) {
  const ids = [...new Set(userIds.filter(Boolean))];
  if (!ids.length) return 0;
  const rows = await many(
    `INSERT INTO notifications(user_id, type, title, body, link, image, actor_id, data)
     SELECT u, $2, $3, $4, $5, $6, $7, $8::jsonb FROM unnest($1::uuid[]) AS u RETURNING *`,
    [ids, type, title, body, link, image, actorId, JSON.stringify(data)],
  );
  const counts = await many(`SELECT user_id, count(*)::int AS n FROM notifications WHERE user_id = ANY($1::uuid[]) AND read_at IS NULL GROUP BY user_id`, [ids]);
  const unread = new Map(counts.map((c) => [c.user_id, c.n]));
  for (const row of rows) await toUser(row.user_id, { type: 'notification', notification: row, unread: unread.get(row.user_id) || 0 });
  if (getSettingSync('telegram.enabled')) {
    try {
      const tg = await many('SELECT id FROM users WHERE id = ANY($1::uuid[]) AND telegram_chat_id IS NOT NULL', [ids]);
      for (const u of tg) await enqueue('telegram', { userId: u.id, title, body, link, type }, { dedupe: false, maxAttempts: 2 });
    } catch { /* ignore */ }
  }
  return rows.length;
}

/** Уведомить всех администраторов (и модераторов при needModerators). */
export async function notifyAdmins(payload, { moderators = false, email = null } = {}) {
  const roles = moderators ? ['admin', 'moderator'] : ['admin'];
  const admins = await many(`SELECT id, email, display_name, prefs FROM users WHERE role = ANY($1) AND status='active' AND deleted_at IS NULL`, [roles]);
  for (const a of admins) {
    await notify(a.id, payload, { email: email ? () => email(a) : null });
  }
  return admins.length;
}

/** Позвать на премьеру: за 30 минут и в момент начала показа (как уведомления YouTube). */
export async function notifyPremiereAudience(video, owner, kind = 'soon') {
  const subs = await many(
    `SELECT u.id, u.email, u.display_name, u.prefs FROM subscriptions s JOIN users u ON u.id = s.subscriber_id
     WHERE s.channel_id = $1 AND s.notify IN ('all','highlights') AND u.status = 'active' AND u.deleted_at IS NULL`,
    [owner.id],
  );
  const link = `/watch/${video.short_id}`;
  const image = video.thumbnail_path ? `/media/${video.thumbnail_path}` : null;
  const when = video.scheduled_at ? new Date(video.scheduled_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '';
  let n = 0;
  for (const u of subs) {
    const prefs = notifPrefs(u);
    if (!prefs.newVideos) continue;
    await notify(u.id, {
      type: kind === 'start' ? 'premiere_started' : 'premiere_soon',
      title: kind === 'start' ? `Премьера началась: ${owner.display_name}` : `Скоро премьера в ${when}`,
      body: video.title, link, image, actorId: owner.id, data: { videoId: video.id, premiere: true },
    });
    n += 1;
  }
  return n;
}

/** Уведомить подписчиков канала о новом видео. */
export async function notifySubscribersNewVideo(video, owner) {
  const subs = await many(
    `SELECT u.id, u.email, u.display_name, u.prefs FROM subscriptions s JOIN users u ON u.id = s.subscriber_id
     WHERE s.channel_id = $1 AND s.notify = 'all' AND u.status = 'active' AND u.deleted_at IS NULL`,
    [owner.id],
  );
  const link = `/watch/${video.short_id}`;
  const image = video.thumbnail_path ? `/media/${video.thumbnail_path}` : null;
  for (const u of subs) {
    const prefs = notifPrefs(u);
    if (!prefs.newVideos) continue;
    await notify(u.id, {
      type: 'new_video',
      title: `${owner.display_name}: новое видео`,
      body: video.title,
      link, image, actorId: owner.id, data: { videoId: video.id },
    }, {
      email: prefs.email && prefs.emailNewVideos ? () => templates.newVideo({ to: u.email, channelName: owner.display_name, videoTitle: video.title, url: config.baseUrl + link, thumbUrl: image ? config.baseUrl + image : null }) : null,
    });
  }
  return subs.length;
}

export async function markRead(userId, ids = null) {
  if (ids && ids.length) await query('UPDATE notifications SET read_at = now() WHERE user_id=$1 AND id = ANY($2) AND read_at IS NULL', [userId, ids]);
  else await query('UPDATE notifications SET read_at = now() WHERE user_id=$1 AND read_at IS NULL', [userId]);
}
