// RSS/Atom-ленты (как у каналов YouTube) и подкаст-ленты (аудиодорожка) для каналов, категорий,
// плейлистов, тегов, новых видео и подписок. Личный токен ?ft=… даёт доступ к видео «для сотрудников».
import fsp from 'node:fs/promises';
import { one, many, query } from '../db.js';
import { notFound, forbidden } from '../lib/util.js';
import { listVisibilitySql } from '../lib/access.js';
import { randomToken } from '../lib/crypto.js';
import { config } from '../config.js';
import { feedTokenUser } from './media.js';
import { storage } from '../lib/storage.js';

// Символы, запрещённые в XML 1.0, и «одинокие» суррогаты ломают любой читатель лент — вычищаем до экранирования.
const XML_FORBIDDEN = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]|[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g;
const esc = (s) => String(s ?? '').replace(XML_FORBIDDEN, '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Размер файла вложения: подкаст-клиентам нужен размер именно того файла, на который ведёт ссылка. */
async function withEnclosureSizes(items, audio) {
  await Promise.all((items || []).map(async (v) => {
    const rel = audio ? (v.audio_path || v.mp4_path) : (v.mp4_path || v.audio_path);
    if (!rel) return;
    try { v.enclosure_size = (await fsp.stat(storage.abs(rel))).size; } catch { /* файл мог быть перенесён */ }
  }));
  return items;
}
const rfc822 = (d) => new Date(d || Date.now()).toUTCString();
const iso = (d) => new Date(d || Date.now()).toISOString();
const fmtDur = (sec) => { const s = Math.max(0, Math.round(Number(sec) || 0)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(x).padStart(2, '0')}`; };

const SELECT = `v.id, v.short_id, v.title, v.description, v.duration, v.published_at, v.created_at, v.updated_at, v.thumbnail_path, v.mp4_path, v.audio_path, v.original_size, v.storage_bytes, v.tags, v.view_count, u.display_name AS owner_name, u.handle AS owner_handle, c.name AS category_name`;
const FROM = `videos v JOIN users u ON u.id = v.owner_id LEFT JOIN categories c ON c.id = v.category_id`;

function whereBase(user) {
  return `v.deleted_at IS NULL AND v.status = 'ready' AND v.moderation_status = 'approved' AND v.published_at IS NOT NULL AND ${listVisibilitySql(user)}`;
}

function mediaAbs(rel, ft) { return `${config.baseUrl}/media/${rel}${ft ? `?ft=${encodeURIComponent(ft)}` : ''}`; }

function buildRss({ title, link, description, items, audio, ft, self, image }) {
  // xmlns:atom объявлен ниже в самом теге <rss>; повторное объявление здесь делало XML невалидным
  const ns = audio ? ' xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/"' : ' xmlns:media="http://search.yahoo.com/mrss/"';
  let out = `<?xml version="1.0" encoding="UTF-8"?>\n<rss version="2.0"${ns} xmlns:atom="http://www.w3.org/2005/Atom">\n<channel>\n<title>${esc(title)}</title>\n<link>${esc(link)}</link>\n<description>${esc(description)}</description>\n<language>ru</language>\n<lastBuildDate>${rfc822(items[0]?.published_at)}</lastBuildDate>\n<atom:link href="${esc(self)}" rel="self" type="application/rss+xml"/>\n<generator>CorpVideo</generator>\n`;
  if (image) out += `<image><url>${esc(image)}</url><title>${esc(title)}</title><link>${esc(link)}</link></image>\n`;
  if (audio) out += `<itunes:author>${esc(title)}</itunes:author>\n<itunes:explicit>false</itunes:explicit>\n${image ? `<itunes:image href="${esc(image)}"/>\n` : ''}`;
  for (const v of items) {
    const url = `${config.baseUrl}/watch/${v.short_id}`;
    const enclosureRel = audio ? (v.audio_path || v.mp4_path) : (v.mp4_path || v.audio_path);
    const thumb = v.thumbnail_path ? mediaAbs(v.thumbnail_path, ft) : null;
    out += `<item>\n<title>${esc(v.title)}</title>\n<link>${esc(url)}</link>\n<guid isPermaLink="true">${esc(url)}</guid>\n<pubDate>${rfc822(v.published_at)}</pubDate>\n<author>${esc(v.owner_name)}</author>\n${v.category_name ? `<category>${esc(v.category_name)}</category>\n` : ''}<description>${esc((v.description || '').slice(0, 2000))}</description>\n`;
    if (enclosureRel) out += `<enclosure url="${esc(mediaAbs(enclosureRel, ft))}" type="${enclosureRel.endsWith('.m4a') ? 'audio/mp4' : 'video/mp4'}" length="${Number(v.enclosure_size) || Number(v.original_size) || 0}"/>\n`;
    if (audio) out += `<itunes:duration>${fmtDur(v.duration)}</itunes:duration>\n<itunes:author>${esc(v.owner_name)}</itunes:author>\n${thumb ? `<itunes:image href="${esc(thumb)}"/>\n` : ''}`;
    else if (thumb) out += `<media:thumbnail url="${esc(thumb)}"/>\n<media:content url="${esc(url)}" medium="video" duration="${Math.round(Number(v.duration) || 0)}"/>\n`;
    out += `</item>\n`;
  }
  return out + `</channel>\n</rss>\n`;
}

function buildAtom({ title, link, items, ft, self }) {
  let out = `<?xml version="1.0" encoding="UTF-8"?>\n<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">\n<title>${esc(title)}</title>\n<link href="${esc(link)}"/>\n<link rel="self" href="${esc(self)}"/>\n<id>${esc(self)}</id>\n<updated>${iso(items[0]?.published_at)}</updated>\n<generator>CorpVideo</generator>\n`;
  for (const v of items) {
    const url = `${config.baseUrl}/watch/${v.short_id}`;
    out += `<entry>\n<title>${esc(v.title)}</title>\n<link href="${esc(url)}"/>\n<id>${esc(url)}</id>\n<published>${iso(v.published_at)}</published>\n<updated>${iso(v.updated_at || v.published_at)}</updated>\n<author><name>${esc(v.owner_name)}</name></author>\n<summary>${esc((v.description || '').slice(0, 2000))}</summary>\n`;
    if (v.thumbnail_path) out += `<media:thumbnail url="${esc(mediaAbs(v.thumbnail_path, ft))}"/>\n`;
    out += `</entry>\n`;
  }
  return out + `</feed>\n`;
}

export default async function feedsRoutes(app) {
  async function ctx(req) {
    if (!req.settings['feeds.enabled']) throw forbidden('RSS-ленты отключены администратором');
    const user = await feedTokenUser(req);
    const limit = Math.min(200, Math.max(1, Number(req.query.limit) || Number(req.settings['feeds.items']) || 30));
    return { user, limit, ft: user && req.query.ft ? String(req.query.ft) : null, audio: req.query.audio === '1' || req.query.podcast === '1', atom: req.query.format === 'atom' };
  }
  async function send(reply, req, c, { title, link, description, items, image }) {
    const self = `${config.baseUrl}${req.raw.url}`;
    if (!c.atom) await withEnclosureSizes(items, c.audio);
    const xml = c.atom ? buildAtom({ title, link, items, ft: c.ft, self }) : buildRss({ title, link, description, items, audio: c.audio, ft: c.ft, self, image });
    reply.header('Content-Type', `${c.atom ? 'application/atom+xml' : 'application/rss+xml'}; charset=utf-8`);
    reply.header('Cache-Control', 'private, max-age=300');
    return xml;
  }
  const siteName = (req) => req.settings['site.name'] || 'CorpVideo';
  const logo = (req) => (req.settings['site.logo_path'] ? `${config.baseUrl}/media/${req.settings['site.logo_path']}` : `${config.baseUrl}/icons/icon-512.png`);

  app.get('/latest', async (req, reply) => {
    const c = await ctx(req);
    const items = await many(`SELECT ${SELECT} FROM ${FROM} WHERE ${whereBase(c.user)} ORDER BY v.published_at DESC LIMIT $1`, [c.limit]);
    return send(reply, req, c, { title: `${siteName(req)} — новые видео`, link: `${config.baseUrl}/latest`, description: 'Новые видео на портале', items, image: logo(req) });
  });

  app.get('/channel/:handle', async (req, reply) => {
    const c = await ctx(req);
    const u = await one(`SELECT id, display_name, handle, avatar_path, bio AS channel_description FROM users WHERE handle = $1 AND deleted_at IS NULL`, [String(req.params.handle).toLowerCase()]);
    if (!u) throw notFound('Канал не найден');
    const items = await many(`SELECT ${SELECT} FROM ${FROM} WHERE v.owner_id = $2 AND ${whereBase(c.user)} ORDER BY v.published_at DESC LIMIT $1`, [c.limit, u.id]);
    return send(reply, req, c, { title: `${u.display_name} — ${siteName(req)}`, link: `${config.baseUrl}/@${u.handle}`, description: u.channel_description || `Видео канала ${u.display_name}`, items, image: u.avatar_path ? mediaAbs(u.avatar_path, c.ft) : logo(req) });
  });

  app.get('/category/:slug', async (req, reply) => {
    const c = await ctx(req);
    const cat = await one('SELECT * FROM categories WHERE slug = $1 OR id::text = $1', [String(req.params.slug)]);
    if (!cat) throw notFound('Категория не найдена');
    const items = await many(`SELECT ${SELECT} FROM ${FROM} WHERE v.category_id = $2 AND ${whereBase(c.user)} ORDER BY v.published_at DESC LIMIT $1`, [c.limit, cat.id]);
    return send(reply, req, c, { title: `${cat.name} — ${siteName(req)}`, link: `${config.baseUrl}/category/${cat.slug}`, description: cat.description || `Видео категории «${cat.name}»`, items, image: logo(req) });
  });

  app.get('/tag/:tag', async (req, reply) => {
    const c = await ctx(req);
    const tag = String(req.params.tag).toLowerCase().slice(0, 60);
    const items = await many(`SELECT ${SELECT} FROM ${FROM} WHERE $2 = ANY(v.tags) AND ${whereBase(c.user)} ORDER BY v.published_at DESC LIMIT $1`, [c.limit, tag]);
    return send(reply, req, c, { title: `#${tag} — ${siteName(req)}`, link: `${config.baseUrl}/tag/${encodeURIComponent(tag)}`, description: `Видео с тегом «${tag}»`, items, image: logo(req) });
  });

  app.get('/playlist/:id', async (req, reply) => {
    const c = await ctx(req);
    const pl = await one(`SELECT p.*, u.display_name AS owner_name FROM playlists p JOIN users u ON u.id = p.owner_id WHERE p.id = cv_uuid($1)`, [String(req.params.id)]);
    if (!pl) throw notFound('Плейлист не найден');
    if (pl.visibility === 'private' && (!c.user || (c.user.id !== pl.owner_id && !['admin', 'moderator'].includes(c.user.role)))) throw notFound('Плейлист не найден');
    const items = await many(`SELECT ${SELECT}, pi.position FROM playlist_items pi JOIN ${FROM} ON v.id = pi.video_id WHERE pi.playlist_id = $2 AND ${whereBase(c.user)} ORDER BY pi.position LIMIT $1`, [c.limit, pl.id]);
    return send(reply, req, c, { title: `${pl.title} — ${siteName(req)}`, link: `${config.baseUrl}/playlist/${pl.id}`, description: pl.description || `Плейлист «${pl.title}» (${pl.owner_name})`, items, image: logo(req) });
  });

  app.get('/subscriptions', async (req, reply) => {
    const c = await ctx(req);
    if (!c.user) throw forbidden('Лента подписок доступна только по личному токену (?ft=…)');
    const items = await many(`SELECT ${SELECT} FROM ${FROM} WHERE v.owner_id IN (SELECT channel_id FROM subscriptions WHERE subscriber_id = $2) AND ${whereBase(c.user)} ORDER BY v.published_at DESC LIMIT $1`, [c.limit, c.user.id]);
    return send(reply, req, c, { title: `Мои подписки — ${siteName(req)}`, link: `${config.baseUrl}/subscriptions`, description: 'Новые видео каналов, на которые вы подписаны', items, image: logo(req) });
  });
}

/** Личный токен RSS: выдать (создать при отсутствии) или пересоздать. */
export async function ensureFeedToken(userId, { reset = false } = {}) {
  const u = await one('SELECT feed_token FROM users WHERE id = $1', [userId]);
  if (u?.feed_token && !reset) return u.feed_token;
  const token = randomToken(24);
  await query('UPDATE users SET feed_token = $2, feed_token_created_at = now() WHERE id = $1', [userId, token]);
  return token;
}
