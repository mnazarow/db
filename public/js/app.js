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
    });

    function humanSize(bytes) {
        if (bytes < 1024) { return bytes + ' Б'; }
        var units = ['КБ', 'МБ', 'ГБ'];
        var v = bytes / 1024, i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }
})();
