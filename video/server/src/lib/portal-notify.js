// Уведомления видеопортала через портал документации (раздел «Видео»).
//
// В составе портала почта и бот Telegram настроены в портале, а у видеопортала их обычно нет. Уведомления
// о задачах — видео ждёт модерации, решение модератора, назначен обязательный просмотр, напоминание о
// сроке, жалоба, просьба о рецензии — видеопортал передаёт порталу (POST /internal/video/notify), и тот
// отправляет письмо и сообщение в Telegram (сотрудникам, привязавшим бота в профиле портала). Уведомление
// внутри видеопортала (колокольчик) остаётся как было. Выключить пересылку: PORTAL_NOTIFY=0.
import { many } from '../db.js';
import { config } from '../config.js';
import { portalApi, portalError, portalEnabled } from './portal.js';

/** Типы уведомлений, которые уходят письмом и в Telegram через портал. Остальные — только внутри видеопортала. */
export const RELAY_TYPES = new Set([
  'moderation', 'report', 'comment_held', 'video_blocked', 'video_failed',
  'assignment', 'assignment_reminder', 'review_requested', 'webinar_cohost', 'certificate',
]);

/** Сотрудников в одном запросе к порталу: портал отправляет письма сразу, большой список не уложится во время ответа. */
export const RELAY_CHUNK = 50;

export function shouldRelay(type) {
  return portalEnabled() && config.portal.relayNotifications && RELAY_TYPES.has(type);
}

/**
 * Переслать уведомление порталу: идентификаторы учётных записей видеопортала → сотрудники портала.
 * Возвращает число сотрудников, которым портал отправил уведомление. Ошибка портала — исключение
 * (задание воркера повторится).
 */
export async function relayToPortal({ userIds = [], type = '', title = '', body = '', link = '/' }) {
  const ids = [...new Set((Array.isArray(userIds) ? userIds : []).filter(Boolean))];
  if (!ids.length || !portalEnabled()) return 0;
  const rows = await many(
    `SELECT portal_user_id FROM users WHERE id = ANY($1::uuid[]) AND portal_user_id IS NOT NULL AND status = 'active' AND deleted_at IS NULL`,
    [ids],
  );
  const portalIds = rows.map((r) => Number(r.portal_user_id));
  let sent = 0;
  for (let i = 0; i < portalIds.length; i += RELAY_CHUNK) {
    const chunk = portalIds.slice(i, i + RELAY_CHUNK);
    const { status, data } = await portalApi('POST', '/internal/video/notify', {
      users: chunk, type, title: String(title).slice(0, 200), body: String(body || '').slice(0, 1000), link: link || '/',
    }, { timeoutMs: 120000 });
    if (status !== 200) throw new Error(portalError(status, data));
    sent += chunk.length;
  }
  return sent;
}
