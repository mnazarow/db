// Простой мигратор: выполняет SQL-файлы из src/migrations по порядку, фиксируя их в schema_migrations.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { pool } from './db.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MIGRATIONS_DIR = path.join(__dirname, 'migrations');

export async function runMigrations({ log = console.log } = {}) {
  const client = await pool.connect();
  try {
    await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
      name text PRIMARY KEY,
      applied_at timestamptz NOT NULL DEFAULT now()
    )`);
    // Блокировка, чтобы два процесса не мигрировали одновременно
    await client.query('SELECT pg_advisory_lock(727272)');
    try {
      const applied = new Set((await client.query('SELECT name FROM schema_migrations')).rows.map((r) => r.name));
      const files = fs.readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith('.sql')).sort();
      let count = 0;
      for (const file of files) {
        if (applied.has(file)) continue;
        const sql = fs.readFileSync(path.join(MIGRATIONS_DIR, file), 'utf8');
        log(`Применяю миграцию ${file}…`);
        await client.query('BEGIN');
        try {
          await client.query(sql);
          await client.query('INSERT INTO schema_migrations(name) VALUES ($1)', [file]);
          await client.query('COMMIT');
          count++;
        } catch (e) {
          await client.query('ROLLBACK');
          throw new Error(`Миграция ${file} не выполнена: ${e.message}`);
        }
      }
      return count;
    } finally {
      await client.query('SELECT pg_advisory_unlock(727272)');
    }
  } finally {
    client.release();
  }
}
