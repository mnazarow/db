#!/usr/bin/env python3
"""Заглушка OpenAI-совместимого API для ручных проверок и e2e-тестов интеграции с LLM.

Запуск: python3 tests/mock_llm_server.py [порт]  (по умолчанию 8090)
В панели администратора → Интеграции укажите адрес http://127.0.0.1:8090/v1 и любую модель.
Описание собирается из реквизитов документа, переданных порталом в запросе.
"""
import json
import re
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):  # noqa: N802
        length = int(self.headers.get('Content-Length', '0'))
        raw = self.rfile.read(length).decode('utf-8', 'replace')
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            payload = {}
        user = ''
        for message in payload.get('messages', []):
            if message.get('role') == 'user':
                user = str(message.get('content', ''))
        title = self._field(user, 'Название') or 'Документ'
        section = self._field(user, 'Раздел')
        code = self._field(user, 'Обозначение/номер')
        has_text = 'Текст документа' in user
        text = f'«{title}»' + (f' ({code})' if code else '') + ' — документ раздела «' + (section or 'портала') + '». '
        text += ('Устанавливает порядок, требования и ответственность по своей теме; предназначен для сотрудников, '
                 'которых он касается, и используется как справочный материал при выполнении работ.'
                 if has_text else
                 'Текст файла для анализа недоступен; описание составлено по названию и разделу.')
        if 'готово' in user.lower():
            text = 'готово'
        body = json.dumps({
            'id': 'chatcmpl-mock',
            'object': 'chat.completion',
            'model': payload.get('model', 'mock'),
            'choices': [{'index': 0, 'message': {'role': 'assistant', 'content': text}, 'finish_reason': 'stop'}],
            'usage': {'prompt_tokens': len(user) // 4, 'completion_tokens': len(text) // 4},
        }, ensure_ascii=False).encode('utf-8')
        self.send_response(200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    @staticmethod
    def _field(text: str, name: str) -> str:
        match = re.search(r'^' + re.escape(name) + r': (.+)$', text, re.M)
        return match.group(1).strip() if match else ''

    def log_message(self, fmt, *args):  # noqa: A003
        sys.stderr.write('[mock-llm] ' + (fmt % args) + '\n')


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8090
    print(f'Mock LLM API: http://127.0.0.1:{port}/v1/chat/completions', flush=True)
    HTTPServer(('127.0.0.1', port), Handler).serve_forever()
