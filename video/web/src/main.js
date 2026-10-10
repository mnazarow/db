import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router.js';
import Icon from './components/Icon.vue';
import { url } from './base.js';
import '@fontsource/roboto/cyrillic-400.css';
import '@fontsource/roboto/cyrillic-500.css';
import '@fontsource/roboto/cyrillic-700.css';
import '@fontsource/roboto/latin-400.css';
import '@fontsource/roboto/latin-500.css';
import '@fontsource/roboto/latin-700.css';
import '@fontsource/oswald/cyrillic-400.css';
import '@fontsource/oswald/cyrillic-500.css';
import '@fontsource/oswald/cyrillic-700.css';
import '@fontsource/oswald/latin-400.css';
import '@fontsource/oswald/latin-500.css';
import '@fontsource/oswald/latin-700.css';
import './styles/tokens.css';
import './styles/base.css';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.component('Icon', Icon);
// $url('/api/…') в шаблонах: путь с префиксом портала (VITE_BASE), см. base.js
app.config.globalProperties.$url = url;
app.mount('#app');

// PWA: предложение установки приложения (Chrome/Edge/Android)
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); import('./stores/ui.js').then(({ useUi }) => useUi().setInstallPrompt(e)); });
// PWA: service worker (только в production-сборке и по https/localhost)
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  // Область — весь портал (с префиксом пути, если он задан): /sw.js или /video/sw.js
  window.addEventListener('load', () => { navigator.serviceWorker.register(url('/sw.js'), { scope: url('/') }).catch(() => {}); });
}
