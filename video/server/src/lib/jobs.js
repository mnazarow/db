// Очередь фоновых заданий в PostgreSQL (SELECT … FOR UPDATE SKIP LOCKED).
import { one, query, many, publish, withTxLock } from '../db.js';

/**
 * Поставить задание в очередь. dedupe=true — не создавать, если такое же уже ждёт/выполняется:
 * для видео — по videoId, для остального — по dedupeKey (например restream:<id>, stream:<id>).
 * Без ключа дедупликация заданий, не привязанных к видео, не работала вовсе: переподключение
 * источника эфира заводило вторую ретрансляцию на ту же площадку.
 */
export async function enqueue(type, payload = {}, { videoId = null, priority = 0, runAt = null, maxAttempts = 3, dedupe = true, dedupeKey = null } = {}) {
  let row;
  if (dedupe && !videoId && dedupeKey) {
    const key = String(dedupeKey);
    const res = await withTxLock(`enqueue:${type}:${key}`, async (c) => {
      const dup = await c.one(`SELECT * FROM jobs WHERE type = $1 AND payload->>'_dedupe' = $2 AND status IN ('queued','running')`, [type, key]);
      if (dup) return { dup: true, row: dup };
      return { dup: false, row: await c.one(...insertArgs(type, { ...payload, _dedupe: key }, null, priority, runAt, maxAttempts)) };
    });
    if (res.dup) return res.row;
    await publish({ type: 'job.enqueued', jobId: res.row.id, jobType: type });
    return res.row;
  }
  if (dedupe && videoId) {
    // «Посмотрели и вставили» под блокировкой: без неё двойной клик на «Обработать заново»
    // ставил два задания на одно видео, и два ffmpeg писали один каталог.
    row = await withTxLock(`enqueue:${type}:${videoId}`, async (c) => {
      const dup = await c.one(`SELECT * FROM jobs WHERE type = $1 AND video_id = $2 AND status IN ('queued','running')`, [type, videoId]);
      if (dup) return { dup: true, row: dup };
      return { dup: false, row: await c.one(...insertArgs(type, payload, videoId, priority, runAt, maxAttempts)) };
    });
    if (row.dup) return row.row;
    row = row.row;
  } else {
    row = await one(...insertArgs(type, payload, videoId, priority, runAt, maxAttempts));
  }
  await publish({ type: 'job.enqueued', jobId: row.id, jobType: type });
  return row;
}

function insertArgs(type, payload, videoId, priority, runAt, maxAttempts) {
  return [
    `INSERT INTO jobs(type, payload, video_id, priority, run_at, max_attempts) VALUES ($1, $2::jsonb, $3, $4, COALESCE($5, now()), $6) RETURNING *`,
    [type, JSON.stringify(payload), videoId, priority, runAt, maxAttempts],
  ];
}

/**
 * Задание кончилось неудачей или отменено — снять «в обработке» с того, что оно обрабатывало.
 * Без этого видео, субтитры и итоги встречи оставались в промежуточном статусе навсегда:
 * отменили транскодирование из панели — и запись вечно «обрабатывается».
 */
export async function finalizeJobEntity(job, message = 'Задание не выполнено') {
  const err = String(message).slice(0, 2000);
  const vid = job.video_id;
  const OWNS_VIDEO = ['transcode', 'live_import', 'import_url', 'video_edit', 'remove_silence', 'video_blur', 'video_render'];
  try {
    if (vid && OWNS_VIDEO.includes(job.type)) {
      await query(`UPDATE videos SET status = 'failed', processing_error = $2, processing_stage = NULL WHERE id = $1 AND status IN ('uploading','queued','processing')`, [vid, err]);
    }
    if (vid && ['subtitles_asr', 'subtitle_translate'].includes(job.type)) {
      await query(`UPDATE subtitles SET status = 'failed', error = $2 WHERE video_id = $1 AND status = 'processing'`, [vid, err]);
    }
    if (vid && job.type === 'meeting_notes') {
      await query(`UPDATE meeting_notes SET status = 'failed', error = $2, updated_at = now() WHERE video_id = $1 AND status = 'processing'`, [vid, err]);
    }
    if (vid && job.type === 'ocr') {
      await query(`UPDATE videos SET ocr_status = 'failed' WHERE id = $1 AND ocr_status IN ('queued','processing')`, [vid]);
    }
  } catch { /* завершение статуса не должно ронять обработку ошибки */ }
}

/** Захватить следующее задание (для воркера). */
export async function claimJob(workerId, types = null) {
  const typeFilter = types && types.length ? 'AND type = ANY($2)' : '';
  const params = types && types.length ? [workerId, types] : [workerId];
  return one(
    `UPDATE jobs SET status = 'running', locked_at = now(), locked_by = $1, heartbeat_at = now(), attempts = attempts + 1, started_at = now(), error = NULL
     WHERE id = (
       SELECT id FROM jobs WHERE status = 'queued' AND run_at <= now() ${typeFilter}
       ORDER BY priority DESC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED
     ) RETURNING *`,
    params,
  );
}

export async function heartbeat(jobId, { progress = null, stage = null } = {}) {
  await query(
    `UPDATE jobs SET heartbeat_at = now(), progress = COALESCE($2, progress), stage = COALESCE($3, stage) WHERE id = $1`,
    [jobId, progress, stage],
  );
}

export async function completeJob(jobId, result = null) {
  await query(`UPDATE jobs SET status = 'done', progress = 100, finished_at = now(), result = $2::jsonb, locked_by = NULL WHERE id = $1`, [jobId, result ? JSON.stringify(result) : null]);
}

export async function failJob(job, error, { retry = true, delaySec = 60 } = {}) {
  const message = String(error?.message || error).slice(0, 4000);
  const canRetry = retry && job.attempts < job.max_attempts;
  await query(
    `UPDATE jobs SET status = $2, error = $3, finished_at = CASE WHEN $2 = 'failed' THEN now() ELSE NULL END,
       run_at = CASE WHEN $2 = 'queued' THEN now() + ($4 || ' seconds')::interval ELSE run_at END, locked_by = NULL, locked_at = NULL
     WHERE id = $1`,
    [job.id, canRetry ? 'queued' : 'failed', message, String(delaySec * job.attempts)],
  );
  return !canRetry;
}

/** Вернуть в очередь зависшие задания (воркер умер). */
export async function reapStaleJobs(staleMinutes = 5) {
  const rows = await many(
    `UPDATE jobs SET status = CASE WHEN attempts < max_attempts THEN 'queued' ELSE 'failed' END,
       error = COALESCE(error, '') || ' [задание зависло и было перезапущено]', locked_by = NULL, locked_at = NULL, run_at = now()
     WHERE status = 'running' AND heartbeat_at < now() - ($1 || ' minutes')::interval RETURNING id, status, video_id, type, max_attempts, attempts`,
    [String(staleMinutes)],
  );
  for (const r of rows) if (r.status === 'failed') await finalizeJobEntity(r, 'Задание зависло и было снято');
  return rows;
}

/**
 * Вернуть в очередь задания этого воркера, оставшиеся в статусе running (перезапуск, падение).
 * ВАЖНО: попытку не «возвращаем» — задание с max_attempts = 1 (монтаж, клип) не должно
 * выполниться второй раз: часть работы могла быть уже применена к видео.
 */
export async function requeueOwnJobs(workerId, { ids = null, staleSeconds = null } = {}) {
  // ids — задания этого процесса (при остановке); staleSeconds — только давно не бившиеся (при старте):
  // при нескольких воркерах с одинаковым WORKER_ID нельзя трогать то, что прямо сейчас выполняется у соседа.
  const cond = []; const params = [workerId];
  if (ids) { if (!ids.length) return []; params.push(ids.map(Number)); cond.push(`id = ANY($${params.length})`); }
  if (staleSeconds != null) { params.push(String(staleSeconds)); cond.push(`heartbeat_at < now() - ($${params.length} || ' seconds')::interval`); }
  const rows = await many(
    `UPDATE jobs SET status = CASE WHEN attempts < max_attempts THEN 'queued' ELSE 'failed' END,
       error = CASE WHEN attempts < max_attempts THEN error ELSE trim(COALESCE(error, '') || ' [воркер остановлен во время выполнения]') END,
       finished_at = CASE WHEN attempts < max_attempts THEN NULL ELSE now() END,
       locked_by = NULL, locked_at = NULL, run_at = now()
     WHERE status = 'running' AND locked_by = $1${cond.length ? ' AND ' + cond.join(' AND ') : ''} RETURNING id, type, status`,
    params,
  );
  return rows;
}

export async function retryJob(jobId) {
  return one(`UPDATE jobs SET status = 'queued', run_at = now(), error = NULL, attempts = 0, progress = 0 WHERE id = $1 AND status IN ('failed','cancelled') RETURNING *`, [jobId]);
}

/**
 * Отменить задание. Возвращает строку задания с полем outcome: 'cancelled' (отменено сразу) или
 * 'cancelling' (воркер получил просьбу прервать). Раньше отменённое в очереди видео оставалось
 * «в очереди» навсегда, а задание «зависшего» воркера нельзя было отменить вовсе.
 */
export async function cancelJob(jobId) {
  const row = await one(`UPDATE jobs SET status = 'cancelled', error = 'Отменено', finished_at = now() WHERE id = $1 AND status = 'queued' RETURNING *`, [jobId]);
  if (row) { await finalizeJobEntity(row, 'Задание отменено'); return { ...row, outcome: 'cancelled' }; }
  const running = await one(`SELECT * FROM jobs WHERE id = $1 AND status = 'running'`, [jobId]);
  if (!running) return null;
  await publish({ type: 'job.cancel', jobId: running.id });
  // Воркер, который его держал, не подаёт признаков жизни — завершаем сами, не дожидаясь уборки
  const silent = !running.heartbeat_at || Date.now() - new Date(running.heartbeat_at).getTime() > 2 * 60000;
  if (silent) {
    const r = await query(`UPDATE jobs SET status = 'cancelled', error = 'Отменено (воркер не отвечал)', finished_at = now(), locked_by = NULL WHERE id = $1 AND status = 'running'`, [running.id]);
    if (r.rowCount) { await finalizeJobEntity(running, 'Задание отменено'); return { ...running, status: 'cancelled', outcome: 'cancelled' }; }
  }
  return { ...running, outcome: 'cancelling' };
}

export async function queueStats() {
  const rows = await many(`SELECT status, count(*)::int AS n FROM jobs GROUP BY status`);
  const out = { queued: 0, running: 0, done: 0, failed: 0, cancelled: 0 };
  for (const r of rows) out[r.status] = r.n;
  return out;
}
