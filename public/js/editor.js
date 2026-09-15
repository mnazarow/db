/* Форма документа: переключение «файл / страница» и редактор страниц Quill. */
(function () {
    'use strict';

    var form = document.querySelector('[data-doc-form]');
    if (!form) { return; }

    var panels = form.querySelectorAll('[data-type-panel]');
    var choices = form.querySelectorAll('[data-type-choice] input[type=radio]');
    var hidden = form.querySelector('[data-page-content]');
    var editorHost = form.querySelector('[data-quill]');
    var quill = null;

    function currentType() {
        var value = form.getAttribute('data-type') || 'file';
        Array.prototype.forEach.call(choices, function (r) { if (r.checked) { value = r.value; } });
        return value;
    }

    function initEditor() {
        if (quill || !editorHost || typeof window.Quill === 'undefined') { return; }
        quill = new window.Quill(editorHost, {
            theme: 'snow',
            placeholder: 'Введите текст документа…',
            modules: {
                toolbar: [
                    [{ header: [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    [{ indent: '-1' }, { indent: '+1' }],
                    [{ align: [] }],
                    ['blockquote', 'code-block'],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });
        if (hidden && hidden.value) {
            quill.clipboard.dangerouslyPasteHTML(hidden.value);
        }
    }

    function applyType() {
        var type = currentType();
        Array.prototype.forEach.call(panels, function (p) { p.hidden = p.getAttribute('data-type-panel') !== type; });
        if (type === 'page') { initEditor(); }
    }

    Array.prototype.forEach.call(choices, function (r) { r.addEventListener('change', applyType); });
    applyType();

    form.addEventListener('submit', function () {
        if (quill && hidden && currentType() === 'page') {
            var html = quill.getSemanticHTML ? quill.getSemanticHTML() : quill.root.innerHTML;
            hidden.value = (quill.getText() || '').trim() === '' && !/<img/i.test(html) ? '' : html;
        }
        var btn = form.querySelector('[data-submit]');
        if (btn) { btn.classList.add('is-loading'); }
    });
})();
