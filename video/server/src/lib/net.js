// Проверка адресов для исходящих запросов портала (импорт по ссылке, ретрансляция, вебхуки,
// интеграции: ИИ, распознавание речи, база знаний, LRS, SSO).
import dns from 'node:dns/promises';
import { config } from '../config.js';

/** Служебные адреса, куда порталу ходить незачем никогда: «этот хост», link-local (метаданные облака), широковещательные. */
function reservedIp(ip) {
  const s = String(ip).toLowerCase().replace(/^::ffff:/, '');
  if (/^0\./.test(s) || /^169\.254\./.test(s) || s === '255.255.255.255') return true;
  if (/^(22[4-9]|23\d)\./.test(s)) return true;                                        // multicast 224.0.0.0/4
  if (s === '::' || /^fe[89ab][0-9a-f]:/.test(s) || /^ff[0-9a-f]{2}:/.test(s)) return true; // IPv6: unspecified, link-local, multicast
  return false;
}

/** Сам сервер (loopback). */
function loopbackIp(ip) {
  const s = String(ip).toLowerCase().replace(/^::ffff:/, '');
  return /^127\./.test(s) || s === '::1';
}

/** Адрес из частных диапазонов (LAN, CGNAT, ULA). Для внутренних порталов это нормально, для ретрансляции наружу — нет. */
function privateIp(ip) {
  const s = String(ip).toLowerCase().replace(/^::ffff:/, '');
  if (/^10\./.test(s)) return true;
  if (/^192\.168\./.test(s)) return true;
  if (/^172\.(1[6-9]|2\d|3[01])\./.test(s)) return true;
  if (/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./.test(s)) return true;               // CGNAT 100.64.0.0/10
  if (/^f[cd][0-9a-f]{2}:/.test(s)) return true;                                      // ULA fc00::/7
  return false;
}

function verdict(ip, { allowPrivate, allowLoopback }) {
  if (reservedIp(ip)) return 'служебный адрес';
  if (loopbackIp(ip) && !allowLoopback) return 'локальный адрес сервера';
  if (!allowPrivate && privateIp(ip)) return 'адрес внутренней сети';
  return null;
}

/**
 * Разрешить имя и проверить все полученные адреса.
 * allowPrivate — можно ли во внутреннюю сеть (LAN), allowLoopback — можно ли на сам сервер.
 * Служебные адреса (link-local, в том числе метаданные облака 169.254.169.254) запрещены всегда.
 * Возвращает { ok, reason } — причина на русском, её можно показать человеку.
 */
export async function checkOutboundHost(host, { allowPrivate = true, allowLoopback = false } = {}) {
  const h = String(host || '').replace(/^\[|\]$/g, '').toLowerCase();
  if (!h) return { ok: false, reason: 'адрес не указан' };
  if (!allowLoopback && (h === 'localhost' || h.endsWith('.localhost'))) return { ok: false, reason: 'локальный адрес сервера' };
  const literal = verdict(h, { allowPrivate, allowLoopback });
  if (literal) return { ok: false, reason: literal };
  let addrs = [];
  try { addrs = await dns.lookup(h, { all: true }); } catch { return { ok: true }; } // имя не разрешилось — запрос всё равно не пройдёт
  for (const a of addrs) {
    const why = verdict(a.address, { allowPrivate, allowLoopback });
    if (why) return { ok: false, reason: `имя ${h} указывает на ${why === 'служебный адрес' ? 'служебный адрес' : why === 'локальный адрес сервера' ? 'сам сервер' : 'внутреннюю сеть'}` };
  }
  return { ok: true };
}

/** Проверка по адресу целиком, с понятной ошибкой. */
export async function assertOutboundUrl(url, opts = {}) {
  let u;
  try { u = new URL(url); } catch { throw new Error('Некорректная ссылка'); }
  if (!/^https?:$/.test(u.protocol) && !opts.anyProtocol) throw new Error('Разрешены только адреса http:// и https://');
  const r = await checkOutboundHost(u.hostname, opts);
  if (!r.ok) throw new Error(`Запрос по этому адресу запрещён: ${r.reason}`);
  return u;
}

/**
 * Политики исходящих запросов.
 *  - integration: ИИ, ASR, база знаний, LRS, SSO — обычно во внутренней сети, бывают и на самом сервере;
 *  - webhook: мессенджеры и внешние системы — на сам сервер нельзя (если явно не разрешено OUTBOUND_ALLOW_LOCAL=1).
 */
export const POLICY = {
  integration: { allowPrivate: true, allowLoopback: true },
  get webhook() { return { allowPrivate: true, allowLoopback: !!config.outboundAllowLocal }; },
};

/**
 * fetch с проверкой адреса на каждом шаге, включая перенаправления: внешний адрес не должен
 * уводить запрос на служебный (например, 302 → http://169.254.169.254/).
 */
export async function safeFetch(url, opts = {}, policy = POLICY.integration, { maxRedirects = 3 } = {}) {
  let current = String(url);
  let o = { ...opts };
  for (let hop = 0; ; hop++) {
    const u = await assertOutboundUrl(current, policy);
    const res = await fetch(u, { ...o, redirect: 'manual' });
    const loc = res.status >= 300 && res.status < 400 ? res.headers.get('location') : null;
    if (!loc) return res;
    if (hop >= maxRedirects) throw new Error('Слишком много перенаправлений');
    current = new URL(loc, u).toString();
    // 301/302/303 превращают POST в GET — так ведут себя браузеры и fetch
    if ([301, 302, 303].includes(res.status) && o.method && !['GET', 'HEAD'].includes(String(o.method).toUpperCase())) {
      o = { ...o, method: 'GET', body: undefined };
      if (o.headers) { o.headers = { ...o.headers }; delete o.headers['content-type']; }
    }
  }
}

/**
 * Что показать человеку из ответа стороннего сервера. Тело ответа целиком не показываем: иначе
 * кнопка «проверить» в панели превращается в чтение внутренних сервисов. Из JSON берём только
 * сообщение об ошибке, из короткого текстового ответа (Slack отвечает «ok» или «invalid_payload») —
 * первые символы, из страниц HTML и прочего — ничего.
 */
export function responseHint(res, text, max = 160) {
  const ct = String(res?.headers?.get?.('content-type') || '').toLowerCase();
  const t = String(text || '');
  if (ct.includes('json')) {
    try {
      const j = JSON.parse(t);
      const m = j?.error?.message || j?.error_description || j?.message || j?.detail || (typeof j?.error === 'string' ? j.error : '') || j?.title;
      return m ? String(m).replace(/\s+/g, ' ').slice(0, max) : '';
    } catch { return ''; }
  }
  if (ct.startsWith('text/plain') && t.length <= 200 && !/[<>]/.test(t)) return t.replace(/\s+/g, ' ').trim().slice(0, max);
  return '';
}
