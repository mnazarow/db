// Порядок в хранилище: что занимает место, кто сколько занимает, исходники старше срока.
import { one, many } from '../../db.js';
import { videoCard } from '../../lib/serialize.js';
import { toCsv, sendCsv } from '../../lib/csv.js';

export default async function adminStorageRoutes(app) {
  app.get('/storage', { preHandler: app.requireStaff }, async (req) => {
    const totals = await one(
      `SELECT coalesce(sum(storage_bytes),0)::bigint AS bytes,
              count(*)::int AS videos,
              coalesce(sum(storage_bytes) FILTER (WHERE deleted_at IS NOT NULL), 0)::bigint AS trash_bytes,
              count(*) FILTER (WHERE deleted_at IS NOT NULL)::int AS trash_videos,
              coalesce(sum(original_size) FILTER (WHERE original_kept AND original_path IS NOT NULL AND deleted_at IS NULL), 0)::bigint AS originals_bytes,
              count(*) FILTER (WHERE original_kept AND original_path IS NOT NULL AND deleted_at IS NULL)::int AS originals
       FROM videos`);
    const byOwner = await many(
      // Так же, как считает квота: плюс файлы проектов графического редактора
      `SELECT u.id, u.display_name, u.email, count(v.id)::int AS videos,
              (coalesce(sum(v.storage_bytes),0) + coalesce(sum((SELECT sum(a.bytes) FROM project_assets a WHERE a.video_id = v.id)),0))::bigint AS bytes
       FROM users u JOIN videos v ON v.owner_id = u.id AND v.deleted_at IS NULL
       GROUP BY u.id, u.display_name, u.email ORDER BY bytes DESC LIMIT 20`);
    const biggest = await many(
      `SELECT v.*, u.display_name AS owner_name, u.handle AS owner_handle
       FROM videos v JOIN users u ON u.id = v.owner_id
       WHERE v.deleted_at IS NULL ORDER BY v.storage_bytes DESC LIMIT 20`);
    const oldOriginals = await one(
      `SELECT count(*)::int AS n, coalesce(sum(original_size),0)::bigint AS bytes FROM videos
       WHERE original_kept AND original_path IS NOT NULL AND deleted_at IS NULL AND created_at < now() - interval '180 days'`);
    const quotaMb = Number(req.settings['storage.quota_mb']) || 0;
    return {
      totals: {
        bytes: Number(totals.bytes), videos: totals.videos,
        trashBytes: Number(totals.trash_bytes), trashVideos: totals.trash_videos,
        originalsBytes: Number(totals.originals_bytes), originals: totals.originals,
      },
      quotaMb,
      oldOriginals: { count: oldOriginals.n, bytes: Number(oldOriginals.bytes) },
      byOwner: byOwner.map((o) => ({
        id: o.id, displayName: o.display_name, email: o.email, videos: o.videos, bytes: Number(o.bytes),
        percentOfQuota: quotaMb ? Math.round((Number(o.bytes) / (quotaMb * 1024 * 1024)) * 100) : null,
      })),
      biggest: biggest.map((v) => ({ ...videoCard(v), storageBytes: Number(v.storage_bytes), owner: { displayName: v.owner_name, handle: v.owner_handle } })),
    };
  });

  app.get('/storage/export', { preHandler: app.requireStaff }, async (req, reply) => {
    const rows = await many(
      `SELECT v.short_id, v.title, v.storage_bytes, v.duration, v.created_at, v.visibility, u.display_name AS owner
       FROM videos v JOIN users u ON u.id = v.owner_id WHERE v.deleted_at IS NULL ORDER BY v.storage_bytes DESC LIMIT 5000`);
    const csv = toCsv(rows, [
      { title: 'Ссылка', value: (r) => `/watch/${r.short_id}` },
      { title: 'Название', value: 'title' },
      { title: 'Автор', value: 'owner' },
      { title: 'Мегабайт', value: (r) => Math.round(Number(r.storage_bytes) / (1024 * 1024)) },
      { title: 'Длительность, с', value: (r) => Math.round(Number(r.duration) || 0) },
      { title: 'Доступ', value: 'visibility' },
      { title: 'Загружено', value: (r) => new Date(r.created_at).toLocaleString('ru-RU') },
    ]);
    return sendCsv(reply, 'storage.csv', csv);
  });
}
