// Страницы разделов «Подготовка презентаций» и «Подготовка пятничной презентации»: диалоги и порядок тем
// собрания перетаскиванием (сохраняется сразу; без JavaScript работают кнопки ↑↓).
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-dialog-open]');
        if (open) {
            var dialog = document.getElementById(open.getAttribute('data-dialog-open'));
            if (dialog && dialog.showModal) {
                dialog.showModal();
                var first = dialog.querySelector('input:not([type=hidden]), textarea, select');
                if (first) { first.focus(); }
            }
            return;
        }
        var close = e.target.closest('[data-dialog-close]');
        if (close) {
            var d = close.closest('dialog');
            if (d) { d.close(); }
        }
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-sortable]'), function (list) {
        var dragged = null;
        var status = document.createElement('div');
        status.className = 'hints deck-sort-status';
        status.setAttribute('role', 'status');
        list.parentNode.insertBefore(status, list.nextSibling);

        function save() {
            var ids = Array.prototype.map.call(list.querySelectorAll(':scope > [data-id]'), function (li) { return li.getAttribute('data-id'); });
            var body = new FormData();
            body.append('_token', list.getAttribute('data-sort-token'));
            body.append('order', ids.join(','));
            status.textContent = 'Сохраняю порядок…';
            fetch(list.getAttribute('data-sort-url'), { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
                .then(function () {
                    status.textContent = 'Порядок тем сохранён. Обновите страницу, чтобы увидеть презентацию собрания в новом порядке.';
                    Array.prototype.forEach.call(list.querySelectorAll('.deck-topic__move button'), function (b) { b.disabled = false; });
                })
                .catch(function () { status.textContent = 'Порядок не сохранён — обновите страницу и попробуйте ещё раз.'; });
        }

        list.addEventListener('dragstart', function (e) {
            var li = e.target.closest('[data-id]');
            if (!li) { return; }
            dragged = li;
            li.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', li.getAttribute('data-id')); } catch (err) { /* IE */ }
        });
        list.addEventListener('dragover', function (e) {
            if (!dragged) { return; }
            e.preventDefault();
            var over = e.target.closest('[data-id]');
            if (!over || over === dragged) { return; }
            var rect = over.getBoundingClientRect();
            var after = e.clientY > rect.top + rect.height / 2;
            list.insertBefore(dragged, after ? over.nextSibling : over);
        });
        list.addEventListener('drop', function (e) { e.preventDefault(); });
        list.addEventListener('dragend', function () {
            if (!dragged) { return; }
            dragged.classList.remove('is-dragging');
            dragged = null;
            save();
        });
    });
})();
