// Вход через OpenID Connect (Authorization Code + PKCE): обнаружение, старт, обмен кода, проверка id_token по JWKS.
import crypto from 'node:crypto';
import { one, query } from '../db.js';
import { safeFetch, POLICY } from './net.js';

const discoveryCache = new Map(); // issuer → { at, conf }
const jwksCache = new Map();      // jwks_uri → { at, keys }

const b64url = (buf) => Buffer.from(buf).toString('base64url');

async function fetchJson(url, opts = {}, timeoutMs = 10000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await safeFetch(url, { ...opts, signal: ctrl.signal }, POLICY.integration);
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not json */ }
    if (!res.ok) throw new Error(`${url} → ${res.status}${json ? ` ${json.error_description || json.error || ''}`.trimEnd() : ''}`);
    if (!json) throw new Error(`${url}: ответ не JSON`);
    return json;
  } finally { clearTimeout(t); }
}

/** Конфигурация провайдера по адресу issuer (кэш 10 минут). */
export async function discover(issuer) {
  const base = String(issuer || '').replace(/\/+$/, '');
  if (!base) throw new Error('Не задан адрес провайдера (issuer)');
  const c = discoveryCache.get(base);
  if (c && Date.now() - c.at < 600000) return c.conf;
  const conf = await fetchJson(`${base}/.well-known/openid-configuration`);
  for (const k of ['authorization_endpoint', 'token_endpoint']) if (!conf[k]) throw new Error(`В конфигурации провайдера нет ${k}`);
  discoveryCache.set(base, { at: Date.now(), conf });
  return conf;
}

/** Ссылка на авторизацию у провайдера; состояние сохраняется в БД (state, nonce, PKCE). */
export async function startLogin(s, redirectUri, nextUrl = '/') {
  const conf = await discover(s['oidc.issuer']);
  const state = b64url(crypto.randomBytes(24));
  const nonce = b64url(crypto.randomBytes(24));
  const verifier = b64url(crypto.randomBytes(48));
  const challenge = b64url(crypto.createHash('sha256').update(verifier).digest());
  await query('INSERT INTO oidc_states(state, nonce, verifier, next_url) VALUES ($1,$2,$3,$4)', [state, nonce, verifier, String(nextUrl || '/').slice(0, 500)]);
  await query(`DELETE FROM oidc_states WHERE created_at < now() - interval '15 minutes'`);
  const u = new URL(conf.authorization_endpoint);
  u.searchParams.set('response_type', 'code');
  u.searchParams.set('client_id', s['oidc.client_id']);
  u.searchParams.set('redirect_uri', redirectUri);
  u.searchParams.set('scope', s['oidc.scopes'] || 'openid profile email');
  u.searchParams.set('state', state);
  u.searchParams.set('nonce', nonce);
  u.searchParams.set('code_challenge', challenge);
  u.searchParams.set('code_challenge_method', 'S256');
  return { url: u.toString(), state };
}

function decodeJwt(token) {
  const parts = String(token || '').split('.');
  if (parts.length !== 3) throw new Error('id_token имеет неверный формат');
  const header = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
  const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
  return { header, payload, signed: `${parts[0]}.${parts[1]}`, signature: Buffer.from(parts[2], 'base64url') };
}

async function jwks(uri) {
  const c = jwksCache.get(uri);
  if (c && Date.now() - c.at < 600000) return c.keys;
  const j = await fetchJson(uri);
  const keys = Array.isArray(j.keys) ? j.keys : [];
  jwksCache.set(uri, { at: Date.now(), keys });
  return keys;
}

/** Проверка подписи и полей id_token (RS256/RS384/RS512/ES256; HS256 — по client_secret). */
async function verifyIdToken(token, conf, s, nonce) {
  const { header, payload, signed, signature } = decodeJwt(token);
  const alg = header.alg || 'RS256';
  let ok = false;
  if (/^HS/.test(alg)) {
    const mac = crypto.createHmac(`sha${alg.slice(2)}`, s['oidc.client_secret']).update(signed).digest();
    ok = mac.length === signature.length && crypto.timingSafeEqual(mac, signature);
  } else {
    if (!conf.jwks_uri) throw new Error('У провайдера нет jwks_uri');
    let keys = await jwks(conf.jwks_uri);
    let jwk = keys.find((k) => !header.kid || k.kid === header.kid);
    if (!jwk) { jwksCache.delete(conf.jwks_uri); keys = await jwks(conf.jwks_uri); jwk = keys.find((k) => !header.kid || k.kid === header.kid); }
    if (!jwk) throw new Error('Ключ подписи id_token не найден в JWKS');
    const pub = crypto.createPublicKey({ key: jwk, format: 'jwk' });
    const hash = `sha${alg.slice(2)}`;
    if (/^ES/.test(alg)) ok = crypto.verify(hash, Buffer.from(signed), { key: pub, dsaEncoding: 'ieee-p1363' }, signature);
    else if (/^PS/.test(alg)) ok = crypto.verify(hash, Buffer.from(signed), { key: pub, padding: crypto.constants.RSA_PKCS1_PSS_PADDING }, signature);
    else ok = crypto.verify(hash, Buffer.from(signed), pub, signature);
  }
  if (!ok) throw new Error('Подпись id_token недействительна');
  const issuer = String(s['oidc.issuer']).replace(/\/+$/, '');
  if (!payload.iss) throw new Error('id_token без издателя (iss)');
  if (String(payload.iss).replace(/\/+$/, '') !== issuer && String(payload.iss).replace(/\/+$/, '') !== String(conf.issuer || '').replace(/\/+$/, '')) throw new Error('id_token выдан другим провайдером');
  const aud = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
  if (!aud.includes(s['oidc.client_id'])) throw new Error('id_token предназначен другому клиенту');
  if (payload.exp && payload.exp * 1000 < Date.now() - 60000) throw new Error('id_token просрочен');
  if (nonce && payload.nonce !== nonce) throw new Error('nonce не совпадает');
  return payload;
}

/** Завершение входа: обмен кода на токены, проверка id_token, сведения о пользователе. */
export async function finishLogin(s, redirectUri, { code, state, boundState }) {
  if (!code || !state) throw new Error('Провайдер не вернул код авторизации');
  // Привязка к браузеру: без неё чужой (заранее полученный) code+state завершил бы вход в аккаунт атакующего
  if (!boundState || boundState !== state) throw new Error('Не найдена начатая сессия входа в этом браузере — начните вход заново');
  const st = await one('DELETE FROM oidc_states WHERE state = $1 RETURNING *', [state]);
  if (!st) throw new Error('Сессия входа устарела — попробуйте ещё раз');
  const conf = await discover(s['oidc.issuer']);
  const body = new URLSearchParams({ grant_type: 'authorization_code', code, redirect_uri: redirectUri, client_id: s['oidc.client_id'], code_verifier: st.verifier });
  const headers = { 'content-type': 'application/x-www-form-urlencoded', accept: 'application/json' };
  if (s['oidc.client_secret']) {
    // client_secret_basic (по умолчанию у большинства провайдеров) + дублируем в теле (client_secret_post)
    headers.authorization = 'Basic ' + Buffer.from(`${encodeURIComponent(s['oidc.client_id'])}:${encodeURIComponent(s['oidc.client_secret'])}`).toString('base64');
    body.set('client_secret', s['oidc.client_secret']);
  }
  const tokens = await fetchJson(conf.token_endpoint, { method: 'POST', headers, body: body.toString() }, 15000);
  if (!tokens.id_token) throw new Error('Провайдер не вернул id_token (нужен scope openid)');
  const claims = await verifyIdToken(tokens.id_token, conf, s, st.nonce);
  let info = { ...claims };
  if (conf.userinfo_endpoint && tokens.access_token) {
    try { const ui = await fetchJson(conf.userinfo_endpoint, { headers: { authorization: `Bearer ${tokens.access_token}` } }, 10000); info = { ...info, ...ui }; } catch { /* userinfo необязателен */ }
  }
  const pick = (claim) => { const v = info[claim]; return v === undefined ? undefined : v; };
  const email = String(pick(s['oidc.email_claim'] || 'email') || info.email || info.preferred_username || info.upn || '').trim().toLowerCase();
  const name = String(pick(s['oidc.name_claim'] || 'name') || info.name || [info.given_name, info.family_name].filter(Boolean).join(' ') || email.split('@')[0] || '').trim();
  let groups = pick(s['oidc.groups_claim'] || 'groups');
  if (typeof groups === 'string') groups = groups.split(/[,\s]+/).filter(Boolean);
  if (!Array.isArray(groups)) groups = [];
  groups = groups.map(String);
  const norm = (g) => String(g).toLowerCase().replace(/^\//, '');
  const inGroup = (g) => !!g && groups.some((x) => norm(x) === norm(g));
  if (s['oidc.required_group'] && !inGroup(s['oidc.required_group'])) throw new Error('Вам не разрешён вход на портал (нет требуемой группы)');
  // email_verified нужен, чтобы не связывать чужую учётку по одному лишь совпадению адреса.
  // Провайдер может его не присылать — тогда считаем адрес подтверждённым (прежнее поведение).
  const emailVerified = info.email_verified === undefined && claims.email_verified === undefined ? true : (info.email_verified ?? claims.email_verified) !== false;
  return { sub: String(claims.sub), email, name, groups, emailVerified, isAdmin: inGroup(s['oidc.admin_group']), nextUrl: st.next_url || '/', claims: info };
}

/** Проверка настроек из панели: обнаружение конфигурации провайдера. */
export async function oidcTest(s) {
  const conf = await discover(s['oidc.issuer']);
  return { ok: true, issuer: conf.issuer, authorization: conf.authorization_endpoint, token: conf.token_endpoint, userinfo: conf.userinfo_endpoint || null, jwks: conf.jwks_uri || null, scopes: conf.scopes_supported || [] };
}
