// Состояние интерфейса: тема, боковая панель, уведомления-тосты, мини-плеер, диалоги.
import { defineStore } from 'pinia';

function readLS(key, def) {
  try { const v = localStorage.getItem(key); return v === null ? def : v; } catch { return def; }
}
function writeLS(key, val) {
  try { localStorage.setItem(key, val); } catch { /* ignore */ }
}

export const useUi = defineStore('ui', {
  state: () => ({
    theme: readLS('cv:theme', 'light'),
    highContrast: readLS('cv:a11y:contrast', '0') === '1',   // 1.4: режим повышенной контрастности
    sidebarCollapsed: readLS('cv:sidebar', '0') === '1',
    sidebarOpen: false, // мобильный ящик
    toasts: [],
    miniPlayer: null, // { video, position, playing }
    theater: readLS('cv:theater', '0') === '1',
    autoplay: readLS('cv:autoplay', '1') === '1',
    confirm: null,
    installPrompt: null, // событие beforeinstallprompt (PWA)
    queue: (() => { try { return JSON.parse(sessionStorage.getItem('cv:queue') || '[]'); } catch { return []; } })(), // очередь просмотра (1.3): [{id, shortId, title, thumbnailUrl, duration, owner}]
  }),
  getters: {
    effectiveTheme: (s) => (s.theme === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : s.theme),
  },
  actions: {
    setTheme(t) {
      this.theme = t;
      writeLS('cv:theme', t);
      document.documentElement.setAttribute('data-theme', this.effectiveTheme);
    },
    toggleTheme() { this.setTheme(this.effectiveTheme === 'dark' ? 'light' : 'dark'); },
    setHighContrast(v) {
      this.highContrast = !!v;
      writeLS('cv:a11y:contrast', this.highContrast ? '1' : '0');
      document.documentElement.classList.toggle('high-contrast', this.highContrast);
    },
    toggleSidebar() {
      if (window.innerWidth < 1024) this.sidebarOpen = !this.sidebarOpen;
      else { this.sidebarCollapsed = !this.sidebarCollapsed; writeLS('cv:sidebar', this.sidebarCollapsed ? '1' : '0'); }
    },
    closeSidebar() { this.sidebarOpen = false; },
    setInstallPrompt(e) { this.installPrompt = e; },
    async installApp() {
      const e = this.installPrompt; if (!e) return false;
      e.prompt(); const r = await e.userChoice.catch(() => null);
      this.installPrompt = null;
      return r?.outcome === 'accepted';
    },
    toast(message, { type = 'info', timeout = 4000, action = null } = {}) {
      const id = Date.now() + Math.random();
      this.toasts.push({ id, message, type, action });
      if (timeout) setTimeout(() => this.dismissToast(id), timeout);
      return id;
    },
    dismissToast(id) { this.toasts = this.toasts.filter((t) => t.id !== id); },
    setTheater(v) { this.theater = v; writeLS('cv:theater', v ? '1' : '0'); },
    setAutoplay(v) { this.autoplay = v; writeLS('cv:autoplay', v ? '1' : '0'); },
    // Очередь просмотра — как «Добавить в очередь» на YouTube: играет после текущего видео
    saveQueue() { try { sessionStorage.setItem('cv:queue', JSON.stringify(this.queue)); } catch { /* ignore */ } },
    queueAdd(v) {
      if (!v || this.queue.some((x) => x.id === v.id)) return false;
      this.queue.push({ id: v.id, shortId: v.shortId, title: v.title, thumbnailUrl: v.thumbnailUrl, duration: v.duration, owner: v.owner ? { displayName: v.owner.displayName } : null });
      this.saveQueue(); return true;
    },
    queueRemove(id) { this.queue = this.queue.filter((x) => x.id !== id); this.saveQueue(); },
    queueShift() { const v = this.queue.shift(); this.saveQueue(); return v || null; },
    queueClear() { this.queue = []; this.saveQueue(); },
    openMini(video, position, playing) { this.miniPlayer = { video, position, playing }; },
    closeMini() { this.miniPlayer = null; },
    /** Диалог подтверждения: возвращает Promise<boolean>. */
    ask({ title, message, okLabel = 'Подтвердить', cancelLabel = 'Отмена', danger = false }) {
      return new Promise((resolve) => {
        this.confirm = { title, message, okLabel, cancelLabel, danger, resolve };
      });
    },
    answer(v) { const c = this.confirm; this.confirm = null; c?.resolve(v); },
  },
});
