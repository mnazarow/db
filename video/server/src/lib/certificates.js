// Сертификаты о прохождении: выдаются при выполнении назначения с флагом «сертификат».
import { one, many, query } from '../db.js';
import { loadSettings } from './settings.js';
import { notify } from './notify.js';
import { assignmentVideos } from './assignments.js';
import { emitEvent, eventUser } from './events.js';

export function certificateOut(c, { user = null, extra = {} } = {}) {
  return {
    id: c.id, number: c.number, title: c.title, issuedAt: c.issued_at, revokedAt: c.revoked_at, details: c.details || {},
    assignmentId: c.assignment_id, videoId: c.video_id, playlistId: c.playlist_id, videoShortId: c.video_short_id || undefined,
    user: user || (c.user_name ? { displayName: c.user_name, email: c.user_email } : undefined),
    url: `/cert/${c.id}`, ...extra,
  };
}

/** Выдать сертификат, если назначение выполнено полностью (все видео) и сертификат ещё не выдан. */
export async function maybeIssueCertificate(a, userId) {
  if (!a?.certificate) return null;
  const s = await loadSettings();
  if (!s['certificates.enabled']) return null;
  const existing = await one('SELECT * FROM certificates WHERE assignment_id = $1 AND user_id = $2', [a.id, userId]);
  if (existing) return existing;
  const videos = await assignmentVideos(a);
  if (!videos.length) return null;
  const progress = await many('SELECT * FROM assignment_progress WHERE assignment_id = $1 AND user_id = $2', [a.id, userId]);
  const done = videos.every((v) => progress.some((p) => p.video_id === v.id && p.completed_at));
  if (!done) return null;
  const quiz = await one(`SELECT max(percent)::int AS best FROM quiz_attempts WHERE user_id = $1 AND video_id = ANY($2::uuid[]) AND passed`, [userId, videos.map((v) => v.id)]);
  const user = await one('SELECT id, display_name, email FROM users WHERE id = $1', [userId]);
  const seq = await one('SELECT nextval(\'certificates_number_seq\')::int AS n');
  const number = `CV-${new Date().getFullYear()}-${String(seq.n).padStart(5, '0')}`;
  const details = {
    percent: Math.round(progress.reduce((n, p) => n + (p.percent || 0), 0) / Math.max(1, videos.length)),
    quizPercent: quiz?.best ?? null, requireQuiz: !!a.require_quiz,
    durationSec: videos.reduce((n, v) => n + (Number(v.duration) || 0), 0), videos: videos.map((v) => ({ id: v.id, title: v.title })),
    issuerName: s['certificates.issuer_name'] || s['site.name'], issuerTitle: s['certificates.issuer_title'], text: s['certificates.text'], siteName: s['site.name'],
    kind: a.kind, note: a.note || '',
  };
  const c = await one(
    `INSERT INTO certificates(number, user_id, assignment_id, video_id, playlist_id, title, details) VALUES ($1,$2,$3,$4,$5,$6,$7::jsonb) ON CONFLICT DO NOTHING RETURNING *`,
    [number, userId, a.id, a.video_id, a.playlist_id, a.title, JSON.stringify(details)],
  );
  if (!c) return existing;
  await notify(userId, { type: 'certificate', title: 'Выдан сертификат о прохождении', body: `${a.title} — № ${number}`, link: `/cert/${c.id}`, data: { certificateId: c.id, assignmentId: a.id } });
  const holder = await one('SELECT id, email, display_name, role, status FROM users WHERE id = $1', [userId]);
  await emitEvent('certificate.issued', { certificate: { id: c.id, number, title: a.title, assignmentId: a.id, issuedAt: c.issued_at, url: `/cert/${c.id}`, details }, user: eventUser(holder) });
  return c;
}
