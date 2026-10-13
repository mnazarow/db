/* Портал документации — service worker: установка портала как приложения (PWA) и страница «Нет связи».
 *
 * Страницы портала (документы, разделы, профиль) не кэшируются — их содержимое зависит от прав
 * сотрудника и должно быть свежим; без сети вместо ошибки браузера показывается страница offline.html.
 * Кэшируются только оформление (стили, скрипты, шрифты, значки) — чтобы приложение открывалось быстрее.
 * Раздел «Видео» (/video/), API и внутренние адреса не затрагиваются.
 *
 * Уведомления в браузере (Web Push): портал присылает заголовок, текст и адрес — они показываются системным
 * уведомлением, нажатие открывает адрес (или уже открытую вкладку портала).
 */
'use strict';

var CACHE = 'docportal-shell-v3';
var OFFLINE = '/offline.html';
var PRECACHE = [OFFLINE, '/css/app.css', '/js/app.js', '/img/logo.svg', '/img/logo-mark.svg', '/img/favicon.svg', '/img/app/icon-192.png'];

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k.indexOf('docportal-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

function isStatic(path) {
    return /^\/(css|js|fonts|img)\//.test(path) || /\.(css|js|woff2?|svg|png|ico)$/.test(path);
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') { return; }
    var url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }
    var path = url.pathname;
    if (path === '/video' || path.indexOf('/video/') === 0 || path.indexOf('/api/') === 0 || path.indexOf('/internal/') === 0) { return; }

    if (request.mode === 'navigate') {
        // Страницы — только из сети; нет сети — страница «Нет связи».
        event.respondWith(fetch(request).catch(function () { return caches.match(OFFLINE); }));
        return;
    }
    if (isStatic(path)) {
        // Оформление — из кэша сразу, с обновлением в фоне.
        event.respondWith(caches.open(CACHE).then(function (cache) {
            return cache.match(request).then(function (cached) {
                var network = fetch(request).then(function (response) {
                    if (response && response.ok && response.type === 'basic') { cache.put(request, response.clone()); }
                    return response;
                }).catch(function () { return cached; });
                return cached || network;
            });
        }));
    }
});

self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
    var title = data.title || 'Портал документации';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/img/app/icon-192.png',
        badge: '/img/app/icon-192.png',
        tag: data.tag || undefined,
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var client = list[i];
            if (client.url === target && 'focus' in client) { return client.focus(); }
        }
        return self.clients.openWindow ? self.clients.openWindow(target) : null;
    }));
});
