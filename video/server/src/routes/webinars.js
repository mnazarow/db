// Раздел «Вебинары»: каталог, лендинг с программой и спикерами, регистрация (в том числе внешних
// участников по персональной ссылке), модерация заявок и лист ожидания, материалы, отчёт и письма.
import path from 'node:path';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import { one, many, query, withTxLock } from '../db.js';
import { config } from '../config.js';
import { badRequest, forbidden, notFound, slugify, extOf, safeFilename, EMAIL_RE, paging, userOrIpKey } from '../lib/util.js';
import { isStaff, isActive, canManageWebinar, canSeeWebinar } from '../lib/access.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { randomToken } from '../lib/crypto.js';
import { notify } from '../lib/notify.js';
import { templates, emailEnabled } from '../lib/mailer.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { audit } from '../lib/audit.js';
import { streamIcs } from './live-interact.js';
import { MIME as ATTACH_MIME } from './attachments.js';
import { liveViewerCount } from './live.js';
import { browserPath, mediaUrl } from '../lib/urls.js';

const FIELD_TYPES = ['text', 'textarea', 'select', 'checkbox'];
const WEBINAR_COOKIE = 'cv_webinar';

export async function loadWebinar(idOrSlug) {
  return one(
    `SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar,
            rv.short_id AS recording_short_id
     FROM live_streams s JOIN users u ON u.id = s.owner_id
     LEFT JOIN videos rv ON rv.id = s.recording_video_id
     WHERE s.slug = $1 OR s.short_id = $1 OR s.id = cv_uuid($1)`, [String(idOrSlug)]);
}

export { canManageWebinar };
const canSee = canSeeWebinar;

/** Действующая регистрация внешнего участника по токену из cookie или запроса. */
export async function guestRegistration(streamId, req) {
  const tokens = [];
  const raw = req.cookies?.[WEBINAR_COOKIE];
  if (raw) tokens.push(...String(raw).split(',').filter((t) => /^[A-Za-z0-9_-]{8,64}$/.test(t)).slice(0, 10));
  const q = req.query?.t || req.headers['x-webinar-token'];
  if (q && /^[A-Za-z0-9_-]{8,64}$/.test(String(q))) tokens.push(String(q));
  if (!tokens.length) return null;
  return one(`SELECT * FROM live_registrations WHERE stream_id = $1 AND token = ANY($2::text[]) AND status = 'approved'`, [streamId, tokens]);
}

/** Адрес материала: отдельный маршрут с проверкой регистрации (через /media/ материалы не раздаются). */
export const materialUrl = (s, m, token = null) => `/api/webinars/${encodeURIComponent(s.slug || s.short_id)}/materials/${m.id}${token ? `?t=${token}` : ''}`;

function webinarCard(s, extra = {}) {
  return {
    id: s.id, shortId: s.short_id, slug: s.slug || s.short_id, title: s.title,
    description: s.description || '', coverUrl: mediaUrl(s.cover_path || s.thumbnail_path),
    scheduledAt: s.scheduled_at, startedAt: s.started_at, endedAt: s.ended_at, status: s.status,
    visibility: s.visibility, speakers: s.speakers || [], registrationCount: Number(s.reg_count) || 0,
    registrationLimit: s.registration_limit || null, recordingShortId: s.recording_short_id || null,
    owner: { id: s.owner_id, displayName: s.owner_name, handle: s.owner_handle },
    autoWebinar: !!s.source_video_id, ...extra,
  };
}

/** Сколько мест занято (одобренные заявки). */
async function seatInfo(s) {
  const r = await one(`SELECT count(*) FILTER (WHERE status = 'approved')::int AS approved,
                              count(*) FILTER (WHERE status = 'pending')::int AS pending,
                              count(*) FILTER (WHERE status = 'waitlist')::int AS waitlist,
                              count(*)::int AS total FROM live_registrations WHERE stream_id = $1`, [s.id]);
  const seatsLeft = s.registration_limit ? Math.max(0, s.registration_limit - (r?.approved || 0)) : null;
  return { ...r, seatsLeft };
}

function whenLabel(d) {
  return d ? new Date(d).toLocaleString('ru-RU', { dateStyle: 'long', timeStyle: 'short' }) : '';
}

/** Ссылка участника: персональная для внешних, обычная — для сотрудников. */
function joinUrl(s, reg) {
  const base = `${config.baseUrl}/webinar/${s.slug || s.short_id}`;
  return reg?.token ? `${base}?t=${reg.token}` : base;
}

export default async function webinarRoutes(app) {
  // --- Каталог -------------------------------------------------------------------------------
  app.get('/webinars', async (req) => {
    if (!req.settings['webinars.enabled']) return { webinars: [], enabled: false };
    const vis = isActive(req.user) || isStaff(req.user) ? `s.visibility IN ('public','internal')` : `s.visibility = 'public'`;
    const past = req.query.scope === 'past';
    const { limit, offset, page } = paging(req.query, 24, 60);
    const rows = await many(
      `SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, rv.short_id AS recording_short_id,
              (SELECT count(*)::int FROM live_registrations r WHERE r.stream_id = s.id AND r.status = 'approved') AS reg_count
       FROM live_streams s JOIN users u ON u.id = s.owner_id LEFT JOIN videos rv ON rv.id = s.recording_video_id
       WHERE s.webinar AND ${vis} AND ${past ? `(s.status = 'ended' OR (s.scheduled_at IS NOT NULL AND s.scheduled_at < now() - interval '4 hours'))` : `(s.status <> 'ended' AND (s.scheduled_at IS NULL OR s.scheduled_at > now() - interval '4 hours'))`}
       ORDER BY s.scheduled_at ${past ? 'DESC NULLS LAST' : 'ASC NULLS LAST'} LIMIT ${limit} OFFSET ${offset}`);
    const scopeCond = past
      ? `(s.status = 'ended' OR (s.scheduled_at IS NOT NULL AND s.scheduled_at < now() - interval '4 hours'))`
      : `(s.status <> 'ended' AND (s.scheduled_at IS NULL OR s.scheduled_at > now() - interval '4 hours'))`;
    const total = (await one(`SELECT count(*)::int AS n FROM live_streams s WHERE s.webinar AND ${vis} AND ${scopeCond}`))?.n || 0;
    return { enabled: true, scope: past ? 'past' : 'upcoming', page, limit, total, webinars: rows.map((s) => webinarCard(s)) };
  });

  // --- Лендинг вебинара ----------------------------------------------------------------------
  app.get('/webinars/:slug', async (req, reply) => {
    const s = await loadWebinar(req.params.slug);
    if (!s || !s.webinar) throw notFound('Вебинар не найден');
    if (!canSee(s, req.user)) throw forbidden('Нет доступа к этому вебинару');
    const guest = await guestRegistration(s.id, req);
    if (req.query.t && guest) {
      // Персональная ссылка: запоминаем токен, чтобы участник попал в комнату без пароля
      reply.setCookie(WEBINAR_COOKIE, guest.token, { path: '/', httpOnly: true, sameSite: 'lax', secure: config.cookieSecure, maxAge: 60 * 60 * 24 * 30 });
    }
    const mine = req.user ? await one('SELECT * FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]) : guest;
    const seats = await seatInfo(s);
    const materials = await many(`SELECT * FROM webinar_materials WHERE stream_id = $1 ${mine?.status === 'approved' || canManageWebinar(s, req.user) ? '' : 'AND before'} ORDER BY position, created_at`, [s.id]);
    const open = s.status !== 'ended' && (!s.reg_closes_at || new Date(s.reg_closes_at) > new Date());
    return {
      webinar: {
        ...webinarCard(s, { registrationCount: seats.approved }),
        agenda: s.agenda || [], speakers: s.speakers || [], cta: s.cta || null,
        registration: {
          enabled: !!s.registration, open, note: s.registration_note || '', fields: s.reg_fields || [],
          external: !!s.reg_external, moderation: !!s.reg_moderation, waitlist: !!s.reg_waitlist,
          closesAt: s.reg_closes_at, seatsLeft: seats.seatsLeft, count: seats.approved,
          my: mine ? { status: mine.status, name: mine.name, email: mine.email, registeredAt: mine.created_at, joinUrl: joinUrl(s, mine) } : null,
        },
        materials: materials.map((m) => ({ id: m.id, name: m.name, url: browserPath(materialUrl(s, m)), bytes: Number(m.bytes) || 0, before: m.before })),
        canManage: canManageWebinar(s, req.user),
        cohosts: canManageWebinar(s, req.user) ? s.cohosts : undefined,
        certificate: { enabled: !!s.cert_enabled, minPercent: s.cert_min_percent },
        viewerCount: s.status === 'live' ? liveViewerCount(s.id) : 0,
        liveUrl: `/live/${s.short_id}`,
        certUrl: mine?.cert_id ? `/cert/${mine.cert_id}` : null,
      },
    };
  });

  app.get('/webinars/:slug/calendar.ics', async (req, reply) => {
    const s = await loadWebinar(req.params.slug);
    if (!s || !s.webinar) throw notFound();
    if (!canSee(s, req.user)) throw forbidden();
    reply.header('Content-Type', 'text/calendar; charset=utf-8').header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent('webinar-' + (s.slug || s.short_id) + '.ics')}`);
    return streamIcs(s, config.baseUrl);
  });

  // --- Регистрация ---------------------------------------------------------------------------
  // Предел — на сотрудника, а для гостей — на адрес: сотрудники одного офиса часто выходят через один
  // адрес, и общий предел «20 на адрес» не давал записаться на популярный вебинар по рассылке
  app.post('/webinars/:slug/register', { config: { rateLimit: { hook: 'preHandler', keyGenerator: userOrIpKey, max: (req, key) => (key.startsWith('u:') ? 20 : 60), timeWindow: '10 minutes' } } }, async (req, reply) => {
    const s = await loadWebinar(req.params.slug);
    if (!s || !s.webinar) throw notFound('Вебинар не найден');
    if (!canSee(s, req.user)) throw forbidden('Нет доступа к этому вебинару');
    if (!s.registration) throw badRequest('Регистрация на этот вебинар не нужна — просто приходите к началу');
    if (s.status === 'ended') throw badRequest('Вебинар уже прошёл');
    if (s.reg_closes_at && new Date(s.reg_closes_at) < new Date()) throw badRequest('Регистрация закрыта');
    const b = req.body || {};
    const external = !req.user;
    if (external && !s.reg_external) throw forbidden('Регистрация только для сотрудников — войдите на портал');
    const name = String(b.name || req.user?.display_name || '').trim().slice(0, 150);
    const email = String(b.email || req.user?.email || '').trim().toLowerCase().slice(0, 200);
    if (!name) throw badRequest('Укажите имя');
    if (!EMAIL_RE.test(email)) throw badRequest('Укажите корректный адрес электронной почты');

    // Ответы анкеты
    const answers = {};
    for (const f of (s.reg_fields || [])) {
      const v = b.answers?.[f.id];
      const val = f.type === 'checkbox' ? !!v : String(v ?? '').trim().slice(0, 1000);
      if (f.required && (f.type === 'checkbox' ? !val : !val.length)) throw badRequest(`Заполните поле «${f.label}»`);
      answers[f.id] = val;
    }

    const exists = req.user
      ? await one('SELECT * FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id])
      : await one('SELECT * FROM live_registrations WHERE stream_id = $1 AND email = $2', [s.id, email]);
    if (exists && exists.status !== 'cancelled') {
      // Ссылку с персональным токеном отдаём только тому, кто вошёл в портал.
      // Анонимному запросу — нейтральный ответ: иначе по чужой почте можно было получить чужой вход.
      if (!req.user) {
        if (exists.token && await emailEnabled()) {
          await templates.webinarConfirm({
            to: exists.email, name: exists.name, title: s.title, when: whenLabel(s.scheduled_at), url: joinUrl(s, exists),
            note: s.registration_note || '', pending: exists.status === 'pending', waitlist: exists.status === 'waitlist', materials: [],
          }).catch(() => {});
        }
        return { ok: true, status: exists.status, already: true, mailed: true };
      }
      return { ok: true, status: exists.status, joinUrl: joinUrl(s, exists), already: true };
    }

    const token = randomToken(18);
    // Пересчёт мест и запись — под блокировкой: иначе одновременные «Записаться»
    // все видят одно свободное место и лимит уходит в минус.
    const { status, row } = await withTxLock(`webinar-seats:${s.id}`, async (c) => {
      const cnt = await c.one(`SELECT count(*) FILTER (WHERE status = 'approved')::int AS approved FROM live_registrations WHERE stream_id = $1`, [s.id]);
      const seatsLeft = s.registration_limit ? Math.max(0, s.registration_limit - (cnt?.approved || 0)) : null;
      let st = 'approved';
      if (s.reg_moderation) st = 'pending';
      else if (seatsLeft === 0) {
        if (!s.reg_waitlist) throw badRequest('Мест больше нет');
        st = 'waitlist';
      }
      const r = exists
        ? await c.one(`UPDATE live_registrations SET status = $2, name = $3, email = $4, answers = $5::jsonb, company = $6, note = $7, token = COALESCE(token, $8), created_at = now() WHERE id = $1 RETURNING *`,
          [exists.id, st, name, email, JSON.stringify(answers), String(b.company || '').slice(0, 200), String(b.note || '').slice(0, 500), token])
        : await c.one(
          `INSERT INTO live_registrations(stream_id, user_id, name, email, note, status, token, answers, company)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8::jsonb,$9) RETURNING *`,
          [s.id, req.user?.id || null, name, email, String(b.note || '').slice(0, 500), st, token, JSON.stringify(answers), String(b.company || '').slice(0, 200)]);
      return { status: st, row: r };
    });

    if (req.user && status === 'approved') await query('INSERT INTO live_reminders(stream_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [s.id, req.user.id]);
    if (external && status === 'approved') {
      reply.setCookie(WEBINAR_COOKIE, token, { path: '/', httpOnly: true, sameSite: 'lax', secure: config.cookieSecure, maxAge: 60 * 60 * 24 * 30 });
    }
    const materials = await many('SELECT * FROM webinar_materials WHERE stream_id = $1 AND before ORDER BY position', [s.id]);
    if (await emailEnabled()) {
      await templates.webinarConfirm({
        to: email, name, title: s.title, when: whenLabel(s.scheduled_at), url: joinUrl(s, row),
        note: s.registration_note || '', pending: status === 'pending', waitlist: status === 'waitlist',
        materials: materials.map((m) => ({ name: m.name, url: `${config.baseUrl}${materialUrl(s, m, row.token)}` })),
      }).catch(() => {});
    }
    if (req.user) {
      await notify(req.user.id, {
        type: 'live_reminder',
        title: status === 'approved' ? `Вы записаны на вебинар: ${s.title}` : status === 'pending' ? `Заявка на вебинар отправлена: ${s.title}` : `Вы в листе ожидания: ${s.title}`,
        body: s.scheduled_at ? `Начало ${whenLabel(s.scheduled_at)}` : 'Дата пока не назначена',
        link: `/webinar/${s.slug || s.short_id}`, data: { streamId: s.id },
      });
    }
    if (status === 'pending') {
      await notify(s.owner_id, { type: 'live_reminder', title: `Новая заявка на вебинар «${s.title}»`, body: `${name} (${email})`, link: `/studio/webinars/${s.id}`, data: { streamId: s.id } });
    }
    return { ok: true, status, joinUrl: joinUrl(s, row) };
  });

  app.post('/webinars/:slug/cancel', async (req) => {
    const s = await loadWebinar(req.params.slug);
    if (!s) throw notFound();
    const guest = await guestRegistration(s.id, req);
    const reg = req.user ? await one('SELECT * FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]) : guest;
    if (!reg) throw notFound('Регистрации не найдено');
    await query(`UPDATE live_registrations SET status = 'cancelled', decided_at = now() WHERE id = $1`, [reg.id]);
    if (reg.user_id) await query('DELETE FROM live_reminders WHERE stream_id = $1 AND user_id = $2', [s.id, reg.user_id]);
    await promoteWaitlist(s);
    return { ok: true, status: 'cancelled' };
  });

  // Материал вебинара: «до вебинара» — всем, кто видит страницу; остальные — только подтверждённым
  // участникам и организаторам. Отдаётся вложением с безопасными заголовками: SVG или HTML с нашего
  // домена не должны открываться как страница (иначе скрипт внутри работал бы от имени портала).
  app.get('/webinars/:slug/materials/:mid', async (req, reply) => {
    const s = await loadWebinar(req.params.slug);
    if (!s || !s.webinar) throw notFound('Вебинар не найден');
    if (!canSee(s, req.user)) throw req.user ? forbidden('Нет доступа к этому вебинару') : notFound('Вебинар не найден');
    const m = await one('SELECT * FROM webinar_materials WHERE id = cv_uuid($1) AND stream_id = $2', [String(req.params.mid), s.id]);
    if (!m) throw notFound('Материал не найден');
    if (!m.before && !canManageWebinar(s, req.user)) {
      const mine = req.user ? await one(`SELECT status FROM live_registrations WHERE stream_id = $1 AND user_id = $2`, [s.id, req.user.id]) : await guestRegistration(s.id, req);
      if (mine?.status !== 'approved') throw forbidden('Материал доступен участникам вебинара');
    }
    const ext = extOf(m.name);
    const inline = req.query.inline !== undefined && /^(pdf|png|jpe?g|gif|txt|md)$/.test(ext);
    reply.header('Content-Disposition', `${inline ? 'inline' : 'attachment'}; filename*=UTF-8''${encodeURIComponent(m.name)}`);
    reply.header('Content-Type', ext === 'svg' || !ATTACH_MIME[ext] ? 'application/octet-stream' : ATTACH_MIME[ext]);
    reply.header('X-Content-Type-Options', 'nosniff');
    reply.header('Cache-Control', 'private, max-age=3600');
    const accel = storage.accel(req, m.path);
    if (accel) { reply.header('X-Accel-Redirect', accel); return ''; }
    const abs = storage.abs(m.path);
    let st;
    try { st = await fs.promises.stat(abs); } catch { throw notFound('Файл не найден'); }
    reply.header('Content-Length', st.size);
    return reply.send(fs.createReadStream(abs));
  });

  // Присутствие внешнего участника (для сотрудников — обычный /live/:id/attendance)
  app.post('/webinars/:slug/attendance', { config: { rateLimit: { hook: 'preHandler', keyGenerator: (req) => `w:${req.params.slug}:${userOrIpKey(req)}:${(req.webinarTokens || []).join(',')}`, max: 10, timeWindow: '1 minute' } } }, async (req) => {
    const s = await loadWebinar(req.params.slug);
    if (!s) throw notFound();
    const reg = req.user
      ? await one('SELECT * FROM live_registrations WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id])
      : await guestRegistration(s.id, req);
    if (!reg || reg.status !== 'approved') throw forbidden('Нет подтверждённой регистрации на этот вебинар');
    if (s.status !== 'live') return { ok: true, ignored: 'not-live' };
    const delta = Math.max(0, Math.min(120, Math.round(Number(req.body?.seconds) || 0)));
    if (!delta) return { ok: true };
    // Не больше, чем прошло с прошлого сигнала (+5 с на сеть) — см. /live/:id/attendance
    await query(
      `UPDATE live_registrations SET
         seconds = seconds + LEAST($2, CASE WHEN last_at IS NULL THEN 60 ELSE GREATEST(0, EXTRACT(EPOCH FROM now() - last_at))::int + 5 END),
         first_at = COALESCE(first_at, now()), last_at = now()
       WHERE id = $1`, [reg.id, delta]);
    await markPresence(s, { registrationId: reg.id });
    return { ok: true };
  });
}

/**
 * Отметка для кривой присутствия: каждый человек учитывается в минуте эфира один раз,
 * сколько бы сигналов ни пришло. Гости отмечаются по регистрации, сотрудники — по учётной записи.
 */
export async function markPresence(s, { registrationId = null, userId = null } = {}) {
  if (!s.started_at || s.status !== 'live' || (!registrationId && !userId)) return false;
  const minute = Math.max(0, Math.floor((Date.now() - new Date(s.started_at).getTime()) / 60000));
  const mark = registrationId
    ? await query('INSERT INTO webinar_presence_marks(stream_id, minute, registration_id) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING', [s.id, minute, registrationId])
    : await query('INSERT INTO webinar_presence_users(stream_id, minute, user_id) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING', [s.id, minute, userId]);
  if (!mark.rowCount) return false;
  await query(`INSERT INTO webinar_presence(stream_id, minute, people) VALUES ($1,$2,1)
               ON CONFLICT (stream_id, minute) DO UPDATE SET people = webinar_presence.people + 1`, [s.id, minute]);
  return true;
}

/** Перевести первых из листа ожидания в участники, если освободились места. */
export async function promoteWaitlist(s) {
  if (!s.registration_limit) return 0;
  // Тот же ключ, что и при записи: одновременные отмены не должны пропустить лишних из очереди
  const queue = await withTxLock(`webinar-seats:${s.id}`, async (c) => {
    const seats = await c.one(`SELECT count(*) FILTER (WHERE status = 'approved')::int AS approved FROM live_registrations WHERE stream_id = $1`, [s.id]);
    const free = s.registration_limit - (seats?.approved || 0);
    if (free <= 0) return [];
    const rows = await c.many(`SELECT * FROM live_registrations WHERE stream_id = $1 AND status = 'waitlist' ORDER BY created_at LIMIT $2 FOR UPDATE`, [s.id, free]);
    if (rows.length) await c.query(`UPDATE live_registrations SET status = 'approved', decided_at = now() WHERE id = ANY($1)`, [rows.map((r) => r.id)]);
    return rows;
  });
  let n = 0;
  for (const r of queue) {
    if (r.user_id) {
      await query('INSERT INTO live_reminders(stream_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [s.id, r.user_id]);
      await notify(r.user_id, { type: 'live_reminder', title: `Место освободилось: ${s.title}`, body: 'Вы участвуете в вебинаре', link: `/webinar/${s.slug || s.short_id}`, data: { streamId: s.id } });
    }
    if (await emailEnabled()) {
      await templates.webinarConfirm({ to: r.email, name: r.name, title: s.title, when: whenLabel(s.scheduled_at), url: joinUrl(s, r), note: '' }).catch(() => {});
    }
    n += 1;
  }
  return n;
}
