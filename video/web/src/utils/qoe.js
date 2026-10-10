// Метрики качества воспроизведения: время до первого кадра, буферизации, ошибки, качество.
// Отправляются пачкой раз в полминуты и при закрытии страницы (sendBeacon), чтобы не терять сеанс.
import { url } from '../base.js';
const KEY_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';

function randomKey() {
  const a = new Uint8Array(16);
  (window.crypto || {}).getRandomValues?.(a);
  return Array.from(a, (b) => KEY_CHARS[b % KEY_CHARS.length]).join('') || String(Date.now());
}

function detectDevice() {
  const ua = navigator.userAgent || '';
  if (/iPad|Tablet|PlayBook|Silk/i.test(ua)) return 'tablet';
  if (/Mobi|Android|iPhone/i.test(ua)) return 'mobile';
  if (/SmartTV|AppleTV|GoogleTV|HbbTV/i.test(ua)) return 'tv';
  return 'desktop';
}
function detectBrowser() {
  const ua = navigator.userAgent || '';
  if (/YaBrowser/i.test(ua)) return 'yandex';
  if (/Edg\//i.test(ua)) return 'edge';
  if (/OPR\//i.test(ua)) return 'opera';
  if (/Firefox/i.test(ua)) return 'firefox';
  if (/Chrome/i.test(ua)) return 'chrome';
  if (/Safari/i.test(ua)) return 'safari';
  return 'other';
}

export function createQoe({ videoId = null, streamId = null, source = 'watch', enabled = true } = {}) {
  const state = {
    sessionKey: randomKey(), videoId, streamId, source,
    device: detectDevice(), browser: detectBrowser(),
    loadStartedAt: 0, startupMs: null, started: false,
    watchSec: 0, rebufferCount: 0, rebufferMs: 0, waitingSince: 0,
    qualityHeight: null, bitrateKbps: null, errors: 0, errorText: null,
    dirty: false, sending: false, timer: null, off: false,
  };
  if (!enabled) state.off = true;

  const payload = () => ({
    sessionKey: state.sessionKey, videoId: state.videoId, streamId: state.streamId, source: state.source,
    startupMs: state.startupMs, watchSec: Math.round(state.watchSec), rebufferCount: state.rebufferCount,
    rebufferMs: Math.round(state.rebufferMs), qualityHeight: state.qualityHeight, bitrateKbps: state.bitrateKbps,
    errors: state.errors, errorText: state.errorText, started: state.started,
    device: state.device, browser: state.browser,
  });

  async function flush({ beacon = false } = {}) {
    // Прощальный beacon уходит, даже если обычная отправка ещё в пути: иначе конец сеанса терялся
    if (state.off || !state.dirty || (state.sending && !beacon)) return;
    state.dirty = false;
    const body = JSON.stringify(payload());
    try {
      if (beacon && navigator.sendBeacon) { navigator.sendBeacon(url('/api/playback'), new Blob([body], { type: 'application/json' })); return; }
      state.sending = true;
      const res = await fetch(url('/api/playback'), { method: 'POST', headers: { 'content-type': 'application/json' }, body, credentials: 'include', keepalive: true });
      if (!res.ok) state.dirty = true;       // не принято (например, 429) — отправим в следующий раз
    } catch { state.dirty = true; /* метрики не должны мешать просмотру */ } finally { state.sending = false; }
  }
  let errorFlushAt = 0;

  return {
    get sessionKey() { return state.sessionKey; },
    loadStart() { state.loadStartedAt = performance.now(); state.startupMs = null; state.started = false; },
    firstFrame() {
      if (state.started) return;
      state.started = true;
      if (state.loadStartedAt) state.startupMs = Math.round(performance.now() - state.loadStartedAt);
      state.dirty = true;
      flush();
    },
    waiting() { if (!state.waitingSince) state.waitingSince = performance.now(); },
    playing() {
      if (state.waitingSince) {
        const d = performance.now() - state.waitingSince;
        state.waitingSince = 0;
        // Короткие паузы на старте — это ещё не «буферизация»
        if (state.started && d > 300) { state.rebufferCount++; state.rebufferMs += d; state.dirty = true; }
      }
    },
    watched(sec) { if (sec > 0) { state.watchSec += sec; state.dirty = true; } },
    quality(height, bitrate) {
      if (height) state.qualityHeight = height;
      if (bitrate) state.bitrateKbps = Math.round(bitrate / 1000);
    },
    // Ошибки сыплются очередью при обрыве сети — отправляем не чаще раза в 5 секунд
    error(text) {
      state.errors++; state.errorText = String(text || '').slice(0, 300); state.dirty = true;
      if (performance.now() - errorFlushAt > 5000) { errorFlushAt = performance.now(); flush(); }
    },
    start() {
      if (state.off || state.timer) return;
      state.timer = setInterval(() => flush(), 30000);
    },
    stop() {
      clearInterval(state.timer);
      state.timer = null;
      flush({ beacon: true });
    },
    flush,
  };
}
