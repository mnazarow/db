// Таблица материалов раздела: просмотр и правка «как в Excel».
// Данные приходят в JSON (#mt-config, #mt-rows), изменения уходят POST-запросами с CSRF-токеном в заголовке.
(function () {
    'use strict';

    var configNode = document.getElementById('mt-config');
    var rowsNode = document.getElementById('mt-rows');
    if (!configNode || !rowsNode) { return; }

    var config = JSON.parse(configNode.textContent);
    var rows = JSON.parse(rowsNode.textContent);
    var params = config.parameters || [];
    var kinds = config.kinds || [];

    var table = document.querySelector('[data-mt-table]');
    var body = document.querySelector('[data-mt-body]');
    var emptyNote = document.querySelector('[data-mt-empty]');
    var summaryBox = document.querySelector('[data-mt-summary]');
    var toastBox = document.querySelector('[data-mt-toast]');
    var fileInput = document.querySelector('[data-mt-file]');
    var dialog = document.querySelector('[data-mt-dialog]');

    var columns = [{ key: 'title', type: 'title', editable: true }, { key: 'kind', type: 'kind', editable: true }, { key: 'state', type: 'state', editable: false }]
        .concat(params.map(function (p) { return { key: p.key, type: p.kind, param: p, editable: true }; }))
        .concat([{ key: 'file', type: 'file', editable: false }]);

    var active = null;      // выбранная ячейка: {row: id, key: 'title'}
    var editing = null;     // открытый редактор: {row, key, input, original}
    var sort = { key: null, dir: 1 };
    var filters = { q: '', kind: '', state: '' };
    var uploadRow = null;
    var menu = null;

    // ---- Вспомогательное ---------------------------------------------------------------------------

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function findRow(id) {
        for (var i = 0; i < rows.length; i++) { if (rows[i].id === id) { return rows[i]; } }
        return null;
    }

    function rowIndex(id) {
        for (var i = 0; i < rows.length; i++) { if (rows[i].id === id) { return i; } }
        return -1;
    }

    function column(key) {
        for (var i = 0; i < columns.length; i++) { if (columns[i].key === key) { return columns[i]; } }
        return null;
    }

    function rawValue(row, key) {
        if (key === 'title') { return row.title; }
        if (key === 'kind') { return row.kind ? String(row.kind.id) : ''; }
        if (key === 'state') { return row.state; }
        return row.values[key] ? row.values[key].value : '';
    }

    function displayValue(row, key) {
        if (key === 'kind') { return row.kind ? row.kind.name : ''; }
        if (row.values && row.values[key]) { return row.values[key].display; }
        return rawValue(row, key);
    }

    function toast(message, isError) {
        if (!toastBox) { return; }
        toastBox.textContent = message;
        toastBox.hidden = false;
        toastBox.classList.toggle('mt-toast--error', !!isError);
        clearTimeout(toast.timer);
        toast.timer = setTimeout(function () { toastBox.hidden = true; }, isError ? 7000 : 4000);
    }

    function request(path, data) {
        var form = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.keys(data || {}).forEach(function (name) { form.append(name, data[name]); });
        }
        return fetch(config.base + path, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': config.token, 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false, error: 'Ошибка сервера (' + response.status + ').' }; })
                .then(function (json) { json.status = response.status; return json; });
        }).catch(function () {
            return { ok: false, error: 'Нет связи с порталом — изменение не сохранено.' };
        });
    }

    // ---- Отрисовка ---------------------------------------------------------------------------------

    function chipClass(value) {
        switch (value) {
            case 'Соответствует': return 'ok';
            case 'Не соответствует': return 'fail';
            case 'Отсутствует': return 'absent';
            case 'Не выбрано': return 'none';
            default: return 'neutral';
        }
    }

    function shortUrl(url) {
        try {
            var parsed = new URL(url, location.href);
            var tail = decodeURIComponent(parsed.pathname.split('/').filter(Boolean).pop() || '');
            return parsed.host + (tail ? ' / ' + (tail.length > 28 ? tail.slice(0, 27) + '…' : tail) : '');
        } catch (e) {
            return url.length > 40 ? url.slice(0, 39) + '…' : url;
        }
    }

    function cellHtml(row, col) {
        var value;
        switch (col.type) {
            case 'title':
                return esc(row.title) || '<span class="mt-muted">без названия</span>';
            case 'kind':
                return row.kind ? '<span class="mt-chip mt-chip--kind">' + esc(row.kind.name) + '</span>' : '';
            case 'state':
                return row.state ? '<span class="state-badge state-badge--' + esc(row.stateClass) + '">' + esc(row.state) + '</span>' : '<span class="mt-muted">—</span>';
            case 'file':
                return fileHtml(row);
            case 'list':
                value = rawValue(row, col.key);
                return value ? '<span class="mt-chip mt-chip--' + chipClass(value) + '">' + esc(value) + '</span>' : '';
            case 'link':
                value = rawValue(row, col.key);
                if (!value) { return ''; }
                if (!/^(https?:\/\/|\/)/i.test(value)) { return esc(value); }
                return '<a class="mt-link" href="' + esc(value) + '" target="_blank" rel="noopener noreferrer" title="' + esc(value) + '">' + esc(shortUrl(value)) + '</a>';
            default:
                return esc(displayValue(row, col.key));
        }
    }

    function fileHtml(row) {
        var doc = row.document;
        if (!doc) {
            return '<button type="button" class="mt-upload" data-upload title="Загрузить файл: документ получит название и тип из строки">Загрузить файл</button>';
        }
        if (doc.hidden) {
            // Документ перенесён в раздел, где у сотрудника нет прав, или доступ к нему ограничен.
            return '<span class="mt-muted" title="Документ перенесён в другой раздел или доступ к нему ограничен. Его можно отвязать от строки в меню «⋯».">документ недоступен</span>';
        }
        var ext = (doc.extension || 'файл').toUpperCase().slice(0, 4);
        var draft = doc.status !== 'published' ? '<span class="tag tag--kind" title="Документ ещё не опубликован">' + (doc.status === 'archived' ? 'архив' : 'черновик') + '</span>' : '';
        return '<span class="mt-file">'
            + '<a class="mt-file__link" href="' + esc(doc.url) + '" title="' + esc(doc.fileName || doc.title) + '">'
            + '<span class="mt-file__ext">' + esc(ext) + '</span>'
            + '<span class="mt-file__name">' + esc(doc.fileName || doc.title) + '</span></a>'
            + '<span class="mt-file__ver">v' + esc(doc.version) + '</span>' + draft
            + (doc.manageable ? '<button type="button" class="mt-file__again" data-upload title="Загрузить новую версию" aria-label="Загрузить новую версию файла «' + esc(row.title) + '»">⤒</button>' : '')
            + '</span>';
    }

    function canUpload(row) {
        return !row.document || row.document.manageable;
    }

    function cellClasses(row, col) {
        var classes = 'mt__cell mt__cell--' + col.type + (col.editable ? ' mt__cell--editable' : '');
        if (col.type === 'state' && row.state) { classes += ' mt__cell--state-' + row.stateClass; }
        return classes;
    }

    function rowHtml(row, number) {
        var html = '<tr data-row="' + row.id + '" class="mt__row">';
        html += '<td class="mt__num">' + number + '</td>';
        columns.forEach(function (col) {
            html += '<td class="' + cellClasses(row, col) + '" data-key="' + col.key + '" tabindex="-1">' + cellHtml(row, col) + '</td>';
        });
        html += '<td class="mt__actions"><button type="button" class="mt__row-menu" data-row-menu aria-haspopup="menu" aria-label="Действия со строкой «' + esc(row.title || 'без названия') + '»" title="Действия со строкой">⋯</button></td>';
        return html + '</tr>';
    }

    function visibleRows() {
        var list = rows.map(function (row, index) { return { row: row, number: index + 1 }; });
        list = list.filter(function (item) {
            var row = item.row;
            if (filters.q && row.title.toLowerCase().indexOf(filters.q) === -1) { return false; }
            if (filters.kind === 'none' && row.kind) { return false; }
            if (filters.kind && filters.kind !== 'none' && (!row.kind || String(row.kind.id) !== filters.kind)) { return false; }
            if (filters.state === 'none' && row.state) { return false; }
            if (filters.state && filters.state !== 'none' && row.state !== filters.state) { return false; }
            return true;
        });
        if (sort.key) {
            var col = column(sort.key);
            list.sort(function (a, b) {
                var x = sort.key === 'state' ? a.row.state : displayValue(a.row, sort.key);
                var y = sort.key === 'state' ? b.row.state : displayValue(b.row, sort.key);
                if (col && col.type === 'number') {
                    // Сравниваем хранимые значения («1.5»): в показе дробная часть — через запятую.
                    x = rawValue(a.row, sort.key); y = rawValue(b.row, sort.key);
                    if (x === '' || y === '') { return x === y ? 0 : (x === '' ? 1 : -1); }
                    return sort.dir * ((parseFloat(x) || 0) - (parseFloat(y) || 0));
                }
                if (col && col.type === 'date') { x = rawValue(a.row, sort.key); y = rawValue(b.row, sort.key); }
                if (!x && y) { return 1; }
                if (x && !y) { return -1; }
                return sort.dir * String(x).localeCompare(String(y), 'ru', { numeric: true });
            });
        }
        return list;
    }

    function render() {
        body.innerHTML = visibleRows().map(function (item) { return rowHtml(item.row, item.number); }).join('');
        emptyNote.hidden = rows.length > 0;
        table.querySelectorAll('[data-sort]').forEach(function (button) {
            var th = button.closest('th');
            var sorted = sort.key === button.dataset.sort;
            th.classList.toggle('is-sorted-asc', sorted && sort.dir === 1);
            th.classList.toggle('is-sorted-desc', sorted && sort.dir === -1);
            th.setAttribute('aria-sort', sorted ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none');
        });
        if (active) { highlight(false); } else { tabStop(null); }
    }

    // В таблицу можно войти клавишей Tab: одна ячейка (выбранная или первая) доступна для фокуса.
    function tabStop(cell) {
        body.querySelectorAll('td[data-key][tabindex="0"]').forEach(function (el) { el.setAttribute('tabindex', '-1'); });
        var target = cell || body.querySelector('td[data-key]');
        if (target) { target.setAttribute('tabindex', '0'); }
    }

    function renderRow(row) {
        var tr = body.querySelector('tr[data-row="' + row.id + '"]');
        if (!tr) { render(); return; }
        if (editing && editing.row === row.id) {
            // В строке открыт редактор другой ячейки (ответ на прежнюю правку пришёл позже):
            // обновляем остальные ячейки, редактор не трогаем.
            columns.forEach(function (col) {
                var td = tr.querySelector('td[data-key="' + col.key + '"]');
                if (!td || col.key === editing.key) { return; }
                td.className = cellClasses(row, col) + (active && active.row === row.id && active.key === col.key ? ' is-active' : '');
                td.innerHTML = cellHtml(row, col);
            });
            return;
        }
        var number = tr.querySelector('.mt__num').textContent;
        var focused = document.activeElement;
        var hadFocus = tr.contains(focused);
        var onMenu = hadFocus && focused.hasAttribute('data-row-menu');
        var holder = document.createElement('tbody');
        holder.innerHTML = rowHtml(row, number);
        tr.replaceWith(holder.firstChild);
        // Фокус был в заменённой строке — возвращаем его на то же место, иначе он ушёл бы в начало
        // страницы и стрелки, Enter и ввод перестали бы работать до щелчка мышью.
        if (onMenu) {
            focusMenuButton(row.id);
        } else if (active && active.row === row.id) {
            // Без прокрутки: ответ сервера не должен сдвигать страницу, которую пользователь листает.
            highlight(false);
            var cell = hadFocus ? cellElement(active.row, active.key) : null;
            if (cell) { cell.focus({ preventScroll: true }); }
        } else if (hadFocus && columns.length) {
            active = { row: row.id, key: columns[0].key };
            highlight(false);
            var first = cellElement(row.id, columns[0].key);
            if (first) { first.focus({ preventScroll: true }); }
        }
        // Ячейка, доступная клавише Tab, могла быть в этой строке.
        if (!body.querySelector('td[data-key][tabindex="0"]')) { tabStop(active ? cellElement(active.row, active.key) : null); }
    }

    function focusMenuButton(rowId) {
        var button = body.querySelector('tr[data-row="' + rowId + '"] [data-row-menu]');
        if (button) { button.focus({ preventScroll: true }); }
    }

    function renderSummary(summary) {
        if (!summary || !summaryBox) { return; }
        var states = [['Готово', 'ready'], ['Допустимо', 'acceptable'], ['Не соответствует', 'mismatch'], ['Отсутствует', 'absent']];
        var html = '<ul class="mt-states" aria-label="Состояние материалов">';
        states.forEach(function (s) {
            html += '<li class="mt-states__item"><span class="state-dot state-dot--' + s[1] + '" aria-hidden="true"></span>' + s[0] + ' <b>' + (summary.states[s[0]] || 0) + '</b></li>';
        });
        if (summary.states['Неизвестно']) {
            html += '<li class="mt-states__item"><span class="state-dot state-dot--unknown" aria-hidden="true"></span>Неизвестно <b>' + summary.states['Неизвестно'] + '</b></li>';
        }
        html += '<li class="mt-states__item mt-states__item--total">Всего <b>' + summary.total + '</b>, с файлами <b>' + summary.files + '</b>'
            + (summary.unrated > 0 ? ', без оценки <b>' + summary.unrated + '</b>' : '') + '</li></ul>';
        summaryBox.innerHTML = html;
    }

    // ---- Выбор ячейки и навигация ------------------------------------------------------------------

    function cellElement(rowId, key) {
        return body.querySelector('tr[data-row="' + rowId + '"] td[data-key="' + key + '"]');
    }

    function highlight(focus) {
        body.querySelectorAll('.is-active').forEach(function (el) { el.classList.remove('is-active'); });
        if (!active) { return; }
        var cell = cellElement(active.row, active.key);
        if (!cell) { tabStop(null); return; }
        cell.classList.add('is-active');
        tabStop(cell);
        if (focus !== false) {
            cell.focus({ preventScroll: true });
            cell.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    function select(rowId, key) {
        active = { row: rowId, key: key };
        highlight(true);
    }

    function move(dRow, dCol) {
        if (!active) { return; }
        var visible = Array.prototype.map.call(body.querySelectorAll('tr[data-row]'), function (tr) { return parseInt(tr.dataset.row, 10); });
        var r = visible.indexOf(active.row);
        var c = -1;
        for (var i = 0; i < columns.length; i++) { if (columns[i].key === active.key) { c = i; } }
        r = Math.max(0, Math.min(visible.length - 1, r + dRow));
        c = Math.max(0, Math.min(columns.length - 1, c + dCol));
        if (visible.length) { select(visible[r], columns[c].key); }
    }

    // ---- Правка ------------------------------------------------------------------------------------

    function startEdit(rowId, key, typed) {
        var col = column(key);
        var row = findRow(rowId);
        if (!col || !col.editable || !row) { return; }
        if (editing && editing.row === rowId && editing.key === key) { return; } // редактор уже открыт
        if (editing) { commit(0, 0); }
        var cell = cellElement(rowId, key);
        if (!cell) { return; }
        var original = rawValue(row, key);
        var input;
        if (col.type === 'kind' || col.type === 'list') {
            input = document.createElement('select');
            var options = col.type === 'kind'
                ? kinds.map(function (k) { return [String(k.id), k.name]; })
                : col.param.options.map(function (o) { return [o, o]; });
            input.innerHTML = '<option value="">—</option>' + options.map(function (o) {
                return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>';
            }).join('');
            if (original && !options.some(function (o) { return o[0] === original; })) {
                input.insertAdjacentHTML('beforeend', '<option value="' + esc(original) + '">' + esc(original) + '</option>');
            }
            input.value = original;
            input.addEventListener('change', function () { commit(0, 0); });
        } else if (col.type === 'text') {
            input = document.createElement('textarea');
            input.rows = Math.min(6, Math.max(2, (original.match(/\n/g) || []).length + 1));
            input.value = typed != null ? typed : original;
        } else {
            input = document.createElement('input');
            // Календарь — только для дат в формате ГГГГ-ММ-ДД: значение другого вида (например, «III кв. 2025»
            // из Excel) поле даты не покажет, и сохранение молча стёрло бы его — такое правим как текст.
            var isoDate = col.type === 'date' && (original === '' || /^\d{4}-\d{2}-\d{2}$/.test(original)) && typed == null;
            input.type = isoDate ? 'date' : (col.type === 'link' ? 'url' : 'text');
            if (col.type === 'number') { input.inputMode = 'decimal'; }
            if (col.type === 'date' && !isoDate) { input.placeholder = 'ДД.ММ.ГГГГ'; }
            input.value = typed != null ? typed : original;
        }
        input.className = 'mt__editor';
        input.setAttribute('aria-label', 'Значение');
        cell.classList.add('is-editing');
        cell.innerHTML = '';
        cell.appendChild(input);
        editing = { row: rowId, key: key, input: input, original: original };
        input.focus();
        if (input.tagName !== 'SELECT' && typeof input.setSelectionRange === 'function' && input.type !== 'date' && input.type !== 'url') {
            var end = input.value.length;
            try { input.setSelectionRange(end, end); } catch (e) { /* поле без выделения */ }
        }
        if (input.tagName === 'SELECT' && typeof input.showPicker === 'function') {
            try { input.showPicker(); } catch (e) { /* откроется по щелчку */ }
        }
        input.addEventListener('blur', function () {
            // Щелчок мимо: сохраняем (как Excel), кроме списка — там сохранение по выбору.
            setTimeout(function () { if (editing && editing.input === input) { commit(0, 0, true); } }, 0);
        });
    }

    function cancelEdit() {
        if (!editing) { return; }
        var row = findRow(editing.row);
        editing = null;
        if (row) { renderRow(row); }
        highlight(true);
    }

    function commit(dRow, dCol, fromBlur) {
        if (!editing) { return; }
        var current = editing;
        editing = null;
        var value = current.input.value;
        var row = findRow(current.row);
        if (!row) { return; }
        if (value === current.original) {
            renderRow(row);
            if (!fromBlur) { select(current.row, current.key); move(dRow, dCol); }
            return;
        }
        // Значение сразу записывается в строку: повторное открытие ячейки до ответа сервера должно
        // показать новый текст, а не прежний (иначе продолжение правки затирало бы сохранённое).
        var previous = fieldSnapshot(row, current.key);
        setLocalValue(row, current.key, value);
        var cell = cellElement(current.row, current.key);
        if (cell) {
            cell.classList.remove('is-editing');
            cell.classList.add('is-saving');
            cell.textContent = current.key === 'kind' ? displayValue(row, 'kind') : value;
        }
        if (!fromBlur) { select(current.row, current.key); move(dRow, dCol); }
        request('/rows/' + current.row, { field: current.key, value: value }).then(function (res) {
            if (res.row) {
                replaceRow(res.row);
            } else if (!res.ok) {
                // Ответа со строкой нет (нет связи) — возвращаем прежнее значение только этого поля и только
                // если его не успели изменить ещё раз: остальные правки строки могли уже сохраниться.
                var fresh = findRow(current.row);
                if (fresh && rawValue(fresh, current.key) === value) {
                    restoreField(fresh, current.key, previous);
                    renderRow(fresh);
                }
            }
            if (res.ok) {
                renderSummary(res.summary);
            } else {
                toast(res.error || 'Не удалось сохранить.', true);
            }
        });
    }

    function fieldSnapshot(row, key) {
        if (key === 'title') { return row.title; }
        if (key === 'kind') { return row.kind ? { id: row.kind.id, name: row.kind.name } : null; }
        var field = row.values && row.values[key];
        return field ? JSON.parse(JSON.stringify(field)) : null;
    }

    function restoreField(row, key, snapshot) {
        if (key === 'title') { row.title = snapshot; return; }
        if (key === 'kind') { row.kind = snapshot; return; }
        row.values = row.values || {};
        if (snapshot) { row.values[key] = snapshot; } else { delete row.values[key]; }
    }

    function setLocalValue(row, key, value) {
        if (key === 'title') { row.title = value; return; }
        if (key === 'kind') {
            var found = kinds.filter(function (k) { return String(k.id) === String(value); })[0];
            row.kind = found ? { id: found.id, name: found.name } : null;
            return;
        }
        row.values = row.values || {};
        row.values[key] = { value: value, display: value };
    }

    function replaceRow(fresh) {
        var index = rowIndex(fresh.id);
        if (index === -1) { return; }
        rows[index] = fresh;
        renderRow(fresh);
    }

    function clearCell() {
        if (!active) { return; }
        var col = column(active.key);
        var row = findRow(active.row);
        if (!col || !col.editable || !row || rawValue(row, active.key) === '') { return; }
        editing = { row: active.row, key: active.key, input: { value: '' }, original: rawValue(row, active.key) };
        commit(0, 0);
    }

    // ---- Строки ------------------------------------------------------------------------------------

    function addRow(after) {
        request('/rows', after ? { after: after } : {}).then(function (res) {
            if (!res.ok) { toast(res.error || 'Не удалось добавить строку.', true); return; }
            var index = after ? rowIndex(after) + 1 : rows.length;
            rows.splice(index, 0, res.row);
            filters.q = ''; filters.kind = ''; filters.state = '';
            document.querySelectorAll('[data-mt-filter]').forEach(function (el) { el.value = ''; });
            sort.key = null;
            render();
            renderSummary(res.summary);
            select(res.row.id, 'title');
            startEdit(res.row.id, 'title');
        });
    }

    function deleteRow(id) {
        var row = findRow(id);
        if (!row) { return; }
        var text = 'Удалить строку «' + (row.title || 'без названия') + '»?' + (row.document ? '\nЗагруженный документ останется в разделе.' : '');
        if (!window.confirm(text)) { return; }
        request('/rows/' + id + '/delete', {}).then(function (res) {
            if (!res.ok) { toast(res.error || 'Не удалось удалить строку.', true); return; }
            var key = active ? active.key : (columns[0] && columns[0].key);
            // Соседняя строка — та, что видна рядом на экране (с учётом сортировки и фильтров).
            var tr = body.querySelector('tr[data-row="' + id + '"]');
            var neighbour = tr ? (tr.nextElementSibling || tr.previousElementSibling) : null;
            var nextId = neighbour && neighbour.dataset.row ? parseInt(neighbour.dataset.row, 10) : null;
            rows.splice(rowIndex(id), 1);
            if (active && active.row === id) { active = null; }
            render();
            renderSummary(res.summary);
            toast('Строка удалена.');
            // Фокус был на удалённой строке (меню «⋯») — переводим его на соседнюю строку.
            if ((document.activeElement === document.body || !document.activeElement) && nextId !== null && key && cellElement(nextId, key)) {
                select(nextId, key);
            }
        });
    }

    function moveRow(id, direction) {
        var index = rowIndex(id);
        var target = index + direction;
        if (index === -1 || target < 0 || target >= rows.length) { return; }
        request('/rows/' + id + '/move', { direction: direction }).then(function (res) {
            if (!res.ok) { toast(res.error || 'Не удалось переместить строку.', true); return; }
            var tmp = rows[index]; rows[index] = rows[target]; rows[target] = tmp;
            sort.key = null;
            var focusLost = function () { return document.activeElement === document.body || !document.activeElement; };
            var hadMenuFocus = focusLost() || (document.activeElement.hasAttribute && document.activeElement.hasAttribute('data-row-menu'));
            render();
            // Таблица перерисована целиком: фокус возвращается на кнопку «⋯» перемещённой строки.
            if (hadMenuFocus && focusLost()) { focusMenuButton(id); }
        });
    }

    function detach(id) {
        if (!window.confirm('Отвязать файл от строки? Документ останется в разделе, строка — без файла.')) { return; }
        request('/rows/' + id + '/detach', {}).then(function (res) {
            if (!res.ok) { toast(res.error || 'Не удалось отвязать файл.', true); return; }
            replaceRow(res.row);
            renderSummary(res.summary);
        });
    }

    // ---- Загрузка файлов ---------------------------------------------------------------------------

    function upload(rowId, file) {
        if (!file) { return; }
        if (config.maxBytes && file.size > config.maxBytes) {
            toast('Файл больше допустимого размера (' + Math.round(config.maxBytes / 1048576) + ' МБ).', true);
            return;
        }
        var cell = cellElement(rowId, 'file');
        if (cell) { cell.innerHTML = '<span class="mt-progress"><span class="mt-progress__bar" style="width:0%"></span></span><span class="mt-muted">загрузка…</span>'; }
        var form = new FormData();
        form.append('file', file);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.base + '/rows/' + rowId + '/upload');
        xhr.setRequestHeader('X-CSRF-Token', config.token);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', function (e) {
            if (!e.lengthComputable || !cell) { return; }
            var bar = cell.querySelector('.mt-progress__bar');
            if (bar) { bar.style.width = Math.round(100 * e.loaded / e.total) + '%'; }
        });
        xhr.addEventListener('load', function () {
            var res;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = { ok: false, error: 'Ошибка сервера (' + xhr.status + ').' }; }
            if (res.ok) {
                replaceRow(res.row);
                renderSummary(res.summary);
                toast(res.message || 'Файл загружен.');
            } else {
                toast(res.error || 'Не удалось загрузить файл.', true);
                var row = findRow(rowId);
                if (row) { renderRow(row); }
            }
        });
        xhr.addEventListener('error', function () {
            toast('Нет связи с порталом — файл не загружен.', true);
            var row = findRow(rowId);
            if (row) { renderRow(row); }
        });
        xhr.send(form);
    }

    fileInput.addEventListener('change', function () {
        if (uploadRow && fileInput.files.length) { upload(uploadRow, fileInput.files[0]); }
        fileInput.value = '';
    });

    body.addEventListener('dragover', function (e) {
        var tr = e.target.closest('tr[data-row]');
        if (!tr || !e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') === -1) { return; }
        e.preventDefault();
        body.querySelectorAll('.is-drop').forEach(function (el) { if (el !== tr) { el.classList.remove('is-drop'); } });
        tr.classList.add('is-drop');
    });
    body.addEventListener('dragleave', function (e) {
        var tr = e.target.closest('tr[data-row]');
        if (tr && !tr.contains(e.relatedTarget)) { tr.classList.remove('is-drop'); }
    });
    body.addEventListener('drop', function (e) {
        var tr = e.target.closest('tr[data-row]');
        if (!tr) { return; }
        e.preventDefault();
        tr.classList.remove('is-drop');
        var dropRow = findRow(parseInt(tr.dataset.row, 10));
        if (dropRow && !canUpload(dropRow)) { toast('Документ этой строки недоступен для правки — отвяжите его, чтобы загрузить новый файл.', true); return; }
        if (e.dataTransfer.files.length) { upload(parseInt(tr.dataset.row, 10), e.dataTransfer.files[0]); }
    });

    // ---- История изменений строки ----------------------------------------------------------------

    var historyDialog = document.querySelector('[data-mt-history]');

    function historyValues(change) {
        var hasOld = change.old !== null && change.old !== '';
        var hasNew = change.new !== null && change.new !== '';
        if (!hasOld && !hasNew) { return ''; }
        return '<div class="mt-history-list__values">'
            + (hasOld ? '<del>' + esc(change.old) + '</del>' + (hasNew || change.action === 'update' || change.action === 'import' ? ' → ' : '') : '')
            + (hasNew ? '<ins>' + esc(change.new) + '</ins>' : (change.action === 'update' || change.action === 'import' ? '<span class="mt-muted">пусто</span>' : ''))
            + '</div>';
    }

    function openHistory(id) {
        if (!historyDialog) { return; }
        var row = findRow(id);
        var bodyEl = historyDialog.querySelector('[data-mt-history-body]');
        historyDialog.querySelector('[data-mt-history-title]').textContent = 'История: ' + ((row && row.title) || 'без названия');
        bodyEl.innerHTML = '<p class="mt-muted">Загрузка…</p>';
        historyDialog.returnFocusTo = id;
        historyDialog.showModal();
        fetch(config.base + '/rows/' + id + '/history', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'Ошибка сервера (' + response.status + ').' }; });
            })
            .then(function (res) {
                if (!res.ok) { bodyEl.innerHTML = '<p class="form-error">' + esc(res.error || 'Не удалось загрузить историю.') + '</p>'; return; }
                if (!res.changes.length) { bodyEl.innerHTML = '<p class="mt-muted">Изменений пока не было (журнал ведётся с версии 1.7.0).</p>'; return; }
                bodyEl.innerHTML = '<ol class="mt-history-list">' + res.changes.map(function (c) {
                    return '<li><div class="mt-history-list__head"><time datetime="' + esc(c.atIso) + '">' + esc(c.at) + '</time> · ' + esc(c.user) + '</div>'
                        + '<div>' + esc(c.actionLabel) + (c.fieldLabel ? ': <b>' + esc(c.fieldLabel) + '</b>' : '') + '</div>'
                        + historyValues(c) + '</li>';
                }).join('') + '</ol>';
            })
            .catch(function () { bodyEl.innerHTML = '<p class="form-error">Нет связи с порталом — история не загружена.</p>'; });
    }

    if (historyDialog) {
        historyDialog.querySelector('[data-mt-history-close]').addEventListener('click', function () { historyDialog.close(); });
        historyDialog.addEventListener('close', function () {
            if (historyDialog.returnFocusTo) { focusMenuButton(historyDialog.returnFocusTo); historyDialog.returnFocusTo = null; }
        });
    }

    // ---- Привязка документа раздела вручную ----------------------------------------------------------
    // Документы раздела и подразделов, которых нет в таблице (и отвязанные вручную), — привязать к строке,
    // когда автоматическое сопоставление ошиблось.

    var linkDialog = document.querySelector('[data-mt-link]');
    var linkState = { row: null, documents: [], selected: null };

    function renderLinkList() {
        var list = linkDialog.querySelector('[data-mt-link-list]');
        var q = linkDialog.querySelector('[data-mt-link-search]').value.trim().toLowerCase();
        var shown = linkState.documents.filter(function (d) {
            return !q || (d.title + ' ' + d.section + ' ' + (d.kind || '')).toLowerCase().indexOf(q) !== -1;
        });
        if (!linkState.documents.length) {
            list.innerHTML = '<p class="mt-muted">Все документы раздела уже в таблице.</p>';
        } else if (!shown.length) {
            list.innerHTML = '<p class="mt-muted">Ничего не найдено.</p>';
        } else {
            list.innerHTML = shown.map(function (d) {
                var meta = [d.section, d.kind, d.status === 'draft' ? 'черновик' : '', d.excluded ? 'отвязан вручную' : ''].filter(Boolean).join(' · ');
                return '<label class="mt-link-item"><input type="radio" name="mt-link-doc" value="' + d.id + '"' + (linkState.selected === d.id ? ' checked' : '') + '>'
                    + '<span><span class="mt-link-item__title">' + esc(d.title) + '</span><span class="mt-muted mt-link-item__meta">' + esc(meta) + '</span></span></label>';
            }).join('');
        }
        linkDialog.querySelector('[data-mt-link-submit]').disabled = linkState.selected === null;
    }

    function openLink(id) {
        if (!linkDialog) { return; }
        var row = findRow(id);
        linkState = { row: id, documents: [], selected: null };
        linkDialog.querySelector('[data-mt-link-title]').textContent = (row && row.document ? 'Заменить документ: ' : 'Привязать документ: ') + ((row && row.title) || 'без названия');
        linkDialog.querySelector('[data-mt-link-search]').value = '';
        linkDialog.querySelector('[data-mt-link-error]').hidden = true;
        linkDialog.querySelector('[data-mt-link-list]').innerHTML = '<p class="mt-muted">Загрузка…</p>';
        linkDialog.querySelector('[data-mt-link-submit]').disabled = true;
        linkDialog.returnFocusTo = id;
        linkDialog.showModal();
        fetch(config.base + '/documents', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json().catch(function () { return { ok: false, error: 'Ошибка сервера (' + response.status + ').' }; }); })
            .then(function (res) {
                if (!res.ok) { linkDialog.querySelector('[data-mt-link-list]').innerHTML = '<p class="form-error">' + esc(res.error || 'Не удалось загрузить документы.') + '</p>'; return; }
                linkState.documents = res.documents;
                renderLinkList();
                linkDialog.querySelector('[data-mt-link-search]').focus();
            })
            .catch(function () { linkDialog.querySelector('[data-mt-link-list]').innerHTML = '<p class="form-error">Нет связи с порталом.</p>'; });
    }

    if (linkDialog) {
        linkDialog.querySelector('[data-mt-link-search]').addEventListener('input', renderLinkList);
        linkDialog.querySelector('[data-mt-link-list]').addEventListener('change', function (e) {
            if (e.target.name === 'mt-link-doc') { linkState.selected = parseInt(e.target.value, 10); renderLinkList(); }
        });
        linkDialog.querySelector('[data-mt-link-list]').addEventListener('dblclick', function (e) {
            if (e.target.closest('.mt-link-item') && linkState.selected !== null) { linkDialog.querySelector('[data-mt-link-submit]').click(); }
        });
        linkDialog.querySelector('[data-mt-link-cancel]').addEventListener('click', function () { linkDialog.close(); });
        linkDialog.querySelector('[data-mt-link-submit]').addEventListener('click', function () {
            if (linkState.selected === null) { return; }
            var button = this;
            button.disabled = true;
            request('/rows/' + linkState.row + '/link', { document: linkState.selected }).then(function (res) {
                button.disabled = false;
                if (!res.ok) {
                    var error = linkDialog.querySelector('[data-mt-link-error]');
                    error.textContent = res.error || 'Не удалось привязать документ.';
                    error.hidden = false;
                    return;
                }
                linkDialog.close();
                rows = res.rows;
                render();
                renderSummary(res.summary);
                toast(res.message);
            });
        });
        linkDialog.addEventListener('close', function () {
            if (linkDialog.returnFocusTo) { focusMenuButton(linkDialog.returnFocusTo); linkDialog.returnFocusTo = null; }
        });
    }

    // ---- Меню строки -------------------------------------------------------------------------------

    function closeMenu() {
        if (menu) { menu.remove(); menu = null; }
    }

    function openMenu(button, id) {
        closeMenu();
        var row = findRow(id);
        if (!row) { return; }
        menu = document.createElement('div');
        menu.className = 'mt-menu';
        menu.setAttribute('role', 'menu');
        var items = [
            ['insert', 'Вставить строку ниже'],
            ['up', 'Переместить вверх'],
            ['down', 'Переместить вниз']
        ];
        if (canUpload(row)) { items.push(['upload', row.document ? 'Загрузить новую версию файла' : 'Загрузить файл']); }
        // Помощник маркетолога (config.canStructure = false) не отвязывает файлы и не удаляет строки.
        var structure = config.canStructure !== false;
        if (row.document) {
            if (!row.document.hidden) { items.push(['open', 'Открыть документ']); }
            if (structure) { items.push(['link', 'Заменить документом раздела…']); }
            if (structure) { items.push(['detach', 'Отвязать файл от строки']); }
        } else if (linkDialog) {
            items.push(['link', 'Привязать документ раздела…']);
        }
        items.push(['history', 'История изменений']);
        if (structure) { items.push(['delete', 'Удалить строку']); }
        menu.innerHTML = items.map(function (item) {
            return '<button type="button" role="menuitem" data-menu="' + item[0] + '"' + (item[0] === 'delete' ? ' class="is-danger"' : '') + '>' + item[1] + '</button>';
        }).join('');
        document.body.appendChild(menu);
        var rect = button.getBoundingClientRect();
        menu.style.top = (window.scrollY + rect.bottom + 4) + 'px';
        menu.style.left = Math.max(8, window.scrollX + rect.right - menu.offsetWidth) + 'px';
        menu.addEventListener('click', function (e) {
            var action = e.target.dataset.menu;
            if (!action) { return; }
            closeMenu();
            // Фокус возвращается на кнопку «⋯» строки (а не в начало страницы); действия, которые
            // открывают редактор или диалог, переводят его дальше сами.
            focusMenuButton(id);
            if (action === 'insert') { addRow(id); }
            if (action === 'up') { moveRow(id, -1); }
            if (action === 'down') { moveRow(id, 1); }
            if (action === 'upload') { uploadRow = id; fileInput.click(); }
            if (action === 'open' && row.document && row.document.url) { location.href = row.document.url; }
            if (action === 'detach') { detach(id); }
            if (action === 'link') { openLink(id); }
            if (action === 'history') { openHistory(id); }
            if (action === 'delete') { deleteRow(id); }
        });
        menu.addEventListener('keydown', function (e) {
            var buttons = Array.prototype.slice.call(menu.querySelectorAll('button'));
            var index = buttons.indexOf(document.activeElement);
            if (e.key === 'Escape') { e.preventDefault(); closeMenu(); focusMenuButton(id); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); buttons[(index + 1) % buttons.length].focus(); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); buttons[(index - 1 + buttons.length) % buttons.length].focus(); return; }
            // Tab закрывает меню и переходит к следующему за кнопкой «⋯» элементу (фокус ставится на кнопку,
            // а сам переход выполняет браузер).
            if (e.key === 'Tab') { closeMenu(); focusMenuButton(id); }
        });
        var first = menu.querySelector('button');
        if (first) { first.focus(); }
    }

    document.addEventListener('click', function (e) {
        if (menu && !menu.contains(e.target) && !e.target.closest('[data-row-menu]')) { closeMenu(); }
    });

    // ---- События таблицы ---------------------------------------------------------------------------

    body.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-row]');
        if (!tr) { return; }
        var id = parseInt(tr.dataset.row, 10);
        if (e.target.closest('[data-row-menu]')) { openMenu(e.target.closest('[data-row-menu]'), id); return; }
        if (e.target.closest('[data-upload]')) { uploadRow = id; fileInput.click(); return; }
        if (e.target.closest('a')) { return; }
        var cell = e.target.closest('td[data-key]');
        if (!cell || cell.classList.contains('is-editing')) { return; }
        select(id, cell.dataset.key);
    });

    body.addEventListener('focusin', function (e) {
        var cell = e.target.closest && e.target.closest('td[data-key]');
        var tr = cell ? cell.closest('tr[data-row]') : null;
        if (!cell || cell !== e.target || !tr || cell.classList.contains('is-editing')) { return; }
        var id = parseInt(tr.dataset.row, 10);
        if (!active || active.row !== id || active.key !== cell.dataset.key) {
            active = { row: id, key: cell.dataset.key };
            highlight(false);
        }
    });

    body.addEventListener('dblclick', function (e) {
        var cell = e.target.closest('td[data-key]');
        var tr = e.target.closest('tr[data-row]');
        // Двойной щелчок внутри открытого редактора — это выделение слова, а не новая правка.
        if (!cell || !tr || e.target.closest('a, button, .mt__editor') || cell.classList.contains('is-editing')) { return; }
        startEdit(parseInt(tr.dataset.row, 10), cell.dataset.key);
    });

    table.addEventListener('keydown', function (e) {
        if (editing) {
            if (e.target !== editing.input) { return; }
            var isTextarea = editing.input.tagName === 'TEXTAREA';
            if (e.key === 'Escape') { e.preventDefault(); cancelEdit(); return; }
            if (e.key === 'Enter' && !(isTextarea && (e.altKey || e.shiftKey))) { e.preventDefault(); commit(1, 0); return; }
            if (e.key === 'Tab') { e.preventDefault(); commit(0, e.shiftKey ? -1 : 1); return; }
            return;
        }
        // Клавиши таблицы работают, только когда фокус на самой ячейке: на кнопках (сортировка, меню строки,
        // загрузка файла) и ссылках Enter, пробел и Delete должны делать своё, а Tab — уводить фокус дальше.
        var cellTarget = e.target.closest && e.target.closest('td[data-key]');
        if (!active || !cellTarget || cellTarget !== e.target || e.altKey || e.ctrlKey || e.metaKey) { return; }
        var tr = cellTarget.closest('tr[data-row]');
        if (tr) { active = { row: parseInt(tr.dataset.row, 10), key: cellTarget.dataset.key }; }
        switch (e.key) {
            case 'ArrowUp': e.preventDefault(); move(-1, 0); return;
            case 'ArrowDown': e.preventDefault(); move(1, 0); return;
            case 'ArrowLeft': e.preventDefault(); move(0, -1); return;
            case 'ArrowRight': e.preventDefault(); move(0, 1); return;
            case 'Enter': case 'F2': e.preventDefault(); startEdit(active.row, active.key); return;
            case 'Delete': case 'Backspace': e.preventDefault(); clearCell(); return;
        }
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            var col = column(active.key);
            if (col && col.editable) {
                e.preventDefault();
                startEdit(active.row, active.key, col.type === 'kind' || col.type === 'list' || col.type === 'date' ? null : e.key);
            }
        }
    });

    // Сортировка по щелчку на заголовке: по возрастанию → по убыванию → исходный порядок.
    table.querySelectorAll('[data-sort]').forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.dataset.sort;
            if (sort.key !== key) { sort = { key: key, dir: 1 }; }
            else if (sort.dir === 1) { sort.dir = -1; }
            else { sort = { key: null, dir: 1 }; }
            render();
        });
    });

    document.querySelectorAll('[data-mt-filter]').forEach(function (input) {
        input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', function () {
            filters[input.dataset.mtFilter] = input.tagName === 'SELECT' ? input.value : input.value.trim().toLowerCase();
            render();
        });
    });

    document.querySelector('[data-mt-action="add-row"]').addEventListener('click', function () { addRow(null); });

    // ---- Столбцы -----------------------------------------------------------------------------------

    var columnForm = dialog ? dialog.querySelector('[data-mt-column-form]') : null;
    var editingColumn = null;

    function openColumnDialog(param) {
        if (!dialog) { return; }
        editingColumn = param;
        var title = dialog.querySelector('[data-mt-dialog-title]');
        var kindRow = dialog.querySelector('[data-mt-kind-row]');
        var optionsRow = dialog.querySelector('[data-mt-options-row]');
        var optionsField = columnForm.elements.options;
        var hint = dialog.querySelector('[data-mt-options-hint]');
        var roleHint = dialog.querySelector('[data-mt-role-hint]');
        var error = dialog.querySelector('[data-mt-dialog-error]');
        error.hidden = true;
        title.textContent = param ? 'Столбец «' + param.name + '»' : 'Новый столбец';
        columnForm.elements.name.value = param ? param.name : '';
        columnForm.elements.kind.value = param ? param.kind : 'text';
        columnForm.elements.width.value = param && param.width ? param.width : '';
        optionsField.value = param ? param.options.join('\n') : '';
        optionsField.disabled = !!(param && param.locked);
        hint.textContent = param && param.locked ? 'Значения фиксированы: по ним считается «Состояние».' : '';
        roleHint.textContent = param && param.roleHint ? 'Особый столбец: ' + param.roleHint + '.' : '';
        kindRow.hidden = !!param;
        var syncOptions = function () { optionsRow.hidden = columnForm.elements.kind.value !== 'list'; };
        columnForm.elements.kind.onchange = syncOptions;
        syncOptions();
        dialog.querySelectorAll('[data-mt-column-move], [data-mt-column-delete]').forEach(function (el) { el.hidden = !param; });
        dialog.showModal();
        columnForm.elements.name.focus();
    }

    if (columnForm) {
        columnForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = { name: columnForm.elements.name.value, width: columnForm.elements.width.value };
            if (!editingColumn) { data.kind = columnForm.elements.kind.value; }
            if (!columnForm.elements.options.disabled && columnForm.elements.kind.value === 'list') { data.options = columnForm.elements.options.value; }
            request(editingColumn ? '/parameters/' + editingColumn.id : '/parameters', data).then(function (res) {
                if (!res.ok) {
                    var error = dialog.querySelector('[data-mt-dialog-error]');
                    error.textContent = res.error || 'Не удалось сохранить столбец.';
                    error.hidden = false;
                    return;
                }
                location.reload();
            });
        });
        dialog.querySelector('[data-mt-dialog-close]').addEventListener('click', function () { dialog.close(); });
        dialog.querySelectorAll('[data-mt-column-move]').forEach(function (button) {
            button.addEventListener('click', function () {
                request('/parameters/' + editingColumn.id + '/move', { direction: button.dataset.mtColumnMove }).then(function () { location.reload(); });
            });
        });
        dialog.querySelector('[data-mt-column-delete]').addEventListener('click', function () {
            if (!window.confirm('Удалить столбец «' + editingColumn.name + '» вместе со значениями во всех строках?')) { return; }
            request('/parameters/' + editingColumn.id + '/delete', {}).then(function (res) {
                if (!res.ok) { toast(res.error || 'Не удалось удалить столбец.', true); return; }
                location.reload();
            });
        });
    }

    document.querySelector('[data-mt-action="add-column"]').addEventListener('click', function () { openColumnDialog(null); });
    table.querySelectorAll('[data-mt-column]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = parseInt(button.dataset.mtColumn, 10);
            openColumnDialog(params.filter(function (p) { return p.id === id; })[0] || null);
        });
    });

    render();
})();
