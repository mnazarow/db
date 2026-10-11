// Поиск: полнотекстовый по видео (название, теги, описание, расшифровка речи), каналы, плейлисты, трансляции; подсказки; история.
import { one, many, query } from '../db.js';
import { listVisibilitySql, isActive } from '../lib/access.js';
import { videoCard, userPublic, playlistOut, liveOut } from '../lib/serialize.js';
import { paging, forbidden, badRequest, safeHeadline, likeEscape } from '../lib/util.js';
import { askLibrary, logAsk } from '../lib/videoqa.js';
import { VIDEO_SELECT, VIDEO_FROM } from './videos.js';

function dateFilter(d) {
  return { hour: "v.published_at > now() - interval '1 hour'", day: "v.published_at > now() - interval '1 day'", week: "v.published_at > now() - interval '7 days'", month: "v.published_at > now() - interval '30 days'", year: "v.published_at > now() - interval '365 days'" }[d] || null;
}
function durationFilter(d) {
  return { short: 'v.duration < 240', medium: 'v.duration BETWEEN 240 AND 1200', long: 'v.duration > 1200' }[d] || null;
}

export default async function searchRoutes(app) {
  app.get('/', async (req) => {
    if (!req.user && !req.settings['site.public_home']) throw forbidden('Для поиска необходимо войти');
    const q = String(req.query.q || '').trim().slice(0, 200);
    const type = ['video', 'channel', 'playlist', 'live'].includes(req.query.type) ? req.query.type : 'video';
    const { limit, offset, page } = paging(req.query, 20, 50);
    const out = { q, type, page, limit };
    if (req.user && q && page === 1 && req.query.record !== '0') {
      await query('DELETE FROM search_history WHERE user_id = $1 AND query = $2', [req.user.id, q]);
      await query('INSERT INTO search_history(user_id, query) VALUES ($1,$2)', [req.user.id, q]);
    }

    if (type === 'channel') {
      const rows = await many(
        `SELECT u.*, similarity(u.display_name, $1) AS sim FROM users u WHERE u.status = 'active' AND u.deleted_at IS NULL AND (u.display_name ILIKE $2 OR u.handle ILIKE $2 OR u.display_name % $1)
         ORDER BY sim DESC, u.subscriber_count DESC LIMIT $3 OFFSET $4`,
        [q, `%${likeEscape(q)}%`, limit, offset],
      );
      out.channels = rows.map(userPublic);
      return out;
    }
    if (type === 'playlist') {
      const vis = isActive(req.user) ? "p.visibility IN ('public','internal')" : "p.visibility = 'public'";
      const rows = await many(
        `SELECT p.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar,
           (SELECT v.thumbnail_path FROM playlist_items pi JOIN videos v ON v.id = pi.video_id WHERE pi.playlist_id = p.id AND v.deleted_at IS NULL ORDER BY pi.position LIMIT 1) AS first_thumbnail
         FROM playlists p JOIN users u ON u.id = p.owner_id WHERE ${vis} AND p.kind = 'normal' AND p.item_count > 0 AND (p.title ILIKE $1 OR p.description ILIKE $1) ORDER BY p.updated_at DESC LIMIT $2 OFFSET $3`,
        [`%${likeEscape(q)}%`, limit, offset],
      );
      out.playlists = rows.map(playlistOut);
      return out;
    }
    if (type === 'live') {
      const vis = isActive(req.user) ? "s.visibility IN ('public','internal')" : "s.visibility = 'public'";
      const rows = await many(
        `SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar FROM live_streams s JOIN users u ON u.id = s.owner_id
         WHERE ${vis} AND s.status IN ('live','idle') AND (s.title ILIKE $1 OR s.description ILIKE $1) ORDER BY s.status = 'live' DESC, s.created_at DESC LIMIT $2 OFFSET $3`,
        [`%${likeEscape(q)}%`, limit, offset],
      );
      out.streams = rows.map((s) => liveOut(s, { viewer: req.user }));
      return out;
    }

    // Видео
    const params = [];
    const where = [listVisibilitySql(req.user)];
    let rank = '0';
    if (q) {
      params.push(q);
      const qi = params.length;
      // websearch_to_tsquery поддерживает кавычки и минус; дополнительно — триграммы по названию для опечаток
      where.push(`(v.search_vector @@ websearch_to_tsquery('russian', unaccent($${qi})) OR v.title ILIKE '%' || cv_like($${qi}) || '%' OR v.title % $${qi} OR $${qi} ILIKE ANY(v.tags))`);
      rank = `ts_rank_cd(v.search_vector, websearch_to_tsquery('russian', unaccent($${qi}))) * 10 + similarity(v.title, $${qi}) * 3 + (CASE WHEN v.title ILIKE '%' || cv_like($${qi}) || '%' THEN 2 ELSE 0 END)`;
    }
    const df = dateFilter(req.query.date); if (df) where.push(df);
    const dur = durationFilter(req.query.duration); if (dur) where.push(dur);
    if (req.query.category) { params.push(String(req.query.category)); where.push(`c.slug = $${params.length}`); }
    if (req.query.channel) { params.push(String(req.query.channel).replace(/^@/, '')); where.push(`u.handle = $${params.length}`); }
    if (req.query.subtitles === '1') where.push(`EXISTS(SELECT 1 FROM subtitles st WHERE st.video_id = v.id AND st.status = 'ready')`);
    if (req.query.hd === '1') where.push('(v.height >= 720 OR v.width >= 720)');
    const sort = { date: 'v.published_at DESC', views: 'v.view_count DESC', rating: '(v.like_count - v.dislike_count) DESC, v.view_count DESC', relevance: `${rank} DESC, v.view_count DESC` }[req.query.sort] || (q ? `${rank} DESC, v.published_at DESC` : 'v.published_at DESC');
    params.push(limit, offset);
    const rows = await many(
      `SELECT ${VIDEO_SELECT}, ${rank} AS rank, ts_headline('russian', coalesce(v.transcript, ''), websearch_to_tsquery('russian', unaccent(${q ? '$1' : "''"})), 'MaxWords=18, MinWords=8, StartSel=<b>, StopSel=</b>, MaxFragments=1') AS transcript_hit,
              ts_headline('russian', coalesce(v.screen_text, ''), websearch_to_tsquery('russian', unaccent(${q ? '$1' : "''"})), 'MaxWords=14, MinWords=6, StartSel=<b>, StopSel=</b>, MaxFragments=1') AS screen_hit
       FROM ${VIDEO_FROM} WHERE ${where.join(' AND ')} ORDER BY ${sort} LIMIT $${params.length - 1} OFFSET $${params.length}`,
      params,
    );
    const total = await one(`SELECT count(*)::int AS n FROM ${VIDEO_FROM} WHERE ${where.join(' AND ')}`, params.slice(0, params.length - 2));
    out.videos = rows.map((r) => videoCard(r, {
      transcriptHit: q && r.transcript_hit && /<b>/.test(r.transcript_hit) ? safeHeadline(r.transcript_hit) : undefined,
      screenTextHit: q && r.screen_hit && /<b>/.test(r.screen_hit) ? safeHeadline(r.screen_hit) : undefined,
    }));
    out.total = total.n;
    return out;
  });

  // --- «Спросите видеотеку»: ответ ИИ с ссылками на моменты (1.5) --------------------------------
  app.post('/ask', { preHandler: app.requireActive }, async (req) => {
    if (!req.settings['ai.enabled'] || !req.settings['search.ask_enabled']) throw forbidden('ИИ-поиск по видеотеке отключён администратором');
    const q = String(req.body?.q || '').trim().slice(0, 300);
    if (q.length < 5) throw badRequest('Сформулируйте вопрос подробнее');
    const started = Date.now();
    const res = await askLibrary(q, req.user, { settings: req.settings });
    const ms = Date.now() - started;
    if (!res.empty) await logAsk({ userId: req.user.id, question: q, answer: res.answer, sources: res.sources, ms }).catch(() => {});
    return { ...res, ms };
  });

  app.get('/suggest', async (req) => {
    const q = String(req.query.q || '').trim().slice(0, 100);
    const out = [];
    if (req.user) {
      const hist = await many('SELECT DISTINCT ON (query) query FROM search_history WHERE user_id = $1 AND ($2 = \'\' OR query ILIKE $3) ORDER BY query, created_at DESC LIMIT 5', [req.user.id, q, `${likeEscape(q)}%`]);
      out.push(...hist.map((h) => ({ text: h.query, kind: 'history' })));
    }
    if (q.length >= 2) {
      const titles = await many(`SELECT v.title FROM videos v WHERE ${listVisibilitySql(req.user)} AND (v.title ILIKE $1 OR v.title % $2) ORDER BY similarity(v.title, $2) DESC, v.view_count DESC LIMIT 6`, [`%${likeEscape(q)}%`, q]);
      for (const t of titles) if (!out.some((o) => o.text.toLowerCase() === t.title.toLowerCase())) out.push({ text: t.title, kind: 'video' });
      const tags = await many(`SELECT DISTINCT t FROM videos v, unnest(v.tags) t WHERE ${listVisibilitySql(req.user)} AND t ILIKE $1 LIMIT 4`, [`${likeEscape(q)}%`]);
      for (const t of tags) out.push({ text: t.t, kind: 'tag' });
      const chans = await many(`SELECT display_name, handle FROM users WHERE status = 'active' AND deleted_at IS NULL AND (display_name ILIKE $1 OR handle ILIKE $1) ORDER BY subscriber_count DESC LIMIT 3`, [`%${likeEscape(q)}%`]);
      for (const c of chans) out.push({ text: c.display_name, kind: 'channel', handle: c.handle });
    }
    return { suggestions: out.slice(0, 12) };
  });

  app.get('/history', { preHandler: app.requireAuth }, async (req) => {
    const rows = await many('SELECT DISTINCT ON (query) query, created_at FROM search_history WHERE user_id = $1 ORDER BY query, created_at DESC', [req.user.id]);
    return { queries: rows.sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).slice(0, 50).map((r) => r.query) };
  });
  app.delete('/history', { preHandler: app.requireAuth }, async (req) => {
    if (req.query.q) await query('DELETE FROM search_history WHERE user_id = $1 AND query = $2', [req.user.id, String(req.query.q)]);
    else await query('DELETE FROM search_history WHERE user_id = $1', [req.user.id]);
    return { ok: true };
  });
}
