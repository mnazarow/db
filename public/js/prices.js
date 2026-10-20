/*
 * Прайс-листы — страница сопоставления столбцов: при смене листа или строки заголовков предпросмотр
 * перестраивается заново (роли столбцов определяются снова), при смене роли столбца — с сохранением
 * остальных ролей. Положение прокрутки сохраняется между перезагрузками предпросмотра.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-price-map]');
    if (!form) {
        return;
    }
    var KEY = 'price-map-scroll:' + location.pathname;
    try {
        var saved = sessionStorage.getItem(KEY);
        if (saved) {
            var pos = JSON.parse(saved);
            sessionStorage.removeItem(KEY);
            window.scrollTo(0, pos.y || 0);
            var scroller = form.querySelector('.map-table') && form.querySelector('.map-table').closest('.table-scroll');
            if (scroller) {
                scroller.scrollLeft = pos.x || 0;
            }
        }
    } catch (e) { /* хранилище недоступно — не страшно */ }

    function reload(reset) {
        var flag = form.querySelector('[data-map-reset-flag]');
        if (flag) {
            flag.value = reset ? '1' : '0';
        }
        try {
            var scroller = form.querySelector('.map-table') && form.querySelector('.map-table').closest('.table-scroll');
            sessionStorage.setItem(KEY, JSON.stringify({y: window.scrollY, x: scroller ? scroller.scrollLeft : 0}));
        } catch (e) { /* без сохранения прокрутки */ }
        var token = form.querySelector('input[name="_token"]');
        if (token) {
            token.disabled = true; // предпросмотр — обычный GET, ключ формы в адрес не нужен
        }
        var button = form.querySelector('[data-map-refresh]');
        if (button && typeof form.requestSubmit === 'function') {
            form.requestSubmit(button);
        } else {
            form.method = 'get';
            form.submit();
        }
    }

    var timer = null;
    Array.prototype.forEach.call(form.querySelectorAll('[data-map-reload]'), function (el) {
        var reset = el.getAttribute('data-map-reload') === 'reset';
        if (el.tagName === 'INPUT') {
            el.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { reload(reset); }, 700);
            });
        } else {
            el.addEventListener('change', function () { reload(reset); });
        }
    });
})();
