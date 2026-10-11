// Единая точка событий портала для интеграций: вебхуки (+ xAPI там, где уместно).
import { emitWebhook } from './webhooks.js';

/** Событие для внешних систем. Ошибки не прерывают основной сценарий. */
export async function emitEvent(type, data = {}) {
  try { await emitWebhook(type, data); } catch (e) { console.error('webhook emit', type, e.message); }
}

/** Компактное представление видео в событиях. */
export function eventVideo(v) {
  if (!v) return null;
  return {
    id: v.id, shortId: v.short_id, title: v.title, visibility: v.visibility, status: v.status, duration: Number(v.duration) || 0,
    ownerId: v.owner_id, categoryId: v.category_id || null, tags: v.tags || [], publishedAt: v.published_at || null, url: `/watch/${v.short_id}`,
  };
}

export function eventUser(u) {
  if (!u) return null;
  return { id: u.id, email: u.email, displayName: u.display_name, role: u.role, status: u.status };
}
