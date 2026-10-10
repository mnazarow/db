// Кабинет организатора вебинара: настройки лендинга, анкета, заявки, материалы, соведущие, отчёт и письма.
import path from 'node:path';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import { one, many, query } from '../db.js';
import { config } from '../config.js';
import { badRequest, forbidden, notFound, slugify, extOf, safeFilename, EMAIL_RE } from '../lib/util.js';
import { isStaff, canEditVideo } from '../lib/access.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { randomToken, shortId } from '../lib/crypto.js';
import { notify } from '../lib/notify.js';
import { templates, emailEnabled } from '../lib/mailer.js';
import { toCsv, sendCsv } from '../lib/csv.js';
import { audit } from '../lib/audit.js';
import { loadWebinar, canManageWebinar, promoteWaitlist, materialUrl } from './webinars.js';
import { ALLOWED as ATTACH_ALLOWED } from './attachments.js';
import { sendWebinarFollowup, issueWebinarCertificates, certThreshold, attendThreshold, wasPresent } from '../jobs/webinar.js';
import { browserPath, mediaUrl } from '../lib/urls.js';

const FIELD_TYPES = ['text', 'textarea', 'select', 'checkbox'];
const whenLabel = (d) => (d ? new Date(d).toLocaleString('ru-RU', { dateStyle: 'long', timeStyle: 'short' }) : '');

async function manage(req, idOrSlug) {
  const s = await loadWebinar(idOrSlug ?? req.params.id);
  if (!s) throw notFound('Вебинар не найден');
  if (!canManageWebinar(s, req.user)) throw forbidden('Управлять вебинаром может организатор, соведущий или модератор');
  return s;
}

function normalizeFields(list) {
  return (Array.isArray(list) ? list : []).slice(0, 15).map((f, i) => ({
    id: String(f.id || `f${i + 1}`).replace(/[^a-zA-Z0-9_]/g, '').slice(0, 20) || `f${i + 1}`,
    label: String(f.label || '').trim().slice(0, 120) || `Поле ${i + 1}`,
    type: FIELD_TYPES.includes(f.type) ? f.type : 'text',
    required: !!f.required,
    options: Array.isArray(f.options) ? f.options.slice(0, 20).map((o) => String(o).slice(0, 80)).filter(Boolean) : [],
  })).filter((f) => f.type !== 'select' || f.options.length);
}

function normalizeAgenda(list) {
  return (Array.isArray(list) ? list : []).slice(0, 40).map((a) => ({
    time: String(a.time || '').slice(0, 20),
    title: String(a.title || '').trim().slice(0, 200),
    note: String(a.note || '').trim().slice(0, 500),
  })).filter((a) => a.title);
}

function normalizeSpeakers(list) {
  return (Array.isArray(list) ? list : []).slice(0, 20).map((sp) => ({
    name: String(sp.name || '').trim().slice(0, 120),
    role: String(sp.role || '').trim().slice(0, 150),
    bio: String(sp.bio || '').trim().slice(0, 800),
    photo: sp.photo ? String(sp.photo).slice(0, 300) : null,
    userId: sp.userId || null,
  })).filter((sp) => sp.name);
}

const regOut = (r) => ({
  id: Number(r.id), name: r.name, email: r.email, company: r.company || '', status: r.status,
  userId: r.user_id, answers: r.answers || {}, note: r.note || '', createdAt: r.created_at,
  seconds: Math.max(Number(r.seconds) || 0, Number(r.attend_seconds) || 0),
  firstAt: r.first_at, lastAt: r.last_at, certId: r.cert_id, external: !r.user_id,
});

export default async function webinarStudioRoutes(app) {
  // --- Список вебинаров организатора ---------------------------------------------------------
  app.get('/studio/webinars', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(
      `SELECT s.*, (SELECT count(*)::int FROM live_registrations r WHERE r.stream_id = s.id AND r.status = 'approved') AS reg_count,
              (SELECT count(*)::int FROM live_registrations r WHERE r.stream_id = s.id AND r.status = 'pending') AS pending_count
       FROM live_streams s
       WHERE s.webinar AND (s.owner_id = $1 OR $1 = ANY(s.cohosts) ${isStaff(req.user) ? 'OR true' : ''})
       ORDER BY s.scheduled_at DESC NULLS LAST LIMIT 100`, [req.user.id]);
    return {
      webinars: rows.map((s) => ({
        id: s.id, shortId: s.short_id, slug: s.slug || s.short_id, title: s.title, status: s.status,
        scheduledAt: s.scheduled_at, registrations: s.reg_count, pending: s.pending_count,
        limit: s.registration_limit, visibility: s.visibility, coverUrl: mediaUrl(s.cover_path),
        recordingVideoId: s.recording_video_id, autoWebinar: !!s.source_video_id,
      })),
    };
  });

  // --- Создание вебинара ----------------------------------------------------------------------
  app.post('/studio/webinars', { preHandler: app.requireActive }, async (req) => {
    const b = req.body || {};
    const title = String(b.title || '').trim().slice(0, 150);
    if (!title) throw badRequest('Укажите название вебинара');
    if (!req.settings['webinars.enabled']) throw forbidden('Вебинары отключены администратором');
    let slug = slugify(String(b.slug || title)).slice(0, 60) || `web-${Date.now().toString(36)}`;
    if (await one('SELECT 1 FROM live_streams WHERE slug = $1', [slug])) slug = `${slug}-${Date.now().toString(36).slice(-4)}`;
    const scheduled = b.scheduledAt ? new Date(b.scheduledAt) : null;
    const row = await one(
      `INSERT INTO live_streams(short_id, owner_id, title, description, visibility, stream_key, scheduled_at, webinar, slug,
                                registration, registration_limit, registration_note, chat_enabled, record, reg_external, reg_moderation)
       VALUES ($1,$2,$3,$4,$5,$6,$7,true,$8,true,$9,$10,true,true,$11,$12) RETURNING *`,
      [shortId(), req.user.id, title, String(b.description || '').slice(0, 5000),
        ['public', 'internal', 'unlisted'].includes(b.visibility) ? b.visibility : req.settings['live.default_visibility'],
        randomToken(18), scheduled && !Number.isNaN(scheduled.getTime()) ? scheduled : null, slug,
        b.registrationLimit ? Math.max(1, Number(b.registrationLimit)) : null, String(b.registrationNote || '').slice(0, 1000),
        !!b.regExternal, !!b.regModeration]);
    await audit(req, 'webinar.create', { targetType: 'live', targetId: row.id, details: { title, slug } });
    return { webinar: { id: row.id, shortId: row.short_id, slug: row.slug, title: row.title } };
  });

  // --- Настройки вебинара ---------------------------------------------------------------------
  app.get('/studio/webinars/:id', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const seats = await one(`SELECT count(*) FILTER (WHERE status='approved')::int AS approved,
                                    count(*) FILTER (WHERE status='pending')::int AS pending,
                                    count(*) FILTER (WHERE status='waitlist')::int AS waitlist FROM live_registrations WHERE stream_id = $1`, [s.id]);
    const materials = await many('SELECT * FROM webinar_materials WHERE stream_id = $1 ORDER BY position, created_at', [s.id]);
    const cohosts = s.cohosts?.length ? await many('SELECT id, display_name, email, avatar_path FROM users WHERE id = ANY($1::uuid[])', [s.cohosts]) : [];
    return {
      webinar: {
        id: s.id, shortId: s.short_id, slug: s.slug || s.short_id, title: s.title, description: s.description || '',
        visibility: s.visibility, scheduledAt: s.scheduled_at, status: s.status, startedAt: s.started_at, endedAt: s.ended_at,
        coverUrl: mediaUrl(s.cover_path), agenda: s.agenda || [], speakers: s.speakers || [],
        registration: !!s.registration, registrationLimit: s.registration_limit, registrationNote: s.registration_note || '',
        regFields: s.reg_fields || [], regModeration: !!s.reg_moderation, regWaitlist: !!s.reg_waitlist,
        regExternal: !!s.reg_external, regClosesAt: s.reg_closes_at, certEnabled: !!s.cert_enabled, certMinPercent: s.cert_min_percent,
        cta: s.cta || null, sourceVideoId: s.source_video_id, recordingVideoId: s.recording_video_id,
        followupSentAt: s.followup_sent_at, record: !!s.record, chatEnabled: !!s.chat_enabled,
        counts: seats, materials: materials.map((m) => ({ id: m.id, name: m.name, url: browserPath(materialUrl(s, m)), bytes: Number(m.bytes) || 0, before: m.before })),
        cohosts: cohosts.map((u) => ({ id: u.id, displayName: u.display_name, email: u.email })),
        ownerId: s.owner_id, canEditCohosts: s.owner_id === req.user.id || isStaff(req.user),
        landingUrl: `${config.baseUrl}/webinar/${s.slug || s.short_id}`,
      },
    };
  });

  app.patch('/studio/webinars/:id', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const b = req.body || {};
    const sets = []; const params = [s.id];
    const add = (c, v) => { params.push(v); sets.push(`${c} = $${params.length}`); };
    if (b.title !== undefined) { const t = String(b.title).trim().slice(0, 150); if (!t) throw badRequest('Пустое название'); add('title', t); }
    if (b.description !== undefined) add('description', String(b.description).slice(0, 5000));
    if (b.visibility !== undefined && ['public', 'internal', 'unlisted', 'private'].includes(b.visibility)) add('visibility', b.visibility);
    if (b.scheduledAt !== undefined) { const d = b.scheduledAt ? new Date(b.scheduledAt) : null; add('scheduled_at', d && !Number.isNaN(d.getTime()) ? d : null); }
    if (b.slug !== undefined) {
      const slug = slugify(String(b.slug)).slice(0, 60);
      if (!slug) throw badRequest('Некорректный адрес страницы');
      if (await one('SELECT 1 FROM live_streams WHERE slug = $1 AND id <> $2', [slug, s.id])) throw badRequest('Такой адрес уже занят');
      add('slug', slug);
    }
    if (b.webinar !== undefined) add('webinar', !!b.webinar);
    if (b.agenda !== undefined) add('agenda', JSON.stringify(normalizeAgenda(b.agenda)));
    if (b.speakers !== undefined) add('speakers', JSON.stringify(normalizeSpeakers(b.speakers)));
    if (b.regFields !== undefined) add('reg_fields', JSON.stringify(normalizeFields(b.regFields)));
    if (b.registration !== undefined) add('registration', !!b.registration);
    if (b.registrationLimit !== undefined) add('registration_limit', b.registrationLimit ? Math.max(1, Math.min(100000, Number(b.registrationLimit))) : null);
    if (b.registrationNote !== undefined) add('registration_note', String(b.registrationNote).slice(0, 1000));
    if (b.regModeration !== undefined) add('reg_moderation', !!b.regModeration);
    if (b.regWaitlist !== undefined) add('reg_waitlist', !!b.regWaitlist);
    if (b.regExternal !== undefined) add('reg_external', !!b.regExternal);
    if (b.regClosesAt !== undefined) { const d = b.regClosesAt ? new Date(b.regClosesAt) : null; add('reg_closes_at', d && !Number.isNaN(d.getTime()) ? d : null); }
    if (b.certEnabled !== undefined) add('cert_enabled', !!b.certEnabled);
    if (b.certMinPercent !== undefined) add('cert_min_percent', Math.max(1, Math.min(100, Number(b.certMinPercent) || 60)));
    if (b.record !== undefined) add('record', !!b.record);
    if (b.chatEnabled !== undefined) add('chat_enabled', !!b.chatEnabled);
    if (b.cta !== undefined) {
      const c = b.cta && typeof b.cta === 'object' ? {
        label: String(b.cta.label || '').trim().slice(0, 60),
        url: String(b.cta.url || '').trim().slice(0, 500),
        text: String(b.cta.text || '').trim().slice(0, 300),
      } : null;
      add('cta', c && c.label && /^https?:\/\//i.test(c.url) ? JSON.stringify(c) : null);
    }
    if (b.sourceVideoId !== undefined) {
      if (b.sourceVideoId) {
        const v = await one('SELECT id, owner_id, duration FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [String(b.sourceVideoId)]);
        if (!v) throw badRequest('Видео для автовебинара не найдено');
        // Показать как эфир можно только своё видео: иначе чужое закрытое видео ушло бы всей аудитории вебинара
        if (!canEditVideo(v, req.user)) throw forbidden('Для автовебинара можно выбрать только своё видео');
        add('source_video_id', v.id);
      } else add('source_video_id', null);
    }
    let addedCohosts = []; let removedCohosts = [];
    if (b.cohosts !== undefined && !(s.owner_id === req.user.id || isStaff(req.user))) throw forbidden('Соведущих назначает организатор вебинара');
    if (b.cohosts !== undefined) {
      const ids = (Array.isArray(b.cohosts) ? b.cohosts : []).map(String).filter((x) => /^[0-9a-f-]{36}$/i.test(x)).slice(0, 20);
      const users = ids.length ? await many('SELECT id FROM users WHERE id = ANY($1::uuid[]) AND status = $2', [ids, 'active']) : [];
      const next = users.map((u) => u.id).filter((id) => id !== s.owner_id);
      const prev = Array.isArray(s.cohosts) ? s.cohosts : [];
      addedCohosts = next.filter((id) => !prev.includes(id));
      removedCohosts = prev.filter((id) => !next.includes(id));
      add('cohosts', next);
    }
    if (sets.length) { sets.push('updated_at = now()'); await query(`UPDATE live_streams SET ${sets.join(', ')} WHERE id = $1`, params); }
    await audit(req, 'webinar.update', { targetType: 'live', targetId: s.id, details: Object.keys(b) });
    // Соведущие узнают о назначении сразу и могут отказаться от роли (POST …/cohost/leave)
    if (addedCohosts.length || removedCohosts.length) {
      await audit(req, 'webinar.cohosts', { targetType: 'live', targetId: s.id, details: { added: addedCohosts, removed: removedCohosts } });
      for (const uid of addedCohosts) {
        await notify(uid, { type: 'webinar_cohost', title: `Вы — соведущий вебинара «${s.title}»`, body: `${req.user.display_name || 'Организатор'} добавил вас соведущим. Если это ошибка, откажитесь от роли на странице управления.`, link: `/studio/webinars/${s.id}`, actorId: req.user.id, data: { streamId: s.id } });
      }
    }
    const upd = await loadWebinar(s.id);
    return { ok: true, slug: upd.slug, webinar: { id: upd.id, slug: upd.slug, title: upd.title } };
  });

  // Соведущий может сам отказаться от роли
  app.post('/studio/webinars/:id/cohost/leave', { preHandler: app.requireActive }, async (req) => {
    const s = await loadWebinar(req.params.id);
    if (!s || !s.webinar) throw notFound('Вебинар не найден');
    if (!(Array.isArray(s.cohosts) && s.cohosts.includes(req.user.id))) throw badRequest('Вы не соведущий этого вебинара');
    await query('UPDATE live_streams SET cohosts = array_remove(cohosts, $2::uuid), updated_at = now() WHERE id = $1', [s.id, req.user.id]);
    await audit(req, 'webinar.cohost_leave', { targetType: 'live', targetId: s.id });
    await notify(s.owner_id, { type: 'webinar_cohost', title: `Соведущий отказался: ${s.title}`, body: `${req.user.display_name} больше не соведущий вебинара`, link: `/studio/webinars/${s.id}`, actorId: req.user.id, data: { streamId: s.id } });
    return { ok: true };
  });

  // --- Заявки ---------------------------------------------------------------------------------
  app.get('/studio/webinars/:id/registrations', { preHandler: app.requireActive }, async (req, reply) => {
    const s = await manage(req);
    const status = ['pending', 'approved', 'waitlist', 'declined', 'cancelled'].includes(req.query.status) ? req.query.status : null;
    const rows = await many(
      `SELECT r.*, COALESCE(a.seconds, 0) AS attend_seconds FROM live_registrations r
       LEFT JOIN live_attendance a ON a.stream_id = r.stream_id AND a.user_id = r.user_id
       WHERE r.stream_id = $1 ${status ? 'AND r.status = $2' : ''} ORDER BY r.created_at DESC LIMIT 2000`,
      status ? [s.id, status] : [s.id]);
    const items = rows.map(regOut);
    if (req.query.format === 'csv') {
      const fields = s.reg_fields || [];
      const csv = toCsv(items, [
        { title: 'Имя', value: 'name' }, { title: 'Почта', value: 'email' }, { title: 'Организация', value: 'company' },
        { title: 'Состояние', value: (r) => ({ approved: 'участник', pending: 'ждёт подтверждения', waitlist: 'лист ожидания', declined: 'отклонена', cancelled: 'отменена' })[r.status] || r.status },
        { title: 'Записался', value: (r) => new Date(r.createdAt).toLocaleString('ru-RU') },
        { title: 'Был в эфире, мин', value: (r) => Math.round(r.seconds / 60) },
        ...fields.map((f) => ({ title: f.label, value: (r) => (typeof r.answers[f.id] === 'boolean' ? (r.answers[f.id] ? 'да' : 'нет') : r.answers[f.id] || '') })),
      ]);
      return sendCsv(reply, `webinar-${s.slug || s.short_id}.csv`, csv);
    }
    return { registrations: items, fields: s.reg_fields || [] };
  });

  app.post('/studio/webinars/:id/registrations/:rid/decision', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const r = await one('SELECT * FROM live_registrations WHERE id = $1 AND stream_id = $2', [Number(req.params.rid), s.id]);
    if (!r) throw notFound('Заявка не найдена');
    const decision = req.body?.decision;
    if (!['approve', 'decline'].includes(decision)) throw badRequest('Решение: approve или decline');
    const status = decision === 'approve' ? 'approved' : 'declined';
    await query('UPDATE live_registrations SET status = $2, decided_at = now() WHERE id = $1', [r.id, status]);
    if (status === 'approved') {
      if (r.user_id) {
        await query('INSERT INTO live_reminders(stream_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [s.id, r.user_id]);
        await notify(r.user_id, { type: 'live_reminder', title: `Заявка одобрена: ${s.title}`, body: whenLabel(s.scheduled_at), link: `/webinar/${s.slug || s.short_id}`, data: { streamId: s.id } });
      }
      if (await emailEnabled()) {
        await templates.webinarConfirm({ to: r.email, name: r.name, title: s.title, when: whenLabel(s.scheduled_at), url: r.token ? `${config.baseUrl}/webinar/${s.slug || s.short_id}?t=${r.token}` : `${config.baseUrl}/webinar/${s.slug || s.short_id}`, note: s.registration_note || '' }).catch(() => {});
      }
    } else if (r.user_id) {
      await notify(r.user_id, { type: 'live_reminder', title: `Заявка отклонена: ${s.title}`, body: String(req.body?.reason || '').slice(0, 200), link: `/webinar/${s.slug || s.short_id}`, data: { streamId: s.id } });
    }
    await audit(req, 'webinar.registration', { targetType: 'live', targetId: s.id, details: { decision, email: r.email } });
    return { ok: true, status };
  });

  // --- Материалы и обложка ---------------------------------------------------------------------
  app.post('/studio/webinars/:id/materials', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const part = await req.file({ limits: { fileSize: (req.settings['upload.attachment_max_mb'] || 200) * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл');
    // Тот же белый список, что и у материалов к видео: документы, презентации, таблицы, архивы, картинки
    const ext = extOf(part.filename || '');
    if (!ATTACH_ALLOWED.has(ext)) { part.file.resume(); throw badRequest(`Формат .${ext || '?'} не поддерживается для материалов`); }
    const dir = path.join(storage.root, 'webinars', s.id);
    await ensureDir(dir);
    const name = safeFilename(part.filename || 'material');
    const file = path.join(dir, `${Date.now().toString(36)}-${name}`);
    await pipeline(part.file, fs.createWriteStream(file));
    if (part.file.truncated) { await removeFile(file); throw badRequest(`Файл больше ${req.settings['upload.attachment_max_mb'] || 200} МБ`); }
    const st = await fs.promises.stat(file);
    const before = String(part.fields?.before?.value ?? 'true') !== 'false';
    const row = await one('INSERT INTO webinar_materials(stream_id, name, path, bytes, before) VALUES ($1,$2,$3,$4,$5) RETURNING *',
      [s.id, name, storage.rel(file), st.size, before]);
    return { material: { id: row.id, name: row.name, url: browserPath(materialUrl(s, row)), bytes: Number(row.bytes), before: row.before } };
  });

  app.delete('/studio/webinars/:id/materials/:mid', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const m = await one('SELECT * FROM webinar_materials WHERE id = $1 AND stream_id = $2', [req.params.mid, s.id]);
    if (!m) throw notFound('Материал не найден');
    await removeFile(storage.abs(m.path)).catch(() => {});
    await query('DELETE FROM webinar_materials WHERE id = $1', [m.id]);
    return { ok: true };
  });

  app.post('/studio/webinars/:id/cover', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const part = await req.file({ limits: { fileSize: 8 * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл');
    const ext = extOf(part.filename);
    if (!['png', 'jpg', 'jpeg', 'webp'].includes(ext)) throw badRequest('Поддерживаются PNG, JPG и WebP');
    const dir = path.join(storage.root, 'webinars', s.id);
    await ensureDir(dir);
    const file = path.join(dir, `cover-${Date.now().toString(36)}.${ext}`);
    await pipeline(part.file, fs.createWriteStream(file));
    const rel = storage.rel(file);
    await query('UPDATE live_streams SET cover_path = $2, updated_at = now() WHERE id = $1', [s.id, rel]);
    return { coverUrl: mediaUrl(rel) };
  });

  // --- Приглашения, послесловие, сертификаты -----------------------------------------------------
  app.post('/studio/webinars/:id/invite', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const raw = String(req.body?.emails || '').split(/[\s,;]+/).map((x) => x.trim().toLowerCase()).filter(Boolean).slice(0, 200);
    const emails = [...new Set(raw.filter((e) => EMAIL_RE.test(e)))];
    if (!emails.length) throw badRequest('Укажите хотя бы один корректный адрес');
    if (!(await emailEnabled())) throw badRequest('Почта не настроена — приглашения отправить нельзя');
    const url = `${config.baseUrl}/webinar/${s.slug || s.short_id}`;
    for (const to of emails) {
      await templates.webinarInvite({ to, title: s.title, when: whenLabel(s.scheduled_at), url, inviterName: req.user.display_name, note: String(req.body?.note || '').slice(0, 500) }).catch(() => {});
    }
    await audit(req, 'webinar.invite', { targetType: 'live', targetId: s.id, details: { count: emails.length } });
    return { ok: true, sent: emails.length };
  });

  app.post('/studio/webinars/:id/followup', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    if (s.status !== 'ended') throw badRequest('Письмо-послесловие отправляется после завершения вебинара');
    const certs = s.cert_enabled ? await issueWebinarCertificates(s) : { issued: 0 };
    const r = await sendWebinarFollowup(s, { force: true });
    await audit(req, 'webinar.followup', { targetType: 'live', targetId: s.id, details: r });
    return { ok: true, ...r, certificates: certs.issued };
  });

  app.post('/studio/webinars/:id/certificates', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    if (!s.cert_enabled) throw badRequest('Сертификаты у этого вебинара выключены');
    const r = await issueWebinarCertificates(s);
    return { ok: true, ...r };
  });

  // --- Отчёт -------------------------------------------------------------------------------------
  app.get('/studio/webinars/:id/report', { preHandler: app.requireActive }, async (req) => {
    const s = await manage(req);
    const rows = await many(
      `SELECT r.*, COALESCE(a.seconds, 0) AS attend_seconds FROM live_registrations r
       LEFT JOIN live_attendance a ON a.stream_id = r.stream_id AND a.user_id = r.user_id
       WHERE r.stream_id = $1 ORDER BY GREATEST(r.seconds, COALESCE(a.seconds,0)) DESC LIMIT 3000`, [s.id]);
    const items = rows.map(regOut);
    const durationSec = s.started_at && s.ended_at ? Math.max(0, (new Date(s.ended_at) - new Date(s.started_at)) / 1000) : 0;
    const approved = items.filter((r) => r.status === 'approved');
    // «Был на вебинаре» — с доли эфира из настроек портала (webinar.attendance_min_percent)
    const need = attendThreshold(req.settings, durationSec);
    const attended = approved.filter((r) => wasPresent(r.seconds, need));
    const stayed = attended.filter((r) => r.seconds >= certThreshold(s, durationSec) && durationSec > 0);
    const presence = await many('SELECT minute, people FROM webinar_presence WHERE stream_id = $1 ORDER BY minute', [s.id]);
    const avg = attended.length ? Math.round(attended.reduce((n, r) => n + r.seconds, 0) / attended.length) : 0;
    return {
      webinar: { id: s.id, title: s.title, slug: s.slug || s.short_id, startedAt: s.started_at, endedAt: s.ended_at, durationSec, status: s.status },
      funnel: {
        registered: items.filter((r) => r.status !== 'cancelled' && r.status !== 'declined').length,
        approved: approved.length, attended: attended.length, stayed: stayed.length,
        pending: items.filter((r) => r.status === 'pending').length,
        waitlist: items.filter((r) => r.status === 'waitlist').length,
        external: approved.filter((r) => r.external).length,
        avgSeconds: avg, peak: presence.reduce((m, p) => Math.max(m, p.people), 0),
      },
      presence: presence.map((p) => ({ minute: p.minute, people: p.people })),
      fields: s.reg_fields || [],
      participants: items,
      certificates: { enabled: !!s.cert_enabled, minPercent: s.cert_min_percent, issued: items.filter((r) => r.certId).length },
      attendance: { minPercent: Number(req.settings['webinar.attendance_min_percent']) || 50, minSeconds: need },
    };
  });
}
