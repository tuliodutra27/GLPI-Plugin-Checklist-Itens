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
     * Câmera dentro da página: só em contexto seguro (HTTPS, ou endereço liberado como seguro
     * no navegador/política do aparelho). Em HTTP o navegador não oferece a câmera à página.
     */
    function cameraSupported() {
        return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    /**
     * Selfie. Quando a câmera na página está disponível, abre sempre a câmera FRONTAL
     * (facingMode "user"); senão usa a câmera nativa do aparelho (input capture), em que o app
     * de câmera decide qual câmera abre. Nos dois casos reduz a foto no navegador e guarda em
     * JPEG base64 no campo oculto. Se a foto nativa não puder ser lida (formato não suportado),
     * o arquivo original segue no envio como alternativa.
     */
    function setupSelfie(box) {
        var input = box.querySelector('[data-ci-selfie-input]');
        var hidden = box.querySelector('[data-ci-selfie-data]');
        var preview = box.querySelector('[data-ci-selfie-preview]');
        var status = box.querySelector('[data-ci-selfie-status]');
        var label = box.querySelector('[data-ci-selfie-label]');
        var nativeButton = box.querySelector('[data-ci-selfie-native]');
        var camera = box.querySelector('[data-ci-camera]');
        var video = box.querySelector('[data-ci-camera-video]');
        var openButton = box.querySelector('[data-ci-camera-open]');
        var openLabel = box.querySelector('[data-ci-camera-open-label]');
        var shootButton = box.querySelector('[data-ci-camera-shoot]');
        var stream = null;

        if (!input || !hidden) {
            return;
        }

        function setStatus(text) {
            if (status) {
                status.textContent = text;
            }
        }

        function clear() {
            hidden.value = '';
            box.removeAttribute('data-ci-selfie-ready');
        }

        /** Reduz a imagem (foto ou quadro do vídeo) e grava no campo oculto. */
        function store(source, width, height) {
            var scale = Math.min(1, MAX_SIDE / Math.max(width, height));
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(width * scale);
            canvas.height = Math.round(height * scale);
            canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);

            hidden.value = canvas.toDataURL('image/jpeg', QUALITY);
            preview.src = hidden.value;
            preview.hidden = false;
            box.setAttribute('data-ci-selfie-ready', '1');
            setStatus('Selfie pronta.');
            notify(box, 'ci:change');
        }

        // ---------------------------------------------------------- câmera nativa do aparelho
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            clear();
            if (!file) {
                notify(box, 'ci:change');
                return;
            }

            setStatus('Processando a foto...');
            var url = URL.createObjectURL(file);
            var img = new Image();

            img.onload = function () {
                URL.revokeObjectURL(url);
                store(img, img.naturalWidth, img.naturalHeight);
                // A versão reduzida já está no campo oculto: não reenviar o arquivo original.
                input.value = '';
                if (label) {
                    label.textContent = 'Tirar outra selfie';
                }
            };

            img.onerror = function () {
                URL.revokeObjectURL(url);
                preview.hidden = true;
                box.setAttribute('data-ci-selfie-ready', '1');
                setStatus('Foto recebida (será enviada como está).');
                notify(box, 'ci:change');
            };

            img.src = url;
        });

        // ---------------------------------------------------------- câmera frontal na página
        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(function (track) {
                    track.stop();
                });
                stream = null;
            }
            if (video) {
                video.srcObject = null;
            }
            if (camera) {
                camera.hidden = true;
            }
        }

        function useNative(message) {
            stopCamera();
            if (openButton) {
                openButton.hidden = true;
            }
            if (nativeButton) {
                nativeButton.hidden = false;
            }
            if (message) {
                setStatus(message);
            }
        }

        if (!cameraSupported() || !camera || !video || !openButton || !shootButton) {
            return; // fica só a câmera nativa do aparelho
        }

        if (nativeButton) {
            nativeButton.hidden = true;
        }
        openButton.hidden = false;

        openButton.addEventListener('click', function () {
            clear();
            preview.hidden = true;
            setStatus('Abrindo a câmera frontal...');
            notify(box, 'ci:change');

            navigator.mediaDevices.getUserMedia({
                video: {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 960}},
                audio: false
            }).then(function (mediaStream) {
                stream = mediaStream;
                video.srcObject = mediaStream;
                camera.hidden = false;
                openButton.hidden = true;
                setStatus('Enquadre o rosto e toque em "Tirar foto".');
                var playing = video.play();
                if (playing && playing.catch) {
                    playing.catch(function () {});
                }
            }).catch(function () {
                useNative('Não foi possível abrir a câmera aqui. Use o botão para abrir a câmera do aparelho.');
            });
        });

        shootButton.addEventListener('click', function () {
            if (!video.videoWidth) {
                setStatus('A câmera ainda está carregando, tente de novo.');
                return;
            }
            store(video, video.videoWidth, video.videoHeight);
            stopCamera();
            openButton.hidden = false;
            if (openLabel) {
                openLabel.textContent = 'Tirar outra selfie';
            }
        });

        // Libera a câmera ao enviar ou sair da página
        var form = box.closest ? box.closest('form') : null;
        if (form) {
            form.addEventListener('submit', stopCamera);
        }
        window.addEventListener('pagehide', stopCamera);
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

        function updateButtons(problems) {
            var hasSelfie = qsa('[data-ci-selfie-box]', form).some(function (box) {
                return box.hasAttribute('data-ci-selfie-ready');
            });

            qsa('[data-ci-require]', form).forEach(function (button) {
                var requirement = button.getAttribute('data-ci-require');
                var ready = requirement === 'problems' ? problems > 0 : (requirement === 'selfie' ? hasSelfie : true);
                button.disabled = !ready;
            });
        }

        function update() {
            // Conferência do gestor: só exige a selfie
            if (flow === 'confirm') {
                updateButtons(0);
                return;
            }

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
            updateButtons(problems);
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
