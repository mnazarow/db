#!/usr/bin/env python3
"""Заглушка провайдера OpenID Connect для ручных проверок и e2e-тестов входа через SSO.

Запуск: python3 tests/mock_sso_server.py [порт] [логин]   (по умолчанию 8091 и petrova)
В панели администратора → «Вход и безопасность» укажите адрес http://127.0.0.1:8091,
идентификатор клиента docportal и любой секрет.

Провайдер сразу «узнаёт» заданного пользователя и возвращает портал обратно с кодом:
формы входа нет, поэтому проверка проходит без участия человека.
"""
import base64
import json
import sys
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8091
LOGIN = sys.argv[2] if len(sys.argv) > 2 else 'petrova'
ISSUER = f'http://127.0.0.1:{PORT}'
CLIENT_ID = 'docportal'

# Выданные коды: код → (nonce, redirect_uri).
CODES: dict[str, tuple[str, str]] = {}

CLAIMS = {
    'preferred_username': LOGIN,
    'name': 'Петрова Мария Сергеевна' if LOGIN == 'petrova' else LOGIN,
    'email': f'{LOGIN}@example.ru',
    'department': 'ИТ-отдел',
    'groups': ['docportal-users'],
}


def b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).decode().rstrip('=')


def id_token(nonce: str) -> str:
    """JWT без настоящей подписи: портал получает токен напрямую по каналу связи и проверяет поля."""
    header = {'alg': 'RS256', 'typ': 'JWT', 'kid': 'mock'}
    payload = {
        'iss': ISSUER,
        'aud': CLIENT_ID,
        'sub': 'mock-' + LOGIN,
        'exp': int(time.time()) + 300,
        'iat': int(time.time()),
        'nonce': nonce,
        **CLAIMS,
    }
    return '.'.join([
        b64url(json.dumps(header).encode()),
        b64url(json.dumps(payload, ensure_ascii=False).encode()),
        b64url(b'mock-signature'),
    ])


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        url = urllib.parse.urlparse(self.path)
        query = urllib.parse.parse_qs(url.query)
        if url.path == '/.well-known/openid-configuration':
            return self._json({
                'issuer': ISSUER,
                'authorization_endpoint': ISSUER + '/authorize',
                'token_endpoint': ISSUER + '/token',
                'userinfo_endpoint': ISSUER + '/userinfo',
                'jwks_uri': ISSUER + '/jwks',
                'response_types_supported': ['code'],
                'subject_types_supported': ['public'],
                'id_token_signing_alg_values_supported': ['RS256'],
                'code_challenge_methods_supported': ['S256'],
            })
        if url.path == '/authorize':
            redirect_uri = query.get('redirect_uri', [''])[0]
            state = query.get('state', [''])[0]
            nonce = query.get('nonce', [''])[0]
            code = 'mock-code-' + b64url(str(time.time()).encode())
            CODES[code] = (nonce, redirect_uri)
            location = redirect_uri + ('&' if '?' in redirect_uri else '?') + urllib.parse.urlencode({'code': code, 'state': state})
            self.send_response(302)
            self.send_header('Location', location)
            self.end_headers()
            return None
        if url.path == '/userinfo':
            return self._json({'sub': 'mock-' + LOGIN, **CLAIMS})
        return self._json({'error': 'not_found'}, 404)

    def do_POST(self):  # noqa: N802
        length = int(self.headers.get('Content-Length', '0'))
        form = urllib.parse.parse_qs(self.rfile.read(length).decode('utf-8', 'replace'))
        if urllib.parse.urlparse(self.path).path != '/token':
            return self._json({'error': 'not_found'}, 404)
        code = form.get('code', [''])[0]
        if code not in CODES:
            return self._json({'error': 'invalid_grant'}, 400)
        nonce, _ = CODES.pop(code)
        if form.get('client_id', [''])[0] != CLIENT_ID or not form.get('code_verifier', [''])[0]:
            return self._json({'error': 'invalid_client'}, 400)
        return self._json({
            'access_token': 'mock-access-token',
            'token_type': 'Bearer',
            'expires_in': 300,
            'id_token': id_token(nonce),
        })

    def _json(self, data, status=200):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        return None

    def log_message(self, fmt, *args):  # noqa: A003
        sys.stderr.write('[mock-sso] ' + (fmt % args) + '\n')


if __name__ == '__main__':
    print(f'Mock OIDC provider: {ISSUER} (пользователь {LOGIN}, client_id {CLIENT_ID})', flush=True)
    HTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
