// Сборка приложения Fastify: плагины, маршруты, раздача SPA с подстановкой мета-тегов.
import fs from 'node:fs';
import path from 'node:path';
import Fastify, { LogController } from 'fastify';
import cookie from '@fastify/cookie';
import multipart from '@fastify/multipart';
import rateLimit from '@fastify/rate-limit';
import fastifyStatic from '@fastify/static';
import websocket from '@fastify/websocket';
import { config } from './config.js';
import { featureForPath } from './lib/features.js';
import authPlugin from './plugins/auth.js';
import { HttpError, escapeHtml, userOrIpKey } from './lib/util.js';
import { publicSettings } from './lib/settings.js';
import { one, many } from './db.js';
import internalRoutes from './routes/internal.js';
import authRoutes from './routes/auth.js';
import videoRoutes from './routes/videos.js';
import uploadRoutes from './routes/uploads.js';
import mediaRoutes from './routes/media.js';
import commentRoutes from './routes/comments.js';
import playlistRoutes from './routes/playlists.js';
import userRoutes from './routes/users.js';
import feedRoutes from './routes/feed.js';
import searchRoutes from './routes/search.js';
import miscRoutes from './routes/misc.js';
import liveRoutes from './routes/live.js';
import wsRoutes from './routes/ws.js';
import adminRoutes from './routes/admin/index.js';
import adminWebhookRoutes from './routes/admin/webhooks.js';
import adminStorageRoutes from './routes/admin/storage.js';
import adminLifecycleRoutes from './routes/admin/lifecycle.js';
import adminFeatureRoutes from './routes/admin/features.js';
import showcaseRoutes from './routes/showcases.js';
import webinarPageRoutes from './routes/webinars.js';
import webinarStudioRoutes from './routes/webinars-studio.js';
import projectRoutes from './routes/projects.js';
import editorRoutes from './routes/editor.js';
import feedsRoutes from './routes/feeds.js';
import scormRoutes from './routes/scorm.js';
import groupRoutes from './routes/groups.js';
import assignmentRoutes from './routes/assignments.js';
import courseRoutes from './routes/courses.js';
import engageRoutes from './routes/engage.js';
import learningRoutes from './routes/learning.js';
import webinarRoutes from './routes/webinar.js';
import playbackRoutes from './routes/playback.js';
import roomRoutes from './routes/rooms.js';
import screenRoutes from './routes/screens.js';
import meetingRoutes from './routes/meeting.js';
import reviewRoutes from './routes/review.js';
import quizRoutes from './routes/quiz.js';
import noteRoutes from './routes/notes.js';
import shareRoutes from './routes/share.js';
import liveInteractRoutes from './routes/live-interact.js';
import attachmentRoutes from './routes/attachments.js';
import ragRoutes from './routes/rag.js';
import { canViewVideo, canViewLive } from './lib/access.js';

export async function buildApp({ logger = true } = {}) {
  const app = Fastify({
    logger: logger ? { level: config.logLevel, transport: config.isDev ? { target: 'pino-pretty' } : undefined } : false,
    trustProxy: config.trustProxy,
    bodyLimit: 4 * 1024 * 1024,
    // В production не пишем строку на каждый запрос (доступ логирует nginx); в dev — подробно
    logController: new LogController({ disableRequestLogging: !config.isDev }),
  });

  await app.register(cookie, { secret: config.secretKey });
  // Предел простой (не чанковой) загрузки: фактический размер проверяет checkUploadAllowed по настройке upload.max_size_mb
  await app.register(multipart, { limits: { fileSize: 2 * 1024 * 1024 * 1024 * 1024, files: 1, fields: 20 } });
  await app.register(rateLimit, {
    global: true, max: 1500, timeWindow: '1 minute',
    // Ограничение действует только на API; статика, медиа, HLS-прокси и проверки доступа не учитываются
    allowList: (req) => {
      // Только путь: раньше учитывалась и query-строка, и любой запрос с «/hls/» в параметрах обходил лимит
      const p = (req.url || '').split('?')[0];
      return !p.startsWith('/api/') || p.startsWith('/api/media/auth') || p.startsWith('/api/internal/') || p.includes('/hls/');
    },
    // Считаем по сотруднику, если он вошёл, иначе по адресу. Раньше — только по адресу: сотрудники
    // за одним NAT или прокси делили общий предел (чат эфира, комментарии, запись на вебинар)
    hook: 'preHandler',
    keyGenerator: userOrIpKey,
    errorResponseBuilder: () => ({ error: 'Слишком много запросов, попробуйте позже', statusCode: 429 }),
  });
  await app.register(websocket, { options: { maxPayload: 64 * 1024 } });
  await app.register(authPlugin);

  // Выключенная в панели возможность перестаёт отвечать и по API, а не только прячется в меню.
  // Маршруты панели администратора не трогаем — иначе выключенное нельзя было бы включить обратно.
  app.addHook('onRequest', async (req, reply) => {
    const p = (req.url || '').split('?')[0];
    if (!p.startsWith('/api/') || p.startsWith('/api/admin/') || p.startsWith('/api/internal/')) return;
    const key = featureForPath(p);
    if (key && req.settings && req.settings[key] === false) {
      reply.code(404).send({ error: 'Раздел отключён администратором портала', feature: key });
      return reply;
    }
  });

  // Заголовки безопасности
  app.addHook('onSend', async (req, reply) => {
    reply.header('X-Content-Type-Options', 'nosniff');
    reply.header('Referrer-Policy', 'strict-origin-when-cross-origin');
    if (!req.url.startsWith('/embed/')) reply.header('X-Frame-Options', 'SAMEORIGIN');
    else {
      // Ограничение доменов для встраивания (Настройки → Общие → Встраивание): frame-ancestors
      const domains = (req.settings?.['embed.allowed_domains'] || []).map((d) => String(d).trim()).filter(Boolean);
      if (domains.length) reply.header('Content-Security-Policy', `frame-ancestors 'self' ${domains.map((d) => (/^https?:\/\//.test(d) ? d : `https://${d} http://${d}`)).join(' ')}`);
    }
    reply.header('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), picture-in-picture=*, fullscreen=*');
  });

  // Обработка ошибок
  app.setErrorHandler((err, req, reply) => {
    if (err instanceof HttpError) {
      reply.code(err.status).send({ error: err.message, ...err.extra });
      return;
    }
    if (err.validation) { reply.code(400).send({ error: 'Некорректные данные запроса', details: err.validation }); return; }
    if (err.statusCode && err.statusCode < 500) { reply.code(err.statusCode).send({ error: err.message }); return; }
    if (err.code === 'FST_REQ_FILE_TOO_LARGE') { reply.code(413).send({ error: 'Файл слишком большой' }); return; }
    // Ошибки PostgreSQL при некорректном вводе (например, не-UUID в идентификаторе) — это ошибка запроса, а не сервера.
    // Подробности пишем в журнал: без них такую 400-ку невозможно разобрать по жалобе пользователя.
    if (err.code === '22P02' || err.code === '22007' || err.code === '22008' || err.code === '22003') {
      const m = /type (\w+): "([^"]*)"/.exec(err.message || '');
      req.log.warn({ pg: { code: err.code, message: err.message, detail: err.detail, table: err.table, column: err.column, routine: err.routine }, route: `${req.method} ${req.url}` }, 'некорректное значение в запросе');
      reply.code(400).send({ error: m ? `Некорректное значение «${m[2]}» в запросе (ожидался тип ${m[1]})` : 'Некорректный идентификатор или значение в запросе' });
      return;
    }
    if (err.code === '23505') { reply.code(409).send({ error: 'Такая запись уже существует' }); return; }
    if (err.code === '23503') { reply.code(400).send({ error: 'Ссылка на несуществующую запись' }); return; }
    req.log.error({ err }, 'unhandled error');
    reply.code(500).send({ error: 'Внутренняя ошибка сервера' });
  });
  app.setNotFoundHandler((req, reply) => {
    if (req.url.startsWith('/api/')) { reply.code(404).send({ error: 'Не найдено' }); return; }
    // Файлы (шрифты, картинки и т.п.) не подменяем страницей SPA
    if (/\.[a-z0-9]{2,5}(\?.*)?$/i.test(req.url.split('?')[0]) && !req.url.endsWith('.html')) { reply.code(404).send('Not found'); return; }
    return serveSpa(req, reply);
  });

  // API
  await app.register(internalRoutes, { prefix: '/api/internal' });
  await app.register(authRoutes, { prefix: '/api/auth' });
  await app.register(videoRoutes, { prefix: '/api/videos' });
  await app.register(uploadRoutes, { prefix: '/api/uploads' });
  await app.register(playlistRoutes, { prefix: '/api/playlists' });
  await app.register(feedRoutes, { prefix: '/api/feed' });
  await app.register(searchRoutes, { prefix: '/api/search' });
  await app.register(commentRoutes, { prefix: '/api' });
  await app.register(userRoutes, { prefix: '/api' });
  await app.register(miscRoutes, { prefix: '/api' });
  await app.register(liveRoutes, { prefix: '/api' });
  await app.register(groupRoutes, { prefix: '/api' });
  await app.register(assignmentRoutes, { prefix: '/api' });
  await app.register(courseRoutes, { prefix: '/api' });
  await app.register(engageRoutes, { prefix: '/api' });
  await app.register(learningRoutes, { prefix: '/api' });
  await app.register(webinarRoutes, { prefix: '/api' });
  await app.register(playbackRoutes, { prefix: '/api' });
  await app.register(roomRoutes, { prefix: '/api' });
  await app.register(screenRoutes, { prefix: '/api' });
  await app.register(meetingRoutes, { prefix: '/api' });
  await app.register(reviewRoutes, { prefix: '/api' });
  await app.register(quizRoutes, { prefix: '/api' });
  await app.register(noteRoutes, { prefix: '/api' });
  await app.register(shareRoutes, { prefix: '/api' });
  await app.register(liveInteractRoutes, { prefix: '/api' });
  await app.register(attachmentRoutes, { prefix: '/api' });
  await app.register(ragRoutes, { prefix: '/api' });
  await app.register(editorRoutes, { prefix: '/api' });
  await app.register(scormRoutes, { prefix: '/api' });
  await app.register(feedsRoutes, { prefix: '/api/rss' });
  await app.register(adminRoutes, { prefix: '/api/admin' });
  await app.register(adminWebhookRoutes, { prefix: '/api/admin' });
  await app.register(adminStorageRoutes, { prefix: '/api/admin' });
  await app.register(adminLifecycleRoutes, { prefix: '/api/admin' });
  await app.register(adminFeatureRoutes, { prefix: '/api/admin/features' });
  await app.register(showcaseRoutes, { prefix: '/api' });
  await app.register(webinarPageRoutes, { prefix: '/api' });
  await app.register(webinarStudioRoutes, { prefix: '/api' });
  await app.register(projectRoutes, { prefix: '/api' });
  await app.register(mediaRoutes);
  await app.register(wsRoutes);

  // Статика SPA
  const dist = config.webDist;
  if (fs.existsSync(path.join(dist, 'index.html'))) {
    await app.register(fastifyStatic, {
      root: dist, prefix: '/', wildcard: false, index: false, serve: true,
      setHeaders: (res, filePath) => {
        if (/\/assets\//.test(filePath)) res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        else res.setHeader('Cache-Control', 'no-cache');
      },
    });
    // Список файлов статики строится один раз при старте (wildcard: false), поэтому пересобранный
    // фронтенд без перезапуска API отдавал бы 404 на все новые файлы. Этот маршрут ищет файл на диске.
    app.get('/assets/*', async (req, reply) => {
      const rel = String(req.params['*'] || '');
      const parts = rel.split('/');
      if (!parts.length || parts.some((x) => !x || x === '.' || x === '..' || !/^[A-Za-z0-9._-]+$/.test(x))) return reply.code(404).send({ error: 'Not found' });
      return reply.sendFile(path.posix.join('assets', rel));
    });
  }

  let indexCache = null; let indexCacheMtime = 0;
  async function serveSpa(req, reply) {
    const file = path.join(dist, 'index.html');
    if (!fs.existsSync(file)) { reply.code(503).type('text/html').send('<h1>Веб-интерфейс не собран</h1><p>Выполните сборку: cd web && npm run build</p>'); return; }
    // Перечитываем index.html при изменении файла: после пересборки фронтенда старый кэш вёл бы на удалённые файлы
    const mtime = fs.statSync(file).mtimeMs;
    if (!indexCache || config.isDev || mtime !== indexCacheMtime) { indexCache = fs.readFileSync(file, 'utf8'); indexCacheMtime = mtime; }
    const s = await publicSettings();
    const meta = await pageMeta(req, s);
    const html = indexCache
      .replace(/<title>[^<]*<\/title>/, `<title>${escapeHtml(meta.title)}</title>`)
      .replace('<!--META-->', meta.tags)
      .replace('__SITE_NAME__', escapeHtml(s.siteName))
      .replace('__THEME__', escapeHtml(s.defaultTheme || 'light'))
      .replace('__ACCENT__', s.accentColor ? `<style>:root{--brand:${escapeHtml(s.accentColor)}}</style>` : '')
      .replace('__FAVICON__', s.faviconUrl ? escapeHtml(s.faviconUrl) : escapeHtml(`${config.basePath}/favicon.svg`));
    reply.type('text/html').header('Cache-Control', 'no-cache').send(html);
  }
  app.get('/', serveSpa);

  async function pageMeta(req, s) {
    const base = config.baseUrl;
    const url = base + req.url.split('?')[0];
    const site = s.siteName;
    let title = site, description = s.description, image = `${base}/og-default.png`, type = 'website', extra = '';
    try {
      let m;
      if ((m = req.url.match(/^\/(?:watch|embed)\/([A-Za-z0-9_-]{6,})/))) {
        const v = await one('SELECT v.*, u.display_name AS owner_name FROM videos v JOIN users u ON u.id = v.owner_id WHERE v.short_id = $1 AND v.deleted_at IS NULL', [m[1]]);
        if (v && (await canViewVideo(v, req.user)) && ['public', 'unlisted'].includes(v.visibility)) {
          title = `${v.title} — ${site}`; description = (v.description || '').slice(0, 200) || `${v.owner_name} · ${site}`; type = 'video.other';
          if (v.thumbnail_path) image = `${base}/media/${v.thumbnail_path}`;
          if (v.allow_embed) extra = `<meta property="og:video" content="${base}/embed/${v.short_id}"><meta property="og:video:type" content="text/html"><meta property="og:video:width" content="1280"><meta property="og:video:height" content="720"><link rel="alternate" type="application/json+oembed" href="${base}/api/oembed?url=${encodeURIComponent(base + '/watch/' + v.short_id)}">`;
          // Разметка schema.org/VideoObject: внутренний поисковый робот и корпоративный портал
          // показывают видео карточкой с обложкой и длительностью (видео-SEO в духе Wistia)
          if (v.visibility === 'public' && s.videoSeo !== false) {
            const ld = {
              '@context': 'https://schema.org', '@type': 'VideoObject',
              name: v.title, description: (v.description || '').slice(0, 500) || v.title,
              thumbnailUrl: v.thumbnail_path ? `${base}/media/${v.thumbnail_path}` : `${base}/og-default.png`,
              uploadDate: v.published_at ? new Date(v.published_at).toISOString() : new Date(v.created_at).toISOString(),
              duration: `PT${Math.max(1, Math.round(Number(v.duration) || 0))}S`,
              contentUrl: v.mp4_path ? `${base}/media/${v.mp4_path}` : undefined,
              embedUrl: v.allow_embed ? `${base}/embed/${v.short_id}` : undefined,
              publisher: { '@type': 'Organization', name: site },
              author: { '@type': 'Person', name: v.owner_name },
              interactionStatistic: { '@type': 'InteractionCounter', interactionType: { '@type': 'WatchAction' }, userInteractionCount: Number(v.view_count) || 0 },
            };
            extra += `<script type="application/ld+json">${JSON.stringify(ld).replace(/</g, '\\u003c')}</script>`;
          }
        } else if (v) { title = `${v.title} — ${site}`; description = 'Видео доступно сотрудникам после входа'; }
      } else if ((m = req.url.match(/^\/@([A-Za-z0-9._-]+)/))) {
        const u = await one('SELECT * FROM users WHERE handle = $1 AND deleted_at IS NULL', [m[1]]);
        if (u) { title = `${u.display_name} — ${site}`; description = u.bio?.slice(0, 200) || `Канал ${u.display_name}`; type = 'profile'; if (u.avatar_path) image = `${base}/media/${u.avatar_path}`; }
      } else if ((m = req.url.match(/^\/live\/([A-Za-z0-9_-]{6,})/))) {
        const l = await one('SELECT * FROM live_streams WHERE short_id = $1', [m[1]]);
        if (l && canViewLive(l, req.user) && l.visibility === 'public') { title = `${l.title} — прямой эфир — ${site}`; description = l.description?.slice(0, 200) || ''; if (l.thumbnail_path) image = `${base}/media/${l.thumbnail_path}`; }
      } else if (req.url.startsWith('/admin')) title = `Администрирование — ${site}`;
      else if (req.url.startsWith('/assignments')) title = `Назначено к просмотру — ${site}`;
      else if (req.url.startsWith('/s/')) title = `Доступ по ссылке — ${site}`;
      else if (req.url.startsWith('/cert/')) title = `Сертификат — ${site}`;
      else if (req.url.startsWith('/shorts')) title = `Короткие видео — ${site}`;
      else if (req.url.startsWith('/studio')) title = `Студия — ${site}`;
      else if (req.url.startsWith('/search')) title = `Поиск — ${site}`;
      else if (req.url.startsWith('/live')) title = `Трансляции — ${site}`;
      else if (req.url.startsWith('/login')) title = `Вход — ${site}`;
      else if (req.url.startsWith('/register')) title = `Регистрация — ${site}`;
    } catch (e) { req.log.warn({ err: e.message }, 'pageMeta'); }
    const tags = `<meta name="description" content="${escapeHtml(description)}">
    <meta property="og:site_name" content="${escapeHtml(site)}">
    <meta property="og:title" content="${escapeHtml(title)}">
    <meta property="og:description" content="${escapeHtml(description)}">
    <meta property="og:type" content="${type}">
    <meta property="og:url" content="${escapeHtml(url)}">
    <meta property="og:image" content="${escapeHtml(image)}">
    <meta name="twitter:card" content="summary_large_image">${extra}`;
    return { title, tags };
  }

  // Манифест PWA с названием и описанием портала из настроек
  // Адреса — с префиксом пути (BASE_PATH), под которым портал виден в браузере
  app.get('/manifest.webmanifest', async (req, reply) => {
    const s = await publicSettings();
    const bp = config.basePath;
    reply.type('application/manifest+json').header('Cache-Control', 'no-cache');
    return {
      name: s.siteName, short_name: s.siteName.length > 14 ? 'Видео' : s.siteName, description: s.description, start_url: `${bp}/?src=pwa`, scope: `${bp}/`, display: 'standalone', orientation: 'any',
      background_color: '#F4F6F9', theme_color: s.accentColor || '#0F4382', lang: 'ru',
      icons: [
        { src: `${bp}/icons/icon-192.png`, sizes: '192x192', type: 'image/png' }, { src: `${bp}/icons/icon-512.png`, sizes: '512x512', type: 'image/png' },
        { src: `${bp}/icons/icon-maskable-512.png`, sizes: '512x512', type: 'image/png', purpose: 'maskable' },
      ],
      shortcuts: [
        { name: 'Назначено мне', url: `${bp}/assignments?src=pwa`, icons: [{ src: `${bp}/icons/icon-192.png`, sizes: '192x192' }] },
        { name: 'Загрузить видео', url: `${bp}/studio/upload?src=pwa`, icons: [{ src: `${bp}/icons/icon-192.png`, sizes: '192x192' }] },
      ],
    };
  });

  // Карта сайта с видео и robots.txt: чтобы публичные ролики попадали в корпоративный поиск
  app.get('/sitemap-video.xml', async (req, reply) => {
    const st = await publicSettings();
    if (st.videoSeo === false) { reply.code(404); return 'disabled'; }
    const base = config.baseUrl;
    const rows = await many(
      `SELECT v.short_id, v.title, v.description, v.duration, v.thumbnail_path, v.published_at, v.view_count
       FROM videos v WHERE v.visibility = 'public' AND v.status = 'ready' AND v.deleted_at IS NULL
         AND v.is_blocked = false AND v.moderation_status = 'approved' AND v.premiere = false
         AND (v.scheduled_at IS NULL OR v.scheduled_at <= now())
       ORDER BY v.published_at DESC NULLS LAST LIMIT 1000`);
    const xml = ['<?xml version="1.0" encoding="UTF-8"?>',
      '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">'];
    for (const v of rows) {
      const loc = `${base}/watch/${v.short_id}`;
      xml.push('<url>', `<loc>${escapeHtml(loc)}</loc>`, '<video:video>',
        `<video:thumbnail_loc>${escapeHtml(v.thumbnail_path ? `${base}/media/${v.thumbnail_path}` : `${base}/og-default.png`)}</video:thumbnail_loc>`,
        `<video:title>${escapeHtml(v.title)}</video:title>`,
        `<video:description>${escapeHtml((v.description || v.title).slice(0, 2000))}</video:description>`,
        `<video:player_loc>${escapeHtml(`${base}/embed/${v.short_id}`)}</video:player_loc>`,
        `<video:duration>${Math.max(1, Math.round(Number(v.duration) || 0))}</video:duration>`,
        v.published_at ? `<video:publication_date>${new Date(v.published_at).toISOString()}</video:publication_date>` : '',
        `<video:view_count>${Number(v.view_count) || 0}</video:view_count>`,
        '</video:video>', '</url>');
    }
    xml.push('</urlset>');
    reply.type('application/xml').header('Cache-Control', 'public, max-age=600');
    return xml.join('');
  });

  // Если в собранном фронтенде остался статический robots.txt (например, после обновления
  // только изменёнными файлами), маршрут не регистрируем: fastify-static уже отдаёт этот путь.
  const hasStaticRobots = fs.existsSync(path.join(config.webDist, 'robots.txt'));
  if (!hasStaticRobots) app.get('/robots.txt', async (req, reply) => {
    const st = await publicSettings();
    reply.type('text/plain').header('Cache-Control', 'public, max-age=3600');
    // Пути — с префиксом BASE_PATH (поисковые роботы читают robots.txt только в корне сайта, поэтому
    // под префиксом эти строки нужно перенести в robots.txt общего портала)
    const bp = config.basePath;
    if (st.videoSeo === false || st.publicHome === false) return `User-agent: *\nDisallow: ${bp}/\n`;
    return `User-agent: *\nAllow: ${bp}/$\nAllow: ${bp}/watch/\nAllow: ${bp}/@\nDisallow: ${bp}/studio\nDisallow: ${bp}/admin\nDisallow: ${bp}/api/\nDisallow: ${bp}/s/\nSitemap: ${config.baseUrl}/sitemap-video.xml\n`;
  });

  // oEmbed для публичных видео
  app.get('/api/oembed', async (req, reply) => {
    const m = String(req.query.url || '').match(/\/watch\/([A-Za-z0-9_-]{6,})/);
    if (!m) { reply.code(404); return { error: 'not found' }; }
    const v = await one('SELECT v.*, u.display_name AS owner_name, u.handle AS owner_handle FROM videos v JOIN users u ON u.id = v.owner_id WHERE v.short_id = $1 AND v.deleted_at IS NULL', [m[1]]);
    if (!v || !['public', 'unlisted'].includes(v.visibility) || !v.allow_embed) { reply.code(404); return { error: 'not found' }; }
    const s = await publicSettings();
    const w = Number(req.query.maxwidth) || 1280, h = Math.round(w * 9 / 16);
    return {
      version: '1.0', type: 'video', provider_name: s.siteName, provider_url: config.baseUrl, title: v.title,
      author_name: v.owner_name, author_url: `${config.baseUrl}/@${v.owner_handle}`, width: w, height: h,
      thumbnail_url: v.thumbnail_path ? `${config.baseUrl}/media/${v.thumbnail_path}` : undefined, thumbnail_width: 1280, thumbnail_height: 720,
      html: `<iframe width="${w}" height="${h}" src="${config.baseUrl}/embed/${v.short_id}" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>`,
    };
  });

  return app;
}
