// Воркер фоновых заданий: транскодирование, субтитры, письма, импорт записей эфиров, обслуживание.
import pino from 'pino';
import { config, assertConfig } from './config.js';
import { startListener, bus, closeDb, query, one } from './db.js';
import { runMigrations } from './migrate.js';
import { loadSettings } from './lib/settings.js';
import { claimJob, heartbeat as jobHeartbeat, completeJob, failJob, reapStaleJobs, enqueue, requeueOwnJobs, finalizeJobEntity } from './lib/jobs.js';
import { runTranscode, runThumbnails, recomputeStorage } from './jobs/transcode.js';
import { runSubtitlesAsr } from './jobs/subtitles.js';
import { runEmail, runLiveImport, runMaintenance, runAnnounce } from './jobs/misc.js';
import { runImportUrl } from './jobs/import.js';
import { runAiEnrich } from './jobs/ai.js';
import { runRagPush } from './jobs/rag.js';
import { runVideoEdit, runRemoveSilence, runClipCreate } from './jobs/editor.js';
import { runSubtitleTranslate } from './jobs/translate.js';
import { runOcr } from './lib/ocr.js';
import { runAudioTrack } from './jobs/platform.js';
import { runLiveCaptions } from './jobs/captions.js';
import { runMeetingNotes, runClipsAi } from './jobs/meeting.js';
import { runAutoChapters } from './jobs/chapters.js';
import { runVideoBlur, runFaceDetect } from './jobs/blur.js';
import { runRestream } from './jobs/restream.js';
import { runRetention } from './jobs/retention.js';
import { runVideoRender } from './jobs/render.js';
import { deliverWebhook } from './lib/webhooks.js';
import { sendXapi } from './lib/xapi.js';
import { telegramNotify, pollUpdates as telegramPoll } from './lib/telegram.js';
import { notify } from './lib/notify.js';
import { publish } from './db.js';

assertConfig();
const log = pino({ level: config.logLevel, transport: config.isDev ? { target: 'pino-pretty' } : undefined });

const HANDLERS = {
  transcode: runTranscode,
  thumbnails: runThumbnails,
  subtitles_asr: runSubtitlesAsr,
  email: runEmail,
  live_import: runLiveImport,
  maintenance: runMaintenance,
  import_url: runImportUrl,
  ai_enrich: runAiEnrich,
  telegram: async (job) => ({ sent: await telegramNotify(job.payload.userId, job.payload) }),
  rag_push: runRagPush,
  recompute_storage: async () => ({ videos: await recomputeStorage() }),
  // 1.3
  video_edit: runVideoEdit,
  remove_silence: runRemoveSilence,
  clip_create: runClipCreate,
  subtitle_translate: runSubtitleTranslate,
  ocr: runOcr,
  audio_track: runAudioTrack,
  // 1.6
  live_captions: runLiveCaptions,
  // 1.7
  meeting_notes: runMeetingNotes,
  clips_ai: runClipsAi,
  // 1.8
  auto_chapters: runAutoChapters,
  // 1.9 — размытие в кадре, автопоиск лиц, ретрансляция эфира, сроки хранения
  video_blur: runVideoBlur,
  face_detect: runFaceDetect,
  restream: runRestream,
  retention: runRetention,
  // 1.10 — сборка ролика по проекту графического редактора
  video_render: runVideoRender,
  webhook_deliver: deliverWebhook,
  xapi_send: sendXapi,
  // 1.12 — объявление всем пользователям пачками, а не в HTTP-запросе
  announce: runAnnounce,
};

// Тяжёлые задания (ffmpeg) ограничены concurrency; лёгкие (письма) выполняются отдельным слотом.
const HEAVY = new Set(['transcode', 'thumbnails', 'subtitles_asr', 'live_import', 'recompute_storage', 'import_url', 'video_edit', 'remove_silence', 'clip_create', 'ocr', 'audio_track', 'auto_chapters', 'video_blur', 'face_detect', 'video_render']);
// Долгие сетевые задания (ИИ, RAG): процессор не занимают, но и письма с вебхуками задерживать не должны — отдельная полоса.
const SLOW = new Set(['ai_enrich', 'rag_push', 'subtitle_translate', 'meeting_notes', 'clips_ai']);
// Многочасовые задания на время эфира: ретрансляция и живые субтитры. Своя полоса — иначе два эфира
// с ретрансляцией на полсуток занимали оба места SLOW, и конспекты, теги и переводы стояли в очереди.
const LONG = new Set(['restream', 'live_captions']);
const LONG_LIMIT = Math.max(1, Number(process.env.WORKER_LONG_CONCURRENCY) || 8);
const QUICK_TYPES = () => Object.keys(HANDLERS).filter((t) => !HEAVY.has(t) && !SLOW.has(t) && !LONG.has(t));
const running = new Map(); // jobId → { abort }
let stopping = false;

/**
 * Предел времени на задание. Без него зависший ffmpeg или сетевой запрос держат слот вечно:
 * биение сердца идёт, поэтому «оживлятор» такое задание не подберёт никогда.
 * Долгие по своей природе задания (ретрансляция, живые субтитры) имеют собственные пределы.
 */
const JOB_MINUTES = { restream: 13 * 60, live_captions: 9 * 60, transcode: 8 * 60, video_render: 8 * 60, video_edit: 6 * 60, import_url: 6 * 60, live_import: 6 * 60, ocr: 3 * 60, subtitles_asr: 4 * 60 };
const DEFAULT_JOB_MINUTES = 60;

async function runJob(job) {
  const ctrl = new AbortController();
  running.set(job.id, { abort: () => ctrl.abort(), job });
  const jlog = log.child({ jobId: job.id, type: job.type, videoId: job.video_id });
  const beat = setInterval(() => jobHeartbeat(job.id).catch(() => {}), 15000);
  const limitMs = (JOB_MINUTES[job.type] || DEFAULT_JOB_MINUTES) * 60000;
  let timedOut = false;
  const deadline = setTimeout(() => { timedOut = true; ctrl.abort(); }, limitMs);
  const ctx = {
    log: jlog,
    signal: ctrl.signal,
    heartbeat: (progress, stage) => jobHeartbeat(job.id, { progress, stage }),
  };
  const started = Date.now();
  try {
    jlog.info('старт задания');
    const handler = HANDLERS[job.type];
    if (!handler) throw Object.assign(new Error(`Неизвестный тип задания: ${job.type}`), { noRetry: true });
    const result = await handler(job, ctx);
    await completeJob(job.id, result);
    jlog.info({ ms: Date.now() - started }, 'задание выполнено');
  } catch (e) {
    const cancelled = ctrl.signal.aborted;
    jlog.error({ err: e.message, cancelled, timedOut }, 'ошибка задания');
    if (timedOut) {
      const msg = `Превышено время выполнения (${Math.round(limitMs / 60000)} мин)`;
      await query(`UPDATE jobs SET status = 'failed', error = $2, finished_at = now(), locked_by = NULL WHERE id = $1`, [job.id, msg]);
      await finalizeJobEntity(job, msg);
    } else if (cancelled) {
      await query(`UPDATE jobs SET status = 'cancelled', error = 'Отменено', finished_at = now(), locked_by = NULL WHERE id = $1`, [job.id]);
      await finalizeJobEntity(job, 'Задание отменено');
    } else {
      const final = await failJob(job, e, { retry: !e.noRetry });
      if (final) await finalizeJobEntity(job, e.message);
      if (final && job.video_id && ['transcode'].includes(job.type)) {
        await query(`UPDATE videos SET status = 'failed', processing_error = $2, processing_stage = NULL WHERE id = $1`, [job.video_id, String(e.message).slice(0, 2000)]);
        const v = await one('SELECT id, short_id, owner_id, title FROM videos WHERE id = $1', [job.video_id]);
        if (v) {
          await publish({ type: 'video.failed', videoId: v.id, shortId: v.short_id, ownerId: v.owner_id, status: 'failed', error: e.message });
          await notify(v.owner_id, { type: 'video_failed', title: 'Не удалось обработать видео', body: `${v.title}: ${String(e.message).slice(0, 200)}`, link: `/studio/videos/${v.id}`, data: { videoId: v.id } });
        }
      }
    }
  } finally {
    clearInterval(beat);
    clearTimeout(deadline);
    running.delete(job.id);
  }
}

function heavyRunning() {
  let n = 0;
  for (const r of running.values()) if (HEAVY.has(r.job.type)) n++;
  return n;
}
function slowRunning() {
  let n = 0;
  for (const r of running.values()) if (SLOW.has(r.job.type)) n++;
  return n;
}
function longRunning() {
  let n = 0;
  for (const r of running.values()) if (LONG.has(r.job.type)) n++;
  return n;
}
function lightRunning() {
  let n = 0;
  for (const r of running.values()) if (!HEAVY.has(r.job.type) && !SLOW.has(r.job.type) && !LONG.has(r.job.type)) n++;
  return n;
}

let wake = null;
function wakeUp() { if (wake) { const w = wake; wake = null; w(); } }

async function loop() {
  while (!stopping) {
    let picked = false;
    try {
      if (heavyRunning() < config.workerConcurrency) {
        const job = await claimJob(config.workerId, [...HEAVY]);
        if (job) { picked = true; runJob(job); }
      }
      if (slowRunning() < 2) {
        const job = await claimJob(config.workerId, [...SLOW]);
        if (job) { picked = true; runJob(job); }
      }
      if (longRunning() < LONG_LIMIT) {
        const job = await claimJob(config.workerId, [...LONG]);
        if (job) { picked = true; runJob(job); }
      }
      if (lightRunning() < 2) {
        const job = await claimJob(config.workerId, QUICK_TYPES());
        if (job) { picked = true; runJob(job); }
      }
    } catch (e) {
      log.error({ err: e.message }, 'ошибка цикла воркера');
      await new Promise((r) => setTimeout(r, 3000));
    }
    if (!picked) await new Promise((r) => { wake = r; setTimeout(wakeUp, 5000); });
  }
}

async function main() {
  await runMigrations({ log: (m) => log.info(m) });
  await loadSettings(true);
  await startListener();
  bus.on('job.enqueued', wakeUp);
  bus.on('job.cancel', (e) => { const r = running.get(e.jobId); if (r) r.abort(); });

  // Зависшие задания прошлых запусков возвращаем в очередь (не выдавая лишней попытки).
  // Только те, что давно не подавали признаков жизни: при одинаковом WORKER_ID у нескольких
  // воркеров иначе можно перезапустить задание, которое прямо сейчас выполняет соседний процесс.
  await requeueOwnJobs(config.workerId, { staleSeconds: 90 });
  setInterval(async () => {
    try { const r = await reapStaleJobs(5); if (r.length) log.warn({ n: r.length }, 'перезапущены зависшие задания'); } catch { /* ignore */ }
  }, 60000).unref();

  // Обслуживание раз в минуту (одно задание на всю систему)
  setInterval(async () => {
    try { await enqueue('maintenance', {}, { dedupe: false, maxAttempts: 1 }); } catch { /* ignore */ }
  }, 60000).unref();
  // Не плодим задания обслуживания при нескольких воркерах: удаляем дубликаты в очереди
  setInterval(async () => {
    try { await query(`DELETE FROM jobs WHERE type = 'maintenance' AND status = 'queued' AND id NOT IN (SELECT max(id) FROM jobs WHERE type = 'maintenance' AND status = 'queued')`); } catch { /* ignore */ }
  }, 60000).unref();

  // Telegram: приём команд бота long-polling (только если включено в настройках)
  (async function telegramLoop() {
    while (!stopping) {
      let n = -1;
      try { n = await telegramPoll(); } catch (e) { log.warn({ err: e.message }, 'telegram: ошибка приёма команд, повтор через 15 с'); }
      await new Promise((r) => setTimeout(r, n === 0 ? 1000 : n < 0 ? 15000 : 200));
    }
  })();

  log.info({ concurrency: config.workerConcurrency, workerId: config.workerId }, 'воркер запущен');
  loop();
}

async function shutdown(signal) {
  if (stopping) return;
  stopping = true;
  log.info({ signal }, 'остановка воркера…');
  const deadline = Date.now() + 25000;
  while (running.size && Date.now() < deadline) await new Promise((r) => setTimeout(r, 500));
  for (const r of running.values()) r.abort();
  await new Promise((r) => setTimeout(r, 1000));
  await requeueOwnJobs(config.workerId, { ids: [...running.keys()] }).catch(() => {});
  await closeDb().catch(() => {});
  process.exit(0);
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('unhandledRejection', (e) => log.error({ err: e?.message || e }, 'unhandledRejection'));

main().catch((e) => { log.error({ err: e.message }, 'не удалось запустить воркер'); process.exit(1); });
