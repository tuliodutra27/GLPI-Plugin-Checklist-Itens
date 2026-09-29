/* Checklist uso de equipamentos: comportamento das telas do plugin (sem dependências). */
(function () {
    'use strict';

    var MAX_SIDE = 1280;   // maior lado da selfie, em pixels
    var QUALITY = 0.8;     // qualidade do JPEG gerado

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function notify(element, name) {
        var event;
        if (typeof window.CustomEvent === 'function') {
            event = new CustomEvent(name, {bubbles: true});
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, false, null);
        }
        element.dispatchEvent(event);
    }

    /**
     * Selfie: abre a câmera frontal (input capture), reduz a foto no navegador e guarda em
     * JPEG base64 no campo oculto. Se o navegador não conseguir ler a foto (ex.: formato não
     * suportado), o arquivo original segue no envio como alternativa.
     */
    function setupSelfie(box) {
        var input = box.querySelector('[data-ci-selfie-input]');
        var hidden = box.querySelector('[data-ci-selfie-data]');
        var preview = box.querySelector('[data-ci-selfie-preview]');
        var status = box.querySelector('[data-ci-selfie-status]');
        var label = box.querySelector('[data-ci-selfie-label]');

        if (!input || !hidden) {
            return;
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            hidden.value = '';
            box.removeAttribute('data-ci-selfie-ready');
            if (!file) {
                notify(box, 'ci:change');
                return;
            }

            status.textContent = 'Processando a foto...';
            var url = URL.createObjectURL(file);
            var img = new Image();

            img.onload = function () {
                var scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(img.naturalWidth * scale);
                canvas.height = Math.round(img.naturalHeight * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);

                hidden.value = canvas.toDataURL('image/jpeg', QUALITY);
                preview.src = hidden.value;
                preview.hidden = false;
                // A versão reduzida já está no campo oculto: não reenviar o arquivo original.
                input.value = '';
                box.setAttribute('data-ci-selfie-ready', '1');
                status.textContent = 'Selfie pronta.';
                if (label) {
                    label.textContent = 'Tirar outra selfie';
                }
                notify(box, 'ci:change');
            };

            img.onerror = function () {
                URL.revokeObjectURL(url);
                preview.hidden = true;
                box.setAttribute('data-ci-selfie-ready', '1');
                status.textContent = 'Foto recebida (será enviada como está).';
                notify(box, 'ci:change');
            };

            img.src = url;
        });
    }

    function init(root) {
        qsa('[data-ci-selfie-box]', root).forEach(setupSelfie);
    }

    window.ChecklistItens = {
        init: init,
        qsa: qsa,
        notify: notify
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})();
