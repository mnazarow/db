// Клиент API: JSON-запросы, обработка ошибок, загрузка файлов.
// Пути вида '/api/…' получают префикс портала (VITE_BASE) в withBase; адреса от сервера уже с ним.
import { url as withBase } from './base.js';

export class ApiError extends Error {
  constructor(status, message, data) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

/** Короткая выжимка из неожиданного ответа — чтобы её можно было показать человеку и переслать администратору. */
function describeBody(text, type) {
  const head = String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
  const kind = /html/i.test(type) ? 'HTML-страница' : type ? `тип ${type.split(';')[0]}` : 'без типа';
  return head ? `${kind}: «${head}»` : kind;
}

async function handle(res) {
  const text = await res.text();
  const type = res.headers.get('content-type') || '';
  let data = null, parsed = true;
  try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; parsed = false; }
  if (!res.ok) {
    // Ошибку без JSON тоже не прячем: часто это страница прокси, антивируса или балансировщика
    const message = (parsed && (data?.error || data?.message)) || (text && !parsed ? `Ошибка ${res.status}. Ответ пришёл не от портала — ${describeBody(text, type)}` : `Ошибка ${res.status}`);
    throw new ApiError(res.status, message, data);
  }
  // Успешный ответ обязан быть JSON. Если это не так, между браузером и порталом кто-то подменил ответ
  // (прокси, VPN, антивирус, страница входа в корпоративную сеть) — молча продолжать нельзя.
  if (!parsed) throw new ApiError(res.status, `Портал ответил не по-своему (код ${res.status}). Скорее всего ответ подменили прокси, VPN или антивирус — ${describeBody(text, type)}`, data);
  return data;
}

/** Запрос к API не должен никуда перенаправляться: на 301 браузер превращает POST в GET и теряет тело. */
function checkRedirect(res, method, url) {
  if (!res.redirected || method === 'GET') return;
  throw new ApiError(0, `Запрос ${method} ${url} был перенаправлен веб-сервером на ${res.url}. При перенаправлении браузер теряет тело запроса. Это ошибка настройки nginx: для адреса без косой черты нужен точный location.`, null);
}

export async function api(method, url, body, { signal, headers = {} } = {}) {
  const opts = { method, headers: { ...headers }, signal, credentials: 'same-origin' };
  if (body instanceof FormData) opts.body = body;
  else if (body !== undefined) { opts.headers['content-type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const res = await fetch(withBase(url), opts);
  checkRedirect(res, method, withBase(url));
  return handle(res);
}

export const get = (url, opts) => api('GET', url, undefined, opts);
export const post = (url, body, opts) => api('POST', url, body, opts);
export const patch = (url, body, opts) => api('PATCH', url, body, opts);
export const put = (url, body, opts) => api('PUT', url, body, opts);
export const del = (url, opts) => api('DELETE', url, undefined, opts);

export function qs(params) {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(params || {})) if (v !== undefined && v !== null && v !== '') p.set(k, String(v));
  const s = p.toString();
  return s ? `?${s}` : '';
}

/** Загрузка файла с прогрессом (XHR, т.к. fetch не даёт прогресс отправки). */
export function uploadFile(url, file, { fieldName = 'file', fields = {}, onProgress, method = 'POST' } = {}) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) form.append(k, v);
    form.append(fieldName, file);
    xhr.open(method, withBase(url));
    xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
    xhr.onload = () => {
      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch { /* ignore */ }
      if (xhr.status >= 200 && xhr.status < 300) resolve(data);
      else reject(new ApiError(xhr.status, data?.error || `Ошибка ${xhr.status}`, data));
    };
    xhr.onerror = () => reject(new ApiError(0, 'Сетевая ошибка'));
    xhr.send(form);
  });
}
