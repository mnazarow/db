// Реестр возможностей портала: что можно выключить, что при этом исчезает и чем оно пользуется.
// Одно место на весь портал: по нему строится раздел «Возможности» в панели администратора,
// по нему же закрываются маршруты API, чтобы выключение не было косметическим.

/**
 * Группы и пункты. У пункта:
 *   key    — ключ настройки (см. lib/settings.js)
 *   title  — как называется в интерфейсе
 *   desc   — что именно перестанет работать
 *   needs  — внешние зависимости (показываются подсказкой)
 *   deps   — другие ключи, без которых пункт бесполезен
 *   stat   — SQL-выражение «сколько этим пользовались» (одно число), либо null
 */
export const FEATURE_GROUPS = [
  {
    id: 'sections',
    title: 'Разделы портала',
    hint: 'Пункты левого меню. Выключенный раздел исчезает из меню, а его страницы и запросы к нему перестают работать.',
    items: [
      { key: 'live.enabled', title: 'Трансляции', desc: 'Прямые эфиры из OBS и браузера, чат эфира, записи эфиров. Раздел «Трансляции» и «Студия → Трансляции».', needs: ['MediaMTX'], stat: 'SELECT count(*)::int FROM live_streams' },
      { key: 'webinars.enabled', title: 'Вебинары', desc: 'Каталог мероприятий, страницы со своим адресом, анкеты регистрации, напоминания и сертификаты участникам.', stat: 'SELECT count(*)::int FROM live_streams WHERE webinar' },
      { key: 'courses.enabled', title: 'Курсы', desc: 'Учебные программы из видео и материалов, прохождение по шагам, отчёты и сертификаты.', stat: 'SELECT count(*)::int FROM courses' },
      { key: 'shorts.enabled', title: 'Короткие', desc: 'Вертикальная лента коротких роликов.', stat: "SELECT count(*)::int FROM videos WHERE is_short AND deleted_at IS NULL" },
      { key: 'trending.enabled', title: 'Тренды', desc: 'Страница «Тренды» с популярным за две недели.', stat: null },
      { key: 'subscriptions.enabled', title: 'Подписки', desc: 'Подписка на каналы коллег и лента подписок.', stat: 'SELECT count(*)::int FROM subscriptions' },
      { key: 'playlists.enabled', title: 'Плейлисты', desc: 'Плейлисты и «Смотреть позже». Курсы и назначения плейлистами больше не пользуются.', stat: 'SELECT count(*)::int FROM playlists' },
      { key: 'notes.enabled', title: 'Мои заметки', desc: 'Личные заметки к секундам видео.', stat: 'SELECT count(*)::int FROM user_notes' },
      { key: 'history.enabled', title: 'История просмотров', desc: 'Раздел «История» и запоминание места остановки. Аналитика видео считается по просмотрам и не зависит от этого.', stat: 'SELECT count(*)::int FROM video_views' },
      { key: 'likes.enabled', title: 'Лайки и «Понравившиеся»', desc: 'Оценка видео и раздел с понравившимся.', stat: 'SELECT count(*)::int FROM video_likes' },
      { key: 'comments.enabled', title: 'Комментарии', desc: 'Обсуждение под видео, ответы, закрепление, жалобы на комментарии.', stat: 'SELECT count(*)::int FROM comments' },
      { key: 'showcases.enabled', title: 'Витрины', desc: 'Страницы-подборки со своим адресом вида /hub/адрес.', stat: 'SELECT count(*)::int FROM showcases' },
      { key: 'screens.enabled', title: 'Экраны для телевизоров', desc: 'Витрины-плейлисты для экранов в холле, страница /screen.', stat: 'SELECT count(*)::int FROM screens' },
    ],
  },
  {
    id: 'learning',
    title: 'Обучение и контроль',
    hint: 'Обязательные просмотры, проверка знаний и всё, что связано с отчётностью по обучению.',
    items: [
      { key: 'assignments.enabled', title: 'Обязательные просмотры', desc: 'Назначение видео и плейлистов сотрудникам со сроком, отчёты «кто посмотрел», напоминания. Раздел «Назначено мне».', stat: 'SELECT count(*)::int FROM assignments' },
      { key: 'quiz.enabled', title: 'Тесты внутри видео', desc: 'Вопросы по ходу ролика, проходной балл, результаты сотрудников.', stat: 'SELECT count(*)::int FROM video_quizzes' },
      { key: 'certificates.enabled', title: 'Сертификаты', desc: 'Выдача сертификатов за курсы, назначения и участие в вебинарах; раздел «Сертификаты».', stat: 'SELECT count(*)::int FROM certificates' },
      { key: 'scenario.enabled', title: 'Тренажёры с ветвлением', desc: 'Видео с выбором действия и отчётом по развилкам.', stat: 'SELECT count(*)::int FROM video_scenarios' },
      { key: 'review.enabled', title: 'Согласование видео', desc: 'Отправка на проверку коллегам, замечания по таймкодам, решения. Раздел «Студия → Согласования».', stat: 'SELECT count(*)::int FROM video_reviews' },
      { key: 'xapi.enabled', title: 'Отправка результатов в LMS (xAPI)', desc: 'Передача результатов обучения во внешнюю LMS.', needs: ['адрес LRS'], stat: null },
    ],
  },
  {
    id: 'studio',
    title: 'Инструменты автора',
    hint: 'Что доступно в студии при работе с уже загруженным видео.',
    items: [
      { key: 'editor.enabled', title: 'Редактор видео', desc: 'Обрезка, вырезание фрагментов, удаление пауз, клипы. Без него выключаются и монтаж, и размытие.', stat: null },
      { key: 'editor.timeline', title: 'Графический видеоредактор', desc: 'Таймлайн с фрагментами, переходами, титрами, картинками и музыкой.', deps: ['editor.enabled'], stat: 'SELECT count(*)::int FROM video_projects' },
      { key: 'editor.blur_enabled', title: 'Размытие в кадре', desc: 'Размытие лиц и произвольных областей.', deps: ['editor.enabled'], stat: null },
      { key: 'editor.faces_enabled', title: 'Автопоиск лиц', desc: 'Портал сам находит лица для размытия.', needs: ['python3 с opencv'], deps: ['editor.blur_enabled'], stat: null },
      { key: 'editor.text_edit', title: 'Монтаж по расшифровке', desc: 'Вырезание фраз и слов-паразитов выделением текста.', deps: ['editor.enabled'], stat: null },
      { key: 'attachments.enabled', title: 'Материалы к видео', desc: 'Файлы-вложения рядом с плеером (презентации, документы).', stat: 'SELECT count(*)::int FROM video_attachments' },
      { key: 'share_links.enabled', title: 'Защищённые ссылки', desc: 'Показ видео внешним зрителям без учётной записи: пароль, срок, лимит открытий.', stat: 'SELECT count(*)::int FROM share_links' },
      { key: 'offline.enabled', title: 'Просмотр без сети', desc: 'Скачивание видео в браузер и раздел «Скачанные».', stat: null },
      { key: 'feeds.enabled', title: 'RSS и подкаст-ленты', desc: 'Ленты каналов, категорий, плейлистов и подписок.', stat: null },
      { key: 'seo.video', title: 'Видимость публичных видео поисковикам', desc: 'Разметка, карта сайта и oEmbed для публичных роликов.', stat: null },
    ],
  },
  {
    id: 'engage',
    title: 'Вовлечение и совместный просмотр',
    hint: 'Всё, что происходит вокруг просмотра.',
    items: [
      { key: 'premiere.enabled', title: 'Премьеры', desc: 'Показ видео по расписанию с обратным отсчётом и чатом.', stat: 'SELECT count(*)::int FROM videos WHERE premiere' },
      { key: 'party.enabled', title: 'Смотрим вместе', desc: 'Комната совместного просмотра с общей паузой и чатом.', stat: 'SELECT count(*)::int FROM watch_parties' },
      { key: 'engage.reactions', title: 'Реакции по таймкоду', desc: 'Эмодзи-реакции к конкретным секундам ролика.', stat: 'SELECT count(*)::int FROM video_reactions' },
      { key: 'engage.heatmap', title: 'Тепловая карта пересмотров', desc: 'Подсветка часто пересматриваемых мест на полосе прогресса.', stat: null },
      { key: 'live.chat_enabled', title: 'Чат эфира', desc: 'Сообщения зрителей во время трансляции, модерация и бан.', deps: ['live.enabled'], stat: 'SELECT count(*)::int FROM live_chat_messages' },
      { key: 'live.whip_enabled', title: 'Эфир прямо из браузера', desc: 'Трансляция без OBS (WebRTC/WHIP).', deps: ['live.enabled'], needs: ['MediaMTX'], stat: null },
      { key: 'live.restream_enabled', title: 'Ретрансляция эфира', desc: 'Копия эфира на VK Видео, YouTube, Rutube.', deps: ['live.enabled'], stat: 'SELECT count(*)::int FROM live_restreams' },
      { key: 'live.dvr', title: 'Перемотка эфира назад', desc: 'Кнопки «назад на 30 секунд» и «с начала эфира».', deps: ['live.enabled'], needs: ['MediaMTX'], stat: null },
    ],
  },
  {
    id: 'ai',
    title: 'Распознавание речи и ИИ-помощник',
    hint: 'Работают только при подключённых внешних службах. Если служб нет, эти пункты лучше держать выключенными — иначе задания копятся в очереди.',
    items: [
      { key: 'asr.enabled', title: 'Распознавание речи (субтитры)', desc: 'Автоматические субтитры и расшифровка. Без него остаётся только загрузка SRT/VTT вручную.', needs: ['сервер ASR'], stat: "SELECT count(*)::int FROM subtitles WHERE kind = 'auto'" },
      { key: 'live.captions', title: 'Живые субтитры эфира', desc: 'Распознавание речи по ходу трансляции.', deps: ['asr.enabled', 'live.enabled'], needs: ['сервер ASR'], stat: null },
      { key: 'ai.enabled', title: 'ИИ-помощник', desc: 'Название, описание, главы и теги по субтитрам. Без него выключаются все пункты ниже.', needs: ['OpenAI-совместимый сервер'], stat: null },
      { key: 'search.ask_enabled', title: '«Спросите видеотеку»', desc: 'Ответ обычными словами со ссылками на секунды видео.', deps: ['ai.enabled'], stat: null },
      { key: 'meeting.notes_enabled', title: 'Итоги встречи', desc: 'Конспект записи совещания: решения, задачи, темы.', deps: ['ai.enabled'], stat: 'SELECT count(*)::int FROM meeting_notes' },
      { key: 'clips.ai_enabled', title: 'Подсказки клипов', desc: 'ИИ предлагает фрагменты, годные в отдельный ролик.', deps: ['ai.enabled'], stat: null },
      { key: 'subtitles.translate_enabled', title: 'Перевод субтитров', desc: 'Перевод существующей дорожки на другой язык.', deps: ['ai.enabled'], stat: null },
      { key: 'ocr.enabled', title: 'Текст на экране', desc: 'Распознавание текста со слайдов и поиск по нему.', needs: ['tesseract'], stat: null },
      { key: 'chapters.auto_enabled', title: 'Главы по сменам слайдов', desc: 'Портал сам расставляет главы по смене картинки.', stat: null },
      { key: 'rag.enabled', title: 'Выгрузка в базу знаний (RAG)', desc: 'Передача расшифровок во внешнюю поисковую базу.', needs: ['адрес базы знаний'], stat: null },
    ],
  },
  {
    id: 'ops',
    title: 'Порядок и наблюдение',
    hint: 'Служебные механизмы. Выключение не прячет уже накопленные данные.',
    items: [
      { key: 'lifecycle.enabled', title: 'Сроки хранения', desc: 'Правила хранения, архив, «не удалять» и пересмотр актуальности.', stat: 'SELECT count(*)::int FROM retention_rules' },
      { key: 'qoe.enabled', title: 'Качество воспроизведения', desc: 'Сбор метрик плеера и раздел «Качество» в панели.', stat: null },
      { key: 'calendar.enabled', title: 'Календарь публикаций', desc: 'Раздел «Студия → Календарь» с публикациями, премьерами и эфирами.', stat: null },
      { key: 'webhooks.enabled', title: 'Вебхуки', desc: 'Отправка событий портала во внешние системы.', needs: ['адреса получателей'], stat: 'SELECT count(*)::int FROM webhooks' },
      { key: 'import.watch_enabled', title: 'Папка автоимпорта', desc: 'Портал сам забирает видео из указанного каталога.', stat: null },
      { key: 'digest.enabled', title: 'Еженедельный дайджест', desc: 'Письмо с новинками портала.', deps: ['smtp.enabled'], stat: null },
    ],
  },
];

/** Названия настроек, от которых зависят возможности, но которые включаются не здесь. */
export const DEP_TITLES = {
  'smtp.enabled': 'Почта (SMTP) в настройках',
  'asr.enabled': 'Распознавание речи',
  'ai.enabled': 'ИИ-помощник',
  'live.enabled': 'Трансляции',
  'editor.enabled': 'Редактор видео',
  'editor.blur_enabled': 'Размытие в кадре',
};

/** Плоский список всех ключей возможностей. */
export const FEATURE_KEYS = FEATURE_GROUPS.flatMap((g) => g.items.map((i) => i.key));

/** Пункт по ключу. */
export function featureByKey(key) {
  for (const g of FEATURE_GROUPS) for (const i of g.items) if (i.key === key) return { ...i, group: g.id };
  return null;
}

/**
 * Соответствие «адрес API → ключ возможности». Проверяется по порядку, первое совпадение решает.
 * Маршруты панели администратора сюда не входят: иначе выключенную возможность
 * нельзя было бы включить обратно.
 */
export const FEATURE_ROUTES = [
  [/^\/api\/webinars(\/|$)/, 'webinars.enabled'],
  [/^\/api\/studio\/webinars(\/|$)/, 'webinars.enabled'],
  [/^\/api\/courses(\/|$)/, 'courses.enabled'],
  [/^\/api\/feed\/shorts(\/|$)/, 'shorts.enabled'],
  [/^\/api\/feed\/trending(\/|$)/, 'trending.enabled'],
  [/^\/api\/feed\/subscriptions(\/|$)/, 'subscriptions.enabled'],
  [/^\/api\/me\/subscriptions(\/|$)/, 'subscriptions.enabled'],
  [/^\/api\/playlists(\/|$)/, 'playlists.enabled'],
  [/^\/api\/channels\/[^/]+\/playlists(\/|$)/, 'playlists.enabled'],
  [/^\/api\/me\/notes(\/|$)/, 'notes.enabled'],
  [/^\/api\/videos\/[^/]+\/notes(\/|$)/, 'notes.enabled'],
  [/^\/api\/me\/history(\/|$)/, 'history.enabled'],
  [/^\/api\/me\/liked(\/|$)/, 'likes.enabled'],
  [/^\/api\/videos\/[^/]+\/like(\/|$)/, 'likes.enabled'],
  [/^\/api\/showcases(\/|$)/, 'showcases.enabled'],
  [/^\/api\/screens(\/|$)/, 'screens.enabled'],
  [/^\/api\/assignments(\/|$)/, 'assignments.enabled'],
  [/^\/api\/certificates(\/|$)/, 'certificates.enabled'],
  [/^\/api\/me\/certificates(\/|$)/, 'certificates.enabled'],
  [/^\/api\/party(\/|$)/, 'party.enabled'],
  [/^\/api\/videos\/[^/]+\/party(\/|$)/, 'party.enabled'],
  [/^\/api\/rss(\/|$)/, 'feeds.enabled'],
  [/^\/api\/live(\/|$)/, 'live.enabled'],
  [/^\/api\/studio\/live(\/|$)/, 'live.enabled'],
  [/^\/api\/channels\/[^/]+\/live(\/|$)/, 'live.enabled'],
  [/^\/api\/playback(\/|$)/, 'qoe.enabled'],
  [/^\/api\/share(\/|$)/, 'share_links.enabled'],
  [/^\/api\/videos\/[^/]+\/share-links(\/|$)/, 'share_links.enabled'],
  [/^\/api\/videos\/[^/]+\/quiz(\/|$)/, 'quiz.enabled'],
  [/^\/api\/videos\/[^/]+\/attachments(\/|$)/, 'attachments.enabled'],
  [/^\/api\/videos\/[^/]+\/scenario(\/|$)/, 'scenario.enabled'],
  [/^\/api\/videos\/[^/]+\/review(\/|$)/, 'review.enabled'],
  [/^\/api\/studio\/reviews(\/|$)/, 'review.enabled'],
  [/^\/api\/videos\/[^/]+\/project(\/|$)/, 'editor.timeline'],
  [/^\/api\/videos\/[^/]+\/meeting-notes(\/|$)/, 'meeting.notes_enabled'],
  [/^\/api\/videos\/[^/]+\/comments(\/|$)/, 'comments.enabled'],
  [/^\/api\/comments(\/|$)/, 'comments.enabled'],
  [/^\/api\/studio\/comments(\/|$)/, 'comments.enabled'],
];

/** Какой возможности принадлежит адрес (или null). */
export function featureForPath(pathname) {
  for (const [re, key] of FEATURE_ROUTES) if (re.test(pathname)) return key;
  return null;
}
