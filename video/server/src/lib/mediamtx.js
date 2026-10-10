// Клиент API MediaMTX (сервер трансляций): состояние путей, зрители, принудительное отключение.
import { config } from '../config.js';

async function api(method, path, body) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 5000);
  try {
    const res = await fetch(`${config.mediamtx.apiUrl}${path}`, {
      method,
      headers: body ? { 'content-type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
      signal: ctrl.signal,
    });
    if (res.status === 404) return null;
    if (!res.ok) throw new Error(`MediaMTX ${res.status}`);
    const text = await res.text();
    return text ? JSON.parse(text) : {};
  } finally { clearTimeout(timer); }
}

export const mediamtx = {
  available: async () => { try { await api('GET', '/v3/config/global/get'); return true; } catch { return false; } },
  /** Активные пути (трансляции). */
  paths: async () => { const r = await api('GET', '/v3/paths/list?itemsPerPage=500'); return r?.items || []; },
  path: async (name) => api('GET', `/v3/paths/get/${encodeURIComponent(name)}`),
  /** Число читателей (зрителей) пути: RTSP/WebRTC/SRT + HLS-мукснеры. */
  readers: async (name) => {
    const p = await api('GET', `/v3/paths/get/${encodeURIComponent(name)}`).catch(() => null);
    if (!p) return 0;
    let n = Array.isArray(p.readers) ? p.readers.length : 0;
    try {
      const hls = await api('GET', `/v3/hlsmuxers/get/${encodeURIComponent(name)}`);
      // HLS-мукснер не знает число клиентов, считаем по нашим auth-запросам (см. live.js)
      if (hls && hls.bytesSent > 0) n += 0;
    } catch { /* ignore */ }
    return n;
  },
  /** Записанные отрезки пути (сервер воспроизведения MediaMTX) — для перемотки эфира назад. */
  playbackList: async (name) => {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 5000);
    try {
      const res = await fetch(`${config.mediamtx.playbackUrl.replace(/\/$/, '')}/list?path=${encodeURIComponent(name)}`, { signal: ctrl.signal });
      if (!res.ok) return [];
      const items = await res.json();
      return Array.isArray(items) ? items.map((x) => ({ start: x.start, duration: Number(x.duration) || 0 })) : [];
    } catch { return []; } finally { clearTimeout(timer); }
  },
  /** Адрес воспроизведения записи с указанного момента. */
  playbackUrl: (name, startIso, duration, format = 'mp4') =>
    `${config.mediamtx.playbackUrl.replace(/\/$/, '')}/get?path=${encodeURIComponent(name)}&start=${encodeURIComponent(startIso)}&duration=${encodeURIComponent(String(duration))}&format=${format}`,

  /** Отключить издателя (завершить эфир принудительно). */
  kick: async (name) => {
    const p = await api('GET', `/v3/paths/get/${encodeURIComponent(name)}`).catch(() => null);
    if (!p?.source) return false;
    const { type, id } = p.source;
    const map = { rtmpConn: 'rtmpconns', rtmpsConn: 'rtmpsconns', srtConn: 'srtconns', webRTCSession: 'webrtcsessions', rtspSession: 'rtspsessions', rtspsSession: 'rtspssessions' };
    const kind = map[type];
    if (!kind || !id) return false;
    await api('POST', `/v3/${kind}/kick/${id}`);
    return true;
  },
};
