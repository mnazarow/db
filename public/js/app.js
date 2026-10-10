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
                    if (res.html) { html += '<div class="ai-answer__text">' + res.html + '</div>'; }
                    if (res.error) { html += '<p class="' + (res.html ? 'text-muted' : 'alert alert--info') + '">' + esc(res.error) + '</p>'; }
                    if (res.sources && res.sources.length) {
                        html += '<ol class="ai-answer__sources">' + res.sources.map(function (s) {
                            return '<li value="' + s.n + '"><span class="tag">' + (s.type === 'video' ? 'видео' : 'документ') + '</span> <a href="' + esc(s.url) + '">' + esc(s.title) + '</a>' + (s.meta ? ' <span class="text-muted">' + esc(s.meta) + '</span>' : '') + '</li>';
                        }).join('') + '</ol>';
                    }
                    body.innerHTML = html || '<p class="text-muted">Ответа нет.</p>';
                })
                .catch(function () { body.innerHTML = '<p class="alert alert--info">Нет связи с сервером — повторите позже.</p>'; })
                .then(function () { if (button) { button.disabled = false; button.textContent = 'Ответить ещё раз'; } });
        }
        if (button) { button.addEventListener('click', ask); }
        if (box.hasAttribute('data-auto')) { ask(); }
    }

    ready(function () {
        var aiBox = document.querySelector('[data-ai-answer]');
        if (aiBox && window.fetch && window.FormData) { setupAiAnswer(aiBox); }

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
                    if (r.status === 409) { return { busy: true }; }
                    if (!r.ok) { return r.json().catch(function () { return {}; }).then(function (d) { throw new Error(d.error || ('Ошибка сервера ' + r.status)); }); }
                    return r.json();
                })
                .then(function (data) {
                    running = false;
                    failures = 0;
                    if (networkBox) { networkBox.hidden = true; }
                    if (data.busy) { setTimeout(tick, 2000); return; }
                    render(data);
                    if (!finished) { setTimeout(tick, 150); }
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
