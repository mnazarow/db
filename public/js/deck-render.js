// Отрисовка слайдов презентации (формат «docportal/deck», см. App\Service\Presentation\DeckValidator):
// один и тот же код показывает слайды в редакторе, миниатюрах, просмотре, показе и при печати в PDF.
// Слайд — 1280×720 «пикселей слайда»; размеры шрифтов — в пунктах (1 пт = 4/3 пикселя слайда), как в PowerPoint.
(function () {
    'use strict';

    var W = 1280, H = 720;
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var config = { assetBase: '', brand: {}, qr: '', chartColors: ['#0F4382', '#79A7C6', '#034185', '#DFDEDE', '#C00000', '#5B7FB0', '#A9C6DD', '#383838'] };
    var BULLET_INDENT = 15.12, NUMBER_INDENT = 36, LEVEL_INDENT = 36;

    function configure(opts) {
        Object.keys(opts || {}).forEach(function (k) { if (opts[k] !== undefined && opts[k] !== null) { config[k] = opts[k]; } });
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function num(v, d) { v = parseFloat(v); return isFinite(v) ? v : d; }

    // #RRGGBBAA → rgba(): браузеры понимают и 8-значную запись, но rgba() надёжнее в SVG и старых движках.
    function color(c, fallback) {
        if (typeof c !== 'string' || !/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(c)) { return fallback === undefined ? 'transparent' : fallback; }
        if (c.length === 7) { return c; }
        var r = parseInt(c.substr(1, 2), 16), g = parseInt(c.substr(3, 2), 16), b = parseInt(c.substr(5, 2), 16), a = parseInt(c.substr(7, 2), 16) / 255;
        return 'rgba(' + r + ',' + g + ',' + b + ',' + Math.round(a * 1000) / 1000 + ')';
    }

    function fontCss(family, weight) {
        // DIN Condensed Light — начертание 300 гарнитуры DIN Condensed (public/fonts).
        if (family === 'DIN Condensed Light') { return { family: "'DIN Condensed Light', 'DIN Condensed', 'Arial Narrow', sans-serif", weight: weight && weight > 400 ? weight : 400 }; }
        if (family === 'DIN Condensed') { return { family: "'DIN Condensed', 'Arial Narrow', sans-serif", weight: weight || 400 }; }
        if (family === 'Roboto') { return { family: "'Roboto', Arial, sans-serif", weight: weight || 400 }; }
        return { family: "'" + (family || 'Roboto') + "', sans-serif", weight: weight || 400 };
    }

    function imageUrl(src) {
        if (typeof src !== 'string' || src === '') { return null; }
        if (src.indexOf('asset:') === 0) { return config.assetBase.replace('_T_', src.substr(6)); }
        if (src.indexOf('brand:') === 0) { var b = config.brand[src.substr(6)]; return b ? b.url : null; }
        if (/^(blob:|data:image\/)/.test(src)) { return src; }
        return null;
    }

    function brandSize(src) {
        if (typeof src === 'string' && src.indexOf('brand:') === 0) { var b = config.brand[src.substr(6)]; if (b) { return { w: b.width, h: b.height }; } }
        return null;
    }

    function qrUrl(el) {
        var q = config.qr + (config.qr.indexOf('?') >= 0 ? '&' : '?');
        return q + 'text=' + encodeURIComponent(el.text || '') + '&color=' + encodeURIComponent(el.color || '#0F4382') + '&bg=' + encodeURIComponent(el.bg || '#FFFFFF');
    }

    // Положение картинки в рамке — та же математика, что в PptxWriter::fitGeometry().
    function fit(w, h, iw, ih, mode, zoom, fx, fy) {
        if (!iw || !ih || mode === 'fill') { return { left: 0, top: 0, width: w, height: h }; }
        zoom = Math.max(1, num(zoom, 1)); fx = num(fx, 0.5); fy = num(fy, 0.5);
        var s = (mode === 'contain' ? Math.min(w / iw, h / ih) : Math.max(w / iw, h / ih)) * zoom;
        var dw = iw * s, dh = ih * s;
        var left = mode === 'contain' && dw <= w ? (w - dw) / 2 : (w - dw) * fx;
        var top = mode === 'contain' && dh <= h ? (h - dh) / 2 : (h - dh) * fy;
        return { left: left, top: top, width: dw, height: dh };
    }

    // ---- Фигуры -----------------------------------------------------------------------------------

    function poly(points) {
        return points.map(function (p, i) { return (i ? 'L' : 'M') + r2(p[0]) + ' ' + r2(p[1]); }).join(' ') + ' Z';
    }
    function r2(v) { return Math.round(v * 100) / 100; }

    function ellipsePath(cx, cy, rx, ry) {
        return 'M' + r2(cx - rx) + ' ' + r2(cy) + ' A' + r2(rx) + ' ' + r2(ry) + ' 0 1 0 ' + r2(cx + rx) + ' ' + r2(cy)
            + ' A' + r2(rx) + ' ' + r2(ry) + ' 0 1 0 ' + r2(cx - rx) + ' ' + r2(cy) + ' Z';
    }

    function roundRectPath(w, h, r) {
        r = Math.max(0, Math.min(r, w / 2, h / 2));
        if (!r) { return 'M0 0 H' + r2(w) + ' V' + r2(h) + ' H0 Z'; }
        return 'M' + r2(r) + ' 0 H' + r2(w - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(w) + ' ' + r2(r)
            + ' V' + r2(h - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(w - r) + ' ' + r2(h)
            + ' H' + r2(r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 0 ' + r2(h - r)
            + ' V' + r2(r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(r) + ' 0 Z';
    }

    function star(w, h, n, inner) {
        var pts = [], cx = w / 2, cy = h / 2;
        for (var i = 0; i < n * 2; i++) {
            var a = -Math.PI / 2 + i * Math.PI / n, k = i % 2 ? inner : 1;
            pts.push([cx + Math.cos(a) * cx * k, cy + Math.sin(a) * cy * k]);
        }
        return poly(pts);
    }

    function shapePath(kind, w, h, radius) {
        var m = Math.min(w, h), d, t, o;
        switch (kind) {
            case 'roundRect': return roundRectPath(w, h, radius > 0 ? radius : m * 0.1667);
            case 'ellipse': return ellipsePath(w / 2, h / 2, w / 2, h / 2);
            case 'triangle': return poly([[w / 2, 0], [w, h], [0, h]]);
            case 'rtTriangle': return poly([[0, 0], [w, h], [0, h]]);
            case 'diamond': return poly([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]);
            case 'pentagon': return poly([[w / 2, 0], [w, h * 0.38], [w * 0.81, h], [w * 0.19, h], [0, h * 0.38]]);
            case 'hexagon': d = Math.min(w * 0.25, h * 0.29); return poly([[d, 0], [w - d, 0], [w, h / 2], [w - d, h], [d, h], [0, h / 2]]);
            case 'octagon': d = m * 0.29; return poly([[d, 0], [w - d, 0], [w, d], [w, h - d], [w - d, h], [d, h], [0, h - d], [0, d]]);
            case 'star5': return star(w, h, 5, 0.38);
            case 'star6': return star(w, h, 6, 0.58);
            case 'rightArrow': d = Math.min(h * 0.5, w * 0.6); return poly([[0, h * 0.25], [w - d, h * 0.25], [w - d, 0], [w, h / 2], [w - d, h], [w - d, h * 0.75], [0, h * 0.75]]);
            case 'leftArrow': d = Math.min(h * 0.5, w * 0.6); return poly([[w, h * 0.25], [d, h * 0.25], [d, 0], [0, h / 2], [d, h], [d, h * 0.75], [w, h * 0.75]]);
            case 'upArrow': d = Math.min(w * 0.5, h * 0.6); return poly([[w * 0.25, h], [w * 0.25, d], [0, d], [w / 2, 0], [w, d], [w * 0.75, d], [w * 0.75, h]]);
            case 'downArrow': d = Math.min(w * 0.5, h * 0.6); return poly([[w * 0.25, 0], [w * 0.75, 0], [w * 0.75, h - d], [w, h - d], [w / 2, h], [0, h - d], [w * 0.25, h - d]]);
            case 'leftRightArrow': d = Math.min(h * 0.5, w * 0.3); return poly([[0, h / 2], [d, 0], [d, h * 0.25], [w - d, h * 0.25], [w - d, 0], [w, h / 2], [w - d, h], [w - d, h * 0.75], [d, h * 0.75], [d, h]]);
            case 'chevron': d = Math.min(h * 0.5, w * 0.5); return poly([[0, 0], [w - d, 0], [w, h / 2], [w - d, h], [0, h], [d, h / 2]]);
            case 'homePlate': d = Math.min(h * 0.5, w * 0.5); return poly([[0, 0], [w - d, 0], [w, h / 2], [w - d, h], [0, h]]);
            case 'parallelogram': o = Math.min(h * 0.25 * (w / Math.max(h, 1)), w * 0.4, h * 0.5); return poly([[o, 0], [w, 0], [w - o, h], [0, h]]);
            case 'trapezoid': o = Math.min(h * 0.25, w * 0.4); return poly([[o, 0], [w - o, 0], [w, h], [0, h]]);
            case 'plus': t = m * 0.25; return poly([[w / 2 - t, 0], [w / 2 + t, 0], [w / 2 + t, h / 2 - t], [w, h / 2 - t], [w, h / 2 + t], [w / 2 + t, h / 2 + t],
                [w / 2 + t, h], [w / 2 - t, h], [w / 2 - t, h / 2 + t], [0, h / 2 + t], [0, h / 2 - t], [w / 2 - t, h / 2 - t]]);
            case 'heart': return 'M' + r2(w / 2) + ' ' + r2(h * 0.25) + ' C' + r2(w * 0.5) + ' ' + r2(h * -0.05) + ' ' + r2(w * -0.05) + ' ' + r2(h * 0.02) + ' ' + r2(w * 0.02) + ' ' + r2(h * 0.38)
                + ' C' + r2(w * 0.08) + ' ' + r2(h * 0.65) + ' ' + r2(w * 0.42) + ' ' + r2(h * 0.82) + ' ' + r2(w / 2) + ' ' + r2(h)
                + ' C' + r2(w * 0.58) + ' ' + r2(h * 0.82) + ' ' + r2(w * 0.92) + ' ' + r2(h * 0.65) + ' ' + r2(w * 0.98) + ' ' + r2(h * 0.38)
                + ' C' + r2(w * 1.05) + ' ' + r2(h * 0.02) + ' ' + r2(w * 0.5) + ' ' + r2(h * -0.05) + ' ' + r2(w / 2) + ' ' + r2(h * 0.25) + ' Z';
            case 'donut': d = m * 0.25; return ellipsePath(w / 2, h / 2, w / 2, h / 2) + ' ' + ellipsePath(w / 2, h / 2, Math.max(1, w / 2 - d), Math.max(1, h / 2 - d));
            case 'wedgeRectCallout': return poly([[0, 0], [w, 0], [w, h * 0.78], [w * 0.42, h * 0.78], [w * 0.2, h], [w * 0.25, h * 0.78], [0, h * 0.78]]);
            case 'wedgeRoundRectCallout':
                d = Math.min(m * 0.12, h * 0.2);
                return 'M' + r2(d) + ' 0 H' + r2(w - d) + ' Q' + r2(w) + ' 0 ' + r2(w) + ' ' + r2(d) + ' V' + r2(h * 0.78 - d) + ' Q' + r2(w) + ' ' + r2(h * 0.78) + ' ' + r2(w - d) + ' ' + r2(h * 0.78)
                    + ' H' + r2(w * 0.42) + ' L' + r2(w * 0.2) + ' ' + r2(h) + ' L' + r2(w * 0.25) + ' ' + r2(h * 0.78) + ' H' + r2(d) + ' Q0 ' + r2(h * 0.78) + ' 0 ' + r2(h * 0.78 - d) + ' V' + r2(d) + ' Q0 0 ' + r2(d) + ' 0 Z';
            case 'cloud':
                return 'M' + r2(w * 0.25) + ' ' + r2(h * 0.85) + ' A' + r2(w * 0.17) + ' ' + r2(h * 0.2) + ' 0 0 1 ' + r2(w * 0.16) + ' ' + r2(h * 0.45)
                    + ' A' + r2(w * 0.18) + ' ' + r2(h * 0.24) + ' 0 0 1 ' + r2(w * 0.42) + ' ' + r2(h * 0.18)
                    + ' A' + r2(w * 0.2) + ' ' + r2(h * 0.24) + ' 0 0 1 ' + r2(w * 0.78) + ' ' + r2(h * 0.25)
                    + ' A' + r2(w * 0.17) + ' ' + r2(h * 0.22) + ' 0 0 1 ' + r2(w * 0.86) + ' ' + r2(h * 0.7)
                    + ' A' + r2(w * 0.15) + ' ' + r2(h * 0.18) + ' 0 0 1 ' + r2(w * 0.62) + ' ' + r2(h * 0.9)
                    + ' A' + r2(w * 0.2) + ' ' + r2(h * 0.18) + ' 0 0 1 ' + r2(w * 0.25) + ' ' + r2(h * 0.85) + ' Z';
            case 'can': d = Math.min(h * 0.15, w * 0.3);
                return 'M0 ' + r2(d) + ' A' + r2(w / 2) + ' ' + r2(d) + ' 0 0 1 ' + r2(w) + ' ' + r2(d) + ' V' + r2(h - d) + ' A' + r2(w / 2) + ' ' + r2(d) + ' 0 0 1 0 ' + r2(h - d) + ' Z'
                    + ' M0 ' + r2(d) + ' A' + r2(w / 2) + ' ' + r2(d) + ' 0 0 0 ' + r2(w) + ' ' + r2(d);
            case 'cube': d = m * 0.25; return poly([[0, d], [d, 0], [w, 0], [w, h - d], [w - d, h], [0, h]]) + ' M0 ' + r2(d) + ' H' + r2(w - d) + ' L' + r2(w) + ' 0 M' + r2(w - d) + ' ' + r2(d) + ' V' + r2(h);
            case 'lightningBolt': return poly([[w * 0.39, 0], [w * 0.62, h * 0.32], [w * 0.52, h * 0.37], [w * 0.8, h * 0.62], [w * 0.7, h * 0.67], [w, h], [w * 0.38, h * 0.72], [w * 0.5, h * 0.66], [w * 0.15, h * 0.42], [w * 0.28, h * 0.36], [0, h * 0.14]]);
            case 'moon': return 'M' + r2(w) + ' 0 A' + r2(w) + ' ' + r2(h / 2) + ' 0 0 0 ' + r2(w) + ' ' + r2(h) + ' A' + r2(w * 0.5) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(w) + ' 0 Z';
            case 'sun':
                var rays = '', cx = w / 2, cy = h / 2;
                for (var i = 0; i < 8; i++) {
                    var a = i * Math.PI / 4, a1 = a - 0.16, a2 = a + 0.16;
                    rays += ' ' + poly([[cx + Math.cos(a) * cx, cy + Math.sin(a) * cy], [cx + Math.cos(a1) * cx * 0.68, cy + Math.sin(a1) * cy * 0.68], [cx + Math.cos(a2) * cx * 0.68, cy + Math.sin(a2) * cy * 0.68]]);
                }
                return ellipsePath(cx, cy, w * 0.28, h * 0.28) + rays;
            default: return roundRectPath(w, h, radius || 0);
        }
    }

    // ---- Текст ------------------------------------------------------------------------------------

    // Маркеры списков: «•» и номера «1.» в data-marker (показывает CSS ::before) — номер считается по
    // подряд идущим нумерованным абзацам одного уровня, как в PowerPoint.
    function numberLists(container) {
        var counters = [];
        Array.prototype.forEach.call(container.querySelectorAll('p'), function (p) {
            var list = p.getAttribute('data-list');
            var level = Math.min(4, parseInt(p.getAttribute('data-level') || '0', 10) || 0);
            if (list === 'number') {
                counters.length = level + 1;
                counters[level] = (counters[level] || 0) + 1;
                p.setAttribute('data-marker', counters[level] + '.');
            } else {
                if (list === 'bullet') { p.setAttribute('data-marker', '•'); } else { p.removeAttribute('data-marker'); }
                counters.length = list ? level : 0;
            }
            var indent = list === 'bullet' ? BULLET_INDENT : (list === 'number' ? NUMBER_INDENT : 0);
            p.style.paddingLeft = (indent + level * LEVEL_INDENT) ? (indent + level * LEVEL_INDENT) + 'px' : '';
            p.style.setProperty('--deck-marker-left', (level * LEVEL_INDENT) + 'px');
        });
    }

    function isBlankHtml(html) {
        if (!html) { return true; }
        return html.replace(/<[^>]+>/g, '').replace(/&nbsp;|\s/g, '') === '';
    }

    function textStyles(node, el) {
        var f = fontCss(el.fontFamily, el.fontWeight);
        var s = node.style;
        s.fontFamily = f.family;
        s.fontWeight = String(f.weight);
        s.fontStyle = el.italic ? 'italic' : 'normal';
        s.fontSize = num(el.fontSize, 16) + 'pt';
        s.color = color(el.color, '#262626');
        s.textAlign = el.align || 'left';
        s.lineHeight = String(Math.round(num(el.lineHeight, 1) * 1.2 * 1000) / 1000);
        s.letterSpacing = el.letterSpacing ? num(el.letterSpacing, 0) + 'pt' : '';
        s.textTransform = el.caps ? 'uppercase' : 'none';
        s.setProperty('--deck-space-before', num(el.spaceBefore, 0) + 'pt');
        s.setProperty('--deck-list-color', color(el.listColor || '#104382'));
    }

    function renderText(wrap, el, opts) {
        var box = document.createElement('div');
        box.className = 'deck-text deck-text--' + (el.valign || 'top');
        box.style.padding = num(el.padding, 0) + 'px';
        var body = document.createElement('div');
        body.className = 'deck-text__body';
        textStyles(body, el);
        body.innerHTML = el.html || '';
        numberLists(body);
        box.appendChild(body);
        if (opts.mode === 'edit' && el.placeholder && isBlankHtml(el.html)) {
            var ph = document.createElement('div');
            ph.className = 'deck-placeholder';
            textStyles(ph, el);
            ph.style.padding = num(el.padding, 0) + 'px';
            ph.textContent = el.placeholder;
            box.appendChild(ph);
        }
        if (opts.mode !== 'edit') {
            Array.prototype.forEach.call(body.querySelectorAll('a[href]'), function (a) { a.target = '_blank'; a.rel = 'noopener'; });
        }
        wrap.appendChild(box);
        return body;
    }

    // ---- Элементы ---------------------------------------------------------------------------------

    function svg(tag, attrs) {
        var n = document.createElementNS(SVG_NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { if (attrs[k] !== null && attrs[k] !== undefined) { n.setAttribute(k, attrs[k]); } });
        return n;
    }

    function dashArray(dash, width) {
        if (dash === 'dash') { return (width * 4) + ' ' + (width * 3); }
        if (dash === 'dot') { return width + ' ' + (width * 2); }
        return null;
    }

    var gradientSeq = 0;
    function paint(defs, el) {
        if (el.gradient && el.gradient.from && el.gradient.to) {
            var id = 'dg' + (++gradientSeq);
            var a = (num(el.gradient.angle, 180) - 90) * Math.PI / 180;
            var g = svg('linearGradient', { id: id, x1: r2(0.5 - Math.cos(a) / 2), y1: r2(0.5 - Math.sin(a) / 2), x2: r2(0.5 + Math.cos(a) / 2), y2: r2(0.5 + Math.sin(a) / 2) });
            g.appendChild(svg('stop', { offset: '0', 'stop-color': color(el.gradient.from) }));
            g.appendChild(svg('stop', { offset: '1', 'stop-color': color(el.gradient.to) }));
            defs.appendChild(g);
            return 'url(#' + id + ')';
        }
        return el.fill ? color(el.fill) : 'none';
    }

    function renderShapeBox(wrap, el) {
        var hasFill = !!(el.fill || el.gradient), hasStroke = !!(el.stroke && num(el.strokeWidth, 0) > 0);
        if (!hasFill && !hasStroke) { return; }
        var w = num(el.w, 100), h = num(el.h, 100), sw = hasStroke ? num(el.strokeWidth, 1) : 0;
        var kind = el.type === 'shape' ? (el.shape || 'rect') : 'rect';
        if ((kind === 'rect' || el.type === 'text') && !el.radius && !el.gradient) {
            // Обычный прямоугольник — фон и рамка средствами CSS (быстрее для миниатюр).
            wrap.style.background = hasFill ? color(el.fill) : '';
            if (hasStroke) { wrap.style.outline = sw + 'px ' + (el.dash === 'dot' ? 'dotted' : (el.dash === 'dash' ? 'dashed' : 'solid')) + ' ' + color(el.stroke); wrap.style.outlineOffset = -(sw / 2) + 'px'; }
            return;
        }
        var s = svg('svg', { class: 'deck-shape', width: w, height: h, viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none' });
        var defs = svg('defs');
        s.appendChild(defs);
        var path = svg('path', {
            d: shapePath(kind === 'rect' && el.radius ? 'roundRect' : kind, w, h, num(el.radius, 0)),
            fill: hasFill ? paint(defs, el) : 'none',
            stroke: hasStroke ? color(el.stroke) : 'none',
            'stroke-width': hasStroke ? sw : null,
            'stroke-dasharray': hasStroke ? dashArray(el.dash, sw) : null,
            'fill-rule': kind === 'donut' ? 'evenodd' : null,
            'vector-effect': 'non-scaling-stroke',
        });
        s.appendChild(path);
        wrap.appendChild(s);
    }

    function renderImage(wrap, el, opts) {
        var url = imageUrl(el.src);
        var w = num(el.w, 100), h = num(el.h, 100);
        if (el.mask === 'circle') { wrap.style.borderRadius = '50%'; } else if (el.radius) { wrap.style.borderRadius = num(el.radius, 0) + 'px'; }
        wrap.style.overflow = 'hidden';
        if (el.border && el.border.width) { wrap.style.boxShadow = 'inset 0 0 0 ' + num(el.border.width, 1) + 'px ' + color(el.border.color); }
        if (!url) {
            if (opts.mode === 'edit' || opts.mode === 'thumb') {
                var ph = document.createElement('div');
                ph.className = 'deck-image-placeholder';
                ph.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m4 16 4.5-5 4 4 2.5-2.5L20 17" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="15.5" cy="9" r="1.6" fill="currentColor"/></svg>'
                    + (opts.mode === 'edit' ? '<span>' + esc(el.name || 'Изображение') + '<br><small>двойной щелчок — выбрать файл</small></span>' : '');
                wrap.appendChild(ph);
            }
            return;
        }
        var img = document.createElement('img');
        img.className = 'deck-img';
        img.alt = el.alt || '';
        img.draggable = false;
        img.decoding = 'async';
        var place = function (iw, ih) {
            var g = fit(w, h, iw, ih, el.fit || 'cover', el.zoom, el.focusX, el.focusY);
            img.style.left = g.left + 'px'; img.style.top = g.top + 'px';
            img.style.width = g.width + 'px'; img.style.height = g.height + 'px';
            var t = [];
            if (el.flipH) { t.push('scaleX(-1)'); }
            if (el.flipV) { t.push('scaleY(-1)'); }
            img.style.transform = t.join(' ');
            img.style.visibility = 'visible';
        };
        var known = brandSize(el.src);
        if (known) { place(known.w, known.h); } else if (el.fit === 'fill') { place(0, 0); } else {
            img.style.visibility = 'hidden';
            img.addEventListener('load', function () { place(img.naturalWidth, img.naturalHeight); if (opts.onImageLoad) { opts.onImageLoad(el, img); } });
        }
        img.src = url;
        wrap.appendChild(img);
    }

    var markerSeq = 0;
    function marker(defs, type, stroke, start) {
        if (!type || type === 'none') { return null; }
        var id = 'dm' + (++markerSeq);
        var m = svg('marker', { id: id, viewBox: '0 0 10 10', refX: type === 'arrow' ? '8' : '5', refY: '5', markerWidth: '4', markerHeight: '4', orient: start ? 'auto-start-reverse' : 'auto', markerUnits: 'strokeWidth' });
        var shape;
        if (type === 'triangle') { shape = svg('path', { d: 'M0 0 L10 5 L0 10 Z', fill: stroke }); m.setAttribute('refX', '6'); }
        else if (type === 'arrow') { shape = svg('path', { d: 'M1 1 L9 5 L1 9', fill: 'none', stroke: stroke, 'stroke-width': '1.6', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }); }
        else if (type === 'oval') { shape = svg('circle', { cx: '5', cy: '5', r: '4', fill: stroke }); }
        else { shape = svg('path', { d: 'M5 0 L10 5 L5 10 L0 5 Z', fill: stroke }); }
        m.appendChild(shape);
        defs.appendChild(m);
        return 'url(#' + id + ')';
    }

    function renderLine(wrap, el) {
        var sw = num(el.strokeWidth, 2), pad = sw * 4 + 6;
        var x1 = num(el.x1, el.x), y1 = num(el.y1, el.y), x2 = num(el.x2, el.x + el.w), y2 = num(el.y2, el.y);
        var left = Math.min(x1, x2) - pad, top = Math.min(y1, y2) - pad;
        wrap.style.left = left + 'px'; wrap.style.top = top + 'px';
        wrap.style.width = (Math.abs(x2 - x1) + pad * 2) + 'px'; wrap.style.height = (Math.abs(y2 - y1) + pad * 2) + 'px';
        wrap.style.transform = '';
        var s = svg('svg', { class: 'deck-line', width: Math.abs(x2 - x1) + pad * 2, height: Math.abs(y2 - y1) + pad * 2 });
        var defs = svg('defs');
        s.appendChild(defs);
        var stroke = color(el.stroke, '#0F4382');
        s.appendChild(svg('line', {
            x1: x1 - left, y1: y1 - top, x2: x2 - left, y2: y2 - top,
            stroke: stroke, 'stroke-width': sw, 'stroke-linecap': el.dash === 'dot' ? 'round' : 'butt',
            'stroke-dasharray': dashArray(el.dash, sw),
            'marker-start': marker(defs, el.start, stroke, true), 'marker-end': marker(defs, el.end, stroke, false),
        }));
        wrap.appendChild(s);
    }

    function renderTable(wrap, el, opts) {
        var st = el.style || {};
        var table = document.createElement('table');
        table.className = 'deck-table';
        var f = fontCss(st.fontFamily || 'Roboto', 400);
        table.style.fontFamily = f.family;
        table.style.fontSize = num(st.fontSize, 16) + 'pt';
        table.style.color = color(st.color, '#262626');
        var cols = el.cols || [], totalW = cols.reduce(function (a, b) { return a + num(b, 1); }, 0) || 1;
        var heights = el.heights || [], totalH = heights.reduce(function (a, b) { return a + num(b, 1); }, 0) || 1;
        var cg = document.createElement('colgroup');
        cols.forEach(function (cw) { var c = document.createElement('col'); c.style.width = (100 * num(cw, 1) / totalW) + '%'; cg.appendChild(c); });
        table.appendChild(cg);
        var bw = num(st.borderWidth, 1), border = bw > 0 ? bw + 'px solid ' + color(st.borderColor, '#BFBFBF') : 'none';
        (el.rows || []).forEach(function (row, ri) {
            var tr = document.createElement('tr');
            tr.style.height = (num(el.h, 100) * num(heights[ri], 1) / totalH) + 'px';
            row.forEach(function (cell, ci) {
                if (cell.hidden) { return; }
                var td = document.createElement('td');
                var header = st.header && ri === 0, first = st.firstCol && ci === 0 && !header;
                var fill = cell.fill || (header ? st.headerFill : (first ? st.firstColFill : (st.banded && ri % 2 === (st.header ? 0 : 1) ? st.bandFill : st.fill)));
                td.style.background = color(fill, 'transparent');
                td.style.color = color(cell.color || (header ? st.headerColor : (first ? st.firstColColor : null)), '');
                td.style.border = border;
                td.style.padding = (num(st.padding, 8) / 2) + 'px ' + num(st.padding, 8) + 'px';
                td.style.textAlign = cell.align || st.align || 'center';
                td.style.fontWeight = cell.bold || header ? '700' : '400';
                td.style.fontStyle = cell.italic ? 'italic' : 'normal';
                if (cell.colspan > 1) { td.colSpan = cell.colspan; }
                if (cell.rowspan > 1) { td.rowSpan = cell.rowspan; }
                td.setAttribute('data-r', ri); td.setAttribute('data-c', ci);
                td.textContent = cell.text || '';
                tr.appendChild(td);
            });
            table.appendChild(tr);
        });
        wrap.appendChild(table);
        return table;
    }

    // ---- Диаграммы --------------------------------------------------------------------------------

    function niceStep(range, ticks) {
        var raw = range / Math.max(1, ticks), mag = Math.pow(10, Math.floor(Math.log10(raw || 1)));
        var n = raw / mag;
        return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * mag;
    }

    function fmt(v) {
        if (v === null || v === undefined || !isFinite(v)) { return ''; }
        var a = Math.abs(v), s = a >= 100 || Math.round(v) === v ? String(Math.round(v * 100) / 100) : String(Math.round(v * 100) / 100);
        return s.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function renderChart(wrap, el) {
        var w = num(el.w, 400), h = num(el.h, 300), fs = num(el.fontSize, 12) * 4 / 3;
        var s = svg('svg', { class: 'deck-chart', width: w, height: h, viewBox: '0 0 ' + w + ' ' + h });
        var txt = color(el.color, '#383838'), grid = color(el.gridColor, '#D9D9D9');
        var font = "'Roboto', Arial, sans-serif";
        var cats = el.categories || [], series = el.series || [];
        var colors = series.map(function (sr, i) { return color(sr.color || config.chartColors[i % config.chartColors.length]); });
        var top = 6, bottom = h - 6, left = 6, right = w - 6;
        function text(x, y, value, attrs) {
            var t = svg('text', Object.assign({ x: r2(x), y: r2(y), fill: txt, 'font-family': font, 'font-size': r2(fs) }, attrs || {}));
            t.textContent = value;
            s.appendChild(t);
            return t;
        }
        if (el.title) { text(w / 2, top + fs * 1.2, el.title, { 'text-anchor': 'middle', 'font-size': r2(fs * 1.25), 'font-weight': '500' }); top += fs * 1.9; }
        var pie = el.chart === 'pie' || el.chart === 'doughnut';
        var legendItems = pie ? cats : series.map(function (sr) { return sr.name; });
        var legendColors = pie ? cats.map(function (c, i) { return color((el.pointColors || [])[i] || config.chartColors[i % config.chartColors.length]); }) : colors;
        if (el.legend && el.legend !== 'none' && legendItems.length) {
            if (el.legend === 'right') {
                var lx = w - Math.min(w * 0.3, 200), ly = top + 8;
                legendItems.forEach(function (name, i) {
                    s.appendChild(svg('rect', { x: lx, y: ly + i * fs * 1.6, width: fs * 0.8, height: fs * 0.8, fill: legendColors[i] }));
                    text(lx + fs * 1.2, ly + i * fs * 1.6 + fs * 0.75, name);
                });
                right = lx - 12;
            } else {
                var widths = legendItems.map(function (n) { return fs * 1.6 + String(n).length * fs * 0.55 + 12; });
                var total = widths.reduce(function (a, b) { return a + b; }, 0), x = Math.max(left, (w - total) / 2);
                var y = el.legend === 'top' ? top + fs : bottom - fs * 0.3;
                legendItems.forEach(function (name, i) {
                    s.appendChild(svg('rect', { x: r2(x), y: r2(y - fs * 0.75), width: r2(fs * 0.8), height: r2(fs * 0.8), fill: legendColors[i] }));
                    text(x + fs * 1.2, y, name);
                    x += widths[i];
                });
                if (el.legend === 'top') { top += fs * 2; } else { bottom -= fs * 2; }
            }
        }
        if (pie) {
            var vals = (series[0] && series[0].values || []).map(function (v) { return Math.max(0, num(v, 0)); });
            var sum = vals.reduce(function (a, b) { return a + b; }, 0);
            var cx = (left + right) / 2, cy = (top + bottom) / 2, rr = Math.max(4, Math.min(right - left, bottom - top) / 2 - 4), inner = el.chart === 'doughnut' ? rr * 0.55 : 0;
            var ang = -Math.PI / 2;
            if (sum <= 0) { s.appendChild(svg('circle', { cx: cx, cy: cy, r: rr, fill: 'none', stroke: grid })); }
            vals.forEach(function (v, i) {
                if (v <= 0 || sum <= 0) { return; }
                var a2 = ang + v / sum * Math.PI * 2, large = a2 - ang > Math.PI ? 1 : 0;
                var p = function (a, rad) { return r2(cx + Math.cos(a) * rad) + ' ' + r2(cy + Math.sin(a) * rad); };
                var d = vals.filter(function (x) { return x > 0; }).length === 1
                    ? ellipsePath(cx, cy, rr, rr) + (inner ? ' ' + ellipsePath(cx, cy, inner, inner) : '')
                    : 'M' + p(ang, rr) + ' A' + r2(rr) + ' ' + r2(rr) + ' 0 ' + large + ' 1 ' + p(a2, rr)
                        + (inner ? ' L' + p(a2, inner) + ' A' + r2(inner) + ' ' + r2(inner) + ' 0 ' + large + ' 0 ' + p(ang, inner) + ' Z' : ' L' + r2(cx) + ' ' + r2(cy) + ' Z');
                s.appendChild(svg('path', { d: d, fill: legendColors[i], stroke: '#FFFFFF', 'stroke-width': '1.5', 'fill-rule': 'evenodd' }));
                if (el.labels) {
                    var mid = (ang + a2) / 2, lr = inner ? (rr + inner) / 2 : rr * 0.65;
                    text(cx + Math.cos(mid) * lr, cy + Math.sin(mid) * lr + fs * 0.35, Math.round(v / sum * 100) + '%', { 'text-anchor': 'middle', fill: '#FFFFFF', 'font-weight': '500' });
                }
                ang = a2;
            });
            wrap.appendChild(s);
            return;
        }
        // Оси: значения слева (или снизу для «полос»), категории снизу (слева).
        var stacked = !!el.stacked && el.chart !== 'line';
        var min = 0, max = 0;
        cats.forEach(function (c, ci) {
            var pos = 0, neg = 0;
            series.forEach(function (sr) {
                var v = num((sr.values || [])[ci], 0);
                if (stacked) { if (v >= 0) { pos += v; } else { neg += v; } } else { max = Math.max(max, v); min = Math.min(min, v); }
            });
            if (stacked) { max = Math.max(max, pos); min = Math.min(min, neg); }
        });
        if (max === min) { max = min + 1; }
        var step = niceStep(max - min, 5);
        max = Math.ceil(max / step) * step; min = Math.floor(min / step) * step;
        var horizontal = el.chart === 'bar';
        var labelW = Math.max.apply(null, [fmt(max), fmt(min)].map(function (t) { return t.length * fs * 0.55; })) + 8;
        var catW = horizontal ? Math.min((right - left) * 0.35, Math.max.apply(null, cats.map(function (c) { return String(c).length * fs * 0.55; }).concat([20])) + 8) : 0;
        var plot = { x: left + (horizontal ? catW : labelW), y: top + fs * 0.6, w: 0, h: 0 };
        plot.w = right - plot.x - 4;
        plot.h = bottom - plot.y - fs * 1.8;
        if (plot.w < 10 || plot.h < 10) { wrap.appendChild(s); return; }
        var scale = function (v) { return (v - min) / (max - min); };
        for (var t = min; t <= max + step / 2; t += step) {
            var k = scale(t);
            if (horizontal) {
                var gx = plot.x + plot.w * k;
                s.appendChild(svg('line', { x1: r2(gx), y1: plot.y, x2: r2(gx), y2: plot.y + plot.h, stroke: grid, 'stroke-width': '1' }));
                text(gx, plot.y + plot.h + fs * 1.3, fmt(t), { 'text-anchor': 'middle' });
            } else {
                var gy = plot.y + plot.h * (1 - k);
                s.appendChild(svg('line', { x1: plot.x, y1: r2(gy), x2: plot.x + plot.w, y2: r2(gy), stroke: grid, 'stroke-width': '1' }));
                text(plot.x - 6, gy + fs * 0.35, fmt(t), { 'text-anchor': 'end' });
            }
        }
        var n = Math.max(1, cats.length), band = (horizontal ? plot.h : plot.w) / n;
        cats.forEach(function (c, ci) {
            var center = (horizontal ? plot.y : plot.x) + band * (ci + 0.5);
            if (horizontal) { text(plot.x - 6, center + fs * 0.35, c, { 'text-anchor': 'end' }); }
            else { text(center, plot.y + plot.h + fs * 1.3, c, { 'text-anchor': 'middle' }); }
        });
        var zero = scale(Math.max(min, Math.min(max, 0)));
        if (el.chart === 'line' || el.chart === 'area') {
            series.forEach(function (sr, si) {
                var pts = [];
                cats.forEach(function (c, ci) {
                    var v = (sr.values || [])[ci];
                    if (v === null || v === undefined || !isFinite(v)) { return; }
                    pts.push([plot.x + band * (ci + 0.5), plot.y + plot.h * (1 - scale(num(v, 0)))]);
                });
                if (!pts.length) { return; }
                var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + r2(p[0]) + ' ' + r2(p[1]); }).join(' ');
                if (el.chart === 'area') {
                    var base = plot.y + plot.h * (1 - zero);
                    s.appendChild(svg('path', { d: d + ' L' + r2(pts[pts.length - 1][0]) + ' ' + r2(base) + ' L' + r2(pts[0][0]) + ' ' + r2(base) + ' Z', fill: colors[si], 'fill-opacity': '0.35' }));
                }
                s.appendChild(svg('path', { d: d, fill: 'none', stroke: colors[si], 'stroke-width': '2.5', 'stroke-linejoin': 'round' }));
                pts.forEach(function (p, i) {
                    if (el.chart === 'line') { s.appendChild(svg('circle', { cx: r2(p[0]), cy: r2(p[1]), r: '3.5', fill: colors[si] })); }
                    if (el.labels) { text(p[0], p[1] - 8, fmt(num((sr.values || [])[i], 0)), { 'text-anchor': 'middle' }); }
                });
            });
            wrap.appendChild(s);
            return;
        }
        var groups = stacked ? 1 : Math.max(1, series.length), gap = band * 0.25, barW = (band - gap) / groups;
        cats.forEach(function (c, ci) {
            var pos = 0, neg = 0;
            series.forEach(function (sr, si) {
                var raw = (sr.values || [])[ci];
                if (raw === null || raw === undefined || !isFinite(raw)) { return; }
                var v = num(raw, 0), from, to;
                if (stacked) { if (v >= 0) { from = pos; pos += v; to = pos; } else { from = neg; neg += v; to = neg; } } else { from = 0; to = v; }
                var a = scale(Math.min(from, to)), b = scale(Math.max(from, to));
                var offset = (horizontal ? plot.y : plot.x) + band * ci + gap / 2 + (stacked ? 0 : barW * si);
                var rect = horizontal
                    ? { x: plot.x + plot.w * a, y: offset, width: Math.max(0.5, plot.w * (b - a)), height: barW }
                    : { x: offset, y: plot.y + plot.h * (1 - b), width: barW, height: Math.max(0.5, plot.h * (b - a)) };
                s.appendChild(svg('rect', { x: r2(rect.x), y: r2(rect.y), width: r2(rect.width), height: r2(rect.height), fill: (el.pointColors || [])[ci] && series.length === 1 ? color(el.pointColors[ci]) : colors[si] }));
                if (el.labels) {
                    if (horizontal) { text(rect.x + rect.width + 4, rect.y + rect.height / 2 + fs * 0.35, fmt(v)); }
                    else { text(rect.x + rect.width / 2, rect.y - 4, fmt(v), { 'text-anchor': 'middle' }); }
                }
            });
        });
        var axis = horizontal ? { x1: plot.x + plot.w * zero, y1: plot.y, x2: plot.x + plot.w * zero, y2: plot.y + plot.h } : { x1: plot.x, y1: plot.y + plot.h * (1 - zero), x2: plot.x + plot.w, y2: plot.y + plot.h * (1 - zero) };
        s.appendChild(svg('line', { x1: r2(axis.x1), y1: r2(axis.y1), x2: r2(axis.x2), y2: r2(axis.y2), stroke: '#8C8C8C', 'stroke-width': '1' }));
        wrap.appendChild(s);
    }

    // ---- Слайд ------------------------------------------------------------------------------------

    function element(el, opts) {
        opts = opts || {};
        var wrap = document.createElement('div');
        wrap.className = 'deck-el deck-el--' + el.type;
        wrap.setAttribute('data-id', el.id);
        wrap.style.left = num(el.x, 0) + 'px';
        wrap.style.top = num(el.y, 0) + 'px';
        wrap.style.width = num(el.w, 100) + 'px';
        wrap.style.height = num(el.h, 100) + 'px';
        if (el.rotation) { wrap.style.transform = 'rotate(' + num(el.rotation, 0) + 'deg)'; }
        if (el.opacity !== undefined && el.opacity < 1) { wrap.style.opacity = String(el.opacity); }
        if (el.shadow) {
            wrap.style.filter = 'drop-shadow(' + num(el.shadow.x, 0) + 'px ' + num(el.shadow.y, 4) + 'px ' + (num(el.shadow.blur, 12) / 2) + 'px ' + color(el.shadow.color, 'rgba(0,0,0,.25)') + ')';
        }
        switch (el.type) {
            case 'text':
            case 'shape':
                renderShapeBox(wrap, el);
                renderText(wrap, el, opts);
                break;
            case 'image': renderImage(wrap, el, opts); break;
            case 'line': renderLine(wrap, el); break;
            case 'table': renderTable(wrap, el, opts); break;
            case 'chart': renderChart(wrap, el); break;
            case 'qr':
                var q = document.createElement('img');
                q.className = 'deck-qr'; q.alt = el.text ? 'QR-код: ' + el.text : 'QR-код'; q.draggable = false;
                if (el.text) { q.src = qrUrl(el); } else { wrap.classList.add('deck-el--empty'); }
                wrap.appendChild(q);
                break;
        }
        if (el.link && opts.mode === 'present') { wrap.classList.add('deck-el--link'); wrap.setAttribute('data-link', JSON.stringify(el.link)); }
        if (el.anim && opts.mode === 'present') {
            wrap.setAttribute('data-anim', el.anim.type);
            wrap.setAttribute('data-anim-order', String(el.anim.order || 0));
            wrap.style.setProperty('--anim-delay', num(el.anim.delay, 0) + 's');
            wrap.style.setProperty('--anim-duration', num(el.anim.duration, 0.6) + 's');
        }
        return wrap;
    }

    function slide(s, opts) {
        opts = opts || {};
        var node = document.createElement('div');
        node.className = 'deck-slide' + (opts.mode ? ' deck-slide--' + opts.mode : '');
        node.setAttribute('data-slide', s.id);
        var bg = s.background || {};
        node.style.backgroundColor = color(bg.color, '#FFFFFF');
        var bgUrl = imageUrl(bg.image);
        if (bgUrl) {
            node.style.backgroundImage = 'url("' + bgUrl.replace(/"/g, '%22') + '")';
            node.style.backgroundSize = bg.fit === 'contain' ? 'contain' : 'cover';
            node.style.backgroundPosition = 'center';
            node.style.backgroundRepeat = 'no-repeat';
        }
        (s.elements || []).forEach(function (el) { node.appendChild(element(el, opts)); });
        return node;
    }

    // Слайд в контейнере любой ширины: масштаб подбирается по ширине (и при её изменении).
    var observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(function (entries) {
        entries.forEach(function (e) { rescale(e.target); });
    }) : null;

    function rescale(box) {
        var inner = box.firstElementChild;
        if (!inner) { return; }
        var k = box.clientWidth / W;
        inner.style.transform = 'scale(' + k + ')';
    }

    function mount(box, s, opts) {
        box.classList.add('deck-frame');
        box.innerHTML = '';
        var node = slide(s, opts || { mode: 'thumb' });
        box.appendChild(node);
        rescale(box);
        if (observer) { observer.observe(box); } else { window.addEventListener('resize', function () { rescale(box); }); }
        return node;
    }

    // Все миниатюры страницы: <div data-deck-slide='{json}'>.
    function mountAll(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('[data-deck-slide]'), function (box) {
            try { mount(box, JSON.parse(box.getAttribute('data-deck-slide')), { mode: 'thumb' }); } catch (e) { /* пустая миниатюра */ }
        });
    }

    window.DeckRender = {
        W: W, H: H,
        configure: configure,
        slide: slide,
        element: element,
        mount: mount,
        mountAll: mountAll,
        rescale: rescale,
        fit: fit,
        shapePath: shapePath,
        numberLists: numberLists,
        textStyles: textStyles,
        imageUrl: imageUrl,
        qrUrl: qrUrl,
        color: color,
        esc: esc,
        isBlankHtml: isBlankHtml,
        config: config,
    };

    // Настройки и миниатюры со страницы: <script type="application/json" id="deck-config">.
    var cfgNode = document.getElementById('deck-config');
    if (cfgNode) {
        try { configure(JSON.parse(cfgNode.textContent)); } catch (e) { /* без настроек */ }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { mountAll(); }); } else { mountAll(); }
})();
