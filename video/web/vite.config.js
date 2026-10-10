import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import fs from 'node:fs';
import path from 'node:path';

// Версия кэша service worker меняется с каждой сборкой: иначе браузеры держали старую
// «оболочку» приложения, пока пользователь не очистит данные сайта вручную.
function swVersion() {
  const pkg = JSON.parse(fs.readFileSync(new URL('./package.json', import.meta.url), 'utf8'));
  const build = `${pkg.version}-${Date.now().toString(36)}`;
  let outDir = 'dist';
  return {
    name: 'corpvideo-sw-version',
    apply: 'build',
    configResolved(cfg) { outDir = cfg.build.outDir; },
    writeBundle() {
      const file = path.resolve(outDir, 'sw.js');
      if (!fs.existsSync(file)) return;
      fs.writeFileSync(file, fs.readFileSync(file, 'utf8').replace(/__CV_BUILD__/g, build));
    },
  };
}

// Префикс пути портала в браузере: VITE_BASE=/video/ npm run build (портал встроен в общий сайт
// под /video). По умолчанию «/» — портал в корне сайта. Нормализуем: '/video/' — с косыми с обеих сторон.
function normBase(raw) {
  const p = String(raw || '').trim().replace(/^\/+|\/+$/g, '');
  return p ? `/${p}/` : '/';
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const base = normBase(process.env.VITE_BASE ?? env.VITE_BASE);
  // Сервер приложения префикса не знает (в production его срезает nginx) — в разработке срезает прокси Vite
  const strip = base === '/' ? undefined : (p) => p.slice(base.length - 1);
  return {
    base,
    plugins: [vue(), swVersion()],
    server: {
      port: 5173,
      proxy: {
        [`${base}api`]: { target: 'http://127.0.0.1:3000', changeOrigin: false, rewrite: strip },
        [`${base}media`]: { target: 'http://127.0.0.1:3000', changeOrigin: false, rewrite: strip },
        [`${base}ws`]: { target: 'ws://127.0.0.1:3000', ws: true, rewrite: strip },
      },
    },
    build: {
      outDir: 'dist',
      sourcemap: false,
      chunkSizeWarningLimit: 1500,
      rollupOptions: {
        output: {
          manualChunks: {
            vendor: ['vue', 'vue-router', 'pinia'],
            hls: ['hls.js'],
          },
        },
      },
    },
  };
});
