// Состояние пользователя и публичной конфигурации.
import { defineStore } from 'pinia';
import { get, post } from '../api.js';
import { url } from '../base.js';

/**
 * Выход через портал документации: форма выхода портала отправляется обычным переходом браузера
 * (портал завершает сессию и показывает страницу входа). Без данных формы — просто на главную портала.
 */
function submitPortalLogout(logout, fallback) {
  if (!logout?.url || !logout?.token) { window.location.assign(fallback || '/'); return; }
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = logout.url;
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = logout.field || '_csrf_token';
  input.value = logout.token;
  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
}

export const useAuth = defineStore('auth', {
  state: () => ({
    user: null,
    totpRequired: false,
    unread: 0,
    assignmentsPending: 0,
    watchLaterId: null,
    config: null,
    loaded: false,
    // Раздел «Видео» портала документации: ссылки на портал, форма выхода, почему не вошёл
    portal: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.user,
    isActive: (s) => !!s.user && s.user.status === 'active',
    isStaff: (s) => !!s.user && ['admin', 'moderator'].includes(s.user.role),
    isAdmin: (s) => !!s.user && s.user.role === 'admin',
    canUpload: (s) => !!s.config?.permissions?.canUpload,
    canStream: (s) => !!s.config?.permissions?.canStream,
    canAssign: (s) => !!s.user && s.user.status === 'active' && (['admin', 'moderator'].includes(s.user.role) || s.config?.assignmentsWhoCanAssign === 'all'),
    siteName: (s) => s.config?.siteName || 'Видео',
    // Вход, выход и роли — общие с порталом документации
    isPortal: (s) => !!(s.config?.portal?.enabled || s.portal?.enabled),
    portalHome: (s) => s.portal?.home || s.config?.portal?.home || '/',
    portalName: (s) => s.portal?.name || s.config?.portal?.name || 'Портал документации',
    /** Роль так, как её называет портал: маркетолог, помощник маркетолога… */
    roleLabel: (s) => {
      const u = s.user;
      if (!u) return '';
      if (u.role === 'admin') return 'Администратор';
      if (s.config?.portal?.enabled) {
        if (u.role === 'moderator') return 'Маркетолог';
        if (s.config?.permissions?.canUpload) return 'Помощник маркетолога';
        return '';
      }
      return u.role === 'moderator' ? 'Модератор' : '';
    },
  },
  actions: {
    async load() {
      try {
        const [me, cfg] = await Promise.all([get('/api/auth/me'), get('/api/config')]);
        this.user = me.user;
        this.totpRequired = !!me.totpRequired;
        this.unread = me.unread || 0;
        this.assignmentsPending = me.assignmentsPending || 0;
        this.watchLaterId = me.watchLaterId || null;
        this.config = cfg;
        this.portal = me.portal || cfg.portal || null;
        try { localStorage.setItem('cv.config', JSON.stringify(cfg)); } catch { /* приватный режим */ }
      } catch (e) {
        // Без сети берём последние известные настройки: нужны разделу «Скачанные»
        try { const cached = localStorage.getItem('cv.config'); if (cached && !this.config) this.config = JSON.parse(cached); } catch { /* ignore */ }
        if (typeof navigator !== 'undefined' && navigator.onLine === false) console.info('Сети нет — доступны скачанные видео');
        else console.error('auth load failed', e);
      } finally {
        this.loaded = true;
      }
    },
    async refreshConfig() {
      this.config = await get('/api/config');
    },
    /** Вход через портал: страница входа портала вернёт обратно на эту страницу видео. */
    portalLogin(next = '/') {
      const login = this.portal?.login || this.config?.portal?.login || '/login';
      window.location.assign(`${login}?next=${encodeURIComponent(url(String(next || '/')))}`);
    },
    async login(login, password) {
      const r = await post('/api/auth/login', { login, password });
      if (r.totpRequired) { this.totpRequired = true; return r; }
      this.user = r.user;
      this.totpRequired = false;
      await this.load();
      return r;
    },
    async totp(code) {
      const r = await post('/api/auth/totp', { code });
      this.user = r.user;
      this.totpRequired = false;
      await this.load();
      return r;
    },
    async logout() {
      const r = await post('/api/auth/logout', {});
      this.user = null;
      this.unread = 0;
      // Следы прошлого входа: скачанные видео и запомненная конфигурация с его правами
      try { const { clearOffline } = await import('../utils/offline.js'); await clearOffline(); } catch { /* ignore */ }
      try { localStorage.removeItem('cv.config'); } catch { /* ignore */ }
      if (this.isPortal) {
        // Выход общий: завершаем сессию портала (видео узнаёт о выходе по ней же)
        submitPortalLogout(r?.portal?.logout || this.portal?.logout, r?.portal?.home || this.portalHome);
        return;
      }
      await this.refreshConfig();
    },
    setUser(u) { this.user = u; },
  },
});
