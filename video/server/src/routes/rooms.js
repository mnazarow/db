// Премьеры и совместный просмотр: чат комнаты, синхронная позиция, участники.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, notFound } from '../lib/util.js';
import { shortId } from '../lib/crypto.js';
import { canViewVideo, isStaff, premiereState, premierePending } from '../lib/access.js';
import { videoCard } from '../lib/serialize.js';
import { notify } from '../lib/notify.js';
import { toChannel } from '../lib/realtime.js';
import { audit } from '../lib/audit.js';
import { config } from '../config.js';
import {
  premierePosition, partyPosition, partyOut, roomHistory, postRoomMessage, roomCount,
  touchMember, partyMembers,
} from '../lib/rooms.js';
import { mediaUrl } from '../lib/urls.js';

async function loadVideo(idOrShort) {
  return one(
    `SELECT v.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar
     FROM videos v JOIN users u ON u.id = v.owner_id
     WHERE (v.id = cv_uuid($1) OR v.short_id = $1) AND v.deleted_at IS NULL`, [String(idOrShort)]);
}

export default async function roomRoutes(app) {
  // --- Премьера ---------------------------------------------------------------------------------
  /** Состояние премьеры: сколько ждать, идёт ли показ, с какой секунды включать плеер. */
  app.get('/videos/:id/premiere', async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user, req.shareTokens))) throw forbidden('Нет доступа к этому видео');
    if (!v.premiere) return { enabled: false };
    const state = premiereState(v);
    const room = `premiere:${v.id}`;
    return {
      enabled: true,
      state,                                   // scheduled | live | done
      at: v.scheduled_at,
      startsInSec: Math.max(0, Math.round((new Date(v.scheduled_at).getTime() - Date.now()) / 1000)),
      position: premierePosition(v),
      chatEnabled: !!v.premiere_chat,
      messages: v.premiere_chat ? await roomHistory(room) : [],
      messageCount: v.premiere_chat ? await roomCount(room) : 0,
      duration: Number(v.duration) || 0,
      serverTime: new Date().toISOString(),
    };
  });

  app.post('/videos/:id/premiere/chat', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user, req.shareTokens))) throw forbidden('Нет доступа к этому видео');
    if (!v.premiere || !v.premiere_chat) throw badRequest('Чат премьеры выключен');
    if (premiereState(v) === 'done') throw badRequest('Премьера уже завершилась — напишите обычный комментарий');
    const msg = await postRoomMessage(`premiere:${v.id}`, req.user, req.body?.body);
    if (!msg) throw badRequest('Пустое сообщение');
    return { message: msg };
  });

  // --- Совместный просмотр ----------------------------------------------------------------------
  /** Создать комнату для видео (как Teleparty: ссылку рассылает организатор). */
  app.post('/videos/:id/party', { preHandler: app.requireActive }, async (req) => {
    const v = await loadVideo(req.params.id);
    if (!v) throw notFound('Видео не найдено');
    if (!(await canViewVideo(v, req.user, req.shareTokens))) throw forbidden('Нет доступа к этому видео');
    if (premierePending(v)) throw badRequest('Премьера ещё не началась');
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    const existing = await one(
      `SELECT * FROM watch_parties WHERE video_id = $1 AND host_id = $2 AND ended_at IS NULL
       AND updated_at > now() - interval '12 hours' ORDER BY created_at DESC LIMIT 1`, [v.id, req.user.id]);
    const party = existing || await one(
      `INSERT INTO watch_parties(code, video_id, host_id, title, everyone_controls, position)
       VALUES ($1,$2,$3,$4,$5,$6) RETURNING *`,
      [shortId(8), v.id, req.user.id, String(req.body?.title || v.title).slice(0, 200),
        // Кто управляет просмотром — по умолчанию как настроил администратор
        req.body?.everyoneControls === undefined ? req.settings['party.everyone_controls'] !== false : req.body.everyoneControls !== false,
        Math.max(0, Number(req.body?.position) || 0)]);
    await touchMember(party.id, req.user.id);
    await audit(req, 'party.created', { targetType: 'video', targetId: v.id, meta: { party: party.id } });
    return { party: partyOut(party, { video: v, viewer: req.user, members: await partyMembers(party.id) }), url: `${config.baseUrl}/party/${party.code}` };
  });

  /** Состояние комнаты: позиция, участники, история чата. */
  app.get('/party/:code', { preHandler: app.requireActive }, async (req) => {
    const party = await one('SELECT * FROM watch_parties WHERE code = $1', [String(req.params.code)]);
    if (!party) throw notFound('Комната не найдена');
    const v = await loadVideo(party.video_id);
    if (!v || !(await canViewVideo(v, req.user))) throw forbidden('Нет доступа к видео этой комнаты');
    await touchMember(party.id, req.user.id);
    const members = await partyMembers(party.id);
    return {
      party: partyOut(party, { video: v, viewer: req.user, members }),
      video: videoCard(v, { owner: { id: v.owner_id, handle: v.owner_handle, displayName: v.owner_name } }),
      hlsUrl: mediaUrl(v.hls_path),
      mp4Url: mediaUrl(v.mp4_path),
      messages: await roomHistory(`party:${party.id}`),
      serverTime: new Date().toISOString(),
    };
  });

  /** Изменить состояние комнаты (пауза, перемотка, скорость) — ведущий или любой участник. */
  app.post('/party/:code/state', { preHandler: app.requireActive }, async (req) => {
    const party = await one('SELECT * FROM watch_parties WHERE code = $1', [String(req.params.code)]);
    if (!party) throw notFound('Комната не найдена');
    if (party.ended_at) throw badRequest('Комната закрыта');
    const isHost = party.host_id === req.user.id;
    if (!isHost && !party.everyone_controls && !isStaff(req.user)) throw forbidden('Управлять просмотром может только ведущий');
    const v = await loadVideo(party.video_id);
    if (!v || !(await canViewVideo(v, req.user))) throw forbidden();
    const b = req.body || {};
    const position = Math.max(0, Math.min(Number(v.duration) || 1e9, Number(b.position) || 0));
    const playing = !!b.playing;
    const rate = Math.min(3, Math.max(0.25, Number(b.rate) || 1));
    const row = await one(
      `UPDATE watch_parties SET position = $2, playing = $3, rate = $4, state_at = now(), updated_at = now()
       WHERE id = $1 RETURNING *`, [party.id, position, playing, rate]);
    await touchMember(party.id, req.user.id);
    await toChannel(`party:${party.id}`, {
      type: 'party.state', partyId: party.id, position, playing, rate,
      by: { id: req.user.id, displayName: req.user.display_name }, serverTime: new Date().toISOString(),
    });
    return { party: partyOut(row, { video: v, viewer: req.user, members: await partyMembers(party.id) }) };
  });

  /** Отметиться в комнате (раз в 30 секунд) — список участников и текущая позиция. */
  app.post('/party/:code/ping', { preHandler: app.requireActive }, async (req) => {
    const party = await one('SELECT * FROM watch_parties WHERE code = $1', [String(req.params.code)]);
    if (!party) throw notFound('Комната не найдена');
    const v = await loadVideo(party.video_id);
    if (!v || !(await canViewVideo(v, req.user))) throw forbidden();
    await touchMember(party.id, req.user.id);
    return { position: partyPosition(party), playing: !!party.playing && !party.ended_at, members: (await partyMembers(party.id)).length, serverTime: new Date().toISOString() };
  });

  app.post('/party/:code/chat', { preHandler: app.requireActive }, async (req) => {
    const party = await one('SELECT * FROM watch_parties WHERE code = $1', [String(req.params.code)]);
    if (!party) throw notFound('Комната не найдена');
    const v = await loadVideo(party.video_id);
    if (!v || !(await canViewVideo(v, req.user))) throw forbidden();
    await touchMember(party.id, req.user.id);
    const msg = await postRoomMessage(`party:${party.id}`, req.user, req.body?.body);
    if (!msg) throw badRequest('Пустое сообщение');
    return { message: msg };
  });

  /** Пригласить сотрудника в комнату — уведомление со ссылкой. */
  app.post('/party/:code/invite', { preHandler: app.requireActive }, async (req) => {
    const party = await one('SELECT * FROM watch_parties WHERE code = $1', [String(req.params.code)]);
    if (!party) throw notFound('Комната не найдена');
    if (party.host_id !== req.user.id && !isStaff(req.user)) throw forbidden('Приглашать может ведущий');
    const ids = Array.isArray(req.body?.userIds) ? req.body.userIds.slice(0, 50) : [];
    if (!ids.length) throw badRequest('Не выбраны сотрудники');
    const v = await loadVideo(party.video_id);
    const users = await many(`SELECT id FROM users WHERE id = ANY($1::uuid[]) AND status='active' AND deleted_at IS NULL`, [ids]);
    for (const u of users) {
      if (u.id === req.user.id) continue;
      await notify(u.id, {
        type: 'party.invite', actorId: req.user.id,
        title: `${req.user.display_name} зовёт посмотреть вместе`,
        body: v?.title || '', link: `/party/${party.code}`,
      });
    }
    return { invited: users.length };
  });

  app.post('/party/:code/end', { preHandler: app.requireActive }, async (req) => {
    const party = await one('SELECT * FROM watch_parties WHERE code = $1', [String(req.params.code)]);
    if (!party) throw notFound('Комната не найдена');
    if (party.host_id !== req.user.id && !isStaff(req.user)) throw forbidden('Закрыть комнату может ведущий');
    await query('UPDATE watch_parties SET ended_at = now(), playing = false, updated_at = now() WHERE id = $1 AND ended_at IS NULL', [party.id]);
    await toChannel(`party:${party.id}`, { type: 'party.ended', partyId: party.id });
    return { ok: true };
  });
}
