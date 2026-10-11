// Адреса, которые видит браузер. Портал может работать под префиксом пути (BASE_PATH=/video):
// nginx срезает префикс перед API, поэтому маршруты сервера остаются прежними, а все адреса,
// уходящие в браузер (медиафайлы, ссылки на API, перенаправления), должны нести префикс.
//
// ВАЖНО: в базе данных и в адресах для писем/Telegram/лент (config.baseUrl + путь) префикс не нужен —
// при работе под префиксом BASE_URL уже его содержит (https://portal.example.ru/video).
// Поэтому храним «голые» пути, а префикс добавляем только при отдаче в браузер.
import { config } from '../config.js';

/** Адрес медиафайла для браузера: '<префикс>/media/<rel>'; null для пустого пути. */
export const mediaUrl = (rel) => (rel ? `${config.basePath}/media/${rel}` : null);

/**
 * Путь от корня портала для браузера: '/api/…' → '<префикс>/api/…'.
 * Абсолютные адреса (http://…, //…), пустые значения и уже содержащие префикс пути не меняются.
 */
export function browserPath(p) {
  if (typeof p !== 'string' || !p.startsWith('/') || p.startsWith('//')) return p;
  const base = config.basePath;
  if (!base || p === base || p.startsWith(base + '/')) return p;
  return base + p;
}

/** Хранимый «голый» путь '/media/…' → адрес для браузера; прочие значения (http…, null) — как есть. */
export const storedMediaUrl = (p) => (typeof p === 'string' && p.startsWith('/media/') ? `${config.basePath}${p}` : p);

/** Убирает префикс из пути запроса (nginx передаёт $request_uri целиком, вместе с префиксом). */
export function stripBasePath(p) {
  const base = config.basePath;
  if (!base || typeof p !== 'string') return p;
  if (p === base) return '/';
  if (p.startsWith(base + '/')) return p.slice(base.length);
  if (p.startsWith(base + '?')) return '/' + p.slice(base.length);
  return p;
}
