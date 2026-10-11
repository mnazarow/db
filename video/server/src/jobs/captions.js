// Живые субтитры эфира: пока идёт трансляция, воркер порциями снимает звук с потока MediaMTX,
// отдаёт его серверу распознавания речи и рассылает реплики зрителям (как живые субтитры в Zoom и Teams).
import fsp from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { one, query } from '../db.js';
import { config } from '../config.js';
import { loadSettings } from '../lib/settings.js';
import { transcribe } from '../lib/asr.js';
import { runFfmpeg } from '../lib/ffmpeg.js';
import { toChannel } from '../lib/realtime.js';
import { vttToText } from '../lib/util.js';

const CHUNK_SEC = 14;          // длительность порции звука
const MAX_HOURS = 8;           // страховка от вечного задания

/** Снять порцию звука с живого потока в mp3. Возвращает путь к файлу или null. */
export async function captureChunk(streamKey, seconds = CHUNK_SEC, dir = os.tmpdir()) {
  const out = path.join(dir, `cv-live-${streamKey}-${Date.now()}.mp3`);
  const url = `${config.mediamtx.hlsUrl.replace(/\/$/, '')}/${streamKey}/index.m3u8`;
  try {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), (seconds + 25) * 1000);
    try {
      await runFfmpeg([
        '-i', url, '-t', String(seconds), '-vn', '-ac', '1', '-ar', '16000', '-b:a', '64k', out,
      ], { signal: ctrl.signal, label: 'live-captions' });
    } finally { clearTimeout(timer); }
  } catch (e) {
    await fsp.unlink(out).catch(() => {});
    throw e;
  }
  const st = await fsp.stat(out).catch(() => null);
  if (!st || st.size < 2000) { await fsp.unlink(out).catch(() => {}); return null; }
  return out;
}

/** Сохранить реплику и разослать её зрителям эфира. */
export async function pushCaption(streamId, { seq, offsetSec, text }) {
  const clean = String(text || '').replace(/\s+/g, ' ').trim();
  if (!clean) return null;
  const row = await one(
    `INSERT INTO live_captions(stream_id, seq, offset_sec, text) VALUES ($1,$2,$3,$4)
     ON CONFLICT (stream_id, seq) DO UPDATE SET text = EXCLUDED.text RETURNING *`,
    [streamId, seq, offsetSec || 0, clean.slice(0, 1000)]);
  await toChannel(`live:${streamId}`, { type: 'live.caption', streamId, seq, offsetSec: row.offset_sec, text: row.text });
  return row;
}

/** Задание воркера: крутится, пока эфир идёт и субтитры включены. */
export async function runLiveCaptions(job, ctx) {
  const { streamId } = job.payload;
  const started = Date.now();
  let seq = 0;
  let chunks = 0;
  let errors = 0;
  const first = await one('SELECT * FROM live_streams WHERE id = $1', [streamId]);
  if (!first) return { skipped: 'no-stream' };
  const startedAt = first.started_at ? new Date(first.started_at).getTime() : Date.now();
  const last = await one('SELECT max(seq) AS n FROM live_captions WHERE stream_id = $1', [streamId]);
  seq = Number(last?.n || 0);

  while (!ctx?.signal?.aborted && Date.now() - started < MAX_HOURS * 3600 * 1000) {
    const s = await one('SELECT status, captions, stream_key FROM live_streams WHERE id = $1', [streamId]);
    if (!s || s.status !== 'live' || !s.captions) break;
    const settings = await loadSettings();
    if (!settings['asr.enabled'] || !settings['live.captions']) break;
    let file = null;
    try {
      file = await captureChunk(s.stream_key, CHUNK_SEC);
      if (!file) { errors += 1; if (errors > 5) break; continue; }
      const res = await transcribe(file, { settings, signal: ctx?.signal });
      const text = res.text || vttToText(res.vtt || '');
      if (text.trim()) {
        seq += 1;
        chunks += 1;
        await pushCaption(streamId, { seq, offsetSec: Math.max(0, (Date.now() - startedAt) / 1000 - CHUNK_SEC), text });
      }
      errors = 0;
    } catch (e) {
      errors += 1;
      if (errors > 5) { await query('UPDATE live_streams SET captions = false WHERE id = $1', [streamId]); break; }
      await new Promise((r) => setTimeout(r, 3000));
    } finally {
      if (file) await fsp.unlink(file).catch(() => {});
    }
  }
  return { chunks, seq };
}

/** Собрать VTT из реплик эфира — чтобы у записи сразу были субтитры. */
export function captionsToVtt(rows, { offsetBase = 0 } = {}) {
  const ts = (sec) => {
    const s = Math.max(0, sec);
    const h = String(Math.floor(s / 3600)).padStart(2, '0');
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const ss = String(Math.floor(s % 60)).padStart(2, '0');
    const ms = String(Math.round((s - Math.floor(s)) * 1000)).padStart(3, '0');
    return `${h}:${m}:${ss}.${ms}`;
  };
  const lines = ['WEBVTT', ''];
  rows.forEach((r, i) => {
    const start = Math.max(0, Number(r.offset_sec || 0) - offsetBase);
    const next = rows[i + 1] ? Math.max(0, Number(rows[i + 1].offset_sec || 0) - offsetBase) : start + CHUNK_SEC;
    lines.push(`${ts(start)} --> ${ts(Math.max(start + 1, next))}`, String(r.text).trim(), '');
  });
  return lines.join('\n');
}
