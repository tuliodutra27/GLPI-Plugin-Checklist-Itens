/* Checklist uso de equipamentos: comportamento das telas do plugin (sem dependências). */

/* Base comum: usada pelos módulos abaixo e pela tela da entrega (js/delivery.js). */
(function () {
    'use strict';

    var QUALITY = 0.8;                 // qualidade do JPEG gerado
    var MAX_MB = 5;                    // limite de cada foto no servidor (UploadedImage::MAX_BYTES)
    var MAX_BYTES = MAX_MB * 1024 * 1024;

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    /** Dispara um evento que sobe pela página, com detalhe opcional. */
    function emit(element, name, detail) {
        var event;
        if (typeof window.CustomEvent === 'function') {
            event = new CustomEvent(name, {bubbles: true, detail: detail === undefined ? null : detail});
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, false, detail === undefined ? null : detail);
        }
        element.dispatchEvent(event);
    }

    /** Desenha a imagem (foto aberta ou quadro do vídeo) com o maior lado em maxSide. */
    function reduce(source, width, height, maxSide) {
        var scale = Math.min(1, maxSide / Math.max(width, height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
        return canvas;
    }

    /** JPEG em data URL, ou '' (canvas vazio ou grande demais para o aparelho devolve "data:,"). */
    function toDataUrl(canvas) {
        var data = '';
        try {
            data = canvas.toDataURL('image/jpeg', QUALITY);
        } catch (e) {
            data = '';
        }
        return /^data:image\/(jpeg|png);base64,/.test(data) ? data : '';
    }

    /** Abre o arquivo como imagem: done(img), ou fail() quando o navegador não consegue ler. */
    function loadImage(file, done, fail) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            done(img);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            fail();
        };
        img.src = url;
    }

    /** Foto reduzida (maior lado maxSide) em JPEG data URL: done(data) ou fail(). */
    function shrinkToDataUrl(file, maxSide, done, fail) {
        loadImage(file, function (img) {
            var data = '';
            try {
                data = toDataUrl(reduce(img, img.naturalWidth, img.naturalHeight, maxSide));
            } catch (e) {
                data = '';
            }
            if (data) {
                done(data);
            } else {
                fail();
            }
        }, fail);
    }

    /** Foto reduzida (maior lado maxSide) como arquivo JPEG: done(blob) ou fail(). */
    function shrinkToBlob(file, maxSide, done, fail) {
        loadImage(file, function (img) {
            var finished = false;
            function result(blob) {
                if (finished) {
                    return;
                }
                finished = true;
                if (blob && blob.size > 0) {
                    done(blob);
                } else {
                    fail();
                }
            }
            try {
                var canvas = reduce(img, img.naturalWidth, img.naturalHeight, maxSide);
                if (canvas.toBlob) {
                    canvas.toBlob(result, 'image/jpeg', QUALITY);
                } else {
                    var data = toDataUrl(canvas);
                    result(data ? dataUrlToBlob(data) : null);
                }
            } catch (e) {
                result(null);
            }
        }, fail);
    }

    /** O servidor aceita o arquivo como está (quando o navegador não consegue reduzi-lo). */
    function fitsAsIs(file) {
        return (file.type === 'image/jpeg' || file.type === 'image/png') && file.size <= MAX_BYTES;
    }

    function dataUrlToBlob(dataUrl) {
        try {
            var parts = String(dataUrl).split(',');
            var mime = (/^data:([^;]+);/.exec(parts[0]) || [])[1] || 'image/jpeg';
            var binary = window.atob(parts[1]);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return new Blob([bytes], {type: mime});
        } catch (e) {
            return null;
        }
    }

    window.ChecklistItens = {
        MAX_BYTES: MAX_BYTES,
        MAX_MB: MAX_MB,
        UNREADABLE: 'Não foi possível ler uma das fotos. Tire outra ou escolha outra imagem (JPEG ou PNG, até ' + MAX_MB + ' MB).',
        PHOTO_SIDE: 1600,             // maior lado das fotos (defeito, entrega, peças)
        SELFIE_SIDE: 1280,            // maior lado da selfie
        QUALITY: QUALITY,
        // Espera máxima pela localização ao enviar, se a leitura começada na selfie não terminou
        LOCATION_WAIT_MS: 10000,
        qsa: qsa,
        emit: emit,
        reduce: reduce,
        toDataUrl: toDataUrl,
        loadImage: loadImage,
        shrinkToDataUrl: shrinkToDataUrl,
        shrinkToBlob: shrinkToBlob,
        fitsAsIs: fitsAsIs,
        dataUrlToBlob: dataUrlToBlob
    };
})();

/* Fotos do equipamento com defeito: módulo próprio, usado pelo fluxo de retirada/devolução abaixo
   e pelo painel de problema da entrega. */
(function () {
    'use strict';

    var base = window.ChecklistItens;
    var qsa = base.qsa;

    function count(container) {
        return container ? qsa('[data-ci-defect-item]', container).length : 0;
    }

    /**
     * Quando o navegador não consegue reduzir a foto, o arquivo original vai num campo próprio
     * (defect_photo_files[]), se couber no limite e o navegador permitir montar o campo.
     */
    function originalField(file) {
        if (!base.fitsAsIs(file) || typeof window.DataTransfer !== 'function') {
            return null;
        }
        try {
            var transfer = new DataTransfer();
            transfer.items.add(file);
            var field = document.createElement('input');
            field.type = 'file';
            field.name = 'defect_photo_files[]';
            field.hidden = true;
            field.files = transfer.files;
            return field.files && field.files.length ? field : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Fotos do defeito: câmera traseira (capture) ou galeria (várias de uma vez), de 1 até o
     * máximo do bloco. Cada foto vira uma miniatura com botão de remover e um campo oculto
     * defect_photos[]. A cada mudança dispara "ci:defectphotos" no bloco (detail.count e
     * detail.busy), para o fluxo liberar o salvamento.
     */
    function setup(container) {
        if (!container || container.ciDefectPhotos) {
            return;
        }

        var inputs = qsa('[data-ci-defect-input]', container);
        var pickers = qsa('[data-ci-defect-picker]', container);
        var list = container.querySelector('[data-ci-defect-list]');
        var fields = container.querySelector('[data-ci-defect-fields]');
        var counter = container.querySelector('[data-ci-defect-counter]');
        var status = container.querySelector('[data-ci-defect-status]');
        var min = parseInt(container.getAttribute('data-ci-min'), 10) || 1;
        var max = parseInt(container.getAttribute('data-ci-max'), 10) || 5;
        var queue = [];
        var current = null;
        var notes = [];
        var generation = 0; // muda ao limpar: descarta fotos que ainda estavam sendo processadas

        if (!inputs.length || !list || !fields) {
            return; // sem JS útil: os campos de arquivo seguem no envio como estão
        }
        container.ciDefectPhotos = true;

        // Com o JS ativo, os campos de escolha só abrem a câmera/galeria: as fotos vão nos campos
        // ocultos de cada miniatura
        inputs.forEach(function (input) {
            input.removeAttribute('name');
        });

        function setStatus(text, warning) {
            if (status) {
                status.textContent = text || '';
                status.classList.toggle('ci-defect-warning', !!warning);
            }
        }

        function pending() {
            return queue.length + (current ? 1 : 0);
        }

        function refresh() {
            var total = count(container);
            var busy = pending() > 0;
            var full = total + pending() >= max;

            if (busy) {
                container.setAttribute('data-ci-defect-busy', '1');
            } else {
                container.removeAttribute('data-ci-defect-busy');
            }
            if (counter) {
                counter.textContent = total + ' de ' + max;
                counter.classList.toggle('ci-defect-counter-ok', total >= min);
            }
            pickers.forEach(function (picker) {
                picker.classList.toggle('disabled', full);
                picker.setAttribute('aria-disabled', full ? 'true' : 'false');
            });
            inputs.forEach(function (input) {
                input.disabled = full;
            });

            base.emit(container, 'ci:defectphotos', {count: total, busy: busy});
        }

        function addItem(field, preview) {
            var item = document.createElement('div');
            item.className = 'ci-defect-item';
            item.setAttribute('data-ci-defect-item', '');

            var thumb;
            if (preview) {
                thumb = document.createElement('img');
                thumb.className = 'ci-defect-thumb';
                thumb.alt = 'Foto do defeito';
                thumb.src = preview;
            } else {
                // arquivo original: sem prévia
                thumb = document.createElement('span');
                thumb.className = 'ci-defect-thumb ci-defect-thumb-file';
                thumb.innerHTML = '<i class="ti ti-photo"></i>';
            }
            item.appendChild(thumb);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ci-defect-remove';
            remove.title = 'Remover foto';
            remove.setAttribute('aria-label', 'Remover foto');
            remove.textContent = '×';
            remove.addEventListener('click', function () {
                if (item.parentNode) {
                    item.parentNode.removeChild(item);
                }
                if (field.parentNode) {
                    field.parentNode.removeChild(field);
                }
                if (!current) {
                    notes = [];
                    setStatus('');
                }
                refresh();
            });
            item.appendChild(remove);

            list.appendChild(item);
            fields.appendChild(field);
            refresh();
        }

        // Uma foto por vez, para não estourar a memória do celular
        function next() {
            current = queue.shift() || null;
            if (!current) {
                setStatus(notes.join(' '), notes.length > 0);
                refresh();
                return;
            }

            var file = current;
            var gen = generation;
            base.shrinkToDataUrl(file, base.PHOTO_SIDE, function (data) {
                if (gen !== generation) {
                    return;
                }
                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'defect_photos[]';
                field.value = data;
                addItem(field, data);
                next();
            }, function () {
                if (gen !== generation) {
                    return;
                }
                var field = originalField(file);
                if (field) {
                    addItem(field, null);
                    notes.push('Uma foto não pôde ser reduzida aqui e será enviada como está.');
                } else {
                    notes.push(base.UNREADABLE);
                }
                next();
            });
        }

        inputs.forEach(function (input) {
            input.addEventListener('change', function () {
                var files = Array.prototype.slice.call(input.files || []);
                // Limpa o campo para poder escolher (ou tirar) de novo a mesma foto
                input.value = '';
                if (!files.length) {
                    return;
                }

                if (!current) {
                    notes = [];
                }
                var room = Math.max(0, max - count(container) - pending());
                if (files.length > room) {
                    files = files.slice(0, room);
                    notes.push('Máximo de ' + max + ' fotos: as que passaram disso não foram adicionadas.');
                }
                if (!files.length) {
                    if (!current) {
                        setStatus(notes.join(' '), true);
                    }
                    return;
                }

                queue = queue.concat(files);
                if (!current) {
                    setStatus(files.length > 1 ? 'Processando as fotos...' : 'Processando a foto...');
                    next();
                }
            });
        });

        // Troca de equipamento: as fotos do anterior não podem ir para o novo
        container.ciDefectClear = function () {
            generation++;
            queue = [];
            current = null;
            notes = [];
            list.innerHTML = '';
            fields.innerHTML = '';
            setStatus('');
            refresh();
        };

        refresh();
    }

    window.ChecklistDefectPhotos = {
        setup: setup,
        count: count,
        /** Ainda há foto sendo reduzida no bloco. */
        busy: function (container) {
            return !!container && container.hasAttribute('data-ci-defect-busy');
        },
        /** Fotos prontas para envio, como arquivos: as reduzidas (JPEG) e as originais. */
        files: function (container) {
            var list = [];
            qsa('[data-ci-defect-fields] input', container).forEach(function (input) {
                if (input.type === 'file') {
                    if (input.files && input.files[0]) {
                        list.push(input.files[0]);
                    }
                } else if (input.value) {
                    var blob = base.dataUrlToBlob(input.value);
                    if (blob) {
                        list.push(blob);
                    }
                }
            });
            return list;
        },
        clear: function (container) {
            if (!container) {
                return;
            }
            var blocks = qsa('[data-ci-defect-photos]', container);
            if (container.ciDefectClear) {
                blocks.push(container);
            }
            blocks.forEach(function (block) {
                if (block.ciDefectClear) {
                    block.ciDefectClear();
                }
            });
        }
    };
})();

(function () {
    'use strict';

    var base = window.ChecklistItens;
    var qsa = base.qsa;
    var notify = base.emit;

    // Situações da localização (iguais às de Location.php)
    var LOCATION = {OK: 1, DENIED: 2, UNAVAILABLE: 3, TIMEOUT: 4, INSECURE: 5};

    /**
     * Localização junto com a selfie: uma leitura só (não é rastreamento). Obrigatória, mas não
     * bloqueia o salvamento: se faltar, mostra o motivo e o botão "Tentar de novo", e o registro
     * vai com a situação para o gestor ver. Também só funciona em contexto seguro (HTTPS).
     */
    function setupLocation(box) {
        var fields = {};
        qsa('[data-ci-location-field]', box).forEach(function (input) {
            fields[input.getAttribute('data-ci-location-field')] = input;
        });
        var line = box.querySelector('[data-ci-location]');
        var text = box.querySelector('[data-ci-location-text]');
        var retry = box.querySelector('[data-ci-location-retry]');
        var pending = false;
        var waiters = [];

        if (!fields.status) {
            return null;
        }

        function show(message, state) {
            if (!line) {
                return;
            }
            line.hidden = false;
            line.classList.toggle('ci-location-ok', state === 'ok');
            line.classList.toggle('ci-location-missing', state === 'missing');
            if (text) {
                text.textContent = message;
            }
        }

        // quiet: leitura restaurada do aparelho, sem avisar a página de uma mudança
        function finish(status, coords, quiet) {
            var accuracy = coords && coords.accuracy ? Math.round(coords.accuracy) : null;

            pending = false;
            fields.status.value = String(status);
            fields.latitude.value = coords ? String(coords.latitude) : '';
            fields.longitude.value = coords ? String(coords.longitude) : '';
            fields.accuracy.value = accuracy !== null ? String(accuracy) : '';

            if (status === LOCATION.OK) {
                show('Localização registrada' + (accuracy !== null ? ' (±' + accuracy + ' m)' : '') + '.', 'ok');
            } else {
                var reasons = {};
                reasons[LOCATION.DENIED] = 'a permissão de localização foi negada';
                reasons[LOCATION.UNAVAILABLE] = 'a localização do aparelho está desligada ou sem sinal';
                reasons[LOCATION.TIMEOUT] = 'o aparelho demorou para responder';
                reasons[LOCATION.INSECURE] = 'o navegador bloqueou (endereço sem HTTPS)';
                show('A localização é obrigatória e não foi registrada: ' + (reasons[status] || 'não foi possível ler')
                    + '. Ative a localização do aparelho e toque em "Tentar de novo". Se não der, o registro será salvo sem localização.', 'missing');
            }
            if (retry) {
                retry.hidden = status === LOCATION.OK;
            }

            var callbacks = waiters;
            waiters = [];
            callbacks.forEach(function (callback) {
                callback();
            });
            if (!quiet) {
                notify(box, 'ci:change');
            }
        }

        function request() {
            if (pending) {
                return;
            }
            if (!window.isSecureContext || !navigator.geolocation) {
                finish(LOCATION.INSECURE, null);
                return;
            }
            pending = true;
            if (retry) {
                retry.hidden = true;
            }
            show('Obtendo localização...', '');
            navigator.geolocation.getCurrentPosition(function (position) {
                finish(LOCATION.OK, position.coords);
            }, function (error) {
                var code = error ? error.code : 0;
                finish(code === 1 ? LOCATION.DENIED : (code === 3 ? LOCATION.TIMEOUT : LOCATION.UNAVAILABLE), null);
            }, {enableHighAccuracy: true, timeout: 20000, maximumAge: 0});
        }

        if (retry) {
            retry.addEventListener('click', request);
        }

        return {
            /** Leitura atual, ou null se ainda não houve. */
            read: function () {
                if (fields.status.value === '') {
                    return null;
                }
                return {
                    status: fields.status.value,
                    latitude: fields.latitude.value,
                    longitude: fields.longitude.value,
                    accuracy: fields.accuracy.value
                };
            },
            /** Leitura guardada no aparelho (tela reaberta) volta aos campos e à linha. */
            restore: function (saved) {
                var status = saved ? parseInt(saved.status, 10) : 0;
                if (!status) {
                    return;
                }
                finish(status, status === LOCATION.OK ? {
                    latitude: saved.latitude,
                    longitude: saved.longitude,
                    accuracy: saved.accuracy ? parseFloat(saved.accuracy) : null
                } : null, true);
            },
            // Primeira leitura: ao tocar no botão da selfie (ou ao salvar, se ainda não houve)
            start: function () {
                if (!pending && fields.status.value === '') {
                    request();
                }
            },
            isPending: function () {
                return pending;
            },
            whenDone: function (callback) {
                if (pending) {
                    waiters.push(callback);
                } else {
                    callback();
                }
            }
        };
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

        // A leitura da localização começa quando a pessoa toca no botão da selfie
        var geo = setupLocation(box);
        box.ciLocation = geo;
        if (geo) {
            box.addEventListener('click', function (event) {
                var target = event.target;
                if (target && target.closest && target.closest('[data-ci-camera-open], [data-ci-selfie-native]')) {
                    geo.start();
                }
            });
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

        /**
         * Reduz a imagem (foto ou quadro do vídeo) e grava no campo oculto.
         *
         * @return {boolean} false quando o aparelho não conseguiu gerar a foto reduzida
         */
        function store(source, width, height) {
            var data = '';
            try {
                data = base.toDataUrl(base.reduce(source, width, height, base.SELFIE_SIDE));
            } catch (e) {
                data = '';
            }
            if (!data) {
                return false;
            }

            hidden.value = data;
            preview.src = data;
            preview.hidden = false;
            box.setAttribute('data-ci-selfie-ready', '1');
            setStatus('Selfie pronta.');
            notify(box, 'ci:change');
            return true;
        }

        /** O navegador não reduziu a foto do aparelho: o arquivo original segue no envio. */
        function keepOriginal() {
            preview.hidden = true;
            box.setAttribute('data-ci-selfie-ready', '1');
            setStatus('Foto recebida (será enviada como está).');
            notify(box, 'ci:change');
        }

        box.ciSelfie = {
            read: function () {
                return {
                    ready: box.hasAttribute('data-ci-selfie-ready'),
                    data: hidden.value,
                    file: input.files && input.files[0] ? input.files[0] : null,
                    location: geo ? geo.read() : null
                };
            },
            restore: function (blob, location) {
                preview.src = URL.createObjectURL(blob);
                preview.hidden = false;
                setStatus('Selfie guardada neste aparelho.');
                box.setAttribute('data-ci-selfie-ready', '1');
                if (geo) {
                    geo.restore(location);
                }
            }
        };

        // ---------------------------------------------------------- câmera nativa do aparelho
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            clear();
            if (!file) {
                notify(box, 'ci:change');
                return;
            }

            setStatus('Processando a foto...');
            base.loadImage(file, function (img) {
                if (!store(img, img.naturalWidth, img.naturalHeight)) {
                    keepOriginal();
                    return;
                }
                // A versão reduzida já está no campo oculto: não reenviar o arquivo original.
                input.value = '';
                if (label) {
                    label.textContent = 'Tirar outra selfie';
                }
            }, keepOriginal);
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
            if (!store(video, video.videoWidth, video.videoHeight)) {
                setStatus('Não foi possível usar esta foto. Tire de novo.');
                return;
            }
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
     * Localização obrigatória sem bloquear: começa a leitura da selfie, se ainda não houve, e chama
     * done uma vez quando ela termina ou depois de LOCATION_WAIT_MS. onWait é chamado só quando há
     * espera (para avisar na tela).
     */
    function whenLocated(box, done, onWait) {
        var geo = box ? box.ciLocation : null;
        var called = false;
        function once() {
            if (!called) {
                called = true;
                done();
            }
        }

        if (!geo) {
            once();
            return;
        }
        geo.start();
        if (!geo.isPending()) {
            once();
            return;
        }
        if (onWait) {
            onWait();
        }
        geo.whenDone(once);
        window.setTimeout(once, base.LOCATION_WAIT_MS);
    }

    /** Bloco da selfie para quem monta a própria tela (entrega para embarcação). */
    window.ChecklistSelfie = {
        /** {ready, data (reduzida, data URL), file (original), location} ou null */
        read: function (box) {
            return box && box.ciSelfie ? box.ciSelfie.read() : null;
        },
        /** Selfie e localização guardadas no aparelho voltam ao bloco. */
        restore: function (box, blob, location) {
            if (box && box.ciSelfie) {
                box.ciSelfie.restore(blob, location);
            }
        },
        whenLocated: whenLocated
    };

    /**
     * Fluxo de retirada/devolução: mostra cada passo só quando o anterior foi respondido,
     * desativa os campos dos tipos não escolhidos e só libera os botões quando dá para enviar.
     * As regras valem de novo no servidor; aqui é só para guiar quem está no celular.
     */
    function setupFlow(form) {
        var flow = form.getAttribute('data-ci-flow');
        var lastKey = null;

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

        function updateButtons(problems, photos) {
            var hasSelfie = qsa('[data-ci-selfie-box]', form).some(function (box) {
                return box.hasAttribute('data-ci-selfie-ready');
            });

            qsa('[data-ci-require]', form).forEach(function (button) {
                var requirement = button.getAttribute('data-ci-require');
                // Botão de uma parte escondida nunca envia (nem pelo Enter do teclado)
                var ready = !button.closest('[hidden]');
                if (requirement === 'problems') {
                    ready = ready && problems > 0;
                } else if (requirement === 'defect') {
                    // problema marcado exige pelo menos uma foto do defeito
                    ready = ready && problems > 0 && photos > 0;
                } else if (requirement === 'selfie') {
                    ready = ready && hasSelfie;
                }
                button.disabled = !ready;
            });
        }

        /** Quantas fotos do defeito estão prontas para envio (redimensionadas ou arquivo nativo). */
        function countPhotos() {
            return window.ChecklistDefectPhotos.count(form.querySelector('[data-ci-section="photos"]'));
        }

        /** Campos de uma parte escondida não vão no envio (ex.: a escolha "é o problema do laudo"). */
        function enable(name, enabled) {
            sections(name).forEach(function (el) {
                qsa('input', el).forEach(function (input) {
                    input.disabled = !enabled;
                });
            });
        }

        function update() {
            // Conferência do gestor: só exige a selfie
            if (flow === 'confirm') {
                updateButtons(0, 0);
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

            var chosen = flow === 'checkout' ? checked('items_id') : checked('usage_id');
            var itemChosen = flow === 'checkout' ? !!chosen : itemtype !== '';

            // Trocou de equipamento: as respostas e fotos do anterior não valem para o novo
            var chosenKey = itemtype + ':' + (chosen ? chosen.value : '');
            if (lastKey !== null && chosenKey !== lastKey) {
                qsa('input[name="is_ok"], input[name="problems[]"], input[name="known_issue"]', form).forEach(function (input) {
                    input.checked = false;
                });
                window.ChecklistDefectPhotos.clear(form.querySelector('[data-ci-section="photos"]'));
            }
            lastKey = chosenKey;
            var answer = checked('is_ok');
            var isOk = !!answer && answer.value === '1';
            var isNok = !!answer && answer.value === '0';
            var problems = qsa('input[name="problems[]"]:checked', form).filter(function (input) {
                return !input.disabled;
            }).length;
            var photos = countPhotos();

            // Laudo em aberto (plugin Laudo): alerta do item escolhido e escolha "é o problema do laudo"
            var knownKey = chosen ? chosen.getAttribute('data-ci-known-key') : '';
            var known = !!chosen && chosen.getAttribute('data-ci-known') === '1';
            qsa('[data-ci-known-for]', form).forEach(function (el) {
                el.hidden = !itemChosen || el.getAttribute('data-ci-known-for') !== knownKey;
            });
            qsa('[data-ci-unknown-only]', form).forEach(function (el) {
                el.hidden = known;
            });

            var defectReady = itemChosen && isNok && problems > 0 && photos > 0;
            var knownChoiceVisible = defectReady && known;
            enable('known-choice', knownChoiceVisible);
            var knownChoice = knownChoiceVisible ? checked('known_issue') : null;

            show('item', flow === 'checkout' && itemtype !== '');
            show('check', itemChosen);
            show('problems', itemChosen && isNok);
            // As fotos podem ir no envio mesmo escondidas: o servidor só as usa com problema marcado
            show('photos', itemChosen && isNok);
            show('refuse', itemChosen && isNok && !known);
            show('known-choice', knownChoiceVisible);

            var selfieVisible = flow === 'checkout'
                ? itemChosen && (isOk || (knownChoice !== null && knownChoice.value === '1'))
                : itemChosen && (isOk || (defectReady && (!known || knownChoice !== null)));
            show('selfie', selfieVisible);
            updateButtons(problems, photos);
        }

        form.addEventListener('change', update);
        form.addEventListener('ci:change', update);
        form.addEventListener('ci:defectphotos', update);
        function disableButtons() {
            qsa('button[type="submit"]', form).forEach(function (button) {
                button.disabled = true;
            });
        }

        form.addEventListener('submit', function (event) {
            // Localização obrigatória sem bloquear: se a leitura de alguma selfie visível ainda
            // está em andamento, espera ela terminar (no máximo LOCATION_WAIT_MS) e envia.
            // "Bloquear" não leva selfie nem localização: envia na hora
            var blocking = !!event.submitter && event.submitter.name === 'refuse';
            if (!form.ciLocationWaited && !blocking) {
                var boxes = qsa('[data-ci-selfie-box]', form).filter(function (box) {
                    return box.ciLocation && box.offsetParent !== null;
                });
                boxes.forEach(function (box) {
                    box.ciLocation.start();
                });
                var waiting = boxes.filter(function (box) {
                    return box.ciLocation.isPending();
                });

                if (waiting.length) {
                    event.preventDefault();
                    form.ciLocationWaited = true;

                    var submitter = event.submitter || null;
                    var sent = false;
                    var send = function () {
                        if (sent) {
                            return;
                        }
                        sent = true;
                        // form.submit() não leva o botão clicado (refuse/save/confirm): vai num campo oculto
                        if (submitter && submitter.name) {
                            var extra = document.createElement('input');
                            extra.type = 'hidden';
                            extra.name = submitter.name;
                            extra.value = submitter.value;
                            form.appendChild(extra);
                        }
                        HTMLFormElement.prototype.submit.call(form);
                    };

                    var left = waiting.length;
                    waiting.forEach(function (box) {
                        whenLocated(box, function () {
                            left -= 1;
                            if (left === 0) {
                                send();
                            }
                        });
                    });
                    disableButtons();
                    return;
                }
            }

            // Evita envio em dobro; o atraso mantém no envio o botão clicado (refuse/save).
            window.setTimeout(disableButtons, 0);
        });
        update();
    }

    /** Busca na lista de equipamentos (número de série, nome, modelo). */
    function setupFilter(input) {
        // Enter/"Ir" do teclado na busca não envia o formulário da retirada
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
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

    function init() {
        qsa('[data-ci-selfie-box]').forEach(setupSelfie);
        qsa('[data-ci-defect-photos]').forEach(window.ChecklistDefectPhotos.setup);
        qsa('form[data-ci-flow]').forEach(setupFlow);
        qsa('[data-ci-filter]').forEach(setupFilter);
        qsa('[data-ci-idle]').forEach(setupIdle);
        qsa('[data-ci-redirect]').forEach(setupRedirect);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
