// Редактор слайдов — ядро: данные презентации, история правок (отмена и повтор), автосохранение с
// проверкой одновременных правок, операции над слайдами и элементами, загрузка изображений.
// Интерфейс — deck-editor-canvas.js (холст) и deck-editor-ui.js (панели и диалоги).
(function () {
    'use strict';

    var root = document.querySelector('[data-deck-editor]');
    var R = window.DeckRender;
    if (!root || !R) { return; }
    var cfg = JSON.parse(document.getElementById('deck-editor-config').textContent);
    var deck = JSON.parse(document.getElementById('deck-data').textContent);

    R.configure({ assetBase: cfg.urls.assetBase, brand: cfg.brand, qr: cfg.urls.qr, chartColors: cfg.chartColors });

    var listeners = {};
    var DE = window.DE = {
        root: root,
        cfg: cfg,
        R: R,
        get deck() { return deck; },
        state: { slide: 0, sel: [], editing: null, zoom: 'fit', dirty: false, saving: false, revision: cfg.revision, readonly: false, slideSel: [] },
        on: function (name, fn) { (listeners[name] = listeners[name] || []).push(fn); },
        emit: function (name, data) { (listeners[name] || []).forEach(function (fn) { try { fn(data); } catch (e) { if (window.console) { console.error(e); } } }); },
    };

    // ---- Помощники --------------------------------------------------------------------------------

    var U = DE.util = {
        esc: R.esc,
        uid: function (prefix) {
            var a = new Uint8Array(5);
            (window.crypto || window.msCrypto).getRandomValues(a);
            return (prefix || 'e') + Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        },
        clone: function (v) { return JSON.parse(JSON.stringify(v)); },
        clamp: function (v, min, max) { return Math.max(min, Math.min(max, v)); },
        round: function (v, d) { var k = Math.pow(10, d === undefined ? 1 : d); return Math.round(v * k) / k; },
        num: function (v, d) { v = parseFloat(String(v).replace(',', '.')); return isFinite(v) ? v : d; },
        plural: function (n, forms) {
            var m10 = n % 10, m100 = n % 100;
            return n + ' ' + (m10 === 1 && m100 !== 11 ? forms[0] : (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20) ? forms[1] : forms[2]));
        },
        responsibleHtml: function (name) {
            return name ? '<p><span style="color:#808080">Ответственный: </span><span style="color:#262626">' + R.esc(name) + '</span></p>' : '';
        },
        h: function (html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; },
    };

    DE.slide = function (i) { return deck.slides[i === undefined ? DE.state.slide : i] || null; };
    DE.el = function (id, slide) {
        slide = slide || DE.slide();
        if (!slide) { return null; }
        for (var i = 0; i < slide.elements.length; i++) { if (slide.elements[i].id === id) { return slide.elements[i]; } }
        return null;
    };
    DE.selected = function () {
        var s = DE.slide();
        if (!s) { return []; }
        return s.elements.filter(function (e) { return DE.state.sel.indexOf(e.id) >= 0; });
    };

    // ---- История правок --------------------------------------------------------------------------

    var past = [], future = [], lastKey = null, lastAt = 0;
    var MAX_HISTORY = 120;

    function snapshot() { return JSON.stringify({ deck: deck, slide: DE.state.slide, sel: DE.state.sel }); }
    function restore(json) {
        var s = JSON.parse(json);
        deck = s.deck;
        DE.state.slide = Math.min(s.slide, deck.slides.length - 1);
        DE.state.sel = s.sel || [];
    }

    // Правка: fn меняет deck. key — объединять серию однотипных правок (набор текста, перетаскивание
    // ползунка) в один шаг отмены. what — что перерисовать: 'stage' (слайд), 'slides' (весь список), 'none'.
    DE.change = function (fn, opts) {
        opts = opts || {};
        if (DE.state.readonly) { DE.toast('Презентацию сейчас нельзя менять.', 'error'); return; }
        var now = Date.now();
        if (!opts.key || opts.key !== lastKey || now - lastAt > 1200) {
            past.push(snapshot());
            if (past.length > MAX_HISTORY) { past.shift(); }
            future = [];
        }
        lastKey = opts.key || null; lastAt = now;
        fn(deck);
        DE.state.sel = DE.state.sel.filter(function (id) { return !!DE.el(id); });
        markDirty();
        DE.emit('change', { what: opts.what || 'stage', slide: DE.state.slide });
    };

    // Перед правкой, которая идёт сериями событий (перетаскивание): снимок один раз в начале.
    DE.beginGesture = function () { past.push(snapshot()); if (past.length > MAX_HISTORY) { past.shift(); } future = []; lastKey = '__gesture'; lastAt = Date.now(); };
    DE.endGesture = function () { lastKey = null; markDirty(); DE.emit('change', { what: 'stage', slide: DE.state.slide }); };

    DE.undo = function () {
        if (!past.length) { DE.toast('Отменять нечего.'); return; }
        future.push(snapshot());
        restore(past.pop());
        lastKey = null;
        markDirty();
        DE.emit('change', { what: 'all' });
    };
    DE.redo = function () {
        if (!future.length) { DE.toast('Повторять нечего.'); return; }
        past.push(snapshot());
        restore(future.pop());
        lastKey = null;
        markDirty();
        DE.emit('change', { what: 'all' });
    };
    DE.canUndo = function () { return past.length > 0; };
    DE.canRedo = function () { return future.length > 0; };

    // Заменить всю колоду (импорт, восстановление версии, загрузка чужих изменений).
    DE.replaceDeck = function (newDeck, revision, keepHistory) {
        if (keepHistory) { past.push(snapshot()); future = []; }
        deck = newDeck;
        if (revision !== undefined) { DE.state.revision = revision; }
        DE.state.slide = Math.min(DE.state.slide, Math.max(0, deck.slides.length - 1));
        DE.state.sel = [];
        DE.emit('change', { what: 'all' });
    };

    // ---- Выбор -----------------------------------------------------------------------------------

    DE.select = function (ids, opts) {
        ids = (ids || []).filter(function (id) { return !!DE.el(id); });
        // Элемент группы выбирается вместе с группой (Alt — один элемент).
        if (!(opts && opts.single)) {
            var groups = {};
            ids.forEach(function (id) { var e = DE.el(id); if (e && e.group) { groups[e.group] = true; } });
            if (Object.keys(groups).length) {
                DE.slide().elements.forEach(function (e) { if (e.group && groups[e.group] && ids.indexOf(e.id) < 0) { ids.push(e.id); } });
            }
        }
        DE.state.sel = ids;
        DE.emit('select', ids);
    };

    DE.goto = function (index, opts) {
        if (index < 0 || index >= deck.slides.length) { return; }
        if (DE.state.editing && DE.stopEditing) { DE.stopEditing(); }
        DE.state.slide = index;
        DE.state.sel = [];
        if (!(opts && opts.keepSlideSel)) { DE.state.slideSel = [index]; }
        DE.emit('slide', index);
    };

    // ---- Сохранение ------------------------------------------------------------------------------

    var saveTimer = null, backupTimer = null;
    var BACKUP_KEY = 'deck-backup-' + cfg.id;

    function markDirty() {
        DE.state.dirty = true;
        DE.emit('save-state', 'dirty');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () { DE.save(); }, 1500);
        clearTimeout(backupTimer);
        backupTimer = setTimeout(backup, 400);
    }

    // Копия несохранённой работы в этом браузере — на случай закрытой вкладки или пропавшей сети.
    function backup() {
        try {
            var json = JSON.stringify({ revision: DE.state.revision, at: Date.now(), deck: deck });
            if (json.length < 4500000) { localStorage.setItem(BACKUP_KEY, json); }
        } catch (e) { /* хранилище недоступно или переполнено */ }
    }
    function clearBackup() { try { localStorage.removeItem(BACKUP_KEY); } catch (e) { /* нет хранилища */ } }
    DE.readBackup = function () {
        try {
            var b = JSON.parse(localStorage.getItem(BACKUP_KEY) || 'null');
            return b && b.deck && b.deck.slides ? b : null;
        } catch (e) { return null; }
    };
    DE.clearBackup = clearBackup;

    DE.api = function (url, opts) {
        opts = opts || {};
        var headers = { 'X-CSRF-Token': cfg.token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' };
        var body = opts.body;
        if (opts.json !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(opts.json); }
        return fetch(url, { method: opts.method || (body ? 'POST' : 'GET'), headers: headers, body: body, credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (t) {
                    var data = null;
                    try { data = t ? JSON.parse(t) : {}; } catch (e) { data = { error: r.status === 403 ? 'Нет доступа — возможно, сессия закончилась. Обновите страницу.' : 'Сервер ответил ошибкой (' + r.status + ').' }; }
                    if (!r.ok) { var err = new Error(data.error || ('Ошибка ' + r.status)); err.status = r.status; err.data = data; throw err; }
                    return data;
                });
            });
    };

    DE.save = function (force) {
        clearTimeout(saveTimer);
        if (DE.state.saving) { DE.state.saveAgain = true; return Promise.resolve(); }
        if (!DE.state.dirty && !force) { return Promise.resolve(); }
        if (DE.state.editing && DE.flushEditing) { DE.flushEditing(); }
        DE.state.saving = true;
        DE.state.dirty = false;
        DE.emit('save-state', 'saving');
        return DE.api(cfg.urls.save, { json: { deck: deck, revision: DE.state.revision, force: force === true } })
            .then(function (res) {
                DE.state.revision = res.revision;
                DE.state.saving = false;
                DE.state.savedAt = new Date();
                clearBackup();
                DE.emit('saved', res);
                DE.emit('save-state', DE.state.dirty ? 'dirty' : 'saved');
                if (DE.state.saveAgain || DE.state.dirty) { DE.state.saveAgain = false; DE.save(); }
            })
            .catch(function (err) {
                DE.state.saving = false;
                DE.state.dirty = true;
                if (err.status === 409) { DE.emit('conflict', err.data); DE.emit('save-state', 'conflict'); return; }
                if (err.status === 403) { DE.state.readonly = err.data && /модерации/.test(err.data.error || ''); }
                DE.emit('save-state', 'error');
                DE.toast(err.message || 'Не удалось сохранить.', 'error');
                if (!err.status || err.status >= 500) { saveTimer = setTimeout(function () { DE.save(); }, 8000); }
            });
    };

    window.addEventListener('beforeunload', function (e) {
        if (DE.state.dirty || DE.state.saving) { backup(); e.preventDefault(); e.returnValue = ''; }
    });

    // ---- Привязанные поля (название и ответственный) ---------------------------------------------

    function applyBinds(slides) {
        slides.forEach(function (s) {
            s.elements.forEach(function (e) {
                if (e.bind === 'title') { e.html = '<p>' + R.esc(deck.meta.title || '') + '</p>'; }
                else if (e.bind === 'responsible') { e.html = U.responsibleHtml(deck.meta.responsible || ''); }
            });
        });
    }
    DE.applyBinds = applyBinds;

    DE.setMeta = function (field, value) {
        DE.change(function (d) {
            d.meta[field] = value;
            applyBinds(d.slides);
        }, { key: 'meta-' + field, what: 'slides' });
        if (DE.fitBoundTitles) { DE.fitBoundTitles(); }
    };

    // ---- Новые элементы и слайды -----------------------------------------------------------------

    var BLUE = '#0F4382', TEXT = '#262626';
    DE.BLUE = BLUE;

    DE.factory = function (type, o) {
        o = o || {};
        var base = { id: U.uid('e'), type: type, x: 100, y: 200, w: 400, h: 100, rotation: 0, opacity: 1, locked: false, group: null, name: null, bind: null, anim: null, link: null, shadow: null };
        var text = {
            html: '<p>Текст</p>', placeholder: '', fontFamily: 'Roboto', fontSize: 18, fontWeight: 400, italic: false, color: TEXT,
            align: 'left', valign: 'top', lineHeight: 1.2, letterSpacing: 0, spaceBefore: 0, caps: false, padding: 0,
            listColor: '#104382', autoFit: false, fill: null, gradient: null, stroke: null, strokeWidth: 0, dash: 'solid', radius: 0,
        };
        var e;
        switch (type) {
            case 'heading':
                e = Object.assign(base, text, { type: 'text', w: 900, h: 70, html: '<p>Заголовок</p>', fontFamily: 'DIN Condensed Light', fontSize: 40, color: BLUE, caps: true, lineHeight: 1.0, name: 'Заголовок' });
                break;
            case 'list':
                e = Object.assign(base, text, { type: 'text', w: 560, h: 200, html: '<p data-list="bullet">Первый пункт</p><p data-list="bullet">Второй пункт</p><p data-list="bullet">Третий пункт</p>', fontSize: 16, spaceBefore: 4 });
                break;
            case 'numbered':
                e = Object.assign(base, text, { type: 'text', w: 560, h: 200, html: '<p data-list="number">Первый шаг</p><p data-list="number">Второй шаг</p><p data-list="number">Третий шаг</p>', fontSize: 16, spaceBefore: 4 });
                break;
            case 'caption':
                e = Object.assign(base, text, { type: 'text', w: 400, h: 30, html: '<p>Подпись</p>', fontSize: 12, color: '#383838' });
                break;
            case 'stat':
                e = Object.assign(base, text, { type: 'text', w: 300, h: 110, html: '<p>123</p>', fontFamily: 'DIN Condensed Light', fontSize: 72, color: BLUE, lineHeight: 1.0, align: 'center' });
                break;
            case 'text':
                e = Object.assign(base, text, { w: 500, h: 60 });
                break;
            case 'shape':
                e = Object.assign(base, text, { type: 'shape', shape: o.shape || 'rect', w: 240, h: 160, html: '', fill: BLUE, color: '#FFFFFF', align: 'center', valign: 'middle', fontSize: 18 });
                if (['star5', 'star6', 'ellipse', 'donut', 'sun', 'moon', 'heart', 'octagon', 'hexagon', 'pentagon'].indexOf(e.shape) >= 0) { e.w = 180; e.h = 180; }
                if (/Arrow$/.test(e.shape) || e.shape === 'chevron' || e.shape === 'homePlate') { e.w = 260; e.h = 120; }
                break;
            case 'line':
                e = Object.assign(base, { x1: 200, y1: 360, x2: 600, y2: 360, stroke: BLUE, strokeWidth: 2, dash: 'solid', start: 'none', end: o.arrow ? 'triangle' : 'none' });
                break;
            case 'image':
                e = Object.assign(base, { w: 480, h: 320, src: null, fit: 'cover', focusX: 0.5, focusY: 0.5, zoom: 1, radius: 0, mask: 'none', border: null, alt: '', flipH: false, flipV: false, name: 'Изображение' });
                break;
            case 'table':
                var rows = [];
                for (var r = 0; r < 4; r++) {
                    var row = [];
                    for (var c = 0; c < 3; c++) { row.push({ text: r === 0 ? 'Заголовок ' + (c + 1) : '', bold: false, italic: false, color: null, fill: null, align: null, colspan: 1, rowspan: 1, hidden: false }); }
                    rows.push(row);
                }
                e = Object.assign(base, { w: 900, h: 220, rows: rows, cols: [1, 1, 1], heights: [1, 1, 1, 1], style: {
                    header: true, firstCol: false, banded: false, headerFill: '#034185', headerColor: '#FFFFFF', firstColFill: '#034185', firstColColor: '#FFFFFF',
                    bandFill: '#F2F4F8', fill: '#FFFFFF', borderColor: '#BFBFBF', borderWidth: 1, fontFamily: 'Roboto', fontSize: 16, color: TEXT, align: 'center', padding: 8,
                } });
                break;
            case 'chart':
                e = Object.assign(base, { w: 800, h: 420, chart: o.chart || 'column', categories: ['I кв.', 'II кв.', 'III кв.', 'IV кв.'],
                    series: [{ name: 'План', values: [120, 140, 160, 180], color: null }, { name: 'Факт', values: [110, 150, 155, 0], color: null }],
                    pointColors: [], legend: 'bottom', labels: false, title: null, stacked: false, fontSize: 12, color: '#383838', gridColor: '#D9D9D9' });
                if (e.chart === 'pie' || e.chart === 'doughnut') { e.series = [{ name: 'Доля', values: [45, 30, 15, 10], color: null }]; e.categories = ['Розница', 'Опт', 'Проекты', 'Сервис']; e.w = 520; e.h = 420; e.labels = true; e.legend = 'right'; }
                break;
            case 'qr':
                e = Object.assign(base, { w: 200, h: 200, text: o.text || '', color: BLUE, bg: '#FFFFFF', name: 'QR-код' });
                break;
            default:
                e = Object.assign(base, text);
        }
        return Object.assign(e, o.props || {});
    };

    // Новые идентификаторы для копий (элементы, группы и ссылки на слайды внутри копии).
    DE.freshIds = function (els) {
        var groups = {};
        return els.map(function (src) {
            var e = U.clone(src);
            e.id = U.uid('e');
            if (e.group) { groups[e.group] = groups[e.group] || U.uid('g'); e.group = groups[e.group]; }
            return e;
        });
    };

    DE.freshSlide = function (slide) {
        var s = U.clone(slide);
        s.id = U.uid('s');
        s.elements = DE.freshIds(s.elements || []);
        return s;
    };

    // Новые элементы — по центру видимой части слайда, со сдвигом, если там уже такой же.
    DE.addElements = function (els, opts) {
        opts = opts || {};
        if (!DE.slide()) { return; }
        DE.change(function (d) {
            var slide = d.slides[DE.state.slide];
            els.forEach(function (e) {
                if (opts.center !== false && e.type !== 'line' && opts.x === undefined) {
                    e.x = U.round((R.W - e.w) / 2); e.y = U.round((R.H - e.h) / 2);
                    while (slide.elements.some(function (o) { return Math.abs(o.x - e.x) < 2 && Math.abs(o.y - e.y) < 2; })) { e.x += 24; e.y += 24; }
                }
                if (slide.elements.length >= cfg.limits.elements) { return; }
                slide.elements.push(e);
            });
        });
        DE.select(els.map(function (e) { return e.id; }));
    };

    DE.removeSelected = function () {
        var ids = DE.state.sel.filter(function (id) { var e = DE.el(id); return e && !e.locked; });
        if (!ids.length) { if (DE.state.sel.length) { DE.toast('Закреплённые элементы не удаляются — снимите закрепление.'); } return; }
        DE.change(function (d) {
            var s = d.slides[DE.state.slide];
            s.elements = s.elements.filter(function (e) { return ids.indexOf(e.id) < 0; });
        });
        DE.select([]);
    };

    DE.duplicateSelected = function () {
        var els = DE.selected();
        if (!els.length) { return; }
        var copies = DE.freshIds(els).map(function (e) {
            if (e.type === 'line') { e.x1 += 20; e.y1 += 20; e.x2 += 20; e.y2 += 20; }
            e.x += 20; e.y += 20; e.locked = false; e.bind = null;
            return e;
        });
        DE.addElements(copies, { center: false, x: 0 });
    };

    // Буфер обмена: внутри редактора и между вкладками (localStorage), картинки и текст — из системного буфера.
    var CLIP_KEY = 'deck-clipboard';
    DE.copy = function (cut) {
        var els = DE.selected();
        if (!els.length) { return false; }
        var data = JSON.stringify({ kind: 'docportal/deck-elements', elements: els });
        DE.clipboard = data;
        try { localStorage.setItem(CLIP_KEY, data); } catch (e) { /* без хранилища */ }
        if (cut) { DE.removeSelected(); }
        DE.toast(cut ? 'Вырезано: ' + els.length : 'Скопировано: ' + els.length);
        return data;
    };
    DE.pasteElements = function (json) {
        var data;
        try { data = JSON.parse(json || DE.clipboard || localStorage.getItem(CLIP_KEY) || 'null'); } catch (e) { data = null; }
        if (!data || data.kind !== 'docportal/deck-elements' || !Array.isArray(data.elements)) { return false; }
        var copies = DE.freshIds(data.elements).map(function (e) {
            e.bind = null; e.locked = false;
            if (DE.el(data.elements[0].id)) { e.x += 20; e.y += 20; if (e.type === 'line') { e.x1 += 20; e.y1 += 20; e.x2 += 20; e.y2 += 20; } }
            return e;
        });
        DE.addElements(copies, { center: false, x: 0 });
        return true;
    };

    // ---- Порядок, выравнивание, группы ------------------------------------------------------------

    DE.order = function (where) {
        var ids = DE.state.sel;
        if (!ids.length) { return; }
        DE.change(function (d) {
            var list = d.slides[DE.state.slide].elements;
            var sel = list.filter(function (e) { return ids.indexOf(e.id) >= 0; });
            var rest = list.filter(function (e) { return ids.indexOf(e.id) < 0; });
            if (where === 'front') { list.splice.apply(list, [0, list.length].concat(rest.concat(sel))); return; }
            if (where === 'back') { list.splice.apply(list, [0, list.length].concat(sel.concat(rest))); return; }
            var idx = list.map(function (e, i) { return ids.indexOf(e.id) >= 0 ? i : -1; }).filter(function (i) { return i >= 0; });
            if (where === 'forward') {
                for (var i = idx.length - 1; i >= 0; i--) { var k = idx[i]; if (k < list.length - 1 && ids.indexOf(list[k + 1].id) < 0) { var t = list[k + 1]; list[k + 1] = list[k]; list[k] = t; } }
            } else {
                for (var j = 0; j < idx.length; j++) { var m = idx[j]; if (m > 0 && ids.indexOf(list[m - 1].id) < 0) { var u = list[m - 1]; list[m - 1] = list[m]; list[m] = u; } }
            }
        });
    };

    function box(e) {
        if (e.type === 'line') { return { x: Math.min(e.x1, e.x2), y: Math.min(e.y1, e.y2), w: Math.abs(e.x2 - e.x1), h: Math.abs(e.y2 - e.y1) }; }
        return { x: e.x, y: e.y, w: e.w, h: e.h };
    }
    DE.box = box;
    DE.bounds = function (els) {
        if (!els.length) { return null; }
        var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        els.forEach(function (e) { var b = box(e); x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y); x2 = Math.max(x2, b.x + b.w); y2 = Math.max(y2, b.y + b.h); });
        return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    };

    function moveBy(e, dx, dy) {
        e.x = U.round(e.x + dx); e.y = U.round(e.y + dy);
        if (e.type === 'line') { e.x1 = U.round(e.x1 + dx); e.x2 = U.round(e.x2 + dx); e.y1 = U.round(e.y1 + dy); e.y2 = U.round(e.y2 + dy); }
    }
    DE.moveBy = moveBy;

    // Выравнивание: один элемент — по слайду, несколько — по общей рамке.
    DE.align = function (how) {
        var els = DE.selected().filter(function (e) { return !e.locked; });
        if (!els.length) { return; }
        var ref = els.length === 1 ? { x: 0, y: 0, w: R.W, h: R.H } : DE.bounds(els);
        DE.change(function () {
            els.forEach(function (src) {
                var e = DE.el(src.id), b = box(e);
                var dx = 0, dy = 0;
                if (how === 'left') { dx = ref.x - b.x; } else if (how === 'center') { dx = ref.x + (ref.w - b.w) / 2 - b.x; } else if (how === 'right') { dx = ref.x + ref.w - b.w - b.x; }
                else if (how === 'top') { dy = ref.y - b.y; } else if (how === 'middle') { dy = ref.y + (ref.h - b.h) / 2 - b.y; } else if (how === 'bottom') { dy = ref.y + ref.h - b.h - b.y; }
                moveBy(e, dx, dy);
            });
        });
    };

    DE.distribute = function (axis) {
        var els = DE.selected().filter(function (e) { return !e.locked; });
        if (els.length < 3) { DE.toast('Выберите три элемента или больше.'); return; }
        DE.change(function () {
            var list = els.map(function (s) { return DE.el(s.id); }).sort(function (a, b) { return axis === 'h' ? box(a).x - box(b).x : box(a).y - box(b).y; });
            var first = box(list[0]), last = box(list[list.length - 1]);
            var total = list.reduce(function (sum, e) { return sum + (axis === 'h' ? box(e).w : box(e).h); }, 0);
            var span = axis === 'h' ? last.x + last.w - first.x : last.y + last.h - first.y;
            var gap = (span - total) / (list.length - 1), pos = axis === 'h' ? first.x : first.y;
            list.forEach(function (e) {
                var b = box(e);
                if (axis === 'h') { moveBy(e, pos - b.x, 0); pos += b.w + gap; } else { moveBy(e, 0, pos - b.y); pos += b.h + gap; }
            });
        });
    };

    DE.group = function () {
        if (DE.state.sel.length < 2) { DE.toast('Для группы выберите несколько элементов.'); return; }
        var g = U.uid('g');
        DE.change(function () { DE.selected().forEach(function (e) { DE.el(e.id).group = g; }); });
        DE.toast('Сгруппировано: элементы двигаются вместе (Alt+щелчок — один элемент).');
    };
    DE.ungroup = function () {
        DE.change(function () { DE.selected().forEach(function (e) { DE.el(e.id).group = null; }); });
    };

    // Свойство выбранных элементов (из панели «Формат»).
    DE.setProp = function (prop, value, opts) {
        var ids = (opts && opts.ids) || DE.state.sel;
        DE.change(function () {
            ids.forEach(function (id) {
                var e = DE.el(id);
                if (!e) { return; }
                if (typeof prop === 'function') { prop(e); } else { e[prop] = value; }
            });
        }, { key: (opts && opts.key) || ('prop-' + (typeof prop === 'string' ? prop : 'fn') + ids.join()) });
    };

    // ---- Слайды ----------------------------------------------------------------------------------

    DE.addSlide = function (slide, at) {
        if (deck.slides.length >= cfg.limits.slides) { DE.toast('В презентации уже ' + cfg.limits.slides + ' слайдов — это предел.', 'error'); return; }
        var s = DE.freshSlide(slide);
        applyBinds([s]);
        at = at === undefined ? DE.state.slide + 1 : at;
        DE.change(function (d) { d.slides.splice(at, 0, s); }, { what: 'slides' });
        DE.goto(at);
        return s;
    };

    DE.duplicateSlides = function (indexes) {
        indexes = (indexes || [DE.state.slide]).slice().sort(function (a, b) { return a - b; });
        var last = indexes[indexes.length - 1];
        DE.change(function (d) {
            var copies = indexes.map(function (i) { return DE.freshSlide(d.slides[i]); });
            d.slides.splice.apply(d.slides, [last + 1, 0].concat(copies));
        }, { what: 'slides' });
        DE.goto(last + 1);
    };

    DE.removeSlides = function (indexes) {
        indexes = (indexes || [DE.state.slide]).slice().sort(function (a, b) { return b - a; });
        if (indexes.length >= deck.slides.length) { DE.toast('В презентации должен остаться хотя бы один слайд.'); return; }
        DE.change(function (d) { indexes.forEach(function (i) { d.slides.splice(i, 1); }); }, { what: 'slides' });
        DE.goto(Math.min(indexes[indexes.length - 1], deck.slides.length - 1));
    };

    DE.moveSlides = function (indexes, to) {
        indexes = indexes.slice().sort(function (a, b) { return a - b; });
        var moving;
        DE.change(function (d) {
            moving = indexes.map(function (i) { return d.slides[i]; });
            var before = indexes.filter(function (i) { return i < to; }).length;
            for (var k = indexes.length - 1; k >= 0; k--) { d.slides.splice(indexes[k], 1); }
            var at = U.clamp(to - before, 0, d.slides.length);
            d.slides.splice.apply(d.slides, [at, 0].concat(moving));
        }, { what: 'slides' });
        var first = deck.slides.indexOf(moving[0]);
        DE.state.slideSel = moving.map(function (s) { return deck.slides.indexOf(s); });
        DE.goto(first, { keepSlideSel: true });
    };

    DE.toggleHidden = function (indexes) {
        indexes = indexes || [DE.state.slide];
        var hide = !deck.slides[indexes[0]].hidden;
        DE.change(function (d) { indexes.forEach(function (i) { d.slides[i].hidden = hide; }); }, { what: 'slides' });
        DE.toast(hide ? 'Слайд скрыт: при показе и в PDF его не будет, в PowerPoint — скрытый слайд.' : 'Слайд снова показывается.');
    };

    // ---- Изображения -----------------------------------------------------------------------------

    // Большие фотографии уменьшаются в браузере до 2560 пикселей по длинной стороне: быстрее загрузка,
    // меньше файл PowerPoint; прозрачные PNG остаются PNG.
    function downscale(file) {
        return new Promise(function (resolve) {
            if (!/^image\/(png|jpeg)$/.test(file.type) || !window.createImageBitmap) { resolve(file); return; }
            createImageBitmap(file).then(function (bmp) {
                var max = 2560, k = Math.min(1, max / Math.max(bmp.width, bmp.height));
                if (k >= 1 && file.size < 4 * 1024 * 1024) { resolve(file); return; }
                var c = document.createElement('canvas');
                c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
                var ctx = c.getContext('2d');
                ctx.drawImage(bmp, 0, 0, c.width, c.height);
                var png = file.type === 'image/png';
                if (png) {
                    // Нет прозрачности — можно сохранить как JPEG (заметно меньше).
                    var data = ctx.getImageData(0, 0, Math.min(c.width, 400), Math.min(c.height, 400)).data, opaque = true;
                    for (var i = 3; i < data.length; i += 16) { if (data[i] < 250) { opaque = false; break; } }
                    png = !opaque;
                }
                c.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) { resolve(file); return; }
                    resolve(new File([blob], file.name.replace(/\.(png|jpe?g)$/i, '') + (png ? '.png' : '.jpg'), { type: blob.type }));
                }, png ? 'image/png' : 'image/jpeg', 0.9);
            }).catch(function () { resolve(file); });
        });
    }

    DE.upload = function (file) {
        if (!file) { return Promise.reject(new Error('Файл не выбран.')); }
        if (!/^image\/(png|jpeg|gif)$/.test(file.type)) { return Promise.reject(new Error('Подходят изображения PNG, JPEG и GIF. Рисунки EMF, WMF, SVG и HEIC сохраните как PNG или JPEG.')); }
        DE.toast('Загружаю изображение…');
        return downscale(file).then(function (f) {
            if (f.size > cfg.limits.imageBytes) { throw new Error('Изображение больше 15 МБ.'); }
            var body = new FormData();
            body.append('file', f, f.name);
            return DE.api(cfg.urls.upload, { body: body });
        }).then(function (res) { DE.toast('Изображение загружено.'); return res; });
    };

    // Картинка: новый элемент или замена в выбранном; размер — по пропорциям картинки.
    DE.placeImage = function (res, target) {
        var ratio = res.width && res.height ? res.width / res.height : 1.5;
        if (target) {
            DE.setProp(function (e) { e.src = res.src; e.zoom = 1; e.focusX = 0.5; e.focusY = 0.5; }, null, { ids: [target.id], key: 'img-' + Date.now() });
            return;
        }
        var w = Math.min(800, res.width || 600), h = w / ratio;
        if (h > 520) { h = 520; w = h * ratio; }
        var e = DE.factory('image', { props: { src: res.src, w: U.round(w), h: U.round(h), fit: 'contain', alt: (res.name || '').replace(/\.[a-z0-9]+$/i, '') } });
        DE.addElements([e]);
    };

    // ---- Сообщения -------------------------------------------------------------------------------

    var toastBox = root.querySelector('[data-toast]'), toastTimer = null;
    DE.toast = function (message, kind, action) {
        if (!toastBox) { return; }
        toastBox.hidden = false;
        toastBox.className = 'de-toast' + (kind ? ' de-toast--' + kind : '');
        toastBox.textContent = message;
        if (action) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = action.label;
            b.addEventListener('click', function () { toastBox.hidden = true; action.fn(); });
            toastBox.appendChild(b);
        }
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastBox.hidden = true; }, action ? 12000 : (kind === 'error' ? 7000 : 3200));
    };
})();
