// Ретрансляция эфира на внешние площадки (мультистриминг как в Restream):
// воркер забирает поток у MediaMTX и отдаёт его копию на RTMP/SRT-адрес площадки.
import { spawn } from 'node:child_process';
import { one, query } from '../db.js';
import { config } from '../config.js';
import { checkOutboundHost } from '../lib/net.js';
import { loadSettings } from '../lib/settings.js';

const MAX_HOURS = 12;
const MAX_ERRORS = 8;

/** Полный адрес площадки: адрес + ключ трансляции. */
export function targetUrl(url, key) {
  const u = String(url || '').trim().replace(/\/+$/, '');
  const k = String(key || '').trim().replace(/^\/+/, '');
  if (!u) return '';
  return k ? `${u}/${k}` : u;
}

/** Адрес не должен вести внутрь сети портала — ретрансляция всегда наружу. */
export async function validTarget(url) {
  const u = String(url || '').trim();
  if (!/^(rtmp|rtmps|srt|rtsp):\/\//i.test(u)) return 'Адрес должен начинаться с rtmp://, rtmps://, srt:// или rtsp://';
  const host = (u.match(/^\w+:\/\/(\[[^\]]+\]|[^/:?]+)/) || [])[1] || '';
  if (config.importAllowLocal) return null;
  // Имя тоже разрешаем: строковая проверка не ловила ни имя, указывающее внутрь сети,
  // ни IPv6 ULA, ни диапазон CGNAT.
  const r = await checkOutboundHost(host, { allowPrivate: false });
  return r.ok ? null : `Внутренние адреса недопустимы — укажите адрес площадки (${r.reason})`;
}

/** Аргументы ffmpeg: поток MediaMTX → площадка (без перекодирования). */
export function restreamArgs(input, target) {
  const proto = (target.match(/^(\w+):/) || [])[1]?.toLowerCase();
  const fmt = proto === 'srt' ? 'mpegts' : proto === 'rtsp' ? 'rtsp' : 'flv';
  return [
    '-hide_banner', '-nostdin', '-loglevel', 'error',
    '-fflags', '+genpts', '-i', input,
    '-c', 'copy', ...(fmt === 'flv' ? ['-bsf:a', 'aac_adtstoasc'] : []),
    '-f', fmt, target,
  ];
}

/** Источник для ретрансляции — HLS-выход MediaMTX (он включён в поставке всегда). */
export function sourceUrl(streamKey) {
  return `${config.mediamtx.hlsUrl.replace(/\/$/, '')}/live/${streamKey}/index.m3u8`;
}

async function setStatus(id, status, fields = {}) {
  const sets = ['status = $2'], vals = [id, status];
  for (const [k, v] of Object.entries(fields)) { vals.push(v); sets.push(`${k} = $${vals.length}`); }
  await query(`UPDATE live_restreams SET ${sets.join(', ')} WHERE id = $1`, vals);
}

/** Один запуск ffmpeg: живёт, пока идёт эфир и площадка включена. */
function pushOnce(input, target, { signal, onStart, isStopped }) {
  return new Promise((resolve) => {
    const child = spawn(config.ffmpegPath, restreamArgs(input, target), { stdio: ['ignore', 'ignore', 'pipe'] });
    let err = '';
    let stopped = false;
    child.stderr.on('data', (d) => { err = (err + d.toString()).slice(-2000); });
    const started = Date.now();
    const timer = setTimeout(() => { onStart?.(); }, 2500);
    const watch = setInterval(async () => {
      try { if (await isStopped()) { stopped = true; child.kill('SIGTERM'); } } catch { /* ignore */ }
    }, 5000);
    const onAbort = () => { stopped = true; child.kill('SIGTERM'); };
    signal?.addEventListener('abort', onAbort, { once: true });
    child.on('error', (e) => { err = e.message; });
    child.on('close', (code) => {
      clearTimeout(timer); clearInterval(watch);
      signal?.removeEventListener('abort', onAbort);
      resolve({ code, stopped, seconds: Math.round((Date.now() - started) / 1000), error: err.trim().split('\n').pop() || '' });
    });
  });
}

/** Задание: ретрансляция одной площадки. payload: {restreamId} */
export async function runRestream(job, ctx) {
  const id = job.payload.restreamId;
  const row = await one('SELECT * FROM live_restreams WHERE id = $1', [id]);
  if (!row) return { skipped: 'no-target' };
  const stream = await one('SELECT * FROM live_streams WHERE id = $1', [row.stream_id]);
  if (!stream) return { skipped: 'no-stream' };
  const target = targetUrl(row.url, row.stream_key);
  const input = sourceUrl(stream.stream_key);
  const startedAt = Date.now();
  let errors = 0, runs = 0, seconds = 0;
  const isStopped = async () => {
    const s = await one(`SELECT r.enabled, l.status FROM live_restreams r JOIN live_streams l ON l.id = r.stream_id WHERE r.id = $1`, [id]);
    return !s || !s.enabled || s.status !== 'live';
  };
  await setStatus(id, 'starting', { started_at: new Date(), last_error: null, stopped_at: null });
  while (!ctx?.signal?.aborted && Date.now() - startedAt < MAX_HOURS * 3600 * 1000) {
    const settings = await loadSettings();
    if (!settings['live.restream_enabled']) { await setStatus(id, 'stopped', { stopped_at: new Date(), last_error: 'Ретрансляция отключена администратором' }); break; }
    if (await isStopped()) { await setStatus(id, 'stopped', { stopped_at: new Date() }); break; }
    runs += 1;
    const r = await pushOnce(input, target, { signal: ctx?.signal, isStopped, onStart: () => setStatus(id, 'live', { last_error: null }).catch(() => {}) });
    seconds += r.seconds;
    if (r.stopped || ctx?.signal?.aborted) { await setStatus(id, 'stopped', { stopped_at: new Date() }); break; }
    if (r.code === 0 && r.seconds > 10) { errors = 0; continue; }         // источник кончился — пробуем снова
    errors += 1;
    await setStatus(id, 'error', { last_error: (r.error || `ffmpeg завершился с кодом ${r.code}`).slice(0, 500) });
    if (errors >= MAX_ERRORS) { await setStatus(id, 'error', { stopped_at: new Date(), last_error: `Ретрансляция остановлена после ${errors} ошибок: ${(r.error || '').slice(0, 300)}` }); break; }
    await new Promise((res) => setTimeout(res, Math.min(30000, 3000 * errors)));
  }
  return { runs, seconds, errors };
}

/** Запустить ретрансляцию всех включённых площадок трансляции (вызывается при старте эфира). */
export async function startRestreams(streamId, enqueue) {
  const settings = await loadSettings();
  if (!settings['live.restream_enabled']) return 0;
  const rows = await query('SELECT id FROM live_restreams WHERE stream_id = $1 AND enabled = true', [streamId]);
  for (const r of rows.rows) await enqueue('restream', { restreamId: r.id }, { dedupe: true, dedupeKey: `restream:${r.id}`, maxAttempts: 1, priority: 1 });
  return rows.rowCount;
}
