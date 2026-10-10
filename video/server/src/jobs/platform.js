// Платформенные задания 1.3: еженедельный дайджест по почте, папка автоимпорта, аудиодорожка для подкастов.
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, many, query } from '../db.js';
import { storage, ensureDir, exists, removeFile } from '../lib/storage.js';
import { loadSettings } from '../lib/settings.js';
import { templates } from '../lib/mailer.js';
import { notifPrefs, notify } from '../lib/notify.js';
import { enqueue } from '../lib/jobs.js';
import { runFfmpeg, probe } from '../lib/ffmpeg.js';
import { finalizeFile } from '../routes/uploads.js';
import { shortId } from '../lib/crypto.js';
import { extOf, safeFilename } from '../lib/util.js';
import { videoSource } from '../lib/ocr.js';
import { applyFreshDefault } from '../lib/lifecycle.js';

const fmtDur = (sec) => { const s = Math.max(0, Math.round(Number(sec) || 0)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return h ? `${h}:${String(m).padStart(2, '0')}:${String(x).padStart(2, '0')}` : `${m}:${String(x).padStart(2, '0')}`; };

/** Еженедельный дайджест: вызывается обслуживанием раз в минуту, отправляет в заданный день и час. */
export async function sendDigests(s, { force = false, onlyUserId = null } = {}) {
  if (!force && !s['digest.enabled']) return 0;
  if (!s['smtp.enabled']) return 0;
  const now = new Date();
  const weekday = ((now.getDay() + 6) % 7) + 1; // 1 = понедельник
  if (!force && (weekday !== Number(s['digest.weekday'] || 1) || now.getHours() !== Number(s['digest.hour'] ?? 9))) return 0;
  const users = onlyUserId
    ? await many(`SELECT * FROM users WHERE id = $1`, [onlyUserId])
    : await many(`SELECT * FROM users WHERE status = 'active' AND deleted_at IS NULL AND email IS NOT NULL AND (digest_sent_at IS NULL OR digest_sent_at < now() - interval '6 days') ORDER BY created_at LIMIT 500`);
  let sent = 0;
  for (const u of users) {
    const p = notifPrefs(u);
    if (!p.email || u.prefs?.notifications?.digest === false) continue;
    // новые видео за неделю: подписки + для сотрудников/публичные
    const videos = await many(
      `SELECT v.short_id, v.title, v.duration, v.thumbnail_path, u2.display_name AS owner_name,
              (v.owner_id IN (SELECT channel_id FROM subscriptions WHERE subscriber_id = $1)) AS subscribed
       FROM videos v JOIN users u2 ON u2.id = v.owner_id
       WHERE v.deleted_at IS NULL AND v.status = 'ready' AND v.moderation_status = 'approved' AND v.visibility IN ('public','internal')
         AND v.published_at > now() - interval '7 days' AND v.owner_id <> $1
       ORDER BY subscribed DESC, v.view_count DESC, v.published_at DESC LIMIT 12`, [u.id],
    );
    const assignments = await many(
      `SELECT DISTINCT a.id, a.title, a.due_at, a.kind, a.video_id, a.playlist_id FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
       WHERE a.status = 'active' AND (t.target_type = 'all' OR t.user_id = $1 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $1)))
         AND NOT EXISTS (SELECT 1 FROM assignment_progress p WHERE p.assignment_id = a.id AND p.user_id = $1 AND p.completed_at IS NOT NULL
                         AND (a.kind = 'video' OR (SELECT count(*) FROM assignment_progress p2 WHERE p2.assignment_id = a.id AND p2.user_id = $1 AND p2.completed_at IS NOT NULL) >= (SELECT count(*) FROM playlist_items pi WHERE pi.playlist_id = a.playlist_id)))
       ORDER BY a.due_at NULLS LAST LIMIT 10`, [u.id],
    );
    const live = await many(`SELECT short_id, title, scheduled_at FROM live_streams WHERE scheduled_at > now() AND scheduled_at < now() + interval '7 days' AND status <> 'ended' AND visibility IN ('public','internal') ORDER BY scheduled_at LIMIT 5`);
    if (!videos.length && !assignments.length && !live.length && !onlyUserId) { await query('UPDATE users SET digest_sent_at = now() WHERE id = $1', [u.id]); continue; }
    try {
      await templates.digest({
        to: u.email, name: u.display_name,
        videos: videos.map((v) => ({ url: `/watch/${v.short_id}`, title: v.title, ownerName: v.owner_name, duration: fmtDur(v.duration), thumbnailUrl: v.thumbnail_path ? `/media/${v.thumbnail_path}` : null })),
        assignments: assignments.map((a) => ({ url: a.kind === 'video' && a.video_id ? `/assignments` : '/assignments', title: a.title, due: a.due_at ? new Date(a.due_at).toLocaleDateString('ru-RU') : '' })),
        live: live.map((l) => ({ url: `/live/${l.short_id}`, title: l.title, when: new Date(l.scheduled_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) })),
      });
      await query('UPDATE users SET digest_sent_at = now() WHERE id = $1', [u.id]);
      sent++;
    } catch (e) { console.error('digest', u.email, e.message); }
  }
  return sent;
}

/** Папка автоимпорта: новые файлы → видео (владелец из настроек), как «hot folder» у корпоративных платформ. */
export async function scanWatchFolder(s, log, { minAgeMs = 60000, settleMs = 3000 } = {}) {
  if (!s['import.watch_enabled'] || !s['import.watch_dir']) return { scanned: 0 };
  const dir = String(s['import.watch_dir']);
  let entries;
  try { entries = await fsp.readdir(dir, { withFileTypes: true }); } catch (e) { log?.warn({ dir, err: e.message }, 'watch dir unavailable'); return { scanned: 0, error: e.message }; }
  const allowed = new Set((s['upload.allowed_extensions'] || []).map((e) => String(e).toLowerCase().replace(/^\./, '')));
  const files = entries.filter((e) => e.isFile() && allowed.has(extOf(e.name)) && !e.name.startsWith('.'));
  if (!files.length) return { scanned: 0 };
  const ownerEmail = String(s['import.watch_owner'] || '').trim().toLowerCase();
  const owner = ownerEmail ? await one(`SELECT * FROM users WHERE email = $1 AND deleted_at IS NULL`, [ownerEmail]) : await one(`SELECT * FROM users WHERE role = 'admin' AND status = 'active' AND deleted_at IS NULL ORDER BY created_at LIMIT 1`);
  if (!owner) return { scanned: files.length, error: 'владелец не найден' };
  let imported = 0;
  for (const f of files) {
    const full = path.join(dir, f.name);
    const st = await fsp.stat(full);
    if (Date.now() - st.mtimeMs < minAgeMs) continue; // файл ещё может копироваться
    const seen = await one('SELECT id, status FROM import_watch_log WHERE filename = $1 AND size = $2', [f.name, st.size]);
    if (seen) continue;
    // повторная проверка через 5 секунд — размер не должен меняться
    await new Promise((r) => setTimeout(r, settleMs));
    const st2 = await fsp.stat(full).catch(() => null);
    if (!st2 || st2.size !== st.size) continue;
    try {
      const max = (s['upload.max_size_mb'] || 0) * 1024 * 1024;
      if (max && st.size > max) throw new Error(`файл больше лимита ${s['upload.max_size_mb']} МБ`);
      const videoId = crypto.randomUUID();
      const vdir = storage.videoDir(videoId);
      await ensureDir(vdir);
      const title = path.basename(f.name, path.extname(f.name)).replace(/[_]+/g, ' ').trim().slice(0, 150) || 'Импорт';
      const visibility = ['public', 'internal', 'unlisted', 'private'].includes(s['import.watch_visibility']) ? s['import.watch_visibility'] : 'private';
      const video = await one(
        `INSERT INTO videos(id, short_id, owner_id, title, description, visibility, status, moderation_status, original_filename, original_size, comments_mode, source_url)
         VALUES ($1,$2,$3,$4,$5,$6,'uploading','approved',$7,$8,$9,$10) RETURNING *`,
        [videoId, shortId(), owner.id, title, `Импортировано из папки автоимпорта: ${f.name}`, visibility, safeFilename(f.name), st.size, s['comments.default_mode'], `file://${full}`],
      );
      await applyFreshDefault(videoId, s);
      const tmp = path.join(vdir, `inbox-source.${extOf(f.name) || 'mp4'}`);
      if (s['import.watch_delete'] !== false) await fsp.rename(full, tmp).catch(async () => { await fsp.copyFile(full, tmp); await fsp.unlink(full); });
      else { await fsp.copyFile(full, tmp); const done = path.join(dir, 'done'); await ensureDir(done); await fsp.rename(full, path.join(done, f.name)).catch(() => {}); }
      await finalizeFile({ video, srcPath: tmp, filename: f.name, priority: 0 });
      await query('INSERT INTO import_watch_log(filename, size, mtime, video_id, status) VALUES ($1,$2,$3,$4,$5) ON CONFLICT DO NOTHING', [f.name, st.size, new Date(st.mtimeMs), videoId, 'imported']);
      await notify(owner.id, { type: 'video_ready', title: 'Файл из папки автоимпорта принят', body: `${title} — идёт обработка`, link: `/studio/videos/${videoId}`, data: { videoId } });
      imported++;
    } catch (e) {
      log?.warn({ file: f.name, err: e.message }, 'watch import failed');
      await query('INSERT INTO import_watch_log(filename, size, mtime, status, error) VALUES ($1,$2,$3,$4,$5) ON CONFLICT DO NOTHING', [f.name, st.size, new Date(st.mtimeMs), 'failed', e.message.slice(0, 500)]);
    }
  }
  return { scanned: files.length, imported };
}

/** Аудиодорожка m4a для режима «только звук» и подкаст-лент. */
export async function makeAudioTrack(video, { signal } = {}) {
  const src = await videoSource(video);
  const meta = await probe(src);
  if (!meta.hasAudio) return null;
  const out = path.join(storage.videoDir(video.id), 'audio.m4a');
  await ensureDir(path.dirname(out));
  await runFfmpeg(['-i', src, '-vn', '-c:a', 'aac', '-b:a', '96k', '-ac', '2', '-movflags', '+faststart', out], { signal, label: 'audio-track' });
  const rel = storage.rel(out);
  await query('UPDATE videos SET audio_path = $2 WHERE id = $1', [video.id, rel]);
  return rel;
}

export async function runAudioTrack(job, ctx) {
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const rel = await makeAudioTrack(video, ctx);
  return { audio: rel };
}

/** Поставить в очередь аудиодорожки для готовых видео без них (пакетно). */
export async function backfillAudioTracks(limit = 200) {
  const rows = await many(`SELECT id FROM videos WHERE deleted_at IS NULL AND status = 'ready' AND audio_path IS NULL ORDER BY published_at DESC NULLS LAST LIMIT $1`, [limit]);
  for (const r of rows) await enqueue('audio_track', { videoId: r.id }, { videoId: r.id, priority: -3 });
  return rows.length;
}

export const _internal = { exists, removeFile };
