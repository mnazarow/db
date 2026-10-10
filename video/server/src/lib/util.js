// Общие утилиты.
import path from 'node:path';

export class HttpError extends Error {
  constructor(status, message, extra = {}) {
    super(message);
    this.status = status;
    this.extra = extra;
  }
}
export const badRequest = (m = 'Некорректный запрос', extra) => new HttpError(400, m, extra);
export const unauthorized = (m = 'Требуется вход в систему') => new HttpError(401, m);
export const forbidden = (m = 'Недостаточно прав') => new HttpError(403, m);
export const notFound = (m = 'Не найдено') => new HttpError(404, m);
export const conflict = (m = 'Конфликт данных') => new HttpError(409, m);
export const tooMany = (m = 'Слишком много запросов') => new HttpError(429, m);

export function clampInt(v, min, max, def) {
  const n = parseInt(v, 10);
  if (!Number.isFinite(n)) return def;
  return Math.min(max, Math.max(min, n));
}

export function paging(q, defLimit = 24, maxLimit = 100) {
  const limit = clampInt(q?.limit, 1, maxLimit, defLimit);
  const page = clampInt(q?.page, 1, 100000, 1);
  return { limit, page, offset: (page - 1) * limit };
}

export function emailDomain(email) {
  const at = String(email || '').lastIndexOf('@');
  return at < 0 ? '' : email.slice(at + 1).toLowerCase();
}

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

const TRANSLIT = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm',
  н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'c', ч: 'ch', ш: 'sh', щ: 'sch',
  ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
};

export function translit(text) {
  return String(text).toLowerCase().split('').map((ch) => (TRANSLIT[ch] !== undefined ? TRANSLIT[ch] : ch)).join('');
}

export function slugify(text, max = 40) {
  const s = translit(text).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, max).replace(/-+$/g, '');
  return s || 'user';
}

/** Имя канала (@handle) из e-mail или имени. */
export function handleFromEmail(email) {
  const local = String(email).split('@')[0];
  return slugify(local, 30).replace(/-/g, '.') || 'user';
}

/** Разбор таймкодов в описании: "0:00 Введение" → главы. */
export function parseChapters(description, duration = 0) {
  const lines = String(description || '').split(/\r?\n/);
  const chapters = [];
  const re = /^\s*(?:[-•*]\s*)?(?:\(?(\d{1,2}):)?(\d{1,2}):(\d{2})\)?\s*[-–—:]?\s*(.+)$/;
  for (const line of lines) {
    const m = line.match(re);
    if (!m) continue;
    const h = m[1] ? parseInt(m[1], 10) : 0;
    const mm = parseInt(m[2], 10), ss = parseInt(m[3], 10);
    const start = h * 3600 + mm * 60 + ss;
    const title = m[4].trim();
    if (!title) continue;
    if (duration && start > duration) continue;
    chapters.push({ start, title });
  }
  chapters.sort((a, b) => a.start - b.start);
  // главы валидны, только если первая начинается с 0 и их минимум 2
  if (chapters.length < 2 || chapters[0].start !== 0) return [];
  return chapters;
}

export function formatDuration(sec) {
  sec = Math.max(0, Math.round(sec || 0));
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

export function deviceFromUa(ua = '') {
  ua = String(ua);
  if (/iPad|Tablet|PlayBook|Silk/i.test(ua) || (/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'tablet';
  if (/Mobi|iPhone|Android|webOS|BlackBerry|IEMobile|Opera Mini/i.test(ua)) return 'mobile';
  if (/SmartTV|SMART-TV|Tizen|WebOS|AppleTV/i.test(ua)) return 'tv';
  return 'desktop';
}

export function safeFilename(name) {
  const base = path.basename(String(name || 'file')).replace(/[\\/:*?"<>|\x00-\x1f]/g, '_').trim();
  return base.slice(0, 200) || 'file';
}

export function extOf(name) {
  return path.extname(String(name || '')).slice(1).toLowerCase();
}

/** Приведение произвольного SRT/VTT текста к WebVTT. */
export function toVtt(text) {
  let t = String(text || '').replace(/\r\n?/g, '\n').replace(/^﻿/, '');
  if (/^WEBVTT/.test(t)) return t;
  // SRT → VTT: заменить запятые в таймкодах на точки, добавить заголовок
  t = t.replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g, '$1.$2');
  return 'WEBVTT\n\n' + t.trim() + '\n';
}

/** Сегменты [{start,end,text}] → WebVTT. */
export function segmentsToVtt(segments) {
  const ts = (s) => {
    s = Math.max(0, s || 0);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${sec.toFixed(3).padStart(6, '0')}`;
  };
  let out = 'WEBVTT\n\n';
  segments.forEach((seg, i) => {
    const text = String(seg.text || '').trim();
    if (!text) return;
    out += `${i + 1}\n${ts(seg.start)} --> ${ts(seg.end ?? seg.start + 3)}\n${text}\n\n`;
  });
  return out;
}

/** Текст из VTT (для поискового индекса). */
export function vttToText(vtt) {
  return String(vtt || '')
    .split('\n')
    .filter((l) => l && !/^WEBVTT/.test(l) && !/-->/.test(l) && !/^\d+$/.test(l) && !/^NOTE/.test(l))
    .join(' ')
    .replace(/<[^>]+>/g, ' ')   // теги вырезаем после склейки: иначе тег, разорванный по строкам, уцелеет
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Фрагмент из ts_headline: PostgreSQL вставляет маркеры <b>…</b>, но окружающий текст отдаёт как есть.
 * Экранируем всё и возвращаем только маркеры — результат безопасно выводить через v-html.
 */
export function safeHeadline(raw) {
  const s = String(raw || '');
  if (!s) return '';
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/&lt;b&gt;/g, '<b>').replace(/&lt;\/b&gt;/g, '</b>');
}

export function pick(obj, keys) {
  const out = {};
  for (const k of keys) if (obj[k] !== undefined) out[k] = obj[k];
  return out;
}

export function normalizeTags(tags) {
  if (typeof tags === 'string') tags = tags.split(/[,\n]+/);
  if (!Array.isArray(tags)) return [];
  const seen = new Set();
  const out = [];
  for (let t of tags) {
    t = String(t).trim().replace(/^#/, '').slice(0, 40);
    if (!t) continue;
    const k = t.toLowerCase();
    if (seen.has(k)) continue;
    seen.add(k);
    out.push(t);
    if (out.length >= 30) break;
  }
  return out;
}

export function extractHashtags(text) {
  const out = new Set();
  for (const m of String(text || '').matchAll(/(?:^|\s)#([\p{L}\p{N}_]{2,40})/gu)) out.add(m[1]);
  return [...out];
}

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

export function bytesHuman(n) {
  n = Number(n || 0);
  const u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(i === 0 ? 0 : 1)} ${u[i]}`;
}

/** Похоже ли значение на UUID: защищает запросы к uuid-колонкам от «undefined» и прочего мусора. */
export function isUuid(v) {
  return typeof v === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v);
}

/** Целое из запроса: пустое, мусорное или отрицательное значение → null (иначе NaN уедет в SQL). */
export function intOrNull(v, { min = 1, max = 2147483647 } = {}) {
  if (v === undefined || v === null || v === '' || v === false) return null;
  const n = Number(v);
  if (!Number.isFinite(n)) return null;
  const i = Math.trunc(n);
  if (i < min || i > max) return null;
  return i;
}

/** Размер файла из запроса: положительное целое в пределах bigint, иначе null. */
export function sizeOrNull(v) {
  const n = Number(v);
  if (!Number.isFinite(n) || n <= 0 || n > Number.MAX_SAFE_INTEGER) return null;
  return Math.trunc(n);
}

export function clientIp(req) {
  return req.ip || req.socket?.remoteAddress || '';
}

/** WebVTT → сегменты [{start,end,text}] (для ИИ-обработки и транскрипта). */
export function vttToSegments(vtt) {
  const out = [];
  const ts = (t) => { const p = t.trim().split(':').map(Number); return p.length === 3 ? p[0] * 3600 + p[1] * 60 + p[2] : p[0] * 60 + p[1]; };
  const blocks = String(vtt || '').replace(/\r\n?/g, '\n').split(/\n\n+/);
  for (const b of blocks) {
    const lines = b.split('\n').filter(Boolean);
    const i = lines.findIndex((l) => l.includes('-->'));
    if (i < 0) continue;
    const [a, c] = lines[i].split('-->');
    const text = lines.slice(i + 1).join(' ').replace(/<[^>]+>/g, '').trim();
    if (!text) continue;
    const endStr = String(c || '').trim().split(/\s+/)[0];
    out.push({ start: ts(a), end: endStr ? ts(endStr) : ts(a), text });
  }
  return out;
}

/** Ключ ограничения частоты: пользователь, если вошёл, иначе адрес (для хуков preHandler, где req.user уже известен). */
export const userOrIpKey = (req) => (req.user?.id ? `u:${req.user.id}` : `ip:${req.ip}`);

/** Экранировать %, _ и \\ для LIKE/ILIKE: иначе поиск «_» или «%» находит всё подряд и нагружает базу. */
export function likeEscape(v) {
  return String(v ?? '').replace(/[\\%_]/g, (c) => `\\${c}`);
}
