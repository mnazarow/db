/* Редактор ONLYOFFICE / Р7-Офис: загружает api.js с сервера документов и открывает файл. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var frame = document.querySelector('[data-onlyoffice]');
        var source = document.getElementById('onlyoffice-config');
        if (!frame || !source) { return; }
        var errorBox = frame.querySelector('[data-onlyoffice-error]');
        var fail = function () { if (errorBox) { errorBox.hidden = false; } };
        var config;
        try { config = JSON.parse(source.textContent); } catch (e) { fail(); return; }
        config.events = {
            onError: function (event) {
                if (window.console) { window.console.warn('Редактор документов:', event && event.data); }
            }
        };
        var script = document.createElement('script');
        script.src = frame.getAttribute('data-api');
        script.async = true;
        script.onload = function () {
            if (typeof window.DocsAPI === 'undefined' || !window.DocsAPI.DocEditor) { fail(); return; }
            new window.DocsAPI.DocEditor('onlyoffice-editor', config);
        };
        script.onerror = fail;
        document.head.appendChild(script);
    });
})();
