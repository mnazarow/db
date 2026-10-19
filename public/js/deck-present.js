// Показ презентации на весь экран: переходы между слайдами, появление элементов по шагам, заметки
// докладчика в отдельном окне (S), обзор слайдов (O), указка (L), чёрный и белый экран (B, W), переход по
// номеру, ссылки на слайды и сайты, жесты на сенсорных экранах.
(function () {
    'use strict';

    var root = document.querySelector('[data-deck-show]');
    var R = window.DeckRender;
    if (!root || !R) { return; }
    var deck = JSON.parse(document.getElementById('deck-data').textContent);
    var all = deck.slides || [];
    var speakerMode = root.getAttribute('data-speaker') === '1';
    var back = root.getAttribute('data-back') || '/';
    var channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(root.getAttribute('data-channel')) : null;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Показываются только не скрытые слайды; номер в адресе (?slide=N) — номер в колоде.
    var slides = all.filter(function (s) { return !s.hidden; });
    if (!slides.length) { slides = all.slice(0, 1); }
    var startDeckIndex = parseInt(root.getAttribute('data-start') || '0', 10) || 0;
    var index = 0;
    for (var i = 0; i < slides.length; i++) { if (all.indexOf(slides[i]) >= startDeckIndex) { index = i; break; } }
    var step = 0;            // сколько групп анимации уже показано на текущем слайде
    var blank = null;        // 'black' | 'white'
    var laser = false;
    var startedAt = Date.now();

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html !== undefined) { n.innerHTML = html; }
        return n;
    }

    // Группы появления: элементы с анимацией по «порядку»; одинаковый порядок — вместе.
    function groups(slide) {
        var orders = [];
        (slide.elements || []).forEach(function (e) {
            if (e.anim && orders.indexOf(e.anim.order || 0) < 0) { orders.push(e.anim.order || 0); }
        });
        return orders.sort(function (a, b) { return a - b; });
    }

    // ---- Окно докладчика -------------------------------------------------------------------------

    if (speakerMode) {
        document.title = 'Заметки — ' + document.title;
        var sp = el('div', 'deck-speaker');
        sp.innerHTML = '<div class="deck-speaker__main"><div class="deck-speaker__label">Сейчас</div><div class="deck-speaker__current" data-cur></div></div>'
            + '<div class="deck-speaker__side"><div class="deck-speaker__label">Дальше</div><div class="deck-speaker__next" data-next></div>'
            + '<div class="deck-speaker__timer"><span data-timer>00:00</span><button type="button" data-reset title="Сбросить время">↺</button><span class="deck-speaker__clock" data-clock></span></div>'
            + '<div class="deck-speaker__nav"><button type="button" data-prev>← Назад</button><span data-count></span><button type="button" data-fwd>Вперёд →</button></div>'
            + '<div class="deck-speaker__label">Заметки</div><div class="deck-speaker__notes" data-notes></div></div>';
        root.appendChild(sp);
        var cur = sp.querySelector('[data-cur]'), nxt = sp.querySelector('[data-next]');
        var renderSpeaker = function () {
            R.mount(cur, slides[index], { mode: 'thumb' });
            if (slides[index + 1]) { R.mount(nxt, slides[index + 1], { mode: 'thumb' }); } else { nxt.innerHTML = '<div class="deck-speaker__end">Конец презентации</div>'; nxt.classList.remove('deck-frame'); }
            sp.querySelector('[data-notes]').textContent = slides[index].notes || 'Заметок к этому слайду нет.';
            sp.querySelector('[data-count]').textContent = (index + 1) + ' / ' + slides.length + (groups(slides[index]).length ? ' · шаг ' + step + ' из ' + groups(slides[index]).length : '');
        };
        var tick = function () {
            var s = Math.floor((Date.now() - startedAt) / 1000);
            sp.querySelector('[data-timer]').textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
            var d = new Date();
            sp.querySelector('[data-clock]').textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        };
        setInterval(tick, 1000); tick();
        sp.querySelector('[data-reset]').addEventListener('click', function () { startedAt = Date.now(); tick(); });
        sp.querySelector('[data-prev]').addEventListener('click', function () { channel && channel.postMessage({ cmd: 'prev' }); });
        sp.querySelector('[data-fwd]').addEventListener('click', function () { channel && channel.postMessage({ cmd: 'next' }); });
        document.addEventListener('keydown', function (e) {
            if (['ArrowRight', 'ArrowDown', 'PageDown', ' ', 'Enter'].indexOf(e.key) >= 0) { e.preventDefault(); channel && channel.postMessage({ cmd: 'next' }); }
            if (['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace'].indexOf(e.key) >= 0) { e.preventDefault(); channel && channel.postMessage({ cmd: 'prev' }); }
        });
        if (channel) {
            channel.onmessage = function (m) {
                if (m.data && m.data.cmd === 'state') { index = Math.min(slides.length - 1, m.data.index); step = m.data.step; renderSpeaker(); }
            };
            channel.postMessage({ cmd: 'hello' });
        }
        renderSpeaker();
        return;
    }

    // ---- Показ -----------------------------------------------------------------------------------

    var stage = el('div', 'deck-show__stage');
    var viewport = el('div', 'deck-show__viewport');
    stage.appendChild(viewport);
    var progress = el('div', 'deck-show__progress', '<span></span>');
    var cover = el('div', 'deck-show__blank');
    var dot = el('div', 'deck-show__laser');
    var hud = el('div', 'deck-show__hud');
    hud.innerHTML = '<button type="button" data-act="prev" title="Назад (←)" aria-label="Назад">‹</button>'
        + '<span class="deck-show__count" data-count></span>'
        + '<button type="button" data-act="next" title="Вперёд (→, пробел)" aria-label="Вперёд">›</button>'
        + '<span class="deck-show__sep"></span>'
        + '<button type="button" data-act="overview" title="Все слайды (O)">Слайды</button>'
        + '<button type="button" data-act="speaker" title="Заметки докладчика в отдельном окне (S)">Заметки</button>'
        + '<button type="button" data-act="laser" title="Указка (L)">Указка</button>'
        + '<button type="button" data-act="full" title="Во весь экран (F)">Экран</button>'
        + '<button type="button" data-act="help" title="Клавиши (?)">?</button>'
        + '<button type="button" data-act="exit" title="Выйти из показа (Esc)">Выйти</button>';
    var help = el('div', 'deck-show__help', '<div><h2>Клавиши показа</h2><dl>'
        + '<dt>→ ↓ пробел PgDn, щелчок</dt><dd>дальше (следующий шаг или слайд)</dd>'
        + '<dt>← ↑ PgUp</dt><dd>назад</dd><dt>Home / End</dt><dd>первый / последний слайд</dd>'
        + '<dt>номер + Enter</dt><dd>перейти к слайду</dd><dt>O</dt><dd>все слайды</dd>'
        + '<dt>S</dt><dd>заметки докладчика в отдельном окне</dd><dt>L</dt><dd>указка</dd>'
        + '<dt>B или .</dt><dd>чёрный экран</dd><dt>W</dt><dd>белый экран</dd><dt>F</dt><dd>во весь экран</dd>'
        + '<dt>Esc</dt><dd>выйти</dd></dl><button type="button" data-act="help">Понятно</button></div>');
    var overview = el('div', 'deck-show__overview');
    root.appendChild(stage); root.appendChild(progress); root.appendChild(cover); root.appendChild(dot); root.appendChild(hud); root.appendChild(help); root.appendChild(overview);

    var current = null;

    function layout() {
        var k = Math.min(window.innerWidth / R.W, window.innerHeight / R.H);
        viewport.style.width = R.W + 'px'; viewport.style.height = R.H + 'px';
        viewport.style.transform = 'translate(-50%, -50%) scale(' + k + ')';
    }

    function applyStep(node, slide, animate) {
        var orders = groups(slide);
        Array.prototype.forEach.call(node.querySelectorAll('[data-anim]'), function (n) {
            var order = parseInt(n.getAttribute('data-anim-order') || '0', 10);
            var pos = orders.indexOf(order);
            var shown = pos < step;
            n.classList.toggle('is-shown', shown);
            n.classList.toggle('is-instant', !animate || reduced);
        });
    }

    function show(newIndex, opts) {
        opts = opts || {};
        if (newIndex < 0 || newIndex >= slides.length) { return; }
        var slide = slides[newIndex];
        var forward = newIndex >= index;
        index = newIndex;
        step = opts.lastStep ? groups(slide).length : 0;
        var node = R.slide(slide, { mode: 'present' });
        var transition = reduced || opts.instant ? 'none' : (slide.transition || 'none');
        node.classList.add('deck-show__slide', 'is-' + transition, forward ? 'is-forward' : 'is-back');
        applyStep(node, slide, false);
        viewport.appendChild(node);
        var old = current;
        current = node;
        if (transition !== 'none') {
            node.classList.add('is-entering');
            requestAnimationFrame(function () { requestAnimationFrame(function () { node.classList.remove('is-entering'); }); });
            if (old) { old.classList.add('is-leaving'); setTimeout(function () { old.remove(); }, 700); }
        } else if (old) { old.remove(); }
        update();
        // Слайды рядом — заранее, чтобы картинки были в кэше браузера.
        [newIndex + 1, newIndex - 1].forEach(function (k) { if (slides[k]) { R.slide(slides[k], { mode: 'thumb' }); } });
    }

    function update() {
        hud.querySelector('[data-count]').textContent = (index + 1) + ' / ' + slides.length;
        progress.firstChild.style.width = (slides.length > 1 ? (index / (slides.length - 1)) * 100 : 100) + '%';
        try { history.replaceState(null, '', location.pathname + '?slide=' + (all.indexOf(slides[index]) + 1)); } catch (e) { /* file: */ }
        if (channel) { channel.postMessage({ cmd: 'state', index: index, step: step }); }
    }

    function next() {
        if (blank) { setBlank(null); return; }
        var slide = slides[index];
        if (step < groups(slide).length) { step++; applyStep(current, slide, true); update(); return; }
        if (index < slides.length - 1) { show(index + 1); }
    }

    function prev() {
        if (blank) { setBlank(null); return; }
        if (step > 0) { step--; applyStep(current, slides[index], false); update(); return; }
        if (index > 0) { show(index - 1, { lastStep: true }); }
    }

    function setBlank(kind) {
        blank = blank === kind ? null : kind;
        cover.className = 'deck-show__blank' + (blank ? ' is-' + blank : '');
    }

    function toggleFull() {
        if (document.fullscreenElement) { document.exitFullscreen(); } else if (root.requestFullscreen) { root.requestFullscreen().catch(function () { /* запрещено */ }); }
    }

    function openSpeaker() {
        var url = location.pathname + '?speaker=1&slide=' + (all.indexOf(slides[index]) + 1);
        window.open(url, 'deck-speaker', 'width=1100,height=720');
    }

    function toggleOverview(force) {
        var open = force !== undefined ? force : !overview.classList.contains('is-open');
        overview.classList.toggle('is-open', open);
        if (open) {
            overview.innerHTML = '';
            slides.forEach(function (s, k) {
                var b = el('button', 'deck-show__thumb' + (k === index ? ' is-current' : ''));
                b.type = 'button';
                var frame = el('div');
                b.appendChild(frame);
                b.appendChild(el('span', '', String(k + 1)));
                b.addEventListener('click', function () { toggleOverview(false); show(k, { instant: true }); });
                overview.appendChild(b);
                R.mount(frame, s, { mode: 'thumb' });
            });
            var cur = overview.querySelector('.is-current');
            if (cur) { cur.scrollIntoView({ block: 'center' }); cur.focus(); }
        }
    }

    function exit() {
        if (document.fullscreenElement) { document.exitFullscreen(); }
        location.href = back;
    }

    var typed = '';
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) { return; }
        var k = e.key;
        if (overview.classList.contains('is-open')) {
            if (k === 'Escape' || k === 'o' || k === 'O' || k === 'щ' || k === 'Щ') { toggleOverview(false); e.preventDefault(); }
            return;
        }
        if (/^[0-9]$/.test(k)) { typed += k; return; }
        if (k === 'Enter' && typed) { show(Math.max(0, Math.min(slides.length - 1, parseInt(typed, 10) - 1)), { instant: true }); typed = ''; e.preventDefault(); return; }
        typed = '';
        switch (k) {
            case 'ArrowRight': case 'ArrowDown': case 'PageDown': case ' ': case 'Enter': e.preventDefault(); next(); break;
            case 'ArrowLeft': case 'ArrowUp': case 'PageUp': case 'Backspace': e.preventDefault(); prev(); break;
            case 'Home': show(0, { instant: true }); break;
            case 'End': show(slides.length - 1, { instant: true }); break;
            case 'b': case 'B': case 'и': case 'И': case '.': setBlank('black'); break;
            case 'w': case 'W': case 'ц': case 'Ц': setBlank('white'); break;
            case 'f': case 'F': case 'а': case 'А': toggleFull(); break;
            case 's': case 'S': case 'ы': case 'Ы': openSpeaker(); break;
            case 'l': case 'L': case 'д': case 'Д': toggleLaser(); break;
            case 'o': case 'O': case 'щ': case 'Щ': case 'g': case 'G': toggleOverview(); break;
            case '?': help.classList.toggle('is-open'); break;
            case 'Escape':
                if (help.classList.contains('is-open')) { help.classList.remove('is-open'); break; }
                if (!document.fullscreenElement) { exit(); }
                break;
        }
    });

    function toggleLaser() {
        laser = !laser;
        root.classList.toggle('is-laser', laser);
        hud.querySelector('[data-act="laser"]').classList.toggle('is-on', laser);
    }

    root.addEventListener('mousemove', function (e) {
        if (laser) { dot.style.left = e.clientX + 'px'; dot.style.top = e.clientY + 'px'; }
        root.classList.add('is-active');
        clearTimeout(root._idle);
        root._idle = setTimeout(function () { root.classList.remove('is-active'); }, 2200);
    });

    hud.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (!b) { return; }
        e.stopPropagation();
        var act = b.getAttribute('data-act');
        if (act === 'prev') { prev(); } else if (act === 'next') { next(); } else if (act === 'overview') { toggleOverview(); }
        else if (act === 'speaker') { openSpeaker(); } else if (act === 'laser') { toggleLaser(); } else if (act === 'full') { toggleFull(); }
        else if (act === 'help') { help.classList.toggle('is-open'); } else if (act === 'exit') { exit(); }
    });
    help.addEventListener('click', function (e) { if (e.target.closest('[data-act="help"]') || e.target === help) { help.classList.remove('is-open'); } });

    stage.addEventListener('click', function (e) {
        var link = e.target.closest('[data-link]');
        if (link) {
            try {
                var l = JSON.parse(link.getAttribute('data-link'));
                if (l.url) { window.open(l.url, '_blank', 'noopener'); return; }
                if (l.slide) {
                    for (var j = 0; j < slides.length; j++) { if (slides[j].id === l.slide) { show(j); return; } }
                }
            } catch (err) { /* ссылка без цели */ }
        }
        if (e.target.closest('a[href]')) { return; }
        if (e.clientX < window.innerWidth / 4) { prev(); } else { next(); }
    });

    var touchX = null;
    stage.addEventListener('touchstart', function (e) { touchX = e.touches[0].clientX; }, { passive: true });
    stage.addEventListener('touchend', function (e) {
        if (touchX === null) { return; }
        var dx = e.changedTouches[0].clientX - touchX;
        touchX = null;
        if (Math.abs(dx) > 40) { if (dx < 0) { next(); } else { prev(); } }
    });

    if (channel) {
        channel.onmessage = function (m) {
            if (!m.data) { return; }
            if (m.data.cmd === 'next') { next(); } else if (m.data.cmd === 'prev') { prev(); } else if (m.data.cmd === 'hello') { update(); }
        };
    }

    window.addEventListener('resize', layout);
    layout();
    show(index, { instant: true });
    root.classList.add('is-active');
    root._idle = setTimeout(function () { root.classList.remove('is-active'); }, 2500);
})();
