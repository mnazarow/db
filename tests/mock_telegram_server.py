#!/usr/bin/env python3
"""Заглушка Telegram Bot API для ручных проверок и e2e-тестов уведомлений.

Запуск: python3 tests/mock_telegram_server.py [порт]   (по умолчанию 8092)
В панели администратора → «Интеграции» → «Уведомления в Telegram» укажите адрес
http://127.0.0.1:8092, любой токен и имя бота docportal_bot.

Сервер отвечает как настоящий Bot API:
  getMe       — сведения о боте;
  sendMessage — принимает сообщение и складывает его в память (GET /_sent — посмотреть, что ушло);
  getUpdates  — отдаёт очередь входящих сообщений (GET /_push?chat=123&text=КОД — положить в очередь).
"""
import json
import sys
import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8092
BOT = {'id': 1234567, 'is_bot': True, 'first_name': 'Портал документации', 'username': 'docportal_bot'}

SENT: list[dict] = []
UPDATES: list[dict] = []
NEXT_ID = [1]


class Handler(BaseHTTPRequestHandler):
    def reply(self, payload: dict, status: int = 200) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        path, _, query = self.path.partition('?')
        params = urllib.parse.parse_qs(query)
        if path == '/_sent':
            self.reply({'sent': SENT})
        elif path == '/_push':
            chat = params.get('chat', ['1001'])[0]
            text = params.get('text', [''])[0]
            name = params.get('name', ['Иван'])[0]
            NEXT_ID[0] += 1
            UPDATES.append({
                'update_id': NEXT_ID[0],
                'message': {
                    'message_id': NEXT_ID[0],
                    'chat': {'id': int(chat), 'type': 'private'},
                    'from': {'id': int(chat), 'first_name': name},
                    'text': text,
                },
            })
            self.reply({'ok': True, 'queued': len(UPDATES)})
        elif path == '/_reset':
            SENT.clear()
            UPDATES.clear()
            self.reply({'ok': True})
        else:
            self.do_POST()

    def do_POST(self) -> None:
        path, _, _ = self.path.partition('?')
        method = path.rsplit('/', 1)[-1]
        length = int(self.headers.get('Content-Length') or 0)
        try:
            data = json.loads(self.rfile.read(length) or b'{}')
        except json.JSONDecodeError:
            data = {}
        if method == 'getMe':
            self.reply({'ok': True, 'result': BOT})
        elif method == 'sendMessage':
            SENT.append(data)
            self.reply({'ok': True, 'result': {'message_id': len(SENT), 'chat': {'id': data.get('chat_id')}, 'text': data.get('text')}})
        elif method == 'getUpdates':
            offset = int(data.get('offset') or 0)
            pending = [u for u in UPDATES if u['update_id'] >= offset]
            self.reply({'ok': True, 'result': pending})
        else:
            self.reply({'ok': False, 'error_code': 404, 'description': 'Unknown method ' + method}, 404)

    def log_message(self, *args) -> None:  # без шума в консоли
        pass


if __name__ == '__main__':
    print(f'Заглушка Telegram Bot API: http://127.0.0.1:{PORT} (бот @{BOT["username"]})')
    HTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
