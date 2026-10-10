// WebSocket: уведомления, прогресс обработки, чат трансляций, служебные события для админки.
import { bus, one } from '../db.js';
import { addSocket, joinChannel, leaveChannel, localToUser, localToChannel, localBroadcast } from '../lib/realtime.js';
import { loadStream } from './live.js';
import { canViewLive, canViewVideo } from '../lib/access.js';

let wired = false;
function wireBus() {
  if (wired) return; wired = true;
  for (const t of ['video.progress', 'video.ready', 'video.failed', 'video.subtitles', 'video.published', 'video.ai']) {
    bus.on(t, (e) => { if (e.ownerId) localToUser(e.ownerId, { ...e }); localToChannel('admin', { ...e }); });
  }
  // Приватные эфиры не анонсируем всем: рассылаем только владельцу и админам
  for (const t of ['live.started', 'live.ended']) {
    bus.on(t, (e) => {
      if (e.visibility && !['public', 'internal'].includes(e.visibility)) {
        if (e.ownerId) localToUser(e.ownerId, { ...e });
        localToChannel('admin', { ...e });
        return;
      }
      localBroadcast({ ...e });
    });
  }
  bus.on('job.enqueued', (e) => localToChannel('admin', { ...e }));
  bus.on('settings.changed', (e) => localBroadcast({ type: 'settings.changed' }));
}

export default async function wsRoutes(app) {
  wireBus();
  app.get('/ws', { websocket: true }, (socket, req) => {
    addSocket(socket, req.user);
    if (req.user && ['admin', 'moderator'].includes(req.user.role)) joinChannel(socket, 'admin');
    socket.send(JSON.stringify({ type: 'hello', userId: req.user?.id || null }));
    socket.on('message', async (raw) => {
      let msg;
      try { msg = JSON.parse(String(raw)); } catch { return; }
      if (!msg || typeof msg !== 'object') return;
      if (msg.type === 'ping') { socket.send(JSON.stringify({ type: 'pong', t: Date.now() })); return; }
      if (msg.type === 'join' && typeof msg.channel === 'string') {
        const ch = msg.channel.slice(0, 80);
        if (ch.startsWith('live:')) {
          const s = await loadStream(ch.slice(5)).catch(() => null);
          if (!s || !canViewLive(s, req.user)) return;
          joinChannel(socket, `live:${s.id}`);
          socket.send(JSON.stringify({ type: 'joined', channel: `live:${s.id}` }));
          return;
        }
        // Премьера видео: чат и события показа (1.6)
        if (ch.startsWith('premiere:')) {
          const v = await one(
            'SELECT * FROM videos WHERE (id = cv_uuid($1) OR short_id = $1) AND deleted_at IS NULL', [ch.slice(9)]).catch(() => null);
          if (!v || !v.premiere || !(await canViewVideo(v, req.user))) return;
          joinChannel(socket, `premiere:${v.id}`);
          socket.send(JSON.stringify({ type: 'joined', channel: `premiere:${v.id}` }));
          return;
        }
        // Комната совместного просмотра (1.6)
        if (ch.startsWith('party:')) {
          const p = await one('SELECT * FROM watch_parties WHERE id = cv_uuid($1) OR code = $1', [ch.slice(6)]).catch(() => null);
          if (!p || !req.user) return;
          const v = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [p.video_id]).catch(() => null);
          if (!v || !(await canViewVideo(v, req.user))) return;
          joinChannel(socket, `party:${p.id}`);
          socket.send(JSON.stringify({ type: 'joined', channel: `party:${p.id}` }));
          return;
        }
        return;
      }
      if (msg.type === 'leave' && typeof msg.channel === 'string') { leaveChannel(socket, msg.channel); }
    });
    // Пинг для поддержания соединения
    const iv = setInterval(() => { try { if (socket.readyState === 1) socket.ping(); } catch { /* ignore */ } }, 25000);
    socket.on('close', () => clearInterval(iv));
  });
}
