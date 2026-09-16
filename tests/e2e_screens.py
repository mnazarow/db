"""
Сквозная проверка портала через браузер (Playwright) и снятие скриншотов для документации.
Перед запуском: загрузите демо-данные (php bin/console app:demo:load --force) и запустите сервер
(./scripts/dev-server.sh). Запуск: python3 tests/e2e_screens.py http://127.0.0.1:8080 docs/images [var/import] [http://127.0.0.1:8090/v1]
"""
import json
import pathlib
import re
import sys
import time
import tempfile
import urllib.request

from playwright.sync_api import expect, sync_playwright

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
OUT = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else 'docs/images')
OUT.mkdir(parents=True, exist_ok=True)
# Каталог импорта того сервера, который проверяется (IMPORT_DIR); нужен для шага «Импорт из каталога».
IMPORT_DIR = pathlib.Path(sys.argv[3] if len(sys.argv) > 3 else 'var/import')
PASSWORD = 'Demo12345'
# Адрес заглушки OpenAI-совместимого API (tests/mock_llm_server.py), например http://127.0.0.1:8090/v1; пусто — шаги LLM пропускаются.
LLM_MOCK = sys.argv[4] if len(sys.argv) > 4 else ''
# Имя тестового пользователя уникально: демо-данные (app:demo:load --force) пользователей не удаляют.
TEST_USER = 'test%d' % (int(time.time()) % 1000000)


def make_import_tree(root):
    """Образец дерева папок для проверки импорта: папки → разделы, файлы → документы."""
    import shutil
    if root.exists():
        shutil.rmtree(root)
    files = {
        'README.txt': 'Документы отдела технического контроля (импорт из сетевой папки).',
        'Положение_об_ОТК.docx': 'docx' * 200,
        'Инструкции по контролю/описание.txt': 'Инструкции по входному и приёмочному контролю.',
        'Инструкции по контролю/2024/ИК-01_Входной_контроль_металла.pdf': '%PDF-1.4 ik01',
        'Инструкции по контролю/2024/ИК-02_Контроль сварных швов.pdf': '%PDF-1.4 ik02',
        'Инструкции по контролю/2025/ИК-03 Приёмочный контроль.pdf': '%PDF-1.4 ik03',
        'Протоколы испытаний/Протокол 1.pdf': '%PDF-1.4 p1',
        'Протоколы испытаний/Протокол 2.pdf': '%PDF-1.4 p2',
        'Протоколы испытаний/Протокол 10.pdf': '%PDF-1.4 p10',
        'Протоколы испытаний/Thumbs.db': 'x',
        'Черновики_2023/старый_отчёт.exe': 'MZ',
    }
    for rel, content in files.items():
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding='utf-8')


def shot(page, name, full=True):
    page.evaluate('window.scrollTo(0, 0)')
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / f'{name}.png'), full_page=full)
    print('screenshot', name)


def login(page, user, password=PASSWORD):
    page.goto(BASE + '/login')
    page.fill('#username', user)
    page.fill('#password', password)
    page.click('button[type=submit]')


def logout(page):
    page.click('.site-header__user button[type=submit]')
    expect(page).to_have_url(BASE + '/login')


def first_doc_url(page, section_path):
    page.goto(BASE + section_path)
    return page.locator('.doc-table__title').first.get_attribute('href')


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, device_scale_factor=1, locale='ru-RU')
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: errors.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    page.on('dialog', lambda d: d.accept())

    # 0. Гость (без входа): дерево разделов и открытые документы, внутренние — только после входа
    page.goto(BASE + '/')
    expect(page.locator('.section-card').first).to_be_visible()
    expect(page.locator('.user-chip--guest')).to_be_visible()
    shot(page, '00-guest-home')
    page.goto(BASE + '/search?q=ИТ-РМ-002')
    guest_doc = page.locator('.doc-table__title').first.get_attribute('href')
    page.goto(BASE + guest_doc)
    expect(page.locator('.h-display')).to_be_visible()
    assert page.locator('.card--actions').count() == 0, 'guest must not see moderator actions'
    shot(page, '00-guest-document', full=False)
    page.goto(BASE + '/search?q=ПР-2026-01')
    assert page.locator('.doc-table__title').count() == 0, 'internal document must be hidden from guests'
    page.goto(BASE + '/admin')
    expect(page).to_have_url(BASE + '/login')
    print('guest ok')

    # 1. Вход
    page.goto(BASE + '/login')
    shot(page, '01-login', full=False)
    page.fill('#username', 'admin'); page.fill('#password', 'wrong'); page.click('button[type=submit]')
    expect(page.locator('.alert--danger')).to_be_visible()
    shot(page, '02-login-error', full=False)

    # 2. Обычный пользователь
    login(page, 'smirnov')
    expect(page).to_have_url(BASE + '/')
    expect(page.locator('.section-card').first).to_be_visible()
    assert page.locator('.attention').count() == 0, 'reader must not see moderator block'
    shot(page, '03-home-user')
    page.click('.section-card >> nth=0')
    expect(page.locator('.h-display')).to_be_visible()
    shot(page, '04-section')
    # вложенный раздел
    page.click('.section-card--compact >> nth=0')
    page.click('.section-card--compact >> nth=0')
    shot(page, '05-section-nested', full=False)
    page_doc_url = page.locator('.doc-table__title').first.get_attribute('href')
    page.goto(BASE + '/search?q=ИТ-РМ-002')
    doc_url = page.locator('.doc-table__title').first.get_attribute('href')
    page.goto(BASE + doc_url)
    expect(page.locator('.doc-head__title')).to_be_visible()
    assert page.locator('.card--actions').count() == 0, 'reader must not see manage actions'
    shot(page, '06-document')
    # скачивание
    with page.expect_download() as dl:
        page.click('.hero__actions a.btn--pill')
    print('download ok:', dl.value.suggested_filename)
    assert dl.value.suggested_filename.endswith('.pdf')
    # страница-документ (Политика паролей)
    page.goto(BASE + '/search?q=ИБ-001')
    expect(page.locator('.doc-table__title').first).to_be_visible()
    shot(page, '07-search')
    page.click('.doc-table__title >> nth=0')
    expect(page.locator('.page-content')).to_be_visible()
    shot(page, '08-document-page')
    # сравнение версий доступно читателю
    page.click('text=Сравнить с текущей >> nth=0')
    expect(page.locator('.diff, .alert')).to_be_visible()
    shot(page, '09-compare')
    # админка и статистика недоступны
    r = page.goto(BASE + '/admin'); assert r.status == 403, r.status
    r = page.goto(BASE + doc_url + '/stats'); assert r.status == 403, r.status
    r = page.goto(BASE + '/documents/new'); assert r.status == 403, r.status
    shot(page, '10-forbidden', full=False)
    page.goto(BASE + '/profile/password')
    shot(page, '11-password', full=False)
    logout(page)

    # 3. Модератор
    login(page, 'petrova')
    expect(page.locator('.attention')).to_be_visible()
    shot(page, '20-home-moderator')
    # раздел, которым управляет
    page.goto(BASE + '/search?q=ИТ-РМ-002')
    page.click('.doc-table__title >> nth=0')
    expect(page.locator('.card--actions')).to_be_visible()
    shot(page, '21-document-moderator')
    # чужой раздел — управлять нельзя
    page.goto(BASE + '/search?q=ПР-2026-01')
    page.click('.doc-table__title >> nth=0')
    assert page.locator('.card--actions').count() == 0, 'moderator must not manage foreign section'
    # новый документ-страница
    page.goto(BASE + '/documents/new?type=page')
    expect(page.locator('#page-editor .ql-editor')).to_be_visible()
    page.fill('#document_title', 'Регламент выдачи оборудования')
    page.fill('#document_code', 'ИТ-РМ-010')
    page.select_option('#document_section', label='— — — Рабочее место')
    page.fill('#document_description', 'Тестовый документ, созданный автоматически.')
    page.fill('#document_tagsString', 'тест, оборудование')
    page.locator('#page-editor .ql-editor').fill('Оборудование выдаётся по заявке руководителя. Срок выдачи — три рабочих дня.')
    page.uncheck('#document_publish')
    shot(page, '22-document-new-page')
    page.click('[data-submit]')
    expect(page.locator('.alert--success').first).to_be_visible()
    assert 'черновик' in page.locator('.alert--success').first.inner_text().lower()
    new_doc_url = page.url
    shot(page, '23-document-draft')
    # редактирование → новая версия
    page.goto(new_doc_url.rstrip('/') + '/edit')
    page.locator('#page-editor .ql-editor').fill('Оборудование выдаётся по заявке руководителя. Срок выдачи — два рабочих дня. Возврат — по акту.')
    page.fill('#document_changeNote', 'Сокращён срок выдачи')
    page.click('[data-submit]')
    expect(page.locator('.alert--success').first).to_contain_text('версия 2')
    shot(page, '24-document-versions')
    # публикация
    page.click('form[action$="/publish"] button')
    expect(page.locator('.alert--success').first).to_contain_text('опубликован')
    # восстановление версии 1
    page.click('form[action$="/versions/1/restore"] button')
    expect(page.locator('.alert--success').first).to_contain_text('восстановлена')
    assert page.locator('.versions-table tbody tr').count() == 3
    # новый файловый документ
    page.goto(BASE + '/documents/new')
    page.fill('#document_title', 'Тестовая инструкция (файл)')
    page.select_option('#document_section', label='— — — Почта и телефония')
    tmp = tempfile.NamedTemporaryFile('w', suffix='.txt', delete=False, encoding='utf-8')
    tmp.write('Тестовый файл для e2e-проверки.\n' * 20); tmp.close()
    page.set_input_files('input[type=file]', tmp.name)
    page.fill('#document_validUntil', '2027-03-01')
    shot(page, '25-document-new-file')
    page.click('[data-submit]')
    expect(page.locator('.alert--success').first).to_contain_text('опубликован')
    file_doc_url = page.url
    # неверный формат файла
    page.goto(BASE + '/documents/new')
    page.fill('#document_title', 'Плохой файл')
    bad = tempfile.NamedTemporaryFile('w', suffix='.exe', delete=False); bad.write('x'); bad.close()
    page.set_input_files('input[type=file]', bad.name)
    page.click('[data-submit]')
    expect(page.locator('.form-error').first).to_contain_text('не принимаются')
    shot(page, '26-document-file-error', full=False)
    # статистика документа
    page.goto(file_doc_url.rstrip('/') + '/stats')
    expect(page.locator('.chart__svg')).to_be_visible()
    shot(page, '27-document-stats')
    # новый подраздел
    page.goto(BASE + '/search?q=ИТ-РМ-002')
    page.click('.doc-table__title >> nth=0')
    page.click('.breadcrumbs a >> nth=-1')
    page.click('text=Новый подраздел')
    page.fill('#section_name', 'Принтеры и МФУ')
    shot(page, '28-section-new', full=False)
    page.click('.card--form button[type=submit]')
    expect(page.locator('.alert--success').first).to_contain_text('создан')
    # удаление тестового файла-документа
    page.goto(file_doc_url)
    page.click('form[action$="/delete"] button')
    expect(page.locator('.alert--success').first).to_contain_text('удалён')
    logout(page)

    # 4. Администратор
    login(page, 'admin')
    page.goto(BASE + '/admin')
    expect(page.locator('.chart__svg')).to_be_visible()
    shot(page, '30-admin-dashboard')
    page.goto(BASE + '/admin/sections')
    shot(page, '31-admin-sections')
    page.click('.tree-table a:has-text("Модераторы") >> nth=0')
    shot(page, '32-admin-moderators', full=False)
    page.select_option('#user_id', label='Смирнов Дмитрий Олегович (smirnov)')
    page.click('.card--form button[type=submit]')
    expect(page.locator('.alert--success').first).to_contain_text('модератором')
    page.click('tr:has-text("Смирнов") form[action*="/moderators/"] button')
    expect(page.locator('.alert--success').first).to_contain_text('больше не модерирует')
    page.goto(BASE + '/admin/sections/moderators')
    shot(page, '33-admin-moderators-all', full=False)
    page.goto(BASE + '/admin/users')
    shot(page, '34-admin-users')
    page.goto(BASE + '/admin/users/new')
    page.fill('#user_username', TEST_USER)
    page.fill('#user_displayName', 'Тестов Тест Тестович')
    page.fill('#user_email', TEST_USER + '@example.ru')
    page.fill('#user_plainPassword', 'Temp12345')
    page.check('#user_mustChangePassword')
    shot(page, '35-admin-user-form')
    page.click('.card--form form[name=user] button[type=submit]')
    expect(page.locator('.alert--success').first).to_contain_text('создан')
    page.goto(BASE + '/admin/documents')
    shot(page, '36-admin-documents')
    page.goto(BASE + '/admin/documents?validity=expired')
    assert page.locator('.doc-table tbody tr').count() >= 2
    page.goto(BASE + '/admin/statistics')
    shot(page, '37-admin-statistics')
    page.goto(BASE + '/admin/statistics/validity')
    expect(page.locator('.validity-table tbody tr').first).to_be_visible()
    assert page.locator('.outdated-table__group').count() >= 3, 'outdated documents must be grouped by section'
    shot(page, '37-admin-statistics-validity')
    page.check('input[name=only_problems]')
    expect(page).to_have_url(re.compile(r'only_problems=1'))
    expect(page.locator('.validity-table tbody tr.is-inactive')).to_have_count(0)
    print('validity by section ok')
    page.goto(BASE + '/admin/events')
    shot(page, '38-admin-events', full=False)
    page.goto(BASE + '/admin/settings')
    shot(page, '39-admin-settings')
    # гостевой доступ: ограничение по подсетям и возврат режима «отовсюду»
    page.check('input[name=mode][value=ip]')
    page.fill('#guest-networks', '10.0.0.0/8\n192.168.0.0/16')
    page.click('form[action$="/guest-access"] button[type=submit]')
    expect(page.locator('.alert--success').first).to_contain_text('гостевого доступа')
    shot(page, '39-admin-settings-guest', full=False)
    page.check('input[name=mode][value=all]')
    page.click('form[action$="/guest-access"] button[type=submit]')
    expect(page.locator('.alert--success').first).to_contain_text('любых адресов')
    page.click('form[action$="/ldap-test"] button')
    expect(page.locator('.alert--danger').first).to_contain_text('LDAP')
    page.click('form[action$="/expiry-run"] button')
    expect(page.locator('.alert--success').first).to_contain_text('Проверка сроков выполнена')
    shot(page, '40-admin-expiry-run', full=False)
    # 4б. Интеграции: ключ API, LLM (заглушка на LLM_MOCK), webhook
    page.goto(BASE + '/admin/integrations')
    expect(page.locator('h1')).to_contain_text('Интеграции')
    page.fill('#key-name', 'Индексатор RAG')
    page.click('form[action$="/integrations/keys"] button[type=submit]')
    api_token = page.locator('#new-token-value').input_value()
    assert api_token.startswith('dp_'), api_token
    shot(page, '45-admin-integrations')
    def api(path):
        req = urllib.request.Request(BASE + '/api/v1' + path, headers={'Authorization': 'Bearer ' + api_token})
        with urllib.request.urlopen(req) as resp:
            return json.loads(resp.read().decode('utf-8'))
    api_data = api('/documents?per_page=2')
    assert api_data['total'] > 0 and api_data['items'][0]['links']['text'], api_data
    # Лента изменений: скрытые и удалённые документы отдаются без названий (ключ видит только открытые).
    changes = api('/changes?since=2000-01-01T00:00:00Z')
    assert changes['changed'] and changes['next_since'] < changes['until'], changes['next_since']
    for item in changes['removed']:
        assert sorted(item.keys()) == ['id', 'reason', 'updated_at'], item
    internal_title = 'Правила работы с конфиденциальной информацией'
    assert internal_title not in json.dumps(changes, ensure_ascii=False), 'название внутреннего документа не должно попадать в ленту'
    print('api ok', api_data['total'], '| removed', len(changes['removed']), '| deleted', len(changes['deleted']))
    if LLM_MOCK:
        page.check('form[action$="/integrations/llm"] input[name=enabled]')
        page.check('form[action$="/integrations/llm"] input[name=auto_describe]')
        page.fill('#llm-base-url', LLM_MOCK)
        page.fill('#llm-model', 'qwen2.5:7b-instruct')
        page.fill('#llm-api-key', 'sk-demo-key-000000')
        page.click('form[action$="/integrations/llm"] button[type=submit]')
        expect(page.locator('.alert--success').first).to_contain_text('LLM сохранены')
        page.click('form[action$="/llm/test"] button')
        expect(page.locator('.alert--success').first).to_contain_text('ответила')
        page.click('form[action$="/llm/describe"] button')
        expect(page.locator('.alert--success').first).to_contain_text('Сформировано описаний')
        page.goto(BASE + '/admin/integrations#llm')
        shot(page, '46-admin-integrations-llm')
        # карточка документа с описанием от ИИ
        page.goto(BASE + '/search?q=ПОЛ-003')
        page.goto(BASE + page.locator('.doc-table__title').first.get_attribute('href'))
        expect(page.locator('.ai-badge')).to_be_visible()
        shot(page, '47-document-ai-description', full=False)
    # 4а. Импорт из каталога: папки → разделы, файлы → документы
    make_import_tree(IMPORT_DIR / 'Архив ОТК')
    page.goto(BASE + '/admin/import')
    expect(page.locator('#import-folder')).to_contain_text('Архив ОТК')
    page.select_option('#import-folder', 'Архив ОТК')
    page.select_option('#import-section', label='Производство')
    page.check('#import-root-as-section')
    shot(page, '42-admin-import')
    page.click('button[type=submit]:has-text("Проверить")')
    expect(page.locator('h1')).to_contain_text('План импорта')
    expect(page.locator('.plan-table')).to_contain_text('новый раздел')
    shot(page, '43-admin-import-preview')
    page.click('button:has-text("Начать импорт")')
    expect(page).to_have_url(re.compile(r'/admin/import/jobs/'))
    expect(page.locator('[data-job-status]')).to_contain_text('Завершён', timeout=60000)
    counters = {el.get_attribute('data-counter'): el.inner_text() for el in page.locator('[data-counter]').all()}
    assert counters['sectionsNew'] == '6' and counters['docsNew'] == '7' and counters['errors'] == '0', counters
    shot(page, '44-admin-import-job')
    page.click('[data-job-open]')
    expect(page.locator('h1')).to_contain_text('Архив ОТК')
    expect(page.locator('.section-card')).to_have_count(3)
    print('import ok', counters)
    # CSV
    with page.expect_download() as dl:
        page.goto(BASE + '/admin/documents')
        page.click('text=Выгрузить CSV')
    assert dl.value.suggested_filename.endswith('.csv')
    # попытка снять права администратора с себя
    page.goto(BASE + '/admin/users')
    page.click('tr:has-text("это вы") a:has-text("Изменить")')
    page.check('#user_role_0')
    page.click('.card--form form[name=user] button[type=submit]')
    expect(page.locator('.alert--danger').first).to_be_visible()
    print('self-demote blocked ok')
    logout(page)

    # 5. Пользователь с временным паролем → принудительная смена
    login(page, TEST_USER, 'Temp12345')
    expect(page).to_have_url(BASE + '/profile/password')
    shot(page, '41-forced-password', full=False)
    page.fill('#change_password_currentPassword', 'Temp12345')
    page.fill('#change_password_newPassword_first', 'NewPass12345')
    page.fill('#change_password_newPassword_second', 'NewPass12345')
    page.click('.card--form button[type=submit]')
    expect(page).to_have_url(BASE + '/')
    print('forced password change ok')

    # 6. Мобильный вид
    mob = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, locale='ru-RU')
    mp = mob.new_page()
    mp.goto(BASE + '/login'); mp.fill('#username', 'smirnov'); mp.fill('#password', PASSWORD); mp.click('button[type=submit]')
    mp.wait_for_url(BASE + '/')
    mp.screenshot(path=str(OUT / '50-mobile-home.png'), full_page=False)
    mp.goto(BASE + doc_url)
    mp.screenshot(path=str(OUT / '51-mobile-document.png'), full_page=False)
    print('screenshot mobile')

    # 7. Троттлинг входа
    ctx2 = browser.new_context(locale='ru-RU'); p2 = ctx2.new_page()
    for i in range(6):
        p2.goto(BASE + '/login'); p2.fill('#username', 'smirnov'); p2.fill('#password', 'bad' + str(i)); p2.click('button[type=submit]')
    txt = p2.locator('.alert--danger').inner_text()
    print('throttle message:', txt)
    assert 'Слишком много' in txt, txt

    browser.close()
    js_errors = [e for e in errors if 'favicon' not in e and '403' not in e and '404' not in e and '422' not in e]
    print('JS/console/HTTP errors:', js_errors)
    assert not js_errors, js_errors
    print('ALL OK')
