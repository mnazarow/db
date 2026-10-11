#!/usr/bin/env python3
"""Заглушка сервера документов ONLYOFFICE / Р7-Офис для e2e-проверки интеграции (не редактор!).

Запуск: python3 tests/mock_onlyoffice_server.py [порт] [секрет JWT]  (по умолчанию 8095 и e2e-secret)
В панели администратора → Интеграции → «Редактор документов» укажите адрес http://127.0.0.1:<порт>
и тот же секрет.

Что делает, как настоящий сервер документов:
* /web-apps/apps/api/documents/api.js — объект DocsAPI.DocEditor; «редактор» показывает название файла,
  режим и кнопку «Закрыть и сохранить»;
* проверяет подпись конфигурации (token, HS256) и скачивает файл по document.url — без сессии портала;
* по кнопке дописывает абзац в DOCX и присылает на editorConfig.callbackUrl уведомление status=2 с адресом
  изменённого файла (/cache/...) и токеном в теле; портал скачивает файл и сохраняет новую версию;
* /healthcheck отвечает true (кнопка «Проверить соединение»).
"""
import base64
import hashlib
import hmac
import io
import json
import sys
import threading
import urllib.request
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8095
SECRET = sys.argv[2] if len(sys.argv) > 2 else 'e2e-secret'
SESSIONS = {}
LOCK = threading.Lock()


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode()


def unb64url(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + '=' * (-len(text) % 4))


def jwt_encode(payload: dict) -> str:
    header = b64url(json.dumps({'alg': 'HS256', 'typ': 'JWT'}).encode())
    body = b64url(json.dumps(payload, ensure_ascii=False).encode())
    signature = hmac.new(SECRET.encode(), f'{header}.{body}'.encode(), hashlib.sha256).digest()
    return f'{header}.{body}.{b64url(signature)}'


def jwt_decode(token: str) -> dict:
    header, body, signature = token.split('.')
    expected = hmac.new(SECRET.encode(), f'{header}.{body}'.encode(), hashlib.sha256).digest()
    if not hmac.compare_digest(expected, unb64url(signature)):
        raise ValueError('подпись не сходится')
    return json.loads(unb64url(body))


def edited_docx(original: bytes) -> bytes:
    """DOCX с дописанным абзацем; не DOCX — исходные байты и строка в конце."""
    try:
        source = zipfile.ZipFile(io.BytesIO(original))
        out = io.BytesIO()
        with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as target:
            for item in source.infolist():
                data = source.read(item.filename)
                if item.filename == 'word/document.xml':
                    data = data.replace(b'</w:body>', '<w:p><w:r><w:t>Правка из редактора онлайн</w:t></w:r></w:p></w:body>'.encode()) \
                        if b'<w:sectPr' not in data else data.replace(b'<w:sectPr', '<w:p><w:r><w:t>Правка из редактора онлайн</w:t></w:r></w:p><w:sectPr'.encode(), 1)
                target.writestr(item, data)
        return out.getvalue()
    except zipfile.BadZipFile:
        return original + '\nПравка из редактора онлайн\n'.encode()


API_JS = r"""
window.DocsAPI = {
  DocEditor: function (id, config) {
    var mock = '%(base)s';
    var el = document.getElementById(id);
    el.innerHTML = '<div class="mock-editor" style="padding:28px 32px;font:15px/1.5 sans-serif;background:#f3f5f8;height:100%%;box-sizing:border-box">' +
      '<div style="font-size:12px;color:#667;text-transform:uppercase;letter-spacing:.08em">Заглушка сервера документов (e2e)</div>' +
      '<h2 style="margin:8px 0 4px">' + config.document.title + '</h2>' +
      '<div>Режим: <b id="mock-mode">' + config.editorConfig.mode + '</b> · пользователь: ' + config.editorConfig.user.name + '</div>' +
      '<div id="mock-status" style="margin:10px 0;color:#456">Открытие…</div>' +
      (config.editorConfig.mode === 'edit' ? '<button id="mock-save" type="button" style="padding:8px 16px">Закрыть и сохранить</button>' : '') +
      '<div id="mock-result" style="margin-top:10px"></div></div>';
    fetch(mock + '/mock/open', { method: 'POST', body: JSON.stringify(config) })
      .then(function (r) { return r.json(); })
      .then(function (d) { el.querySelector('#mock-status').textContent = d.status; });
    var save = el.querySelector('#mock-save');
    if (save) {
      save.addEventListener('click', function () {
        fetch(mock + '/mock/save', { method: 'POST', body: JSON.stringify({ key: config.document.key }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { el.querySelector('#mock-result').textContent = d.result; });
      });
    }
  }
};
"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):  # noqa: D401
        sys.stderr.write('mock-onlyoffice: ' + fmt % args + '\n')

    def _send(self, status, body, content_type='application/json; charset=utf-8'):
        data = body if isinstance(body, bytes) else body.encode()
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(data)

    def do_OPTIONS(self):  # noqa: N802
        self.send_response(204)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()

    def do_GET(self):  # noqa: N802
        if self.path == '/healthcheck':
            return self._send(200, 'true', 'text/plain')
        if self.path.startswith('/web-apps/apps/api/documents/api.js'):
            return self._send(200, API_JS % {'base': f'http://127.0.0.1:{PORT}'}, 'application/javascript; charset=utf-8')
        if self.path.startswith('/cache/'):
            key = self.path.split('/')[2]
            with LOCK:
                session = SESSIONS.get(key)
            if not session or 'output' not in session:
                return self._send(404, '{}')
            return self._send(200, session['output'], 'application/octet-stream')
        return self._send(404, '{}')

    def do_POST(self):  # noqa: N802
        length = int(self.headers.get('Content-Length', '0'))
        payload = json.loads(self.rfile.read(length) or b'{}')
        if self.path == '/mock/open':
            token = payload.pop('token', None)
            payload.pop('events', None)  # обработчики событий браузер добавляет сам, в подпись они не входят
            if token is None:
                return self._send(200, json.dumps({'status': 'Ошибка: конфигурация без подписи (token)'}, ensure_ascii=False))
            try:
                signed = jwt_decode(token)
            except ValueError as e:
                return self._send(200, json.dumps({'status': f'Ошибка подписи: {e}'}, ensure_ascii=False))
            if signed != payload:
                return self._send(200, json.dumps({'status': 'Ошибка: подписанная конфигурация отличается'}, ensure_ascii=False))
            # Сервер документов скачивает файл сам, без cookies браузера.
            with urllib.request.urlopen(payload['document']['url'], timeout=30) as response:
                original = response.read()
            with LOCK:
                SESSIONS[payload['document']['key']] = {'config': payload, 'original': original}
            return self._send(200, json.dumps({'status': f'Подпись верна, файл получен ({len(original)} байт)'}, ensure_ascii=False))
        if self.path == '/mock/save':
            key = payload.get('key')
            with LOCK:
                session = SESSIONS.get(key)
            if not session:
                return self._send(200, json.dumps({'result': 'Сессия не найдена'}, ensure_ascii=False))
            config = session['config']
            session['output'] = edited_docx(session['original'])
            body = {
                'key': key,
                'status': 2,
                'url': f'http://127.0.0.1:{PORT}/cache/{key}/output.{config["document"]["fileType"]}',
                'filetype': config['document']['fileType'],
                'users': [config['editorConfig']['user']['id']],
                'actions': [{'type': 0, 'userid': config['editorConfig']['user']['id']}],
            }
            body['token'] = jwt_encode(dict(body))
            request = urllib.request.Request(config['editorConfig']['callbackUrl'], data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'}, method='POST')
            with urllib.request.urlopen(request, timeout=60) as response:
                answer = json.loads(response.read() or b'{}')
            result = 'Сохранено в портале' if answer.get('error') == 0 else f'Портал ответил ошибкой: {answer}'
            return self._send(200, json.dumps({'result': result}, ensure_ascii=False))
        return self._send(404, '{}')


if __name__ == '__main__':
    print(f'Заглушка сервера документов: http://127.0.0.1:{PORT} (секрет JWT: {SECRET})')
    ThreadingHTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
