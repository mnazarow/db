"""
Генерация схем для руководства портала документации (SVG → PNG через Chromium/Playwright).
Запуск из корня проекта: python3 docs/diagrams/make_diagrams.py
"""
import html
import pathlib

OUT_SVG = pathlib.Path(__file__).parent
OUT_PNG = pathlib.Path(__file__).parent.parent / 'images' / 'diagrams'
OUT_PNG.mkdir(parents=True, exist_ok=True)

BLUE = '#0F4382'; LIGHT = '#79A7C6'; PALE = '#E7EFF6'; BG = '#F4F8FB'; TEXT = '#1D1E1E'; MUTED = '#6E7480'; BORDER = '#DFDEDE'
RED = '#D00024'; AMBER = '#D98A00'; VIOLET = '#5B4FCF'; GREEN = '#1F8F4E'; WHITE = '#FFFFFF'
FONT = "Roboto, Arial, Helvetica, sans-serif"
HEAD = "'DIN Condensed', 'Arial Narrow', Arial, sans-serif"


class Svg:
    def __init__(self, w, h, title):
        self.w, self.h = w, h
        self.parts = [
            f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}" font-family="{FONT}" font-size="14">',
            '<defs>'
            f'<marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="{BLUE}"/></marker>'
            f'<marker id="arrow-red" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="{RED}"/></marker>'
            f'<marker id="arrow-green" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="{GREEN}"/></marker>'
            f'<marker id="arrow-muted" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="{MUTED}"/></marker>'
            '</defs>',
            f'<rect width="{w}" height="{h}" fill="{WHITE}"/>',
            f'<text x="24" y="34" font-family="{HEAD}" font-size="24" font-weight="600" fill="{BLUE}" letter-spacing="0.5">{html.escape(title).upper()}</text>',
        ]

    def box(self, x, y, w, h, title, lines=(), fill=WHITE, stroke=BLUE, title_color=None, r=14, sw=1.5, size=13, title_size=14, dashed=False):
        dash = ' stroke-dasharray="6 4"' if dashed else ''
        self.parts.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{dash}/>')
        ty = y + 24
        if title:
            self.parts.append(f'<text x="{x + w / 2}" y="{ty}" text-anchor="middle" font-weight="700" font-size="{title_size}" fill="{title_color or BLUE}">{html.escape(title)}</text>')
            ty += 20
        for line in lines:
            self.parts.append(f'<text x="{x + w / 2}" y="{ty}" text-anchor="middle" font-size="{size}" fill="{TEXT}">{html.escape(line)}</text>')
            ty += 18

    def text(self, x, y, s, size=13, color=TEXT, anchor='start', weight='normal', italic=False):
        st = ' font-style="italic"' if italic else ''
        self.parts.append(f'<text x="{x}" y="{y}" font-size="{size}" fill="{color}" text-anchor="{anchor}" font-weight="{weight}"{st} xml:space="preserve">{html.escape(s)}</text>')

    def multiline(self, x, y, lines, size=12, color=MUTED, anchor='start', lh=16):
        for i, line in enumerate(lines):
            self.text(x, y + i * lh, line, size=size, color=color, anchor=anchor)

    def arrow(self, x1, y1, x2, y2, label='', color=BLUE, marker='arrow', dashed=False, label_dy=-6):
        dash = ' stroke-dasharray="6 4"' if dashed else ''
        self.parts.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{color}" stroke-width="1.8" marker-end="url(#{marker})"{dash}/>')
        if label:
            mx, my = (x1 + x2) / 2, (y1 + y2) / 2 + label_dy
            self.parts.append(f'<text x="{mx}" y="{my}" text-anchor="middle" font-size="12" fill="{color}">{html.escape(label)}</text>')

    def path(self, d, color=BLUE, marker='arrow', dashed=False):
        dash = ' stroke-dasharray="6 4"' if dashed else ''
        self.parts.append(f'<path d="{d}" fill="none" stroke="{color}" stroke-width="1.8" marker-end="url(#{marker})"{dash}/>')

    def group_bg(self, x, y, w, h, label, fill=BG, stroke=BORDER):
        self.parts.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="18" fill="{fill}" stroke="{stroke}"/>')
        self.parts.append(f'<text x="{x + 16}" y="{y + 22}" font-size="12" font-weight="700" fill="{LIGHT}" letter-spacing="1.5">{html.escape(label).upper()}</text>')

    def diamond(self, cx, cy, w, h, lines, fill=PALE):
        self.parts.append(f'<polygon points="{cx},{cy - h / 2} {cx + w / 2},{cy} {cx},{cy + h / 2} {cx - w / 2},{cy}" fill="{fill}" stroke="{BLUE}" stroke-width="1.5"/>')
        ty = cy - (len(lines) - 1) * 8 + 4
        for line in lines:
            self.parts.append(f'<text x="{cx}" y="{ty}" text-anchor="middle" font-size="12" fill="{TEXT}">{html.escape(line)}</text>')
            ty += 16

    def pill(self, x, y, w, text, color, fill):
        self.parts.append(f'<rect x="{x}" y="{y}" width="{w}" height="26" rx="13" fill="{fill}" stroke="{color}"/>')
        self.parts.append(f'<circle cx="{x + 14}" cy="{y + 13}" r="4" fill="{color}"/>')
        self.parts.append(f'<text x="{x + 26}" y="{y + 17}" font-size="12" fill="{TEXT}">{html.escape(text)}</text>')

    def save(self, name):
        self.parts.append('</svg>')
        (OUT_SVG / f'{name}.svg').write_text('\n'.join(self.parts), encoding='utf-8')
        return OUT_SVG / f'{name}.svg'


def architecture():
    s = Svg(1160, 600, 'Архитектура портала')
    s.group_bg(24, 60, 250, 470, 'Люди')
    s.box(50, 110, 200, 78, 'Сотрудники', ['читают и скачивают', 'опубликованные документы'])
    s.box(50, 214, 200, 78, 'Модераторы разделов', ['создают документы,', 'версии, публикуют'])
    s.box(50, 318, 200, 78, 'Администраторы', ['разделы, модераторы,', 'пользователи, статистика'])
    s.box(50, 422, 200, 88, 'Консоль сервера', ['docportal …', 'php bin/console …', 'скрипты deploy/*.sh'], fill=PALE)

    s.group_bg(310, 60, 560, 470, 'Сервер приложения (nginx + PHP-FPM или Docker)')
    s.box(340, 110, 230, 70, 'nginx', ['статика public/', 'HTTPS, лимит 128 МБ'])
    s.box(610, 110, 230, 70, 'PHP-FPM 8.2+', ['php-fpm пул www', 'opcache, ext-ldap'])
    s.box(340, 220, 500, 290, 'Приложение Symfony 7.4', [], fill=WHITE, stroke=LIGHT)
    s.box(360, 258, 150, 70, 'Security', ['вход: локальный /', 'LDAP, роли, voter'], size=11, title_size=13, fill=PALE, stroke=LIGHT)
    s.box(525, 258, 150, 70, 'Разделы', ['дерево любой глубины,', 'модераторы, наследование'], size=11, title_size=13, fill=PALE, stroke=LIGHT)
    s.box(690, 258, 135, 70, 'Документы', ['файлы и страницы,', 'версии, статусы'], size=11, title_size=13, fill=PALE, stroke=LIGHT)
    s.box(360, 344, 150, 70, 'Актуальность', ['сроки, уведомления', 'по cron (Mailer)'], size=11, title_size=13, fill=PALE, stroke=LIGHT)
    s.box(525, 344, 150, 70, 'Статистика', ['события: просмотры,', 'скачивания, версии'], size=11, title_size=13, fill=PALE, stroke=LIGHT)
    s.box(690, 344, 135, 70, 'Twig + CSS', ['стиль vodokomfort.ru,', 'редактор Quill'], size=11, title_size=13, fill=PALE, stroke=LIGHT)
    s.text(590, 450, 'Doctrine ORM, миграции, консольные команды app:*', size=11, color=MUTED, anchor='middle')
    s.text(590, 470, 'Журналы: var/log/prod.log (ошибки), var/log/audit.log (действия пользователей)', size=11, color=MUTED, anchor='middle')

    s.group_bg(900, 60, 236, 470, 'Хранилища и службы')
    s.box(922, 100, 192, 84, 'MySQL 8 / MariaDB', ['user, section, document,', 'document_version,', 'document_event'])
    s.box(922, 204, 192, 74, 'Хранилище файлов', ['var/storage (native:', 'shared/storage; docker: том)'])
    s.box(922, 298, 192, 66, 'Active Directory', ['LDAP 389/636:', 'проверка паролей'], fill=PALE)
    s.box(922, 384, 192, 60, 'SMTP-сервер', ['уведомления о сроках'], fill=PALE)
    s.box(922, 464, 192, 56, 'Резервные копии', ['/var/backups/docportal'], fill=PALE)

    s.arrow(250, 149, 340, 145, 'HTTP(S)')
    s.arrow(250, 253, 340, 160, '', dashed=True)
    s.arrow(250, 357, 340, 175, '', dashed=True)
    s.arrow(570, 145, 610, 145, 'FastCGI', label_dy=-10)
    s.arrow(725, 180, 725, 220)
    s.arrow(250, 466, 340, 466, 'CLI', dashed=True)
    s.arrow(840, 300, 922, 150, 'SQL')
    s.arrow(840, 330, 922, 240, 'файлы')
    s.arrow(840, 360, 922, 330, 'bind', dashed=True)
    s.arrow(840, 390, 922, 414, 'SMTP', dashed=True)
    return s.save('01-architecture')


def roles():
    s = Svg(1100, 560, 'Роли и права')
    cols = [('Возможность', 500), ('Пользователь', 180), ('Модератор', 180), ('Администратор', 180)]
    x0, y0 = 40, 70
    x = x0
    for name, w in cols:
        s.parts.append(f'<rect x="{x}" y="{y0}" width="{w}" height="40" fill="{BG}" stroke="{BORDER}"/>')
        s.text(x + 14, y0 + 26, name, size=13, weight='700', color=BLUE)
        x += w
    rows = [
        ('Вход (локальная или доменная учётная запись), «запомнить меня»', True, True, True),
        ('Просмотр разделов и опубликованных документов, поиск, скачивание', True, True, True),
        ('История версий: открыть, скачать, сравнить любую версию', True, True, True),
        ('Смена собственного пароля (локальные учётные записи)', True, True, True),
        ('Просмотр черновиков и архива в своих разделах', False, True, True),
        ('Создание документов (файл или страница), загрузка новых версий', False, 'свои', True),
        ('Публикация, снятие с публикации, архив, восстановление версии, удаление', False, 'свои', True),
        ('Создание и изменение подразделов', False, 'свои', True),
        ('Статистика по документам своих разделов', False, 'свои', True),
        ('Назначение модераторов на разделы', False, False, True),
        ('Пользователи, реестр документов, вся статистика, журнал, настройки', False, False, True),
        ('Консольные команды и скрипты на сервере (root)', False, False, True),
    ]
    y = y0 + 40
    for label, *vals in rows:
        x = x0
        for i, (name, w) in enumerate(cols):
            s.parts.append(f'<rect x="{x}" y="{y}" width="{w}" height="32" fill="{WHITE}" stroke="{BORDER}"/>')
            if i == 0:
                s.text(x + 14, y + 21, label, size=12)
            else:
                v = vals[i - 1]
                if v == 'свои':
                    s.text(x + w / 2, y + 21, 'свои разделы', size=11, color=AMBER, anchor='middle', weight='700')
                else:
                    s.text(x + w / 2, y + 22, '✔' if v else '—', size=14, color=GREEN if v else MUTED, anchor='middle', weight='700')
            x += w
        y += 32
    s.text(40, y + 26, '«Свои разделы» — разделы, на которые пользователь назначен модератором, и все их подразделы любой глубины.', size=12, color=MUTED)
    s.text(40, y + 44, 'Администратор обладает всеми правами во всех разделах. Нельзя заблокировать/удалить себя и последнего администратора.', size=12, color=MUTED)
    return s.save('02-roles')


def lifecycle():
    s = Svg(1160, 520, 'Жизненный цикл документа и версии')
    s.group_bg(30, 60, 1100, 200, 'Статусы документа')
    s.pill(70, 130, 150, 'Черновик', VIOLET, '#ECEAFB')
    s.pill(420, 130, 170, 'Опубликован', GREEN, '#E6F5EC')
    s.pill(800, 130, 150, 'В архиве', MUTED, '#F6F6F6')
    s.arrow(220, 143, 420, 143, 'Опубликовать', color=GREEN, marker='arrow-green')
    s.path('M 420 155 C 350 200, 290 200, 220 155', color=MUTED, marker='arrow-muted', dashed=True)
    s.text(320, 205, 'Снять с публикации', size=11, color=MUTED, anchor='middle')
    s.arrow(590, 143, 800, 143, 'В архив', color=MUTED, marker='arrow-muted')
    s.path('M 800 155 C 740 215, 300 235, 220 158', color=MUTED, marker='arrow-muted', dashed=True)
    s.text(520, 236, 'Опубликовать снова / снять с публикации (из архива)', size=11, color=MUTED, anchor='middle')
    s.text(145, 100, 'видят модераторы', size=11, color=MUTED, anchor='middle')
    s.text(505, 100, 'видят все сотрудники', size=11, color=MUTED, anchor='middle')
    s.text(875, 100, 'снят с публикации, история сохранена', size=11, color=MUTED, anchor='middle')

    s.group_bg(30, 290, 1100, 200, 'Версии (никогда не изменяются и не удаляются)')
    s.box(60, 340, 190, 100, 'v1', ['первая версия:', 'файл или текст', 'страницы'])
    s.box(300, 340, 190, 100, 'v2', ['новая версия:', 'загрузка файла /', 'сохранение текста'])
    s.box(540, 340, 190, 100, 'v3', ['ещё одна', 'правка'])
    s.box(780, 340, 220, 100, 'v4 = копия v2', ['«Восстановить» создаёт', 'новую версию с содержимым', 'выбранной старой'], fill=PALE)
    s.arrow(250, 390, 300, 390)
    s.arrow(490, 390, 540, 390)
    s.arrow(730, 390, 780, 390)
    s.path('M 395 340 C 500 290, 800 290, 890 340', color=VIOLET, marker='arrow', dashed=True)
    s.text(640, 318, 'восстановление v2', size=11, color=VIOLET, anchor='middle')
    s.text(580, 470, 'Текущая версия — всегда последняя. Любую версию можно открыть/скачать, версии страниц — сравнить построчно; каждая версия хранит автора, дату и комментарий.', size=12, color=MUTED, anchor='middle')
    return s.save('03-lifecycle')


def validity():
    s = Svg(1160, 520, 'Контроль актуальности документа')
    s.text(24, 62, 'Дата «Актуален до» задаётся в карточке документа (по умолчанию — через DEFAULT_VALIDITY_MONTHS месяцев). Пустая дата — бессрочный документ.', size=12, color=MUTED)
    y = 110
    s.box(40, y, 250, 120, 'Бессрочный', ['дата не задана', 'уведомлений нет', 'серый значок'], fill='#F6F6F6', stroke=MUTED, title_color=MUTED)
    s.box(330, y, 250, 120, 'Актуален', ['до срока больше', 'EXPIRY_SOON_DAYS (30) дней', 'зелёный значок'], fill='#E6F5EC', stroke=GREEN, title_color=GREEN)
    s.box(620, y, 250, 120, 'Истекает', ['до срока 0–30 дней', 'уведомление (стадия 1)', 'жёлтый значок'], fill='#FFF3DC', stroke=AMBER, title_color=AMBER)
    s.box(910, y, 220, 120, 'Просрочен', ['срок прошёл', 'уведомление (стадия 2)', 'красный значок'], fill='#FCE9EC', stroke=RED, title_color=RED)
    s.arrow(580, y + 60, 620, y + 60, 'время →', label_dy=-8)
    s.arrow(870, y + 60, 910, y + 60)

    s.group_bg(40, 270, 1090, 220, 'Ежедневная проверка: docportal console app:documents:expiry (cron 08:00)')
    s.box(70, 320, 240, 130, 'Кому', ['ответственный (создатель),', 'модераторы раздела и всех', 'родительских разделов,', 'администраторы (NOTIFY_ADMINS)'])
    s.box(350, 320, 240, 130, 'Когда', ['один раз при входе в окно', '«истекает» и один раз после', 'истечения; при изменении', 'даты стадия сбрасывается'])
    s.box(630, 320, 240, 130, 'Как', ['письмо со списком', 'документов и ссылками', '(MAILER_DSN); событие', '«Уведомление о сроке»'])
    s.box(910, 320, 190, 130, 'Что делать', ['загрузить новую версию', 'и продлить срок, либо', 'перенести документ', 'в архив'], fill=PALE)
    s.arrow(310, 385, 350, 385)
    s.arrow(590, 385, 630, 385)
    s.arrow(870, 385, 910, 385)
    return s.save('04-validity')


def sections_tree():
    s = Svg(1160, 560, 'Дерево разделов и наследование прав модераторов')
    def node(x, y, w, title, lines=(), fill=WHITE, stroke=BLUE):
        s.box(x, y, w, 66, title, lines, fill=fill, stroke=stroke, size=11, title_size=13, r=12)
    node(60, 80, 220, 'Общие документы', ['модератор: Иванов'], fill=PALE)
    node(60, 180, 200, 'Регламенты и политики', ['(права Иванова)'])
    node(60, 280, 200, 'Информационная безопасность', ['(права Иванова)'])
    node(320, 180, 200, 'Инструкции', ['(права Иванова)'])
    node(320, 280, 200, 'ИТ', ['модератор: Петрова', '(и права Иванова)'], fill=PALE)
    node(320, 380, 200, 'Рабочее место', ['(права Петровой и Иванова)'])
    node(560, 380, 200, 'Почта и телефония', ['(права Петровой и Иванова)'])
    node(560, 280, 200, 'Охрана труда', ['(права Иванова)'])
    node(840, 80, 220, 'Производство', ['модератор: Сидоров'], fill=PALE)
    node(840, 180, 200, 'Технологические карты', ['(права Сидорова)'])
    node(840, 280, 200, 'Чертежи и схемы', ['(права Сидорова)'])
    for (x1, y1, x2, y2) in [(160, 146, 160, 180), (160, 246, 160, 280), (280, 113, 420, 180), (420, 246, 420, 280), (420, 346, 420, 380), (520, 320, 660, 380), (520, 213, 660, 280), (950, 146, 940, 180), (940, 246, 940, 280)]:
        s.arrow(x1, y1, x2, y2, color=LIGHT, marker='arrow-muted')
    s.text(60, 490, 'Модератор раздела автоматически управляет всеми его подразделами любой глубины (максимум — 10 уровней).', size=12, color=MUTED)
    s.text(60, 510, 'Модератор «ИТ» (Петрова) не имеет прав на «Охрану труда» и «Регламенты» — они выше или в соседней ветке дерева.', size=12, color=MUTED)
    s.text(60, 530, 'При переносе раздела в другое место дерева права пересчитываются автоматически по новому положению.', size=12, color=MUTED)
    return s.save('05-sections-tree')


def native_layout():
    s = Svg(1100, 620, 'Размещение на сервере (режим native)')
    x, y = 40, 70
    tree = [
        ('/opt/docportal/', 0, True),
        ('current  →  releases/1.0.0-20260915…/   (символическая ссылка на рабочий релиз)', 1, False),
        ('releases/', 1, True), ('1.0.0-20260915120000/   код версии: bin/, config/, public/, src/, templates/, vendor/', 2, False), ('0.9.0-…/   предыдущий релиз (для отката, хранится 3 последних)', 2, False),
        ('shared/', 1, True), ('.env.local   настройки (секрет, база данных, почта, LDAP)', 2, False), ('storage/   файлы всех версий документов (по каталогу на документ)', 2, False), ('log/   prod.log, audit.log, expiry-cron.log', 2, False),
        ('/etc/docportal/install.conf   параметры установки (для update/backup/uninstall)', 0, False),
        ('/etc/docportal/admin-credentials.txt   первый пароль администратора (удалить!)', 0, False),
        ('/etc/nginx/sites-available/docportal.conf   виртуальный хост', 0, False),
        ('/etc/php/8.x/fpm/conf.d/90-docportal.ini   лимиты PHP', 0, False),
        ('/etc/cron.d/docportal   проверка сроков 08:00, резервная копия 03:15', 0, False),
        ('/usr/local/bin/docportal   команда управления', 0, False),
        ('/var/backups/docportal/   архивы резервных копий', 0, False),
        ('/var/log/docportal/   журналы скриптов установки и обновления', 0, False),
    ]
    for label, depth, is_dir in tree:
        s.text(x + depth * 28, y, ('▸ ' if is_dir else '  ') + label, size=12.5, color=BLUE if is_dir else TEXT, weight='700' if is_dir else 'normal')
        y += 26
    s.box(760, 462, 300, 74, '/usr/local/bin/docportal', ['status | update | backup | restore |', 'console | logs | uninstall'], fill=PALE)
    s.text(40, 580, 'Обновление создаёт новый каталог в releases/ и переключает ссылку current; при ошибке ссылка возвращается на прежний релиз.', size=12, color=MUTED)
    s.text(40, 600, 'Пользователь службы (www-data) владеет только shared/ и var/; код релизов доступен ему только для чтения.', size=12, color=MUTED)
    return s.save('06-native-layout')


def update_flow():
    s = Svg(1200, 430, 'Обновление одной командой и автоматический откат')
    y = 80
    steps = [
        ('Резервная копия', ['БД + файлы +', 'настройки']),
        ('Новый релиз', ['копирование файлов', 'в releases/<версия>']),
        ('Зависимости', ['vendor/ из поставки', 'или из прошлого релиза']),
        ('Кэш и миграции', ['cache:warmup,', 'migrations:migrate']),
        ('Переключение', ['current → новый,', 'reload PHP-FPM/nginx']),
        ('Проверка', ['GET /health,', 'app:check']),
    ]
    x = 30
    for i, (t, lines) in enumerate(steps):
        s.box(x, y, 160, 90, t, lines, fill=WHITE if i != 5 else PALE)
        if i < len(steps) - 1:
            s.arrow(x + 160, y + 45, x + 178, y + 45)
        x += 178
    s.arrow(x - 18 + 0, y + 45, x + 10, y + 45, color=GREEN, marker='arrow-green')
    s.pill(x + 12, y + 32, 60, 'OK', GREEN, '#E6F5EC')
    s.text(x + 42, y + 84, 'старые релизы', size=11, color=MUTED, anchor='middle')
    s.text(x + 42, y + 98, 'удаляются (--keep 3)', size=11, color=MUTED, anchor='middle')
    s.group_bg(30, 210, 1140, 190, 'При любой ошибке на шагах 2–6')
    s.box(60, 250, 220, 110, 'Откат кода', ['ссылка current', 'возвращается на', 'предыдущий релиз'], fill=WHITE, stroke=RED, title_color=RED)
    s.box(320, 250, 220, 110, 'Откат базы', ['если миграции уже', 'применялись — импорт', 'дампа из резервной копии'], fill=WHITE, stroke=RED, title_color=RED)
    s.box(580, 250, 220, 110, 'Очистка', ['каталог неудавшегося', 'релиза удаляется,', 'журнал сохраняется'], fill=WHITE, stroke=RED, title_color=RED)
    s.box(840, 250, 300, 110, 'Результат', ['портал работает на прежней версии;', 'причина ошибки — в журнале', '/var/log/docportal/update-*.log'], fill=PALE, stroke=RED, title_color=RED)
    s.arrow(280, 305, 320, 305, color=RED, marker='arrow-red')
    s.arrow(540, 305, 580, 305, color=RED, marker='arrow-red')
    s.arrow(800, 305, 840, 305, color=RED, marker='arrow-red')
    return s.save('07-update-flow')


def docker_arch():
    s = Svg(1100, 440, 'Схема развёртывания в Docker Compose')
    s.box(40, 100, 160, 70, 'Браузер', ['порт HTTP_PORT', '(по умолчанию 80)'])
    s.group_bg(240, 60, 820, 340, 'docker compose (каталог docker/)')
    s.box(270, 100, 190, 90, 'web', ['nginx:1.27-alpine', 'public/ (только чтение)', 'проксирует PHP в app:9000'])
    s.box(490, 100, 190, 90, 'app', ['php:8.4-fpm-alpine', 'код портала + vendor', 'entrypoint: миграции, админ'])
    s.box(710, 100, 150, 90, 'db', ['mysql:8.0', 'utf8mb4', 'healthcheck'])
    s.box(890, 100, 150, 90, 'cron', ['тот же образ', 'crond: проверка', 'сроков 08:00'], fill=PALE)
    s.box(490, 250, 190, 70, 'том storage', ['/var/www/html/var/storage'], fill=PALE)
    s.box(710, 250, 150, 70, 'том db-data', ['/var/lib/mysql'], fill=PALE)
    s.box(270, 250, 190, 70, 'том logs', ['/var/www/html/var/log'], fill=PALE)
    s.arrow(200, 135, 270, 135)
    s.arrow(460, 145, 490, 145, 'FastCGI')
    s.arrow(680, 145, 710, 145, 'SQL')
    s.arrow(585, 190, 585, 250)
    s.arrow(785, 190, 785, 250)
    s.arrow(490, 180, 400, 250, dashed=True)
    s.arrow(890, 160, 860, 160, dashed=True)
    s.text(40, 350, 'Настройки — docker/.env (пароли БД, APP_SECRET, ADMIN_USER/ADMIN_PASSWORD, порты, образы, LDAP, почта, расписание EXPIRY_CRON).', size=12, color=MUTED)
    s.text(40, 370, 'Если Docker Hub недоступен, укажите зеркало: --docker-mirror https://mirror.gcr.io или образы из другого реестра в .env.', size=12, color=MUTED)
    return s.save('08-docker')


def login_flow():
    s = Svg(1160, 560, 'Вход: локальные и доменные учётные записи')
    s.box(40, 90, 200, 60, 'Форма входа', ['логин + пароль'], fill=PALE, r=22)
    s.arrow(240, 120, 300, 120)
    s.diamond(400, 120, 200, 80, ['Есть локальная', 'учётная запись?'])
    s.arrow(500, 120, 620, 120, 'да', color=GREEN, marker='arrow-green')
    s.box(620, 80, 230, 80, 'Проверка пароля', ['по хэшу в базе портала', '(bcrypt/argon2)'])
    s.arrow(400, 160, 400, 230, 'нет', label_dy=-2)
    s.diamond(400, 280, 200, 80, ['LDAP_ENABLED=1?'])
    s.arrow(400, 320, 400, 400, 'нет', color=RED, marker='arrow-red', label_dy=-2)
    s.pill(320, 405, 170, 'Отказ во входе', RED, '#FCE9EC')
    s.arrow(500, 280, 620, 280, 'да', color=GREEN, marker='arrow-green')
    s.box(620, 230, 230, 100, 'Active Directory', ['bind служебной учётной', 'записью + поиск, либо', 'bind как логин@домен'])
    s.arrow(850, 280, 900, 280)
    s.box(900, 230, 230, 100, 'Учётная запись в портале', ['создаётся при первом входе,', 'обновляются имя, почта,', 'подразделение, права админа'], fill=PALE)
    s.arrow(850, 120, 900, 120)
    s.box(900, 80, 230, 80, 'Сессия', ['роли, «запомнить меня»,', 'журнал входов'], fill=PALE)
    s.arrow(1015, 230, 1015, 160, color=MUTED, marker='arrow-muted')
    s.text(40, 480, 'Защита от перебора: LOGIN_MAX_ATTEMPTS попыток на пару «логин + IP» и LOGIN_MAX_ATTEMPTS_GLOBAL на IP за LOGIN_ATTEMPT_INTERVAL.', size=12, color=MUTED)
    s.text(40, 500, 'Группы AD: LDAP_USER_GROUP — кому разрешён вход, LDAP_ADMIN_GROUP — кому выдаются права администратора (учитываются вложенные группы).', size=12, color=MUTED)
    s.text(40, 520, 'Пароли доменных учётных записей в портале не хранятся; их смена выполняется средствами домена.', size=12, color=MUTED)
    return s.save('09-login-flow')


def font_css():
    """@font-face со шрифтами портала, встроенными как data:-URI (file:// из about:blank не грузится)."""
    import base64
    fonts = pathlib.Path(__file__).resolve().parents[2] / 'public' / 'fonts'
    def face(family, file, weight):
        data = base64.b64encode((fonts / file).read_bytes()).decode()
        return f"@font-face{{font-family:'{family}';src:url(data:font/woff2;base64,{data}) format('woff2');font-weight:{weight}}}"
    return ''.join([
        face('DIN Condensed', 'DINCondensed-Regular.woff2', 400),
        face('DIN Condensed', 'DINCondensed-Regular.woff2', 600),
        face('DIN Condensed', 'DINCondensed-Regular.woff2', 700),
        face('Roboto', 'roboto-cyrillic-400-normal.woff2', 400),
        face('Roboto', 'roboto-latin-400-normal.woff2', 400),
        face('Roboto', 'roboto-cyrillic-700-normal.woff2', 700),
        face('Roboto', 'roboto-latin-700-normal.woff2', 700),
    ])


def main():
    files = [architecture(), roles(), lifecycle(), validity(), sections_tree(), native_layout(), update_flow(), docker_arch(), login_flow()]
    from playwright.sync_api import sync_playwright
    import re
    with sync_playwright() as p:
        b = p.chromium.launch()
        for f in files:
            svg = f.read_text(encoding='utf-8')
            m = re.search(r'width="(\d+)" height="(\d+)"', svg)
            w, h = int(m.group(1)), int(m.group(2))
            page = b.new_page(viewport={'width': w, 'height': h}, device_scale_factor=2)
            css = font_css() + "body{margin:0}"
            page.set_content(f'<html><head><style>{css}</style></head><body>{svg}</body></html>')
            page.wait_for_timeout(300)
            out = OUT_PNG / (f.stem + '.png')
            page.screenshot(path=str(out), clip={'x': 0, 'y': 0, 'width': w, 'height': h})
            page.close()
            print('rendered', out)
        b.close()


if __name__ == '__main__':
    main()
