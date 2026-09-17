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

    ready(function () {
        /* Подтверждения */
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form && form.hasAttribute('data-confirm')) {
                if (!window.confirm(form.getAttribute('data-confirm'))) { e.preventDefault(); }
            }
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
    });

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
