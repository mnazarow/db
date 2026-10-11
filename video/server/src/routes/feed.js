// Ленты: главная (подборки), тренды, подписки, новые, категории, короткие видео.
import { one, many } from '../db.js';
import { listVisibilitySql, isActive } from '../lib/access.js';
import { videoCard, categoryOut, liveOut } from '../lib/serialize.js';
import { paging, notFound, forbidden } from '../lib/util.js';
import { VIDEO_SELECT, VIDEO_FROM } from './videos.js';
import { mediaUrl } from '../lib/urls.js';

function guestGate(req) {
  if (!req.user && !req.settings['site.public_home']) throw forbidden('Для просмотра портала необходимо войти');
}

export default async function feedRoutes(app) {
  app.get('/home', async (req) => {
    guestGate(req);
    const vis = listVisibilitySql(req.user);
    const sections = [];
    const user = req.user;

    if (isActive(user)) {
      // Назначенные к обязательному просмотру и ещё не завершённые
      const assigned = await many(
        `SELECT DISTINCT ON (v.id) ${VIDEO_SELECT}, a.due_at, a.id AS assignment_id, a.title AS assignment_title, p.percent AS progress_percent
         FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
         JOIN ${VIDEO_FROM} ON (v.id = a.video_id OR v.id IN (SELECT video_id FROM playlist_items WHERE playlist_id = a.playlist_id))
         LEFT JOIN assignment_progress p ON p.assignment_id = a.id AND p.user_id = $1 AND p.video_id = v.id
         WHERE a.status = 'active' AND v.deleted_at IS NULL AND v.status = 'ready' AND p.completed_at IS NULL
           AND (t.target_type = 'all' OR t.user_id = $1 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $1)))
         ORDER BY v.id, a.due_at NULLS LAST`,
        [user.id],
      );
      if (assigned.length) {
        assigned.sort((x, y) => (x.due_at ? new Date(x.due_at) : Infinity) - (y.due_at ? new Date(y.due_at) : Infinity));
        sections.push({ key: 'assigned', title: 'Назначено вам к просмотру', link: '/assignments', videos: assigned.slice(0, 8).map((r) => videoCard(r, { dueAt: r.due_at, assignmentId: r.assignment_id, assignmentTitle: r.assignment_title, progressPercent: r.progress_percent || 0 })) });
      }
      const cont = await many(
        `SELECT * FROM (
           SELECT DISTINCT ON (v.id) ${VIDEO_SELECT}, vv.position AS progress_position, vv.last_at
           FROM video_views vv JOIN ${VIDEO_FROM} ON v.id = vv.video_id
           WHERE vv.user_id = $1 AND vv.completed = false AND vv.position > 15 AND v.duration > 60 AND vv.position < v.duration * 0.95 AND ${vis}
           ORDER BY v.id, vv.last_at DESC
         ) t ORDER BY last_at DESC LIMIT 8`,
        [user.id],
      );
      if (cont.length) sections.push({ key: 'continue', title: 'Продолжить просмотр', videos: cont.map((r) => videoCard(r)) });

      const subs = await many(
        `SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.owner_id IN (SELECT channel_id FROM subscriptions WHERE subscriber_id = $1) AND ${vis}
         ORDER BY v.published_at DESC LIMIT 12`,
        [user.id],
      );
      if (subs.length) sections.push({ key: 'subscriptions', title: 'Из ваших подписок', link: '/subscriptions', videos: subs.map((r) => videoCard(r)) });
    }

    const live = await many(
      `SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar FROM live_streams s JOIN users u ON u.id = s.owner_id
       WHERE s.status = 'live' AND ${isActive(user) ? "s.visibility IN ('public','internal')" : "s.visibility = 'public'"} ORDER BY s.started_at DESC LIMIT 8`,
    );
    if (live.length) sections.push({ key: 'live', title: 'Сейчас в эфире', link: '/live', streams: live.map((s) => liveOut(s, { viewer: user })) });

    const latest = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE ${vis} ORDER BY v.published_at DESC LIMIT 24`);
    if (latest.length) sections.push({ key: 'latest', title: 'Новые видео', link: '/latest', videos: latest.map((r) => videoCard(r)) });

    // Сначала агрегируем двухнедельную статистику одним проходом по индексу дня и сужаем набор
    // кандидатов (самые просматриваемые за две недели + самые популярные за всё время),
    // а не считаем подзапрос по каждому видимому видео при каждом заходе на главную.
    const popular = await many(
      `WITH recent AS (
         SELECT video_id, sum(views)::bigint AS recent_views FROM video_stats_daily
         WHERE day >= current_date - 14 GROUP BY video_id
       ), cand AS (
         (SELECT video_id AS id FROM recent ORDER BY recent_views DESC LIMIT 300)
         UNION
         (SELECT id FROM videos WHERE deleted_at IS NULL ORDER BY view_count DESC LIMIT 100)
       )
       SELECT ${VIDEO_SELECT}, coalesce(r.recent_views, 0) AS recent_views
       FROM ${VIDEO_FROM} JOIN cand ON cand.id = v.id LEFT JOIN recent r ON r.video_id = v.id
       WHERE ${vis} ORDER BY recent_views DESC, v.view_count DESC LIMIT 12`,
    );
    if (popular.some((p) => Number(p.recent_views) > 0 || p.view_count > 0)) sections.push({ key: 'popular', title: 'Популярное за две недели', link: '/trending', videos: popular.map((r) => videoCard(r)) });

    // Подборки по интересам: категории просмотренных видео / активные категории
    let cats;
    if (isActive(user)) {
      cats = await many(
        `SELECT c.* FROM categories c WHERE c.is_active AND c.id IN (
           SELECT v.category_id FROM video_views vv JOIN videos v ON v.id = vv.video_id WHERE vv.user_id = $1 AND v.category_id IS NOT NULL
           GROUP BY v.category_id ORDER BY count(*) DESC LIMIT 3)`,
        [user.id],
      );
    }
    if (!cats || !cats.length) cats = await many(`SELECT c.* FROM categories c WHERE c.is_active AND c.video_count > 0 ORDER BY c.video_count DESC, c.sort_order LIMIT 3`);
    for (const c of cats) {
      const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.category_id = $1 AND ${vis} ORDER BY v.published_at DESC LIMIT 12`, [c.id]);
      if (rows.length) sections.push({ key: `cat:${c.slug}`, title: c.name, link: `/category/${c.slug}`, videos: rows.map((r) => videoCard(r)) });
    }

    const shorts = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.is_short AND ${vis} ORDER BY v.published_at DESC LIMIT 12`);
    if (shorts.length) sections.push({ key: 'shorts', title: 'Короткие видео', link: '/shorts', videos: shorts.map((r) => videoCard(r)), layout: 'shorts' });

    return { sections };
  });

  app.get('/trending', async (req) => {
    guestGate(req);
    const days = { day: 1, week: 7, month: 30, year: 365 }[req.query.period] || 7;
    const { limit, offset, page } = paging(req.query, 24, 100);
    // Статистика за период агрегируется один раз и присоединяется хешем — вместо двух
    // коррелированных подзапросов на каждое видимое видео.
    const rows = await many(
      `WITH recent AS (
         SELECT video_id, sum(views)::bigint AS recent_views, sum(watch_seconds)::bigint AS recent_watch
         FROM video_stats_daily WHERE day >= current_date - $1::int GROUP BY video_id
       )
       SELECT * FROM (
         SELECT ${VIDEO_SELECT}, coalesce(r.recent_views, 0) AS recent_views, coalesce(r.recent_watch, 0) AS recent_watch
         FROM ${VIDEO_FROM} LEFT JOIN recent r ON r.video_id = v.id
         WHERE ${listVisibilitySql(req.user)}
       ) t ORDER BY (recent_views * 10 + recent_watch / 60.0 + like_count * 3 + comment_count * 2) DESC, published_at DESC LIMIT $2 OFFSET $3`,
      [days, limit, offset],
    );
    return { videos: rows.map((r) => videoCard(r, { recentViews: Number(r.recent_views) })), page, limit };
  });

  app.get('/subscriptions', { preHandler: app.requireActive }, async (req) => {
    const { limit, offset, page } = paging(req.query, 24, 100);
    const rows = await many(
      `SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.owner_id IN (SELECT channel_id FROM subscriptions WHERE subscriber_id = $1) AND ${listVisibilitySql(req.user)}
       ORDER BY v.published_at DESC LIMIT $2 OFFSET $3`,
      [req.user.id, limit, offset],
    );
    return { videos: rows.map((r) => videoCard(r)), page, limit };
  });

  app.get('/latest', async (req) => {
    guestGate(req);
    const { limit, offset, page } = paging(req.query, 24, 100);
    const params = [limit, offset];
    let where = listVisibilitySql(req.user);
    if (req.query.category) { params.push(String(req.query.category)); where += ` AND c.slug = $${params.length}`; }
    const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE ${where} ORDER BY v.published_at DESC LIMIT $1 OFFSET $2`, params);
    return { videos: rows.map((r) => videoCard(r)), page, limit };
  });

  app.get('/shorts', async (req) => {
    guestGate(req);
    const { limit, offset, page } = paging(req.query, 12, 50);
    const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.is_short AND ${listVisibilitySql(req.user)} ORDER BY v.published_at DESC LIMIT $1 OFFSET $2`, [limit, offset]);
    // Для ленты со свайпом: источники плеера, лайк и подписка зрителя
    let likes = new Set(); let subs = new Set();
    if (req.user && rows.length) {
      likes = new Set((await many('SELECT video_id FROM video_likes WHERE user_id = $1 AND value = 1 AND video_id = ANY($2::uuid[])', [req.user.id, rows.map((r) => r.id)])).map((r) => r.video_id));
      subs = new Set((await many('SELECT channel_id FROM subscriptions WHERE subscriber_id = $1', [req.user.id])).map((r) => r.channel_id));
    }
    return { videos: rows.map((r) => videoCard(r, { hlsUrl: mediaUrl(r.hls_path), mp4Url: mediaUrl(r.mp4_path), liked: likes.has(r.id), subscribed: subs.has(r.owner_id), tags: r.tags || [] })), page, limit };
  });

  app.get('/categories', async (req) => {
    const rows = await many('SELECT * FROM categories WHERE is_active ORDER BY sort_order, name');
    return { categories: rows.map(categoryOut) };
  });

  app.get('/categories/:slug', async (req) => {
    guestGate(req);
    const c = await one('SELECT * FROM categories WHERE slug = $1 AND is_active', [req.params.slug]);
    if (!c) throw notFound('Категория не найдена');
    const { limit, offset, page } = paging(req.query, 24, 100);
    const sort = { popular: 'v.view_count DESC', oldest: 'v.published_at ASC' }[req.query.sort] || 'v.published_at DESC';
    const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE v.category_id = $1 AND ${listVisibilitySql(req.user)} ORDER BY ${sort} LIMIT $2 OFFSET $3`, [c.id, limit, offset]);
    const total = await one(`SELECT count(*)::int AS n FROM ${VIDEO_FROM} WHERE v.category_id = $1 AND ${listVisibilitySql(req.user)}`, [c.id]);
    return { category: categoryOut(c), videos: rows.map((r) => videoCard(r)), total: total.n, page, limit };
  });

  // Видео по тегу
  app.get('/tags/:tag', async (req) => {
    guestGate(req);
    const { limit, offset, page } = paging(req.query, 24, 100);
    const tag = String(req.params.tag).slice(0, 40);
    const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE $1 ILIKE ANY(v.tags) AND ${listVisibilitySql(req.user)} ORDER BY v.published_at DESC LIMIT $2 OFFSET $3`, [tag, limit, offset]);
    return { tag, videos: rows.map((r) => videoCard(r)), page, limit };
  });
}
