// Service worker CorpVideo: оболочка приложения офлайн, статика — из кэша, API и медиа — только сеть.
// Версию подставляет сборка (vite.config.js): новая сборка — новый кэш оболочки
const VERSION = 'cv-__CV_BUILD__';
const OFFLINE = 'cv-offline-v1';   // скачанные видео (кладёт приложение, см. utils/offline.js)
// Корень портала — путь области service worker: '/' или '/video/', если портал встроен в общий сайт
// под префиксом (VITE_BASE). Все пути ниже строятся от него.
const ROOT = new URL(self.registration.scope).pathname.replace(/\/?$/, '/');
const SHELL = ['', 'manifest.webmanifest', 'favicon.svg', 'icons/icon-192.png', 'icons/icon-512.png'].map((p) => ROOT + p);

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL).catch(() => {})).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  // Удаляем только свои прежние кэши (cv-…): на общем сайте у других приложений могут быть свои
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('cv-') && k !== VERSION && k !== OFFLINE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  // Чужие адреса того же сайта (другие приложения общего портала) не трогаем
  if (!url.pathname.startsWith(ROOT)) return;
  const p = url.pathname.slice(ROOT.length);   // путь от корня портала, без ведущей косой черты
  // Скачанное видео отдаём из кэша — так оно смотрится без сети. Потоки (HLS, субтитры) не скачиваются,
  // для них кэш не проверяем, чтобы не замедлять каждый сегмент.
  if (p.startsWith('media/') && !/\.(m3u8|ts|m4s|vtt)$/i.test(p)) {
    e.respondWith(caches.open(OFFLINE).then(async (c) => {
      const hit = await c.match(req, { ignoreSearch: true, ignoreVary: true });
      if (hit) return req.headers.get('range') ? rangeResponse(hit, req.headers.get('range')) : hit;
      return fetch(req);
    }));
    return;
  }
  // API, вебсокеты, загрузки — всегда сеть
  if (p.startsWith('api/') || p.startsWith('ws') || p.startsWith('hls/') || p.startsWith('whip')) return;
  // Собранные ассеты с хэшем — кэш навсегда
  if (p.startsWith('assets/') || p.startsWith('fonts/') || p.startsWith('icons/')) {
    e.respondWith(caches.open(VERSION).then(async (c) => { const hit = await c.match(req); if (hit) return hit; const res = await fetch(req); if (res.ok) c.put(req, res.clone()).catch(() => {}); return res; }));
    return;
  }
  // Навигация: сеть, при её отсутствии — сохранённая оболочка
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then((res) => {
      // Копию снимаем сразу: после того как ответ отдан странице, его тело уже прочитано и clone() бросает исключение
      if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(ROOT, copy)).catch(() => {}); }
      return res;
    }).catch(() => caches.match(ROOT).then((r) => r || new Response('<h1>Нет соединения</h1><p>Портал недоступен офлайн.</p>', { headers: { 'content-type': 'text/html; charset=utf-8' } }))));
  }
});

/** Ответ на запрос диапазона из кэша: Safari (и iOS) без 206 не проигрывает видео. */
async function rangeResponse(hit, range) {
  const m = /bytes=(\d*)-(\d*)/.exec(range || '');
  if (!m) return hit;
  const blob = await hit.blob();
  const size = blob.size;
  let start = m[1] ? Number(m[1]) : 0;
  let end = m[2] ? Number(m[2]) : size - 1;
  if (!m[1] && m[2]) { start = Math.max(0, size - Number(m[2])); end = size - 1; }
  if (start >= size || start > end) return new Response(null, { status: 416, headers: { 'content-range': `bytes */${size}` } });
  end = Math.min(end, size - 1);
  return new Response(blob.slice(start, end + 1), {
    status: 206,
    headers: { 'content-type': hit.headers.get('content-type') || 'video/mp4', 'content-range': `bytes ${start}-${end}/${size}`, 'content-length': String(end - start + 1), 'accept-ranges': 'bytes' },
  });
}
self.addEventListener('message', (e) => { if (e.data === 'skipWaiting') self.skipWaiting(); });
