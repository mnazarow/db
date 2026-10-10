// xAPI (Tin Can): отправка выражений об обучении в LRS (Moodle, iSpring Learn, WebTutor, Mirapolis, Learning Locker).
import { one, query } from '../db.js';
import { enqueue } from './jobs.js';
import { getSettingSync, loadSettings } from './settings.js';
import { config } from '../config.js';
import { safeFetch, responseHint, POLICY } from './net.js';

const VERBS = {
  completed: { id: 'http://adlnet.gov/expapi/verbs/completed', display: { 'en-US': 'completed', 'ru-RU': 'завершил' } },
  passed: { id: 'http://adlnet.gov/expapi/verbs/passed', display: { 'en-US': 'passed', 'ru-RU': 'сдал' } },
  failed: { id: 'http://adlnet.gov/expapi/verbs/failed', display: { 'en-US': 'failed', 'ru-RU': 'не сдал' } },
  experienced: { id: 'http://adlnet.gov/expapi/verbs/experienced', display: { 'en-US': 'experienced', 'ru-RU': 'просмотрел' } },
  progressed: { id: 'http://adlnet.gov/expapi/verbs/progressed', display: { 'en-US': 'progressed', 'ru-RU': 'продвинулся' } },
};

function isoDuration(sec) {
  const s = Math.max(0, Math.round(Number(sec) || 0));
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
  return `PT${h ? `${h}H` : ''}${m ? `${m}M` : ''}${x || (!h && !m) ? `${x}S` : ''}`;
}

export function xapiActor(user, s) {
  const by = s['xapi.actor_by'] || 'email';
  if (by === 'account') return { objectType: 'Agent', name: user.display_name, account: { homePage: s['xapi.homepage'] || config.baseUrl, name: user.ldap_login || user.email } };
  return { objectType: 'Agent', name: user.display_name, mbox: `mailto:${user.email}` };
}

export function xapiObject(video) {
  return {
    objectType: 'Activity', id: `${config.baseUrl}/watch/${video.short_id}`,
    definition: { name: { 'ru-RU': video.title }, description: video.description ? { 'ru-RU': String(video.description).slice(0, 500) } : undefined, type: 'https://w3id.org/xapi/video/activity-type/video' },
  };
}

/** Записать выражение и поставить отправку в очередь. verb: completed | passed | failed | experienced | progressed */
export async function xapiStatement(verb, { user, video, object = null, result = null, context = null }) {
  if (!getSettingSync('xapi.enabled') || !getSettingSync('xapi.endpoint')) return null;
  if (!VERBS[verb] || !user || (!video && !object)) return null;
  const s = await loadSettings();
  // object — произвольная активность (например курс); по умолчанию это видео
  const activity = object
    ? { objectType: 'Activity', id: object.id, definition: { name: { 'ru-RU': object.name }, type: object.type || 'http://adlnet.gov/expapi/activities/course' } }
    : xapiObject(video);
  const statement = {
    id: crypto.randomUUID(),
    actor: xapiActor(user, s),
    verb: VERBS[verb],
    object: activity,
    timestamp: new Date().toISOString(),
    context: { platform: 'CorpVideo', language: 'ru-RU', ...(context || {}) },
  };
  if (result) statement.result = result;
  const row = await one('INSERT INTO xapi_statements(user_id, video_id, verb, statement) VALUES ($1,$2,$3,$4::jsonb) RETURNING id', [user.id, video?.id || null, verb, JSON.stringify(statement)]);
  await enqueue('xapi_send', { statementId: row.id }, { dedupe: false, maxAttempts: 5 });
  return row.id;
}

function authHeaders(s) {
  const h = { 'content-type': 'application/json', 'X-Experience-API-Version': '1.0.3' };
  if (s['xapi.auth_header']) h.authorization = s['xapi.auth_header'];
  else if (s['xapi.username']) h.authorization = `Basic ${Buffer.from(`${s['xapi.username']}:${s['xapi.password'] || ''}`).toString('base64')}`;
  return h;
}

function statementsUrl(s) {
  const base = String(s['xapi.endpoint'] || '').replace(/\/+$/, '');
  return /\/statements$/.test(base) ? base : `${base}/statements`;
}

/** Отправка выражения (задание воркера). */
export async function sendXapi(job) {
  const s = await loadSettings(true);
  const row = await one('SELECT * FROM xapi_statements WHERE id = $1', [job.payload.statementId]);
  if (!row || row.status === 'ok') return { skipped: true };
  if (!s['xapi.endpoint']) { await query(`UPDATE xapi_statements SET status = 'failed', error = 'LRS не настроен' WHERE id = $1`, [row.id]); return { skipped: 'no endpoint' }; }
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 20000);
  try {
    const res = await safeFetch(statementsUrl(s), { method: 'POST', headers: authHeaders(s), body: JSON.stringify(row.statement), signal: ctrl.signal }, POLICY.integration);
    const hint = responseHint(res, await res.text().catch(() => ''));
    // 409 Conflict — такой statementId уже принят LRS (повтор доставки): считаем успехом, иначе зациклимся
    if (!res.ok && res.status !== 409) throw new Error(`LRS ответил ${res.status}${hint ? `: ${hint}` : ''}`);
    await query(`UPDATE xapi_statements SET status = 'ok', sent_at = now(), error = NULL WHERE id = $1`, [row.id]);
    return { status: res.status };
  } catch (e) {
    await query(`UPDATE xapi_statements SET status = $2, error = $3 WHERE id = $1`, [row.id, job.attempts >= job.max_attempts ? 'failed' : 'queued', e.message.slice(0, 1000)]);
    throw e;
  } finally { clearTimeout(t); }
}

/** Проверка из панели: отправить тестовое выражение. */
export async function xapiTest(s, user) {
  const statement = {
    id: crypto.randomUUID(), actor: xapiActor(user, s), verb: VERBS.experienced,
    object: { objectType: 'Activity', id: `${config.baseUrl}/`, definition: { name: { 'ru-RU': 'Проверка связи CorpVideo → LRS' } } },
    timestamp: new Date().toISOString(),
  };
  const res = await safeFetch(statementsUrl(s), { method: 'POST', headers: authHeaders(s), body: JSON.stringify(statement), signal: AbortSignal.timeout(15000) }, POLICY.integration);
  const hint = responseHint(res, await res.text().catch(() => ''));
  if (!res.ok) throw new Error(`LRS ответил ${res.status}${hint ? `: ${hint}` : ''}`);
  return { status: res.status };
}

export function xapiResultForProgress(percent, durationSec, completed) {
  return { completion: !!completed, duration: isoDuration(durationSec), extensions: { 'https://w3id.org/xapi/video/extensions/progress': Math.round(Number(percent) || 0) / 100 } };
}
export function xapiResultForQuiz(percent, passed, passPercent) {
  return { success: !!passed, completion: true, score: { scaled: Math.round(Number(percent) || 0) / 100, raw: Math.round(Number(percent) || 0), min: 0, max: 100 }, extensions: { 'https://w3id.org/xapi/cmi5/result/extensions/pass-percent': passPercent } };
}
