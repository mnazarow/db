// Комнаты совместного просмотра: премьера видео и «Смотрим вместе».
// Позиция воспроизведения у премьеры считается от назначенного времени (все видят одно и то же),
// у комнаты — от последнего состояния, зафиксированного ведущим (как в Teleparty и Plex Watch Together).
import { one, many, query } from '../db.js';
import { toChannel } from './realtime.js';
import { premiereState } from './access.js';
import { userPublic } from './serialize.js';
import { mediaUrl } from './urls.js';

export const MAX_MESSAGE = 500;

/** Позиция премьеры: сколько секунд прошло с начала показа. */
export function premierePosition(video) {
  if (!video?.premiere || !video.scheduled_at) return null;
  const sec = (Date.now() - new Date(video.scheduled_at).getTime()) / 1000;
  if (sec < 0) return 0;
  return Math.min(sec, Number(video.duration) || sec);
}

/** Позиция комнаты с поправкой на время, прошедшее с последнего состояния. */
export function partyPosition(party) {
  const base = Number(party.position) || 0;
  if (!party.playing || party.ended_at) return base;
  const drift = (Date.now() - new Date(party.state_at).getTime()) / 1000;
  return base + drift * (Number(party.rate) || 1);
}

export function partyOut(party, { members = [], video = null, viewer = null } = {}) {
  return {
    id: party.id,
    code: party.code,
    title: party.title || video?.title || '',
    videoId: party.video_id,
    videoShortId: video?.short_id,
    hostId: party.host_id,
    isHost: !!(viewer && viewer.id === party.host_id),
    canControl: !!(viewer && (party.everyone_controls || viewer.id === party.host_id)),
    everyoneControls: !!party.everyone_controls,
    playing: !!party.playing && !party.ended_at,
    rate: Number(party.rate) || 1,
    position: partyPosition(party),
    endedAt: party.ended_at,
    createdAt: party.created_at,
    members: members.map((m) => ({ ...userPublic(m), joinedAt: m.joined_at })),
  };
}

export function roomMessageOut(m) {
  return {
    id: String(m.id),
    body: m.is_deleted ? '' : m.body,
    deleted: !!m.is_deleted,
    createdAt: m.created_at,
    user: m.display_name ? { id: m.user_id, handle: m.handle, displayName: m.display_name, avatarUrl: mediaUrl(m.avatar_path), role: m.role } : { id: m.user_id },
  };
}

export async function roomHistory(room, limit = 80) {
  const rows = await many(
    `SELECT m.*, u.handle, u.display_name, u.avatar_path, u.role
     FROM room_messages m JOIN users u ON u.id = m.user_id
     WHERE m.room = $1 AND m.is_deleted = false ORDER BY m.id DESC LIMIT $2`, [room, limit]);
  return rows.reverse().map(roomMessageOut);
}

export async function postRoomMessage(room, user, body) {
  const text = String(body || '').trim().slice(0, MAX_MESSAGE);
  if (!text) return null;
  const row = await one(
    `INSERT INTO room_messages(room, user_id, body) VALUES ($1,$2,$3)
     RETURNING id, room, user_id, body, is_deleted, created_at`, [room, user.id, text]);
  const out = roomMessageOut({ ...row, handle: user.handle, display_name: user.display_name, avatar_path: user.avatar_path, role: user.role });
  await toChannel(room, { type: 'room.message', room, message: out });
  return out;
}

/** Сколько всего сообщений в комнате (для показа «чат премьеры» в записи). */
export async function roomCount(room) {
  const r = await one('SELECT count(*)::int AS n FROM room_messages WHERE room = $1 AND is_deleted = false', [room]);
  return r?.n || 0;
}

export async function touchMember(partyId, userId) {
  await query(
    `INSERT INTO watch_party_members(party_id, user_id) VALUES ($1,$2)
     ON CONFLICT (party_id, user_id) DO UPDATE SET last_seen = now()`, [partyId, userId]);
}

export async function partyMembers(partyId) {
  return many(
    `SELECT u.*, m.joined_at FROM watch_party_members m JOIN users u ON u.id = m.user_id
     WHERE m.party_id = $1 AND m.last_seen > now() - interval '2 minutes' ORDER BY m.joined_at`, [partyId]);
}

export { premiereState };
