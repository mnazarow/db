// Редактор слайдов — интерфейс: панель инструментов, список слайдов, панель «Формат», замечания, история
// версий, проверка корпоративного стиля, галерея макетов, данные диаграмм, импорт и выгрузка, модерация.
(function () {
    'use strict';

    var DE = window.DE;
    if (!DE) { return; }
    var R = DE.R, U = DE.util, cfg = DE.cfg, root = DE.root;
    var esc = U.esc;
    var toolbar = root.querySelector('[data-toolbar]');
    var topRight = root.querySelector('[data-top-right]');
    var list = root.querySelector('[data-slides]');
    var panel = root.querySelector('[data-panel]');
    var notes = root.querySelector('[data-notes]');
    var menuBox = root.querySelector('[data-menu]');
    var dialog = root.querySelector('[data-dialog]');
    var dialogBody = root.querySelector('[data-dialog-body]');
    var saveState = root.querySelector('[data-save-state]');
    var info = root.querySelector('[data-info]');
    var imageInput = root.querySelector('[data-file-image]');
    var pptxInput = root.querySelector('[data-file-pptx]');
    var tab = 'format';
    var comments = [];

    var TYPE_NAMES = { text: 'Надпись', shape: 'Фигура', line: 'Линия', image: 'Изображение', table: 'Таблица', chart: 'Диаграмма', qr: 'QR-код' };
    var SHAPE_NAMES = {
        rect: 'Прямоугольник', roundRect: 'Скруглённый прямоугольник', ellipse: 'Овал', triangle: 'Треугольник', rtTriangle: 'Прямоугольный треугольник',
        diamond: 'Ромб', pentagon: 'Пятиугольник', hexagon: 'Шестиугольник', octagon: 'Восьмиугольник', star5: 'Звезда (5)', star6: 'Звезда (6)',
        rightArrow: 'Стрелка вправо', leftArrow: 'Стрелка влево', upArrow: 'Стрелка вверх', downArrow: 'Стрелка вниз', leftRightArrow: 'Двойная стрелка',
        chevron: 'Шеврон', homePlate: 'Указатель', parallelogram: 'Параллелограмм', trapezoid: 'Трапеция', plus: 'Плюс', heart: 'Сердце', donut: 'Кольцо',
        wedgeRectCallout: 'Выноска', wedgeRoundRectCallout: 'Скруглённая выноска', cloud: 'Облако', can: 'Цилиндр', cube: 'Куб', lightningBolt: 'Молния', moon: 'Месяц', sun: 'Солнце',
    };
    var CHART_NAMES = { column: 'Гистограмма', bar: 'Линейчатая', line: 'График', area: 'С областями', pie: 'Круговая', doughnut: 'Кольцевая' };
    var ANIM_NAMES = { '': 'Нет', fade: 'Появление', 'fade-up': 'Выезд снизу', 'fade-down': 'Выезд сверху', 'fade-left': 'Выезд справа', 'fade-right': 'Выезд слева', zoom: 'Увеличение', wipe: 'Вытеснение' };
    var TRANSITION_NAMES = { none: 'Нет', fade: 'Растворение', slide: 'Сдвиг', zoom: 'Приближение', cover: 'Наплыв' };
    var ARROW_NAMES = { none: 'Нет', arrow: 'Стрелка', triangle: 'Треугольник', oval: 'Круг', diamond: 'Ромб' };
    var CORPORATE_FONTS = ['DIN Condensed Light', 'DIN Condensed', 'Roboto'];

    // ---- Меню и всплывающие окна ------------------------------------------------------------------

    function openMenu(items, x, y) {
        menuBox.innerHTML = '';
        items.filter(Boolean).forEach(function (it) {
            if (it.sep) { menuBox.appendChild(U.h('<hr>')); return; }
            if (it.title) { menuBox.appendChild(U.h('<div class="de-menu__title">' + esc(it.title) + '</div>')); return; }
            var b = U.h('<button type="button" class="de-menu__item"' + (it.disabled ? ' disabled' : '') + '>' + (it.icon ? '<span class="de-menu__icon">' + it.icon + '</span>' : '') + '<span>' + esc(it.label) + '</span>' + (it.shortcut ? '<kbd>' + esc(it.shortcut) + '</kbd>' : '') + '</button>');
            b.addEventListener('click', function () { closeMenu(); it.fn(); });
            menuBox.appendChild(b);
        });
        menuBox.hidden = false;
        var w = menuBox.offsetWidth, h = menuBox.offsetHeight;
        menuBox.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 8)) + 'px';
        menuBox.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 8)) + 'px';
        var first = menuBox.querySelector('button:not([disabled])');
        if (first) { first.focus(); }
    }
    function closeMenu() { menuBox.hidden = true; menuBox.innerHTML = ''; }
    DE.menu = openMenu;
    document.addEventListener('pointerdown', function (e) { if (!menuBox.hidden && !menuBox.contains(e.target) && !e.target.closest('[data-menu-anchor]')) { closeMenu(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menuBox.hidden) { closeMenu(); e.stopPropagation(); } }, true);
    function menuAt(anchor, items) { var r = anchor.getBoundingClientRect(); openMenu(items, r.left, r.bottom + 4); }

    function openDialog(html, onReady, opts) {
        dialogBody.innerHTML = html;
        dialog.className = 'de-dialog' + (opts && opts.wide ? ' de-dialog--wide' : '');
        if (!dialog.open) { dialog.showModal(); }
        Array.prototype.forEach.call(dialogBody.querySelectorAll('[data-close]'), function (b) { b.addEventListener('click', function () { dialog.close(); }); });
        if (onReady) { onReady(dialogBody); }
    }
    DE.dialog = openDialog;

    // Выбор цвета: фирменная палитра, недавние, свой цвет.
    var recent = [];
    function colorPicker(anchor, value, allowNone, pick) {
        var html = '<div class="de-colors"><div class="de-colors__title">Фирменные цвета</div><div class="de-colors__grid">'
            + cfg.palette.map(function (p) { return '<button type="button" class="de-swatch' + (value && value.toUpperCase().indexOf(p[0]) === 0 ? ' is-on' : '') + '" data-c="' + p[0] + '" title="' + esc(p[1]) + ' ' + p[0] + '" style="background:' + p[0] + '"></button>'; }).join('')
            + '</div>' + (recent.length ? '<div class="de-colors__title">Недавние</div><div class="de-colors__grid">' + recent.map(function (c) { return '<button type="button" class="de-swatch" data-c="' + c + '" title="' + c + '" style="background:' + R.color(c) + '"></button>'; }).join('') + '</div>' : '')
            + '<div class="de-colors__custom"><input type="color" data-custom value="' + (value && /^#[0-9a-f]{6}/i.test(value) ? value.substr(0, 7) : '#0F4382') + '" aria-label="Свой цвет"><input class="de-input" data-hex value="' + esc(value || '') + '" placeholder="#RRGGBB" maxlength="9" aria-label="Код цвета">'
            + '<button type="button" class="de-btn de-btn--small" data-apply>OK</button></div>'
            + (allowNone ? '<button type="button" class="de-btn de-btn--ghost de-btn--small de-colors__none" data-c="">Без цвета</button>' : '') + '</div>';
        menuBox.innerHTML = html;
        menuBox.hidden = false;
        var r = anchor.getBoundingClientRect();
        menuBox.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 260)) + 'px';
        menuBox.style.top = Math.min(r.bottom + 4, window.innerHeight - menuBox.offsetHeight - 8) + 'px';
        var done = function (c) {
            if (c && !/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(c)) { DE.toast('Цвет — в виде #RRGGBB.', 'error'); return; }
            if (c) { c = c.toUpperCase(); recent = [c].concat(recent.filter(function (x) { return x !== c; })).slice(0, 8); }
            closeMenu();
            pick(c || null);
        };
        menuBox.querySelectorAll('[data-c]').forEach(function (b) { b.addEventListener('click', function () { done(b.getAttribute('data-c')); }); });
        var hex = menuBox.querySelector('[data-hex]'), custom = menuBox.querySelector('[data-custom]');
        custom.addEventListener('input', function () { hex.value = custom.value.toUpperCase(); });
        menuBox.querySelector('[data-apply]').addEventListener('click', function () { done(hex.value.trim()); });
        hex.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); done(hex.value.trim()); } });
    }
    DE.on('color-picker', function (o) { colorPicker(o.anchor, o.value, o.allowNone, o.pick); });

    // ---- Панель инструментов -----------------------------------------------------------------------

    function icon(name) {
        var p = {
            undo: '<path d="M9 7 4 12l5 5M4 12h11a5 5 0 0 1 0 10h-3"/>', redo: '<path d="m15 7 5 5-5 5M20 12H9a5 5 0 0 0 0 10h3"/>',
            text: '<path d="M5 6h14M12 6v13M9 19h6"/>', shape: '<rect x="3.5" y="5" width="9" height="9" rx="1"/><circle cx="16" cy="15" r="5"/>',
            line: '<path d="M4 19 20 5"/>', image: '<rect x="3.5" y="5" width="17" height="14" rx="1.5"/><path d="m4 16 4.5-5 4 4 2.5-2.5L20 17"/><circle cx="15.5" cy="9" r="1.4"/>',
            table: '<rect x="3.5" y="5" width="17" height="14" rx="1"/><path d="M3.5 10h17M3.5 14.5h17M9.5 5v14M15 5v14"/>',
            chart: '<path d="M4 20V4M4 20h16"/><rect x="7" y="11" width="3" height="7"/><rect x="12" y="7" width="3" height="11"/><rect x="17" y="13" width="3" height="5"/>',
            qr: '<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2"/>',
            brand: '<path d="M12 3c3 4 6 7 6 11a6 6 0 0 1-12 0c0-4 3-7 6-11z"/>', play: '<path d="M7 5v14l12-7z"/>', down: '<path d="M12 4v11m-5-4 5 5 5-5M5 20h14"/>',
            help: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>', send: '<path d="M4 12 20 4l-4 16-4-6-8-2z"/>', check: '<path d="m5 12 4 4 10-10"/>',
        }[name] || '';
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>';
    }

    function buildToolbar() {
        toolbar.innerHTML = ''
            + '<button type="button" class="de-tool" data-act="undo" title="Отменить (Ctrl+Z)">' + icon('undo') + '</button>'
            + '<button type="button" class="de-tool" data-act="redo" title="Повторить (Ctrl+Shift+Z)">' + icon('redo') + '</button>'
            + '<span class="de-tool-sep"></span>'
            + '<button type="button" class="de-tool de-tool--label" data-act="insert-text" data-menu-anchor title="Надпись, заголовок, список">' + icon('text') + '<span>Текст</span></button>'
            + '<button type="button" class="de-tool de-tool--label" data-act="insert-shape" data-menu-anchor title="Фигуры">' + icon('shape') + '<span>Фигура</span></button>'
            + '<button type="button" class="de-tool de-tool--label de-tool--minor" data-act="insert-line" data-menu-anchor title="Линия или стрелка">' + icon('line') + '<span>Линия</span></button>'
            + '<button type="button" class="de-tool de-tool--label" data-act="insert-image" data-menu-anchor title="Изображение с компьютера, место для изображения, фирменные картинки">' + icon('image') + '<span>Изображение</span></button>'
            + '<button type="button" class="de-tool de-tool--label de-tool--minor" data-act="insert-table" title="Таблица в фирменном стиле">' + icon('table') + '<span>Таблица</span></button>'
            + '<button type="button" class="de-tool de-tool--label" data-act="insert-chart" data-menu-anchor title="Диаграмма">' + icon('chart') + '<span>Диаграмма</span></button>'
            + '<button type="button" class="de-tool de-tool--label de-tool--minor" data-act="insert-qr" title="QR-код ссылки">' + icon('qr') + '<span>QR</span></button>';
        var moderation = '';
        if (cfg.canDecide) {
            moderation = '<button type="button" class="de-btn de-btn--primary" data-act="decide" data-menu-anchor>' + icon('check') + 'Решение</button>';
        } else if (cfg.canSubmit) {
            moderation = '<button type="button" class="de-btn de-btn--primary" data-act="submit">' + icon('send') + 'На модерацию</button>';
        }
        topRight.innerHTML = ''
            + '<button type="button" class="de-btn" data-act="present" data-menu-anchor title="Показ (F5 — с начала, Shift+F5 — с текущего)">' + icon('play') + 'Показ</button>'
            + '<button type="button" class="de-btn" data-act="export" data-menu-anchor title="PowerPoint, PDF, импорт из PowerPoint">' + icon('down') + 'Файл</button>'
            + moderation
            + '<button type="button" class="de-tool" data-act="help" title="Клавиши и подсказки (?)">' + icon('help') + '</button>';
    }

    var SHAPE_ORDER = ['rect', 'roundRect', 'ellipse', 'triangle', 'rtTriangle', 'diamond', 'pentagon', 'hexagon', 'octagon', 'parallelogram', 'trapezoid', 'star5', 'star6', 'plus',
        'rightArrow', 'leftArrow', 'upArrow', 'downArrow', 'leftRightArrow', 'chevron', 'homePlate', 'wedgeRectCallout', 'wedgeRoundRectCallout', 'heart', 'donut', 'cloud', 'can', 'cube', 'lightningBolt', 'moon', 'sun'];

    function shapeIcon(kind) {
        return '<svg viewBox="-2 -2 44 34" aria-hidden="true"><path d="' + R.shapePath(kind, 40, 30, 0) + '" fill="#E6E9F0" stroke="#0F4382" stroke-width="1.5" fill-rule="evenodd"/></svg>';
    }

    function shapeGallery(anchor, pick) {
        menuBox.innerHTML = '<div class="de-shapes">' + SHAPE_ORDER.filter(function (s) { return cfg.shapes.indexOf(s) >= 0; }).map(function (s) {
            return '<button type="button" class="de-shapes__item" data-shape="' + s + '" title="' + esc(SHAPE_NAMES[s] || s) + '">' + shapeIcon(s) + '</button>';
        }).join('') + '</div>';
        menuBox.hidden = false;
        var r = anchor.getBoundingClientRect();
        menuBox.style.left = Math.min(r.left, window.innerWidth - 300) + 'px';
        menuBox.style.top = (r.bottom + 4) + 'px';
        menuBox.querySelectorAll('[data-shape]').forEach(function (b) { b.addEventListener('click', function () { closeMenu(); pick(b.getAttribute('data-shape')); }); });
    }

    function brandItems(onPick) {
        return Object.keys(cfg.brand).map(function (key) { return { label: cfg.brand[key].label, fn: function () { onPick(key); } }; });
    }

    function insertBrand(key, target) {
        var b = cfg.brand[key];
        if (target) { DE.setProp(function (e) { e.src = 'brand:' + key; e.fit = 'contain'; }, null, { ids: [target.id] }); return; }
        var w = Math.min(400, b.width), h = w * b.height / b.width;
        if (h > 400) { h = 400; w = h * b.width / b.height; }
        DE.addElements([DE.factory('image', { props: { src: 'brand:' + key, w: U.round(w), h: U.round(h), fit: 'contain', name: b.label } })]);
    }

    var pendingImageTarget = null;
    function pickImage(target) {
        pendingImageTarget = target || null;
        imageInput.value = '';
        imageInput.click();
    }
    imageInput.addEventListener('change', function () {
        var f = imageInput.files[0];
        if (!f) { return; }
        var target = pendingImageTarget;
        var bgSlide = target === 'background' ? DE.state.slide : null;
        DE.upload(f).then(function (res) {
            if (bgSlide !== null) {
                DE.change(function (d) { d.slides[bgSlide].background.image = res.src; });
                return;
            }
            DE.placeImage(res, target && target.id ? target : null);
        }).catch(function (err) { DE.toast(err.message, 'error'); });
    });
    DE.on('pick-image', function (e) { pickImage(e); });

    function act(name, anchor) {
        var slide = DE.slide();
        switch (name) {
            case 'undo': DE.undo(); break;
            case 'redo': DE.redo(); break;
            case 'insert-text':
                menuAt(anchor, [
                    { label: 'Надпись', fn: function () { DE.addElements([DE.factory('text')]); } },
                    { label: 'Заголовок (DIN Condensed, прописные)', fn: function () { DE.addElements([DE.factory('heading')]); } },
                    { label: 'Маркированный список', fn: function () { DE.addElements([DE.factory('list')]); } },
                    { label: 'Нумерованный список', fn: function () { DE.addElements([DE.factory('numbered')]); } },
                    { label: 'Подпись', fn: function () { DE.addElements([DE.factory('caption')]); } },
                    { label: 'Крупная цифра', fn: function () { DE.addElements([DE.factory('stat')]); } },
                ]);
                break;
            case 'insert-shape': shapeGallery(anchor, function (kind) { DE.addElements([DE.factory('shape', { shape: kind })]); }); break;
            case 'insert-line':
                menuAt(anchor, [
                    { label: 'Линия', fn: function () { DE.addElements([DE.factory('line')], { center: false }); } },
                    { label: 'Стрелка', fn: function () { DE.addElements([DE.factory('line', { arrow: true })], { center: false }); } },
                ]);
                break;
            case 'insert-image':
                menuAt(anchor, [{ label: 'С компьютера…', fn: function () { pickImage(null); } },
                    { label: 'Место для изображения', fn: function () { DE.addElements([DE.factory('image')]); } },
                    { sep: true }, { title: 'Фирменные изображения' }].concat(brandItems(function (key) { insertBrand(key); })));
                break;
            case 'insert-table': DE.addElements([DE.factory('table')]); break;
            case 'insert-chart':
                menuAt(anchor, Object.keys(CHART_NAMES).map(function (c) { return { label: CHART_NAMES[c], fn: function () { DE.addElements([DE.factory('chart', { chart: c })]); } }; }));
                break;
            case 'insert-qr':
                var url = window.prompt('Ссылка или текст для QR-кода:', 'https://');
                if (url && url.trim() && url.trim() !== 'https://') { DE.addElements([DE.factory('qr', { text: url.trim().substr(0, 200) })]); }
                break;
            case 'present':
                menuAt(anchor, [{ label: 'С начала', shortcut: 'F5', fn: function () { present(true); } }, { label: 'С текущего слайда', shortcut: 'Shift+F5', fn: function () { present(false); } },
                    { label: 'Окно заметок докладчика', fn: function () { DE.save().then(function () { window.open(cfg.urls.present + '?speaker=1&slide=' + (DE.state.slide + 1), 'deck-speaker', 'width=1100,height=720'); }); } }]);
                break;
            case 'export':
                menuAt(anchor, [
                    { label: 'Скачать PowerPoint (.pptx)', fn: function () { DE.save().then(function () { location.href = cfg.urls.export; }); } },
                    { label: 'PDF (печать)', fn: function () { DE.save().then(function () { window.open(cfg.urls.print, '_blank'); }); } },
                    { sep: true },
                    { label: 'Импорт слайдов из PowerPoint…', fn: function () { pptxInput.value = ''; pptxInput.click(); } },
                    { label: 'Страница презентации', fn: function () { DE.save().then(function () { location.href = cfg.urls.show; }); } },
                ]);
                break;
            case 'submit': submitDialog(); break;
            case 'decide':
                menuAt(anchor, [{ label: 'Одобрить', fn: function () { decideDialog('approve'); } }, { label: 'Вернуть на доработку…', fn: function () { decideDialog('reject'); } }]);
                break;
            case 'help': helpDialog(); break;
            case 'slide-new': layoutGallery(); break;
            case 'notes-toggle':
                var open = notes.hidden;
                notes.hidden = !open;
                anchor.setAttribute('aria-expanded', String(open));
                DE.layout();
                break;
            case 'zoom-in': DE.setZoom((DE.scale || 1) * 1.2); break;
            case 'zoom-out': DE.setZoom((DE.scale || 1) / 1.2); break;
            case 'zoom-fit': DE.setZoom(DE.state.zoom === 'fit' ? 1 : 'fit'); break;
        }
        return slide;
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (!b || !root.contains(b) || b.closest('[data-menu]')) { return; }
        act(b.getAttribute('data-act'), b);
    });

    function present(fromStart) {
        DE.save().then(function () { window.open(cfg.urls.present + (fromStart ? '' : '?slide=' + (DE.state.slide + 1)), '_blank'); });
    }
    DE.on('present', present);
    DE.on('new-slide', function () { layoutGallery(); });
    DE.on('help', function () { helpDialog(); });

    // ---- Отправка формы (модерация, восстановление версии) ----------------------------------------

    function postForm(url, fields) {
        var f = document.createElement('form');
        f.method = 'post'; f.action = url; f.hidden = true;
        fields._token = cfg.formToken;
        Object.keys(fields).forEach(function (k) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = k; i.value = fields[k];
            f.appendChild(i);
        });
        document.body.appendChild(f);
        DE.state.dirty = false;
        f.submit();
    }

    function submitDialog() {
        var problems = runChecks().filter(function (p) { return p.level === 'warn'; }).length;
        openDialog('<h2 class="h-section">Отправить на модерацию</h2>'
            + '<p>' + (cfg.kind === 'topic' ? 'Тема' : 'Презентация') + ' уйдёт маркетологам и помощникам маркетолога. Пока она на модерации, автор её не меняет (можно отозвать).</p>'
            + (problems ? '<p class="de-warn">Проверка стиля нашла замечаний: ' + problems + ' — посмотрите вкладку «Проверка».</p>' : '')
            + '<label class="form-label" for="de-submit-note">Комментарий для маркетолога</label><textarea class="form-control" id="de-submit-note" rows="3" maxlength="2000" placeholder="Необязательно"></textarea>'
            + '<div class="form-actions"><button type="button" class="btn btn--pill" data-go>Отправить</button><button type="button" class="btn btn--ghost" data-close>Отмена</button></div>',
        function (body) {
            body.querySelector('[data-go]').addEventListener('click', function () {
                var note = body.querySelector('textarea').value;
                DE.save(true).then(function () { postForm(cfg.urls.submit, { note: note, back: cfg.urls.show }); });
            });
        });
    }

    function decideDialog(decision) {
        openDialog('<h2 class="h-section">' + (decision === 'approve' ? 'Одобрить' : 'Вернуть на доработку') + '</h2>'
            + (decision === 'approve' ? '<p>' + (cfg.kind === 'topic' ? 'Тема войдёт в презентацию собрания (в этой редакции).' : 'Сотрудники увидят эту редакцию в «Готовых презентациях».') + '</p>' : '<p>Опишите, что исправить; замечания к отдельным слайдам можно оставить на вкладке «Замечания».</p>')
            + '<label class="form-label" for="de-decide-note">Комментарий' + (decision === 'reject' ? ' (обязательно)' : '') + '</label><textarea class="form-control" id="de-decide-note" rows="3" maxlength="2000"></textarea>'
            + '<div class="form-actions"><button type="button" class="btn btn--pill" data-go>' + (decision === 'approve' ? 'Одобрить' : 'Вернуть на доработку') + '</button><button type="button" class="btn btn--ghost" data-close>Отмена</button></div>',
        function (body) {
            body.querySelector('[data-go]').addEventListener('click', function () {
                var note = body.querySelector('textarea').value.trim();
                if (decision === 'reject' && !note) { DE.toast('Напишите, что исправить.', 'error'); return; }
                DE.save(true).then(function () { postForm(cfg.urls.decide, { decision: decision, note: note, back: cfg.urls.show }); });
            });
        });
    }

    // ---- Галерея макетов -------------------------------------------------------------------------

    // Библиотека фирменных слайдов (маркетологи сохраняют удачные слайды как общие шаблоны).
    var library = null;
    function loadLibrary(force) {
        if (!cfg.library) { return Promise.resolve({ templates: [], categories: [] }); }
        if (library && !force) { return Promise.resolve(library); }
        return DE.api(cfg.library.url).then(function (res) { library = res; return res; });
    }

    function renderLibrary(body) {
        var box = body.querySelector('[data-library-body]');
        var ts = (library && library.templates) || [];
        if (!ts.length) {
            box.innerHTML = '<p class="hints">В библиотеке пока нет слайдов.' + (cfg.isModerator ? ' Сохраните удачный слайд: меню слайда «⋯» в списке слева → «Сохранить в библиотеку…».' : ' Их добавляют маркетологи.') + '</p>';
            return;
        }
        var groups = {};
        ts.forEach(function (t, i) { (groups[t.category] = groups[t.category] || []).push(i); });
        var html = '<div class="de-layouts">';
        Object.keys(groups).forEach(function (g) {
            html += '<div class="de-layouts__group"><div class="de-layouts__title">' + esc(g) + '</div><div class="de-layouts__grid">';
            groups[g].forEach(function (i) {
                var t = ts[i];
                html += '<button type="button" class="de-layouts__item" data-tpl="' + i + '" title="' + esc(t.description || t.title) + '"><div class="de-layouts__thumb" data-tpl-thumb="' + i + '"></div><span>' + esc(t.title) + '</span></button>';
            });
            html += '</div></div>';
        });
        box.innerHTML = html + '</div>';
        box.querySelectorAll('[data-tpl-thumb]').forEach(function (el) {
            var s = U.clone(ts[+el.getAttribute('data-tpl-thumb')].slide);
            DE.applyBinds([s]);
            R.mount(el, s, { mode: 'thumb' });
        });
        box.querySelectorAll('[data-tpl]').forEach(function (b) {
            b.addEventListener('click', function () {
                var t = ts[+b.getAttribute('data-tpl')];
                dialog.close();
                DE.addSlide(t.slide);
                DE.toast('Вставлен слайд «' + t.title + '» из библиотеки — замените текст и картинки.');
                DE.api(cfg.library.use.replace(/0$/, String(t.id)), { json: {}, token: cfg.library.token }).catch(function () { /* учёт не важен */ });
            });
        });
    }

    function layoutGallery(startTab) {
        var groups = {};
        Object.keys(cfg.layouts).forEach(function (key) { var l = cfg.layouts[key]; (groups[l.group] = groups[l.group] || []).push(key); });
        var html = '<h2 class="h-section">Новый слайд</h2>';
        if (cfg.library) {
            html += '<div class="de-panel__tabs de-gallery-tabs" role="tablist"><button type="button" role="tab" data-gtab="layouts" aria-selected="true">Макеты</button><button type="button" role="tab" data-gtab="library" aria-selected="false">Библиотека слайдов</button></div>';
        }
        html += '<div data-gpane="layouts"><p class="hints">Макеты в стиле «Презентации к собранию». Слайд встанет после текущего.</p><div class="de-layouts">';
        Object.keys(groups).forEach(function (g) {
            html += '<div class="de-layouts__group"><div class="de-layouts__title">' + esc(g) + '</div><div class="de-layouts__grid">';
            groups[g].forEach(function (key) {
                var l = cfg.layouts[key];
                html += '<button type="button" class="de-layouts__item" data-layout="' + key + '" title="' + esc(l.description) + '"><div class="de-layouts__thumb" data-layout-thumb="' + key + '"></div><span>' + esc(l.name) + '</span></button>';
            });
            html += '</div></div>';
        });
        html += '</div></div>';
        if (cfg.library) {
            html += '<div data-gpane="library" hidden><p class="hints">Фирменные слайды, которые подготовили маркетологи. Вставляется копия — замените текст и картинки. <a href="' + esc(cfg.library.page) + '" target="_blank" rel="noopener">Вся библиотека</a></p><div data-library-body><p class="hints">Загрузка…</p></div></div>';
        }
        html += '<div class="form-actions"><button type="button" class="btn btn--ghost" data-close>Отмена</button></div>';
        openDialog(html, function (body) {
            body.querySelectorAll('[data-layout-thumb]').forEach(function (box) {
                var l = cfg.layouts[box.getAttribute('data-layout-thumb')];
                var s = U.clone(cfg.kind === 'topic' ? l.boundSlide : l.slide);
                DE.applyBinds([s]);
                R.mount(box, s, { mode: 'thumb' });
            });
            body.querySelectorAll('[data-layout]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var l = cfg.layouts[b.getAttribute('data-layout')];
                    dialog.close();
                    DE.addSlide(cfg.kind === 'topic' ? l.boundSlide : l.slide);
                });
            });
            var show = function (name) {
                body.querySelectorAll('[data-gtab]').forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-gtab') === name ? 'true' : 'false'); });
                body.querySelectorAll('[data-gpane]').forEach(function (p) { p.hidden = p.getAttribute('data-gpane') !== name; });
                if (name === 'library') {
                    loadLibrary().then(function () { renderLibrary(body); }).catch(function (err) {
                        body.querySelector('[data-library-body]').innerHTML = '<p class="form-error">' + esc(err.message || 'Библиотека не загрузилась.') + '</p>';
                    });
                }
            };
            body.querySelectorAll('[data-gtab]').forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-gtab')); }); });
            if (startTab === 'library' && cfg.library) { show('library'); }
        }, { wide: true });
    }

    // Сохранить слайд в библиотеку — для маркетологов и помощников маркетолога.
    function saveToLibrary(index) {
        var s = DE.slide(index);
        if (!s || !cfg.library) { return; }
        var firstText = '';
        s.elements.some(function (e) {
            if (e.type === 'text' && e.html) { var d = document.createElement('div'); d.innerHTML = e.html; firstText = (d.textContent || '').trim().slice(0, 120); }
            return !!firstText;
        });
        var layoutName = s.layout && cfg.layouts[s.layout] ? cfg.layouts[s.layout].name : '';
        loadLibrary().catch(function () { return { categories: [] }; }).then(function (lib) {
            var cats = (lib && lib.categories) || [];
            openDialog('<h2 class="h-section">Сохранить слайд в библиотеку</h2>'
                + '<p class="hints">Слайд станет образцом для всех сотрудников: его копию можно вставить из «+ Слайд» → «Библиотека слайдов». Заметки докладчика не сохраняются.</p>'
                + '<div class="de-lib-preview" data-lib-preview></div>'
                + '<label class="form-label" for="de-lib-title">Название</label><input class="form-control" id="de-lib-title" maxlength="200" value="' + esc(firstText || layoutName || 'Слайд') + '">'
                + '<label class="form-label" for="de-lib-cat">Категория</label><input class="form-control" id="de-lib-cat" maxlength="100" list="de-lib-cats" placeholder="Общие" value="' + esc(layoutName ? (cfg.layouts[s.layout].group || '') : '') + '"><datalist id="de-lib-cats">' + cats.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist>'
                + '<label class="form-label" for="de-lib-desc">Описание</label><textarea class="form-control" id="de-lib-desc" rows="2" maxlength="1000" placeholder="Когда использовать (необязательно)"></textarea>'
                + '<div class="form-actions"><button type="button" class="btn btn--pill" data-go>Сохранить в библиотеку</button><button type="button" class="btn btn--ghost" data-close>Отмена</button></div>',
            function (body) {
                R.mount(body.querySelector('[data-lib-preview]'), s, { mode: 'thumb' });
                body.querySelector('#de-lib-title').select();
                body.querySelector('[data-go]').addEventListener('click', function () {
                    var title = body.querySelector('#de-lib-title').value.trim();
                    if (!title) { DE.toast('Укажите название слайда.', 'error'); return; }
                    var go = this;
                    go.disabled = true;
                    DE.api(cfg.library.create, { token: cfg.library.token, json: { title: title, category: body.querySelector('#de-lib-cat').value.trim(), description: body.querySelector('#de-lib-desc').value.trim(), slide: s } })
                        .then(function (res) { dialog.close(); library = null; DE.toast(res.message); })
                        .catch(function (err) { go.disabled = false; DE.toast(err.message, 'error'); });
                });
            });
        });
    }

    // ---- Список слайдов --------------------------------------------------------------------------

    var thumbTimer = null;
    function commentCount(slideId) {
        return comments.filter(function (c) { return c.slideId === slideId && !c.parentId && !c.resolved; }).length;
    }

    function renderSlides() {
        list.innerHTML = '';
        DE.deck.slides.forEach(function (s, i) {
            var li = document.createElement('li');
            li.className = 'de-slide' + (i === DE.state.slide ? ' is-current' : '') + (DE.state.slideSel.indexOf(i) >= 0 ? ' is-selected' : '') + (s.hidden ? ' is-hidden' : '');
            li.draggable = true;
            li.setAttribute('data-index', i);
            var n = commentCount(s.id);
            li.innerHTML = '<span class="de-slide__num">' + (i + 1) + '</span><div class="de-slide__frame"></div>'
                + (s.hidden ? '<span class="de-slide__hidden" title="Скрытый слайд">скрыт</span>' : '')
                + (n ? '<span class="de-slide__comments" title="Открытые замечания">' + n + '</span>' : '')
                + '<button type="button" class="de-slide__more" data-slide-menu aria-label="Действия со слайдом ' + (i + 1) + '" data-menu-anchor>⋯</button>';
            list.appendChild(li);
            R.mount(li.querySelector('.de-slide__frame'), s, { mode: 'thumb' });
        });
        var cur = list.children[DE.state.slide];
        if (cur && cur.scrollIntoView) { cur.scrollIntoView({ block: 'nearest' }); }
        updateInfo();
    }

    function refreshThumb(index) {
        clearTimeout(thumbTimer);
        thumbTimer = setTimeout(function () {
            var li = list.children[index], s = DE.slide(index);
            if (!li || !s) { renderSlides(); return; }
            R.mount(li.querySelector('.de-slide__frame'), s, { mode: 'thumb' });
            li.classList.toggle('is-hidden', !!s.hidden);
        }, 250);
    }

    function slideMenu(index, x, y) {
        var sel = DE.state.slideSel.indexOf(index) >= 0 && DE.state.slideSel.length > 1 ? DE.state.slideSel.slice() : [index];
        var hidden = DE.slide(index).hidden;
        openMenu([
            { label: 'Новый слайд после…', shortcut: 'Ctrl+M', fn: function () { DE.goto(index); layoutGallery(); } },
            { label: sel.length > 1 ? 'Дублировать слайды (' + sel.length + ')' : 'Дублировать', shortcut: 'Ctrl+D', fn: function () { DE.duplicateSlides(sel); } },
            { label: hidden ? 'Показывать слайд' : 'Скрыть слайд', fn: function () { DE.toggleHidden(sel); } },
            { label: 'Вставить из библиотеки…', fn: function () { DE.goto(index); layoutGallery('library'); } },
            cfg.isModerator && cfg.library ? { label: 'Сохранить в библиотеку…', fn: function () { saveToLibrary(index); } } : null,
            { sep: true },
            { label: 'Выше', disabled: index === 0, fn: function () { DE.moveSlides(sel, Math.min.apply(null, sel) - 1); } },
            { label: 'Ниже', disabled: index >= DE.deck.slides.length - 1, fn: function () { DE.moveSlides(sel, Math.max.apply(null, sel) + 2); } },
            { sep: true },
            { label: sel.length > 1 ? 'Удалить слайды (' + sel.length + ')' : 'Удалить слайд', shortcut: 'Delete', disabled: DE.deck.slides.length <= sel.length, fn: function () { removeSlidesConfirm(sel); } },
        ], x, y);
    }

    function removeSlidesConfirm(sel) {
        var withContent = sel.filter(function (i) { return DE.slide(i).elements.length > 0; }).length;
        if (withContent && !window.confirm(sel.length > 1 ? 'Удалить слайды (' + sel.length + ')? Отменить можно кнопкой «Отменить» (Ctrl+Z).' : 'Удалить слайд ' + (sel[0] + 1) + '? Отменить можно кнопкой «Отменить» (Ctrl+Z).')) { return; }
        DE.removeSlides(sel);
    }

    list.addEventListener('click', function (e) {
        var li = e.target.closest('.de-slide');
        if (!li) { return; }
        var i = +li.getAttribute('data-index');
        if (e.target.closest('[data-slide-menu]')) { var r = e.target.getBoundingClientRect(); slideMenu(i, r.left, r.bottom); return; }
        if (e.shiftKey) {
            var a = Math.min(DE.state.slide, i), b = Math.max(DE.state.slide, i), sel = [];
            for (var k = a; k <= b; k++) { sel.push(k); }
            DE.state.slideSel = sel;
            renderSlides();
            return;
        }
        if (e.ctrlKey || e.metaKey) {
            var pos = DE.state.slideSel.indexOf(i);
            if (pos >= 0 && DE.state.slideSel.length > 1) { DE.state.slideSel.splice(pos, 1); } else if (pos < 0) { DE.state.slideSel.push(i); }
            renderSlides();
            return;
        }
        DE.goto(i);
    });
    list.addEventListener('contextmenu', function (e) {
        var li = e.target.closest('.de-slide');
        if (!li) { return; }
        e.preventDefault();
        slideMenu(+li.getAttribute('data-index'), e.clientX, e.clientY);
    });
    list.addEventListener('keydown', function (e) {
        var i = DE.state.slide, mod = e.ctrlKey || e.metaKey;
        if (e.key === 'ArrowDown' && !mod) { e.preventDefault(); DE.goto(i + 1); }
        else if (e.key === 'ArrowUp' && !mod) { e.preventDefault(); DE.goto(i - 1); }
        else if (e.key === 'ArrowDown' && mod) { e.preventDefault(); DE.moveSlides(DE.state.slideSel, Math.max.apply(null, DE.state.slideSel) + 2); }
        else if (e.key === 'ArrowUp' && mod) { e.preventDefault(); DE.moveSlides(DE.state.slideSel, Math.min.apply(null, DE.state.slideSel) - 1); }
        else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeSlidesConfirm(DE.state.slideSel); }
        else if (mod && (e.key === 'd' || e.key === 'в')) { e.preventDefault(); e.stopPropagation(); DE.duplicateSlides(DE.state.slideSel); }
    });

    var dragFrom = null;
    list.addEventListener('dragstart', function (e) {
        var li = e.target.closest('.de-slide');
        if (!li) { return; }
        dragFrom = +li.getAttribute('data-index');
        if (DE.state.slideSel.indexOf(dragFrom) < 0) { DE.state.slideSel = [dragFrom]; }
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', 'slide'); } catch (err) { /* старый браузер */ }
    });
    list.addEventListener('dragover', function (e) {
        if (dragFrom === null) { return; }
        e.preventDefault();
        var li = e.target.closest('.de-slide');
        list.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (n) { n.classList.remove('is-drop-before', 'is-drop-after'); });
        if (!li) { return; }
        var r = li.getBoundingClientRect();
        li.classList.add(e.clientY < r.top + r.height / 2 ? 'is-drop-before' : 'is-drop-after');
    });
    list.addEventListener('drop', function (e) {
        if (dragFrom === null) { return; }
        e.preventDefault();
        var li = e.target.closest('.de-slide');
        var to = DE.deck.slides.length;
        if (li) { var r = li.getBoundingClientRect(); to = +li.getAttribute('data-index') + (e.clientY < r.top + r.height / 2 ? 0 : 1); }
        dragFrom = null;
        DE.moveSlides(DE.state.slideSel, to);
    });
    list.addEventListener('dragend', function () { dragFrom = null; list.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (n) { n.classList.remove('is-drop-before', 'is-drop-after'); }); });

    // ---- Элементы управления панели «Формат» ------------------------------------------------------

    var fromPanel = false;
    function set(prop, value, key) {
        fromPanel = true;
        DE.setProp(prop, value, { key: key || ('p-' + (typeof prop === 'string' ? prop : 'fn') + DE.state.sel.join()) });
        fromPanel = false;
    }
    function setSlide(fn, key) {
        fromPanel = true;
        DE.change(function (d) { fn(d.slides[DE.state.slide]); }, { key: key, what: 'stage' });
        fromPanel = false;
    }

    function field(label, control, opts) {
        return '<label class="de-field' + (opts && opts.wide ? ' de-field--wide' : '') + '"><span>' + esc(label) + '</span>' + control + '</label>';
    }
    function num(name, value, attrs) {
        return '<input class="de-input" type="number" data-f="' + name + '" value="' + (value === null || value === undefined ? '' : U.round(value, 2)) + '" ' + (attrs || '') + '>';
    }
    function sel(name, options, value) {
        return '<select class="de-input" data-f="' + name + '">' + Object.keys(options).map(function (k) { return '<option value="' + esc(k) + '"' + (String(value) === k ? ' selected' : '') + '>' + esc(options[k]) + '</option>'; }).join('') + '</select>';
    }
    function check(name, value, label) {
        return '<label class="de-check"><input type="checkbox" data-f="' + name + '"' + (value ? ' checked' : '') + '> ' + esc(label) + '</label>';
    }
    function color(name, value, allowNone) {
        return '<button type="button" class="de-color" data-color="' + name + '" data-none="' + (allowNone ? 1 : 0) + '" data-value="' + esc(value || '') + '" data-menu-anchor title="' + esc(value || 'нет') + '"><i style="background:' + (value ? R.color(value) : 'transparent') + '"></i><span>' + (value || 'нет') + '</span></button>';
    }
    function range(name, value, min, max, step) {
        return '<input class="de-range" type="range" data-f="' + name + '" min="' + min + '" max="' + max + '" step="' + step + '" value="' + value + '">';
    }
    function buttons(items) {
        return '<div class="de-buttons">' + items.map(function (b) { return '<button type="button" class="de-btn de-btn--small' + (b.on ? ' is-on' : '') + '" data-do="' + b.act + '" title="' + esc(b.title || b.label) + '"' + (b.disabled ? ' disabled' : '') + '>' + b.label + '</button>'; }).join('') + '</div>';
    }
    function section(title, body, open) {
        return '<details class="de-sec"' + (open === false ? '' : ' open') + '><summary>' + esc(title) + '</summary><div class="de-sec__body">' + body + '</div></details>';
    }

    var ALIGN_ICONS = {
        left: '<svg viewBox="0 0 24 24"><path d="M4 3v18M8 7h10v4H8zM8 13h6v4H8z"/></svg>', center: '<svg viewBox="0 0 24 24"><path d="M12 3v18M6 7h12v4H6zM9 13h6v4H9z"/></svg>',
        right: '<svg viewBox="0 0 24 24"><path d="M20 3v18M6 7h10v4H6zM10 13h6v4h-6z"/></svg>', top: '<svg viewBox="0 0 24 24"><path d="M3 4h18M7 8v10h4V8zM13 8v6h4V8z"/></svg>',
        middle: '<svg viewBox="0 0 24 24"><path d="M3 12h18M7 6v12h4V6zM13 9v6h4V9z"/></svg>', bottom: '<svg viewBox="0 0 24 24"><path d="M3 20h18M7 6v10h4V6zM13 10v6h4v-6z"/></svg>',
    };

    function renderPanel() {
        if (tab !== 'format') { return; }
        var els = DE.selected();
        var html = els.length ? elementPanel(els) : slidePanel();
        panel.innerHTML = html;
        bindPanel(els);
    }

    function slidePanel() {
        var s = DE.slide();
        if (!s) { return ''; }
        var layout = s.layout && cfg.layouts[s.layout] ? cfg.layouts[s.layout].name : 'свой';
        var bg = s.background || {};
        var html = section('Слайд ' + (DE.state.slide + 1), ''
            + '<p class="hints">Макет: ' + esc(layout) + '. Щёлкните элемент на слайде, чтобы изменить его; рамкой — выбрать несколько.</p>'
            + field('Фон', color('bg-color', bg.color || '#FFFFFF', false))
            + field('Картинка фона', '<div class="de-buttons"><button type="button" class="de-btn de-btn--small" data-do="bg-upload">Загрузить…</button><button type="button" class="de-btn de-btn--small" data-do="bg-brand" data-menu-anchor>Фирменная</button>' + (bg.image ? '<button type="button" class="de-btn de-btn--small" data-do="bg-remove">Убрать</button>' : '') + '</div>', { wide: true })
            + (bg.image ? field('Размещение', sel('bg-fit', { cover: 'Заполнить', contain: 'Вписать' }, bg.fit || 'cover')) : '')
            + field('Переход', sel('transition', TRANSITION_NAMES, s.transition || 'none'))
            + check('hidden', s.hidden, 'Скрыть слайд при показе')
            + field('Имя слайда', '<input class="de-input" data-f="slide-name" maxlength="120" value="' + esc(s.name || '') + '" placeholder="Для себя — в PowerPoint и навигации">', { wide: true })
            + buttons([{ act: 'slide-dup', label: 'Дублировать' }, { act: 'slide-hide', label: s.hidden ? 'Показывать' : 'Скрыть' }, { act: 'slide-del', label: 'Удалить', disabled: DE.deck.slides.length < 2 }]));
        html += section(cfg.kind === 'topic' ? 'Тема собрания' : 'Презентация', ''
            + field('Название', '<input class="de-input" data-meta-field="title" maxlength="255" value="' + esc(DE.deck.meta.title || '') + '">', { wide: true })
            + field('Ответственный', '<input class="de-input" data-meta-field="responsible" maxlength="255" value="' + esc(DE.deck.meta.responsible || '') + '" placeholder="' + esc(cfg.authorName) + '">', { wide: true })
            + '<p class="hints">Название и «Ответственный: …» подставляются в шапку слайдов, привязанную к ним' + (cfg.kind === 'topic' ? ', и в «Содержание» презентации собрания.' : '.') + '</p>');
        html += section('Элементы слайда', layersHtml(s), true);
        return html;
    }

    function layersHtml(s) {
        if (!s.elements.length) { return '<p class="hints">На слайде нет элементов — добавьте их кнопками вверху.</p>'; }
        return '<ol class="de-layers">' + s.elements.slice().reverse().map(function (e) {
            var label = e.name || (e.type === 'text' || e.type === 'shape' ? (R.isBlankHtml(e.html) ? (e.placeholder || TYPE_NAMES[e.type]) : e.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substr(0, 40)) : TYPE_NAMES[e.type]);
            return '<li><button type="button" class="de-layers__item" data-layer="' + e.id + '"><span class="de-layers__type">' + esc(TYPE_NAMES[e.type] || e.type) + '</span>' + esc(label) + (e.bind ? ' <span class="tag">привязано</span>' : '') + '</button>'
                + '<button type="button" class="de-layers__lock' + (e.locked ? ' is-on' : '') + '" data-layer-lock="' + e.id + '" title="' + (e.locked ? 'Снять закрепление' : 'Закрепить') + '">' + (e.locked ? '🔒' : '🔓') + '</button></li>';
        }).join('') + '</ol>';
    }

    function common(els) {
        var e = els[0], same = function (p) { return els.every(function (x) { return x[p] === e[p]; }) ? e[p] : null; };
        return { e: e, same: same };
    }

    function elementPanel(els) {
        var c = common(els), e = c.e, single = els.length === 1;
        var kinds = {}; els.forEach(function (x) { kinds[x.type] = true; });
        var types = Object.keys(kinds);
        var title = single ? (TYPE_NAMES[e.type] + (e.type === 'shape' ? ': ' + (SHAPE_NAMES[e.shape] || e.shape) : '')) : 'Выбрано: ' + els.length;
        var grouped = els.some(function (x) { return x.group; });
        var html = '<div class="de-panel__head"><b>' + esc(title) + '</b>' + (e.bind && single ? '<span class="tag" title="Текст подставляется из свойств презентации">' + (e.bind === 'title' ? 'название' : (e.bind === 'responsible' ? 'ответственный' : e.bind)) + '</span>' : '') + '</div>'
            + buttons([{ act: 'dup', label: 'Дублировать', title: 'Дублировать (Ctrl+D)' }, { act: 'lock', label: c.same('locked') ? 'Открепить' : 'Закрепить', on: !!c.same('locked') },
                { act: grouped ? 'ungroup' : 'group', label: grouped ? 'Разгруппировать' : 'Группировать', disabled: !grouped && els.length < 2 }, { act: 'del', label: 'Удалить', title: 'Удалить (Delete)' }]);
        html += section('Расположение', ''
            + '<div class="de-buttons de-buttons--icons">'
            + ['left', 'center', 'right', 'top', 'middle', 'bottom'].map(function (a) { return '<button type="button" class="de-btn de-btn--icon" data-do="align-' + a + '" title="' + { left: 'По левому краю', center: 'По центру', right: 'По правому краю', top: 'По верхнему краю', middle: 'По середине', bottom: 'По нижнему краю' }[a] + (single ? ' слайда' : '') + '">' + ALIGN_ICONS[a] + '</button>'; }).join('')
            + '</div>'
            + (els.length > 2 ? buttons([{ act: 'dist-h', label: 'Распределить по горизонтали' }, { act: 'dist-v', label: 'Распределить по вертикали' }]) : '')
            + buttons([{ act: 'front', label: 'На передний план', title: 'Ctrl+Shift+]' }, { act: 'forward', label: 'Вперёд', title: 'Ctrl+]' }, { act: 'backward', label: 'Назад', title: 'Ctrl+[' }, { act: 'back', label: 'На задний план', title: 'Ctrl+Shift+[' }]));
        if (single) {
            html += section('Положение и размер', e.type === 'line'
                ? '<div class="de-grid4">' + field('X1', num('x1', e.x1)) + field('Y1', num('y1', e.y1)) + field('X2', num('x2', e.x2)) + field('Y2', num('y2', e.y2)) + '</div>'
                : '<div class="de-grid4">' + field('X', num('x', e.x)) + field('Y', num('y', e.y)) + field('Ширина', num('w', e.w, 'min="1"')) + field('Высота', num('h', e.h, 'min="1"')) + '</div>'
                    + field('Поворот, °', num('rotation', e.rotation || 0, 'min="-360" max="360" step="1"')));
        }
        if (types.length === 1) {
            if (e.type === 'text' || e.type === 'shape') { html += textSection(els, c); }
            if (e.type === 'image' && single) { html += imageSection(e); }
            if (e.type === 'line') { html += lineSection(c); }
            if (e.type === 'table' && single) { html += tableSection(e); }
            if (e.type === 'chart' && single) { html += chartSection(e); }
            if (e.type === 'qr' && single) {
                html += section('QR-код', field('Ссылка или текст', '<input class="de-input" data-f="text" maxlength="200" value="' + esc(e.text) + '" placeholder="https://…">', { wide: true })
                    + '<div class="de-grid2">' + field('Цвет', color('color', e.color, false)) + field('Фон', color('bg', e.bg, false)) + '</div>');
            }
        }
        html += section('Вид', field('Прозрачность', range('opacity', c.same('opacity') === null ? 1 : e.opacity, 0.05, 1, 0.05))
            + check('shadow', !!e.shadow, 'Тень')
            + (e.shadow && single ? '<div class="de-grid4">' + field('Размытие', num('shadow.blur', e.shadow.blur, 'min="0" max="200"')) + field('X', num('shadow.x', e.shadow.x)) + field('Y', num('shadow.y', e.shadow.y)) + field('Цвет', color('shadow.color', e.shadow.color, false)) + '</div>' : ''), false);
        if (single) {
            var anim = e.anim || {};
            html += section('Анимация', field('Появление', sel('anim.type', ANIM_NAMES, anim.type || ''))
                + (e.anim ? '<div class="de-grid3">' + field('Порядок', num('anim.order', anim.order || 0, 'min="0" max="99" step="1"')) + field('Задержка, с', num('anim.delay', anim.delay || 0, 'min="0" max="30" step="0.1"')) + field('Длит., с', num('anim.duration', anim.duration || 0.6, 'min="0.1" max="10" step="0.1"')) + '</div>' : '')
                + '<p class="hints">При показе элементы с анимацией появляются по щелчку, по порядку; одинаковый порядок — вместе.</p>', false);
            var linkKind = e.link ? (e.link.url ? 'url' : 'slide') : '';
            var slides = { '': '—' };
            DE.deck.slides.forEach(function (s, i) { if (i !== DE.state.slide) { slides[s.id] = (i + 1) + '. ' + (s.name || slideTitle(s) || 'Слайд'); } });
            html += section('Ссылка', field('При щелчке на показе', sel('link.kind', { '': 'Нет', slide: 'Перейти к слайду', url: 'Открыть адрес' }, linkKind))
                + (linkKind === 'slide' ? field('Слайд', sel('link.slide', slides, e.link.slide || '')) : '')
                + (linkKind === 'url' ? field('Адрес', '<input class="de-input" data-f="link.url" value="' + esc(e.link.url || '') + '" placeholder="https://…">', { wide: true }) : ''), false);
            html += section('Подпись', field('Имя элемента', '<input class="de-input" data-f="name" maxlength="120" value="' + esc(e.name || '') + '">', { wide: true })
                + (e.type === 'image' ? field('Текст для незрячих (alt)', '<input class="de-input" data-f="alt" maxlength="500" value="' + esc(e.alt || '') + '">', { wide: true }) : ''), false);
        }
        return html;
    }

    function slideTitle(s) {
        var t = s.elements.filter(function (e) { return (e.type === 'text') && !R.isBlankHtml(e.html) && (e.bind === 'title' || e.fontSize >= 28 || e.name === 'Заголовок'); })[0];
        return t ? t.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substr(0, 50) : '';
    }

    function textSection(els, c) {
        var e = c.e, isShape = e.type === 'shape';
        var weights = { 300: 'Тонкий (300)', 400: 'Обычный (400)', 500: 'Средний (500)', 700: 'Жирный (700)' };
        var fonts = {}; cfg.fonts.forEach(function (f) { fonts[f] = f + (CORPORATE_FONTS.indexOf(f) < 0 ? ' — не фирменный' : ''); });
        var html = section('Текст', ''
            + field('Шрифт', sel('fontFamily', fonts, c.same('fontFamily') || ''), { wide: true })
            + '<div class="de-grid3">' + field('Размер, пт', num('fontSize', c.same('fontSize'), 'min="4" max="400" step="1"')) + field('Начертание', sel('fontWeight', weights, c.same('fontWeight'))) + field('Цвет', color('color', c.same('color'), false)) + '</div>'
            + '<div class="de-grid3">' + field('Интервал', num('lineHeight', c.same('lineHeight'), 'min="0.5" max="4" step="0.05"')) + field('Трекинг, пт', num('letterSpacing', c.same('letterSpacing'), 'min="-20" max="100" step="0.5"')) + field('Перед абзацем', num('spaceBefore', c.same('spaceBefore'), 'min="0" max="200" step="1"')) + '</div>'
            + '<div class="de-grid3">' + field('По горизонтали', sel('align', { left: 'Слева', center: 'По центру', right: 'Справа', justify: 'По ширине' }, c.same('align'))) + field('По вертикали', sel('valign', { top: 'Сверху', middle: 'Посередине', bottom: 'Снизу' }, c.same('valign'))) + field('Поля', num('padding', c.same('padding'), 'min="0" max="200"')) + '</div>'
            + check('italic', !!c.same('italic'), 'Курсив') + check('caps', !!c.same('caps'), 'Все прописные') + check('autoFit', !!c.same('autoFit'), 'Уменьшать шрифт, если текст не помещается')
            + field('Цвет маркеров списка', color('listColor', c.same('listColor') || '#104382', true))
            + (els.length === 1 ? field('Подсказка в пустой надписи', '<input class="de-input" data-f="placeholder" maxlength="200" value="' + esc(e.placeholder || '') + '">', { wide: true }) : ''));
        var shapes = {}; SHAPE_ORDER.forEach(function (s) { if (cfg.shapes.indexOf(s) >= 0) { shapes[s] = SHAPE_NAMES[s] || s; } });
        html += section(isShape ? 'Фигура' : 'Заливка и рамка', ''
            + (isShape && els.length === 1 ? field('Вид', sel('shape', shapes, e.shape), { wide: true }) : '')
            + '<div class="de-grid2">' + field('Заливка', color('fill', c.same('fill'), true)) + field('Рамка', color('stroke', c.same('stroke'), true)) + '</div>'
            + '<div class="de-grid3">' + field('Толщина рамки', num('strokeWidth', c.same('strokeWidth'), 'min="0" max="50" step="0.5"')) + field('Линия', sel('dash', { solid: 'Сплошная', dash: 'Штрих', dot: 'Точки' }, c.same('dash'))) + field('Скругление', num('radius', c.same('radius'), 'min="0" max="2000"')) + '</div>'
            + check('gradient', !!e.gradient, 'Градиент')
            + (e.gradient && els.length === 1 ? '<div class="de-grid3">' + field('От', color('gradient.from', e.gradient.from, false)) + field('До', color('gradient.to', e.gradient.to, false)) + field('Угол', num('gradient.angle', e.gradient.angle, 'min="0" max="360"')) + '</div>' : ''), isShape);
        return html;
    }

    function imageSection(e) {
        return section('Изображение', ''
            + '<div class="de-buttons"><button type="button" class="de-btn de-btn--small" data-do="img-upload">' + (e.src ? 'Заменить…' : 'Выбрать файл…') + '</button><button type="button" class="de-btn de-btn--small" data-do="img-brand" data-menu-anchor>Фирменное</button>'
            + (e.src ? '<button type="button" class="de-btn de-btn--small" data-do="img-clear" title="Оставить место для изображения">Убрать</button>' : '') + '</div>'
            + field('Размещение', sel('fit', { cover: 'Заполнить (обрезать)', contain: 'Вписать целиком', fill: 'Растянуть' }, e.fit))
            + (e.fit !== 'fill' ? field('Масштаб', range('zoom', e.zoom || 1, 1, 5, 0.05)) + field('Кадр по горизонтали', range('focusX', e.focusX, 0, 1, 0.01)) + field('Кадр по вертикали', range('focusY', e.focusY, 0, 1, 0.01)) : '')
            + '<div class="de-grid2">' + field('Скругление', num('radius', e.radius, 'min="0" max="2000"')) + field('Форма', sel('mask', { none: 'Как есть', circle: 'Круг' }, e.mask)) + '</div>'
            + '<div class="de-grid2">' + field('Рамка', color('border.color', e.border ? e.border.color : null, true)) + field('Толщина', num('border.width', e.border ? e.border.width : 0, 'min="0" max="50"')) + '</div>'
            + check('flipH', e.flipH, 'Отразить по горизонтали') + check('flipV', e.flipV, 'Отразить по вертикали')
            + buttons([{ act: 'img-reset', label: 'Сбросить кадрирование' }, { act: 'img-natural', label: 'Пропорции картинки' }]));
    }

    function lineSection(c) {
        return section('Линия', '<div class="de-grid3">' + field('Цвет', color('stroke', c.same('stroke'), false)) + field('Толщина', num('strokeWidth', c.same('strokeWidth'), 'min="0.25" max="50" step="0.25"')) + field('Вид', sel('dash', { solid: 'Сплошная', dash: 'Штрих', dot: 'Точки' }, c.same('dash'))) + '</div>'
            + '<div class="de-grid2">' + field('Начало', sel('start', ARROW_NAMES, c.same('start'))) + field('Конец', sel('end', ARROW_NAMES, c.same('end'))) + '</div>'
            + buttons([{ act: 'line-swap', label: 'Поменять концы' }]));
    }

    function tableSection(e) {
        var st = e.style;
        return section('Таблица', ''
            + '<p class="hints">Дважды щёлкните таблицу, чтобы вводить текст в ячейки (Tab — следующая ячейка).</p>'
            + buttons([{ act: 'tbl-row-add', label: '+ Строка' }, { act: 'tbl-row-del', label: '− Строка', disabled: e.rows.length < 2 }, { act: 'tbl-col-add', label: '+ Столбец' }, { act: 'tbl-col-del', label: '− Столбец', disabled: e.cols.length < 2 }, { act: 'tbl-even', label: 'Столбцы поровну' }])
            + check('style.header', st.header, 'Строка заголовков') + check('style.firstCol', st.firstCol, 'Первый столбец') + check('style.banded', st.banded, 'Чередовать строки')
            + '<div class="de-grid2">' + field('Заголовки: фон', color('style.headerFill', st.headerFill, false)) + field('Заголовки: текст', color('style.headerColor', st.headerColor, false)) + '</div>'
            + '<div class="de-grid2">' + field('Фон ячеек', color('style.fill', st.fill, true)) + field('Чередование', color('style.bandFill', st.bandFill, false)) + '</div>'
            + '<div class="de-grid2">' + field('Границы', color('style.borderColor', st.borderColor, false)) + field('Толщина', num('style.borderWidth', st.borderWidth, 'min="0" max="10" step="0.5"')) + '</div>'
            + '<div class="de-grid3">' + field('Размер, пт', num('style.fontSize', st.fontSize, 'min="4" max="120"')) + field('Цвет текста', color('style.color', st.color, false)) + field('Выравнивание', sel('style.align', { left: 'Слева', center: 'По центру', right: 'Справа' }, st.align)) + '</div>'
            + field('Поля ячеек', num('style.padding', st.padding, 'min="0" max="60"')));
    }

    function chartSection(e) {
        var html = section('Диаграмма', ''
            + field('Вид', sel('chart', CHART_NAMES, e.chart))
            + buttons([{ act: 'chart-data', label: 'Данные…' }])
            + field('Заголовок', '<input class="de-input" data-f="title" maxlength="200" value="' + esc(e.title || '') + '">', { wide: true })
            + '<div class="de-grid2">' + field('Легенда', sel('legend', { none: 'Нет', top: 'Сверху', bottom: 'Снизу', right: 'Справа' }, e.legend)) + field('Размер текста, пт', num('fontSize', e.fontSize, 'min="6" max="48"')) + '</div>'
            + check('labels', e.labels, 'Подписи значений') + (e.chart === 'pie' || e.chart === 'doughnut' || e.chart === 'line' ? '' : check('stacked', e.stacked, 'Накопление'))
            + '<div class="de-grid2">' + field('Цвет текста', color('color', e.color, false)) + field('Сетка', color('gridColor', e.gridColor, false)) + '</div>'
            + '<div class="de-sec__sub">Цвета рядов</div>'
            + e.series.map(function (s, i) { return field(s.name || ('Ряд ' + (i + 1)), color('series.' + i, s.color || cfg.chartColors[i % cfg.chartColors.length], false)); }).join(''));
        return html;
    }

    // Привязка элементов панели: data-f — свойство (с точкой — вложенное), data-color — выбор цвета, data-do — кнопки.
    function bindPanel(els) {
        var e = els[0];
        panel.querySelectorAll('[data-f]').forEach(function (n) {
            var f = n.getAttribute('data-f');
            var evt = n.type === 'checkbox' || n.tagName === 'SELECT' ? 'change' : 'input';
            n.addEventListener(evt, function () {
                var v = n.type === 'checkbox' ? n.checked : (n.type === 'number' || n.type === 'range' ? U.num(n.value, null) : n.value);
                if (v === null && (n.type === 'number' || n.type === 'range')) { return; }
                applyField(f, v, els);
            });
            if (n.type === 'number') { n.addEventListener('change', function () { renderPanel(); }); }
        });
        panel.querySelectorAll('[data-color]').forEach(function (b) {
            b.addEventListener('click', function () {
                colorPicker(b, b.getAttribute('data-value'), b.getAttribute('data-none') === '1', function (c) { applyField(b.getAttribute('data-color'), c, els, true); renderPanel(); });
            });
        });
        panel.querySelectorAll('[data-do]').forEach(function (b) { b.addEventListener('click', function () { panelAction(b.getAttribute('data-do'), b, e); }); });
        panel.querySelectorAll('[data-meta-field]').forEach(function (n) {
            n.addEventListener('input', function () { fromPanel = true; DE.setMeta(n.getAttribute('data-meta-field'), n.value); fromPanel = false; if (n.getAttribute('data-meta-field') === 'title') { root.querySelector('[data-meta="title"]').value = n.value; } });
        });
        panel.querySelectorAll('[data-layer]').forEach(function (b) {
            b.addEventListener('click', function () { DE.select([b.getAttribute('data-layer')], { single: true }); });
        });
        panel.querySelectorAll('[data-layer-lock]').forEach(function (b) {
            b.addEventListener('click', function () { var id = b.getAttribute('data-layer-lock'); var el = DE.el(id); DE.setProp('locked', !el.locked, { ids: [id] }); renderPanel(); });
        });
    }

    function applyField(f, v, els, now) {
        var key = 'f-' + f + DE.state.sel.join();
        // Свойства слайда.
        if (f === 'bg-color') { setSlide(function (s) { s.background.color = v; }, key); return; }
        if (f === 'bg-fit') { setSlide(function (s) { s.background.fit = v; }, key); return; }
        if (f === 'transition') { setSlide(function (s) { s.transition = v; }, key); return; }
        if (f === 'hidden') { setSlide(function (s) { s.hidden = v; }, key); renderSlides(); return; }
        if (f === 'slide-name') { setSlide(function (s) { s.name = v || null; }, key); return; }
        var reRender = /^(anim\.type|link\.kind|shadow|gradient|fit|chart|shape|mask)$/.test(f);
        set(function (e) {
            switch (f) {
                case 'shadow': e.shadow = v ? { blur: 16, x: 0, y: 4, color: '#0F438240' } : null; break;
                case 'gradient': e.gradient = v ? { angle: 180, from: e.fill || DE.BLUE, to: '#79A7C6' } : null; break;
                case 'anim.type': e.anim = v ? Object.assign({ order: 0, delay: 0, duration: 0.6 }, e.anim || {}, { type: v }) : null; break;
                case 'link.kind': e.link = v === 'url' ? { url: 'https://' } : (v === 'slide' ? { slide: '' } : null); break;
                case 'link.slide': e.link = v ? { slide: v } : { slide: '' }; break;
                case 'link.url': e.link = { url: v }; break;
                case 'border.color': e.border = v ? { color: v, width: (e.border && e.border.width) || 2 } : null; break;
                case 'border.width': e.border = v > 0 ? { color: (e.border && e.border.color) || DE.BLUE, width: v } : null; break;
                case 'x1': case 'y1': case 'x2': case 'y2':
                    e[f] = v; e.x = Math.min(e.x1, e.x2); e.y = Math.min(e.y1, e.y2); e.w = Math.max(1, Math.abs(e.x2 - e.x1)); e.h = Math.max(1, Math.abs(e.y2 - e.y1));
                    break;
                case 'fontWeight': e.fontWeight = parseInt(v, 10); break;
                default:
                    if (/^series\.\d+$/.test(f)) { var si = +f.split('.')[1]; if (e.series && e.series[si]) { e.series[si].color = v; } break; }
                    var parts = f.split('.');
                    if (parts.length === 2) { if (e[parts[0]]) { e[parts[0]][parts[1]] = v; } } else { e[f] = v; }
            }
            if (e.type === 'qr' && (f === 'w' || f === 'h')) { e.w = e.h = v; }
        }, null, now ? 'now' + Date.now() : key);
        if (reRender) { renderPanel(); }
    }

    function panelAction(a, b, e) {
        var els = DE.selected();
        switch (a) {
            case 'dup': DE.duplicateSelected(); break;
            case 'del': DE.removeSelected(); break;
            case 'lock': var lock = !els.every(function (x) { return x.locked; }); DE.setProp('locked', lock); if (lock) { DE.toast('Закреплено: элемент не двигается и не меняется на холсте.'); } renderPanel(); break;
            case 'group': DE.group(); renderPanel(); break;
            case 'ungroup': DE.ungroup(); renderPanel(); break;
            case 'front': case 'forward': case 'backward': case 'back': DE.order(a); break;
            case 'dist-h': DE.distribute('h'); break;
            case 'dist-v': DE.distribute('v'); break;
            case 'bg-upload': pickImage('background'); break;
            case 'bg-brand': menuAt(b, brandItems(function (key) { setSlide(function (s) { s.background.image = 'brand:' + key; }, 'bg'); renderPanel(); })); break;
            case 'bg-remove': setSlide(function (s) { s.background.image = null; }, 'bg'); renderPanel(); break;
            case 'slide-dup': DE.duplicateSlides([DE.state.slide]); break;
            case 'slide-hide': DE.toggleHidden([DE.state.slide]); renderPanel(); break;
            case 'slide-del': removeSlidesConfirm([DE.state.slide]); break;
            case 'img-upload': pickImage(e); break;
            case 'img-brand': menuAt(b, brandItems(function (key) { insertBrand(key, e); })); break;
            case 'img-clear': DE.setProp('src', null); renderPanel(); break;
            case 'img-reset': DE.setProp(function (x) { x.zoom = 1; x.focusX = 0.5; x.focusY = 0.5; }); renderPanel(); break;
            case 'img-natural':
                var img = DE.nodeOf(e.id) && DE.nodeOf(e.id).querySelector('img');
                var nw = img && img.naturalWidth, nh = img && img.naturalHeight;
                if (e.src && e.src.indexOf('brand:') === 0) { nw = cfg.brand[e.src.substr(6)].width; nh = cfg.brand[e.src.substr(6)].height; }
                if (nw && nh) { DE.setProp(function (x) { x.h = U.round(x.w * nh / nw); }); renderPanel(); }
                break;
            case 'line-swap': DE.setProp(function (x) { var t = [x.x1, x.y1, x.start]; x.x1 = x.x2; x.y1 = x.y2; x.start = x.end; x.x2 = t[0]; x.y2 = t[1]; x.end = t[2]; }); renderPanel(); break;
            case 'tbl-row-add': DE.setProp(function (x) { x.rows.push(x.rows[0].map(function () { return emptyCell(); })); x.heights.push(1); x.h = U.round(x.h + x.h / Math.max(1, x.rows.length - 1)); }); renderPanel(); break;
            case 'tbl-row-del': DE.setProp(function (x) { if (x.rows.length > 1) { x.rows.pop(); x.heights.pop(); x.h = U.round(x.h * x.rows.length / (x.rows.length + 1)); } }); renderPanel(); break;
            case 'tbl-col-add': DE.setProp(function (x) { x.rows.forEach(function (r, i) { r.push(emptyCell(i === 0 && x.style.header ? 'Заголовок' : '')); }); x.cols.push(1); }); renderPanel(); break;
            case 'tbl-col-del': DE.setProp(function (x) { if (x.cols.length > 1) { x.rows.forEach(function (r) { r.pop(); }); x.cols.pop(); } }); renderPanel(); break;
            case 'tbl-even': DE.setProp(function (x) { x.cols = x.cols.map(function () { return 1; }); }); break;
            case 'chart-data': chartDialog(e); break;
            default:
                if (a.indexOf('align-') === 0) { DE.align(a.substr(6)); }
        }
    }

    function emptyCell(text) { return { text: text || '', bold: false, italic: false, color: null, fill: null, align: null, colspan: 1, rowspan: 1, hidden: false }; }

    // Во время перетаскивания обновляются только поля положения.
    DE.on('geometry', function () {
        var e = DE.selected()[0];
        if (!e || tab !== 'format') { return; }
        ['x', 'y', 'w', 'h', 'rotation', 'x1', 'y1', 'x2', 'y2'].forEach(function (f) {
            var n = panel.querySelector('[data-f="' + f + '"]');
            if (n && document.activeElement !== n) { n.value = U.round(e[f] || 0, 2); }
        });
        updateInfo();
    });

    // ---- Данные диаграммы ------------------------------------------------------------------------

    function chartDialog(e) {
        var grid = [['', ].concat(e.series.map(function (s) { return s.name; }))];
        e.categories.forEach(function (c, i) { grid.push([c].concat(e.series.map(function (s) { var v = s.values[i]; return v === null || v === undefined ? '' : String(v).replace('.', ','); }))); });
        var draw = function (body) {
            var t = '<table class="de-datagrid"><tbody>' + grid.map(function (row, r) {
                return '<tr>' + row.map(function (cell, c) {
                    var ph = r === 0 && c === 0 ? '' : (r === 0 ? 'Ряд' : (c === 0 ? 'Категория' : '0'));
                    return '<td><input class="de-input" data-r="' + r + '" data-c="' + c + '" value="' + esc(cell) + '" placeholder="' + ph + '"' + (r === 0 && c === 0 ? ' disabled' : '') + '></td>';
                }).join('') + '</tr>';
            }).join('') + '</tbody></table>';
            body.querySelector('[data-grid]').innerHTML = t;
            body.querySelectorAll('[data-r]').forEach(function (inp) { inp.addEventListener('input', function () { grid[+inp.getAttribute('data-r')][+inp.getAttribute('data-c')] = inp.value; }); });
        };
        openDialog('<h2 class="h-section">Данные диаграммы</h2><p class="hints">Первый столбец — категории, первая строка — названия рядов. Можно вставить таблицу из Excel в поле ниже.</p>'
            + '<div class="de-datagrid-wrap" data-grid></div>'
            + '<div class="de-buttons"><button type="button" class="de-btn de-btn--small" data-g="row+">+ Категория</button><button type="button" class="de-btn de-btn--small" data-g="row-">− Категория</button><button type="button" class="de-btn de-btn--small" data-g="col+">+ Ряд</button><button type="button" class="de-btn de-btn--small" data-g="col-">− Ряд</button></div>'
            + '<details><summary>Вставить из Excel</summary><textarea class="form-control" rows="5" data-paste placeholder="Скопируйте ячейки в Excel (вместе с заголовками) и вставьте сюда"></textarea><button type="button" class="de-btn de-btn--small" data-g="paste">Применить вставку</button></details>'
            + '<div class="form-actions"><button type="button" class="btn btn--pill" data-go>Готово</button><button type="button" class="btn btn--ghost" data-close>Отмена</button></div>',
        function (body) {
            draw(body);
            body.querySelectorAll('[data-g]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var g = b.getAttribute('data-g');
                    if (g === 'row+') { grid.push(grid[0].map(function () { return ''; })); }
                    else if (g === 'row-' && grid.length > 2) { grid.pop(); }
                    else if (g === 'col+' && grid[0].length < 13) { grid.forEach(function (r, i) { r.push(i === 0 ? 'Ряд ' + r.length : ''); }); }
                    else if (g === 'col-' && grid[0].length > 2) { grid.forEach(function (r) { r.pop(); }); }
                    else if (g === 'paste') {
                        var rows = body.querySelector('[data-paste]').value.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; }).map(function (l) { return l.split('\t'); });
                        if (rows.length < 2) { DE.toast('Вставьте хотя бы строку заголовков и одну строку данных.', 'error'); return; }
                        var width = Math.min(13, Math.max.apply(null, rows.map(function (r) { return r.length; })));
                        grid = rows.slice(0, 101).map(function (r) { var out = r.slice(0, width); while (out.length < width) { out.push(''); } return out; });
                        grid[0][0] = '';
                    }
                    draw(body);
                });
            });
            body.querySelector('[data-go]').addEventListener('click', function () {
                var cats = grid.slice(1).map(function (r) { return r[0]; });
                var series = grid[0].slice(1).map(function (name, si) {
                    var old = e.series[si] || {};
                    return { name: name || ('Ряд ' + (si + 1)), color: old.color || null, values: grid.slice(1).map(function (r) { var v = String(r[si + 1] || '').replace(/\s/g, '').replace(',', '.'); return v === '' || !isFinite(+v) ? null : +v; }) };
                });
                DE.setProp(function (x) { x.categories = cats; x.series = series; }, null, { ids: [e.id], key: 'chart' + Date.now() });
                dialog.close();
            });
        }, { wide: true });
    }
    DE.on('chart-data', chartDialog);

    // ---- Замечания -------------------------------------------------------------------------------

    function loadComments() {
        if (!cfg.canComment) { return Promise.resolve(); }
        return DE.api(cfg.urls.comments).then(function (res) { comments = res.comments || []; afterComments(); }).catch(function () { /* без замечаний */ });
    }

    function afterComments() {
        var open = comments.filter(function (c) { return !c.parentId && !c.resolved; }).length;
        root.querySelector('[data-comments-count]').textContent = open ? String(open) : '';
        if (tab === 'comments') { renderComments(); }
        renderSlides();
        drawPins();
    }

    var commentFilter = 'slide';
    function renderComments() {
        var slide = DE.slide();
        var threads = comments.filter(function (c) { return !c.parentId; });
        if (commentFilter === 'slide') { threads = threads.filter(function (c) { return slide && c.slideId === slide.id; }); }
        if (commentFilter === 'open') { threads = threads.filter(function (c) { return !c.resolved; }); }
        var el = DE.selected()[0];
        var html = '<div class="de-comments">'
            + '<div class="de-buttons de-buttons--tabs">' + [['slide', 'Этот слайд'], ['open', 'Открытые'], ['all', 'Все']].map(function (f) { return '<button type="button" class="de-btn de-btn--small' + (commentFilter === f[0] ? ' is-on' : '') + '" data-cf="' + f[0] + '">' + f[1] + '</button>'; }).join('') + '</div>'
            + '<form class="de-comments__new" data-cnew><textarea class="de-input" rows="3" maxlength="4000" placeholder="Замечание к ' + (el ? 'выбранному элементу' : 'слайду ' + (DE.state.slide + 1)) + '…" required></textarea>'
            + '<button type="submit" class="de-btn de-btn--small de-btn--primary">Добавить замечание</button></form>';
        if (!threads.length) { html += '<p class="hints">' + (commentFilter === 'slide' ? 'К этому слайду замечаний нет.' : 'Замечаний нет.') + '</p>'; }
        threads.forEach(function (t) {
            var idx = DE.deck.slides.findIndex(function (s) { return s.id === t.slideId; });
            var replies = comments.filter(function (c) { return c.parentId === t.id; });
            html += '<div class="de-thread' + (t.resolved ? ' is-resolved' : '') + '" data-thread="' + t.id + '">'
                + '<div class="de-thread__meta">' + (idx >= 0 ? '<button type="button" class="de-link" data-cgo="' + idx + '" data-cel="' + esc(t.elementId || '') + '">Слайд ' + (idx + 1) + (t.elementId ? ', элемент' : '') + '</button>' : (t.slideId ? 'слайд удалён' : 'вся презентация')) + ' · ' + esc(t.author) + ' · ' + new Date(t.at).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) + (t.resolved ? ' · решено' + (t.resolvedBy ? ' (' + esc(t.resolvedBy) + ')' : '') : '') + '</div>'
                + '<div class="de-thread__text">' + esc(t.text) + '</div>'
                + replies.map(function (r) { return '<div class="de-thread__reply"><b>' + esc(r.author) + ':</b> ' + esc(r.text) + (r.mine || cfg.isModerator ? ' <button type="button" class="de-link" data-cdel="' + r.id + '">удалить</button>' : '') + '</div>'; }).join('')
                + '<div class="de-thread__actions"><button type="button" class="de-link" data-creply="' + t.id + '">Ответить</button>'
                + '<button type="button" class="de-link" data-cres="' + t.id + '">' + (t.resolved ? 'Открыть снова' : 'Решено') + '</button>'
                + (t.mine || cfg.isModerator ? '<button type="button" class="de-link" data-cdel="' + t.id + '">Удалить</button>' : '') + '</div></div>';
        });
        html += '</div>';
        panel.innerHTML = html;
        panel.querySelectorAll('[data-cf]').forEach(function (b) { b.addEventListener('click', function () { commentFilter = b.getAttribute('data-cf'); renderComments(); }); });
        panel.querySelector('[data-cnew]').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var text = ev.target.querySelector('textarea').value.trim();
            if (!text) { return; }
            DE.api(cfg.urls.comments, { json: { text: text, slideId: slide ? slide.id : null, elementId: el ? el.id : null } })
                .then(function (res) { comments = res.comments; afterComments(); DE.toast('Замечание добавлено — автор увидит его в редакторе.'); })
                .catch(function (err) { DE.toast(err.message, 'error'); });
        });
        panel.querySelectorAll('[data-cgo]').forEach(function (b) { b.addEventListener('click', function () { DE.goto(+b.getAttribute('data-cgo')); var id = b.getAttribute('data-cel'); if (id && DE.el(id)) { DE.select([id], { single: true }); } }); });
        panel.querySelectorAll('[data-creply]').forEach(function (b) {
            b.addEventListener('click', function () {
                var text = window.prompt('Ответ:');
                if (!text || !text.trim()) { return; }
                DE.api(cfg.urls.comments, { json: { text: text.trim(), parentId: +b.getAttribute('data-creply') } }).then(function (res) { comments = res.comments; afterComments(); }).catch(function (err) { DE.toast(err.message, 'error'); });
            });
        });
        panel.querySelectorAll('[data-cres]').forEach(function (b) {
            b.addEventListener('click', function () {
                var id = +b.getAttribute('data-cres'), c = comments.filter(function (x) { return x.id === id; })[0];
                commentAction(id, c && c.resolved ? 'reopen' : 'resolve');
            });
        });
        panel.querySelectorAll('[data-cdel]').forEach(function (b) { b.addEventListener('click', function () { if (window.confirm('Удалить замечание?')) { commentAction(+b.getAttribute('data-cdel'), 'delete'); } }); });
    }

    function commentAction(id, action) {
        DE.api(cfg.urls.commentBase.replace(/0$/, String(id)), { json: { action: action } }).then(function (res) { comments = res.comments; afterComments(); }).catch(function (err) { DE.toast(err.message, 'error'); });
    }

    // Значки замечаний на элементах слайда.
    function drawPins() {
        var overlay = root.querySelector('[data-overlay]');
        overlay.querySelectorAll('.de-pin').forEach(function (n) { n.remove(); });
        var slide = DE.slide();
        if (!slide || !DE.scale) { return; }
        var byEl = {};
        comments.forEach(function (c) { if (!c.parentId && !c.resolved && c.slideId === slide.id && c.elementId) { byEl[c.elementId] = (byEl[c.elementId] || 0) + 1; } });
        Object.keys(byEl).forEach(function (id) {
            var e = DE.el(id);
            if (!e) { return; }
            var b = DE.box(e);
            var pin = U.h('<button type="button" class="de-pin" title="Замечания: ' + byEl[id] + '">' + byEl[id] + '</button>');
            pin.style.left = ((b.x + b.w) * DE.scale - 10) + 'px'; pin.style.top = (b.y * DE.scale - 10) + 'px';
            pin.addEventListener('click', function () { DE.select([id], { single: true }); setTab('comments'); });
            overlay.appendChild(pin);
        });
    }
    DE.on('overlay', drawPins);

    // ---- История версий --------------------------------------------------------------------------

    function renderVersions() {
        panel.innerHTML = '<p class="hints">Портал сохраняет версии сам: при правке — не реже раза в 10 минут, при отправке на модерацию, решении, импорте и восстановлении.</p><div data-vlist>Загружаю…</div>';
        DE.api(cfg.urls.versions).then(function (res) {
            var box = panel.querySelector('[data-vlist]');
            if (!box) { return; }
            if (!res.versions.length) { box.innerHTML = '<p class="hints">Версий пока нет.</p>'; return; }
            box.innerHTML = '<ol class="de-versions">' + res.versions.map(function (v) {
                return '<li><div><b>' + esc(v.label) + '</b><div class="hints">' + new Date(v.at).toLocaleString('ru-RU') + (v.by ? ' · ' + esc(v.by) : '') + ' · ' + U.plural(v.slides, ['слайд', 'слайда', 'слайдов']) + '</div></div>'
                    + '<div class="de-buttons"><button type="button" class="de-btn de-btn--small" data-vshow="' + v.id + '">Посмотреть</button><button type="button" class="de-btn de-btn--small" data-vrestore="' + v.id + '">Восстановить</button></div></li>';
            }).join('') + '</ol>';
            box.querySelectorAll('[data-vshow]').forEach(function (b) { b.addEventListener('click', function () { showVersion(+b.getAttribute('data-vshow')); }); });
            box.querySelectorAll('[data-vrestore]').forEach(function (b) { b.addEventListener('click', function () { restoreVersion(+b.getAttribute('data-vrestore')); }); });
        }).catch(function (err) { panel.querySelector('[data-vlist]').textContent = err.message; });
    }

    function showVersion(id) {
        DE.api(cfg.urls.version.replace(/0$/, String(id))).then(function (res) {
            var slides = (res.deck && res.deck.slides) || [];
            openDialog('<h2 class="h-section">Версия: ' + U.plural(slides.length, ['слайд', 'слайда', 'слайдов']) + '</h2><div class="de-version-grid">' + slides.map(function (s, i) { return '<figure><div data-vthumb="' + i + '"></div><figcaption>' + (i + 1) + '</figcaption></figure>'; }).join('') + '</div>'
                + '<div class="form-actions"><button type="button" class="btn btn--pill" data-vr>Восстановить эту версию</button><button type="button" class="btn btn--ghost" data-close>Закрыть</button></div>', function (body) {
                body.querySelectorAll('[data-vthumb]').forEach(function (box) { R.mount(box, slides[+box.getAttribute('data-vthumb')], { mode: 'thumb' }); });
                body.querySelector('[data-vr]').addEventListener('click', function () { restoreVersion(id); });
            }, { wide: true });
        }).catch(function (err) { DE.toast(err.message, 'error'); });
    }

    function restoreVersion(id) {
        if (!window.confirm('Восстановить эту версию? Текущие слайды сохранятся в истории — к ним можно вернуться.')) { return; }
        DE.save(true).then(function () { postForm(cfg.urls.restore.replace('/0/', '/' + id + '/'), {}); });
    }

    // ---- Проверка корпоративного стиля -------------------------------------------------------------

    var palette = cfg.palette.map(function (p) { return p[0].toUpperCase(); });
    function runChecks() {
        var out = [];
        var add = function (level, slideIndex, el, text, fix) { out.push({ level: level, slide: slideIndex, el: el ? el.id : null, text: text, fix: fix }); };
        var probeBox = document.createElement('div');
        probeBox.style.cssText = 'position:fixed;left:-20000px;top:0;width:1280px;height:720px;visibility:hidden;';
        document.body.appendChild(probeBox);
        DE.deck.slides.forEach(function (s, si) {
            var words = 0;
            s.elements.forEach(function (e) {
                var b = DE.box(e);
                if (b.x < -2 || b.y < -2 || b.x + b.w > R.W + 2 || b.y + b.h > R.H + 2) {
                    add('warn', si, e, (TYPE_NAMES[e.type] || 'Элемент') + ' выходит за край слайда.', function () { DE.change(function () { var x = DE.el(e.id, DE.slide(si)); if (x.type !== 'line') { x.x = U.clamp(x.x, 0, Math.max(0, R.W - x.w)); x.y = U.clamp(x.y, 0, Math.max(0, R.H - x.h)); } }); });
                }
                if (e.type === 'text' || e.type === 'shape') {
                    if (CORPORATE_FONTS.indexOf(e.fontFamily) < 0) { add('warn', si, e, 'Шрифт «' + e.fontFamily + '» не фирменный — используйте Roboto для текста и DIN Condensed для заголовков.', function () { DE.change(function () { DE.el(e.id, DE.slide(si)).fontFamily = 'Roboto'; }); }); }
                    var fonts = (e.html || '').match(/font-family:\s*'?([^;'"]+)/g) || [];
                    fonts.forEach(function (f) { var name = f.replace(/font-family:\s*'?/, ''); if (CORPORATE_FONTS.indexOf(name) < 0) { add('warn', si, e, 'Во фрагменте текста шрифт «' + name + '» — он не фирменный.'); } });
                    if (!R.isBlankHtml(e.html) && e.fontSize < 10) { add('warn', si, e, 'Мелкий текст (' + e.fontSize + ' пт) — с последних рядов не прочитать; лучше от 12 пт.'); }
                    if (R.isBlankHtml(e.html) && e.placeholder && !e.fill && !e.bind) { add('info', si, e, 'Пустая надпись «' + e.placeholder + '» — заполните или удалите.', function () { setTimeout(function () { DE.startEditing(e.id); }, 50); }); }
                    words += (e.html || '').replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;
                    [e.color, e.fill].forEach(function (c) { if (c && palette.indexOf(c.substr(0, 7).toUpperCase()) < 0 && c.length === 7) { add('info', si, e, 'Цвет ' + c + ' не из фирменной палитры.'); } });
                    if (!R.isBlankHtml(e.html)) {
                        var node = R.element(Object.assign({}, e, { x: 0, y: 0, rotation: 0 }), { mode: 'view' });
                        probeBox.appendChild(node);
                        var body = node.querySelector('.deck-text__body');
                        // Допуск — треть строки: высота строки шрифта больше видимых букв.
                        if (body && body.scrollHeight > e.h - 2 * (e.padding || 0) + Math.max(6, e.fontSize * 4 / 3 * 0.4)) { add('warn', si, e, 'Текст не помещается в рамку — увеличьте её или уменьшите текст.'); }
                        node.remove();
                    }
                }
                if (e.type === 'image' && !e.src) { add('warn', si, e, 'Пустое место для изображения — выберите картинку или удалите место.'); }
                if (e.type === 'qr' && !e.text) { add('warn', si, e, 'QR-код без ссылки.'); }
                if (e.type === 'chart' && !e.series.some(function (sr) { return sr.values.some(function (v) { return v !== null; }); })) { add('warn', si, e, 'В диаграмме нет данных.'); }
            });
            if (words > 130) { add('info', si, null, 'На слайде ' + words + ' слов — для слайда это много; часть можно перенести в заметки докладчика.'); }
            if (!s.elements.length) { add('info', si, null, 'Пустой слайд.'); }
        });
        probeBox.remove();
        if (cfg.kind !== 'topic' && DE.deck.slides.length > 1) {
            if (DE.deck.slides[0].layout !== 'cover') { add('info', 0, null, 'Презентация обычно начинается с титульного слайда (макет «Титульный»).'); }
            if (DE.deck.slides[DE.deck.slides.length - 1].layout !== 'closing') { add('info', DE.deck.slides.length - 1, null, 'В конце — финальный слайд с логотипом (макет «Финальный»).'); }
        }
        return out;
    }

    function renderChecks() {
        var issues = runChecks();
        root.querySelector('[data-check-count]').textContent = issues.filter(function (i) { return i.level === 'warn'; }).length || '';
        if (!issues.length) { panel.innerHTML = '<p class="de-ok">✓ Замечаний нет: шрифты и цвета фирменные, текст помещается, всё на своих местах.</p>'; return; }
        panel.innerHTML = '<p class="hints">Проверка стиля «Презентации к собранию»: шрифты, цвета, размеры, пустые места.</p><ul class="de-issues">' + issues.map(function (i, n) {
            return '<li class="de-issue de-issue--' + i.level + '"><button type="button" class="de-link" data-igo="' + n + '">Слайд ' + (i.slide + 1) + '</button> ' + esc(i.text) + (i.fix ? ' <button type="button" class="de-link" data-ifix="' + n + '">Исправить</button>' : '') + '</li>';
        }).join('') + '</ul><button type="button" class="de-btn de-btn--small" data-irecheck>Проверить снова</button>';
        panel.querySelectorAll('[data-igo]').forEach(function (b) { b.addEventListener('click', function () { var i = issues[+b.getAttribute('data-igo')]; DE.goto(i.slide); if (i.el) { DE.select([i.el], { single: true }); } }); });
        panel.querySelectorAll('[data-ifix]').forEach(function (b) { b.addEventListener('click', function () { var i = issues[+b.getAttribute('data-ifix')]; DE.goto(i.slide); i.fix(); renderChecks(); }); });
        panel.querySelector('[data-irecheck]').addEventListener('click', renderChecks);
    }

    // ---- Вкладки панели --------------------------------------------------------------------------

    function setTab(name) {
        tab = name;
        root.querySelectorAll('[data-tab]').forEach(function (b) { b.setAttribute('aria-selected', String(b.getAttribute('data-tab') === name)); });
        if (name === 'format') { renderPanel(); } else if (name === 'comments') { renderComments(); loadComments(); } else if (name === 'versions') { renderVersions(); } else { renderChecks(); }
    }
    root.querySelectorAll('[data-tab]').forEach(function (b) { b.addEventListener('click', function () { setTab(b.getAttribute('data-tab')); }); });
    if (!cfg.canComment) { var ct = root.querySelector('[data-tab="comments"]'); if (ct) { ct.hidden = true; } }

    // ---- Справка, импорт, конфликт ------------------------------------------------------------------

    function helpDialog() {
        var rows = [
            ['Двойной щелчок по тексту, Enter', 'править текст; Esc — закончить'], ['Щелчок, Shift+щелчок, рамка мышью', 'выбрать элементы'], ['Alt+щелчок', 'один элемент группы'],
            ['Перетаскивание', 'двигать (Shift — по одной оси, Alt — без направляющих)'], ['Стрелки, Shift+стрелки', 'сдвиг на 1 и 10 пикселей'],
            ['Ctrl+C, Ctrl+X, Ctrl+V', 'копировать, вырезать, вставить (и картинки из буфера)'], ['Ctrl+D', 'дублировать'], ['Delete', 'удалить'],
            ['Ctrl+Z, Ctrl+Shift+Z', 'отменить, повторить'], ['Ctrl+G, Ctrl+Shift+G', 'сгруппировать, разгруппировать'], ['Ctrl+], Ctrl+[', 'вперёд, назад (с Shift — на передний и задний план)'],
            ['Ctrl+A', 'выбрать всё на слайде'], ['Ctrl+M', 'новый слайд'], ['PageUp, PageDown', 'предыдущий, следующий слайд'],
            ['Ctrl+B, Ctrl+I, Ctrl+U, Ctrl+K', 'жирный, курсив, подчёркивание, ссылка (в тексте)'], ['Tab, Shift+Tab', 'уровень пункта списка'],
            ['Ctrl+колёсико, Ctrl+0', 'масштаб, по размеру окна'], ['F5, Shift+F5', 'показ с начала, с текущего слайда'], ['Ctrl+S', 'сохранить сейчас (сохраняется и само)'],
        ];
        openDialog('<h2 class="h-section">Клавиши и подсказки</h2><table class="de-keys"><tbody>' + rows.map(function (r) { return '<tr><th>' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</tbody></table>'
            + '<p class="hints">Изображения можно перетащить на слайд из папки или вставить из буфера обмена; файл .pptx, перетащенный на слайд, импортируется. Шрифты на компьютере, где открывают файл PowerPoint, должны быть установлены: Roboto и DIN Condensed.</p>'
            + '<div class="form-actions"><button type="button" class="btn btn--pill" data-close>Понятно</button></div>', null, { wide: true });
    }

    function importPptx(file) {
        if (!file) { return; }
        openDialog('<h2 class="h-section">Импорт из PowerPoint</h2><p>Файл «' + esc(file.name) + '». Перенесутся текст, картинки, фигуры, таблицы, диаграммы, фон и заметки; оформление — как получится ближе к фирменному.</p>'
            + '<label class="de-check"><input type="radio" name="mode" value="append" checked> Добавить слайды в конец</label><label class="de-check"><input type="radio" name="mode" value="replace"> Заменить все слайды</label>'
            + '<div class="form-actions"><button type="button" class="btn btn--pill" data-go>Импортировать</button><button type="button" class="btn btn--ghost" data-close>Отмена</button></div>',
        function (body) {
            body.querySelector('[data-go]').addEventListener('click', function () {
                var mode = body.querySelector('input[name=mode]:checked').value;
                var btn = body.querySelector('[data-go]');
                btn.disabled = true; btn.textContent = 'Импортирую…';
                DE.save(true).then(function () {
                    var fd = new FormData();
                    fd.append('file', file, file.name);
                    fd.append('mode', mode);
                    return DE.api(cfg.urls.import, { body: fd });
                }).then(function (res) {
                    dialog.close();
                    DE.replaceDeck(res.deck, res.revision, true);
                    DE.state.dirty = false;
                    DE.emit('save-state', 'saved');
                    DE.toast('Импортировано слайдов: ' + res.imported + '.' + (res.warnings && res.warnings.length ? ' ' + res.warnings.join(' ') : ''), res.warnings && res.warnings.length ? 'warn' : null);
                    if (mode === 'append') { DE.goto(DE.deck.slides.length - res.imported); }
                }).catch(function (err) { btn.disabled = false; btn.textContent = 'Импортировать'; DE.toast(err.message, 'error'); });
            });
        });
    }
    pptxInput.addEventListener('change', function () { importPptx(pptxInput.files[0]); });
    DE.on('import-pptx', importPptx);

    DE.on('conflict', function (data) {
        openDialog('<h2 class="h-section">Презентацию изменили в другом месте</h2><p>' + esc(data && data.error || '') + (data && data.updatedBy ? ' Последнее сохранение: ' + esc(data.updatedBy) + (data.updatedAt ? ', ' + new Date(data.updatedAt).toLocaleString('ru-RU') : '') + '.' : '') + '</p>'
            + '<p>Чтобы не потерять чужие правки, загрузите сохранённую версию (ваши несохранённые изменения пропадут) — или сохраните свои поверх.</p>'
            + '<div class="form-actions"><button type="button" class="btn btn--pill" data-load>Загрузить сохранённую</button><button type="button" class="btn btn--ghost" data-force>Сохранить мои поверх</button></div>',
        function (body) {
            body.querySelector('[data-load]').addEventListener('click', function () {
                DE.api(cfg.urls.data).then(function (res) { dialog.close(); DE.replaceDeck(res.deck, res.revision, true); DE.state.dirty = false; DE.clearBackup(); DE.emit('save-state', 'saved'); DE.toast('Загружена сохранённая версия. Ваши изменения можно вернуть кнопкой «Отменить».'); });
            });
            body.querySelector('[data-force]').addEventListener('click', function () { dialog.close(); DE.save(true); });
        });
    });

    // ---- Состояние, контекстное меню, заметки ------------------------------------------------------

    DE.on('save-state', function (s) {
        var text = { dirty: 'Есть несохранённые изменения', saving: 'Сохраняю…', saved: 'Сохранено' + (DE.state.savedAt ? ' в ' + DE.state.savedAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : ''), error: 'Не сохранено — повторю', conflict: 'Конфликт версий' }[s] || '';
        saveState.textContent = text;
        saveState.className = 'de-top__save is-' + s;
    });
    DE.on('saved', function (res) {
        var badge = root.querySelector('[data-status-label]');
        if (badge && res.statusLabel) { badge.textContent = res.statusLabel; }
        if (res.title) { document.title = res.title + ' — редактор слайдов'; }
    });

    DE.on('context-menu', function (o) {
        var els = DE.selected();
        var items = [];
        if (els.length) {
            items = [
                { label: 'Вырезать', shortcut: 'Ctrl+X', fn: function () { DE.copy(true); } },
                { label: 'Копировать', shortcut: 'Ctrl+C', fn: function () { DE.copy(false); } },
                { label: 'Вставить', shortcut: 'Ctrl+V', fn: function () { if (!DE.pasteElements()) { DE.toast('Буфер пуст — скопируйте элементы.'); } } },
                { label: 'Дублировать', shortcut: 'Ctrl+D', fn: DE.duplicateSelected },
                { label: 'Удалить', shortcut: 'Delete', fn: DE.removeSelected },
                { sep: true },
                { label: 'На передний план', shortcut: 'Ctrl+Shift+]', fn: function () { DE.order('front'); } },
                { label: 'На задний план', shortcut: 'Ctrl+Shift+[', fn: function () { DE.order('back'); } },
                { label: els.length > 1 ? 'Сгруппировать' : 'Разгруппировать', shortcut: els.length > 1 ? 'Ctrl+G' : 'Ctrl+Shift+G', disabled: els.length < 2 && !els[0].group, fn: function () { if (els.length > 1) { DE.group(); } else { DE.ungroup(); } } },
                { label: els[0].locked ? 'Открепить' : 'Закрепить', fn: function () { DE.setProp('locked', !els[0].locked); renderPanel(); } },
            ];
            if (cfg.canComment) { items.push({ sep: true }, { label: 'Замечание к элементу…', fn: function () { setTab('comments'); var t = panel.querySelector('[data-cnew] textarea'); if (t) { t.focus(); } } }); }
        } else {
            items = [
                { label: 'Вставить', shortcut: 'Ctrl+V', fn: function () { if (!DE.pasteElements()) { DE.toast('Буфер пуст — скопируйте элементы.'); } } },
                { label: 'Новый слайд…', shortcut: 'Ctrl+M', fn: layoutGallery },
                { label: 'Дублировать слайд', fn: function () { DE.duplicateSlides([DE.state.slide]); } },
                { label: 'Фон слайда…', fn: function () { DE.select([]); setTab('format'); } },
            ];
        }
        openMenu(items, o.x, o.y);
    });

    notes.addEventListener('input', function () {
        var v = notes.value;
        DE.change(function (d) { d.slides[DE.state.slide].notes = v; }, { key: 'notes' + DE.state.slide, what: 'none' });
    });

    function updateInfo() {
        var s = DE.slide();
        if (!s) { return; }
        var els = DE.selected();
        info.textContent = 'Слайд ' + (DE.state.slide + 1) + ' из ' + DE.deck.slides.length
            + (els.length === 1 ? ' · ' + (TYPE_NAMES[els[0].type] || '') + ' ' + Math.round(els[0].x) + ', ' + Math.round(els[0].y) + ' · ' + Math.round(els[0].w) + '×' + Math.round(els[0].h) : (els.length ? ' · выбрано ' + els.length : ''));
    }

    DE.on('zoom', function (k) {
        var label = root.querySelector('[data-zoom-label]');
        if (label) { label.textContent = DE.state.zoom === 'fit' ? 'По окну · ' + Math.round(k * 100) + '%' : Math.round(k * 100) + '%'; }
        drawPins();
    });

    var metaInput = root.querySelector('[data-meta="title"]');
    metaInput.addEventListener('input', function () { DE.setMeta('title', metaInput.value); });
    DE.on('focus-meta', function (field) {
        if (field === 'title') { metaInput.focus(); metaInput.select(); return; }
        DE.select([]); setTab('format');
        var n = panel.querySelector('[data-meta-field="' + field + '"]');
        if (n) { n.focus(); }
    });
    DE.on('focus-prop', function (f) { setTab('format'); var n = panel.querySelector('[data-f="' + f + '"]'); if (n) { n.focus(); } });

    var banner = root.querySelector('[data-banner-close]');
    if (banner) { banner.addEventListener('click', function () { banner.parentNode.remove(); DE.layout(); }); }

    // ---- События изменений -----------------------------------------------------------------------

    DE.on('change', function (info2) {
        if (info2.what === 'all' || info2.what === 'slides') { renderSlides(); } else { refreshThumb(DE.state.slide); }
        if (!fromPanel && tab === 'format') { renderPanel(); }
        if (info2.what === 'all') { notes.value = (DE.slide() && DE.slide().notes) || ''; }
        if (tab === 'check') { clearTimeout(DE._checkTimer); DE._checkTimer = setTimeout(renderChecks, 600); }
        updateInfo();
        updateUndo();
    });
    DE.on('select', function () { if (tab === 'format') { renderPanel(); } else if (tab === 'comments') { renderComments(); } updateInfo(); });
    DE.on('slide', function () {
        renderSlides();
        notes.value = (DE.slide() && DE.slide().notes) || '';
        if (tab === 'format') { renderPanel(); } else if (tab === 'comments') { renderComments(); }
        drawPins();
    });
    DE.on('text-input', function () { refreshThumb(DE.state.slide); DE.emit('save-state', 'dirty'); clearTimeout(DE._textSave); DE._textSave = setTimeout(function () { DE.save(); }, 2500); });
    DE.on('editing', function (id) { if (!id) { refreshThumb(DE.state.slide); } });

    function updateUndo() {
        var u = toolbar.querySelector('[data-act="undo"]'), r = toolbar.querySelector('[data-act="redo"]');
        if (u) { u.disabled = !DE.canUndo(); } if (r) { r.disabled = !DE.canRedo(); }
    }

    // ---- Запуск ----------------------------------------------------------------------------------

    buildToolbar();
    DE.state.slideSel = [0];
    DE.layout();
    DE.renderStage();
    renderSlides();
    renderPanel();
    updateUndo();
    notes.value = (DE.slide() && DE.slide().notes) || '';
    loadComments();
    setTimeout(function () { var issues = runChecks().filter(function (i) { return i.level === 'warn'; }).length; root.querySelector('[data-check-count]').textContent = issues || ''; }, 1500);

    // Несохранённая работа из прошлого раза (вкладку закрыли или пропала сеть).
    var backup = DE.readBackup();
    if (backup) {
        if (backup.revision === DE.state.revision && JSON.stringify(backup.deck) !== JSON.stringify(DE.deck)) {
            DE.toast('В этом браузере осталась несохранённая правка от ' + new Date(backup.at).toLocaleString('ru-RU') + '.', 'warn', { label: 'Вернуть её', fn: function () { DE.replaceDeck(backup.deck, undefined, true); DE.state.dirty = true; DE.save(); } });
        } else if (backup.revision !== DE.state.revision) { DE.clearBackup(); }
    }
    if (cfg.status === 'submitted' && cfg.isModerator) {
        DE.toast('Презентация на модерации: правки модератора сохраняются в её рабочей редакции.');
    }
})();
