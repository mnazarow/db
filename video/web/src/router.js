import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './stores/auth.js';

const views = import.meta.glob('./views/**/*.vue');
const v = (p) => views[`./views/${p}.vue`];

export const router = createRouter({
  // Префикс пути портала (VITE_BASE, например /video/) — база истории: пути маршрутов остаются прежними
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, from, saved) {
    if (saved) return saved;
    if (to.hash) return { el: to.hash };
    if (to.name === from.name && to.name !== 'watch') return false;
    return { top: 0 };
  },
  routes: [
    { path: '/', name: 'home', component: v('HomeView') },
    { path: '/trending', name: 'trending', component: v('TrendingView'), meta: { feature: 'trending.enabled' } },
    { path: '/latest', name: 'latest', component: v('LatestView') },
    { path: '/shorts', name: 'shorts', component: v('ShortsView'), meta: { feature: 'shorts.enabled' } },
    { path: '/subscriptions', name: 'subscriptions', component: v('SubscriptionsView'), meta: { feature: 'subscriptions.enabled',  active: true } },
    { path: '/live', name: 'live', component: v('LiveListView'), meta: { feature: 'live.enabled' } },
    { path: '/live/:id', name: 'live-watch', component: v('LiveWatchView') },
    { path: '/category/:slug', name: 'category', component: v('CategoryView') },
    // 1.9: витрина-подборка со своим адресом
    { path: '/hub/:slug', name: 'showcase', component: v('ShowcaseView') },
    // 1.10: раздел «Вебинары»
    { path: '/webinars', name: 'webinars', component: v('WebinarsView'), meta: { feature: 'webinars.enabled' } },
    { path: '/webinar/:slug', name: 'webinar', component: v('WebinarView') },
    { path: '/tag/:tag', name: 'tag', component: v('TagView') },
    { path: '/watch/:id', name: 'watch', component: v('WatchView') },
    { path: '/search', name: 'search', component: v('SearchView') },
    { path: '/@:handle', name: 'channel', component: v('ChannelView') },
    { path: '/channel/:handle', redirect: (to) => `/@${to.params.handle}` },
    { path: '/playlist/:id', name: 'playlist', component: v('PlaylistView') },
    { path: '/library', name: 'library', component: v('LibraryView'), meta: { active: true } },
    { path: '/history', name: 'history', component: v('HistoryView'), meta: { feature: 'history.enabled',  active: true } },
    { path: '/watch-later', name: 'watch-later', component: v('WatchLaterView'), meta: { feature: 'playlists.enabled',  active: true } },
    { path: '/liked', name: 'liked', component: v('LikedView'), meta: { feature: 'likes.enabled',  active: true } },
    { path: '/notifications', name: 'notifications', component: v('NotificationsView'), meta: { auth: true } },
    { path: '/assignments', name: 'assignments', component: v('AssignmentsView'), meta: { feature: 'assignments.enabled',  active: true } },
    { path: '/courses', name: 'courses', component: v('CoursesView'), meta: { feature: 'courses.enabled' } },
    { path: '/course/:id', name: 'course', component: v('CourseView') },
    { path: '/notes', name: 'notes', component: v('NotesView'), meta: { feature: 'notes.enabled',  active: true } },
    { path: '/certificates', name: 'certificates', component: v('CertificatesView'), meta: { feature: 'certificates.enabled',  active: true } },
    { path: '/cert/:id', name: 'certificate', component: v('CertificateView') },
    { path: '/s/:token', name: 'share', component: v('ShareView'), meta: { plain: true } },
    { path: '/settings/:tab?', name: 'settings', component: v('SettingsView'), meta: { auth: true } },
    { path: '/login', name: 'login', component: v('auth/LoginView'), meta: { guestOnly: true, plain: true } },
    { path: '/register', name: 'register', component: v('auth/RegisterView'), meta: { guestOnly: true, plain: true } },
    { path: '/verify-email', name: 'verify-email', component: v('auth/VerifyEmailView'), meta: { plain: true } },
    { path: '/forgot-password', name: 'forgot', component: v('auth/ForgotView'), meta: { plain: true } },
    { path: '/reset-password', name: 'reset', component: v('auth/ResetView'), meta: { plain: true } },
    { path: '/invite/:token', name: 'invite', component: v('auth/InviteView'), meta: { plain: true } },
    { path: '/account-status', name: 'account-status', component: v('auth/StatusView'), meta: { auth: true, plain: true } },
    { path: '/embed/:id', name: 'embed', component: v('EmbedView'), meta: { embed: true } },
    // 1.6: комната совместного просмотра и витрина для телевизора в холле
    { path: '/party/:code', name: 'party', component: v('PartyView'), meta: { active: true } },
    // «Скачанные» открываются и без сети: список и файлы лежат на устройстве
    { path: '/offline', name: 'offline', component: v('OfflineView') },
    { path: '/screen/:token', name: 'screen', component: v('ScreenView'), meta: { embed: true } },
    {
      path: '/studio', component: v('studio/StudioLayout'), meta: { active: true },
      children: [
        { path: '', name: 'studio', component: v('studio/StudioDashboard') },
        { path: 'videos', name: 'studio-videos', component: v('studio/StudioVideos') },
        { path: 'upload', name: 'studio-upload', component: v('studio/StudioUpload'), meta: { needUpload: true } },
        { path: 'videos/:id/:tab?', name: 'studio-video', component: v('studio/StudioVideoEdit') },
        { path: 'comments', name: 'studio-comments', component: v('studio/StudioComments'), meta: { feature: 'comments.enabled' } },
        { path: 'playlists', name: 'studio-playlists', component: v('studio/StudioPlaylists'), meta: { feature: 'playlists.enabled' } },
        { path: 'analytics', name: 'studio-analytics', component: v('studio/StudioAnalytics') },
        { path: 'channel', name: 'studio-channel', component: v('studio/StudioChannel') },
        { path: 'live', name: 'studio-live', component: v('studio/StudioLive'), meta: { feature: 'live.enabled', needStream: true } },
        { path: 'live/:id', name: 'studio-live-edit', component: v('studio/StudioLiveEdit'), meta: { feature: 'live.enabled', needStream: true } },
        { path: 'assignments/:id?', name: 'studio-assignments', component: v('studio/StudioAssignments'), meta: { feature: 'assignments.enabled', needAssign: true } },
        { path: 'courses', name: 'studio-courses', component: v('studio/StudioCourses'), meta: { feature: 'courses.enabled', needAssign: true } },
        { path: 'reviews', name: 'studio-reviews', component: v('studio/StudioReviews'), meta: { feature: 'review.enabled' } },
        { path: 'calendar', name: 'studio-calendar', component: v('studio/StudioCalendar'), meta: { feature: 'calendar.enabled' } },
        { path: 'webinars', name: 'studio-webinars', component: v('studio/StudioWebinars'), meta: { feature: 'webinars.enabled', needStream: true } },
        { path: 'webinars/:id', name: 'studio-webinar', component: v('studio/StudioWebinarEdit'), meta: { feature: 'webinars.enabled', needStream: true } },
        { path: 'courses/:id', name: 'studio-course', component: v('studio/StudioCourseEdit'), meta: { feature: 'courses.enabled', needAssign: true } },
        { path: 'record', name: 'studio-record', component: v('studio/StudioRecord'), meta: { needUpload: true } },
      ],
    },
    {
      path: '/admin', component: v('admin/AdminLayout'), meta: { staff: true },
      children: [
        { path: '', name: 'admin', component: v('admin/AdminDashboard') },
        { path: 'users', name: 'admin-users', component: v('admin/AdminUsers') },
        { path: 'users/:id', name: 'admin-user', component: v('admin/AdminUserDetail') },
        { path: 'domains', name: 'admin-domains', component: v('admin/AdminDomains') },
        { path: 'groups', name: 'admin-groups', component: v('admin/AdminGroups') },
        { path: 'videos', name: 'admin-videos', component: v('admin/AdminVideos') },
        { path: 'moderation', name: 'admin-moderation', component: v('admin/AdminModeration') },
        { path: 'comments', name: 'admin-comments', component: v('admin/AdminComments') },
        { path: 'reports', name: 'admin-reports', component: v('admin/AdminReports') },
        { path: 'categories', name: 'admin-categories', component: v('admin/AdminCategories') },
        { path: 'live', name: 'admin-live', component: v('admin/AdminLive') },
        { path: 'learning', name: 'admin-learning', component: v('admin/AdminLearning') },
        { path: 'quality', name: 'admin-quality', component: v('admin/AdminQuality') },
        { path: 'screens', name: 'admin-screens', component: v('admin/AdminScreens') },
        { path: 'storage', name: 'admin-storage', component: v('admin/AdminStorage') },
        { path: 'showcases', name: 'admin-showcases', component: v('admin/AdminShowcases'), meta: { admin: true } },
        { path: 'lifecycle', name: 'admin-lifecycle', component: v('admin/AdminLifecycle') },
        { path: 'features', name: 'admin-features', component: v('admin/AdminFeatures'), meta: { admin: true } },
        { path: 'settings/:tab?', name: 'admin-settings', component: v('admin/AdminSettings'), meta: { admin: true } },
        { path: 'jobs', name: 'admin-jobs', component: v('admin/AdminJobs') },
        { path: 'audit', name: 'admin-audit', component: v('admin/AdminAudit') },
        { path: 'system', name: 'admin-system', component: v('admin/AdminSystem') },
      ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: v('NotFoundView') },
  ],
});

router.beforeEach(async (to) => {
  const auth = useAuth();
  if (!auth.loaded) await auth.load();
  const needsAuth = to.matched.some((r) => r.meta.auth || r.meta.active || r.meta.staff || r.meta.admin);
  // Без сети не отправляем на страницу входа: она всё равно не откроется, а «Скачанные» работают
  if (needsAuth && !auth.user && typeof navigator !== 'undefined' && navigator.onLine === false) return { name: 'offline' };
  // Раздел «Видео» портала документации: входа у видео нет — вход через портал с возвратом на эту страницу
  const PORTAL_AUTH_PAGES = ['login', 'register', 'verify-email', 'forgot', 'reset', 'invite'];
  if (auth.isPortal && PORTAL_AUTH_PAGES.includes(to.name)) {
    if (auth.user) return to.query.next ? String(to.query.next) : { name: 'home' };
    auth.portalLogin(String(to.query.next || '/'));
    return false;
  }
  if (needsAuth && !auth.user && auth.isPortal) { auth.portalLogin(to.fullPath); return false; }
  if (needsAuth && !auth.user) return { name: 'login', query: { next: to.fullPath } };
  if (to.matched.some((r) => r.meta.active || r.meta.staff || r.meta.admin) && auth.user && auth.user.status !== 'active') return { name: 'account-status' };
  if (to.matched.some((r) => r.meta.staff) && !auth.isStaff) return { name: 'home' };
  if (to.matched.some((r) => r.meta.admin) && !auth.isAdmin) return { name: 'admin' };
  if (to.meta.guestOnly && auth.user) return to.query.next ? String(to.query.next) : { name: 'home' };
  // Выключенная возможность: страницу не открываем совсем, а не показываем пустой экран
  if (to.meta.feature && auth.config?.features?.[to.meta.feature] === false) return { name: 'home' };
  // Права студии проверяем до открытия страницы: раньше человек без права загрузки
  // мог зайти по прямому адресу, выбрать файл и узнать об отказе только в конце
  if (to.meta.needUpload && auth.user && !auth.canUpload) return { name: 'studio' };
  if (to.meta.needStream && auth.user && !auth.canStream) return { name: 'studio' };
  if (to.meta.needAssign && auth.user && !auth.canAssign) return { name: 'studio' };
  return true;
});

router.afterEach((to) => {
  const auth = useAuth();
  const site = auth.siteName;
  const titles = {
    home: site, trending: 'Тренды', latest: 'Новые видео', shorts: 'Короткие видео', subscriptions: 'Подписки', live: 'Трансляции', library: 'Библиотека', history: 'История',
    'watch-later': 'Смотреть позже', liked: 'Понравившиеся', notifications: 'Уведомления', settings: 'Настройки', login: 'Вход', register: 'Регистрация', search: 'Поиск',
    studio: 'Студия', 'studio-videos': 'Мои видео', 'studio-upload': 'Загрузка', admin: 'Администрирование', assignments: 'Назначено вам', notes: 'Мои заметки',
    'studio-assignments': 'Обязательные просмотры', webinars: 'Вебинары', 'studio-webinars': 'Мои вебинары', 'studio-record': 'Запись экрана', 'admin-groups': 'Группы сотрудников', share: 'Доступ по ссылке', certificates: 'Мои сертификаты',
  };
  const t = titles[to.name];
  if (t && to.name !== 'watch' && to.name !== 'channel') document.title = t === site ? site : `${t} — ${site}`;
});
