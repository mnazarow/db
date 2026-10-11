// Файловое хранилище: расположение файлов и служебные операции.
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { config } from '../config.js';
import { mediaUrl } from './urls.js';

const execFileP = promisify(execFile);

export const storage = {
  root: config.mediaDir,

  videoDir: (videoId) => path.join(config.mediaDir, 'videos', videoId),
  hlsDir: (videoId) => path.join(config.mediaDir, 'videos', videoId, 'hls'),
  thumbsDir: (videoId) => path.join(config.mediaDir, 'videos', videoId, 'thumbs'),
  subsDir: (videoId) => path.join(config.mediaDir, 'videos', videoId, 'subs'),
  storyboardDir: (videoId) => path.join(config.mediaDir, 'videos', videoId, 'storyboard'),
  avatarsDir: () => path.join(config.mediaDir, 'avatars'),
  bannersDir: () => path.join(config.mediaDir, 'banners'),
  liveDir: (streamId) => path.join(config.mediaDir, 'live', streamId),
  uploadTmp: (uploadId) => path.join(config.uploadTmpDir, `${uploadId}.part`),

  /** Относительный путь (для БД/URL) из абсолютного. */
  rel: (abs) => path.relative(config.mediaDir, abs).split(path.sep).join('/'),
  /** Абсолютный путь из относительного. Защита от выхода за пределы каталога. */
  abs: (rel) => {
    const p = path.resolve(config.mediaDir, rel);
    if (!p.startsWith(path.resolve(config.mediaDir) + path.sep)) throw new Error('Недопустимый путь');
    return p;
  },
  /** Адрес для браузера — с префиксом пути портала (BASE_PATH). */
  url: (rel) => mediaUrl(rel),
  /**
   * Адрес для X-Accel-Redirect, если API стоит за nginx; null — файл отдаёт сам Node.
   * В заголовке допустим только ASCII, а имена материалов бывают кириллицей, поэтому путь кодируется
   * по сегментам — nginx раскодирует %XX сам. Пути с «%» и «?» отдаём из Node: после раскодирования
   * nginx понял бы их по-своему (повторное раскодирование, начало строки аргументов).
   */
  accel: (req, rel) => {
    if (!(req.headers['x-accel-supported'] === '1' || process.env.NGINX_ACCEL === '1')) return null;
    const p = String(rel || '');
    if (!p || /[%?\0\r\n]/.test(p) || p.split('/').some((seg) => seg === '' || seg === '.' || seg === '..')) return null;
    // Внутренний location nginx (internal) — уникальное имя без префикса BASE_PATH: портал может
    // делить сервер nginx с другими приложениями (см. deploy/nginx/locations.conf.template)
    return '/__corpvideo_internal_media/' + p.split('/').map(encodeURIComponent).join('/');
  },
};

export async function ensureDir(dir) {
  await fsp.mkdir(dir, { recursive: true });
  return dir;
}

export async function exists(p) {
  try { await fsp.access(p); return true; } catch { return false; }
}

export async function removeDir(dir) {
  if (!dir) return;
  const resolved = path.resolve(dir);
  const root = path.resolve(config.mediaDir);
  const tmp = path.resolve(config.uploadTmpDir);
  const live = path.resolve(config.liveRecordDir);
  if (![root, tmp, live].some((r) => resolved.startsWith(r + path.sep))) throw new Error('Отказ удалять вне каталога данных: ' + dir);
  await fsp.rm(resolved, { recursive: true, force: true });
}

export async function removeFile(p) {
  try { await fsp.unlink(p); } catch { /* ignore */ }
}

/** Размер каталога в байтах. */
export async function dirSize(dir) {
  let total = 0;
  let entries;
  try { entries = await fsp.readdir(dir, { withFileTypes: true }); } catch { return 0; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) total += await dirSize(p);
    else if (e.isFile()) { try { total += (await fsp.stat(p)).size; } catch { /* ignore */ } }
  }
  return total;
}

/** Свободное/общее место на диске с данными. */
export async function diskUsage(dir = config.mediaDir) {
  try {
    const st = await fsp.statfs(dir);
    return { total: st.blocks * st.bsize, free: st.bavail * st.bsize, used: (st.blocks - st.bfree) * st.bsize };
  } catch {
    try {
      const { stdout } = await execFileP('df', ['-kP', dir]);
      const line = stdout.trim().split('\n').pop().split(/\s+/);
      return { total: +line[1] * 1024, used: +line[2] * 1024, free: +line[3] * 1024 };
    } catch { return { total: 0, free: 0, used: 0 }; }
  }
}

export function fileStream(p, opts) {
  return fs.createReadStream(p, opts);
}
