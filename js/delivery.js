/* Checklist uso de equipamentos: entrega de rádios para embarcação e recebimento de volta.

   Tudo o que o gestor faz fica guardado primeiro no aparelho (js/offline.js) e segue para o
   servidor em partes, uma tarefa por vez e na ordem em que foi feita. O servidor é a verdade sobre
   os rádios reservados e as fotos que já recebeu; o aparelho é a verdade sobre o que ainda não foi
   enviado. As regras valem de novo no servidor: aqui é só para guiar quem está no celular. */
(function () {
    'use strict';

    var MAX_SIDE = 1600;               // maior lado de cada foto, em pixels
    var QUALITY = 0.8;                 // qualidade do JPEG gerado
    var MAX_BYTES = 5 * 1024 * 1024;   // limite de cada arquivo no servidor
    var LOCATION_WAIT_MS = 10000;      // espera máxima pela localização ao concluir
    var SAVE_DELAY_MS = 400;           // espera para gravar o que está sendo digitado
    var VESSEL_FILTER_MIN = 7;         // com mais embarcações do que isso, aparece a busca

    var GONE_DELIVERY = 'Esta separação não existe mais no servidor: foi cancelada, ficou parada tempo demais e expirou, ou já foi concluída. '
        + 'Os rádios foram liberados. As fotos continuam guardadas neste aparelho: reserve os rádios de novo para continuar, ou cancele para descartar.';
    var GONE_RECEIPT = 'Este recebimento não existe mais no servidor: foi cancelado ou ficou parado tempo demais e expirou. '
        + 'As fotos continuam guardadas neste aparelho e voltam quando você abrir de novo o recebimento da mesma entrega.';

    var root = null;        // bloco da tela
    var ui = {};            // elementos da tela
    var offline = null;     // window.ChecklistOffline
    var data = null;        // dados que vieram com a página
    var mode = '';          // "delivery" ou "receipt"
    var isDelivery = true;
    var limits = {};
    var store = null;
    var queue = null;
    var draft = null;       // o que está guardado no aparelho
    var server = null;      // último estado que o servidor devolveu
    var available = [];     // rádios que ainda podem entrar na entrega
    var tasks = {};         // tarefas na fila de envio: id => meta
    var hasBlob = {};       // fotos cujo arquivo está no aparelho: uuid => true
    var photoUrls = {};     // miniaturas das fotos do aparelho: uuid => URL
    var photoEls = {};      // miniaturas desenhadas: uuid => elemento
    var photoQueue = [];    // fotos escolhidas esperando a redução
    var photoCurrent = null;
    var photoNotes = [];
    var panelItem = null;   // rádio com o painel de problema aberto
    var selfieSaving = null;
    var lastSelfie = null;
    var saveTimer = null;
    var vesselPlaceholder = 'Escolha a embarcação';
    var finishText = '';    // texto do botão de concluir, como veio na página
    var ready = false;      // dados do aparelho carregados
    var halted = false;     // tela parada (erro ao abrir, ou recebimento que não existe mais)
    var stalled = false;    // o servidor recusou um envio sem ser por regra: não reenviar sozinho
    var leaving = false;    // saindo da página de propósito

    // ------------------------------------------------------------------ utilidades

    function qsa(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function icon(name) {
        return el('i', 'ti ' + name);
    }

    function button(className, iconName, label) {
        var node = el('button', className);
        node.type = 'button';
        node.appendChild(icon(iconName));
        node.appendChild(document.createTextNode(' ' + label));
        return node;
    }

    function toInt(value) {
        return parseInt(value, 10) || 0;
    }

    function clampBatteries(value) {
        return Math.min(99, Math.max(0, toInt(value)));
    }

    function plural(count, singular, many) {
        return count + ' ' + (count === 1 ? singular : many);
    }

    /** Data do banco (AAAA-MM-DD...) no formato do dia a dia. */
    function formatDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return match ? match[3] + '/' + match[2] + '/' + match[1] : String(value || '');
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

    // As chaves desta tela têm prefixo próprio, separado das chaves da fila de envio
    function key(name) {
        return 'form-' + mode + ':' + name;
    }

    function photoKey(uuid) {
        return key('photo:' + uuid);
    }

    function defectKey(uuid) {
        return key('defect:' + uuid);
    }

    // ------------------------------------------------------------------ mensagens

    function message(text, type, focus) {
        if (!ui.message) {
            return;
        }
        ui.message.className = 'alert alert-' + (type || 'danger');
        ui.message.textContent = text || '';
        ui.message.hidden = !text;
        if (text && focus && ui.message.scrollIntoView) {
            ui.message.scrollIntoView({block: 'center'});
        }
    }

    function clearMessage() {
        message('');
    }

    /** Erro que impede a tela de funcionar. */
    function fail(text) {
        halted = true;
        if (ui.form) {
            ui.form.hidden = true;
        }
        if (ui.sync) {
            ui.sync.hidden = true;
        }
        message(text, 'danger', true);
    }

    // ------------------------------------------------------------------ rascunho no aparelho

    function emptyDraft() {
        return {
            uuid: offline.uuid(),   // identificador desta separação, gerado no aparelho
            serverId: 0,            // id da entrega (ou do recebimento) no servidor
            deliveryId: 0,          // recebimento: entrega a que ele pertence
            vessel: 0,
            picked: [],             // rádios marcados e ainda não reservados
            batteries: 0,
            isOk: null,
            comment: '',
            photos: [],             // {uuid, sent, url}
            returned: {},           // recebimento: usage_id => false quando o rádio não voltou
            problems: {},           // recebimento: usage_id => {ids, names, known, photos}
            selfie: false,
            location: null,
            finishing: false        // a conclusão já foi para a fila de envio
        };
    }

    function normalize(saved) {
        var base = emptyDraft();
        if (!saved || typeof saved !== 'object') {
            return base;
        }

        if (typeof saved.uuid === 'string' && saved.uuid !== '') {
            base.uuid = saved.uuid;
        }
        base.serverId = toInt(saved.serverId);
        base.deliveryId = toInt(saved.deliveryId);
        base.vessel = toInt(saved.vessel);
        if (Array.isArray(saved.picked)) {
            base.picked = saved.picked.map(toInt).filter(function (id) {
                return id > 0;
            });
        }
        base.batteries = clampBatteries(saved.batteries);
        base.isOk = saved.isOk === 0 || saved.isOk === 1 ? saved.isOk : null;
        base.comment = typeof saved.comment === 'string' ? saved.comment : '';
        if (Array.isArray(saved.photos)) {
            saved.photos.forEach(function (photo) {
                if (photo && typeof photo.uuid === 'string' && photo.uuid !== '') {
                    base.photos.push({uuid: photo.uuid, sent: !!photo.sent, url: typeof photo.url === 'string' ? photo.url : ''});
                }
            });
        }
        if (saved.returned && typeof saved.returned === 'object') {
            base.returned = saved.returned;
        }
        if (saved.problems && typeof saved.problems === 'object') {
            base.problems = saved.problems;
        }
        base.selfie = !!saved.selfie;
        base.location = saved.location && typeof saved.location === 'object' ? saved.location : null;
        base.finishing = !!saved.finishing;

        return base;
    }

    function saveDraft() {
        window.clearTimeout(saveTimer);
        if (!store || !draft || leaving) {
            return;
        }
        store.set(key('draft'), draft).then(null, function () {
            if (ui.nostore) {
                ui.nostore.hidden = false;
            }
        });
    }

    function saveSoon() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveDraft, SAVE_DELAY_MS);
    }

    /** Apaga tudo o que esta tela guardou no aparelho (rascunho, fotos e selfie). */
    function wipeLocal() {
        Object.keys(photoUrls).forEach(function (uuid) {
            URL.revokeObjectURL(photoUrls[uuid]);
        });
        photoUrls = {};
        hasBlob = {};
        window.clearTimeout(saveTimer);

        return store.clear(key('')).then(null, function () {});
    }

    // ------------------------------------------------------------------ fila de envio

    function countTasks(kind, field, value) {
        var total = 0;
        Object.keys(tasks).forEach(function (id) {
            var meta = tasks[id];
            if (meta.kind === kind && (field === undefined || meta[field] === value)) {
                total++;
            }
        });
        return total;
    }

    /** Põe uma tarefa na fila. Nunca rejeita: devolve false se não deu para guardar. */
    function push(action, fields, blobs, meta) {
        var task = {id: offline.uuid(), action: action, fields: fields, blobs: blobs || [], meta: meta || {}};
        if (!task.meta.kind) {
            task.meta.kind = action;
        }
        tasks[task.id] = task.meta;
        renderSync();

        return queue.push(task).then(function () {
            renderSync();
            return true;
        }, function () {
            delete tasks[task.id];
            renderSync();
            message('Não foi possível guardar esta alteração no aparelho. Tente de novo.', 'danger', true);
            return false;
        });
    }

    /** Esvazia a fila: as tarefas eram de uma separação que não vale mais. */
    function dropTasks() {
        tasks = {};
        return queue.clear().then(null, function () {});
    }

    function getJson(action) {
        return window.fetch(data.ajaxUrl + '?action=' + encodeURIComponent(action), {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (response) {
            return response.json();
        });
    }

    /** Atualiza a lista de rádios disponíveis (outra pessoa pode ter retirado algum). */
    function refreshAvailable() {
        if (!isDelivery || !window.fetch) {
            return;
        }
        getJson('available').then(function (response) {
            if (response && response.ok && Array.isArray(response.items)) {
                available = response.items;
                if (ready && !halted) {
                    renderAvailable();
                }
            }
        }, function () {});
    }

    // ------------------------------------------------------------------ estado do servidor

    function serverItems() {
        return server && Array.isArray(server.items) ? server.items : [];
    }

    function removedItems() {
        return server && Array.isArray(server.removed) ? server.removed : [];
    }

    function findItem(usageId) {
        var found = null;
        serverItems().forEach(function (item) {
            if (item.usage_id === usageId) {
                found = item;
            }
        });
        return found;
    }

    function returnedItems() {
        return serverItems().filter(function (item) {
            return draft.returned[item.usage_id] !== false;
        });
    }

    function maxItems() {
        return toInt(limits.max_items) || 8;
    }

    function minPhotos() {
        return Math.max(0, toInt(limits.min_photos));
    }

    function maxPhotos() {
        return toInt(limits.max_photos) || 40;
    }

    function findPhoto(uuid) {
        for (var i = 0; i < draft.photos.length; i++) {
            if (draft.photos[i].uuid === uuid) {
                return i;
            }
        }
        return -1;
    }

    /** Fotos que mudaram de separação: voltam a "não enviada" se o arquivo ainda está no aparelho. */
    function keepPhotosUnsent() {
        draft.photos = draft.photos.filter(function (photo) {
            photo.sent = false;
            photo.url = '';
            return !!hasBlob[photo.uuid];
        });
    }

    /** Junta a lista de fotos do aparelho com a que o servidor diz ter. */
    function syncPhotos(list) {
        var deleting = {};
        Object.keys(tasks).forEach(function (id) {
            if (tasks[id].kind === 'photo_delete') {
                deleting[tasks[id].uuid] = true;
            }
        });

        var remote = {};
        list.forEach(function (photo) {
            if (photo && photo.uuid && !deleting[photo.uuid]) {
                remote[photo.uuid] = photo.url || '';
            }
        });

        var known = {};
        draft.photos = draft.photos.filter(function (photo) {
            known[photo.uuid] = true;
            if (remote[photo.uuid] !== undefined) {
                photo.sent = true;
                photo.url = remote[photo.uuid];
                return true;
            }
            if (photo.sent) {
                // o servidor não tem mais esta foto: reenvia se o arquivo ainda está no aparelho
                photo.sent = false;
                photo.url = '';
                return !!hasBlob[photo.uuid];
            }
            return true;
        });

        // fotos que só o servidor tem (enviadas por outro aparelho, ou o navegador foi limpo)
        Object.keys(remote).forEach(function (uuid) {
            if (!known[uuid]) {
                draft.photos.push({uuid: uuid, sent: true, url: remote[uuid]});
            }
        });
    }

    function applyState(state) {
        server = state;
        draft.serverId = toInt(state.id);
        if (state.client_uuid) {
            draft.uuid = state.client_uuid;
        }
        if (state.limits) {
            Object.keys(state.limits).forEach(function (name) {
                limits[name] = state.limits[name];
            });
        }

        if (isDelivery) {
            if (state.vessel && state.vessel.id && !countTasks('set_vessel')) {
                draft.vessel = toInt(state.vessel.id);
            }
            // o que já está reservado (ou saiu por problema) sai da lista de marcados
            var taken = {};
            serverItems().concat(removedItems()).forEach(function (item) {
                taken[item.items_id] = true;
            });
            draft.picked = draft.picked.filter(function (id) {
                return !taken[id];
            });
        } else {
            draft.deliveryId = state.delivery ? toInt(state.delivery.id) : 0;
            // marcações de rádios que não estão mais com a embarcação
            var present = {};
            serverItems().forEach(function (item) {
                present[item.usage_id] = true;
            });
            Object.keys(draft.problems).forEach(function (usageId) {
                if (!present[usageId]) {
                    dropProblem(usageId);
                }
            });
            Object.keys(draft.returned).forEach(function (usageId) {
                if (!present[usageId]) {
                    delete draft.returned[usageId];
                }
            });
        }

        if (panelItem && !findItem(panelItem.usage_id)) {
            closeProblem();
        }
        syncPhotos(Array.isArray(state.photos) ? state.photos : []);
    }

    /**
     * Ao abrir a tela: compara o que está no aparelho com o que o servidor tem.
     * - mesma separação: continua de onde parou;
     * - o servidor não tem mais (cancelada, expirada ou concluída): as fotos do aparelho ficam
     *   para uma separação nova, a não ser que a conclusão já tivesse sido enviada;
     * - o servidor tem outra: vale a do servidor.
     */
    function reconcile(state) {
        var previous = draft.serverId;

        if (state && previous === toInt(state.id)) {
            if (draft.finishing && !countTasks('finish')) {
                draft.finishing = false;
            }
            applyState(state);
            return Promise.resolve();
        }
        if (!state && !previous) {
            server = null;
            return Promise.resolve();
        }
        if (!state && draft.finishing && countTasks('finish')) {
            // a conclusão ainda está na fila: o servidor responde se já concluiu ou se não existe mais
            server = null;
            return Promise.resolve();
        }

        return dropTasks().then(function () {
            var concluded = draft.finishing;
            var other = !isDelivery && !!state && draft.deliveryId > 0 && state.delivery
                && draft.deliveryId !== toInt(state.delivery.id);

            if (concluded || other) {
                // já tinha sido concluída, ou é de outra entrega: nada do aparelho serve mais
                return wipeLocal().then(function () {
                    draft = emptyDraft();
                    if (concluded && !state) {
                        message(isDelivery
                            ? 'A entrega que estava neste aparelho já foi concluída.'
                            : 'O recebimento que estava neste aparelho já foi concluído.', 'info');
                    } else if (other) {
                        message('As fotos guardadas neste aparelho eram do recebimento de outra entrega e foram descartadas.', 'warning');
                    }
                });
            }

            keepPhotosUnsent();
            draft.serverId = 0;
            draft.uuid = offline.uuid();
            draft.picked = [];
            if (previous && !state) {
                message(GONE_DELIVERY, 'warning');
            }
        }).then(function () {
            if (state) {
                applyState(state);
            } else {
                server = null;
            }
        });
    }

    /**
     * A separação (ou o recebimento) não existe mais no servidor. Se foi concluída (por outro
     * aparelho), as fotos já estão lá e nada do aparelho serve mais; se foi cancelada ou expirou,
     * as fotos do aparelho ficam para uma separação nova.
     */
    function gone(response) {
        if (response && response.concluded) {
            if (halted) {
                return;
            }
            halted = true;
            leaving = true;
            dropTasks().then(wipeLocal).then(function () {
                ui.form.hidden = true;
                ui.finishing.hidden = true;
                message(response.message || 'Este registro já foi concluído.', 'info', true);
                var link = el('a', 'btn btn-primary ms-2', 'Voltar ao início');
                link.href = data.homeUrl;
                ui.message.appendChild(link);
            });
            return;
        }

        if (isDelivery) {
            if (!draft.serverId) {
                return;
            }
            draft.serverId = 0;
            dropTasks().then(function () {
                keepPhotosUnsent();
                draft.uuid = offline.uuid();
                draft.picked = [];
                draft.finishing = false;
                server = null;
                closeProblem();
                saveDraft();
                refreshAvailable();
                render();
                message(GONE_DELIVERY, 'warning', true);
            });
            return;
        }

        if (halted) {
            return;
        }
        halted = true;
        dropTasks().then(function () {
            keepPhotosUnsent();
            draft.finishing = false;
            saveDraft();
            render();
            message(GONE_RECEIPT, 'warning', true);
            var link = el('a', 'btn btn-warning ms-2', 'Abrir o recebimento de novo');
            link.href = window.location.pathname + '?receive=1';
            ui.message.appendChild(link);
        });
    }

    // ------------------------------------------------------------------ respostas do servidor

    function labelOf(itemsId) {
        var label = '';
        available.concat(serverItems()).forEach(function (item) {
            if ((item.items_id || item.id) === itemsId) {
                label = item.label;
            }
        });
        return label || ('#' + itemsId);
    }

    /** Rádios que o servidor não conseguiu reservar (já saíram, limite atingido). */
    function reportFailed(response) {
        var reserved = {};
        serverItems().forEach(function (item) {
            reserved[item.items_id] = true;
        });
        var lines = [];
        (Array.isArray(response.failed) ? response.failed : []).forEach(function (entry) {
            // reenvio da mesma reserva: o rádio já é desta entrega, não é falha
            if (entry && !reserved[entry.items_id]) {
                lines.push(labelOf(toInt(entry.items_id)) + ': ' + (entry.message || 'não foi possível reservar'));
            }
        });

        if (lines.length) {
            message('Não foi possível reservar: ' + lines.join('; ') + '.', 'warning', true);
        } else if (response.ok === false && response.message) {
            message(response.message, 'danger', true);
        }
    }

    function finished(response) {
        leaving = true;
        var target = response && response.redirect ? response.redirect : data.homeUrl;
        wipeLocal().then(function () {
            window.location.href = target;
        });
    }

    function onDone(task, response) {
        var meta = task && task.meta ? task.meta : {};
        var index;
        response = response || {};
        if (task) {
            delete tasks[task.id];
        }
        if (leaving || !draft) {
            return;
        }

        switch (meta.kind) {
            case 'photo':
                index = findPhoto(meta.uuid);
                if (index !== -1) {
                    draft.photos[index].sent = true;
                    if (response.photo && response.photo.url) {
                        draft.photos[index].url = response.photo.url;
                    }
                }
                saveDraft();
                renderSync();
                renderPhotos();
                return;

            case 'photo_delete':
                renderSync();
                return;

            case 'defect_photo':
                // na entrega a foto já está no servidor; no recebimento ela fica até a conclusão,
                // porque a conclusão pode ser recusada e enviada de novo
                if (isDelivery && meta.key) {
                    store.removeBlob(meta.key).then(null, function () {});
                }
                renderSync();
                return;

            case 'finish':
                finished(response);
                return;

            case 'cancel':
                finished({redirect: data.homeUrl});
                return;

            case 'reserve':
            case 'add_items':
                if (response.delivery) {
                    applyState(response.delivery);
                }
                reportFailed(response);
                refreshAvailable();
                break;

            case 'item_problem':
                if (response.delivery) {
                    applyState(response.delivery);
                }
                if (response.laudo_closed) {
                    message(response.message || 'O laudo deste equipamento foi concluído: o problema bloqueia o item.', 'warning', true);
                } else if (response.blocked) {
                    message('O rádio ' + (meta.label || '') + ' saiu da entrega e ficou bloqueado. Se precisar, reserve outro no lugar.', 'info');
                }
                refreshAvailable();
                break;

            case 'remove_item':
                if (response.delivery) {
                    applyState(response.delivery);
                }
                refreshAvailable();
                break;

            default:
                if (response.delivery) {
                    applyState(response.delivery);
                }
        }

        queueMissing();
        saveDraft();
        render();
    }

    function onRejected(task, response) {
        var meta = task && task.meta ? task.meta : {};
        var text;
        response = response || {};
        if (task) {
            delete tasks[task.id];
        }
        if (leaving || !draft) {
            return;
        }
        if (response.code === 'gone') {
            gone(response);
            return;
        }
        text = response.message || 'O servidor recusou esta alteração.';

        if (response.code === 'http' || response.code === 'right') {
            // Não foi uma regra do sistema que recusou (bloqueio no caminho, limite de tamanho, perfil
            // trocado em outra aba): nada do aparelho é descartado, e o envio só é tentado de novo
            // quando a página for recarregada
            stalled = true;
            if (meta.kind === 'finish') {
                draft.finishing = false;
            }
            message(text + ' O que você fez continua guardado neste aparelho. Recarregue a página para tentar de novo; se continuar, avise o TI.', 'danger', true);
            saveDraft();
            render();
            return;
        }

        switch (meta.kind) {
            case 'photo':
                // o arquivo foi removido antes do envio (code "blob"), ou o servidor recusou a foto
                if (response.code !== 'blob') {
                    forgetPhoto(meta.uuid);
                    photoNotes.push('Uma foto foi recusada: ' + text);
                }
                break;

            case 'photo_delete':
                break;

            case 'defect_photo':
                if (response.code !== 'blob') {
                    message('Uma foto do defeito foi recusada: ' + text, 'danger');
                }
                if (isDelivery && meta.key) {
                    store.removeBlob(meta.key).then(null, function () {});
                }
                break;

            case 'reserve':
            case 'add_items':
                if (response.delivery) {
                    applyState(response.delivery);
                }
                reportFailed(response);
                refreshAvailable();
                break;

            case 'finish':
                draft.finishing = false;
                if (response.delivery || response.receipt) {
                    applyState(response.delivery || response.receipt);
                }
                message(text, 'danger', true);
                break;

            default:
                if (response.delivery || response.receipt) {
                    applyState(response.delivery || response.receipt);
                }
                message(text, 'danger', true);
        }

        queueMissing();
        saveDraft();
        render();
    }

    // ------------------------------------------------------------------ situação da conexão

    function pendingText() {
        var photos = 0;
        var other = 0;
        Object.keys(tasks).forEach(function (id) {
            if (tasks[id].kind === 'photo' || tasks[id].kind === 'defect_photo') {
                photos++;
            } else {
                other++;
            }
        });

        var parts = [];
        if (photos) {
            parts.push(plural(photos, 'foto', 'fotos'));
        }
        if (other) {
            parts.push(plural(other, 'alteração', 'alterações'));
        }
        return parts.length ? parts.join(' e ') + ' aguardando envio' : '';
    }

    function queueStatus() {
        if (queue) {
            return queue.status() || {};
        }
        return {online: navigator.onLine !== false, pending: 0, sending: false, needLogin: false, lastError: ''};
    }

    function renderSync() {
        if (!ui.sync || !ready) {
            return;
        }
        var status = queueStatus();
        var waiting = pendingText();
        var state = 'ok';
        var iconName = 'ti-wifi';
        var text = 'Conectado · tudo enviado';

        if (!waiting && status.pending > 0) {
            waiting = plural(status.pending, 'envio', 'envios') + ' na fila';
        }

        if (status.needLogin) {
            state = 'login';
            iconName = 'ti-lock';
            text = 'Sessão expirada' + (waiting ? ' · ' + waiting : '');
        } else if (status.online === false) {
            state = 'offline';
            iconName = 'ti-wifi-off';
            text = 'Sem conexão: o que você fizer fica guardado neste aparelho' + (waiting ? ' · ' + waiting : '');
        } else if (waiting) {
            state = status.lastError ? 'error' : 'sending';
            iconName = status.lastError ? 'ti-alert-triangle' : 'ti-cloud-upload';
            text = (status.lastError ? 'Falha no envio, tentando de novo' : 'Enviando') + ' · ' + waiting;
        }

        ui.sync.setAttribute('data-ci-sync-state', state);
        ui.syncIcon.className = 'ti ' + iconName;
        ui.syncText.textContent = text;
        ui.syncRetry.hidden = !(waiting && (state === 'offline' || state === 'error'));
        if (ui.login) {
            ui.login.hidden = !status.needLogin;
        }

        if (ui.finishingText && ui.finishing && !ui.finishing.hidden) {
            if (status.needLogin) {
                ui.finishingText.textContent = 'Entre de novo para continuar o envio.';
            } else if (status.online === false) {
                ui.finishingText.textContent = 'Sem conexão: o envio continua sozinho quando a conexão voltar. Deixe esta tela aberta.';
            } else {
                ui.finishingText.textContent = waiting || 'Aguardando a confirmação do servidor...';
            }
        }
    }

    // ------------------------------------------------------------------ laudos (problema já conhecido)

    function laudoBadge(laudos) {
        var first = laudos[0] || {};
        var node = el('span', 'badge bg-yellow-lt text-wrap');
        node.appendChild(icon('ti-alert-triangle'));
        node.appendChild(document.createTextNode(' Problema conhecido (' + (first.name || '') + ' · ' + (first.status || '')
            + (laudos.length > 1 ? ' +' + (laudos.length - 1) : '') + ')'));
        return node;
    }

    /** Mesmo quadro de alerta da retirada comum, com um bloco por laudo em aberto. */
    function laudoAlert(laudos) {
        var box = el('div', 'alert alert-warning mb-0');
        var flex = el('div', 'd-flex');
        var side = el('div');
        var body = el('div', 'flex-fill');

        box.setAttribute('role', 'alert');
        side.appendChild(icon('ti-alert-triangle alert-icon'));
        body.appendChild(el('h4', 'alert-title', 'Este equipamento tem problema já conhecido'));

        laudos.forEach(function (laudo, index) {
            var block = el('div', index === 0 ? 'mt-1' : 'border-top pt-2 mt-2');
            var head = el('div');
            head.appendChild(el('strong', '', laudo.name || ''));
            if (laudo.date) {
                head.appendChild(document.createTextNode(' · ' + formatDate(laudo.date)));
            }
            head.appendChild(document.createTextNode(' · '));
            head.appendChild(el('span', 'badge bg-orange-lt text-wrap', laudo.status || ''));
            block.appendChild(head);
            if (laudo.occurrence) {
                block.appendChild(el('div', '', laudo.occurrence));
            }
            if (laudo.items) {
                block.appendChild(el('div', 'ci-small', 'Itens afetados: ' + laudo.items));
            }
            if (laudo.url) {
                var link = el('a', 'ci-small');
                link.href = laudo.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.appendChild(icon('ti-external-link'));
                link.appendChild(document.createTextNode(' Abrir laudo'));
                block.appendChild(link);
            }
            body.appendChild(block);
        });

        flex.appendChild(side);
        flex.appendChild(body);
        box.appendChild(flex);
        return box;
    }

    // ------------------------------------------------------------------ rádios

    function tags(names) {
        var list = el('ul', 'ci-problem-tags');
        names.forEach(function (name) {
            list.appendChild(el('li', '', name));
        });
        return list;
    }

    function itemText(item) {
        var text = el('span');
        text.appendChild(el('strong', '', item.label));
        if (item.detail) {
            text.appendChild(el('br'));
            text.appendChild(el('small', 'text-muted', item.detail));
        }
        if (Array.isArray(item.laudos) && item.laudos.length) {
            text.appendChild(el('br'));
            text.appendChild(laudoBadge(item.laudos));
        }
        return text;
    }

    function itemButton(className, iconName, label, attribute, usageId) {
        var node = button(className, iconName, label);
        node.setAttribute(attribute, String(usageId));
        return node;
    }

    /** Entrega: rádio reservado, com "Tem problema" (quando a resposta é Não) e "Tirar". */
    function deliveryRow(item) {
        var row = el('div', 'ci-item ci-delivery-item');
        var main = el('span', 'ci-delivery-main');
        var text = itemText(item);
        var problemPending = countTasks('item_problem', 'items_id', item.items_id) > 0;
        var removePending = countTasks('remove_item', 'items_id', item.items_id) > 0;

        if (item.known_issue) {
            text.appendChild(tags(Array.isArray(item.problems) ? item.problems : []));
            text.appendChild(el('div', 'ci-delivery-state', 'Segue na entrega com o problema já conhecido (laudo em aberto).'));
        }
        if (problemPending) {
            text.appendChild(el('div', 'ci-delivery-state', 'Registrando o problema...'));
        }
        if (removePending) {
            text.appendChild(el('div', 'ci-delivery-state', 'Tirando da entrega...'));
        }
        main.appendChild(text);
        row.appendChild(main);

        if (!problemPending && !removePending) {
            var actions = el('div', 'ci-item-actions');
            if (draft.isOk === 0 && !item.known_issue) {
                actions.appendChild(itemButton('btn btn-sm btn-warning', 'ti-alert-triangle', 'Tem problema', 'data-ci-item-problem', item.usage_id));
            }
            actions.appendChild(itemButton('btn btn-sm btn-outline-secondary', 'ti-x', 'Tirar', 'data-ci-item-remove', item.usage_id));
            row.appendChild(actions);
        }
        return row;
    }

    /** Recebimento: rádio que está com a embarcação, marcado quando voltou. */
    function receiptRow(item) {
        var row = el('div', 'ci-item ci-delivery-item');
        var main = el('label', 'ci-delivery-main');
        var returned = draft.returned[item.usage_id] !== false;
        var problem = draft.problems[item.usage_id];
        var check = el('input', 'form-check-input');
        var text = itemText(item);

        check.type = 'checkbox';
        check.checked = returned;
        check.setAttribute('data-ci-item-returned', String(item.usage_id));
        main.appendChild(check);

        if (!returned) {
            text.appendChild(el('div', 'ci-delivery-state', 'Continua com a embarcação.'));
        } else if (problem) {
            text.appendChild(tags(Array.isArray(problem.names) ? problem.names : []));
            text.appendChild(el('div', 'ci-delivery-state', problem.known
                ? 'Problema já conhecido (laudo em aberto): não bloqueia.'
                : 'Problema novo: o rádio fica bloqueado ao concluir.'));
        }
        main.appendChild(text);
        row.appendChild(main);

        if (returned && draft.isOk === 0) {
            var actions = el('div', 'ci-item-actions');
            if (problem) {
                actions.appendChild(itemButton('btn btn-sm btn-outline-secondary', 'ti-arrow-back', 'Desfazer', 'data-ci-item-undo', item.usage_id));
            } else {
                actions.appendChild(itemButton('btn btn-sm btn-warning', 'ti-alert-triangle', 'Tem problema', 'data-ci-item-problem', item.usage_id));
            }
            row.appendChild(actions);
        }
        return row;
    }

    function removedRow(item) {
        var row = el('div', 'ci-item ci-delivery-item ci-item-disabled');
        var main = el('span', 'ci-delivery-main');
        var text = el('span');

        main.appendChild(icon('ti-lock'));
        text.appendChild(el('strong', '', item.label));
        if (item.detail) {
            text.appendChild(el('br'));
            text.appendChild(el('small', 'text-muted', item.detail));
        }
        text.appendChild(tags(Array.isArray(item.problems) ? item.problems : []));
        main.appendChild(text);
        row.appendChild(main);
        return row;
    }

    function renderItems() {
        var items = serverItems();

        ui.reserved.innerHTML = '';
        items.forEach(function (item) {
            ui.reserved.appendChild(isDelivery ? deliveryRow(item) : receiptRow(item));
        });
        if (!items.length) {
            ui.reserved.appendChild(el('div', 'text-muted ci-small', isDelivery
                ? 'Nenhum rádio reservado ainda.'
                : 'Não há mais rádio desta entrega com a embarcação.'));
        }

        if (!isDelivery) {
            ui.itemsCount.textContent = returnedItems().length + ' de ' + items.length;
            return;
        }

        ui.itemsCount.textContent = items.length + ' de ' + maxItems();
        ui.removedBox.hidden = !removedItems().length;
        ui.removed.innerHTML = '';
        removedItems().forEach(function (item) {
            ui.removed.appendChild(removedRow(item));
        });
    }

    /** Entrega: lista dos rádios que ainda podem entrar, com busca e limite. */
    function renderAvailable() {
        if (!isDelivery || !ui.add) {
            return;
        }

        var taken = {};
        serverItems().concat(removedItems()).forEach(function (item) {
            taken[item.items_id] = true;
        });
        var listed = {};
        available.forEach(function (item) {
            listed[item.id] = true;
        });
        // marcado que já não está disponível (outra pessoa retirou) deixa de contar
        draft.picked = draft.picked.filter(function (id) {
            return listed[id] && !taken[id];
        });

        var count = serverItems().length;
        var room = Math.max(0, maxItems() - count);
        var busy = countTasks('reserve') + countTasks('add_items') > 0;
        var query = ui.filter.value.toLowerCase().trim();
        var top = ui.available.scrollTop;
        var shown = 0;

        if (draft.picked.length > room) {
            draft.picked = draft.picked.slice(0, room);
        }

        ui.available.innerHTML = '';
        available.forEach(function (item) {
            if (taken[item.id]) {
                return;
            }
            var picked = draft.picked.indexOf(item.id) !== -1;
            var search = (item.label + ' ' + (item.detail || '')).toLowerCase();
            // o que já está marcado continua à vista mesmo fora da busca
            if (query !== '' && search.indexOf(query) === -1 && !picked) {
                return;
            }

            var row = el('label', 'ci-item');
            var check = el('input', 'form-check-input');
            check.type = 'checkbox';
            check.checked = picked;
            check.disabled = busy || (!picked && draft.picked.length >= room);
            check.setAttribute('data-ci-pick', String(item.id));
            if (check.disabled && !picked) {
                row.className += ' ci-item-disabled';
            }
            row.appendChild(check);
            row.appendChild(itemText(item));
            ui.available.appendChild(row);
            shown++;
        });
        if (!shown) {
            ui.available.appendChild(el('div', 'text-muted ci-small', query !== ''
                ? 'Nenhum rádio encontrado na busca.'
                : 'Nenhum rádio disponível no seu setor.'));
        }
        ui.available.scrollTop = top;

        var full = room === 0;
        ui.available.hidden = full;
        ui.filter.hidden = full;
        ui.reserve.hidden = full;
        ui.addTitle.hidden = full;
        ui.addTitle.textContent = count ? 'Acrescentar outros rádios:' : 'Marque os rádios que vão para a embarcação:';
        ui.reserve.disabled = busy || !draft.picked.length || !vesselValid();
        ui.reserveLabel.textContent = busy
            ? 'Reservando...'
            : (count ? 'Acrescentar ' : 'Reservar ') + plural(draft.picked.length, 'rádio', 'rádios');

        if (full) {
            ui.reserveHint.textContent = 'Limite de ' + maxItems() + ' rádios por entrega atingido.';
        } else if (!vesselValid()) {
            ui.reserveHint.textContent = 'Escolha a embarcação primeiro.';
        } else if (draft.picked.length >= room) {
            ui.reserveHint.textContent = 'Você marcou o máximo para esta entrega (' + maxItems() + ' rádios). Reservar precisa de conexão.';
        } else {
            ui.reserveHint.textContent = 'Você pode marcar mais ' + plural(room - draft.picked.length, 'rádio', 'rádios')
                + '. Reservar precisa de conexão.';
        }
    }

    /**
     * Lista de embarcações, filtrada pela busca (que só aparece quando a lista é longa). A lista é
     * refeita em vez de esconder as opções: o seletor do iPhone não esconde opção.
     */
    function renderVesselOptions() {
        if (!ui.vessel) {
            return;
        }
        var list = Array.isArray(data.vessels) ? data.vessels : [];
        var query = ui.vesselFilter ? ui.vesselFilter.value.toLowerCase().trim() : '';
        var first = el('option', '', vesselPlaceholder);

        if (ui.vesselFilter) {
            ui.vesselFilter.hidden = list.length <= VESSEL_FILTER_MIN;
        }
        ui.vessel.innerHTML = '';
        first.value = '0';
        ui.vessel.appendChild(first);
        list.forEach(function (vessel) {
            var id = toInt(vessel.id);
            var option;
            // a embarcação já escolhida continua na lista mesmo fora da busca
            if (query !== '' && String(vessel.name).toLowerCase().indexOf(query) === -1 && id !== draft.vessel) {
                return;
            }
            option = el('option', '', vessel.name);
            option.value = String(id);
            ui.vessel.appendChild(option);
        });
        ui.vessel.value = vesselValid() ? String(draft.vessel) : '0';
    }

    function vesselValid() {
        var found = false;
        (Array.isArray(data.vessels) ? data.vessels : []).forEach(function (vessel) {
            if (toInt(vessel.id) === draft.vessel) {
                found = true;
            }
        });
        return draft.vessel > 0 && found;
    }

    function reserve() {
        if (!ready || !draft.picked.length || !vesselValid() || countTasks('reserve') + countTasks('add_items') > 0) {
            return;
        }
        var ids = draft.picked.slice();
        var fields = [];
        var action = draft.serverId ? 'add_items' : 'reserve';

        if (draft.serverId) {
            fields.push(['delivery_id', draft.serverId]);
        } else {
            fields.push(['client_uuid', draft.uuid]);
            fields.push(['vessels_id', draft.vessel]);
        }
        ids.forEach(function (id) {
            fields.push(['items[]', id]);
        });

        clearMessage();
        push(action, fields, [], {kind: action});
        saveDraft();
        render();
    }

    function removeItem(usageId) {
        var item = findItem(usageId);
        if (!item || !draft.serverId || !window.confirm('Tirar o rádio ' + item.label + ' desta entrega? Ele volta a ficar disponível.')) {
            return;
        }
        if (panelItem && panelItem.usage_id === usageId) {
            closeProblem();
        }
        clearMessage();
        push('remove_item', [['delivery_id', draft.serverId], ['items_id', item.items_id]], [], {kind: 'remove_item', items_id: item.items_id});
        render();
    }

    // ------------------------------------------------------------------ problema em um rádio

    function hasProblem() {
        if (isDelivery) {
            return removedItems().length > 0 || countTasks('item_problem') > 0 || serverItems().some(function (item) {
                return !!item.known_issue;
            });
        }
        return returnedItems().some(function (item) {
            return !!draft.problems[item.usage_id];
        });
    }

    /** Recebimento: desfaz a marcação de problema de um rádio e apaga as fotos guardadas. */
    function dropProblem(usageId) {
        var problem = draft.problems[usageId];
        if (!problem) {
            return;
        }
        (Array.isArray(problem.photos) ? problem.photos : []).forEach(function (uuid) {
            store.removeBlob(defectKey(uuid)).then(null, function () {});
        });
        delete draft.problems[usageId];
    }

    function defectBlock() {
        return ui.problemPhotos ? ui.problemPhotos.querySelector('[data-ci-defect-photos]') : null;
    }

    function openProblem(usageId) {
        var item = findItem(usageId);
        if (!item) {
            return;
        }
        panelItem = item;
        ui.problemTitle.textContent = item.label;
        ui.problemLaudos.innerHTML = '';
        if (Array.isArray(item.laudos) && item.laudos.length) {
            ui.problemLaudos.appendChild(laudoAlert(item.laudos));
        }
        qsa('[data-ci-problem-check]', ui.problemPanel).forEach(function (check) {
            check.checked = false;
        });
        if (window.ChecklistDefectPhotos) {
            window.ChecklistDefectPhotos.clear(ui.problemPhotos);
        }
        render();
        if (ui.problemPanel.scrollIntoView) {
            ui.problemPanel.scrollIntoView({block: 'start'});
        }
    }

    function closeProblem() {
        panelItem = null;
        if (ui.problemPanel) {
            ui.problemPanel.hidden = true;
        }
        if (window.ChecklistDefectPhotos && ui.problemPhotos) {
            window.ChecklistDefectPhotos.clear(ui.problemPhotos);
        }
    }

    function renderProblem() {
        if (!panelItem) {
            ui.problemPanel.hidden = true;
            return;
        }
        var block = defectBlock();
        var hasLaudo = Array.isArray(panelItem.laudos) && panelItem.laudos.length > 0;
        var checked = qsa('[data-ci-problem-check]:checked', ui.problemPanel).length;
        var photos = window.ChecklistDefectPhotos ? window.ChecklistDefectPhotos.count(block) : 0;
        var busy = !!block && block.hasAttribute('data-ci-defect-busy');
        var complete = checked > 0 && photos > 0 && !busy;

        ui.problemPanel.hidden = false;
        ui.problemKnown.hidden = !hasLaudo;
        ui.problemKnown.disabled = !complete;
        ui.problemBlock.disabled = !complete;
        if (isDelivery) {
            ui.problemBlockLabel.textContent = hasLaudo ? 'É outro problema: bloquear e tirar da entrega' : 'Bloquear e tirar da entrega';
        } else {
            ui.problemBlockLabel.textContent = hasLaudo ? 'É outro problema: bloquear o rádio' : 'Registrar o problema (o rádio fica bloqueado)';
        }
    }

    /** Fotos do defeito que o bloco de fotos montou (reduzidas, ou o arquivo original). */
    function collectDefectPhotos() {
        var list = [];
        qsa('[data-ci-defect-fields] input', ui.problemPhotos).forEach(function (input) {
            if (input.type === 'file') {
                if (input.files && input.files[0]) {
                    list.push(input.files[0]);
                }
            } else if (input.value) {
                var blob = dataUrlToBlob(input.value);
                if (blob) {
                    list.push(blob);
                }
            }
        });
        return list;
    }

    function pushDefectPhoto(usageId, phase, uuid) {
        return push('defect_photo', [['usage_id', usageId], ['phase', phase], ['uuid', uuid]],
            [['image', defectKey(uuid), 'defeito.jpg']], {kind: 'defect_photo', uuid: uuid, key: defectKey(uuid)});
    }

    /**
     * Confirma o problema do rádio aberto no painel.
     * Entrega: as fotos e o problema seguem na hora (o rádio sai e fica bloqueado, ou segue com o
     * problema do laudo). Recebimento: fica guardado no aparelho e vai junto com a conclusão.
     */
    function confirmProblem(known) {
        var item = panelItem;
        if (!item || !ready) {
            return;
        }

        var ids = [];
        var names = [];
        qsa('[data-ci-problem-check]:checked', ui.problemPanel).forEach(function (check) {
            var label = check.parentNode ? check.parentNode.querySelector('span') : null;
            ids.push(toInt(check.value));
            names.push(label ? label.textContent.replace(/\s+/g, ' ').trim() : '');
        });
        var photos = collectDefectPhotos();
        if (!ids.length || !photos.length) {
            return;
        }

        ui.problemKnown.disabled = true;
        ui.problemBlock.disabled = true;
        clearMessage();

        var uuids = [];
        var chain = Promise.resolve();
        photos.forEach(function (blob) {
            var uuid = offline.uuid();
            uuids.push(uuid);
            chain = chain.then(function () {
                return store.putBlob(defectKey(uuid), blob);
            });
        });

        chain.then(function () {
            if (!isDelivery) {
                dropProblem(item.usage_id);
                draft.problems[item.usage_id] = {ids: ids, names: names, known: !!known, photos: uuids};
                return true;
            }

            // a fila envia na ordem: as fotos chegam antes do problema
            var sent = Promise.resolve();
            uuids.forEach(function (uuid) {
                sent = sent.then(function () {
                    return pushDefectPhoto(item.usage_id, 1, uuid);
                });
            });
            return sent.then(function () {
                var fields = [['delivery_id', draft.serverId], ['items_id', item.items_id]];
                ids.forEach(function (id) {
                    fields.push(['problems[]', id]);
                });
                fields.push(['known', known ? 1 : 0]);
                return push('item_problem', fields, [], {kind: 'item_problem', items_id: item.items_id, label: item.label});
            });
        }).then(function () {
            closeProblem();
            saveDraft();
            render();
        }, function () {
            message('Não foi possível guardar as fotos do defeito neste aparelho. Tente de novo.', 'danger', true);
            renderProblem();
        });
    }

    // ------------------------------------------------------------------ fotos

    /** Reduz a foto (maior lado MAX_SIDE) e devolve o JPEG como arquivo. */
    function shrink(file, done, failed) {
        var url = URL.createObjectURL(file);
        var img = new Image();

        img.onload = function () {
            URL.revokeObjectURL(url);
            var finished = false;
            function result(blob) {
                if (finished) {
                    return;
                }
                finished = true;
                // canvas vazio ou grande demais para o aparelho não gera arquivo
                if (blob && blob.size > 0) {
                    done(blob);
                } else {
                    failed();
                }
            }

            try {
                var width = img.naturalWidth;
                var height = img.naturalHeight;
                var scale = Math.min(1, MAX_SIDE / Math.max(width, height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(width * scale);
                canvas.height = Math.round(height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                if (canvas.toBlob) {
                    canvas.toBlob(result, 'image/jpeg', QUALITY);
                } else {
                    var encoded = canvas.toDataURL('image/jpeg', QUALITY);
                    result(/^data:image\/jpeg;base64,/.test(encoded) ? dataUrlToBlob(encoded) : null);
                }
            } catch (e) {
                result(null);
            }
        };

        img.onerror = function () {
            URL.revokeObjectURL(url);
            failed();
        };

        img.src = url;
    }

    function queuePhoto(uuid) {
        if (!draft.serverId || countTasks('photo', 'uuid', uuid) > 0) {
            return;
        }
        push('photo', [['kind', mode], ['id', draft.serverId], ['uuid', uuid]],
            [['image', photoKey(uuid), 'foto.jpg']], {kind: 'photo', uuid: uuid});
    }

    /** Garante uma tarefa de envio para cada foto do aparelho que o servidor ainda não tem. */
    function queueMissing() {
        if (!draft.serverId || halted || stalled) {
            return;
        }
        draft.photos.forEach(function (photo) {
            if (!photo.sent && hasBlob[photo.uuid]) {
                queuePhoto(photo.uuid);
            }
        });
    }

    function addPhoto(blob) {
        var uuid = offline.uuid();
        return store.putBlob(photoKey(uuid), blob).then(function () {
            hasBlob[uuid] = true;
            photoUrls[uuid] = URL.createObjectURL(blob);
            draft.photos.push({uuid: uuid, sent: false, url: ''});
            saveDraft();
            queuePhoto(uuid);
        }, function () {
            photoNotes.push('Não foi possível guardar uma foto neste aparelho (pode estar sem espaço).');
        });
    }

    // Uma foto por vez, para não estourar a memória do celular
    function nextPhoto() {
        photoCurrent = photoQueue.shift() || null;
        renderPhotos();
        if (!photoCurrent) {
            return;
        }

        var file = photoCurrent;
        shrink(file, function (blob) {
            addPhoto(blob).then(nextPhoto);
        }, function () {
            // o navegador não conseguiu reduzir: o arquivo original vai como está, se o servidor aceitar
            if ((file.type === 'image/jpeg' || file.type === 'image/png') && file.size <= MAX_BYTES) {
                addPhoto(file).then(nextPhoto);
            } else {
                photoNotes.push('Não foi possível ler uma das fotos. Tire outra ou escolha outra imagem (JPEG ou PNG, até 5 MB).');
                nextPhoto();
            }
        });
    }

    function pickPhotos(input) {
        var files = Array.prototype.slice.call(input.files || []);
        // limpa o campo para poder escolher (ou tirar) de novo a mesma foto
        input.value = '';
        if (!ready || !files.length) {
            return;
        }

        if (!photoCurrent) {
            photoNotes = [];
        }
        var room = Math.max(0, maxPhotos() - draft.photos.length - photoQueue.length - (photoCurrent ? 1 : 0));
        if (files.length > room) {
            files = files.slice(0, room);
            photoNotes.push('Máximo de ' + maxPhotos() + ' fotos: as que passaram disso não foram adicionadas.');
        }
        photoQueue = photoQueue.concat(files);
        if (!photoCurrent) {
            nextPhoto();
        } else {
            renderPhotos();
        }
    }

    /** Tira a foto da tela e do aparelho (sem avisar o servidor). */
    function forgetPhoto(uuid) {
        var index = findPhoto(uuid);
        if (index === -1) {
            return null;
        }
        var photo = draft.photos.splice(index, 1)[0];
        if (photoUrls[uuid]) {
            URL.revokeObjectURL(photoUrls[uuid]);
            delete photoUrls[uuid];
        }
        if (hasBlob[uuid]) {
            delete hasBlob[uuid];
            store.removeBlob(photoKey(uuid)).then(null, function () {});
        }
        return photo;
    }

    function removePhoto(uuid) {
        var queued = countTasks('photo', 'uuid', uuid) > 0;
        var photo = forgetPhoto(uuid);
        if (!photo) {
            return;
        }
        // a fila segue a ordem: se o envio ainda está nela, o pedido de apagar chega depois
        if (draft.serverId && (photo.sent || queued)) {
            push('photo_delete', [['kind', mode], ['id', draft.serverId], ['uuid', uuid]], [], {kind: 'photo_delete', uuid: uuid});
        }
        if (!photoCurrent) {
            photoNotes = [];
        }
        saveDraft();
        renderPhotos();
    }

    function photoNode(photo) {
        var node = el('div', 'ci-defect-item');
        var source = photoUrls[photo.uuid] || photo.url || '';
        var thumb;

        if (source) {
            thumb = el('img', 'ci-defect-thumb');
            thumb.alt = 'Foto';
            thumb.src = source;
        } else {
            thumb = el('span', 'ci-defect-thumb ci-defect-thumb-file');
            thumb.appendChild(icon('ti-photo'));
        }
        node.appendChild(thumb);

        var mark = el('i', 'ci-photo-mark ti');
        mark.setAttribute('data-ci-photo-mark', '');
        node.appendChild(mark);

        var remove = el('button', 'ci-defect-remove', '×');
        remove.type = 'button';
        remove.title = 'Remover foto';
        remove.setAttribute('aria-label', 'Remover foto');
        remove.setAttribute('data-ci-photo-remove', photo.uuid);
        node.appendChild(remove);

        return node;
    }

    function renderPhotos() {
        if (!ready || !ui.photos) {
            return;
        }
        var seen = {};
        var waiting = 0;

        draft.photos.forEach(function (photo) {
            var node = photoEls[photo.uuid];
            seen[photo.uuid] = true;
            if (!node) {
                node = photoNode(photo);
                photoEls[photo.uuid] = node;
                ui.photos.appendChild(node);
            }
            var mark = node.querySelector('[data-ci-photo-mark]');
            if (!photo.sent) {
                waiting++;
            }
            node.classList.toggle('ci-photo-waiting', !photo.sent);
            mark.className = 'ci-photo-mark ti ' + (photo.sent ? 'ti-check' : 'ti-cloud-upload');
            mark.title = photo.sent ? 'Enviada' : 'Aguardando envio';
        });
        Object.keys(photoEls).forEach(function (uuid) {
            if (!seen[uuid]) {
                if (photoEls[uuid].parentNode) {
                    photoEls[uuid].parentNode.removeChild(photoEls[uuid]);
                }
                delete photoEls[uuid];
            }
        });

        var total = draft.photos.length;
        var min = minPhotos();
        var processing = photoQueue.length + (photoCurrent ? 1 : 0);
        var full = total + processing >= maxPhotos();

        ui.photosCount.textContent = total < min ? total + ' de ' + min : plural(total, 'foto', 'fotos') + (total >= maxPhotos() ? ' (máximo)' : '');
        ui.photosCount.className = 'badge ms-1 ' + (total >= min ? 'bg-green-lt' : 'bg-yellow-lt');
        ui.photoPickers.forEach(function (picker) {
            picker.classList.toggle('disabled', full);
            picker.setAttribute('aria-disabled', full ? 'true' : 'false');
        });
        ui.photoInputs.forEach(function (input) {
            input.disabled = full;
        });

        var lines = [];
        if (processing) {
            lines.push(processing > 1 ? 'Processando as fotos...' : 'Processando a foto...');
        }
        if (waiting) {
            lines.push(plural(waiting, 'foto aguardando envio', 'fotos aguardando envio') + '.');
        }
        ui.photosStatus.textContent = lines.concat(photoNotes).join(' ');
        ui.photosStatus.classList.toggle('ci-defect-warning', photoNotes.length > 0);

        renderFinish();
    }

    /** Ao abrir a tela: lê do aparelho os arquivos das fotos guardadas. */
    function loadBlobs() {
        var chain = Promise.resolve();
        draft.photos.forEach(function (photo) {
            chain = chain.then(function () {
                return store.getBlob(photoKey(photo.uuid)).then(function (blob) {
                    if (blob) {
                        hasBlob[photo.uuid] = true;
                        photoUrls[photo.uuid] = URL.createObjectURL(blob);
                    }
                }, function () {});
            });
        });
        return chain;
    }

    /** Foto sem arquivo no aparelho e que o servidor não tem não pode mais ser enviada. */
    function prunePhotos() {
        var lost = 0;
        draft.photos = draft.photos.filter(function (photo) {
            if (hasBlob[photo.uuid] || (photo.sent && photo.url)) {
                return true;
            }
            lost++;
            return false;
        });
        if (lost) {
            photoNotes.push(plural(lost, 'foto guardada neste aparelho não pôde ser recuperada', 'fotos guardadas neste aparelho não puderam ser recuperadas') + '.');
        }
    }

    // ------------------------------------------------------------------ selfie e localização

    function locationFields() {
        var fields = {};
        qsa('[data-ci-location-field]', ui.selfieBox).forEach(function (input) {
            fields[input.getAttribute('data-ci-location-field')] = input;
        });
        return fields;
    }

    function readLocation() {
        var fields = locationFields();
        if (!fields.status || fields.status.value === '') {
            return null;
        }
        return {
            status: fields.status.value,
            latitude: fields.latitude ? fields.latitude.value : '',
            longitude: fields.longitude ? fields.longitude.value : '',
            accuracy: fields.accuracy ? fields.accuracy.value : ''
        };
    }

    function storeSelfie(blob) {
        if (!blob) {
            return;
        }
        selfieSaving = store.putBlob(key('selfie'), blob).then(function () {
            draft.selfie = true;
            saveDraft();
            renderFinish();
        }, function () {
            draft.selfie = false;
            message('Não foi possível guardar a selfie neste aparelho. Tire de novo.', 'danger', true);
            renderFinish();
        });
    }

    /** O bloco da selfie avisou uma mudança (foto nova, câmera reaberta, localização lida). */
    function captureSelfie() {
        if (!ready || !ui.selfieBox) {
            return;
        }
        var hidden = ui.selfieBox.querySelector('[data-ci-selfie-data]');
        var input = ui.selfieBox.querySelector('[data-ci-selfie-input]');
        var value = hidden ? hidden.value : '';
        var file = input && input.files && input.files[0] ? input.files[0] : null;

        if (!ui.selfieBox.hasAttribute('data-ci-selfie-ready')) {
            // tirando outra: a que estava guardada não vale mais
            lastSelfie = null;
            if (draft.selfie) {
                draft.selfie = false;
                store.removeBlob(key('selfie')).then(null, function () {});
            }
        } else if (value !== '' && value !== lastSelfie) {
            lastSelfie = value;
            storeSelfie(dataUrlToBlob(value));
        } else if (value === '' && file && file !== lastSelfie) {
            // foto que o navegador não conseguiu reduzir: vai o arquivo original
            lastSelfie = file;
            if (file.size > MAX_BYTES) {
                message('A selfie ficou grande demais (mais de 5 MB). Tire de novo.', 'danger', true);
            } else {
                storeSelfie(file);
            }
        }

        draft.location = readLocation();
        saveDraft();
        renderFinish();
    }

    /** Ao abrir a tela: a selfie e a localização guardadas voltam para o bloco da selfie. */
    function restoreSelfie() {
        if (!ui.selfieBox || !draft.selfie) {
            draft.selfie = false;
            return Promise.resolve();
        }

        return store.getBlob(key('selfie')).then(function (blob) {
            if (!blob) {
                draft.selfie = false;
                return;
            }
            var preview = ui.selfieBox.querySelector('[data-ci-selfie-preview]');
            var status = ui.selfieBox.querySelector('[data-ci-selfie-status]');
            if (preview) {
                preview.src = URL.createObjectURL(blob);
                preview.hidden = false;
            }
            if (status) {
                status.textContent = 'Selfie guardada neste aparelho.';
            }
            ui.selfieBox.setAttribute('data-ci-selfie-ready', '1');

            var saved = draft.location;
            var fields = locationFields();
            if (!saved || !saved.status || !fields.status) {
                return;
            }
            fields.status.value = String(saved.status);
            ['latitude', 'longitude', 'accuracy'].forEach(function (name) {
                if (fields[name]) {
                    fields[name].value = saved[name] ? String(saved[name]) : '';
                }
            });

            var ok = String(saved.status) === '1';
            var line = ui.selfieBox.querySelector('[data-ci-location]');
            var text = ui.selfieBox.querySelector('[data-ci-location-text]');
            var retry = ui.selfieBox.querySelector('[data-ci-location-retry]');
            if (line) {
                line.hidden = false;
                line.classList.toggle('ci-location-ok', ok);
                line.classList.toggle('ci-location-missing', !ok);
            }
            if (text) {
                text.textContent = ok
                    ? 'Localização registrada' + (saved.accuracy ? ' (±' + saved.accuracy + ' m)' : '') + '.'
                    : 'A localização não foi registrada com esta selfie. Toque em "Tentar de novo".';
            }
            if (retry) {
                retry.hidden = ok;
            }
        }, function () {
            draft.selfie = false;
        });
    }

    // ------------------------------------------------------------------ conclusão e cancelamento

    function whatIsMissing() {
        var list = [];
        var min = minPhotos();

        if (isDelivery) {
            if (!vesselValid()) {
                list.push('escolher a embarcação');
            }
            if (!serverItems().length) {
                list.push('reservar pelo menos um rádio');
            }
        } else if (!returnedItems().length) {
            list.push('marcar pelo menos um rádio que voltou');
        }

        if (draft.isOk === null) {
            list.push('responder "Itens ok?"');
        } else if (draft.isOk === 0 && !hasProblem()) {
            list.push('indicar o rádio com problema (ou responder Sim)');
        }
        if (panelItem) {
            list.push('concluir ou fechar o problema do rádio');
        }
        if (photoQueue.length || photoCurrent) {
            list.push('esperar o processamento das fotos');
        } else if (draft.photos.length < min) {
            list.push('tirar mais ' + plural(min - draft.photos.length, 'foto', 'fotos'));
        }
        if (!draft.selfie) {
            list.push('tirar a selfie');
        }
        return list;
    }

    function renderFinish() {
        if (!ready || !ui.finish) {
            return;
        }
        var missing = whatIsMissing();
        var offlineNow = queueStatus().online === false;

        ui.finish.disabled = missing.length > 0;
        if (ui.finishLabel) {
            ui.finishLabel.textContent = offlineNow ? 'Enviar quando voltar a conexão' : finishText;
        }
        if (missing.length) {
            ui.finishHint.textContent = 'Falta: ' + missing.join('; ') + '.';
        } else if (offlineNow) {
            ui.finishHint.textContent = 'Sem conexão: a conclusão fica guardada e é enviada quando a conexão voltar.';
        } else {
            ui.finishHint.textContent = '';
        }
    }

    /** Espera a leitura da localização em andamento (no máximo LOCATION_WAIT_MS), como na retirada. */
    function waitLocation(done) {
        var location = ui.selfieBox ? ui.selfieBox.ciLocation : null;
        var called = false;
        function once() {
            if (!called) {
                called = true;
                done();
            }
        }

        if (!location) {
            done();
            return;
        }
        location.start();
        if (!location.isPending()) {
            done();
            return;
        }
        ui.finishHint.textContent = 'Obtendo a localização...';
        location.whenDone(once);
        window.setTimeout(once, LOCATION_WAIT_MS);
    }

    function finish() {
        if (!ready || draft.finishing || whatIsMissing().length) {
            return;
        }
        ui.finish.disabled = true;
        clearMessage();

        waitLocation(function () {
            (selfieSaving || Promise.resolve()).then(function () {
                if (draft.finishing || whatIsMissing().length) {
                    renderFinish();
                    return;
                }

                var fields = [];
                var chain = Promise.resolve();
                var location = readLocation() || draft.location || {};

                if (isDelivery) {
                    fields.push(['delivery_id', draft.serverId]);
                    fields.push(['spare_batteries', draft.batteries]);
                } else {
                    fields.push(['receipt_id', draft.serverId]);
                    fields.push(['returned_batteries', draft.batteries]);
                    returnedItems().forEach(function (item) {
                        var problem = draft.problems[item.usage_id];
                        fields.push(['usages[]', item.usage_id]);
                        if (draft.isOk !== 0 || !problem) {
                            return;
                        }
                        (Array.isArray(problem.ids) ? problem.ids : []).forEach(function (id) {
                            fields.push(['problems[' + item.usage_id + '][]', id]);
                        });
                        if (problem.known) {
                            fields.push(['known[' + item.usage_id + ']', 1]);
                        }
                        // as fotos do defeito chegam antes da conclusão; o mesmo identificador não duplica
                        (Array.isArray(problem.photos) ? problem.photos : []).forEach(function (uuid) {
                            chain = chain.then(function () {
                                return pushDefectPhoto(item.usage_id, 2, uuid);
                            });
                        });
                    });
                }
                fields.push(['is_ok', draft.isOk]);
                fields.push(['comment', draft.comment]);
                fields.push(['location_status', location.status || '']);
                fields.push(['location_latitude', location.latitude || '']);
                fields.push(['location_longitude', location.longitude || '']);
                fields.push(['location_accuracy', location.accuracy || '']);

                draft.location = readLocation() || draft.location;
                draft.finishing = true;
                saveDraft();
                render();

                chain.then(function () {
                    return push(isDelivery ? 'finish' : 'receipt_finish', fields, [['selfie_file', key('selfie'), 'selfie.jpg']], {kind: 'finish'});
                }).then(function (queued) {
                    if (!queued) {
                        draft.finishing = false;
                        saveDraft();
                        render();
                    }
                });
            });
        });
    }

    function cancel() {
        var question = isDelivery
            ? 'Cancelar a separação? Os rádios voltam a ficar disponíveis e as fotos desta separação são descartadas.'
            : 'Cancelar o recebimento? As fotos deste recebimento são descartadas e os rádios continuam com a embarcação.';
        if (!ready || !window.confirm(question)) {
            return;
        }
        ui.cancel.disabled = true;
        clearMessage();

        // nada no servidor: só limpa o que está no aparelho
        if (!draft.serverId) {
            leaving = true;
            wipeLocal().then(function () {
                window.location.href = data.homeUrl;
            });
            return;
        }

        var id = draft.serverId;
        // o que ainda não foi enviado não precisa mais ir
        dropTasks().then(function () {
            return push(isDelivery ? 'cancel' : 'receipt_cancel', [[isDelivery ? 'delivery_id' : 'receipt_id', id]], [], {kind: 'cancel'});
        }).then(render);
    }

    // ------------------------------------------------------------------ desenho da tela

    function renderRest() {
        var locked = hasProblem();

        ui.rest.hidden = isDelivery && !serverItems().length;
        ui.cancel.hidden = !(draft.serverId || draft.photos.length);
        ui.cancel.disabled = false;

        if (document.activeElement !== ui.batteries) {
            ui.batteries.value = String(draft.batteries);
        }
        ui.ok.forEach(function (input) {
            input.checked = draft.isOk !== null && input.value === String(draft.isOk);
            // com rádio com problema a resposta só pode ser Não
            input.disabled = locked && input.value === '1';
        });
        ui.okHint.hidden = !(draft.isOk === 0 && !locked && !panelItem);
        if (document.activeElement !== ui.comment && ui.comment.value !== draft.comment) {
            ui.comment.value = draft.comment;
        }

        renderPhotos();
    }

    function render() {
        if (!ready) {
            return;
        }
        var cancelling = countTasks('cancel') > 0;
        var working = draft.finishing || cancelling;

        ui.form.hidden = working || halted;
        ui.finishing.hidden = !working || halted;
        if (working && ui.finishingTitle) {
            if (cancelling) {
                ui.finishingTitle.textContent = isDelivery ? 'Cancelando a separação...' : 'Cancelando o recebimento...';
            } else {
                ui.finishingTitle.textContent = isDelivery ? 'Concluindo a entrega...' : 'Concluindo o recebimento...';
            }
        }
        renderSync();
        if (working || halted) {
            return;
        }

        if (hasProblem()) {
            draft.isOk = 0;
        }
        if (isDelivery) {
            if (ui.vessel) {
                ui.vessel.value = vesselValid() ? String(draft.vessel) : '0';
            }
        } else if (server && server.delivery) {
            ui.receiptVessel.textContent = server.delivery.vessel_name || '';
            ui.receiptInfo.textContent = 'Entregue em ' + (server.delivery.date_delivery_label || server.delivery.date_delivery || '');
        }
        renderItems();
        renderAvailable();
        renderProblem();
        renderRest();
    }

    // ------------------------------------------------------------------ eventos

    function closest(target, selector) {
        return target && target.closest ? target.closest(selector) : null;
    }

    function setBatteries(value) {
        draft.batteries = clampBatteries(value);
        ui.batteries.value = String(draft.batteries);
        saveDraft();
    }

    function bind() {
        // nada nesta tela é enviado pelo formulário: tudo vai pela fila
        ui.form.addEventListener('submit', function (event) {
            event.preventDefault();
        });
        ui.form.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target && event.target.tagName === 'INPUT') {
                event.preventDefault();
            }
        });

        if (ui.vesselFilter) {
            ui.vesselFilter.addEventListener('input', function () {
                if (ready) {
                    renderVesselOptions();
                }
            });
        }
        if (ui.vessel) {
            ui.vessel.addEventListener('change', function () {
                if (!ready) {
                    return;
                }
                var value = toInt(ui.vessel.value);
                // com rádios reservados a entrega não fica sem embarcação: volta para a anterior
                if (value > 0) {
                    draft.vessel = value;
                    if (draft.serverId) {
                        push('set_vessel', [['delivery_id', draft.serverId], ['vessels_id', value]], [], {kind: 'set_vessel'});
                    }
                } else if (!draft.serverId) {
                    draft.vessel = 0;
                }
                saveDraft();
                render();
            });
        }

        if (ui.add) {
            ui.filter.addEventListener('input', function () {
                if (ready) {
                    renderAvailable();
                }
            });
            ui.available.addEventListener('change', function (event) {
                var check = closest(event.target, '[data-ci-pick]');
                if (!ready || !check) {
                    return;
                }
                var id = toInt(check.getAttribute('data-ci-pick'));
                var index = draft.picked.indexOf(id);
                if (check.checked && index === -1) {
                    draft.picked.push(id);
                } else if (!check.checked && index !== -1) {
                    draft.picked.splice(index, 1);
                }
                saveDraft();
                renderAvailable();
            });
            ui.reserve.addEventListener('click', reserve);
        }

        ui.reserved.addEventListener('click', function (event) {
            if (!ready) {
                return;
            }
            var problem = closest(event.target, '[data-ci-item-problem]');
            var remove = closest(event.target, '[data-ci-item-remove]');
            var undo = closest(event.target, '[data-ci-item-undo]');
            if (problem) {
                openProblem(toInt(problem.getAttribute('data-ci-item-problem')));
            } else if (remove) {
                removeItem(toInt(remove.getAttribute('data-ci-item-remove')));
            } else if (undo) {
                dropProblem(toInt(undo.getAttribute('data-ci-item-undo')));
                saveDraft();
                render();
            }
        });
        ui.reserved.addEventListener('change', function (event) {
            var check = closest(event.target, '[data-ci-item-returned]');
            if (!ready || !check) {
                return;
            }
            var usageId = toInt(check.getAttribute('data-ci-item-returned'));
            if (check.checked) {
                delete draft.returned[usageId];
            } else {
                draft.returned[usageId] = false;
                dropProblem(usageId);
                if (panelItem && panelItem.usage_id === usageId) {
                    closeProblem();
                }
            }
            saveDraft();
            render();
        });

        ui.problemPanel.addEventListener('change', renderProblem);
        ui.problemPanel.addEventListener('ci:defectphotos', renderProblem);
        ui.problemKnown.addEventListener('click', function () {
            confirmProblem(true);
        });
        ui.problemBlock.addEventListener('click', function () {
            confirmProblem(false);
        });
        ui.problemCancel.addEventListener('click', function () {
            closeProblem();
            render();
        });

        ui.batteriesMinus.addEventListener('click', function () {
            if (ready) {
                setBatteries(draft.batteries - 1);
            }
        });
        ui.batteriesPlus.addEventListener('click', function () {
            if (ready) {
                setBatteries(draft.batteries + 1);
            }
        });
        ui.batteries.addEventListener('input', function () {
            if (ready) {
                draft.batteries = clampBatteries(ui.batteries.value);
                saveSoon();
            }
        });
        ui.batteries.addEventListener('change', function () {
            if (ready) {
                setBatteries(ui.batteries.value);
            }
        });

        ui.ok.forEach(function (input) {
            input.addEventListener('change', function () {
                if (!ready || !input.checked) {
                    return;
                }
                draft.isOk = input.value === '1' ? 1 : 0;
                if (draft.isOk === 1 && panelItem) {
                    closeProblem();
                }
                saveDraft();
                render();
            });
        });

        ui.comment.addEventListener('input', function () {
            if (ready) {
                draft.comment = ui.comment.value.slice(0, 1000);
                saveSoon();
            }
        });

        ui.photoInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                pickPhotos(input);
            });
        });
        ui.photos.addEventListener('click', function (event) {
            var remove = closest(event.target, '[data-ci-photo-remove]');
            if (ready && remove) {
                removePhoto(remove.getAttribute('data-ci-photo-remove'));
            }
        });

        root.addEventListener('ci:change', captureSelfie);
        ui.finish.addEventListener('click', finish);
        ui.cancel.addEventListener('click', cancel);

        ui.syncRetry.addEventListener('click', function () {
            if (queue) {
                queue.retryNow();
            }
        });
        if (ui.loginButton) {
            ui.loginButton.addEventListener('click', function () {
                // a página pede o login e volta para cá; o que está guardado continua no aparelho
                leaving = true;
                window.location.reload();
            });
        }
        if (ui.message) {
            ui.message.addEventListener('click', function (event) {
                if (!halted && !closest(event.target, 'a')) {
                    clearMessage();
                }
            });
        }

        window.addEventListener('beforeunload', function (event) {
            // avisa antes de sair com coisa ainda não enviada
            if (!leaving && Object.keys(tasks).length > 0) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        window.addEventListener('pagehide', function () {
            if (!leaving) {
                saveDraft();
            }
        });
    }

    function collect() {
        function get(name) {
            return root.querySelector('[data-ci-' + name + ']');
        }

        ui.sync = get('sync');
        ui.syncIcon = get('sync-icon');
        ui.syncText = get('sync-text');
        ui.syncRetry = get('sync-retry');
        ui.login = get('login');
        ui.loginButton = get('login-button');
        ui.nostore = get('nostore');
        ui.message = get('message');
        ui.finishing = get('finishing');
        ui.finishingTitle = get('finishing-title');
        ui.finishingText = get('finishing-text');
        ui.form = get('delivery-form');
        ui.vessel = get('vessel');
        ui.vesselFilter = get('vessel-filter');
        if (ui.vessel && ui.vessel.options.length) {
            vesselPlaceholder = ui.vessel.options[0].textContent;
        }
        ui.receiptVessel = get('receipt-vessel');
        ui.receiptInfo = get('receipt-info');
        ui.itemsCount = get('items-count');
        ui.reserved = get('reserved');
        ui.removedBox = get('removed-box');
        ui.removed = get('removed');
        ui.add = get('add');
        ui.addTitle = get('add-title');
        ui.filter = get('add-filter');
        ui.available = get('available');
        ui.reserve = get('reserve');
        ui.reserveLabel = get('reserve-label');
        ui.reserveHint = get('reserve-hint');
        ui.problemPanel = get('problem-panel');
        ui.problemTitle = get('problem-title');
        ui.problemLaudos = get('problem-laudos');
        ui.problemPhotos = get('problem-photos');
        ui.problemKnown = get('problem-known');
        ui.problemBlock = get('problem-block');
        ui.problemBlockLabel = get('problem-block-label');
        ui.problemCancel = get('problem-cancel');
        ui.rest = get('rest');
        ui.batteries = get('batteries');
        ui.batteriesMinus = get('batteries-minus');
        ui.batteriesPlus = get('batteries-plus');
        ui.ok = qsa('[data-ci-ok]', root);
        ui.okHint = get('ok-hint');
        ui.comment = get('comment');
        ui.photosCount = get('photos-count');
        ui.photoPickers = qsa('[data-ci-photo-picker]', root);
        ui.photoInputs = qsa('[data-ci-photo-input]', root);
        ui.photos = get('photos');
        ui.photosStatus = get('photos-status');
        ui.selfieBox = get('selfie-box');
        ui.finish = get('finish');
        ui.finishHint = get('finish-hint');
        ui.finishLabel = get('finish-label');
        if (ui.finishLabel) {
            finishText = ui.finishLabel.textContent;
        }
        ui.cancel = get('cancel');
    }

    function init() {
        root = document.querySelector('[data-ci-delivery]');
        if (!root) {
            return;
        }
        collect();
        offline = window.ChecklistOffline;

        var source = root.querySelector('[data-ci-delivery-data]');
        try {
            data = JSON.parse(source ? source.textContent : '');
        } catch (e) {
            data = null;
        }
        if (!data || !data.mode || !ui.form || !offline || !window.Promise) {
            fail('Não foi possível carregar esta tela. Recarregue a página; se continuar, avise o TI.');
            return;
        }

        mode = data.mode;
        isDelivery = mode === 'delivery';
        limits = data.limits || {};
        available = Array.isArray(data.available) ? data.available : [];
        bind();

        offline.open('u' + toInt(data.user) + ':').then(function (opened) {
            store = opened;
            if (ui.nostore) {
                ui.nostore.hidden = store.available !== false;
            }
            return store.get(key('draft'));
        }).then(function (saved) {
            draft = normalize(saved);
            return loadBlobs();
        }).then(function () {
            queue = offline.createQueue({
                store: store,
                name: mode,
                url: data.ajaxUrl,
                tokenUrl: data.ajaxUrl + '?action=token',
                token: data.token,
                // conexão fraca: cada foto reduzida tem algumas centenas de KB
                timeoutMs: 120000,
                onDone: onDone,
                onRejected: onRejected,
                onStatus: function () {
                    renderSync();
                    renderFinish();
                }
            });
            return queue.pending();
        }).then(function (pending) {
            (Array.isArray(pending) ? pending : []).forEach(function (task) {
                tasks[task.id] = task.meta && task.meta.kind ? task.meta : {kind: task.action};
            });
            return reconcile(data.state || null);
        }).then(function () {
            return restoreSelfie();
        }).then(function () {
            prunePhotos();
            ready = true;
            queueMissing();
            saveDraft();
            renderVesselOptions();
            render();
            queue.start();

            // "Cancelar" clicado no aviso da tela inicial: o cancelamento passa por aqui para limpar
            // também o que está guardado neste aparelho. O parâmetro sai do endereço para um
            // recarregar não repetir a pergunta.
            if (/[?&]cancel=1(&|$)/.test(window.location.search) && (draft.serverId || draft.photos.length)) {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', window.location.pathname + (isDelivery ? '' : '?receive=1'));
                }
                cancel();
            }
        }).then(null, function () {
            fail('Não foi possível abrir os dados guardados neste aparelho. Recarregue a página; se continuar, avise o TI.');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
