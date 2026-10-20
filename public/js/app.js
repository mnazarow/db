/* Портал документации — клиентская логика (без внешних библиотек).
 * - подтверждения действий (data-confirm), мобильное меню;
 * - зона перетаскивания файла (data-dropzone);
 * - автоотправка форм при выборе значения (data-autosubmit);
 * - форма пользователя: скрытие полей пароля для доменных учётных записей;
 * - форма сравнения версий.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    /* Установка портала как приложения: service worker (только оформление в кэше и страница «Нет связи») */
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { /* не критично */ });
        });
    }

    /* Ответ ИИ на странице поиска: вопрос уходит на сервер, ответ — с пронумерованными ссылками на источники */
    function setupAiAnswer(box) {
        var body = box.querySelector('[data-ai-body]');
        var button = box.querySelector('[data-ai-ask]');
        box.hidden = false;
        function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function ask() {
            if (button) { button.disabled = true; }
            body.innerHTML = '<p class="text-muted ai-answer__wait">Ищу ответ в документах…</p>';
            var data = new FormData();
            data.append('q', box.getAttribute('data-q') || '');
            data.append('_token', box.getAttribute('data-token') || '');
            fetch(box.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { error: 'Сервер ответил ошибкой (' + r.status + ').' }; }); })
                .then(function (res) {
                    var html = '';
                    if (res.verified) {
                        html += '<p class="ai-answer__verified"><span class="badge badge--done"><span class="badge__dot"></span>Проверенный ответ</span>'
                            + ' <span class="text-muted">составлен администратором портала' + (res.verified.updated ? ', обновлён ' + esc(res.verified.updated) : '') + '</span></p>';
                    }
                    if (res.html) { html += '<div class="ai-answer__text">' + res.html + '</div>'; }
                    if (res.error) { html += '<p class="' + (res.html ? 'text-muted' : 'alert alert--info') + '">' + esc(res.error) + '</p>'; }
                    if (res.sources && res.sources.length) {
                        html += '<ol class="ai-answer__sources">' + res.sources.map(function (s) {
                            return '<li value="' + s.n + '"><span class="tag">' + ({video: 'видео', price: 'прайс-лист'}[s.type] || 'документ') + '</span> <a href="' + esc(s.url) + '">' + esc(s.title) + '</a>' + (s.meta ? ' <span class="text-muted">' + esc(s.meta) + '</span>' : '') + '</li>';
                        }).join('') + '</ol>';
                    }
                    if (res.rate_url) {
                        html += '<div class="ai-answer__rate" data-ai-rate><span class="ai-answer__rate-label">Ответ помог?</span>'
                            + '<button class="btn btn--ghost btn--small" type="button" data-rate="1">Да</button>'
                            + '<button class="btn btn--ghost btn--small" type="button" data-rate="-1">Нет</button></div>';
                    }
                    body.innerHTML = html || '<p class="text-muted">Ответа нет.</p>';
                    if (res.rate_url) { setupRating(body.querySelector('[data-ai-rate]'), res.rate_url); }
                })
                .catch(function () { body.innerHTML = '<p class="alert alert--info">Нет связи с сервером — повторите позже.</p>'; })
                .then(function () { if (button) { button.disabled = false; button.textContent = 'Ответить ещё раз'; } });
        }
        function send(url, rating, comment) {
            var data = new FormData();
            data.append('rating', String(rating));
            data.append('comment', comment || '');
            data.append('_token', box.getAttribute('data-token') || '');
            return fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { error: 'Сервер ответил ошибкой (' + r.status + ').' }; }); });
        }
        /* «Ответ помог?»: «Да» — спасибо; «Нет» — оценка сразу и необязательное поле «чего не хватило». */
        function setupRating(rate, url) {
            if (!rate) { return; }
            rate.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('[data-rate]') : null;
                if (!b) { return; }
                var rating = parseInt(b.getAttribute('data-rate'), 10);
                Array.prototype.forEach.call(rate.querySelectorAll('[data-rate]'), function (x) { x.disabled = true; });
                send(url, rating, '').then(function (res) {
                    if (res.error) { rate.innerHTML = '<span class="text-muted">' + esc(res.error) + '</span>'; return; }
                    if (rating > 0) { rate.innerHTML = '<span class="ai-answer__thanks">Спасибо за оценку!</span>'; return; }
                    rate.innerHTML = '<form class="ai-answer__feedback" data-ai-feedback><label class="form-label" for="ai-feedback-text">Чего не хватило в ответе? (необязательно)</label>'
                        + '<textarea class="form-control" id="ai-feedback-text" rows="2" maxlength="500" placeholder="Например: нужен порядок для филиалов"></textarea>'
                        + '<div class="form-actions"><button class="btn btn--pill btn--small" type="submit">Отправить</button><span class="text-muted">Оценка учтена — администратор увидит, каких документов не хватает.</span></div></form>';
                    var form = rate.querySelector('[data-ai-feedback]');
                    form.addEventListener('submit', function (ev) {
                        ev.preventDefault();
                        var text = form.querySelector('textarea').value;
                        form.querySelector('button').disabled = true;
                        send(url, -1, text).then(function (r2) {
                            rate.innerHTML = '<span class="ai-answer__thanks">' + (r2.error ? esc(r2.error) : 'Спасибо, комментарий передан.') + '</span>';
                        });
                    });
                }).catch(function () { rate.innerHTML = '<span class="text-muted">Нет связи с сервером — оценка не сохранена.</span>'; });
            });
        }
        if (button) { button.addEventListener('click', ask); }
        if (box.hasAttribute('data-auto')) { ask(); }
    }

    /* «Похоже по смыслу»: документы, близкие к запросу по смыслу, которых нет среди найденных по словам. */
    function setupSemantic(box) {
        var list = box.querySelector('[data-semantic-list]');
        function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        var shown = {};
        Array.prototype.forEach.call(document.querySelectorAll('.table-card a[href^="/documents/"]'), function (a) {
            if (!box.contains(a)) { shown[a.getAttribute('href')] = true; }
        });
        var data = new FormData();
        data.append('q', box.getAttribute('data-q') || '');
        data.append('_token', box.getAttribute('data-token') || '');
        fetch(box.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var items = (res.items || []).filter(function (it) { return !shown[it.url]; });
                if (!items.length) { return; }
                list.innerHTML = items.map(function (it) {
                    return '<li class="snippet"><div class="snippet__head"><a class="snippet__title" href="' + esc(it.url) + '">' + esc(it.title) + '</a></div>'
                        + (it.meta ? '<div class="snippet__section">' + esc(it.meta) + '</div>' : '')
                        + (it.snippet ? '<p class="snippet__text">' + esc(it.snippet) + '</p>' : '') + '</li>';
                }).join('');
                box.hidden = false;
            })
            .catch(function () { /* блок необязательный — без него страница работает */ });
    }

    /* Уведомления в браузере (Web Push): подписка этого устройства через service worker портала */
    function setupPush(box) {
        var state = box.querySelector('[data-push-state]');
        var on = box.querySelector('[data-push-on]');
        var off = box.querySelector('[data-push-off]');
        var key = box.getAttribute('data-key');
        if (!key || !state) { return; }
        function say(text) { state.textContent = text; }
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            say(window.isSecureContext ? 'Этот браузер не поддерживает уведомления. На iPhone и iPad добавьте портал на экран «Домой» и откройте его оттуда.' : 'Уведомления работают только при входе на портал по защищённому адресу (https).');
            return;
        }
        function keyBytes(b64) {
            var s = (b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
            var raw = atob(s), out = new Uint8Array(raw.length);
            for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
            return out;
        }
        function post(url, data) {
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': box.getAttribute('data-token') || '' }, body: JSON.stringify(data) })
                .then(function (r) { return r.json().catch(function () { return { error: 'Сервер ответил ошибкой (' + r.status + ').' }; }); });
        }
        var registration = null;
        function render(sub) {
            on.hidden = !!sub;
            off.hidden = !sub;
            if (Notification.permission === 'denied') {
                on.hidden = true;
                say('Уведомления от портала запрещены в настройках браузера — разрешите их для этого сайта (значок слева от адреса) и обновите страницу.');
                return;
            }
            say(sub ? 'На этом устройстве уведомления включены.' : 'На этом устройстве уведомления выключены.');
        }
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
            registration = reg;
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            // Подписка есть, но ключ портала сменился — подписываемся заново.
            if (sub && sub.options && sub.options.applicationServerKey) {
                var current = new Uint8Array(sub.options.applicationServerKey), wanted = keyBytes(key), same = current.length === wanted.length;
                for (var i = 0; same && i < current.length; i++) { same = current[i] === wanted[i]; }
                if (!same) { return sub.unsubscribe().then(function () { return null; }); }
            }
            if (!sub) { return null; }
            // Устройство уже включено этим сотрудником — обновляем ключи; иначе (другой сотрудник на общем
            // компьютере) показываем «выключены»: включить можно кнопкой.
            var data = sub.toJSON(); data.refresh = true;
            return post(box.getAttribute('data-subscribe'), data).then(function (res) { return res && res.known ? sub : null; });
        }).then(render).catch(function () { say('Не удалось подключить уведомления в этом браузере.'); });
        on.addEventListener('click', function () {
            if (!registration) { return; }
            on.disabled = true;
            Notification.requestPermission().then(function (permission) {
                if (permission !== 'granted') { render(null); return null; }
                return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) }).then(function (sub) {
                    return post(box.getAttribute('data-subscribe'), sub.toJSON()).then(function (res) {
                        if (res.error) { say(res.error); return sub.unsubscribe(); }
                        window.location.reload();
                    });
                });
            }).catch(function () { say('Браузер не разрешил подписку на уведомления.'); }).then(function () { on.disabled = false; });
        });
        off.addEventListener('click', function () {
            if (!registration) { return; }
            off.disabled = true;
            registration.pushManager.getSubscription().then(function (sub) {
                if (!sub) { return null; }
                return post(box.getAttribute('data-unsubscribe'), { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
            }).then(function () { window.location.reload(); }).catch(function () { off.disabled = false; });
        });
    }

    ready(function () {
        var pushBox = document.querySelector('[data-push]');
        if (pushBox && window.fetch) { setupPush(pushBox); }
        var aiBox = document.querySelector('[data-ai-answer]');
        if (aiBox && window.fetch && window.FormData) { setupAiAnswer(aiBox); }
        var semanticBox = document.querySelector('[data-semantic]');
        if (semanticBox && window.fetch && window.FormData) { setupSemantic(semanticBox); }

        /* Подтверждения: data-confirm у формы или у нажатой кнопки (например, «Удалить» рядом с «Сохранить») */
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.getAttribute) { return; }
            var button = e.submitter || null;
            var message = (button && button.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) { e.preventDefault(); }
        });

        /* Мобильное меню */
        var toggle = document.querySelector('[data-nav-toggle]');
        var nav = document.querySelector('.site-nav');
        if (toggle && nav) {
            toggle.addEventListener('click', function () {
                var open = nav.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        /* Шапка: если меню, поиск и блок пользователя не помещаются в строку (пунктов меню у ролей разное
           число, имена и подписи разной длины), шапка ужимается по шагам — сначала подписи счётчиков и роли,
           затем имя (остаётся аватар с подсказкой), название портала у логотипа, поле поиска (вместо него —
           пункт «Поиск»), плотнее меню и, если и этого мало, меню прячется под кнопку, как на телефоне. */
        var headerInner = document.querySelector('.site-header__inner');
        if (headerInner) {
            var FIT = ['hdr-fit-1', 'hdr-fit-2', 'hdr-fit-3', 'hdr-fit-4', 'hdr-fit-5', 'hdr-fit-6'];
            var squeezed = function () {
                if (headerInner.scrollWidth > headerInner.clientWidth + 1) { return true; }
                var parts = headerInner.querySelectorAll('.site-header__user, .site-header__user > *, .site-header__user .user-chip, .site-header__user .user-chip__name, .site-nav');
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i].offsetWidth > 0 && parts[i].scrollWidth > parts[i].clientWidth + 1) { return true; }
                }
                var search = headerInner.querySelector('.header-search');
                return !!(search && search.offsetWidth > 0 && search.offsetWidth < 170);
            };
            var fitHeader = function () {
                FIT.forEach(function (c) { headerInner.classList.remove(c); });
                if (window.innerWidth <= 900) { return; } // на узких экранах своя раскладка с кнопкой меню
                for (var i = 0; i < FIT.length && squeezed(); i++) { headerInner.classList.add(FIT[i]); }
            };
            var fitTimer = null;
            fitHeader();
            window.addEventListener('resize', function () { clearTimeout(fitTimer); fitTimer = setTimeout(fitHeader, 80); });
            window.addEventListener('load', fitHeader);
            if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitHeader); }
        }

        /* Назначение ознакомления: выбрать всех / по подразделению / снять выбор */
        var ackForm = document.querySelector('[data-ack-form]');
        if (ackForm) {
            var boxes = function () { return ackForm.querySelectorAll('input[name="users[]"]:not([disabled])'); };
            var counter = ackForm.querySelector('[data-ack-counter]');
            var recount = function () {
                if (!counter) { return; }
                var n = ackForm.querySelectorAll('input[name="users[]"]:checked').length;
                counter.textContent = 'выбрано: ' + n;
            };
            var setAll = function (value) {
                Array.prototype.forEach.call(boxes(), function (b) { b.checked = value; });
                Array.prototype.forEach.call(ackForm.querySelectorAll('[data-ack-group]'), function (g) { g.checked = value; });
                recount();
            };
            var allBtn = ackForm.querySelector('[data-ack-all]');
            var noneBtn = ackForm.querySelector('[data-ack-none]');
            if (allBtn) { allBtn.addEventListener('click', function () { setAll(true); }); }
            if (noneBtn) { noneBtn.addEventListener('click', function () { setAll(false); }); }
            Array.prototype.forEach.call(ackForm.querySelectorAll('[data-ack-group]'), function (group) {
                group.addEventListener('change', function () {
                    var box = group.closest('.ack-people__group');
                    if (!box) { return; }
                    Array.prototype.forEach.call(box.querySelectorAll('input[name="users[]"]:not([disabled])'), function (b) { b.checked = group.checked; });
                    recount();
                });
            });
            ackForm.addEventListener('change', function (e) {
                if (e.target && e.target.name === 'users[]') { recount(); }
            });
            recount();
        }

        /* Копирование в буфер обмена (data-copy="селектор или текст") */
        Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
            btn.addEventListener('click', function () {
                var ref = btn.getAttribute('data-copy') || '';
                var source = /^[#.]/.test(ref) ? document.querySelector(ref) : null;
                var text = source ? (typeof source.value === 'string' ? source.value : source.textContent) : ref;
                var done = function () {
                    var label = btn.textContent;
                    btn.textContent = 'Скопировано';
                    btn.classList.add('is-done');
                    setTimeout(function () { btn.textContent = label; btn.classList.remove('is-done'); }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () { window.prompt('Скопируйте:', text); });
                } else {
                    window.prompt('Скопируйте:', text);
                }
            });
        });

        /* Автоотправка при выборе */
        Array.prototype.forEach.call(document.querySelectorAll('[data-autosubmit]'), function (el) {
            el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
        });

        /* Зона перетаскивания файла */
        Array.prototype.forEach.call(document.querySelectorAll('[data-dropzone]'), function (zone) {
            var input = zone.querySelector('[data-dropzone-input]');
            var name = zone.querySelector('[data-dropzone-name]');
            if (!input) { return; }
            function show() {
                if (!name) { return; }
                if (input.files && input.files.length) {
                    var f = input.files[0];
                    name.textContent = f.name + ' (' + humanSize(f.size) + ')';
                    name.classList.add('is-set');
                } else {
                    name.textContent = 'Файл не выбран';
                    name.classList.remove('is-set');
                }
            }
            input.addEventListener('change', show);
            ['dragenter', 'dragover'].forEach(function (ev) {
                zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-dragover'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-dragover'); });
            });
            zone.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                    try { input.files = e.dataTransfer.files; } catch (err) { /* старые браузеры */ }
                    show();
                }
            });
            show();
        });

        /* Форма пользователя: поля пароля только для локальных учётных записей */
        var userForm = document.querySelector('[data-user-form]');
        if (userForm) {
            var radios = userForm.querySelectorAll('[data-auth-source] input[type=radio]');
            var localOnly = userForm.querySelector('[data-local-only]');
            function applySource() {
                var value = null;
                Array.prototype.forEach.call(radios, function (r) { if (r.checked) { value = r.value; } });
                if (localOnly) { localOnly.hidden = value === 'ldap'; }
            }
            Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', applySource); });
            if (radios.length) { applySource(); }
        }

        /* Сравнение версий: собрать адрес из выбранных версий */
        var compareForm = document.querySelector('[data-compare-form]');
        if (compareForm) {
            compareForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var a = compareForm.querySelector('[name=a]').value;
                var b = compareForm.querySelector('[name=b]').value;
                var tpl = compareForm.getAttribute('data-url-template');
                window.location.href = tpl.replace('/versions/0/compare/0', '/versions/' + a + '/compare/' + b);
            });
        }

        /* Импорт из каталога: выполнение задания порциями с показом хода */
        var jobCard = document.querySelector('[data-import-job]');
        if (jobCard) { importJob(jobCard); }

        /* PDF-сборник раздела: выбор документов и сборка порциями */
        var bundleForm = document.querySelector('[data-bundle-form]');
        if (bundleForm) { bundleChoice(bundleForm); }
        var bundleCard = document.querySelector('[data-bundle-job]');
        if (bundleCard) { bundleJob(bundleCard); }
    });

    function pluralRu(n, forms) {
        var a = Math.abs(n) % 100, b = a % 10;
        var form = (a > 10 && a < 20) ? forms[2] : (b > 1 && b < 5) ? forms[1] : (b === 1 ? forms[0] : forms[2]);
        return n + ' ' + form;
    }

    function bundleChoice(form) {
        var boxes = function () { return form.querySelectorAll('input[name="documents[]"]'); };
        var counter = form.querySelector('[data-bundle-counter]');
        var syncGroups = function () {
            Array.prototype.forEach.call(form.querySelectorAll('[data-bundle-group]'), function (g) {
                var box = g.closest('.ack-people__group');
                if (!box) { return; }
                var all = box.querySelectorAll('input[name="documents[]"]');
                var on = box.querySelectorAll('input[name="documents[]"]:checked');
                g.checked = all.length > 0 && on.length === all.length;
                g.indeterminate = on.length > 0 && on.length < all.length;
            });
        };
        var recount = function () {
            if (counter) { counter.textContent = 'отмечено: ' + form.querySelectorAll('input[name="documents[]"]:checked').length + ' из ' + boxes().length; }
            syncGroups();
        };
        var setAll = function (value) {
            Array.prototype.forEach.call(boxes(), function (b) { b.checked = value; });
            recount();
        };
        var allBtn = form.querySelector('[data-bundle-all]');
        var noneBtn = form.querySelector('[data-bundle-none]');
        if (allBtn) { allBtn.addEventListener('click', function () { setAll(true); }); }
        if (noneBtn) { noneBtn.addEventListener('click', function () { setAll(false); }); }
        Array.prototype.forEach.call(form.querySelectorAll('[data-bundle-group]'), function (group) {
            group.addEventListener('change', function () {
                var box = group.closest('.ack-people__group');
                if (!box) { return; }
                Array.prototype.forEach.call(box.querySelectorAll('input[name="documents[]"]'), function (b) { b.checked = group.checked; });
                recount();
            });
        });
        form.addEventListener('change', function (e) {
            if (e.target && e.target.name === 'documents[]') { recount(); }
        });
        recount();
    }

    function bundleJob(card) {
        var url = card.getAttribute('data-bundle-job');
        var token = card.getAttribute('data-token');
        var finished = card.getAttribute('data-finished') === '1';
        var running = false, failures = 0;
        var q = function (selector) { return card.querySelector(selector); };
        var bar = q('[data-job-bar]'), position = q('[data-job-position]'), percent = q('[data-job-percent]');
        var phase = q('[data-job-phase]'), status = q('[data-job-status]'), errorBox = q('[data-job-error]');
        var networkBox = q('[data-job-network]'), download = q('[data-job-download]'), size = q('[data-job-size]');
        var removeForm = q('[data-job-delete]');
        var rows = document.querySelectorAll('[data-job-items] [data-item]');

        function badge(cls, text) {
            return '<span class="badge badge--' + cls + '"><span class="badge__dot"></span>' + escapeHtml(text) + '</span>';
        }
        function render(data) {
            if (bar) { bar.style.width = data.percent + '%'; bar.parentNode.setAttribute('aria-valuenow', data.percent); }
            if (position) { position.textContent = data.position; }
            if (percent) { percent.textContent = data.percent; }
            if (phase) { phase.textContent = data.finished ? 'готово' : (data.phase === 'merge' ? 'объединение файлов' : 'документы'); }
            Object.keys(data.counters || {}).forEach(function (key) {
                var el = card.querySelector('[data-counter="' + key + '"]');
                if (el) { el.textContent = data.counters[key]; }
            });
            (data.items || []).forEach(function (item, i) {
                var cell = rows[i] ? rows[i].querySelector('[data-item-result]') : null;
                if (!cell) { return; }
                if (item.status === 'ok') { cell.innerHTML = badge('done', pluralRu(item.pages, ['страница', 'страницы', 'страниц'])); }
                else if (item.status === 'note') { cell.innerHTML = badge('soon', 'Только реквизиты') + '<div class="hints">' + escapeHtml(item.note || '') + '</div>'; }
            });
            if (data.error) {
                if (errorBox) { errorBox.textContent = data.error; errorBox.hidden = false; }
                if (status) { status.innerHTML = badge('overdue', 'Ошибка'); }
            }
            if (data.download && download) {
                download.setAttribute('href', data.download.url);
                download.hidden = false;
                if (size) { size.textContent = ' (' + pluralRu(data.download.pages || 0, ['страница', 'страницы', 'страниц']) + ', ' + data.download.size + ')'; }
            }
            if (data.finished) {
                finished = true;
                if (!data.error && status) { status.innerHTML = badge('done', 'Готов'); }
                var removeLabel = removeForm ? removeForm.querySelector('[data-job-delete-label]') : null;
                if (removeLabel) { removeLabel.textContent = 'Удалить сборник'; }
            }
        }
        function tick() {
            if (finished || running) { return; }
            running = true;
            var body = new FormData();
            body.append('_token', token);
            fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) { return r.json().catch(function () { return {}; }).then(function (d) { throw new Error(d.error || ('Ошибка сервера ' + r.status)); }); }
                    return r.json();
                })
                .then(function (data) {
                    running = false;
                    failures = 0;
                    if (networkBox) { networkBox.hidden = true; }
                    render(data);
                    // Сборку ведёт обработчик фоновых задач или другая вкладка — только следим за ходом.
                    if (!finished) { setTimeout(tick, data.busy || data.background ? 1500 : 150); }
                })
                .catch(function (err) {
                    running = false;
                    failures++;
                    if (failures >= 5) {
                        if (errorBox) { errorBox.textContent = err.message + ' Обновите страницу, чтобы продолжить.'; errorBox.hidden = false; }
                        return;
                    }
                    if (networkBox) { networkBox.hidden = false; }
                    setTimeout(tick, 3000);
                });
        }
        if (!finished) { tick(); }
    }

    function importJob(card) {
        var url = card.getAttribute('data-import-job');
        var token = card.getAttribute('data-token');
        var label = card.getAttribute('data-label') || '(каталог)';
        var finished = card.getAttribute('data-finished') === '1';
        var running = false, paused = false, failures = 0;
        var bar = card.querySelector('[data-job-bar]');
        var position = card.querySelector('[data-job-position]');
        var percent = card.querySelector('[data-job-percent]');
        var status = card.querySelector('[data-job-status]');
        var errorBox = card.querySelector('[data-job-error]');
        var networkBox = card.querySelector('[data-job-network]');
        var btnContinue = card.querySelector('[data-job-continue]');
        var btnPause = card.querySelector('[data-job-pause]');
        var btnOpen = card.querySelector('[data-job-open]');
        var logBody = document.querySelector('[data-job-log]');
        var logNote = document.querySelector('[data-job-log-note]');
        var badgeClass = { section_new: 'done', doc_new: 'done', version_new: 'shifted', section_exists: 'progress', unchanged: 'none', exists: 'soon', skipped: 'soon', error: 'overdue' };

        function badge(cls, text) {
            return '<span class="badge badge--' + cls + '"><span class="badge__dot"></span>' + escapeHtml(text) + '</span>';
        }
        function setStatus(state) {
            if (!status) { return; }
            if (state === 'error') { status.innerHTML = badge('overdue', 'Ошибка'); }
            else if (state === 'done') { status.innerHTML = badge('done', 'Завершён'); }
            else if (state === 'paused') { status.innerHTML = badge('none', 'Приостановлен'); }
            else { status.innerHTML = badge('progress', 'Выполняется'); }
        }
        function render(data) {
            if (bar) { bar.style.width = data.percent + '%'; bar.parentNode.setAttribute('aria-valuenow', data.percent); }
            if (position) { position.textContent = data.position; }
            if (percent) { percent.textContent = data.percent; }
            Object.keys(data.counters || {}).forEach(function (key) {
                var el = card.querySelector('[data-counter="' + key + '"]');
                if (el) { el.textContent = data.counters[key]; }
            });
            if (logBody && data.log) {
                var html = '';
                data.log.forEach(function (line) {
                    var cls = badgeClass[line.result] || 'none';
                    html += '<tr><td>' + (line.type === 'dir' ? '▸ ' : '') + escapeHtml(line.path === '' ? label : line.path) + '</td><td class="col-nowrap">' + badge(cls, line.label) + '</td><td class="text-muted">' + escapeHtml(line.message || '') + '</td></tr>';
                });
                if (html) { logBody.innerHTML = html; }
                if (logNote) { logNote.textContent = data.log.length >= 300 ? 'показаны последние ' + data.log.length + ' записей' : ''; }
            }
            if (data.root_section && btnOpen) {
                btnOpen.setAttribute('href', data.root_section.url);
                btnOpen.textContent = 'Открыть раздел «' + data.root_section.name.split(' / ').pop() + '»';
            }
            if (data.error) {
                if (errorBox) { errorBox.textContent = data.error; errorBox.hidden = false; }
                setStatus('error');
            }
            if (data.finished) {
                finished = true;
                if (!data.error) { setStatus('done'); }
                if (btnContinue) { btnContinue.hidden = true; }
                if (btnPause) { btnPause.hidden = true; }
                if (btnOpen) { btnOpen.hidden = false; }
            }
        }
        function tick() {
            if (finished || paused || running) { return; }
            running = true;
            var body = new FormData();
            body.append('_token', token);
            fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (r.status === 409) { return r.json().then(function () { return { busy: true }; }); }
                    if (!r.ok) { return r.json().catch(function () { return {}; }).then(function (d) { throw new Error(d.error || ('Ошибка сервера ' + r.status)); }); }
                    return r.json();
                })
                .then(function (data) {
                    running = false;
                    failures = 0;
                    if (networkBox) { networkBox.hidden = true; }
                    if (data.busy) { setTimeout(tick, 2000); return; }
                    render(data);
                    if (!finished && !paused) { setTimeout(tick, 150); }
                })
                .catch(function (err) {
                    running = false;
                    failures++;
                    if (failures >= 5) {
                        if (errorBox) { errorBox.textContent = err.message; errorBox.hidden = false; }
                        setStatus('paused');
                        paused = true;
                        if (btnContinue) { btnContinue.hidden = false; }
                        return;
                    }
                    if (networkBox) { networkBox.hidden = false; }
                    setTimeout(tick, 3000);
                });
        }
        if (btnContinue) {
            btnContinue.addEventListener('click', function () {
                paused = false; failures = 0;
                if (errorBox) { errorBox.hidden = true; }
                setStatus('running');
                if (btnPause) { btnPause.hidden = false; }
                btnContinue.hidden = true;
                tick();
            });
        }
        if (btnPause) {
            btnPause.addEventListener('click', function () {
                paused = true;
                setStatus('paused');
                btnPause.hidden = true;
                if (btnContinue) { btnContinue.hidden = false; }
            });
        }
        window.addEventListener('beforeunload', function (e) {
            if (!finished && !paused) { e.preventDefault(); e.returnValue = ''; }
        });
        if (!finished && card.getAttribute('data-autostart') === '1') {
            if (btnContinue) { btnContinue.hidden = true; }
            tick();
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function humanSize(bytes) {
        if (bytes < 1024) { return bytes + ' Б'; }
        var units = ['КБ', 'МБ', 'ГБ'];
        var v = bytes / 1024, i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }
})();

// Поля ограниченного доступа показываем только при включённом флажке.
(function () {
    var box = document.querySelector('[data-restricted-fields]');
    if (!box) { return; }
    var checkbox = document.querySelector('#document_restricted');
    if (!checkbox) { return; }
    var sync = function () { box.hidden = !checkbox.checked; };
    checkbox.addEventListener('change', sync);
    sync();
})();

// Обсуждение документа: ответ на реплику и правка своего сообщения без перезагрузки страницы.
(function () {
    var form = document.getElementById('comment-form');
    var body = document.getElementById('comment-body');
    var parent = document.getElementById('comment-parent');
    var replyTo = document.getElementById('comment-reply-to');

    function resetReply() {
        if (!parent || !replyTo) { return; }
        parent.value = '';
        replyTo.hidden = true;
    }

    document.addEventListener('click', function (event) {
        var reply = event.target.closest('[data-reply-to]');
        if (reply && form && parent && replyTo && body) {
            parent.value = reply.getAttribute('data-reply-to');
            replyTo.hidden = false;
            replyTo.querySelector('b').textContent = reply.getAttribute('data-reply-name') || '';
            body.focus();
            form.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }
        if (event.target.id === 'comment-reply-cancel') { resetReply(); return; }

        var edit = event.target.closest('[data-edit-comment]');
        if (edit) {
            var box = document.getElementById('comment-edit-' + edit.getAttribute('data-edit-comment'));
            if (box) { box.hidden = false; box.querySelector('textarea').focus(); }
            return;
        }
        var cancel = event.target.closest('[data-edit-cancel]');
        if (cancel) {
            var form2 = document.getElementById('comment-edit-' + cancel.getAttribute('data-edit-cancel'));
            if (form2) { form2.hidden = true; }
        }
    });
})();

// Массовые операции в реестре документов: панель появляется, когда отмечен хотя бы один документ.
(function () {
    var bar = document.querySelector('[data-bulk-bar]');
    var form = document.getElementById('bulk-form');
    if (!bar || !form) { return; }
    var action = bar.querySelector('[data-bulk-action]');
    var counter = bar.querySelector('[data-bulk-count]');
    var submit = bar.querySelector('[data-bulk-submit]');
    var all = document.querySelector('[data-bulk-all]');

    function items() { return Array.prototype.slice.call(document.querySelectorAll('[data-bulk-item]')); }

    function syncFields() {
        var value = action.value;
        bar.querySelectorAll('[data-bulk-for]').forEach(function (field) {
            field.classList.toggle('is-shown', field.getAttribute('data-bulk-for') === value);
        });
        submit.classList.toggle('btn--danger', value === 'delete');
    }

    function sync() {
        var checked = items().filter(function (box) { return box.checked; });
        counter.textContent = String(checked.length);
        bar.hidden = checked.length === 0;
        if (all) { all.checked = checked.length > 0 && checked.length === items().length; }
    }

    if (all) {
        all.addEventListener('change', function () {
            items().forEach(function (box) { box.checked = all.checked; });
            sync();
        });
    }
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-bulk-item]')) { sync(); }
        if (event.target === action) { syncFields(); }
    });
    form.addEventListener('submit', function (event) {
        if (action.value === 'delete' && !window.confirm(submit.getAttribute('data-confirm-delete'))) {
            event.preventDefault();
        }
    });
    syncFields();
    sync();
})();
