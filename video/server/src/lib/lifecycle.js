// Значения по умолчанию для жизненного цикла материалов (1.12).
import { query } from '../db.js';

/** «Актуально до» для нового видео: через N месяцев из настройки (0 — не заполнять). */
export function freshUntilDefault(s, from = new Date()) {
  if (s?.['lifecycle.enabled'] === false) return null;
  const months = Math.max(0, Math.min(120, Math.round(Number(s?.['lifecycle.freshness_months']) || 0)));
  if (!months) return null;
  const d = new Date(from);
  d.setMonth(d.getMonth() + months);
  return d.toISOString().slice(0, 10);
}

/** Проставить срок актуальности только что созданному видео (если автор его ещё не задал). */
export async function applyFreshDefault(videoId, s, db = { query }) {
  const until = freshUntilDefault(s);
  if (!until) return null;
  await db.query('UPDATE videos SET fresh_until = $2 WHERE id = $1 AND fresh_until IS NULL', [videoId, until]);
  return until;
}
