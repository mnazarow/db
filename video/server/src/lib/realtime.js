// Реальное время: WebSocket-хаб (уведомления, прогресс обработки, live-чат) поверх шины событий PostgreSQL.
import { bus, publish } from '../db.js';

/** Подключения: userId → Set<socket>; каналы (например, чат трансляции): channel → Set<socket>. */
const byUser = new Map();
const byChannel = new Map();
const all = new Set();

export function addSocket(socket, user) {
  all.add(socket);
  socket.cvUser = user || null;
  socket.cvChannels = new Set();
  if (user) {
    if (!byUser.has(user.id)) byUser.set(user.id, new Set());
    byUser.get(user.id).add(socket);
  }
  socket.on('close', () => removeSocket(socket));
}

export function removeSocket(socket) {
  all.delete(socket);
  if (socket.cvUser) {
    const set = byUser.get(socket.cvUser.id);
    if (set) { set.delete(socket); if (!set.size) byUser.delete(socket.cvUser.id); }
  }
  for (const ch of socket.cvChannels || []) leaveChannel(socket, ch);
}

export function joinChannel(socket, channel) {
  if (!byChannel.has(channel)) byChannel.set(channel, new Set());
  byChannel.get(channel).add(socket);
  socket.cvChannels.add(channel);
}

export function leaveChannel(socket, channel) {
  const set = byChannel.get(channel);
  if (set) { set.delete(socket); if (!set.size) byChannel.delete(channel); }
  socket.cvChannels?.delete(channel);
}

export function channelSize(channel) {
  return byChannel.get(channel)?.size || 0;
}

export function channelUsers(channel) {
  const ids = new Set();
  for (const s of byChannel.get(channel) || []) if (s.cvUser) ids.add(s.cvUser.id);
  return ids.size;
}

function send(socket, msg) {
  try { if (socket.readyState === 1) socket.send(typeof msg === 'string' ? msg : JSON.stringify(msg)); } catch { /* ignore */ }
}

/** Локальная рассылка (в этом процессе). */
export function localToUser(userId, msg) {
  const set = byUser.get(userId);
  if (!set) return;
  const text = JSON.stringify(msg);
  for (const s of set) send(s, text);
}

export function localToChannel(channel, msg) {
  const set = byChannel.get(channel);
  if (!set) return;
  const text = JSON.stringify(msg);
  for (const s of set) send(s, text);
}

export function localBroadcast(msg) {
  const text = JSON.stringify(msg);
  for (const s of all) send(s, text);
}

/** Межпроцессная рассылка через NOTIFY: воркер → все API-процессы → сокеты. */
export async function toUser(userId, msg) {
  await publish({ type: 'ws.user', userId, msg });
}
export async function toChannel(channel, msg) {
  await publish({ type: 'ws.channel', channel, msg });
}
export async function broadcast(msg) {
  await publish({ type: 'ws.all', msg });
}

bus.on('ws.user', (e) => localToUser(e.userId, e.msg));
bus.on('ws.channel', (e) => localToChannel(e.channel, e.msg));
bus.on('ws.all', (e) => localBroadcast(e.msg));

export function stats() {
  return { sockets: all.size, users: byUser.size, channels: byChannel.size };
}
