// Прочие задания: письма, импорт записи трансляции, обслуживание (расписание публикаций, очистка, агрегаты).
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, query, many, publish, withLock } from '../db.js';
import { sendMailNow } from '../lib/mailer.js';
import { storage, ensureDir, exists, removeDir, removeFile } from '../lib/storage.js';
import { loadSettings } from '../lib/settings.js';
import { enqueue } from '../lib/jobs.js';
import { notifyMany, notifySubscribersNewVideo, notifyPremiereAudience, notify } from '../lib/notify.js';
import { captionsToVtt } from './captions.js';
import { runFfmpeg } from '../lib/ffmpeg.js';
import { shortId } from '../lib/crypto.js';
import { config } from '../config.js';
import { sendReminders } from '../lib/assignments.js';
import { sendLiveReminders } from '../routes/live-interact.js';
import { emitEvent, eventVideo } from '../lib/events.js';
import { scanWatchFolder, sendDigests } from './platform.js';
import { runRetention } from './retention.js';
import { runWebinarMaintenance } from './webinar.js';
import { applyFreshDefault } from '../lib/lifecycle.js';
import { syncPortalDirectory, portalSyncDue } from '../lib/portal-sync.js';

export async function runEmail(job) {
  const { to, subject, html, text } = job.payload;
  await sendMailNow({ to, subject, html, text });
  return { to };
}

/**
 * Найти файлы записи эфира в каталоге MediaMTX и при необходимости склеить сегменты в один.
 * Возвращает путь к файлу или null, если записи нет.
 */
async function findLiveRecording(job, stream, ctx) {
  const key = String(job.payload.streamKey || stream.stream_key || '');
  if (!/^[A-Za-z0-9_-]+$/.test(key)) return null;
  const dir = path.join(config.liveRecordDir, 'recordings', 'live', key);
  const names = await fsp.readdir(dir).catch(() => []);
  const since = job.payload.startedAt ? new Date(job.payload.startedAt).getTime() - 60000 : 0;
  const fresh = [];
  for (const n of names) {
    if (!/\.(mp4|ts|mkv)$/.test(n)) continue;
    const f = path.join(dir, n);
    const st = await fsp.stat(f).catch(() => null);
    if (st && st.isFile() && st.mtimeMs >= since && st.size > 10000) fresh.push({ f, mtime: st.mtimeMs, size: st.size });
  }
  if (!fresh.length) return null;
  fresh.sort((a, b) => a.mtime - b.mtime);
  // Последний сегмент может ещё дописываться — тогда повторим попытку позже
  const last = fresh[fresh.length - 1];
  await new Promise((r) => setTimeout(r, 3000));
  const again = await fsp.stat(last.f).catch(() => null);
  if (again && again.size !== last.size) throw new Error('Файл записи ещё пишется');
  if (fresh.length === 1) return last.f;
  const list = path.join(dir, `concat_${job.id}.txt`);
  const out = path.join(dir, `joined_${job.id}.mp4`);
  await fsp.writeFile(list, fresh.map((x) => `file '${x.f.replace(/'/g, "'\\''")}'`).join('\n'));
  try {
    await runFfmpeg(['-f', 'concat', '-safe', '0', '-i', list, '-c', 'copy', out], { label: 'concat', signal: ctx?.signal });
  } finally { await fsp.unlink(list).catch(() => {}); }
  for (const x of fresh) await fsp.unlink(x.f).catch(() => {});
  return out;
}

/** Импорт записи завершённого эфира: файл записи → новое видео → обработка. */
export async function runLiveImport(job, ctx) {
  const { streamId } = job.payload;
  const stream = await one('SELECT s.*, u.display_name AS owner_name FROM live_streams s JOIN users u ON u.id = s.owner_id WHERE s.id = $1', [streamId]);
  if (!stream) {
    // Трансляцию удалили сразу после окончания — забирать запись некуда, это не ошибка
    if (job.payload.discover) return { skipped: 'трансляция удалена' };
    throw Object.assign(new Error('Трансляция не найдена'), { noRetry: true });
  }
  let src = job.payload.file;
  if (!src && job.payload.discover) {
    src = await findLiveRecording(job, stream, ctx);
    if (!src) return { skipped: 'запись эфира не найдена' };
  }
  if (!src) throw Object.assign(new Error('Не указан файл записи'), { noRetry: true });
  if (!(await exists(src))) throw new Error('Файл записи ещё не появился: ' + src);
  // Дожидаемся, пока файл перестанет расти (MediaMTX закрывает сегмент)
  const st1 = await fsp.stat(src);
  await new Promise((r) => setTimeout(r, 3000));
  const st2 = await fsp.stat(src);
  if (st1.size !== st2.size) throw new Error('Файл записи ещё пишется');
  if (st2.size < 10000) { await fsp.unlink(src).catch(() => {}); return { skipped: 'empty' }; }

  const s = await loadSettings(true);
  const videoId = crypto.randomUUID();
  const dir = storage.videoDir(videoId);
  await ensureDir(dir);
  const ext = path.extname(src).slice(1) || 'mp4';
  const dest = path.join(dir, `original.${ext}`);
  await fsp.rename(src, dest).catch(async () => { await fsp.copyFile(src, dest); await fsp.unlink(src); });
  const title = `${stream.title} — запись эфира ${new Date(stream.started_at || Date.now()).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })}`;
  await query(
    `INSERT INTO videos(id, short_id, owner_id, title, description, category_id, visibility, status, moderation_status, original_filename, original_size, original_path, is_live_recording, live_stream_id, comments_mode, recording_started_at)
     VALUES ($1,$2,$3,$4,$5,$6,$7,'queued',$8,$9,$10,$11,true,$12,$13,$14)`,
    [videoId, shortId(), stream.owner_id, title, stream.description || '', stream.category_id, stream.visibility,
      s['upload.premoderation'] ? 'pending' : 'approved', path.basename(src), st2.size, storage.rel(dest), stream.id, s['comments.default_mode'], stream.started_at || null],
  );
  await applyFreshDefault(videoId, s);
  await query('UPDATE live_streams SET recording_video_id = $2 WHERE id = $1', [stream.id, videoId]);
  // Живые субтитры эфира сразу становятся субтитрами записи — расшифровку не нужно ждать (1.6)
  try {
    const caps = await many('SELECT seq, offset_sec, text FROM live_captions WHERE stream_id = $1 ORDER BY seq', [stream.id]);
    if (caps.length) {
      const vtt = captionsToVtt(caps);
      const subDir = storage.videoDir(videoId);
      await ensureDir(subDir);
      const subPath = path.join(subDir, 'subs_live_ru.vtt');
      await fsp.writeFile(subPath, vtt, 'utf8');
      await query(
        `INSERT INTO subtitles(video_id, language, label, kind, status, path, is_default) VALUES ($1,'ru','Русские (эфир)','auto','ready',$2,true)`,
        [videoId, storage.rel(subPath)]);
    }
  } catch (e) { ctx?.log?.warn({ err: e.message }, 'live captions → subtitles'); }
  await enqueue('transcode', { videoId }, { videoId, priority: 1 });
  await notify(stream.owner_id, { type: 'live_recording', title: 'Запись эфира сохранена', body: title, link: `/studio/videos/${videoId}`, data: { videoId } });
  return { videoId };
}

/**
 * Удалить устаревшие строки пачками по 10 000. Одна огромная DELETE на многомиллионной таблице
 * раздувает журнал упреждающей записи, надолго держит блокировки и мешает автоочистке.
 * Условие where — доверенный текст из кода, значения передаются параметрами.
 */
export async function purge(table, where, params = [], { batch = 10000, max = 2000000 } = {}) {
  let total = 0;
  for (;;) {
    const r = await query(`DELETE FROM ${table} WHERE ctid = ANY(ARRAY(SELECT ctid FROM ${table} WHERE ${where} LIMIT ${batch}))`, params);
    total += r.rowCount;
    if (r.rowCount < batch || total >= max) break;
    await new Promise((res) => setImmediate(res));   // даём дышать остальным запросам
  }
  return total;
}

const TMP_PATTERN = /^(?:render-([0-9a-f-]{36})-[0-9a-z]+|([0-9a-f-]{36})-(?:blur|edit)-\d+\.part\.mp4|cv-pv-.+\.txt)$/;

/** Удалить брошенные временные файлы заданий старше 12 часов в каталоге загрузок. */
export async function cleanupJobTemp({ olderThanMs = 12 * 3600 * 1000 } = {}) {
  const dir = config.uploadTmpDir;
  const names = await fsp.readdir(dir).catch(() => []);
  let removed = 0;
  for (const name of names) {
    const m = TMP_PATTERN.exec(name);
    if (!m) continue;
    const full = path.join(dir, name);
    const st = await fsp.stat(full).catch(() => null);
    if (!st || Date.now() - st.mtimeMs < olderThanMs) continue;
    const videoId = m[1] || m[2];
    if (videoId) {
      const busy = await one(`SELECT 1 FROM jobs WHERE video_id = $1 AND status = 'running' LIMIT 1`, [videoId]);
      if (busy) continue;
    }
    await fsp.rm(full, { recursive: true, force: true }).catch(() => {});
    removed += 1;
  }
  return removed;
}

/** Обслуживание: выполняется воркером раз в минуту. */
export async function runMaintenance(job, ctx) {
  // При нескольких воркерах обслуживание должно выполняться ровно один раз:
  // иначе дайджесты рассылаются дважды, а папка автоимпорта обрабатывается параллельно.
  const r = await withLock('corpvideo:maintenance', () => maintenanceBody(job, ctx));
  if (r.ok) return r.value;
  // Ручной запуск (с hourly/daily) не отбрасываем: повторим, когда освободится блокировка
  if (job.payload?.hourly || job.payload?.daily) {
    await enqueue('maintenance', job.payload, { dedupe: false, maxAttempts: 1, runAt: new Date(Date.now() + 30000) });
    return { requeued: 'обслуживание занято другим воркером — повтор через 30 секунд' };
  }
  return { skipped: 'обслуживание уже выполняется другим воркером' };
}

async function maintenanceBody(job, ctx) {
  const s = await loadSettings(true);
  const out = {};
  // 1. Отложенная публикация
  const due = await many(
    `UPDATE videos SET published_at = now(), scheduled_at = NULL WHERE scheduled_at IS NOT NULL AND scheduled_at <= now() AND status = 'ready' AND deleted_at IS NULL RETURNING *`,
  );
  for (const v of due) {
    const owner = await one('SELECT id, display_name FROM users WHERE id = $1', [v.owner_id]);
    await publish({ type: 'video.published', videoId: v.id, ownerId: v.owner_id });
    if (['public', 'internal'].includes(v.visibility) && v.moderation_status === 'approved') await notifySubscribersNewVideo(v, owner);
    await emitEvent('video.published', { video: eventVideo(v) });
  }
  out.published = due.length;
  // 1a. Премьеры: за 30 минут зовём подписчиков, в назначенный час начинаем показ (как на YouTube)
  try {
    const soon = await many(
      `UPDATE videos SET premiere_notified_at = now()
       WHERE premiere = true AND premiere_notified_at IS NULL AND status = 'ready' AND deleted_at IS NULL
         AND scheduled_at > now() AND scheduled_at <= now() + interval '30 minutes' RETURNING *`);
    for (const v of soon) {
      const owner = await one('SELECT id, display_name FROM users WHERE id = $1', [v.owner_id]);
      await notifyPremiereAudience(v, owner, 'soon');
    }
    const started = await many(
      `UPDATE videos SET premiere_started_at = now(), published_at = COALESCE(published_at, scheduled_at)
       WHERE premiere = true AND premiere_started_at IS NULL AND status = 'ready' AND deleted_at IS NULL
         AND scheduled_at <= now() RETURNING *`);
    for (const v of started) {
      const owner = await one('SELECT id, display_name FROM users WHERE id = $1', [v.owner_id]);
      await publish({ type: 'video.published', videoId: v.id, ownerId: v.owner_id });
      await notifyPremiereAudience(v, owner, 'start');
      await emitEvent('video.published', { video: eventVideo(v) });
    }
    out.premieres = { soon: soon.length, started: started.length };
  } catch (e) { ctx?.log?.warn({ err: e.message }, 'premieres'); }
  // 1b. Напоминания о запланированных эфирах (за 15 минут)
  try { out.liveReminders = await sendLiveReminders(); } catch (e) { ctx?.log?.warn({ err: e.message }, 'live reminders'); }
  // 1b-2. Вебинары (1.10): напоминания за сутки/час/15 минут, сертификаты и письмо-послесловие
  try { out.webinars = await runWebinarMaintenance(); } catch (e) { ctx?.log?.warn({ err: e.message }, 'webinars'); }
  // 1c. Срок публикации: видео с истёкшим сроком становятся приватными
  const expired = await many(`UPDATE videos SET visibility = 'private', expired_at = now() WHERE expires_at IS NOT NULL AND expires_at <= now() AND expired_at IS NULL AND deleted_at IS NULL RETURNING id, short_id, owner_id, title`);
  for (const v of expired) {
    await publish({ type: 'video.published', videoId: v.id, ownerId: v.owner_id });
    await notify(v.owner_id, { type: 'video_expired', title: 'Срок публикации видео истёк', body: `${v.title} — видео скрыто (приватное). Продлить срок можно в студии.`, link: `/studio/videos/${v.id}`, data: { videoId: v.id } });
    await emitEvent('video.expired', { video: { id: v.id, shortId: v.short_id, title: v.title, ownerId: v.owner_id, url: `/watch/${v.short_id}` } });
  }
  out.expired = expired.length;
  // 1d. Папка автоимпорта и еженедельный дайджест (1.3)
  try { const w = await scanWatchFolder(s, ctx?.log); if (w.imported) out.watchImported = w.imported; } catch (e) { ctx?.log?.warn({ err: e.message }, 'watch folder'); }
  try { const d = await sendDigests(s); if (d) out.digests = d; } catch (e) { ctx?.log?.warn({ err: e.message }, 'digest'); }

  // 1e. Справочник сотрудников из портала документации (1.13): сразу после включения раздела и дальше
  // раз в PORTAL_SYNC_MINUTES минут. Портал недоступен — повтор в следующий раз, видео работает дальше.
  try {
    if (job.payload.portalSync || await portalSyncDue()) out.portalSync = await syncPortalDirectory();
  } catch (e) { out.portalSyncError = e.message; ctx?.log?.warn({ err: e.message }, 'portal sync'); }

  const minute = new Date().getMinutes();
  if (job.payload.hourly || minute === 7) {
    // 2. Просроченные сессии и токены
    await purge('sessions', 'expires_at < now()');
    await purge('tokens', `expires_at < now() - interval '7 days'`);
    await purge('login_failures', `updated_at < now() - interval '1 day' AND (locked_until IS NULL OR locked_until < now())`);
    // 3. Брошенные загрузки
    const hours = s['retention.upload_tmp_hours'] || 48;
    const stale = await many(`UPDATE uploads SET status = 'expired' WHERE status = 'active' AND updated_at < now() - ($1 || ' hours')::interval RETURNING *`, [String(hours)]);
    for (const u of stale) {
      await fsp.unlink(u.tmp_path).catch(() => {});
      if (u.video_id) {
        const v = await one(`SELECT id FROM videos WHERE id = $1 AND status = 'uploading'`, [u.video_id]);
        if (v) { await query('DELETE FROM videos WHERE id = $1', [v.id]); await removeDir(storage.videoDir(v.id)).catch(() => {}); }
      }
    }
    out.expiredUploads = stale.length;
    // 3a. Временные файлы заданий, прерванных аварийно (воркер убит, сервер перезагружен):
    // каталоги сборки монтажа и промежуточные файлы правки/размытия. Задания, которые ещё идут, не трогаем.
    try { out.tmpRemoved = await cleanupJobTemp(); } catch (e) { ctx?.log?.warn({ err: e.message }, 'tmp cleanup'); }
    // 4. Старые задания
    await purge('jobs', `status IN ('done','cancelled') AND finished_at < now() - interval '14 days'`);
    await purge('jobs', `status = 'failed' AND finished_at < now() - interval '90 days'`);
    // 5. Уведомления старше 90 дней
    out.purged = { notifications: await purge('notifications', `created_at < now() - interval '90 days'`) };
    // 6. Напоминания о сроках обязательных просмотров
    try { out.reminders = await sendReminders(); } catch (e) { ctx?.log?.warn({ err: e.message }, 'reminders'); }
    // 7. Автоматически закрываем назначения через 90 дней после срока
    await query(`UPDATE assignments SET status = 'closed', updated_at = now() WHERE status = 'active' AND due_at IS NOT NULL AND due_at < now() - interval '90 days'`);
  }
  if (job.payload.daily || (minute === 15 && new Date().getHours() === 4)) {
    const hist = s['retention.history_days'] || 365;
    const raw = s['retention.raw_views_days'] || 400;
    const purged = {};
    purged.views = await purge('video_views', `last_at < now() - ($1 || ' days')::interval`, [String(Math.max(hist, raw))]);
    purged.audit = await purge('audit_log', `created_at < now() - ($1 || ' days')::interval`, [String(s['retention.audit_days'] || 730)]);
    // Сеансы воспроизведения (качество) и журнал вопросов к видеотеке
    purged.playback = await purge('playback_sessions', `created_at < now() - ($1 || ' days')::interval`, [String(s['qoe.retention_days'] || 90)]);
    purged.aiSearch = await purge('ai_search_log', `created_at < now() - interval '180 days'`);
    // 1.6: реплики живых субтитров и закрытые комнаты совместного просмотра
    purged.captions = await purge('live_captions', `created_at < now() - ($1 || ' days')::interval`, [String(s['retention.history_days'] || 365)]);
    await query(`UPDATE watch_parties SET ended_at = now(), playing = false WHERE ended_at IS NULL AND updated_at < now() - interval '12 hours'`);
    await query(`DELETE FROM watch_parties WHERE ended_at IS NOT NULL AND ended_at < now() - interval '30 days'`);
    purged.roomMessages = await purge('room_messages', `room LIKE 'party:%' AND created_at < now() - interval '30 days'`);
    // 1.8: исходники старше заданного срока удаляем — HLS и mp4 остаются, место освобождается
    const origDays = Number(s['storage.originals_days']) || 0;
    if (origDays > 0) {
      const old = await many(
        `SELECT id, original_path, storage_bytes FROM videos
         WHERE original_kept = true AND original_path IS NOT NULL AND status = 'ready' AND deleted_at IS NULL
           AND created_at < now() - ($1 || ' days')::interval LIMIT 50`, [String(origDays)]);
      for (const v of old) {
        try {
          const abs = storage.abs(v.original_path);
          const st = await fsp.stat(abs).catch(() => null);
          await removeFile(abs);
          await query('UPDATE videos SET original_kept = false, original_path = NULL, storage_bytes = greatest(0, storage_bytes - $2) WHERE id = $1',
            [v.id, st ? st.size : 0]);
        } catch (e) { ctx?.log?.warn({ err: e.message, videoId: v.id }, 'originals cleanup'); }
      }
      out.originalsRemoved = old.length;
    }
    // 1.9: правила хранения, архив и пересмотр актуальности — раз в сутки
    if (s['lifecycle.enabled']) {
      try { out.retention = await runRetention({ payload: {} }); } catch (e) { ctx?.log?.warn({ err: e.message }, 'retention'); }
    }
    purged.searchHistory = await purge('search_history', `created_at < now() - interval '180 days'`);
    out.purged = { ...(out.purged || {}), ...purged };
    // Корзина: окончательно удаляем видео, стёртые давнее retention.trash_days (файлы и записи)
    const trashDays = Math.max(1, Number(s['retention.trash_days']) || 30);
    const trashed = await many(`SELECT id FROM videos WHERE deleted_at IS NOT NULL AND deleted_at < now() - ($1 || ' days')::interval LIMIT 200`, [String(trashDays)]);
    for (const v of trashed) {
      await removeDir(storage.videoDir(v.id)).catch(() => {});
      await query('DELETE FROM videos WHERE id = $1', [v.id]);
    }
    out.purgedVideos = trashed.length;
    // Синхронизация счётчиков
    await query(`UPDATE users u SET subscriber_count = (SELECT count(*) FROM subscriptions s WHERE s.channel_id = u.id),
      video_count = (SELECT count(*) FROM videos v WHERE v.owner_id = u.id AND v.deleted_at IS NULL AND v.status = 'ready'),
      total_views = (SELECT coalesce(sum(view_count),0) FROM videos v WHERE v.owner_id = u.id AND v.deleted_at IS NULL)`);
    await query(`UPDATE categories c SET video_count = (SELECT count(*) FROM videos v WHERE v.category_id = c.id AND v.deleted_at IS NULL AND v.status = 'ready')`);
    out.daily = true;
  }
  return out;
}


/**
 * Объявление всем активным сотрудникам — фоновым заданием. Раньше рассылка шла прямо в HTTP-запросе:
 * на трёх тысячах человек он висел минуты и обрывался по таймауту nginx, а часть уведомлений
 * к этому моменту уже была создана.
 */
export async function runAnnounce(job, ctx) {
  const { title, body = '', link = null, actorId = null } = job.payload || {};
  let after = '00000000-0000-0000-0000-000000000000';
  let sent = 0;
  for (;;) {
    if (ctx?.signal?.aborted) throw new Error('Отменено');
    const batch = await many(`SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL AND id > $1 ORDER BY id LIMIT 500`, [after]);
    if (!batch.length) break;
    sent += await notifyMany(batch.map((u) => u.id), { type: 'announcement', title, body, link, actorId });
    after = batch[batch.length - 1].id;
    await ctx?.heartbeat?.(null, `разослано ${sent}`);
  }
  return { sent };
}
