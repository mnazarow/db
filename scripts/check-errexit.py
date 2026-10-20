#!/usr/bin/env python3
"""Проверка скриптов развёртывания на присваивания, которые под «set -e» прерывают скрипт.

    VAR="…$( [[ условие ]] && echo текст )…"

При ложном условии подстановка завершается с кодом 1, присваивание возвращает этот код, и set -e
останавливает скрипт — так в 1.8.0 падала резервная копия с разделом «Видео». Внутри echo/printf/
info/die такая подстановка безопасна (их код не зависит от подстановки), в local/export — тоже
(код маскируется), а в простом присваивании — нет. Безопасные варианты: «… || echo другое»,
отдельный if, «[[ ! условие ]] || VAR+=…».

Использование: scripts/check-errexit.py ФАЙЛ… (код 1 — найдены опасные присваивания).
"""
import re
import sys

ASSIGN = re.compile(r'^\s*(?:[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]*\])?\+?=)')
MASKED = re.compile(r'^\s*(?:local|export|declare|readonly|typeset)\b')
TEST_START = re.compile(r'^\s*(?:\[\[|\[\s|test\s)')


def substitutions(line: str):
    """Содержимое подстановок $( … ) верхнего уровня (без $(( … )))."""
    i = 0
    while True:
        j = line.find('$(', i)
        if j < 0:
            return
        if line.startswith('$((', j):
            i = j + 3
            continue
        depth, k = 0, j + 1
        while k < len(line):
            if line[k] == '(':
                depth += 1
            elif line[k] == ')':
                depth -= 1
                if depth == 0:
                    break
            k += 1
        yield line[j + 2:k]
        i = k + 1


def check(path: str) -> int:
    problems = 0
    with open(path, encoding='utf-8') as fh:
        for n, line in enumerate(fh, 1):
            stripped = line.strip()
            if stripped.startswith('#') or not ASSIGN.match(line) or MASKED.match(line):
                continue
            for sub in substitutions(line):
                # «||» внутри [[ … ]] — часть условия, а не запасная ветка.
                outer = re.sub(r'\[\[.*?\]\]', '[[ ]]', sub)
                if TEST_START.match(sub) and '&&' in outer and '||' not in outer:
                    print(f'{path}:{n}: присваивание с «$( [[ … ]] && … )» прервёт скрипт под set -e, '
                          f'если условие ложно: {stripped[:140]}')
                    problems += 1
    return problems


def main(argv) -> int:
    total = sum(check(p) for p in argv[1:])
    if total:
        print(f'Найдено опасных присваиваний: {total}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
