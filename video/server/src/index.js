// Точка входа API-сервера.
import { config, assertConfig } from './config.js';
import { runMigrations } from './migrate.js';
import { loadSettings } from './lib/settings.js';
import { startListener, closeDb } from './db.js';
import { buildApp } from './app.js';
import { startLivePoller } from './routes/live.js';

assertConfig();

const app = await buildApp();
try {
  await runMigrations({ log: (m) => app.log.info(m) });
  await loadSettings(true);
  await startListener();
  await app.listen({ host: config.host, port: config.port });
  app.log.info({ url: `http://${config.host}:${config.port}`, baseUrl: config.baseUrl, version: config.version }, 'CorpVideo API запущен');
  startLivePoller(app);
} catch (e) {
  app.log.error({ err: e.message }, 'не удалось запустить сервер');
  process.exit(1);
}

let shuttingDown = false;
async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  app.log.info({ signal }, 'остановка сервера…');
  const t = setTimeout(() => process.exit(0), 10000);
  t.unref();
  try { await app.close(); } catch { /* ignore */ }
  try { await closeDb(); } catch { /* ignore */ }
  process.exit(0);
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('unhandledRejection', (e) => app.log.error({ err: e?.message || e }, 'unhandledRejection'));
