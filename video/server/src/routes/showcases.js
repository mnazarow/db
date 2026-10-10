// Витрины — брендированные страницы-подборки со своим адресом (как Wistia Channels и Brightcove Gallery).
// Публичная часть: список для меню и страница витрины. Администраторская: создание, разделы, обложка.
import path from 'node:path';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import { one, many, query, tx } from '../db.js';
import { badRequest, notFound, forbidden, slugify, extOf } from '../lib/util.js';
import { videoCard } from '../lib/serialize.js';
import { listVisibilitySql, isActive, isStaff, canSeeShowcase } from '../lib/access.js';
import { storage, ensureDir } from '../lib/storage.js';
import { audit } from '../lib/audit.js';
import { VIDEO_SELECT, VIDEO_FROM } from './videos.js';
import { mediaUrl } from '../lib/urls.js';

const KINDS = ['videos', 'playlist', 'category', 'tag', 'latest'];
const LAYOUTS = ['grid', 'row', 'hero'];

function showcaseOut(s, { sections = null } = {}) {
  return {
    id: s.id, slug: s.slug, title: s.title, subtitle: s.subtitle || '', description: s.description || '',
    coverUrl: mediaUrl(s.cover_path), accent: s.accent || null,
    visibility: s.visibility, inMenu: !!s.in_menu, position: s.position, enabled: !!s.enabled,
    viewCount: Number(s.view_count) || 0, createdAt: s.created_at, updatedAt: s.updated_at,
    ...(sections ? { sections } : {}),
  };
}

const canSee = canSeeShowcase;
/** Цвет витрины — только #RGB/#RRGGBB(AA); остальное отбрасываем, чтобы в стиль не попал произвольный CSS. */
const validAccent = (v) => (v && /^#[0-9a-fA-F]{3,8}$/.test(String(v).trim()) ? String(v).trim() : null);

/** Видео раздела витрины: вручную выбранные, плейлист, категория, тег или просто новые. */
async function sectionVideos(sec, user) {
  const vis = listVisibilitySql(user, 'v');
  const limit = Math.max(1, Math.min(48, sec.max_items || 12));
  if (sec.kind === 'videos') {
    return many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} JOIN showcase_items si ON si.video_id = v.id
                 WHERE si.section_id = $1 AND ${vis} ORDER BY si.position, v.published_at DESC NULLS LAST LIMIT ${limit}`, [sec.id]);
  }
  if (sec.kind === 'playlist' && sec.playlist_id) {
    return many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} JOIN playlist_items pi ON pi.video_id = v.id
                 WHERE pi.playlist_id = $1 AND ${vis} ORDER BY pi.position LIMIT ${limit}`, [sec.playlist_id]);
  }
  if (sec.kind === 'category' && sec.category_id) {
    return many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.category_id = $1 AND ${vis} ORDER BY v.published_at DESC NULLS LAST LIMIT ${limit}`, [sec.category_id]);
  }
  if (sec.kind === 'tag' && sec.tag) {
    return many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE $1 = ANY(v.tags) AND ${vis} ORDER BY v.published_at DESC NULLS LAST LIMIT ${limit}`, [sec.tag]);
  }
  return many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE ${vis} ORDER BY v.published_at DESC NULLS LAST LIMIT ${limit}`, []);
}

export default async function showcaseRoutes(app) {
  // --- Публичная часть ------------------------------------------------------------------
  app.get('/showcases', async (req) => {
    if (!req.settings['showcases.enabled']) return { showcases: [] };
    const vis = isActive(req.user) || isStaff(req.user) ? `visibility IN ('public','internal')` : `visibility = 'public'`;
    const rows = await many(`SELECT * FROM showcases WHERE enabled AND ${vis} ${req.query.menu !== undefined ? 'AND in_menu' : ''} ORDER BY position, title`, []);
    return { showcases: rows.map((s) => showcaseOut(s)) };
  });

  app.get('/showcases/:slug', async (req) => {
    if (!req.settings['showcases.enabled']) throw notFound('Витрины отключены');
    const sc = await one('SELECT * FROM showcases WHERE slug = $1 OR id = cv_uuid($1)', [String(req.params.slug)]);
    if (!sc) throw notFound('Витрина не найдена');
    if (!canSee(sc, req.user)) throw req.user ? forbidden('Нет доступа к витрине') : notFound('Витрина не найдена');
    const secs = await many('SELECT * FROM showcase_sections WHERE showcase_id = $1 ORDER BY position', [sc.id]);
    const sections = [];
    for (const sec of secs) {
      const vids = await sectionVideos(sec, req.user);
      sections.push({
        id: sec.id, title: sec.title, note: sec.note || '', kind: sec.kind, layout: sec.layout,
        playlistId: sec.playlist_id, categoryId: sec.category_id, tag: sec.tag, maxItems: sec.max_items,
        videos: vids.map(videoCard),
      });
    }
    if (!isStaff(req.user)) await query('UPDATE showcases SET view_count = view_count + 1 WHERE id = $1', [sc.id]).catch(() => {});
    return { showcase: showcaseOut(sc, { sections }) };
  });

  // --- Панель администратора ------------------------------------------------------------
  app.get('/admin/showcases', { preHandler: app.requireAdmin }, async () => {
    const rows = await many(`SELECT s.*, (SELECT count(*)::int FROM showcase_sections x WHERE x.showcase_id = s.id) AS sections FROM showcases s ORDER BY s.position, s.title`, []);
    return { showcases: rows.map((s) => ({ ...showcaseOut(s), sections: s.sections })) };
  });

  app.post('/admin/showcases', { preHandler: app.requireAdmin }, async (req) => {
    const b = req.body || {};
    const title = String(b.title || '').trim().slice(0, 120);
    if (!title) throw badRequest('Укажите название витрины');
    let slug = slugify(String(b.slug || title)).slice(0, 60) || `hub-${Date.now().toString(36)}`;
    if (await one('SELECT 1 FROM showcases WHERE slug = $1', [slug])) slug = `${slug}-${Date.now().toString(36).slice(-4)}`;
    const row = await one(
      `INSERT INTO showcases(slug, title, subtitle, description, visibility, in_menu, position, accent, created_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING *`,
      [slug, title, String(b.subtitle || '').slice(0, 200), String(b.description || '').slice(0, 4000),
        ['public', 'internal', 'unlisted'].includes(b.visibility) ? b.visibility : 'internal',
        !!b.inMenu, Number(b.position) || 0, validAccent(b.accent), req.user.id],
    );
    await audit(req, 'showcase.create', { targetType: 'showcase', targetId: row.id, details: { slug, title } });
    return { showcase: showcaseOut(row) };
  });

  app.patch('/admin/showcases/:id', { preHandler: app.requireAdmin }, async (req) => {
    const sc = await one('SELECT * FROM showcases WHERE id = $1', [req.params.id]);
    if (!sc) throw notFound('Витрина не найдена');
    const b = req.body || {};
    const sets = []; const params = [sc.id];
    const add = (c, v) => { params.push(v); sets.push(`${c} = $${params.length}`); };
    if (b.title !== undefined) { const t = String(b.title).trim().slice(0, 120); if (!t) throw badRequest('Пустое название'); add('title', t); }
    if (b.slug !== undefined) {
      const slug = slugify(String(b.slug)).slice(0, 60);
      if (!slug) throw badRequest('Некорректный адрес');
      if (await one('SELECT 1 FROM showcases WHERE slug = $1 AND id <> $2', [slug, sc.id])) throw badRequest('Такой адрес уже занят');
      add('slug', slug);
    }
    if (b.subtitle !== undefined) add('subtitle', String(b.subtitle).slice(0, 200));
    if (b.description !== undefined) add('description', String(b.description).slice(0, 4000));
    if (b.visibility !== undefined && ['public', 'internal', 'unlisted'].includes(b.visibility)) add('visibility', b.visibility);
    if (b.inMenu !== undefined) add('in_menu', !!b.inMenu);
    if (b.enabled !== undefined) add('enabled', !!b.enabled);
    if (b.position !== undefined) add('position', Number(b.position) || 0);
    if (b.accent !== undefined) add('accent', validAccent(b.accent));
    if (sets.length) { sets.push('updated_at = now()'); await query(`UPDATE showcases SET ${sets.join(', ')} WHERE id = $1`, params); }
    await audit(req, 'showcase.update', { targetType: 'showcase', targetId: sc.id, details: Object.keys(b) });
    return { showcase: showcaseOut(await one('SELECT * FROM showcases WHERE id = $1', [sc.id])) };
  });

  app.delete('/admin/showcases/:id', { preHandler: app.requireAdmin }, async (req) => {
    const sc = await one('SELECT * FROM showcases WHERE id = $1', [req.params.id]);
    if (!sc) throw notFound('Витрина не найдена');
    await query('DELETE FROM showcases WHERE id = $1', [sc.id]);
    await audit(req, 'showcase.delete', { targetType: 'showcase', targetId: sc.id, details: { slug: sc.slug } });
    return { ok: true };
  });

  // Разделы витрины заменяются целиком — так проще и не остаётся висячих ссылок
  app.put('/admin/showcases/:id/sections', { preHandler: app.requireAdmin }, async (req) => {
    const sc = await one('SELECT * FROM showcases WHERE id = $1', [req.params.id]);
    if (!sc) throw notFound('Витрина не найдена');
    const list = (Array.isArray(req.body?.sections) ? req.body.sections : []).slice(0, 20);
    await tx(async (c) => {
      await c.query('DELETE FROM showcase_sections WHERE showcase_id = $1', [sc.id]);
      for (const [i, sec] of list.entries()) {
        const kind = KINDS.includes(sec.kind) ? sec.kind : 'videos';
        const r = await c.query(
          `INSERT INTO showcase_sections(showcase_id, title, note, kind, playlist_id, category_id, tag, max_items, layout, position)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING id`,
          [sc.id, String(sec.title || '').slice(0, 120), String(sec.note || '').slice(0, 500), kind,
            kind === 'playlist' && sec.playlistId ? sec.playlistId : null,
            kind === 'category' && sec.categoryId ? Number(sec.categoryId) : null,
            kind === 'tag' && sec.tag ? String(sec.tag).slice(0, 60) : null,
            Math.max(1, Math.min(48, Number(sec.maxItems) || 12)), LAYOUTS.includes(sec.layout) ? sec.layout : 'grid', i],
        );
        const sectionId = r.rows[0].id;
        if (kind === 'videos') {
          const ids = (Array.isArray(sec.videoIds) ? sec.videoIds : []).slice(0, 48);
          for (const [j, vid] of ids.entries()) {
            await c.query('INSERT INTO showcase_items(section_id, video_id, position) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING', [sectionId, vid, j]);
          }
        }
      }
      await c.query('UPDATE showcases SET updated_at = now() WHERE id = $1', [sc.id]);
    });
    const secs = await many('SELECT * FROM showcase_sections WHERE showcase_id = $1 ORDER BY position', [sc.id]);
    const out = [];
    for (const sec of secs) {
      const items = sec.kind === 'videos'
        ? await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} JOIN showcase_items si ON si.video_id = v.id WHERE si.section_id = $1 AND v.deleted_at IS NULL ORDER BY si.position`, [sec.id])
        : [];
      out.push({ id: sec.id, title: sec.title, note: sec.note, kind: sec.kind, layout: sec.layout, playlistId: sec.playlist_id, categoryId: sec.category_id, tag: sec.tag, maxItems: sec.max_items, videos: items.map(videoCard) });
    }
    await audit(req, 'showcase.sections', { targetType: 'showcase', targetId: sc.id, details: { sections: out.length } });
    return { sections: out };
  });

  // Разделы для редактирования (с выбранными вручную видео, даже скрытыми из каталога)
  app.get('/admin/showcases/:id/sections', { preHandler: app.requireAdmin }, async (req) => {
    const sc = await one('SELECT * FROM showcases WHERE id = $1', [req.params.id]);
    if (!sc) throw notFound('Витрина не найдена');
    const secs = await many('SELECT * FROM showcase_sections WHERE showcase_id = $1 ORDER BY position', [sc.id]);
    const out = [];
    for (const sec of secs) {
      const items = sec.kind === 'videos'
        ? await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} JOIN showcase_items si ON si.video_id = v.id WHERE si.section_id = $1 AND v.deleted_at IS NULL ORDER BY si.position`, [sec.id])
        : [];
      out.push({ id: sec.id, title: sec.title, note: sec.note, kind: sec.kind, layout: sec.layout, playlistId: sec.playlist_id, categoryId: sec.category_id, tag: sec.tag, maxItems: sec.max_items, videos: items.map(videoCard) });
    }
    return { showcase: showcaseOut(sc), sections: out };
  });

  app.post('/admin/showcases/:id/cover', { preHandler: app.requireAdmin }, async (req) => {
    const sc = await one('SELECT * FROM showcases WHERE id = $1', [req.params.id]);
    if (!sc) throw notFound('Витрина не найдена');
    const part = await req.file({ limits: { fileSize: 6 * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл');
    const ext = extOf(part.filename);
    if (!['png', 'jpg', 'jpeg', 'webp'].includes(ext)) throw badRequest('Поддерживаются PNG, JPG и WebP');
    const dir = path.join(storage.root, 'showcases');
    await ensureDir(dir);
    const file = path.join(dir, `${sc.id}_${Date.now()}.${ext}`);
    await pipeline(part.file, fs.createWriteStream(file));
    const rel = storage.rel(file);
    await query('UPDATE showcases SET cover_path = $2, updated_at = now() WHERE id = $1', [sc.id, rel]);
    return { coverUrl: mediaUrl(rel) };
  });
}
