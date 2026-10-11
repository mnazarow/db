// Выгрузка таблиц в CSV (UTF-8 с BOM и точкой с запятой — открывается в Excel без настройки).
export function toCsv(rows, columns) {
  const esc = (v) => {
    if (v === null || v === undefined) return '';
    let s = v instanceof Date ? v.toISOString() : String(v);
    // Название видео или ответ анкеты, начинающиеся с = + - @, Excel считает формулой и выполняет.
    // Апостроф впереди делает значение обычным текстом.
    if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
    return /[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const head = columns.map((c) => esc(c.title)).join(';');
  const body = rows.map((r) => columns.map((c) => esc(typeof c.value === 'function' ? c.value(r) : r[c.value])).join(';'));
  return '﻿' + [head, ...body].join('\r\n');
}

export function sendCsv(reply, filename, csv) {
  reply.header('Content-Type', 'text/csv; charset=utf-8');
  reply.header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(filename)}`);
  return reply.send(csv);
}
