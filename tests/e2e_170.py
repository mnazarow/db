"""
Сквозная проверка возможностей 1.7.0 через браузер (Playwright) и скриншоты для документации:
мониторинг, сводка ознакомлений по подразделениям, журнал таблиц материалов, поиск по фразе, лист
ознакомления в PDF и его проверка по коду, PDF-сборник раздела, редактирование в ONLYOFFICE (через
заглушку сервера документов tests/mock_onlyoffice_server.py).

Перед запуском: демо-данные (php bin/console app:demo:load --force), очистка кэшей
(php bin/console cache:pool:clear --all) и сервер с несколькими обработчиками — заглушка сервера
документов обращается к порталу, пока он отвечает браузеру:
    PHP_CLI_SERVER_WORKERS=4 ./scripts/dev-server.sh
Запуск: python3 tests/e2e_170.py http://127.0.0.1:8080 docs/images
"""
import pathlib
import re
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request

from playwright.sync_api import expect, sync_playwright

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
OUT = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else 'docs/images')
OUT.mkdir(parents=True, exist_ok=True)
PASSWORD = 'Demo12345'
MOCK_PORT = 8095
MOCK_SECRET = 'e2e-secret'
TMP = pathlib.Path(tempfile.mkdtemp())


def shot(page, name, full=True):
    page.evaluate('window.scrollTo(0, 0)')
    page.wait_for_timeout(300)
    page.screenshot(path=str(OUT / f'{name}.png'), full_page=full)
    print('screenshot', name)


def element_shot(page, selector, name):
    """Скриншот блока страницы; закреплённая шапка сайта на время снимка не перекрывает блок."""
    page.add_style_tag(content='.site-header { position: static !important; }')
    element = page.locator(selector).first
    element.scroll_into_view_if_needed()
    page.wait_for_timeout(300)
    element.screenshot(path=str(OUT / f'{name}.png'))
    print('screenshot', name)


def login(page, user):
    page.goto(BASE + '/login')
    page.fill('#username', user)
    page.fill('#password', PASSWORD)
    page.click('button[type=submit]')
    page.wait_for_url(re.compile(r'^(?!.*/login).*$'))


def pdf_page_png(pdf_path, name, page_no=1, resolution=110):
    """Страница PDF в PNG для документации (pdftoppm из poppler-utils)."""
    prefix = TMP / name
    subprocess.run(['pdftoppm', '-f', str(page_no), '-l', str(page_no), '-r', str(resolution), '-png', '-singlefile', str(pdf_path), str(prefix)], check=True)
    target = OUT / f'{name}.png'
    target.write_bytes((TMP / f'{name}.png').read_bytes())
    print('screenshot', name, '(PDF)')


def pdf_text(pdf_path):
    return subprocess.run(['pdftotext', '-layout', str(pdf_path), '-'], check=True, capture_output=True, text=True).stdout


def doc_url(page, code):
    """Адрес карточки документа по обозначению (через поиск)."""
    page.goto(BASE + '/search?q=' + urllib.parse.quote(code))
    href = page.locator('a.doc-table__title, a.snippet__title').first.get_attribute('href')
    assert href and href.startswith('/documents/'), href
    return href


mock = subprocess.Popen([sys.executable, str(pathlib.Path(__file__).with_name('mock_onlyoffice_server.py')), str(MOCK_PORT), MOCK_SECRET])
time.sleep(0.8)
errors = []
try:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, device_scale_factor=1, locale='ru-RU', accept_downloads=True)
        page = ctx.new_page()
        page.on('console', lambda m: errors.append('console: ' + m.text) if m.type == 'error' else None)
        page.on('pageerror', lambda e: errors.append('pageerror: ' + str(e)))

        # 1. Администратор: мониторинг, ознакомления по подразделениям, таблицы материалов
        login(page, 'admin')
        page.goto(BASE + '/admin/monitoring')
        expect(page.locator('h1')).to_contain_text('Мониторинг')
        shot(page, '69-admin-monitoring')
        page.goto(BASE + '/admin/acknowledgements?view=departments')
        expect(page.locator('table.heatmap')).to_be_visible()
        expect(page.locator('table.heatmap')).to_contain_text('Отдел кадров')
        shot(page, '70-admin-acknowledgements-departments')
        page.goto(BASE + '/admin/materials')
        table_href = page.locator('.ms-pivot tbody th a').first.get_attribute('href')
        page.goto(BASE + table_href)
        page.locator('[data-row-menu]').first.click()
        page.locator('.mt-menu [data-menu=history]').click()
        expect(page.locator('[data-mt-history]')).to_be_visible()
        page.wait_for_timeout(500)
        page.screenshot(path=str(OUT / '71-materials-row-history.png'))
        print('screenshot 71-materials-row-history')
        page.keyboard.press('Escape')
        page.goto(BASE + table_href + '/history')
        expect(page.locator('h1')).to_contain_text('Журнал')
        shot(page, '72-materials-history')
        page.goto(BASE + '/admin/materials')
        element_shot(page, '#remind', '73-admin-materials-remind')

        # 2. Поиск по точной фразе
        page.goto(BASE + '/search?q=' + urllib.parse.quote('"испытательный срок"'))
        expect(page.locator('main')).to_contain_text('Правила внутреннего трудового распорядка')
        shot(page, '74-search-phrase', full=False)

        # 3. Интеграция с редактором документов (заглушка сервера документов)
        page.goto(BASE + '/admin/integrations#onlyoffice')
        form = page.locator('#onlyoffice form[action$="/onlyoffice"]')
        form.locator('input[name=enabled]').check()
        form.locator('#oo-url').fill('http://127.0.0.1:%d' % MOCK_PORT)
        form.locator('#oo-secret').fill(MOCK_SECRET)
        form.locator('button[type=submit]').click()
        expect(page.locator('.alert--success').first).to_contain_text('Настройки редактора документов сохранены')
        page.locator('#onlyoffice button:has-text("Проверить соединение")').click()
        expect(page.locator('.alert--success').first).to_contain_text('Сервер документов отвечает')
        element_shot(page, '#onlyoffice', '81-admin-integrations-onlyoffice')
        page.click('.site-header__user button[type=submit]')

        # 4. Модератор: лист ознакомления в PDF
        login(page, 'ivanov')
        url = doc_url(page, 'ОТ-001')
        page.goto(BASE + url)
        doc_id = re.search(r'/documents/(\d+)', url).group(1)
        page.goto(BASE + '/documents/%s/acknowledgements' % doc_id)
        expect(page.locator('.hero__actions')).to_contain_text('Лист в PDF')
        with page.expect_download() as dl:
            page.click('a:has-text("Лист в PDF")')
        sheet_pdf = TMP / 'sheet.pdf'
        dl.value.save_as(str(sheet_pdf))
        assert dl.value.suggested_filename.endswith('.pdf'), dl.value.suggested_filename
        text = pdf_text(sheet_pdf)
        code = re.search(r'Код проверки: ([0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4})', text).group(1)
        assert 'Лист ознакомления с документом' in text
        pdf_page_png(sheet_pdf, '75-signature-sheet')

        # 5. Проверка листа без входа (код набран строчными и с пробелами)
        guest = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        gp = guest.new_page()
        gp.on('console', lambda m: errors.append('guest console: ' + m.text) if m.type == 'error' else None)
        gp.goto(BASE + '/verify')
        gp.fill('#verify-code', code.lower().replace('-', ' '))
        shot(gp, '76-verify-form', full=False)
        gp.click('button[type=submit]')
        expect(gp).to_have_url(BASE + '/verify/' + code)
        expect(gp.locator('h1')).to_contain_text('Лист выдан порталом')
        expect(gp.locator('.alert--success')).to_contain_text('Лист актуален')
        shot(gp, '77-verify-sheet')
        guest.close()

        # 6. PDF-сборник раздела «Общие документы»
        page.goto(BASE + '/')
        page.click('a.section-card:has-text("Общие документы")')
        page.click('.hero__actions a:has-text("PDF-сборник")')
        expect(page.locator('h1')).to_contain_text('PDF-сборник раздела')
        expect(page.locator('[data-bundle-counter]')).to_contain_text('отмечено:')
        shot(page, '78-section-bundle')
        page.click('button:has-text("Собрать PDF")')
        expect(page.locator('[data-job-status]')).to_contain_text('Готов', timeout=180000)
        expect(page.locator('[data-job-download]')).to_be_visible()
        shot(page, '79-section-bundle-job')
        with page.expect_download() as dl:
            page.click('[data-job-download]')
        bundle_pdf = TMP / 'bundle.pdf'
        dl.value.save_as(str(bundle_pdf))
        text = pdf_text(bundle_pdf)
        assert 'Сборник документов' in text and 'Содержание' in text, text[:500]
        pdf_page_png(bundle_pdf, '80-bundle-cover')

        # 7. Редактирование онлайн: файл DOCX, правка сохраняется новой версией
        url = doc_url(page, 'ПОЛ-003')
        page.goto(BASE + url)
        versions_before = page.locator('table.versions-table tbody tr').count()
        edit = page.locator('.hero__actions a:has-text("Редактировать онлайн")')
        expect(edit).to_be_visible()
        edit.click()
        expect(page.locator('#mock-status')).to_contain_text('Подпись верна', timeout=20000)
        expect(page.locator('#mock-mode')).to_have_text('edit')
        page.screenshot(path=str(TMP / 'office-editor.png'))
        page.click('#mock-save')
        expect(page.locator('#mock-result')).to_have_text('Сохранено в портале', timeout=60000)
        page.goto(BASE + url)
        expect(page.locator('main')).to_contain_text('Изменено в редакторе онлайн')
        assert page.locator('table.versions-table tbody tr').count() == versions_before + 1
        print('office edit saved as a new version ok')
        # Сотрудник видит документ, но не правит его.
        page.click('.site-header__user button[type=submit]')
        login(page, 'smirnov')
        page.goto(BASE + url)
        expect(page.locator('.hero__actions')).not_to_contain_text('Редактировать онлайн')
        response = page.goto(BASE + url.split('?')[0] + '/office?mode=edit')
        assert response.status == 403, response.status

        browser.close()
finally:
    mock.terminate()

problems = [e for e in errors if 'favicon' not in e and '403' not in e and '404' not in e and '422' not in e]
print('JS/console errors:', problems)
assert not problems, problems
print('ALL 1.7.0 OK')
