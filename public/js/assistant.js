/*
 * Помощник: окно чата (кнопка «Помощник» на всех страницах) и страница /assistant.
 * Вопрос уходит на /assistant/ask, ответ приходит готовым HTML (ссылки [n] на источники) со списком источников;
 * открытый разговор запоминается на время сеанса вкладки — при переходе на другую страницу окно продолжает его.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-assistant]');
    if (!root) {
        return;
    }
    var cfg = {};
    try {
        cfg = JSON.parse(root.querySelector('[data-assistant-config]').textContent || '{}');
    } catch (e) {
        return;
    }
    var mode = root.getAttribute('data-mode');
    var panel = root.querySelector('.assistant__panel');
    var log = root.querySelector('[data-assistant-log]');
    var welcome = root.querySelector('[data-assistant-welcome]');
    var form = root.querySelector('[data-assistant-form]');
    var input = form.querySelector('textarea');
    var send = form.querySelector('button[type=submit]');
    var list = root.querySelector('[data-assistant-list]');
    var listItems = root.querySelector('[data-assistant-list-items]');
    var listEmpty = root.querySelector('[data-assistant-list-empty]');
    var toggle = root.querySelector('[data-assistant-toggle]');
    var KEY = 'assistant:conversation';
    var OPEN = 'assistant:open';
    var conversation = null;
    var busy = false;
    var loaded = false;

    function store(key, value) {
        try {
            if (value === null) { sessionStorage.removeItem(key); } else { sessionStorage.setItem(key, String(value)); }
        } catch (e) { /* хранилище недоступно */ }
    }
    function stored(key) {
        try { return sessionStorage.getItem(key); } catch (e) { return null; }
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function request(url, body) {
        var options = {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}};
        if (body) {
            options.method = 'POST';
            options.body = body;
        }
        return fetch(url, options).then(function (r) {
            return r.json().catch(function () { return {error: 'Сервер ответил ошибкой (' + r.status + ').'}; }).then(function (data) {
                if (!r.ok && !data.error) { data.error = 'Сервер ответил ошибкой (' + r.status + ').'; }
                return data;
            });
        }, function () { return {error: 'Нет связи с порталом — проверьте сеть и повторите.'}; });
    }
    function scrollDown() {
        log.scrollTop = log.scrollHeight;
    }

    var KIND = {video: 'видео', price: 'прайс-лист'};
    function bubble(message) {
        var el = document.createElement('div');
        el.className = 'assistant__msg assistant__msg--' + (message.role === 'user' ? 'user' : 'bot') + (message.notice ? ' assistant__msg--notice' : '');
        var html = '<div class="assistant__text">' + message.html + '</div>';
        if (message.sources && message.sources.length && !message.notice) {
            html += '<ol class="assistant__sources">' + message.sources.map(function (s) {
                return '<li value="' + s.n + '"><span class="tag">' + (KIND[s.type] || 'документ') + '</span> <a href="' + esc(s.url) + '">' + esc(s.title) + '</a>' + (s.meta ? ' <span class="text-muted">' + esc(s.meta) + '</span>' : '') + '</li>';
            }).join('') + '</ol>';
        }
        if (message.verified) {
            html += '<div class="assistant__meta">Проверенный ответ администраторов портала</div>';
        }
        if (message.rate_url) {
            html += '<div class="assistant__rate" data-rate="' + esc(message.rate_url) + '">'
                + (message.rating ? '<span class="text-muted">' + (message.rating > 0 ? 'Спасибо, ответ помог.' : 'Спасибо — учтём.') + '</span>'
                    : '<span class="text-muted">Ответ помог?</span> <button type="button" class="assistant__rate-btn" data-rating="1">Да</button> <button type="button" class="assistant__rate-btn" data-rating="-1">Нет</button>')
                + '</div>';
        }
        el.innerHTML = html;
        return el;
    }
    function show(messages) {
        Array.prototype.forEach.call(log.querySelectorAll('.assistant__msg'), function (m) { m.remove(); });
        welcome.hidden = messages.length > 0;
        messages.forEach(function (m) { log.appendChild(bubble(m)); });
        scrollDown();
    }
    function notice(text) {
        log.appendChild(bubble({role: 'assistant', html: esc(text), notice: true}));
        scrollDown();
    }

    function setConversation(id) {
        conversation = id ? Number(id) : null;
        store(KEY, conversation);
        if (mode === 'page' && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', cfg.page + (conversation ? '?c=' + conversation : ''));
        }
        if (listItems) {
            Array.prototype.forEach.call(listItems.querySelectorAll('a'), function (a) {
                a.classList.toggle('is-active', Number(a.getAttribute('data-id')) === conversation);
            });
        }
    }
    function load(id) {
        setConversation(id);
        if (!id) {
            show([]);
            return Promise.resolve();
        }
        return request(cfg.conversation.replace(/\/0$/, '/' + id)).then(function (data) {
            if (data.error) {
                setConversation(null);
                show([]);
                return;
            }
            show(data.messages || []);
        });
    }
    function refreshList() {
        if (!listItems) { return; }
        request(cfg.list).then(function (data) {
            var items = data.conversations || [];
            listItems.innerHTML = items.map(function (c) {
                return '<li><a href="' + esc(cfg.page) + '?c=' + c.id + '" data-id="' + c.id + '" class="' + (c.id === conversation ? 'is-active' : '') + '"><span>' + esc(c.title) + '</span><small>' + (c.channel !== 'web' && c.channel_label ? esc(c.channel_label) + ' · ' : '') + esc(c.updated) + '</small></a>'
                    + '<button type="button" class="assistant__list-del" data-delete="' + c.id + '" title="Удалить разговор" aria-label="Удалить разговор">×</button></li>';
            }).join('');
            if (listEmpty) { listEmpty.hidden = items.length > 0; }
        });
    }

    function ask(text) {
        text = text.trim();
        if (!text || busy) { return; }
        busy = true;
        send.disabled = true;
        welcome.hidden = true;
        log.appendChild(bubble({role: 'user', html: esc(text).replace(/\n/g, '<br>')}));
        var typing = document.createElement('div');
        typing.className = 'assistant__msg assistant__msg--bot assistant__msg--typing';
        typing.innerHTML = '<span class="assistant__dots"><i></i><i></i><i></i></span> Ищу в материалах портала…';
        log.appendChild(typing);
        scrollDown();
        input.value = '';
        autosize();
        var body = new FormData();
        body.append('_token', cfg.token);
        body.append('message', text);
        if (conversation) { body.append('conversation', String(conversation)); }
        var slow = setTimeout(function () {
            typing.lastChild.textContent = ' Готовлю ответ — языковой модели нужно время…';
        }, 8000);
        request(cfg.ask, body).then(function (data) {
            clearTimeout(slow);
            typing.remove();
            busy = false;
            send.disabled = false;
            if (data.error) {
                notice(data.error);
                if (!data.reply) { input.value = text; autosize(); }
                return;
            }
            var isNew = conversation !== data.conversation.id;
            setConversation(data.conversation.id);
            log.appendChild(bubble(data.reply));
            scrollDown();
            if (isNew || mode === 'page') { refreshList(); }
        });
    }

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        ask(input.value);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            ask(input.value);
        }
    });
    input.addEventListener('input', autosize);
    root.addEventListener('click', function (e) {
        var t = e.target.closest('button, a');
        if (!t || !root.contains(t)) { return; }
        if (t.hasAttribute('data-assistant-example')) {
            ask(t.textContent);
        } else if (t.hasAttribute('data-assistant-new')) {
            load(null);
            input.focus();
        } else if (t.hasAttribute('data-assistant-list-toggle')) {
            list.hidden = !list.hidden;
            if (!list.hidden) { refreshList(); }
        } else if (t.hasAttribute('data-assistant-close')) {
            close();
        } else if (t.hasAttribute('data-delete')) {
            var id = Number(t.getAttribute('data-delete'));
            var body = new FormData();
            body.append('_token', cfg.token);
            request(cfg['delete'].replace('/0/', '/' + id + '/'), body).then(function () {
                if (id === conversation) { load(null); }
                refreshList();
            });
        } else if (t.hasAttribute('data-rating')) {
            var box = t.closest('[data-rate]');
            var rate = new FormData();
            rate.append('_token', cfg.rateToken);
            rate.append('rating', t.getAttribute('data-rating'));
            request(box.getAttribute('data-rate'), rate).then(function (data) {
                box.innerHTML = '<span class="text-muted">' + (data.error ? esc(data.error) : (Number(data.rating) > 0 ? 'Спасибо, ответ помог.' : 'Спасибо — учтём.')) + '</span>';
            });
        } else if (t.matches('[data-assistant-list-items] a')) {
            e.preventDefault();
            load(Number(t.getAttribute('data-id')));
            if (mode === 'panel') { list.hidden = true; }
        }
    });

    function open() {
        panel.hidden = false;
        root.classList.add('is-open');
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
        store(OPEN, '1');
        if (!loaded) {
            loaded = true;
            load(stored(KEY) ? Number(stored(KEY)) : null);
        }
        setTimeout(function () { input.focus(); }, 30);
    }
    function close() {
        panel.hidden = true;
        root.classList.remove('is-open');
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
        store(OPEN, null);
    }

    if (mode === 'panel') {
        toggle.addEventListener('click', function () { panel.hidden ? open() : close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) { close(); }
        });
        // Окно было открыто на прошлой странице — остаётся открытым (на телефоне — нет: оно на весь экран).
        if (stored(OPEN) && window.innerWidth > 640) { open(); }
        var expand = root.querySelector('[data-assistant-expand]');
        if (expand) {
            expand.addEventListener('click', function () {
                if (conversation) { expand.href = cfg.page + '?c=' + conversation; }
                store(OPEN, null);
            });
        }
    } else {
        loaded = true;
        // Страница продолжает разговор из окна помощника (или открытый по ссылке ?c=…).
        var current = root.getAttribute('data-current') || stored(KEY);
        load(current ? Number(current) : null);
        refreshList();
        input.focus();
    }
})();
