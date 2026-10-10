// Префикс пути портала в браузере. Портал может быть встроен в общий сайт под префиксом
// (https://portal.example.ru/video): сборка с VITE_BASE=/video/ задаёт import.meta.env.BASE_URL.
// Пути маршрутизатора (/watch/…, /@handle) префикса не содержат — его добавляет история vue-router.
// Всё, что идёт мимо маршрутизатора (fetch, XHR, WebSocket, <a href>, <img src>, ссылки «Поделиться»),
// оборачивается в url() / absUrl().

/** Префикс без завершающей косой черты: '' в корне сайта или '/video'. */
export const BASE = String(import.meta.env.BASE_URL || '/').replace(/\/+$/, '');

/**
 * Путь от корня портала → путь для браузера: '/api/x' → '/video/api/x'.
 * Не трогает: абсолютные адреса (http:, https:, //host), data:, blob:, относительные пути,
 * а также пути, уже начинающиеся с префикса (адреса от сервера приходят с ним).
 */
export function url(path) {
  if (typeof path !== 'string' || !BASE) return path;
  if (!path.startsWith('/') || path.startsWith('//')) return path;
  if (path === BASE || path.startsWith(BASE + '/') || path.startsWith(BASE + '?') || path.startsWith(BASE + '#')) return path;
  return BASE + path;
}

/** Полный адрес для копирования и QR-кодов: location.origin + url(path). Абсолютные адреса — как есть. */
export function absUrl(path) {
  if (typeof path === 'string' && /^[a-z][a-z0-9+.-]*:/i.test(path)) return path;
  if (typeof path === 'string' && path.startsWith('//')) return location.protocol + path;
  return location.origin + url(path);
}

/** Обратное преобразование: путь из адреса страницы → путь маршрутизатора (без префикса). */
export function routerPath(path) {
  if (typeof path !== 'string' || !BASE) return path;
  if (path === BASE) return '/';
  if (path.startsWith(BASE + '/')) return path.slice(BASE.length);
  if (path.startsWith(BASE + '?') || path.startsWith(BASE + '#')) return '/' + path.slice(BASE.length);
  return path;
}
