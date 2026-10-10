// Вебинары: регистрация на эфир, напоминания зарегистрированным, учёт присутствия и отчёт.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, notFound, userOrIpKey } from '../lib/util.js';
import { isStaff, isActive } from '../lib/access.js';
import { notify } from '../lib/notify.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { audit } from '../lib/audit.js';
import { config } from '../config.js';
import { attendThreshold, wasPresent } from '../jobs/webinar.js';
import { markPresence } from './webinars.js';

async function loadStream(idOrShort) {
  return one(`SELECT s.*, u.display_name AS owner_name FROM live_streams s JOIN users u ON u.id = s.owner_id WHERE s.id = cv_uuid($1) OR s.short_id = $1`, [String(idOrShort)]);
}
function canManage(s, user) { return !!user && (s.owner_id === user.id || isStaff(user)); }

/** Может ли пользователь видеть эфир (для регистрации достаточно видимости). */
function canSee(s, user) {
  if (canManage(s, user)) return true;
  if (s.visibility === 'public' || s.visibility === 'unlisted') return true;
  if (s.visibility === 'internal') return isActive(user);
  return false;
}

export default async function webinarRoutes(app) {
  // --- Регистрация ------------------------------------------------------------------------------
  app.get('/live/:id/registration', async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Эфир не найден');
    if (!canSee(s, req.user)) throw forbidden('Нет доступа к этому эфиру');
    const count = await one('SELECT count(*)::int AS n FROM live_registrations WHERE stream_id = $1', [s.id]);
    const mine = req.user ? await one('SELECT * FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]) : null;
    const seatsLeft = s.registration_limit ? Math.max(0, s.registration_limit - count.n) : null;
    return {
      enabled: !!s.registration, note: s.registration_note || '', limit: s.registration_limit || null,
      count: count.n, seatsLeft, registered: !!mine, registeredAt: mine?.created_at || null,
      scheduledAt: s.scheduled_at, title: s.title, canManage: canManage(s, req.user),
    };
  });

  app.post('/live/:id/registration', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Эфир не найден');
    if (!canSee(s, req.user)) throw forbidden('Нет доступа к этому эфиру');
    if (!s.registration) throw badRequest('Регистрация на этот эфир не требуется');
    if (s.status === 'ended') throw badRequest('Эфир уже завершён');
    const exists = await one('SELECT id FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]);
    if (exists) return { ok: true, registered: true };
    if (s.registration_limit) {
      const count = await one('SELECT count(*)::int AS n FROM live_registrations WHERE stream_id = $1', [s.id]);
      if (count.n >= s.registration_limit) throw badRequest('Мест больше нет');
    }
    await query('INSERT INTO live_registrations(stream_id, user_id, name, email, note) VALUES ($1,$2,$3,$4,$5) ON CONFLICT DO NOTHING',
      [s.id, req.user.id, req.user.display_name, req.user.email, String(req.body?.note || '').slice(0, 500)]);
    // Регистрация подразумевает напоминание за 15 минут до начала
    await query('INSERT INTO live_reminders(stream_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [s.id, req.user.id]);
    await notify(req.user.id, {
      type: 'live_reminder', title: `Вы зарегистрированы: ${s.title}`,
      body: s.scheduled_at ? `Начало ${new Date(s.scheduled_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })}. Напомним за 15 минут.` : 'Напомним, когда эфир начнётся.',
      link: `/live/${s.short_id}`, data: { streamId: s.id },
    });
    return { ok: true, registered: true };
  });

  app.delete('/live/:id/registration', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Эфир не найден');
    await query('DELETE FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]);
    await query('DELETE FROM live_reminders WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]);
    return { ok: true, registered: false };
  });

  // --- Присутствие: «сердцебиение» страницы эфира ------------------------------------------------
  app.post('/live/:id/attendance', { preHandler: app.requireActive, config: { rateLimit: { hook: 'preHandler', keyGenerator: userOrIpKey, max: 10, timeWindow: '1 minute' } } }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Эфир не найден');
    if (!canSee(s, req.user)) throw forbidden();
    // Присутствие копится только во время эфира: иначе его можно было бы «досидеть» после окончания
    if (s.status !== 'live') return { ok: true, ignored: 'not-live' };
    const delta = Math.max(0, Math.min(120, Math.round(Number(req.body?.seconds) || 0)));
    if (!delta) return { ok: true };
    // Засчитываем не больше, чем прошло с прошлого сигнала (+5 с на сеть): частые запросы
    // и несколько открытых вкладок не накручивают время, от которого зависят сертификаты.
    await query(
      `INSERT INTO live_attendance(stream_id, user_id, seconds) VALUES ($1,$2,LEAST($3, 60))
       ON CONFLICT (stream_id, user_id) DO UPDATE SET
         seconds = live_attendance.seconds + LEAST($3, GREATEST(0, EXTRACT(EPOCH FROM now() - live_attendance.last_at))::int + 5),
         last_at = now()`,
      [s.id, req.user.id, delta],
    );
    if (s.webinar) await markPresence(s, { userId: req.user.id });
    return { ok: true };
  });

  // --- Отчёт для организатора --------------------------------------------------------------------
  app.get('/live/:id/attendees', { preHandler: app.requireActive }, async (req, reply) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Эфир не найден');
    if (!canManage(s, req.user)) throw forbidden('Отчёт доступен организатору эфира и модераторам');
    const rows = await many(
      `SELECT u.id, u.display_name, u.email,
              r.created_at AS registered_at,
              COALESCE(a.seconds, 0) AS seconds, a.first_at, a.last_at
       FROM users u
       LEFT JOIN live_registrations r ON r.stream_id = $1 AND r.user_id = u.id
       LEFT JOIN live_attendance a ON a.stream_id = $1 AND a.user_id = u.id
       WHERE r.id IS NOT NULL OR a.user_id IS NOT NULL
       ORDER BY COALESCE(a.seconds, 0) DESC, u.display_name`, [s.id]);
    const durationSec = s.started_at && s.ended_at ? Math.round((new Date(s.ended_at) - new Date(s.started_at)) / 1000) : (s.started_at ? Math.round((Date.now() - new Date(s.started_at)) / 1000) : 0);
    const need = attendThreshold(req.settings, durationSec);
    const people = rows.map((r) => ({
      id: r.id, displayName: r.display_name, email: r.email,
      registeredAt: r.registered_at, seconds: Number(r.seconds) || 0,
      percent: durationSec > 0 ? Math.min(100, Math.round(((Number(r.seconds) || 0) / durationSec) * 100)) : null,
      firstAt: r.first_at, lastAt: r.last_at,
      // Для вебинара участие засчитывается с порога из настроек, для обычного эфира — любое присутствие
      attended: s.webinar ? wasPresent(Number(r.seconds) || 0, need) : (Number(r.seconds) || 0) > 0,
    }));
    if (req.query.format === 'csv') {
      const csv = toCsv(people, [
        { title: 'Сотрудник', value: 'displayName' },
        { title: 'Почта', value: 'email' },
        { title: 'Зарегистрировался', value: (p) => (p.registeredAt ? new Date(p.registeredAt).toLocaleString('ru-RU') : '') },
        { title: 'Был на эфире', value: (p) => (p.attended ? 'да' : 'нет') },
        { title: 'Минут', value: (p) => Math.round(p.seconds / 60) },
        { title: 'Доля эфира', value: (p) => (p.percent === null ? '' : `${p.percent}%`) },
      ]);
      return sendCsv(reply, `webinar-${s.short_id}.csv`, csv);
    }
    await audit(req, 'live.attendees', { targetType: 'live', targetId: s.id });
    return {
      stream: { id: s.id, shortId: s.short_id, title: s.title, scheduledAt: s.scheduled_at, startedAt: s.started_at, endedAt: s.ended_at, durationSec },
      people,
      totals: {
        registered: people.filter((p) => p.registeredAt).length,
        attended: people.filter((p) => p.attended).length,
        noShow: people.filter((p) => p.registeredAt && !p.attended).length,
        avgPercent: people.filter((p) => p.attended).length
          ? Math.round(people.filter((p) => p.attended).reduce((n, p) => n + (p.percent || 0), 0) / people.filter((p) => p.attended).length)
          : 0,
      },
    };
  });

  // Приглашение зарегистрированным: ссылка на эфир для рассылки
  app.get('/live/:id/registration-link', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Эфир не найден');
    if (!canManage(s, req.user)) throw forbidden();
    return { url: `${config.baseUrl}/live/${s.short_id}`, calendarUrl: `${config.baseUrl}/api/live/${s.id}/calendar.ics` };
  });
}
