// Качество воспроизведения (QoE): плеер сообщает о старте, буферизациях и ошибках,
// администратор видит сводку — как Mux Data / Conviva, но на своих данных.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, userOrIpKey } from '../lib/util.js';
import { isStaff } from '../lib/access.js';
import { toCsv, sendCsv } from '../lib/csv.js';

const SOURCES = ['watch', 'embed', 'live', 'shorts', 'studio', 'share'];
const DEVICES = ['desktop', 'mobile', 'tablet', 'tv'];

export default async function playbackRoutes(app) {
  // --- Приём метрик от плеера --------------------------------------------------------------------
  // Плеер шлёт пачку раз в полминуты: 60 в минуту на человека хватает с запасом (несколько вкладок, ошибки).
  // Гости (встраивание на внешних страницах) часто сидят за одним адресом NAT — им предел шире.
  app.post('/playback', { config: { rateLimit: { hook: 'preHandler', keyGenerator: userOrIpKey, max: (req, key) => (key.startsWith('u:') ? 60 : 600), timeWindow: '1 minute' } } }, async (req) => {
    if (!req.settings['qoe.enabled']) return { ok: true, disabled: true };
    const b = req.body || {};
    const key = String(b.sessionKey || '').slice(0, 64);
    if (!/^[A-Za-z0-9_-]{8,64}$/.test(key)) throw badRequest('Некорректный идентификатор сеанса');
    const num = (v, max) => (v === undefined || v === null ? null : Math.max(0, Math.min(max, Math.round(Number(v) || 0))));
    const videoId = typeof b.videoId === 'string' && /^[0-9a-f-]{36}$/i.test(b.videoId) ? b.videoId : null;
    const streamId = typeof b.streamId === 'string' && /^[0-9a-f-]{36}$/i.test(b.streamId) ? b.streamId : null;
    const source = SOURCES.includes(b.source) ? b.source : 'watch';
    const device = DEVICES.includes(b.device) ? b.device : 'desktop';
    const browser = String(b.browser || '').slice(0, 40);
    const errorText = b.errorText ? String(b.errorText).slice(0, 300) : null;
    await query(
      `INSERT INTO playback_sessions(session_key, video_id, stream_id, user_id, startup_ms, watch_sec, rebuffer_count, rebuffer_ms,
         quality_height, bitrate_kbps, errors, error_text, started, device, browser, source)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16)
       ON CONFLICT (session_key) DO UPDATE SET
         startup_ms = COALESCE(playback_sessions.startup_ms, EXCLUDED.startup_ms),
         watch_sec = greatest(playback_sessions.watch_sec, EXCLUDED.watch_sec),
         rebuffer_count = greatest(playback_sessions.rebuffer_count, EXCLUDED.rebuffer_count),
         rebuffer_ms = greatest(playback_sessions.rebuffer_ms, EXCLUDED.rebuffer_ms),
         quality_height = COALESCE(EXCLUDED.quality_height, playback_sessions.quality_height),
         bitrate_kbps = COALESCE(EXCLUDED.bitrate_kbps, playback_sessions.bitrate_kbps),
         errors = greatest(playback_sessions.errors, EXCLUDED.errors),
         error_text = COALESCE(EXCLUDED.error_text, playback_sessions.error_text),
         started = playback_sessions.started OR EXCLUDED.started,
         updated_at = now()`,
      [key, videoId, streamId, req.user?.id || null, num(b.startupMs, 600000), num(b.watchSec, 86400) || 0,
        num(b.rebufferCount, 10000) || 0, num(b.rebufferMs, 86400000) || 0, num(b.qualityHeight, 4320), num(b.bitrateKbps, 200000),
        num(b.errors, 1000) || 0, errorText, !!b.started, device, browser, source],
    );
    return { ok: true };
  });

  // --- Сводка для администратора ------------------------------------------------------------------
  app.get('/admin/quality', { preHandler: app.requireActive }, async (req, reply) => {
    // Сводка называет самые проблемные видео по всему порталу, включая закрытые, — только модераторам и администраторам
    if (!isStaff(req.user)) throw forbidden('Раздел доступен модераторам и администраторам');
    const days = Math.min(90, Math.max(1, Number(req.query.days) || 7));
    const totals = await one(
      `SELECT count(*)::int AS sessions,
              count(*) FILTER (WHERE started)::int AS started,
              count(*) FILTER (WHERE errors > 0)::int AS failed,
              percentile_disc(0.5) WITHIN GROUP (ORDER BY startup_ms) FILTER (WHERE startup_ms IS NOT NULL) AS startup_median,
              percentile_disc(0.95) WITHIN GROUP (ORDER BY startup_ms) FILTER (WHERE startup_ms IS NOT NULL) AS startup_p95,
              COALESCE(sum(rebuffer_ms), 0)::bigint AS rebuffer_ms,
              COALESCE(sum(watch_sec), 0)::bigint AS watch_sec,
              COALESCE(sum(rebuffer_count), 0)::int AS rebuffers,
              round(avg(bitrate_kbps))::int AS avg_bitrate
       FROM playback_sessions WHERE created_at > now() - ($1 || ' days')::interval`, [String(days)]);
    const watchMs = Number(totals.watch_sec) * 1000 + Number(totals.rebuffer_ms);
    const byDay = await many(
      `SELECT date_trunc('day', created_at)::date AS day, count(*)::int AS sessions,
              percentile_disc(0.5) WITHIN GROUP (ORDER BY startup_ms) FILTER (WHERE startup_ms IS NOT NULL) AS startup_median,
              COALESCE(sum(rebuffer_ms), 0)::bigint AS rebuffer_ms, COALESCE(sum(watch_sec), 0)::bigint AS watch_sec,
              count(*) FILTER (WHERE errors > 0)::int AS failed
       FROM playback_sessions WHERE created_at > now() - ($1 || ' days')::interval GROUP BY 1 ORDER BY 1`, [String(days)]);
    const byDevice = await many(
      `SELECT device, count(*)::int AS sessions,
              percentile_disc(0.5) WITHIN GROUP (ORDER BY startup_ms) FILTER (WHERE startup_ms IS NOT NULL) AS startup_median,
              COALESCE(sum(rebuffer_ms), 0)::bigint AS rebuffer_ms, COALESCE(sum(watch_sec), 0)::bigint AS watch_sec,
              count(*) FILTER (WHERE errors > 0)::int AS failed
       FROM playback_sessions WHERE created_at > now() - ($1 || ' days')::interval GROUP BY 1 ORDER BY sessions DESC`, [String(days)]);
    const byQuality = await many(
      `SELECT COALESCE(quality_height, 0) AS height, count(*)::int AS sessions FROM playback_sessions
       WHERE created_at > now() - ($1 || ' days')::interval GROUP BY 1 ORDER BY 1 DESC`, [String(days)]);
    const worst = await many(
      `SELECT v.id, v.short_id, v.title, count(*)::int AS sessions,
              COALESCE(sum(p.rebuffer_ms), 0)::bigint AS rebuffer_ms, COALESCE(sum(p.watch_sec), 0)::bigint AS watch_sec,
              count(*) FILTER (WHERE p.errors > 0)::int AS failed,
              percentile_disc(0.5) WITHIN GROUP (ORDER BY p.startup_ms) FILTER (WHERE p.startup_ms IS NOT NULL) AS startup_median
       FROM playback_sessions p JOIN videos v ON v.id = p.video_id
       WHERE p.created_at > now() - ($1 || ' days')::interval GROUP BY v.id, v.short_id, v.title
       HAVING count(*) >= 2 ORDER BY (COALESCE(sum(p.rebuffer_ms), 0)::float / greatest(1, sum(p.watch_sec) * 1000 + sum(p.rebuffer_ms))) DESC, failed DESC LIMIT 10`, [String(days)]);
    const errors = await many(
      `SELECT error_text, count(*)::int AS n, max(created_at) AS last_at FROM playback_sessions
       WHERE created_at > now() - ($1 || ' days')::interval AND error_text IS NOT NULL GROUP BY 1 ORDER BY n DESC LIMIT 10`, [String(days)]);

    const ratio = (rb, ws) => { const total = Number(ws) * 1000 + Number(rb); return total > 0 ? Math.round((Number(rb) / total) * 10000) / 100 : 0; };
    const data = {
      days,
      totals: {
        sessions: totals.sessions, started: totals.started, failed: totals.failed,
        startupMedianMs: totals.startup_median === null ? null : Number(totals.startup_median),
        startupP95Ms: totals.startup_p95 === null ? null : Number(totals.startup_p95),
        rebufferRatio: ratio(totals.rebuffer_ms, totals.watch_sec),
        rebuffers: totals.rebuffers,
        watchHours: Math.round((Number(totals.watch_sec) / 3600) * 10) / 10,
        failureRate: totals.sessions ? Math.round((totals.failed / totals.sessions) * 1000) / 10 : 0,
        startFailureRate: totals.sessions ? Math.round(((totals.sessions - totals.started) / totals.sessions) * 1000) / 10 : 0,
        avgBitrate: totals.avg_bitrate || null,
        watchMs,
      },
      byDay: byDay.map((d) => ({ day: d.day, sessions: d.sessions, startupMedianMs: d.startup_median === null ? null : Number(d.startup_median), rebufferRatio: ratio(d.rebuffer_ms, d.watch_sec), failed: d.failed })),
      byDevice: byDevice.map((d) => ({ device: d.device || 'desktop', sessions: d.sessions, startupMedianMs: d.startup_median === null ? null : Number(d.startup_median), rebufferRatio: ratio(d.rebuffer_ms, d.watch_sec), failed: d.failed })),
      byQuality: byQuality.map((q) => ({ height: Number(q.height) || null, sessions: q.sessions })),
      worst: worst.map((w) => ({ id: w.id, shortId: w.short_id, title: w.title, sessions: w.sessions, rebufferRatio: ratio(w.rebuffer_ms, w.watch_sec), failed: w.failed, startupMedianMs: w.startup_median === null ? null : Number(w.startup_median) })),
      errors: errors.map((e) => ({ text: e.error_text, count: e.n, lastAt: e.last_at })),
    };
    if (req.query.format === 'csv') {
      const csv = toCsv(data.byDay, [
        { title: 'Дата', value: (d) => new Date(d.day).toLocaleDateString('ru-RU') },
        { title: 'Сеансов', value: 'sessions' },
        { title: 'Старт (медиана), мс', value: (d) => d.startupMedianMs ?? '' },
        { title: 'Доля буферизации, %', value: 'rebufferRatio' },
        { title: 'С ошибками', value: 'failed' },
      ]);
      return sendCsv(reply, 'playback-quality.csv', csv);
    }
    return data;
  });
}
