// Квота на канал сотрудника (storage.quota_mb): сколько места занимают его видео и файлы проектов.
import { forbidden } from './util.js';

/**
 * Занятое место, байт. Видео, которые ещё загружаются или обрабатываются, считаются по размеру
 * исходника: иначе десяток одновременно начатых загрузок проходил проверку при «пустом» канале.
 */
export async function storageUsedBytes(db, userId) {
  const r = await db.one(
    `SELECT coalesce((SELECT sum(CASE WHEN v.status IN ('uploading','queued','processing')
                                      THEN greatest(coalesce(v.storage_bytes, 0), coalesce(v.original_size, 0))
                                      ELSE coalesce(v.storage_bytes, 0) END)
                      FROM videos v WHERE v.owner_id = $1 AND v.deleted_at IS NULL), 0)
          + coalesce((SELECT sum(a.bytes) FROM project_assets a JOIN videos v ON v.id = a.video_id
                      WHERE v.owner_id = $1 AND v.deleted_at IS NULL), 0) AS b`, [userId]);
  return Number(r?.b) || 0;
}

/**
 * Проверить квоту перед добавлением addBytes. db — клиент транзакции: вызывающий сначала берёт
 * блокировку lockQuota(), чтобы параллельные загрузки одного сотрудника проверялись по очереди.
 */
export async function assertQuota(db, req, addBytes = 0) {
  const quotaMb = Number(req.settings?.['storage.quota_mb']) || 0;
  if (!quotaMb || req.user?.role === 'admin') return;
  const used = await storageUsedBytes(db, req.user.id);
  if (used + Math.max(0, Number(addBytes) || 0) > quotaMb * 1024 * 1024) {
    throw forbidden(`Место на канале закончилось: занято ${Math.round(used / 1048576)} МБ из ${quotaMb} МБ. Удалите ненужные видео или попросите администратора увеличить квоту.`);
  }
}

export const lockQuota = (db, userId) => db.query('SELECT pg_advisory_xact_lock(hashtext($1))', [`quota:${userId}`]);
