// Доступ к PostgreSQL: пул соединений, транзакции, LISTEN/NOTIFY.
import pg from 'pg';
import { EventEmitter } from 'node:events';
import { config } from './config.js';

const { Pool } = pg;

// numeric → number, int8 → number (для счётчиков)
pg.types.setTypeParser(1700, (v) => (v === null ? null : parseFloat(v)));
pg.types.setTypeParser(20, (v) => (v === null ? null : Number(v)));
// date → строка 'YYYY-MM-DD' (без сдвига часовых поясов)
pg.types.setTypeParser(1082, (v) => v);

export const pool = new Pool({
  connectionString: config.databaseUrl,
  max: config.dbPoolSize,
  idleTimeoutMillis: 30000,
  connectionTimeoutMillis: 10000,
});

pool.on('error', (err) => {
  console.error('Ошибка пула PostgreSQL:', err.message);
});

/** Выполнить запрос. */
export async function query(text, params = []) {
  return pool.query(text, params);
}

/** Одна строка или null. */
export async function one(text, params = []) {
  const r = await pool.query(text, params);
  return r.rows[0] || null;
}

/** Массив строк. */
export async function many(text, params = []) {
  const r = await pool.query(text, params);
  return r.rows;
}

/** Скалярное значение первой колонки первой строки. */
export async function scalar(text, params = []) {
  const r = await pool.query(text, params);
  if (!r.rows[0]) return null;
  return r.rows[0][r.fields[0].name];
}

/** Транзакция: fn получает клиент с методами query/one/many. */
/**
 * Выполнить fn в транзакции под рекомендательной блокировкой PostgreSQL по строковому ключу.
 * В отличие от withLock ниже (которая пропускает работу, если ключ занят) — ЖДЁТ освобождения.
 * Нужен там, где «посмотрели и вставили» обязано быть неделимым: места на вебинаре,
 * квота канала, приём части загрузки, постановка задания без дублей.
 */
export async function withTxLock(key, fn) {
  return tx(async (c) => {
    await c.query('SELECT pg_advisory_xact_lock(hashtext($1))', [String(key)]);
    return fn(c);
  });
}

export async function tx(fn) {
  const client = await pool.connect();
  const api = {
    query: (t, p = []) => client.query(t, p),
    one: async (t, p = []) => (await client.query(t, p)).rows[0] || null,
    many: async (t, p = []) => (await client.query(t, p)).rows,
    client,
  };
  try {
    await client.query('BEGIN');
    const result = await fn(api);
    await client.query('COMMIT');
    return result;
  } catch (e) {
    try { await client.query('ROLLBACK'); } catch { /* ignore */ }
    throw e;
  } finally {
    client.release();
  }
}

/** Шина событий на LISTEN/NOTIFY (между API и воркерами). */
/**
 * Выполнить fn под межпроцессной блокировкой PostgreSQL (advisory lock).
 * Блокировка удерживается на выделенном соединении всё время работы fn.
 * Возвращает { ok:false } без выполнения, если блокировку уже держит другой воркер.
 */
export async function withLock(key, fn) {
  const client = await pool.connect();
  let held = false;
  try {
    const r = await client.query('SELECT pg_try_advisory_lock(hashtext($1)) AS ok', [String(key)]);
    held = !!r.rows[0]?.ok;
    if (!held) return { ok: false };
    return { ok: true, value: await fn() };
  } finally {
    if (held) await client.query('SELECT pg_advisory_unlock(hashtext($1))', [String(key)]).catch(() => {});
    client.release();
  }
}

export const bus = new EventEmitter();
bus.setMaxListeners(1000);
let listenClient = null;
const CHANNEL = 'corpvideo_events';

let listenerStarted = false;
let dbClosing = false;
let listenerDownSince = 0;
export async function startListener() {
  if (listenClient || listenerStarted) return;
  listenerStarted = true;
  let retryTimer = null;
  const reconnect = (client, delay) => {
    // Один перезапуск на одно соединение: «error» и «end» часто приходят вместе
    if (dbClosing || (client && client.__cvDead)) return;
    if (client) { client.__cvDead = true; try { client.end().catch(() => {}); } catch { /* уже закрыт */ } }
    if (listenClient === client) listenClient = null;
    clearTimeout(retryTimer);
    retryTimer = setTimeout(connect, delay);
  };
  const connect = async () => {
    let client = null;
    try {
      client = new pg.Client({ connectionString: config.databaseUrl, keepAlive: true });
      await client.connect();
      client.on('notification', (msg) => {
        if (msg.channel !== CHANNEL) return;
        try {
          const payload = JSON.parse(msg.payload);
          bus.emit('event', payload);
          if (payload.type) bus.emit(payload.type, payload);
        } catch { /* ignore */ }
      });
      // Раньше переподключение было только по «error»: при штатном закрытии соединения (перезапуск
      // PostgreSQL) приходит лишь «end» — и события между процессами молча переставали доходить
      client.on('error', () => reconnect(client, 3000));
      client.on('end', () => reconnect(client, 3000));
      await client.query(`LISTEN ${CHANNEL}`);
      const wasDown = listenerDownSince;
      listenClient = client; listenerDownSince = 0;
      // Пока слушатель лежал, события могли потеряться: даём подписчикам перечитать состояние
      if (wasDown) { bus.emit('listener.reconnected', { downMs: Date.now() - wasDown }); bus.emit('settings.changed', { type: 'settings.changed' }); }
    } catch (e) {
      if (!listenerDownSince) listenerDownSince = Date.now();
      console.error('LISTEN недоступен, повтор через 5 c:', e.message);
      reconnect(client, 5000);
      return;
    }
  };
  // «Полуоткрытое» соединение (обрыв сети без закрытия) не даёт ни error, ни end — проверяем сами
  setInterval(async () => {
    const c = listenClient;
    if (!c) return;
    try {
      await Promise.race([c.query('SELECT 1'), new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 10000))]);
    } catch {
      if (!listenerDownSince) listenerDownSince = Date.now();
      reconnect(c, 1000);
    }
  }, 60000).unref();
  await connect();
}

/** Опубликовать событие всем процессам (API/воркеры). Полезная нагрузка — до 8000 байт. */
export async function publish(payload) {
  try {
    const text = JSON.stringify(payload);
    if (text.length > 7900) return;
    await pool.query('SELECT pg_notify($1, $2)', [CHANNEL, text]);
  } catch (e) {
    console.error('publish failed:', e.message);
  }
}

export async function closeDb() {
  dbClosing = true;   // штатное закрытие — не переподключаемся
  try { if (listenClient) await listenClient.end(); } catch { /* ignore */ }
  await pool.end();
}
