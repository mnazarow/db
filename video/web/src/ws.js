// WebSocket-клиент с автопереподключением и простой шиной событий.
import { BASE } from './base.js';
const listeners = new Map();
let socket = null;
let timer = null;
let attempts = 0;
let joined = new Set();

function emit(type, payload) {
  for (const fn of listeners.get(type) || []) { try { fn(payload); } catch (e) { console.error(e); } }
  for (const fn of listeners.get('*') || []) { try { fn(payload); } catch (e) { console.error(e); } }
}

export function on(type, fn) {
  if (!listeners.has(type)) listeners.set(type, new Set());
  listeners.get(type).add(fn);
  return () => listeners.get(type)?.delete(fn);
}

export function send(msg) {
  if (socket && socket.readyState === 1) socket.send(JSON.stringify(msg));
}

export function join(channel) {
  joined.add(channel);
  send({ type: 'join', channel });
}
export function leave(channel) {
  joined.delete(channel);
  send({ type: 'leave', channel });
}

export function connect() {
  if (socket && (socket.readyState === 0 || socket.readyState === 1)) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  let ws;
  try {
    ws = new WebSocket(`${proto}://${location.host}${BASE}/ws`);
  } catch { scheduleReconnect(); return; }
  socket = ws;
  // События старого сокета (после reconnect()) не должны трогать новый: иначе его onclose
  // останавливал пинг уже открытого соединения
  ws.onopen = () => {
    if (socket !== ws) return;
    attempts = 0;
    lastSeen = Date.now();
    startPing();
    emit('open', {});
    for (const ch of joined) send({ type: 'join', channel: ch });
  };
  ws.onmessage = (ev) => {
    if (socket !== ws) return;
    lastSeen = Date.now();
    let msg;
    try { msg = JSON.parse(ev.data); } catch { return; }
    if (msg && msg.type) emit(msg.type, msg);
  };
  ws.onclose = () => { if (socket !== ws) return; stopPing(); emit('close', {}); scheduleReconnect(); };
  ws.onerror = () => { try { ws.close(); } catch { /* ignore */ } };
}

function scheduleReconnect() {
  clearTimeout(timer);
  const delay = Math.min(30000, 1000 * 2 ** Math.min(attempts++, 5));
  timer = setTimeout(connect, delay);
}

export function reconnect() {
  try { socket?.close(); } catch { /* ignore */ }
  socket = null;
  attempts = 0;
  connect();
}

// Пинг — только пока соединение открыто. Если сервер молчит дольше двух интервалов (ноутбук
// проснулся, сеть сменилась, «полуоткрытое» соединение), закрываем сокет и переподключаемся.
let pingTimer = null;
let lastSeen = 0;
function startPing() {
  stopPing();
  pingTimer = setInterval(() => {
    if (Date.now() - lastSeen > 75000) { try { socket?.close(); } catch { /* ignore */ } return; }
    send({ type: 'ping' });
  }, 30000);
}
function stopPing() { clearInterval(pingTimer); pingTimer = null; }
