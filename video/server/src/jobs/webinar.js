// Вебинары: письма-напоминания (за сутки, за час, за 15 минут), письмо-послесловие с записью
// и материалами, сертификаты участникам, которые пробыли в эфире дольше порога.
import { one, many, query } from '../db.js';
import { config } from '../config.js';
import { loadSettings } from '../lib/settings.js';
import { templates, emailEnabled } from '../lib/mailer.js';
import { notify } from '../lib/notify.js';

const plural = (n, one, few, many) => {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
  return many;
};
function leadLabel(min) {
  if (min === 1440) return 'завтра';
  if (min % 1440 === 0) return `через ${min / 1440} ${plural(min / 1440, 'день', 'дня', 'дней')}`;
  if (min === 60) return 'через час';
  if (min % 60 === 0) return `через ${min / 60} ${plural(min / 60, 'час', 'часа', 'часов')}`;
  return `через ${min} ${plural(min, 'минуту', 'минуты', 'минут')}`;
}

/** Расписание напоминаний из настройки webinars.reminders (минуты до начала, от 5 минут до недели). */
export function reminderLeads(s) {
  const raw = Array.isArray(s?.['webinars.reminders']) ? s['webinars.reminders'] : String(s?.['webinars.reminders'] ?? '').split(/[\s,;]+/);
  const mins = [...new Set(raw.map((x) => Math.round(Number(x))).filter((x) => Number.isFinite(x) && x >= 5 && x <= 10080))]
    .sort((a, b) => b - a).slice(0, 6);
  return mins.map((m) => ({ key: String(m), minutes: m, label: leadLabel(m) }));
}

/** Сколько секунд нужно пробыть на вебинаре, чтобы участие засчитали (webinar.attendance_min_percent). */
export function attendThreshold(s, durationSec) {
  const pct = Math.max(1, Math.min(100, Number(s?.['webinar.attendance_min_percent']) || 50));
  return durationSec > 0 ? Math.max(1, Math.round(durationSec * pct / 100)) : 60;
}
export const wasPresent = (seconds, need) => seconds > 0 && seconds >= need;

const whenLabel = (d) => (d ? new Date(d).toLocaleString('ru-RU', { dateStyle: 'long', timeStyle: 'short' }) : '');
const pageUrl = (s) => `${config.baseUrl}/webinar/${s.slug || s.short_id}`;
const joinUrl = (s, r) => (r.token ? `${pageUrl(s)}?t=${r.token}` : pageUrl(s));

/** Напоминания участникам о ближайших вебинарах. */
export async function sendWebinarReminders() {
  const s = await loadSettings();
  if (!s['webinars.enabled']) return { sent: 0 };
  let sent = 0;
  const leads = reminderLeads(s);
  if (!leads.length) return { sent: 0 };
  const mailOk = await emailEnabled();
  const horizon = leads[0].minutes + 60;
  const streams = await many(
    `SELECT * FROM live_streams WHERE webinar AND status <> 'ended' AND scheduled_at IS NOT NULL
       AND scheduled_at BETWEEN now() - interval '5 minutes' AND now() + make_interval(mins => $1)`, [horizon]);
  for (const w of streams) {
    const minutesLeft = (new Date(w.scheduled_at).getTime() - Date.now()) / 60000;
    for (const lead of leads) {
      if (minutesLeft > lead.minutes || minutesLeft < lead.minutes - 20) continue;   // окно 20 минут
      const regs = await many(
        `SELECT * FROM live_registrations WHERE stream_id = $1 AND status = 'approved' AND NOT (reminded ? $2) LIMIT 500`,
        [w.id, lead.key]);
      for (const r of regs) {
        if (mailOk && r.email) {
          await templates.webinarReminder({ to: r.email, name: r.name || 'коллега', title: w.title, when: whenLabel(w.scheduled_at), url: joinUrl(w, r), leadLabel: lead.label }).catch(() => {});
        }
        if (r.user_id) {
          await notify(r.user_id, { type: 'live_reminder', title: `Вебинар ${lead.label}: ${w.title}`, body: whenLabel(w.scheduled_at), link: `/webinar/${w.slug || w.short_id}`, data: { streamId: w.id } });
        }
        await query(`UPDATE live_registrations SET reminded = reminded || jsonb_build_object($2::text, to_jsonb(now())) WHERE id = $1`, [r.id, lead.key]);
        sent += 1;
      }
    }
  }
  return { sent };
}

/** Порог присутствия в секундах для сертификата. */
export function certThreshold(w, durationSec) {
  const pct = Math.max(1, Math.min(100, Number(w.cert_min_percent) || 60));
  return Math.round((durationSec || 0) * pct / 100);
}

/** Выдать сертификаты участникам вебинара (после эфира). */
export async function issueWebinarCertificates(w) {
  const s = await loadSettings();
  if (!w.cert_enabled || !s['certificates.enabled']) return { issued: 0 };
  const durationSec = w.started_at && w.ended_at ? (new Date(w.ended_at) - new Date(w.started_at)) / 1000 : 0;
  const need = certThreshold(w, durationSec);
  const regs = await many(
    `SELECT r.*, COALESCE(a.seconds, 0) AS attend_seconds FROM live_registrations r
     LEFT JOIN live_attendance a ON a.stream_id = r.stream_id AND a.user_id = r.user_id
     WHERE r.stream_id = $1 AND r.status = 'approved' AND r.cert_id IS NULL`, [w.id]);
  let issued = 0;
  for (const r of regs) {
    const seconds = Math.max(Number(r.seconds) || 0, Number(r.attend_seconds) || 0);
    if (!r.user_id || seconds < need || seconds <= 0) continue;
    const seq = await one("SELECT nextval('certificates_number_seq')::int AS n");
    const number = `CV-${new Date().getFullYear()}-${String(seq.n).padStart(5, '0')}`;
    const details = {
      kind: 'webinar', webinar: w.title, minutes: Math.round(seconds / 60),
      requiredMinutes: Math.round(need / 60), heldAt: w.started_at,
      issuerName: s['certificates.issuer_name'] || s['site.name'], issuerTitle: s['certificates.issuer_title'],
      text: s['certificates.text'], siteName: s['site.name'],
    };
    const c = await one(
      `INSERT INTO certificates(number, user_id, title, details) VALUES ($1,$2,$3,$4::jsonb) RETURNING *`,
      [number, r.user_id, `Участие в вебинаре «${w.title}»`, JSON.stringify(details)]);
    await query('UPDATE live_registrations SET cert_id = $2 WHERE id = $1', [r.id, c.id]);
    await notify(r.user_id, { type: 'certificate', title: 'Сертификат участника вебинара', body: `${w.title} — № ${number}`, link: `/cert/${c.id}`, data: { certificateId: c.id } });
    issued += 1;
  }
  return { issued };
}

/** Письмо-послесловие: ссылка на запись, материалы, сертификат. */
/**
 * force — ручная повторная отправка организатором: всем участникам, даже если письмо уже уходило.
 * waited — запись так и не появилась за отведённое время: отправляем без неё.
 */
export async function sendWebinarFollowup(w, { force = false, waited = false } = {}) {
  const s = await loadSettings();
  if (!w.webinar) return { sent: 0, skipped: 'not-webinar' };
  if (w.followup_sent_at && !force) return { sent: 0, skipped: 'already' };
  const rec = w.recording_video_id ? await one('SELECT short_id FROM videos WHERE id = $1', [w.recording_video_id]) : null;
  if (!rec && !force && !waited && w.record) return { sent: 0, skipped: 'no-recording' };   // ждём обработку записи
  const materials = await many('SELECT * FROM webinar_materials WHERE stream_id = $1 ORDER BY position', [w.id]);
  // Письмо получает каждый участник ровно один раз: отметка ставится по каждому адресату,
  // поэтому сбой посреди рассылки не приводит к повторным письмам при следующем проходе.
  const regs = await many(
    `SELECT r.*, COALESCE(a.seconds, 0) AS attend_seconds FROM live_registrations r
     LEFT JOIN live_attendance a ON a.stream_id = r.stream_id AND a.user_id = r.user_id
     WHERE r.stream_id = $1 AND r.status = 'approved' AND ($2 OR r.followup_at IS NULL)`, [w.id, !!force]);
  const durationSec = w.started_at && w.ended_at ? (new Date(w.ended_at) - new Date(w.started_at)) / 1000 : 0;
  const need = attendThreshold(s, durationSec);
  const mailOk = await emailEnabled();
  let sent = 0;
  for (const r of regs) {
    const attended = wasPresent(Math.max(Number(r.seconds) || 0, Number(r.attend_seconds) || 0), need);
    if (mailOk && r.email) {
      await templates.webinarFollowup({
        to: r.email, name: r.name || 'коллега', title: w.title,
        url: `${config.baseUrl}/webinar/${w.slug || w.short_id}`,
        recordingUrl: rec ? `${config.baseUrl}/watch/${rec.short_id}` : null,
        // Материалы — по адресу с проверкой регистрации; внешнему участнику — с его личным токеном
        materials: materials.map((m) => ({ name: m.name, url: `${config.baseUrl}/api/webinars/${encodeURIComponent(w.slug || w.short_id)}/materials/${m.id}${r.token ? `?t=${r.token}` : ''}` })),
        certUrl: r.cert_id ? `${config.baseUrl}/cert/${r.cert_id}` : null,
        attended,
      }).catch(() => {});
    }
    if (r.user_id && rec) {
      await notify(r.user_id, { type: 'video_ready', title: `Запись вебинара готова: ${w.title}`, body: 'Материалы и сертификат — на странице вебинара', link: `/watch/${rec.short_id}`, data: { streamId: w.id } });
    }
    await query('UPDATE live_registrations SET followup_at = now() WHERE id = $1', [r.id]);
    sent += 1;
  }
  await query('UPDATE live_streams SET followup_sent_at = now() WHERE id = $1', [w.id]);
  return { sent, recording: rec?.short_id || null };
}

/** Обслуживание: завершившиеся вебинары — сертификаты и послесловие. */
export async function runWebinarMaintenance() {
  const s = await loadSettings();
  if (!s['webinars.enabled']) return { skipped: 'disabled' };
  const out = { reminders: await sendWebinarReminders(), certificates: 0, followups: 0 };
  const done = await many(
    `SELECT * FROM live_streams WHERE webinar AND status = 'ended' AND ended_at > now() - interval '7 days' AND followup_sent_at IS NULL`);
  for (const w of done) {
    // Даём записи время обработаться: письмо уходит либо когда запись готова, либо через 2 часа
    const waited = Date.now() - new Date(w.ended_at).getTime() > 2 * 3600 * 1000;
    if (w.cert_enabled) out.certificates += (await issueWebinarCertificates(w)).issued;
    if (s['webinars.followup'] === false) {
      // Письма выключены: вебинар считается обработанным, иначе включение настройки
      // разослало бы запоздалые письма по всем вебинарам последней недели.
      // Сертификаты к этому моменту уже выданы (сразу выше).
      await query('UPDATE live_streams SET followup_sent_at = now() WHERE id = $1', [w.id]);
      continue;
    }
    if (w.recording_video_id || waited || !w.record) {
      const r = await sendWebinarFollowup(w, { waited });
      if (r.sent) out.followups += r.sent;
    }
  }
  return out;
}
