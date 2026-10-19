// Редактор слайдов — холст: выбор элементов (щелчок, Shift, рамка), перетаскивание с направляющими,
// изменение размера и поворот, концы линий, правка текста на слайде с панелью оформления, ячейки таблиц,
// клавиши, буфер обмена, перетаскивание файлов.
(function () {
    'use strict';

    var DE = window.DE;
    if (!DE) { return; }
    var R = DE.R, U = DE.util, root = DE.root;
    var canvas = root.querySelector('[data-canvas]');
    var stage = root.querySelector('[data-stage]');
    var slideBox = root.querySelector('[data-stage-slide]');
    var overlay = root.querySelector('[data-overlay]');
    var textbar = root.querySelector('[data-textbar]');
    var k = 1; // масштаб холста: экранных пикселей на пиксель слайда
    var SNAP = 6;

    // ---- Масштаб и отрисовка ---------------------------------------------------------------------

    function computeScale() {
        var z = DE.state.zoom;
        if (z === 'fit') {
            var w = canvas.clientWidth - 48, h = canvas.clientHeight - 48;
            return Math.max(0.1, Math.min(w / R.W, h / R.H));
        }
        return z;
    }

    function layout() {
        k = computeScale();
        stage.style.width = (R.W * k) + 'px';
        stage.style.height = (R.H * k) + 'px';
        slideBox.style.transform = 'scale(' + k + ')';
        DE.scale = k;
        drawOverlay();
        DE.emit('zoom', k);
    }
    DE.layout = layout;

    DE.setZoom = function (z) {
        DE.state.zoom = z === 'fit' ? 'fit' : U.clamp(z, 0.2, 3);
        layout();
    };

    function nodeOf(id) { return slideBox.querySelector('.deck-el[data-id="' + id + '"]'); }
    DE.nodeOf = nodeOf;

    function renderStage() {
        var slide = DE.slide();
        slideBox.innerHTML = '';
        if (!slide) { return; }
        var node = R.slide(slide, { mode: 'edit', onImageLoad: function () { drawOverlay(); } });
        if (DE.state.editing) {
            // Элемент, который сейчас правят, не перерисовывается — иначе пропал бы курсор.
            var old = DE.state.editing.node, fresh = node.querySelector('.deck-el[data-id="' + DE.state.editing.id + '"]');
            if (old && fresh) { fresh.replaceWith(old); }
        }
        slideBox.appendChild(node);
        markLocked();
        drawOverlay();
    }
    DE.renderStage = renderStage;

    function markLocked() {
        DE.slide().elements.forEach(function (e) { var n = nodeOf(e.id); if (n && e.locked) { n.classList.add('is-locked'); } });
    }

    // Рамка выбора, ручки размера и поворота, концы линий.
    function drawOverlay(guides) {
        overlay.innerHTML = '';
        var els = DE.selected();
        if (DE.state.editing) {
            var ed = DE.el(DE.state.editing.id);
            if (ed) { overlay.appendChild(frame(ed, 'de-sel de-sel--editing')); }
            return;
        }
        els.forEach(function (e) {
            if (e.type === 'line') { overlay.appendChild(lineFrame(e, els.length === 1)); return; }
            var f = frame(e, 'de-sel' + (e.locked ? ' de-sel--locked' : ''));
            if (els.length === 1 && !e.locked) {
                ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(function (h) {
                    var d = document.createElement('div');
                    d.className = 'de-handle de-handle--' + h;
                    d.setAttribute('data-handle', h);
                    f.appendChild(d);
                });
                var rot = document.createElement('div');
                rot.className = 'de-handle de-handle--rotate';
                rot.setAttribute('data-handle', 'rotate');
                rot.title = 'Повернуть (Shift — шаг 15°)';
                f.appendChild(rot);
            }
            if (e.locked) {
                var lock = document.createElement('span');
                lock.className = 'de-sel__lock';
                lock.title = 'Закреплено — не двигается; снять закрепление можно на панели «Формат»';
                lock.textContent = '🔒';
                f.appendChild(lock);
            }
            overlay.appendChild(f);
        });
        if (els.length > 1) {
            var b = DE.bounds(els);
            var g = document.createElement('div');
            g.className = 'de-sel de-sel--group';
            place(g, b.x, b.y, b.w, b.h, 0);
            overlay.appendChild(g);
        }
        (guides || []).forEach(function (gd) {
            var line = document.createElement('div');
            line.className = 'de-guide de-guide--' + gd.axis;
            if (gd.axis === 'x') { line.style.left = (gd.at * k) + 'px'; } else { line.style.top = (gd.at * k) + 'px'; }
            overlay.appendChild(line);
        });
        DE.emit('overlay');
    }
    DE.drawOverlay = drawOverlay;

    function place(node, x, y, w, h, rot) {
        node.style.left = (x * k) + 'px'; node.style.top = (y * k) + 'px';
        node.style.width = (w * k) + 'px'; node.style.height = (h * k) + 'px';
        node.style.transform = rot ? 'rotate(' + rot + 'deg)' : '';
    }

    function frame(e, cls) {
        var f = document.createElement('div');
        f.className = cls;
        f.setAttribute('data-for', e.id);
        place(f, e.x, e.y, e.w, e.h, e.rotation || 0);
        return f;
    }

    function lineFrame(e, handles) {
        var f = document.createElement('div');
        f.className = 'de-line-sel';
        [['p1', e.x1, e.y1], ['p2', e.x2, e.y2]].forEach(function (p) {
            var d = document.createElement('div');
            d.className = 'de-handle de-handle--point';
            d.setAttribute('data-handle', p[0]);
            d.style.left = (p[1] * k) + 'px'; d.style.top = (p[2] * k) + 'px';
            if (!handles || e.locked) { d.style.pointerEvents = 'none'; }
            f.appendChild(d);
        });
        var len = Math.hypot(e.x2 - e.x1, e.y2 - e.y1), ang = Math.atan2(e.y2 - e.y1, e.x2 - e.x1) * 180 / Math.PI;
        var bar = document.createElement('div');
        bar.className = 'de-line-sel__bar';
        bar.style.left = (e.x1 * k) + 'px'; bar.style.top = (e.y1 * k) + 'px';
        bar.style.width = (len * k) + 'px'; bar.style.transform = 'rotate(' + ang + 'deg)';
        f.appendChild(bar);
        return f;
    }

    // ---- Координаты ------------------------------------------------------------------------------

    function toSlide(ev) {
        var r = stage.getBoundingClientRect();
        return { x: (ev.clientX - r.left) / k, y: (ev.clientY - r.top) / k };
    }
    DE.toSlide = toSlide;

    // Направляющие: края и центры слайда и других элементов.
    function snapTargets(exclude) {
        var xs = [0, R.W / 2, R.W], ys = [0, R.H / 2, R.H];
        DE.slide().elements.forEach(function (e) {
            if (exclude.indexOf(e.id) >= 0) { return; }
            var b = DE.box(e);
            xs.push(b.x, b.x + b.w / 2, b.x + b.w);
            ys.push(b.y, b.y + b.h / 2, b.y + b.h);
        });
        return { xs: xs, ys: ys };
    }

    function snapValue(values, targets) {
        var best = null;
        values.forEach(function (v) {
            targets.forEach(function (t) {
                var d = t - v;
                if (Math.abs(d) <= SNAP / k && (best === null || Math.abs(d) < Math.abs(best.d))) { best = { d: d, at: t }; }
            });
        });
        return best;
    }

    // ---- Указатель -------------------------------------------------------------------------------

    var gesture = null;

    function startMove(ev, ids) {
        var els = ids.map(function (id) { return DE.el(id); }).filter(function (e) { return e && !e.locked; });
        if (!els.length) { return; }
        gesture = {
            type: 'move', start: toSlide(ev), moved: false,
            items: els.map(function (e) { return { e: e, x: e.x, y: e.y, x1: e.x1, y1: e.y1, x2: e.x2, y2: e.y2 }; }),
            bounds: DE.bounds(els), targets: snapTargets(els.map(function (e) { return e.id; })),
        };
    }

    function onMove(ev) {
        var p = toSlide(ev), g = gesture;
        var dx = p.x - g.start.x, dy = p.y - g.start.y;
        if (!g.moved) {
            if (Math.abs(dx) * k < 3 && Math.abs(dy) * k < 3) { return; }
            g.moved = true;
            DE.beginGesture();
            root.classList.add('is-dragging');
        }
        if (ev.shiftKey) { if (Math.abs(dx) > Math.abs(dy)) { dy = 0; } else { dx = 0; } }
        var guides = [];
        if (!ev.altKey) {
            var b = g.bounds;
            var sx = snapValue([b.x + dx, b.x + b.w / 2 + dx, b.x + b.w + dx], g.targets.xs);
            var sy = snapValue([b.y + dy, b.y + b.h / 2 + dy, b.y + b.h + dy], g.targets.ys);
            if (sx) { dx += sx.d; guides.push({ axis: 'x', at: sx.at }); }
            if (sy) { dy += sy.d; guides.push({ axis: 'y', at: sy.at }); }
        }
        g.items.forEach(function (it) {
            var e = it.e;
            e.x = U.round(it.x + dx); e.y = U.round(it.y + dy);
            if (e.type === 'line') { e.x1 = U.round(it.x1 + dx); e.y1 = U.round(it.y1 + dy); e.x2 = U.round(it.x2 + dx); e.y2 = U.round(it.y2 + dy); }
            refreshNode(e);
        });
        drawOverlay(guides);
        DE.emit('geometry');
    }

    // Перерисовать один элемент (быстро, во время перетаскивания).
    function refreshNode(e) {
        var n = nodeOf(e.id);
        if (!n) { return; }
        if (e.type === 'line' || e.type === 'chart' || e.type === 'table' || (e.type === 'shape' && e.shape !== 'rect') || (e.type === 'image' && e.fit !== 'fill') || e.radius) {
            var fresh = R.element(e, { mode: 'edit' });
            if (e.locked) { fresh.classList.add('is-locked'); }
            n.replaceWith(fresh);
            return;
        }
        n.style.left = e.x + 'px'; n.style.top = e.y + 'px'; n.style.width = e.w + 'px'; n.style.height = e.h + 'px';
        n.style.transform = e.rotation ? 'rotate(' + e.rotation + 'deg)' : '';
    }
    DE.refreshNode = refreshNode;

    function startResize(ev, handle) {
        var e = DE.selected()[0];
        if (!e || e.locked) { return; }
        DE.beginGesture();
        var map = { nw: [-1, -1], n: [0, -1], ne: [1, -1], e: [1, 0], se: [1, 1], s: [0, 1], sw: [-1, 1], w: [-1, 0] };
        gesture = { type: 'resize', e: e, h: map[handle], start: { x: e.x, y: e.y, w: e.w, h: e.h, rot: e.rotation || 0, fontSize: e.fontSize }, targets: snapTargets([e.id]) };
    }

    function onResize(ev) {
        var g = gesture, e = g.e, s = g.start, p = toSlide(ev);
        var a = s.rot * Math.PI / 180, cos = Math.cos(a), sin = Math.sin(a);
        var cx = s.x + s.w / 2, cy = s.y + s.h / 2, hx = g.h[0], hy = g.h[1];
        // Точка напротив ручки остаётся на месте.
        var ax = cx + cos * (-hx * s.w / 2) - sin * (-hy * s.h / 2);
        var ay = cy + sin * (-hx * s.w / 2) + cos * (-hy * s.h / 2);
        var lx = (p.x - ax) * cos + (p.y - ay) * sin, ly = -(p.x - ax) * sin + (p.y - ay) * cos;
        var w = hx ? Math.max(8, hx * lx) : s.w, h = hy ? Math.max(8, hy * ly) : s.h;
        var keep = (e.type === 'image' || e.type === 'qr') ? !ev.shiftKey : ev.shiftKey;
        if (e.type === 'qr') { keep = true; }
        if (keep && hx && hy) {
            var ratio = s.w / s.h;
            if (w / h > ratio) { h = w / ratio; } else { w = h * ratio; }
        }
        var guides = [];
        if (!s.rot && !ev.altKey) {
            // Края — к направляющим.
            var nx = hx ? (hx > 0 ? ax : ax - w) : ax - w / 2, ny = hy ? (hy > 0 ? ay : ay - h) : ay - h / 2;
            if (hx) {
                var edge = hx > 0 ? nx + w : nx, sx = snapValue([edge], g.targets.xs);
                if (sx) { w += hx * sx.d; guides.push({ axis: 'x', at: sx.at }); }
            }
            if (hy) {
                var edgeY = hy > 0 ? ny + h : ny, sy = snapValue([edgeY], g.targets.ys);
                if (sy) { h += hy * sy.d; guides.push({ axis: 'y', at: sy.at }); }
            }
        }
        // Новый центр: от неподвижной точки вдоль осей элемента.
        var ncx = ax + cos * (hx * w / 2) - sin * (hy * h / 2);
        var ncy = ay + sin * (hx * w / 2) + cos * (hy * h / 2);
        e.w = U.round(w); e.h = U.round(h);
        e.x = U.round(ncx - w / 2); e.y = U.round(ncy - h / 2);
        refreshNode(e);
        drawOverlay(guides);
        DE.emit('geometry');
    }

    function startRotate() {
        var e = DE.selected()[0];
        if (!e || e.locked) { return; }
        DE.beginGesture();
        gesture = { type: 'rotate', e: e };
    }

    function onRotate(ev) {
        var e = gesture.e, p = toSlide(ev);
        var ang = Math.atan2(p.y - (e.y + e.h / 2), p.x - (e.x + e.w / 2)) * 180 / Math.PI + 90;
        if (ev.shiftKey) { ang = Math.round(ang / 15) * 15; } else {
            [0, 90, 180, 270, 360, -90].forEach(function (t) { if (Math.abs(ang - t) < 3) { ang = t; } });
        }
        if (ang > 180) { ang -= 360; }
        e.rotation = U.round(ang);
        refreshNode(e);
        drawOverlay();
        DE.emit('geometry');
    }

    function startPoint(ev, which) {
        var e = DE.selected()[0];
        if (!e || e.locked) { return; }
        DE.beginGesture();
        gesture = { type: 'point', e: e, which: which, targets: snapTargets([e.id]) };
    }

    function onPoint(ev) {
        var g = gesture, e = g.e, p = toSlide(ev);
        var ox = g.which === 'p1' ? e.x2 : e.x1, oy = g.which === 'p1' ? e.y2 : e.y1;
        if (ev.shiftKey) {
            var ang = Math.round(Math.atan2(p.y - oy, p.x - ox) / (Math.PI / 12)) * (Math.PI / 12), len = Math.hypot(p.x - ox, p.y - oy);
            p = { x: ox + Math.cos(ang) * len, y: oy + Math.sin(ang) * len };
        } else if (!ev.altKey) {
            var sx = snapValue([p.x], g.targets.xs.concat([ox])), sy = snapValue([p.y], g.targets.ys.concat([oy]));
            if (sx) { p.x += sx.d; } if (sy) { p.y += sy.d; }
        }
        if (g.which === 'p1') { e.x1 = U.round(p.x); e.y1 = U.round(p.y); } else { e.x2 = U.round(p.x); e.y2 = U.round(p.y); }
        e.x = Math.min(e.x1, e.x2); e.y = Math.min(e.y1, e.y2); e.w = Math.max(1, Math.abs(e.x2 - e.x1)); e.h = Math.max(1, Math.abs(e.y2 - e.y1));
        refreshNode(e);
        drawOverlay();
        DE.emit('geometry');
    }

    function startMarquee(ev) {
        var p = toSlide(ev);
        var box = document.createElement('div');
        box.className = 'de-marquee';
        overlay.appendChild(box);
        gesture = { type: 'marquee', start: p, box: box, add: ev.shiftKey || ev.ctrlKey || ev.metaKey, base: DE.state.sel.slice() };
    }

    function onMarquee(ev) {
        var g = gesture, p = toSlide(ev);
        var x = Math.min(g.start.x, p.x), y = Math.min(g.start.y, p.y), w = Math.abs(p.x - g.start.x), h = Math.abs(p.y - g.start.y);
        place(g.box, x, y, w, h, 0);
        var hit = DE.slide().elements.filter(function (e) {
            if (e.locked) { return false; }
            var b = DE.box(e);
            return b.x < x + w && b.x + b.w > x && b.y < y + h && b.y + b.h > y;
        }).map(function (e) { return e.id; });
        DE.state.sel = g.add ? g.base.concat(hit.filter(function (id) { return g.base.indexOf(id) < 0; })) : hit;
        drawOverlay();
        overlay.appendChild(g.box);
    }

    stage.addEventListener('pointerdown', function (ev) {
        if (ev.button !== 0) { return; }
        var handle = ev.target.closest('[data-handle]');
        if (DE.state.editing) {
            var editNode = DE.state.editing.node;
            if (editNode && editNode.contains(ev.target)) { return; } // курсор внутри текста
            DE.stopEditing();
        }
        if (handle) {
            ev.preventDefault();
            var h = handle.getAttribute('data-handle');
            if (h === 'rotate') { startRotate(); } else if (h === 'p1' || h === 'p2') { startPoint(ev, h); } else { startResize(ev, h); }
        } else {
            var node = ev.target.closest('.deck-el');
            var id = node ? node.getAttribute('data-id') : null;
            var e = id ? DE.el(id) : null;
            if (e) {
                ev.preventDefault();
                var additive = ev.shiftKey || ev.ctrlKey || ev.metaKey;
                if (additive) {
                    var sel = DE.state.sel.slice(), pos = sel.indexOf(id);
                    if (pos >= 0) { sel.splice(pos, 1); DE.select(sel, { single: true }); } else { DE.select(sel.concat([id]), { single: ev.altKey }); }
                } else if (DE.state.sel.indexOf(id) < 0 || ev.altKey) {
                    DE.select([id], { single: ev.altKey });
                }
                if (DE.state.sel.indexOf(id) >= 0) { startMove(ev, DE.state.sel); }
            } else {
                if (!(ev.shiftKey || ev.ctrlKey || ev.metaKey)) { DE.select([]); }
                startMarquee(ev);
            }
        }
        if (gesture) {
            stage.setPointerCapture(ev.pointerId);
        }
    });

    stage.addEventListener('pointermove', function (ev) {
        if (!gesture) { return; }
        if (gesture.type === 'move') { onMove(ev); } else if (gesture.type === 'resize') { onResize(ev); } else if (gesture.type === 'rotate') { onRotate(ev); }
        else if (gesture.type === 'point') { onPoint(ev); } else if (gesture.type === 'marquee') { onMarquee(ev); }
    });

    function endGesture() {
        var g = gesture;
        gesture = null;
        root.classList.remove('is-dragging');
        if (!g) { return; }
        if (g.type === 'marquee') { g.box.remove(); DE.select(DE.state.sel, { single: true }); return; }
        if (g.type === 'move' && !g.moved) { drawOverlay(); return; }
        if (g.type === 'resize' && g.e.autoFit) { fitText(g.e); }
        DE.endGesture();
    }
    stage.addEventListener('pointerup', endGesture);
    stage.addEventListener('pointercancel', endGesture);
    stage.addEventListener('lostpointercapture', function () { if (gesture) { endGesture(); } });

    // Элемент под указателем (щелчки после захвата указателя приходят на сам холст).
    function elementAt(ev) {
        var hit = document.elementsFromPoint ? document.elementsFromPoint(ev.clientX, ev.clientY) : [ev.target];
        for (var i = 0; i < hit.length; i++) {
            var n = hit[i].closest && hit[i].closest('.deck-el');
            if (n && slideBox.contains(n)) { return { node: n, target: hit[i] }; }
        }
        return null;
    }

    stage.addEventListener('dblclick', function (ev) {
        var at = elementAt(ev);
        var node = at ? at.node : null;
        if (!node) { return; }
        var e = DE.el(node.getAttribute('data-id'));
        if (!e) { return; }
        if (e.type === 'text' || e.type === 'shape') { startEditing(e.id, ev); }
        else if (e.type === 'image') { DE.emit('pick-image', e); }
        else if (e.type === 'table') { editTable(e, at.target.closest('td')); }
        else if (e.type === 'chart') { DE.emit('chart-data', e); }
        else if (e.type === 'qr') { DE.emit('focus-prop', 'text'); }
    });

    stage.addEventListener('contextmenu', function (ev) {
        var node = ev.target.closest('.deck-el');
        if (DE.state.editing) { return; }
        ev.preventDefault();
        if (node && DE.state.sel.indexOf(node.getAttribute('data-id')) < 0) { DE.select([node.getAttribute('data-id')]); }
        DE.emit('context-menu', { x: ev.clientX, y: ev.clientY, slidePoint: toSlide(ev), onElement: !!node });
    });

    DE.on('select', function () { drawOverlay(); updateTextbar(); });
    DE.on('slide', function () { renderStage(); updateTextbar(); });
    DE.on('change', function (info) {
        if (info.what === 'none') { return; }
        renderStage();
        updateTextbar();
    });
    window.addEventListener('resize', function () { layout(); });
    if (typeof ResizeObserver !== 'undefined') { new ResizeObserver(function () { layout(); }).observe(canvas); }
    canvas.addEventListener('wheel', function (ev) {
        if (!(ev.ctrlKey || ev.metaKey)) { return; }
        ev.preventDefault();
        DE.setZoom((DE.state.zoom === 'fit' ? k : DE.state.zoom) * (ev.deltaY < 0 ? 1.1 : 0.9));
    }, { passive: false });

    // ---- Правка текста ---------------------------------------------------------------------------

    // HTML абзацев без служебной разметки отрисовки (номера и отступы списков).
    function cleanHtml(body) {
        var copy = body.cloneNode(true);
        Array.prototype.forEach.call(copy.querySelectorAll('p'), function (p) {
            p.removeAttribute('data-marker');
            p.style.removeProperty('padding-left');
            p.style.removeProperty('--deck-marker-left');
            if (!p.getAttribute('style')) { p.removeAttribute('style'); }
        });
        Array.prototype.forEach.call(copy.querySelectorAll('font'), function (f) {
            var span = document.createElement('span');
            if (f.getAttribute('color')) { span.style.color = f.getAttribute('color'); }
            if (f.getAttribute('face')) { span.style.fontFamily = f.getAttribute('face'); }
            while (f.firstChild) { span.appendChild(f.firstChild); }
            f.replaceWith(span);
        });
        // Текст вне абзацев (после удаления всего) — в абзац.
        var html = copy.innerHTML.trim();
        if (html && !/^<p[\s>]/i.test(html)) { html = '<p>' + html.replace(/<\/?div[^>]*>/gi, '') + '</p>'; }
        if (R.isBlankHtml(html) && !/data-list/.test(html)) { html = ''; }
        return html;
    }
    DE.cleanHtml = cleanHtml;

    function caretFromPoint(x, y) {
        if (document.caretRangeFromPoint) { return document.caretRangeFromPoint(x, y); }
        if (document.caretPositionFromPoint) {
            var pos = document.caretPositionFromPoint(x, y);
            if (!pos) { return null; }
            var r = document.createRange(); r.setStart(pos.offsetNode, pos.offset); r.collapse(true); return r;
        }
        return null;
    }

    function startEditing(id, ev) {
        var e = DE.el(id);
        if (!e || DE.state.readonly) { return; }
        if (e.bind) {
            DE.toast(e.bind === 'title' ? 'Это название презентации — меняйте его в поле названия вверху.' : 'Это строка «Ответственный» — меняется в свойствах презентации (панель «Формат», когда ничего не выбрано).');
            DE.emit('focus-meta', e.bind);
            return;
        }
        if (e.locked) { DE.toast('Элемент закреплён — снимите закрепление, чтобы править.'); return; }
        var node = nodeOf(id);
        if (!node) { return; }
        var body = node.querySelector('.deck-text__body');
        var ph = node.querySelector('.deck-placeholder');
        if (ph) { ph.remove(); }
        DE.select([id], { single: true });
        DE.state.editing = { id: id, node: node, body: body, original: e.html };
        DE.beginGesture();
        node.classList.add('is-editing');
        body.contentEditable = 'true';
        body.spellcheck = true;
        if (!body.innerHTML.trim()) { body.innerHTML = '<p><br></p>'; }
        try { document.execCommand('defaultParagraphSeparator', false, 'p'); document.execCommand('styleWithCSS', false, true); } catch (err) { /* старый браузер */ }
        body.focus();
        var range = ev && ev.clientX !== undefined ? caretFromPoint(ev.clientX, ev.clientY) : null;
        var sel = window.getSelection();
        sel.removeAllRanges();
        if (range && body.contains(range.startContainer) && range.startContainer !== body) { sel.addRange(range); } else {
            var all = document.createRange();
            if (ev && ev.selectAll) { all.selectNodeContents(body); } else {
                // Курсор — в конец последнего абзаца (а не после него).
                var last = body.querySelector('p:last-of-type') || body;
                all.selectNodeContents(last);
                all.collapse(false);
            }
            sel.addRange(all);
        }
        drawOverlay();
        updateTextbar();
        DE.emit('editing', id);
    }
    DE.startEditing = startEditing;

    var flushTimer = null;
    function flushEditing() {
        var ed = DE.state.editing;
        if (!ed) { return; }
        var e = DE.el(ed.id);
        if (!e) { return; }
        var html = cleanHtml(ed.body);
        if (html !== e.html) { e.html = html; DE.state.dirty = true; DE.emit('text-input', e); }
    }
    DE.flushEditing = flushEditing;

    function stopEditing() {
        var ed = DE.state.editing;
        if (!ed) { return; }
        clearTimeout(flushTimer);
        flushEditing();
        ed.body.contentEditable = 'false';
        ed.node.classList.remove('is-editing');
        var e = DE.el(ed.id);
        DE.state.editing = null;
        if (e && e.autoFit) { fitText(e); }
        window.getSelection().removeAllRanges();
        DE.endGesture();
        DE.emit('editing', null);
    }
    DE.stopEditing = stopEditing;

    // Текст не помещается в рамку «с подгонкой» — уменьшаем размер шрифта (как «Сжать текст» в PowerPoint).
    function fitText(e) {
        var probe = R.element(Object.assign({}, e, { rotation: 0 }), { mode: 'view' });
        probe.style.visibility = 'hidden';
        probe.style.left = '0'; probe.style.top = '0';
        slideBox.firstElementChild && slideBox.firstElementChild.appendChild(probe);
        var body = probe.querySelector('.deck-text__body');
        var size = e.fontSize, min = Math.max(8, Math.round(e.fontSize * 0.4));
        var avail = e.h - 2 * (e.padding || 0);
        while (body && body.scrollHeight > avail + 1 && size > min) {
            size -= size > 24 ? 2 : 1;
            body.style.fontSize = size + 'pt';
        }
        probe.remove();
        if (size !== e.fontSize) { e.fontSize = size; DE.toast('Текст не помещался — размер шрифта уменьшен до ' + size + ' пт.'); }
    }
    DE.fitText = fitText;
    DE.fitBoundTitles = function () {
        DE.deck.slides.forEach(function (s) { s.elements.forEach(function (e) { if (e.bind === 'title' && e.autoFit) { fitTextOn(s, e); } }); });
    };
    function fitTextOn(slide, e) {
        if (slide === DE.slide()) { fitText(e); }
    }

    slideBox.addEventListener('input', function (ev) {
        var ed = DE.state.editing;
        if (!ed || !ed.body.contains(ev.target)) { return; }
        R.numberLists(ed.body);
        clearTimeout(flushTimer);
        flushTimer = setTimeout(flushEditing, 400);
        drawOverlay();
    });

    slideBox.addEventListener('keydown', function (ev) {
        var ed = DE.state.editing;
        if (!ed) { return; }
        if (ev.key === 'Escape') { ev.preventDefault(); stopEditing(); return; }
        if (ev.key === 'Tab') {
            var ps = selectedParagraphs();
            if (ps.some(function (p) { return p.getAttribute('data-list'); })) {
                ev.preventDefault();
                indent(ev.shiftKey ? -1 : 1);
            }
            return;
        }
        if (ev.key === 'Enter' && !ev.shiftKey) {
            var p = currentParagraph();
            if (p && p.getAttribute('data-list') && !p.textContent.trim()) {
                // Пустой пункт списка + Enter — конец списка.
                ev.preventDefault();
                p.removeAttribute('data-list'); p.removeAttribute('data-level');
                R.numberLists(ed.body);
            }
        }
        if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'л')) { ev.preventDefault(); linkSelection(); }
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') { ev.preventDefault(); flushEditing(); DE.save(true); }
    });

    slideBox.addEventListener('paste', function (ev) {
        if (!DE.state.editing) { return; }
        var text = ev.clipboardData && ev.clipboardData.getData('text/plain');
        if (text === undefined || text === null) { return; }
        ev.preventDefault();
        document.execCommand('insertText', false, text.replace(/\r\n?/g, '\n'));
    });

    function currentParagraph() {
        var sel = window.getSelection();
        if (!sel.rangeCount) { return null; }
        var n = sel.getRangeAt(0).startContainer;
        while (n && n !== DE.state.editing.body) { if (n.nodeName === 'P') { return n; } n = n.parentNode; }
        return null;
    }

    function selectedParagraphs() {
        var ed = DE.state.editing;
        if (!ed) { return []; }
        var sel = window.getSelection();
        var ps = Array.prototype.slice.call(ed.body.querySelectorAll('p'));
        if (!sel.rangeCount) { return ps; }
        var r = sel.getRangeAt(0);
        var hit = ps.filter(function (p) { return r.intersectsNode(p); });
        return hit.length ? hit : (currentParagraph() ? [currentParagraph()] : []);
    }

    function indent(dir) {
        selectedParagraphs().forEach(function (p) {
            var lvl = U.clamp((parseInt(p.getAttribute('data-level') || '0', 10) || 0) + dir, 0, 4);
            if (lvl) { p.setAttribute('data-level', String(lvl)); } else { p.removeAttribute('data-level'); }
        });
        R.numberLists(DE.state.editing.body);
        flushEditing();
    }

    function linkSelection() {
        var url = window.prompt('Адрес ссылки (https://… или mailto:…). Пусто — убрать ссылку.', 'https://');
        if (url === null) { return; }
        url = url.trim();
        if (!url) { document.execCommand('unlink'); } else if (/^(https?:\/\/|mailto:)/i.test(url)) { document.execCommand('createLink', false, url); } else { DE.toast('Ссылка должна начинаться с https://, http:// или mailto:', 'error'); }
        flushEditing();
    }

    // ---- Таблица: правка ячеек ---------------------------------------------------------------------

    function editTable(e, td) {
        if (e.locked) { DE.toast('Таблица закреплена.'); return; }
        var node = nodeOf(e.id);
        if (!node) { return; }
        DE.beginGesture();
        var cells = node.querySelectorAll('td');
        node.classList.add('is-editing');
        Array.prototype.forEach.call(cells, function (c) {
            try { c.contentEditable = 'plaintext-only'; } catch (err) { c.contentEditable = 'true'; }
            if (c.contentEditable !== 'plaintext-only') { c.contentEditable = 'true'; }
        });
        DE.state.editing = { id: e.id, node: node, body: node, table: true };
        var target = td || cells[0];
        if (target) { target.focus(); var r = document.createRange(); r.selectNodeContents(target); r.collapse(false); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
        drawOverlay();
        DE.emit('editing', e.id);
    }

    function flushTable() {
        var ed = DE.state.editing;
        var e = DE.el(ed.id);
        Array.prototype.forEach.call(ed.node.querySelectorAll('td[data-r]'), function (c) {
            var r = +c.getAttribute('data-r'), col = +c.getAttribute('data-c');
            if (e.rows[r] && e.rows[r][col]) { e.rows[r][col].text = c.innerText.replace(/\n$/, ''); }
        });
    }

    var stopText = stopEditing, flushText = flushEditing;
    DE.stopEditing = stopEditing = function () {
        var ed = DE.state.editing;
        if (ed && ed.table) {
            flushTable();
            Array.prototype.forEach.call(ed.node.querySelectorAll('td'), function (c) { c.contentEditable = 'false'; });
            ed.node.classList.remove('is-editing');
            DE.state.editing = null;
            DE.endGesture();
            DE.emit('editing', null);
            return;
        }
        stopText();
    };
    DE.flushEditing = flushEditing = function () {
        var ed = DE.state.editing;
        if (ed && ed.table) { flushTable(); return; }
        flushText();
    };

    slideBox.addEventListener('keydown', function (ev) {
        var ed = DE.state.editing;
        if (!ed || !ed.table) { return; }
        var td = ev.target.closest('td');
        if (ev.key === 'Escape') { ev.preventDefault(); DE.stopEditing(); return; }
        if (ev.key === 'Tab' && td) {
            ev.preventDefault();
            var all = Array.prototype.slice.call(ed.node.querySelectorAll('td'));
            var next = all[all.indexOf(td) + (ev.shiftKey ? -1 : 1)];
            if (next) { next.focus(); var r = document.createRange(); r.selectNodeContents(next); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
        }
    }, true);

    // ---- Панель оформления текста ----------------------------------------------------------------

    var tb = {};
    function buildTextbar() {
        var fonts = DE.cfg.fonts.map(function (f) { return '<option value="' + U.esc(f) + '">' + U.esc(f) + '</option>'; }).join('');
        textbar.innerHTML = ''
            + '<select class="de-input de-input--font" data-tb="font" title="Шрифт" aria-label="Шрифт">' + fonts + '</select>'
            + '<span class="de-tb-group"><button type="button" class="de-tb" data-tb="smaller" title="Меньше (Ctrl+[)">A−</button>'
            + '<input class="de-input de-input--size" data-tb="size" type="number" min="4" max="400" step="1" title="Размер, пт" aria-label="Размер шрифта, пт">'
            + '<button type="button" class="de-tb" data-tb="bigger" title="Больше (Ctrl+])">A+</button></span>'
            + '<span class="de-tb-group">'
            + '<button type="button" class="de-tb de-tb--b" data-tb="bold" title="Жирный (Ctrl+B)"><b>Ж</b></button>'
            + '<button type="button" class="de-tb de-tb--i" data-tb="italic" title="Курсив (Ctrl+I)"><i>К</i></button>'
            + '<button type="button" class="de-tb de-tb--u" data-tb="underline" title="Подчёркнутый (Ctrl+U)"><u>Ч</u></button>'
            + '<button type="button" class="de-tb de-tb--s" data-tb="strike" title="Зачёркнутый"><s>З</s></button>'
            + '<button type="button" class="de-tb" data-tb="sup" title="Надстрочный">x²</button>'
            + '<button type="button" class="de-tb" data-tb="sub" title="Подстрочный">x₂</button>'
            + '<button type="button" class="de-tb" data-tb="caps" title="Все прописные">Аа</button></span>'
            + '<span class="de-tb-group"><button type="button" class="de-tb de-tb--color" data-tb="color" title="Цвет текста"><span>А</span><i data-tb-swatch></i></button>'
            + '<button type="button" class="de-tb" data-tb="highlight" title="Выделение цветом">▮</button></span>'
            + '<span class="de-tb-group">'
            + '<button type="button" class="de-tb" data-tb="align-left" title="По левому краю">⯇</button>'
            + '<button type="button" class="de-tb" data-tb="align-center" title="По центру">≡</button>'
            + '<button type="button" class="de-tb" data-tb="align-right" title="По правому краю">⯈</button>'
            + '<button type="button" class="de-tb" data-tb="align-justify" title="По ширине">☰</button></span>'
            + '<span class="de-tb-group">'
            + '<button type="button" class="de-tb" data-tb="bullet" title="Маркированный список">•≡</button>'
            + '<button type="button" class="de-tb" data-tb="number" title="Нумерованный список">1.≡</button>'
            + '<button type="button" class="de-tb" data-tb="outdent" title="Уровень выше (Shift+Tab)">⇤</button>'
            + '<button type="button" class="de-tb" data-tb="indent" title="Уровень ниже (Tab)">⇥</button></span>'
            + '<select class="de-input de-input--lh" data-tb="lh" title="Межстрочный интервал" aria-label="Межстрочный интервал">'
            + [0.8, 0.9, 1, 1.1, 1.2, 1.3, 1.5, 1.75, 2].map(function (v) { return '<option value="' + v + '">' + String(v).replace('.', ',') + '</option>'; }).join('') + '</select>'
            + '<span class="de-tb-group"><button type="button" class="de-tb" data-tb="link" title="Ссылка (Ctrl+K)">🔗</button>'
            + '<button type="button" class="de-tb" data-tb="clear" title="Убрать оформление фрагмента">⌫</button></span>'
            + '<span class="de-textbar__hint" data-tb-hint></span>';
        Array.prototype.forEach.call(textbar.querySelectorAll('[data-tb]'), function (n) { tb[n.getAttribute('data-tb')] = n; });
        tb.hint = textbar.querySelector('[data-tb-hint]');
    }
    buildTextbar();

    function textTargets() {
        return DE.selected().filter(function (e) { return (e.type === 'text' || e.type === 'shape') && !e.bind; });
    }

    function updateTextbar() {
        var ed = DE.state.editing && !DE.state.editing.table ? DE.el(DE.state.editing.id) : null;
        var targets = ed ? [ed] : textTargets();
        textbar.classList.toggle('is-active', targets.length > 0);
        textbar.classList.toggle('is-editing', !!ed);
        var e = targets[0];
        if (!e) { tb.hint.textContent = 'Выберите надпись или фигуру — оформление применится ко всему тексту; дважды щёлкните, чтобы править текст и оформлять фрагменты.'; return; }
        tb.font.value = e.fontFamily;
        tb.size.value = e.fontSize;
        tb.lh.value = String(e.lineHeight);
        tb.bold.classList.toggle('is-on', e.fontWeight >= 600);
        tb.italic.classList.toggle('is-on', !!e.italic);
        tb.caps.classList.toggle('is-on', !!e.caps);
        textbar.querySelector('[data-tb-swatch]').style.background = e.color;
        ['left', 'center', 'right', 'justify'].forEach(function (a) { tb['align-' + a].classList.toggle('is-on', e.align === a); });
        tb.hint.textContent = '';
    }
    DE.updateTextbar = updateTextbar;

    // Команда к выделенному фрагменту (при правке) или ко всему тексту выбранных элементов.
    function execOnHtml(html, fn) {
        var tmp = document.createElement('div');
        tmp.contentEditable = 'true';
        tmp.style.cssText = 'position:fixed;left:-9999px;top:0;width:400px;';
        tmp.innerHTML = html || '';
        document.body.appendChild(tmp);
        var r = document.createRange(); r.selectNodeContents(tmp);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        try { document.execCommand('styleWithCSS', false, true); fn(); } catch (err) { /* команда недоступна */ }
        var out = cleanHtml(tmp);
        tmp.remove();
        s.removeAllRanges();
        return out;
    }

    function inline(cmd, value) {
        var ed = DE.state.editing;
        if (ed && !ed.table) {
            ed.body.focus();
            document.execCommand(cmd, false, value);
            R.numberLists(ed.body);
            flushEditing();
            return;
        }
        var targets = textTargets();
        if (!targets.length) { return; }
        DE.setProp(function (e) { e.html = execOnHtml(e.html, function () { document.execCommand(cmd, false, value); }); }, null, { ids: targets.map(function (e) { return e.id; }), key: 'tb-' + cmd + Date.now() });
    }

    function setFontSizeOfSelection(pt) {
        var ed = DE.state.editing;
        var sel = window.getSelection();
        if (ed && !ed.table && sel.rangeCount && !sel.isCollapsed) {
            document.execCommand('styleWithCSS', false, false);
            document.execCommand('fontSize', false, '7');
            Array.prototype.forEach.call(ed.body.querySelectorAll('font[size="7"]'), function (f) {
                var span = document.createElement('span');
                span.style.fontSize = pt + 'pt';
                while (f.firstChild) { span.appendChild(f.firstChild); }
                f.replaceWith(span);
            });
            Array.prototype.forEach.call(ed.body.querySelectorAll('span[style*="xxx-large"]'), function (s2) { s2.style.fontSize = pt + 'pt'; });
            document.execCommand('styleWithCSS', false, true);
            flushEditing();
            return true;
        }
        return false;
    }

    function elementProp(prop, value, fnForEditing) {
        var ed = DE.state.editing && !DE.state.editing.table ? DE.el(DE.state.editing.id) : null;
        if (ed) {
            if (fnForEditing && fnForEditing()) { return; }
            ed[prop] = value;
            R.textStyles(DE.state.editing.body, ed);
            DE.state.dirty = true;
            drawOverlay(); updateTextbar();
            return;
        }
        var ids = textTargets().map(function (e) { return e.id; });
        if (!ids.length) { return; }
        DE.setProp(prop, value, { ids: ids });
        if (prop === 'fontSize' || prop === 'lineHeight' || prop === 'fontFamily') { textTargets().forEach(function (e) { if (e.autoFit) { /* размер задан вручную */ e.autoFit = false; } }); }
    }

    function paragraphs(fn) {
        var ed = DE.state.editing;
        if (ed && !ed.table) {
            selectedParagraphs().forEach(fn);
            R.numberLists(ed.body);
            flushEditing();
            return;
        }
        var targets = textTargets();
        DE.setProp(function (e) {
            var tmp = document.createElement('div');
            tmp.innerHTML = e.html || '<p><br></p>';
            if (!tmp.querySelector('p')) { tmp.innerHTML = '<p>' + tmp.innerHTML + '</p>'; }
            Array.prototype.forEach.call(tmp.querySelectorAll('p'), fn);
            e.html = cleanHtml(tmp);
        }, null, { ids: targets.map(function (e) { return e.id; }), key: 'para-' + Date.now() });
    }

    function toggleList(kind) {
        var ps = [], all = true;
        var collect = function (p) { ps.push(p); if (p.getAttribute('data-list') !== kind) { all = false; } };
        // Сначала узнаём, все ли абзацы уже в таком списке, затем переключаем.
        var ed = DE.state.editing;
        if (ed && !ed.table) { selectedParagraphs().forEach(collect); }
        else { textTargets().forEach(function (e) { var t = document.createElement('div'); t.innerHTML = e.html; Array.prototype.forEach.call(t.querySelectorAll('p'), collect); }); }
        paragraphs(function (p) { if (all) { p.removeAttribute('data-list'); } else { p.setAttribute('data-list', kind); } });
    }

    textbar.addEventListener('mousedown', function (ev) {
        // Кнопки не забирают фокус у текста — выделение остаётся.
        if (ev.target.closest('button')) { ev.preventDefault(); }
    });

    textbar.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-tb]');
        if (!b) { return; }
        var cmd = b.getAttribute('data-tb');
        var e = (DE.state.editing && DE.el(DE.state.editing.id)) || textTargets()[0];
        if (!e) { return; }
        switch (cmd) {
            case 'bold':
                if (DE.state.editing && !window.getSelection().isCollapsed) { inline('bold'); } else { elementProp('fontWeight', e.fontWeight >= 600 ? 400 : 700); }
                break;
            case 'italic':
                if (DE.state.editing && !window.getSelection().isCollapsed) { inline('italic'); } else { elementProp('italic', !e.italic); }
                break;
            case 'underline': inline('underline'); break;
            case 'strike': inline('strikeThrough'); break;
            case 'sup': inline('superscript'); break;
            case 'sub': inline('subscript'); break;
            case 'caps': elementProp('caps', !e.caps); break;
            case 'clear': inline('removeFormat'); break;
            case 'link': if (DE.state.editing) { linkSelection(); } else { DE.toast('Дважды щёлкните текст и выделите слова для ссылки. Ссылку на весь элемент задайте на панели «Формат».'); } break;
            case 'smaller': case 'bigger':
                var next = sizeStep(e.fontSize, cmd === 'bigger' ? 1 : -1);
                if (!setFontSizeOfSelection(next)) { elementProp('fontSize', next); }
                break;
            case 'color': case 'highlight':
                DE.emit('color-picker', {
                    anchor: b, value: cmd === 'color' ? e.color : null, allowNone: cmd === 'highlight',
                    pick: function (c) {
                        if (cmd === 'highlight') { inline('hiliteColor', c || 'transparent'); return; }
                        if (DE.state.editing && !window.getSelection().isCollapsed) { inline('foreColor', c); } else { elementProp('color', c); }
                    },
                });
                break;
            case 'align-left': case 'align-center': case 'align-right': case 'align-justify':
                var a = cmd.substr(6);
                if (DE.state.editing && selectedParagraphs().length < DE.state.editing.body.querySelectorAll('p').length) {
                    paragraphs(function (p) { if (a === 'left') { p.style.removeProperty('text-align'); } else { p.style.textAlign = a; } });
                } else { paragraphs(function (p) { p.style.removeProperty('text-align'); }); elementProp('align', a); }
                break;
            case 'valign-top': case 'valign-middle': case 'valign-bottom': elementProp('valign', cmd.substr(7)); if (DE.state.editing) { DE.state.editing.node.querySelector('.deck-text').className = 'deck-text deck-text--' + cmd.substr(7); } break;
            case 'bullet': toggleList('bullet'); break;
            case 'number': toggleList('number'); break;
            case 'indent': case 'outdent':
                if (DE.state.editing) { indent(cmd === 'indent' ? 1 : -1); } else {
                    paragraphs(function (p) { var l = U.clamp((parseInt(p.getAttribute('data-level') || '0', 10) || 0) + (cmd === 'indent' ? 1 : -1), 0, 4); if (l) { p.setAttribute('data-level', String(l)); } else { p.removeAttribute('data-level'); } });
                }
                break;
        }
        updateTextbar();
    });

    textbar.addEventListener('change', function (ev) {
        var t = ev.target.getAttribute('data-tb');
        if (t === 'font') {
            if (DE.state.editing && !window.getSelection().isCollapsed) { inline('fontName', ev.target.value); } else { elementProp('fontFamily', ev.target.value); }
        } else if (t === 'size') {
            var v = U.clamp(U.num(ev.target.value, 16), 4, 400);
            if (!setFontSizeOfSelection(v)) { elementProp('fontSize', v); }
        } else if (t === 'lh') {
            elementProp('lineHeight', U.num(ev.target.value, 1));
        }
        if (DE.state.editing) { DE.state.editing.body.focus(); }
    });

    var SIZES = [8, 9, 10, 11, 12, 13, 14, 16, 18, 20, 24, 28, 32, 36, 40, 44, 48, 54, 60, 66, 72, 80, 88, 96, 120, 144];
    function sizeStep(v, dir) {
        if (dir > 0) { for (var i = 0; i < SIZES.length; i++) { if (SIZES[i] > v) { return SIZES[i]; } } return Math.min(400, v + 12); }
        for (var j = SIZES.length - 1; j >= 0; j--) { if (SIZES[j] < v) { return SIZES[j]; } }
        return Math.max(4, v - 1);
    }

    // ---- Клавиши ---------------------------------------------------------------------------------

    function inField(target) {
        return target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    }

    document.addEventListener('keydown', function (ev) {
        if (root.querySelector('dialog[open]')) { return; }
        var mod = ev.ctrlKey || ev.metaKey;
        var key = ev.key;
        if (mod && (key === 's' || key === 'ы')) { ev.preventDefault(); if (DE.state.editing) { DE.flushEditing(); } DE.save(true); return; }
        if (inField(ev.target)) { return; }
        var sel = DE.selected();
        if (mod && (key === 'z' || key === 'я') && !ev.shiftKey) { ev.preventDefault(); DE.undo(); return; }
        if (mod && ((key === 'z' || key === 'я') && ev.shiftKey || key === 'y' || key === 'н')) { ev.preventDefault(); DE.redo(); return; }
        if (mod && (key === 'a' || key === 'ф')) { ev.preventDefault(); DE.select(DE.slide().elements.filter(function (e) { return !e.locked; }).map(function (e) { return e.id; }), { single: true }); return; }
        if (mod && (key === 'd' || key === 'в')) { ev.preventDefault(); DE.duplicateSelected(); return; }
        if (mod && (key === 'g' || key === 'п')) { ev.preventDefault(); if (ev.shiftKey) { DE.ungroup(); } else { DE.group(); } return; }
        if (mod && (key === 'm' || key === 'ь')) { ev.preventDefault(); DE.emit('new-slide'); return; }
        if (mod && (key === '=' || key === '+')) { ev.preventDefault(); DE.setZoom(k * 1.2); return; }
        if (mod && key === '-') { ev.preventDefault(); DE.setZoom(k / 1.2); return; }
        if (mod && key === '0') { ev.preventDefault(); DE.setZoom('fit'); return; }
        if (mod && key === ']') { ev.preventDefault(); DE.order(ev.shiftKey ? 'front' : 'forward'); return; }
        if (mod && key === '[') { ev.preventDefault(); DE.order(ev.shiftKey ? 'back' : 'backward'); return; }
        if (key === 'F5' || (mod && key === 'Enter')) { ev.preventDefault(); DE.emit('present', !ev.shiftKey); return; }
        if (key === '?' || (key === '/' && ev.shiftKey)) { ev.preventDefault(); DE.emit('help'); return; }
        if (key === 'Escape') { if (DE.state.sel.length) { DE.select([]); } return; }
        if (key === 'PageDown') { ev.preventDefault(); DE.goto(DE.state.slide + 1); return; }
        if (key === 'PageUp') { ev.preventDefault(); DE.goto(DE.state.slide - 1); return; }
        if (!sel.length) { return; }
        if (key === 'Delete' || key === 'Backspace') { ev.preventDefault(); DE.removeSelected(); return; }
        if (key === 'Enter' || key === 'F2') {
            if (sel.length === 1 && (sel[0].type === 'text' || sel[0].type === 'shape')) { ev.preventDefault(); startEditing(sel[0].id, { selectAll: true }); }
            else if (sel.length === 1 && sel[0].type === 'table') { ev.preventDefault(); editTable(sel[0]); }
            return;
        }
        var step = ev.shiftKey ? 10 : 1, d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[key];
        if (d) {
            ev.preventDefault();
            DE.change(function () { sel.forEach(function (e) { if (!e.locked) { DE.moveBy(DE.el(e.id), d[0], d[1]); } }); }, { key: 'nudge' });
            return;
        }
        // Начать печатать в выбранной надписи.
        if (key.length === 1 && !mod && sel.length === 1 && (sel[0].type === 'text' || sel[0].type === 'shape') && !sel[0].bind && !sel[0].locked) {
            startEditing(sel[0].id, { selectAll: true });
        }
    });

    // ---- Буфер обмена и файлы --------------------------------------------------------------------

    document.addEventListener('copy', function (ev) {
        if (inField(ev.target) || DE.state.editing || !DE.state.sel.length) { return; }
        var data = DE.copy(false);
        if (data && ev.clipboardData) { ev.clipboardData.setData('text/plain', data); ev.preventDefault(); }
    });
    document.addEventListener('cut', function (ev) {
        if (inField(ev.target) || DE.state.editing || !DE.state.sel.length) { return; }
        var data = DE.copy(true);
        if (data && ev.clipboardData) { ev.clipboardData.setData('text/plain', data); ev.preventDefault(); }
    });
    document.addEventListener('paste', function (ev) {
        if (inField(ev.target) || DE.state.editing || root.querySelector('dialog[open]')) { return; }
        var cd = ev.clipboardData;
        if (!cd) { return; }
        var file = Array.prototype.filter.call(cd.files || [], function (f) { return /^image\//.test(f.type); })[0];
        if (file) { ev.preventDefault(); DE.upload(file).then(function (res) { DE.placeImage(res, imageTarget()); }).catch(function (err) { DE.toast(err.message, 'error'); }); return; }
        var text = cd.getData('text/plain');
        if (!text) { if (DE.pasteElements()) { ev.preventDefault(); } return; }
        ev.preventDefault();
        if (DE.pasteElements(text)) { return; }
        var lines = text.replace(/\r\n?/g, '\n').split('\n').slice(0, 200);
        var html = lines.map(function (l) { return '<p>' + (U.esc(l) || '<br>') + '</p>'; }).join('');
        var e = DE.factory('text', { props: { html: html, w: 700, h: Math.min(560, 40 + lines.length * 30), fontSize: 16 } });
        DE.addElements([e]);
    });

    function imageTarget() {
        var s = DE.selected();
        return s.length === 1 && s[0].type === 'image' && !s[0].src ? s[0] : null;
    }

    canvas.addEventListener('dragover', function (ev) { if (ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types, 'Files') >= 0) { ev.preventDefault(); canvas.classList.add('is-drop'); } });
    canvas.addEventListener('dragleave', function () { canvas.classList.remove('is-drop'); });
    canvas.addEventListener('drop', function (ev) {
        canvas.classList.remove('is-drop');
        var files = ev.dataTransfer && ev.dataTransfer.files;
        if (!files || !files.length) { return; }
        ev.preventDefault();
        var f = files[0];
        if (/\.pptx$/i.test(f.name)) { DE.emit('import-pptx', f); return; }
        var p = toSlide(ev);
        var node = ev.target.closest && ev.target.closest('.deck-el');
        var target = node ? DE.el(node.getAttribute('data-id')) : null;
        DE.upload(f).then(function (res) {
            if (target && target.type === 'image') { DE.placeImage(res, target); return; }
            DE.placeImage(res, null);
            var added = DE.selected()[0];
            if (added) { DE.change(function () { var e = DE.el(added.id); e.x = U.round(U.clamp(p.x - e.w / 2, 0, R.W - e.w)); e.y = U.round(U.clamp(p.y - e.h / 2, 0, R.H - e.h)); }, { key: 'drop' }); }
        }).catch(function (err) { DE.toast(err.message, 'error'); });
    });
})();
