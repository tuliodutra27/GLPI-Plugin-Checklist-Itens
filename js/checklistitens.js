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

    /**
     * Fluxo de retirada/devolução: mostra cada passo só quando o anterior foi respondido,
     * desativa os campos dos tipos não escolhidos e só libera os botões quando dá para enviar.
     * As regras valem de novo no servidor; aqui é só para guiar quem está no celular.
     */
    function setupFlow(form) {
        var flow = form.getAttribute('data-ci-flow');

        function checked(name) {
            return form.querySelector('input[name="' + name + '"]:checked');
        }

        function sections(name) {
            return qsa('[data-ci-section="' + name + '"]', form);
        }

        function show(name, visible) {
            sections(name).forEach(function (el) {
                el.hidden = !visible;
            });
        }

        function update() {
            var itemtype = '';
            if (flow === 'checkout') {
                var type = checked('itemtype');
                itemtype = type ? type.value : '';
            } else {
                var usage = checked('usage_id');
                itemtype = usage ? usage.getAttribute('data-ci-itemtype') : '';
            }

            // Blocos por tipo: só o tipo escolhido fica visível e ativo
            qsa('[data-ci-type]', form).forEach(function (block) {
                var active = block.getAttribute('data-ci-type') === itemtype;
                block.hidden = !active;
                qsa('input', block).forEach(function (input) {
                    if (!active && input.checked) {
                        input.checked = false;
                    }
                    input.disabled = !active;
                });
            });

            var itemChosen = flow === 'checkout' ? !!checked('items_id') : itemtype !== '';
            var answer = checked('is_ok');
            var isOk = !!answer && answer.value === '1';
            var isNok = !!answer && answer.value === '0';
            var problems = qsa('input[name="problems[]"]:checked', form).filter(function (input) {
                return !input.disabled;
            }).length;

            show('item', flow === 'checkout' && itemtype !== '');
            show('check', itemChosen);
            show('problems', itemChosen && isNok);
            show('refuse', itemChosen && isNok);

            var selfieVisible = flow === 'checkout'
                ? itemChosen && isOk
                : itemChosen && (isOk || (isNok && problems > 0));
            show('selfie', selfieVisible);

            var hasSelfie = qsa('[data-ci-selfie-box]', form).some(function (box) {
                return box.hasAttribute('data-ci-selfie-ready');
            });

            qsa('[data-ci-require]', form).forEach(function (button) {
                var requirement = button.getAttribute('data-ci-require');
                var ready = requirement === 'problems' ? problems > 0 : (requirement === 'selfie' ? hasSelfie : true);
                button.disabled = !ready;
            });
        }

        form.addEventListener('change', update);
        form.addEventListener('ci:change', update);
        form.addEventListener('submit', function () {
            // Evita envio em dobro; o atraso mantém no envio o botão clicado (refuse/save).
            window.setTimeout(function () {
                qsa('button[type="submit"]', form).forEach(function (button) {
                    button.disabled = true;
                });
            }, 0);
        });
        update();
    }

    /** Busca na lista de equipamentos (número de série, nome, modelo). */
    function setupFilter(input) {
        input.addEventListener('input', function () {
            var query = input.value.toLowerCase().trim();
            var scope = input.form || document;
            qsa('[data-ci-search]', scope).forEach(function (el) {
                el.hidden = query !== '' && el.getAttribute('data-ci-search').indexOf(query) === -1;
            });
        });
    }

    /** Logoff por inatividade nas telas do colaborador (aparelho compartilhado). */
    function setupIdle(el) {
        var seconds = parseInt(el.getAttribute('data-ci-idle'), 10);
        var url = el.getAttribute('data-ci-logout');
        var timer = null;

        if (!seconds || !url) {
            return;
        }

        function reset() {
            window.clearTimeout(timer);
            // Com a câmera aberta a página fica em segundo plano: não conta como inatividade.
            if (document.hidden) {
                return;
            }
            timer = window.setTimeout(function () {
                window.location.href = url;
            }, seconds * 1000);
        }

        ['click', 'touchstart', 'keydown', 'input', 'change', 'scroll'].forEach(function (name) {
            document.addEventListener(name, reset, true);
        });
        document.addEventListener('visibilitychange', reset);
        reset();
    }

    /** Redirecionamento com contagem regressiva (logoff depois da retirada). */
    function setupRedirect(el) {
        var remaining = parseInt(el.getAttribute('data-ci-redirect-after'), 10) || 5;
        var url = el.getAttribute('data-ci-redirect');
        var counter = el.querySelector('[data-ci-countdown]');

        var interval = window.setInterval(function () {
            remaining -= 1;
            if (counter) {
                counter.textContent = String(Math.max(remaining, 0));
            }
            if (remaining <= 0) {
                window.clearInterval(interval);
                window.location.href = url;
            }
        }, 1000);
    }

    function init(root) {
        qsa('[data-ci-selfie-box]', root).forEach(setupSelfie);
        qsa('form[data-ci-flow]', root).forEach(setupFlow);
        qsa('[data-ci-filter]', root).forEach(setupFilter);
        qsa('[data-ci-idle]', root).forEach(setupIdle);
        qsa('[data-ci-redirect]', root).forEach(setupRedirect);
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
