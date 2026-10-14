/* Checklist uso de equipamentos: dados guardados no aparelho e fila de envio (sem dependências). */

/*
 * A conexão do celular ou tablet cai com frequência. A tela grava tudo primeiro no aparelho e
 * empilha tarefas de envio; este módulo guarda os dados (IndexedDB) e entrega as tarefas ao
 * servidor, uma por vez e na ordem, retomando sozinho quando a conexão volta. O servidor reconhece
 * a tarefa repetida, então reenviar não duplica nada.
 *
 *   ChecklistOffline.open(namespace)     -> Promise<Store>
 *       Store: available, get, set, remove, keys, putBlob, getBlob, removeBlob, clear
 *   ChecklistOffline.createQueue(opções) -> Queue
 *       Queue: push, start, stop, retryNow, status, pending, clear
 *   ChecklistOffline.uuid()              -> string
 */
(function () {
    'use strict';

    var DB_NAME = 'checklistitens';
    var DB_VERSION = 1;
    var KV = 'kv';                      // valores (qualquer JSON)
    var BLOBS = 'blobs';                // arquivos (fotos)
    var OPEN_TIMEOUT_MS = 6000;         // há navegador que nunca responde ao abrir o banco
    var BACKOFF_MS = [2000, 5000, 10000, 30000, 60000];   // espera entre tentativas seguidas
    var MAX_TOKEN_SWAPS = 2;            // trocas de token seguidas para a mesma tarefa
    var PING_MS = 300000;               // intervalo padrão do ping que mantém a sessão
    var TIMEOUT_MS = 60000;             // tempo limite padrão de cada pedido
    var SEQ_DIGITS = 12;                // zeros à esquerda: a ordem das chaves é a ordem de envio
    var LOGIN_MESSAGE = 'Sua sessão expirou. Entre de novo para continuar o envio.';
    var BLOB_MESSAGE = 'Arquivo não encontrado neste aparelho.';
    var STORE_MESSAGE = 'Não foi possível usar os dados guardados neste aparelho.';

    var connection = null;      // Promise da conexão com o banco, uma só para a página
    var usable = null;          // Promise<boolean>: a primeira abertura do banco deu certo?
    var memory = null;          // armazenamento em memória, para quando não há banco
    var blobAsBuffer = false;   // este navegador não grava Blob no banco

    function noop() {}

    function positive(value, fallback) {
        return typeof value === 'number' && value > 0 ? value : fallback;
    }

    function isBlob(value) {
        return !!window.Blob && value instanceof window.Blob;
    }

    /** Erro com texto que a tela pode mostrar; o erro original do navegador vai em "cause". */
    function asError(cause, message) {
        var full = !!cause && cause.name === 'QuotaExceededError';
        var error = new Error(full ? 'Sem espaço no aparelho para guardar os dados.' : message);

        if (cause) {
            error.cause = cause;
            error.name = cause.name || error.name;
        }
        return error;
    }

    /** Os callbacks são de quem usa o módulo: um erro lá dentro não pode parar o envio. */
    function invoke(callback, first, second) {
        if (typeof callback !== 'function') {
            return;
        }
        try {
            callback(first, second);
        } catch (error) {
            if (window.console && window.console.error) {
                window.console.error(error);
            }
        }
    }

    // ------------------------------------------------------------------ identificadores

    function randomBytes(count) {
        var cryptoApi = window.crypto || window.msCrypto;
        var bytes = [];
        var i;

        if (cryptoApi && typeof cryptoApi.getRandomValues === 'function' && window.Uint8Array) {
            try {
                return cryptoApi.getRandomValues(new window.Uint8Array(count));
            } catch (error) {
                // segue para o Math.random
            }
        }
        for (i = 0; i < count; i++) {
            bytes.push(Math.floor(Math.random() * 256));
        }
        return bytes;
    }

    /** UUID v4. O randomUUID só existe em HTTPS; fora dele o identificador é montado aqui. */
    function uuid() {
        var cryptoApi = window.crypto || window.msCrypto;
        var bytes;
        var hex = '';
        var i;

        if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
            try {
                return cryptoApi.randomUUID();
            } catch (error) {
                // segue para a montagem manual
            }
        }
        bytes = randomBytes(16);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;   // versão 4
        bytes[8] = (bytes[8] & 0x3f) | 0x80;   // variante do padrão
        for (i = 0; i < 16; i++) {
            hex += (bytes[i] + 0x100).toString(16).slice(1);
        }
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    // ------------------------------------------------------------------ banco do aparelho (IndexedDB)

    function supported() {
        return !!window.indexedDB && !!window.IDBKeyRange && !!window.IDBObjectStore
            && typeof window.IDBObjectStore.prototype.getAllKeys === 'function';
    }

    /** Abre (e cria, na primeira vez) o banco. Rejeita quando o navegador não oferece ou bloqueia. */
    function openDatabase() {
        return new Promise(function (resolve, reject) {
            var settled = false;
            var timer = null;
            var request;

            function fail(cause) {
                if (!settled) {
                    settled = true;
                    window.clearTimeout(timer);
                    reject(asError(cause, 'Este navegador não guarda dados no aparelho.'));
                }
            }

            try {
                if (!supported()) {
                    fail(null);
                    return;
                }
                request = window.indexedDB.open(DB_NAME, DB_VERSION);
            } catch (cause) {
                fail(cause);    // há navegador que bloqueia até a consulta (cookies desligados)
                return;
            }
            timer = window.setTimeout(function () {
                fail(null);
            }, OPEN_TIMEOUT_MS);

            request.onupgradeneeded = function () {
                var db = request.result;
                [KV, BLOBS].forEach(function (area) {
                    if (!db.objectStoreNames.contains(area)) {
                        db.createObjectStore(area);
                    }
                });
            };
            request.onerror = function (event) {
                if (event && event.preventDefault) {
                    event.preventDefault();     // senão o navegador ainda mostra o erro como não tratado
                }
                fail(request.error);
            };
            request.onsuccess = function () {
                var db = request.result;
                var complete = db.objectStoreNames.contains(KV) && db.objectStoreNames.contains(BLOBS);

                if (settled || !complete) {
                    db.close();     // abriu depois do tempo limite, ou é um banco sem as nossas áreas
                    fail(null);
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                resolve(db);
            };
        });
    }

    function release(attempt) {
        if (connection === attempt) {
            connection = null;
        }
    }

    /** Conexão compartilhada; se cair, a próxima operação abre outra. */
    function connect() {
        var attempt;

        if (connection) {
            return connection;
        }
        attempt = openDatabase().then(function (db) {
            // fechada pelo navegador, ou a pedido de outra aba que quer atualizar o banco
            db.onclose = function () {
                release(attempt);
            };
            db.onversionchange = function () {
                db.close();
                release(attempt);
            };
            return db;
        });
        attempt.catch(function () {
            release(attempt);
        });
        connection = attempt;
        return attempt;
    }

    /** Uma transação: resolve só depois de gravada de verdade (oncomplete). */
    function runTransaction(db, areas, mode, work) {
        return new Promise(function (resolve, reject) {
            var transaction;
            var result;

            function fail(cause, reopen) {
                var error = asError(cause, STORE_MESSAGE);
                error.reopen = reopen || error.name === 'UnknownError' || error.name === 'InvalidStateError';
                reject(error);
            }

            try {
                transaction = db.transaction(areas, mode);
            } catch (cause) {
                fail(cause, true);      // a conexão já estava fechada
                return;
            }
            transaction.oncomplete = function () {
                resolve(result);
            };
            transaction.onabort = function () {
                fail(transaction.error, false);
            };
            transaction.onerror = function (event) {
                fail(transaction.error || (event && event.target && event.target.error), false);
            };

            try {
                work(transaction, function (value) {
                    result = value;
                });
            } catch (cause) {
                fail(cause, false);
                try {
                    transaction.abort();
                } catch (ignored) {
                    // a transação já tinha terminado
                }
            }
        });
    }

    function transact(areas, mode, work) {
        function attempt() {
            return connect().then(function (db) {
                return runTransaction(db, areas, mode, work);
            });
        }

        return attempt().catch(function (error) {
            if (!error || !error.reopen) {
                throw error;
            }
            // O navegador derruba a conexão com a página em segundo plano: abre outra e repete, uma vez
            connection = null;
            return attempt();
        });
    }

    function prefixRange(prefix) {
        return window.IDBKeyRange.bound(prefix, prefix + '￿');
    }

    // ['delete'] entre colchetes: verificadores de sintaxe antigos não aceitam ".delete"
    var database = {
        get: function (area, key) {
            return transact([area], 'readonly', function (transaction, done) {
                var request = transaction.objectStore(area).get(key);
                request.onsuccess = function () {
                    done(request.result);
                };
            });
        },
        put: function (area, key, value) {
            return transact([area], 'readwrite', function (transaction) {
                transaction.objectStore(area).put(value, key);
            });
        },
        remove: function (area, key) {
            return transact([area], 'readwrite', function (transaction) {
                transaction.objectStore(area)['delete'](key);
            });
        },
        keys: function (area, prefix) {
            return transact([area], 'readonly', function (transaction, done) {
                var request = transaction.objectStore(area).getAllKeys(prefixRange(prefix));
                request.onsuccess = function () {
                    done(request.result || []);
                };
            });
        },
        clear: function (prefix) {
            return transact([KV, BLOBS], 'readwrite', function (transaction) {
                transaction.objectStore(KV)['delete'](prefixRange(prefix));
                transaction.objectStore(BLOBS)['delete'](prefixRange(prefix));
            });
        }
    };

    // ------------------------------------------------------------------ sem banco: memória da página

    function promised(work) {
        return new Promise(function (resolve) {
            resolve(work());
        });
    }

    /** Cópia em JSON: como no banco, quem lê não recebe o mesmo objeto que foi gravado. */
    function copy(value) {
        return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
    }

    function memoryDriver() {
        var areas = {};
        areas[KV] = Object.create(null);
        areas[BLOBS] = Object.create(null);

        function matching(area, prefix) {
            return Object.keys(areas[area]).filter(function (key) {
                return key.indexOf(prefix) === 0;
            }).sort();
        }

        return {
            get: function (area, key) {
                return promised(function () {
                    return area === KV ? copy(areas[area][key]) : areas[area][key];
                });
            },
            put: function (area, key, value) {
                return promised(function () {
                    areas[area][key] = area === KV ? copy(value) : value;
                });
            },
            remove: function (area, key) {
                return promised(function () {
                    delete areas[area][key];
                });
            },
            keys: function (area, prefix) {
                return promised(function () {
                    return matching(area, prefix);
                });
            },
            clear: function (prefix) {
                return promised(function () {
                    [KV, BLOBS].forEach(function (area) {
                        matching(area, prefix).forEach(function (key) {
                            delete areas[area][key];
                        });
                    });
                });
            }
        };
    }

    // ------------------------------------------------------------------ armazenamento (Store)

    /** Conteúdo do arquivo. O FileReader existe em todo navegador; blob.arrayBuffer(), não. */
    function readBuffer(blob) {
        return new Promise(function (resolve, reject) {
            var reader = new window.FileReader();

            function fail() {
                reject(asError(reader.error, 'Não foi possível ler o arquivo.'));
            }

            reader.onload = function () {
                resolve(reader.result);
            };
            reader.onerror = fail;
            reader.onabort = fail;
            reader.readAsArrayBuffer(blob);
        });
    }

    function putBuffer(driver, key, blob) {
        return readBuffer(blob).then(function (buffer) {
            return driver.put(BLOBS, key, {buffer: buffer, type: blob.type || ''});
        });
    }

    /**
     * Alguns navegadores antigos (iOS) não gravam Blob no banco: se falhar, o arquivo vai como
     * {buffer, type} e o Blob é remontado na leitura.
     */
    function putBlob(driver, key, blob) {
        if (!isBlob(blob)) {
            return Promise.reject(new Error('putBlob: o valor precisa ser um Blob.'));
        }
        if (blobAsBuffer) {
            return putBuffer(driver, key, blob);
        }
        return driver.put(BLOBS, key, blob).catch(function () {
            return putBuffer(driver, key, blob).then(function () {
                blobAsBuffer = true;    // deu certo assim: os próximos já vão direto
            });
        });
    }

    function toBlob(value) {
        if (isBlob(value)) {
            return value;
        }
        if (value && value.buffer) {
            return new window.Blob([value.buffer], {type: value.type || ''});
        }
        return undefined;
    }

    /**
     * Armazenamento de um namespace (ex.: "u12:", já com o separador no fim): cada usuário só
     * enxerga as próprias chaves. Quem chama passa e recebe as chaves sem o namespace.
     */
    function makeStore(namespace, driver, available) {
        function full(key) {
            return namespace + (key === undefined || key === null ? '' : String(key));
        }

        return {
            available: available,
            get: function (key) {
                return driver.get(KV, full(key));
            },
            set: function (key, value) {
                return driver.put(KV, full(key), value);
            },
            remove: function (key) {
                return driver.remove(KV, full(key));
            },
            // Chaves dos valores (não dos arquivos) que começam com o prefixo, em ordem
            keys: function (prefix) {
                return driver.keys(KV, full(prefix)).then(function (found) {
                    return found.map(function (key) {
                        return String(key).slice(namespace.length);
                    });
                });
            },
            putBlob: function (key, blob) {
                return putBlob(driver, full(key), blob);
            },
            getBlob: function (key) {
                return driver.get(BLOBS, full(key)).then(toBlob);
            },
            removeBlob: function (key) {
                return driver.remove(BLOBS, full(key));
            },
            // Apaga valores e arquivos cuja chave começa com o prefixo
            clear: function (prefix) {
                return driver.clear(full(prefix));
            }
        };
    }

    /**
     * Abre o armazenamento do namespace. Sempre resolve: sem banco (aba anônima, bloqueio), os dados
     * ficam só na memória da página e "available" vem false, para a tela poder avisar.
     */
    function open(namespace) {
        var prefix = namespace === undefined || namespace === null ? '' : String(namespace);

        if (!usable) {
            usable = connect().then(function () {
                return true;
            }, function () {
                return false;
            });
        }
        return usable.then(function (ok) {
            if (!ok && !memory) {
                memory = memoryDriver();
            }
            return makeStore(prefix, ok ? database : memory, ok);
        });
    }

    // ------------------------------------------------------------------ pedidos ao servidor

    function parseObject(body) {
        var value;

        try {
            value = JSON.parse(body);
        } catch (error) {
            return null;
        }
        return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
    }

    function abort(holder) {
        if (holder && typeof holder.abort === 'function') {
            try {
                holder.abort();
            } catch (error) {
                // o pedido já tinha terminado
            }
        }
    }

    /**
     * Pedido HTTP com tempo limite (sem AbortController, segue sem limite). Nunca rejeita: devolve
     * {status, data} (data = o JSON da resposta, quando é um objeto) ou {network: true} quando não
     * houve resposta. Enquanto o pedido dura, holder.abort() o interrompe.
     */
    function request(url, init, timeoutMs, holder) {
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timedOut = false;
        var timer = null;

        function done(reply) {
            if (timer !== null) {
                window.clearTimeout(timer);
            }
            holder.abort = null;
            return reply;
        }

        if (controller) {
            init.signal = controller.signal;
            holder.abort = function () {
                controller.abort();
            };
            timer = window.setTimeout(function () {
                timedOut = true;
                controller.abort();
            }, timeoutMs);
        }

        return new Promise(function (resolve) {
            resolve(window.fetch(url, init));
        }).then(function (response) {
            // O tempo limite também vale para a leitura do corpo, que pode parar no meio
            return response.text().then(function (body) {
                return done({status: response.status, data: parseObject(body)});
            });
        }).catch(function () {
            return done({network: true, timedOut: timedOut});
        });
    }

    /** Recusa sempre no mesmo formato, mesmo quando o servidor não mandou JSON. */
    function refusal(data, status) {
        var response = data || {};

        response.ok = false;
        if (typeof response.message !== 'string' || response.message === '') {
            response.message = 'O servidor recusou o envio (HTTP ' + status + ').';
        }
        return response;
    }

    /**
     * Traduz a resposta do servidor no que a fila faz com a tarefa:
     *   done       aceita: sai da fila;
     *   rejected   recusa definitiva (regra de negócio, ou falta de direito): sai da fila;
     *   retry      fica na fila e tenta de novo depois (rede, tempo esgotado, 5xx, 429, retry = true);
     *   login      sessão encerrada (401);
     *   unexpected não é resposta do sistema (403 sem "code", 200 sem JSON, outro 4xx): o token pode
     *              ter vencido.
     */
    function classify(reply) {
        var data = reply.data;
        var status = reply.status;

        if (reply.network) {
            return {
                kind: 'retry',
                online: false,
                message: reply.timedOut ? 'O servidor demorou para responder.' : 'Sem conexão com o servidor.'
            };
        }
        if (status === 401) {
            return {kind: 'login'};
        }
        if (status >= 500 || status === 429 || status === 408) {
            return {kind: 'retry', message: 'O servidor não conseguiu atender agora (HTTP ' + status + ').'};
        }
        if (status >= 200 && status < 300 && data) {
            if (data.ok === true) {
                return {kind: 'done', response: data};
            }
            if (data.retry === true) {
                return {kind: 'retry', online: true, message: data.message || 'O servidor pediu para tentar de novo.'};
            }
            return {kind: 'rejected', response: refusal(data, status)};
        }
        if (status >= 300 && data && data.code) {
            return {kind: 'rejected', response: refusal(data, status)};
        }
        return {kind: 'unexpected'};
    }

    /**
     * Cópia do arquivo em memória. O Blob que vem do banco aponta para um arquivo do navegador: se
     * esse arquivo sumiu, o envio falharia como se fosse a rede e a fila ficaria presa para sempre.
     * Devolve null quando não dá para ler.
     */
    function inMemory(blob) {
        return readBuffer(blob).then(function (buffer) {
            return new window.Blob([buffer], {type: blob.type || ''});
        }, function () {
            return null;
        });
    }

    // ------------------------------------------------------------------ fila de envio (Queue)

    /**
     * Fila de tarefas gravada no Store, em "queue:<name>:<ordem>". Envia uma tarefa por vez, na ordem
     * em que entraram; a tarefa só sai da fila depois da resposta do servidor (fechar a página no meio
     * do envio não perde nada).
     */
    function createQueue(options) {
        var settings = options || {};
        var store = settings.store;
        var prefix = 'queue:' + String(settings.name || 'default') + ':';
        var seqKey = prefix + 'seq';
        var pingMs = positive(settings.pingMs, PING_MS);
        var timeoutMs = positive(settings.timeoutMs, TIMEOUT_MS);
        var token = settings.token ? String(settings.token) : '';
        var state = {online: true, pending: 0, sending: false, needLogin: false, lastError: null};
        var started = false;
        var busy = false;                   // há uma rodada de envio em andamento
        var epoch = 0;                      // muda em stop() e clear(): o que estava em andamento deixa de valer
        var active = null;                  // tarefa em envio, para poder interromper o pedido
        var pingHolder = {abort: null};     // idem para o ping
        var probing = false;
        var retryTimer = null;
        var pingTimer = null;
        var failures = 0;                   // tentativas seguidas sem sucesso: escolhe a espera
        var sequence = 0;                   // último número de ordem usado
        var lastContact = Date.now();       // última resposta boa do servidor
        var writes = Promise.resolve();     // operações na fila gravada, uma depois da outra

        if (!store || !settings.url || !settings.tokenUrl) {
            throw new Error('ChecklistOffline.createQueue: informe store, url e tokenUrl.');
        }
        state.online = window.navigator.onLine !== false;

        // -------------------------------------------------------------- situação

        function snapshot() {
            return {
                online: state.online,
                pending: state.pending,
                sending: state.sending,
                needLogin: state.needLogin,
                lastError: state.lastError
            };
        }

        /** Avisa a tela só quando algum campo muda de fato. */
        function setState(changes) {
            var changed = false;

            Object.keys(changes).forEach(function (name) {
                if (state[name] !== changes[name]) {
                    state[name] = changes[name];
                    changed = true;
                }
            });
            if (changed) {
                invoke(settings.onStatus, snapshot());
            }
        }

        // -------------------------------------------------------------- fila gravada no aparelho

        function pad(number) {
            var text = String(number);

            while (text.length < SEQ_DIGITS) {
                text = '0' + text;
            }
            return text;
        }

        function orderOf(key) {
            return key.slice(prefix.length);
        }

        function taskKeys() {
            return store.keys(prefix).then(function (keys) {
                var found = keys.filter(function (key) {
                    return /^\d+$/.test(orderOf(key));      // "seq" fica de fora
                }).sort();

                if (found.length) {
                    sequence = Math.max(sequence, parseInt(orderOf(found[found.length - 1]), 10));
                }
                return found;
            });
        }

        /** Uma operação por vez na fila gravada: duas ao mesmo tempo pegariam o mesmo número. */
        function serialize(work) {
            var result = writes.then(work);

            writes = result.then(noop, noop);
            return result;
        }

        function nextSequence() {
            return store.get(seqKey).then(function (saved) {
                var number = Math.max(sequence, parseInt(saved, 10) || 0) + 1;

                sequence = number;
                // O número é gravado antes da tarefa: se a página fechar no meio, só se pula um número
                return store.set(seqKey, number).then(function () {
                    return number;
                });
            });
        }

        function pairs(list, name) {
            if (list === undefined || list === null) {
                return [];
            }
            if (!Array.isArray(list) || !list.every(Array.isArray)) {
                throw new Error('Tarefa inválida: "' + name + '" precisa ser uma lista de listas.');
            }
            return list;
        }

        /** A tarefa é gravada como JSON puro: é assim que ela volta do aparelho, com ou sem banco. */
        function normalize(task) {
            if (!task || typeof task !== 'object' || !task.action) {
                throw new Error('Tarefa inválida: falta "action".');
            }
            return JSON.parse(JSON.stringify({
                id: task.id ? String(task.id) : uuid(),
                action: String(task.action),
                fields: pairs(task.fields, 'fields'),
                blobs: pairs(task.blobs, 'blobs'),
                meta: task.meta === undefined ? null : task.meta
            }));
        }

        function sendable(task) {
            return !!task && typeof task === 'object' && typeof task.action === 'string'
                && Array.isArray(task.fields) && Array.isArray(task.blobs);
        }

        /** Primeira tarefa da fila, ou null se não há nenhuma. */
        function head() {
            return taskKeys().then(function (keys) {
                setState({pending: keys.length});
                if (!keys.length) {
                    return null;
                }
                return store.get(keys[0]).then(function (task) {
                    return {key: keys[0], task: task};
                });
            });
        }

        // -------------------------------------------------------------- montagem do envio

        function headers(withToken) {
            var list = {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'};

            if (withToken) {
                list['X-Glpi-Csrf-Token'] = token;
            }
            return list;
        }

        /** O PHP leria "true"/"false" como texto: sim e não vão como 1 e 0. */
        function fieldText(value) {
            if (value === true) {
                return '1';
            }
            if (value === false) {
                return '0';
            }
            return value === undefined || value === null ? '' : String(value);
        }

        function postOptions(task, list) {
            var form = new window.FormData();

            form.append('action', task.action);
            task.fields.forEach(function (pair) {
                form.append(String(pair[0]), fieldText(pair[1]));
            });
            list.forEach(function (file) {
                form.append(file.field, file.blob, file.name);
            });
            return {method: 'POST', body: form, headers: headers(true), credentials: 'same-origin'};
        }

        /** Arquivos da tarefa, já em memória; null quando algum não existe mais ou não abre. */
        function files(task) {
            return Promise.all(task.blobs.map(function (item) {
                return store.getBlob(item[1]).then(function (blob) {
                    return isBlob(blob) ? inMemory(blob) : null;
                }).then(function (blob) {
                    return blob ? {field: String(item[0]), blob: blob, name: String(item[2] || 'arquivo')} : null;
                });
            })).then(function (list) {
                return list.indexOf(null) === -1 ? list : null;
            });
        }

        // -------------------------------------------------------------- conversa com o servidor

        function stale(job) {
            return job.epoch !== epoch;
        }

        /**
         * GET tokenUrl: confirma a sessão e pega um token novo. Devolve no mesmo formato de classify();
         * "done" quer dizer token trocado.
         */
        function requestToken(holder) {
            var init = {method: 'GET', headers: headers(false), credentials: 'same-origin', cache: 'no-store'};

            return request(settings.tokenUrl, init, timeoutMs, holder).then(function (reply) {
                var outcome = classify(reply);

                if (outcome.kind === 'done' && typeof reply.data.token === 'string' && reply.data.token !== '') {
                    token = reply.data.token;
                    lastContact = Date.now();
                    return outcome;
                }
                if (outcome.kind === 'login' || outcome.kind === 'retry'
                    || (outcome.kind === 'rejected' && reply.data.code)) {
                    return outcome;
                }
                // Página de login do Wi-Fi, proxy no caminho, plugin desativado: não é o sistema respondendo
                return {kind: 'retry', online: false, message: 'O servidor não respondeu como esperado.'};
            });
        }

        /**
         * Resposta que não veio do sistema: pede um token novo e repete a mesma tarefa. Se o sistema
         * entrega o token e a tarefa continua sem passar, o problema é dela: sai da fila como recusada.
         */
        function renew(job, reply) {
            var response;

            if (job.swaps >= MAX_TOKEN_SWAPS) {
                response = refusal(reply.data, reply.status);
                response.code = response.code || 'http';
                return {kind: 'rejected', response: response};
            }
            return requestToken(job).then(function (result) {
                if (result.kind !== 'done') {
                    return result;      // sem sessão, sem direito ou sem conexão: vale para a tarefa
                }
                job.swaps += 1;
                return exchange(job);
            });
        }

        /** Envia a tarefa e devolve o que fazer com ela (formato de classify()). */
        function exchange(job) {
            if (stale(job)) {
                return Promise.resolve({kind: 'stale'});
            }
            return files(job.task).then(function (list) {
                if (stale(job)) {
                    return {kind: 'stale'};
                }
                if (!list) {
                    return {kind: 'rejected', local: true, response: {ok: false, message: BLOB_MESSAGE, code: 'blob'}};
                }
                return request(settings.url, postOptions(job.task, list), timeoutMs, job).then(function (reply) {
                    var outcome = classify(reply);
                    return outcome.kind === 'unexpected' ? renew(job, reply) : outcome;
                });
            });
        }

        // -------------------------------------------------------------- andamento da fila

        function clearRetry() {
            if (retryTimer !== null) {
                window.clearTimeout(retryTimer);
                retryTimer = null;
            }
        }

        /** Próxima tentativa com espera crescente (2 s, 5 s, 10 s, 30 s, 60 s). */
        function retryLater() {
            clearRetry();
            if (!started || state.needLogin) {
                return;
            }
            retryTimer = window.setTimeout(function () {
                retryTimer = null;
                pump();
            }, BACKOFF_MS[Math.min(failures, BACKOFF_MS.length - 1)]);
            failures += 1;
        }

        /** Sessão encerrada: tudo parado até start() ser chamado de novo. */
        function loginNeeded(changes) {
            clearRetry();
            changes.needLogin = true;
            changes.online = true;
            changes.lastError = LOGIN_MESSAGE;
            setState(changes);
        }

        /** Falha passageira: a tarefa continua na fila. */
        function failed(outcome) {
            var changes = {sending: false, lastError: outcome.message};

            if (outcome.online !== undefined) {
                changes.online = outcome.online;
            }
            busy = false;
            active = null;
            setState(changes);
            retryLater();
        }

        /**
         * Tarefa resolvida (aceita, recusada de vez ou registro estragado): primeiro sai da fila, depois
         * a tela é avisada. Se a tela trocar de página no aviso, a tarefa já não volta a ser enviada.
         */
        function finish(job, outcome) {
            return store.remove(job.key).then(function () {
                var left = Math.max(0, state.pending - 1);
                var changes = {pending: left};
                var alive = !stale(job);

                if (alive) {
                    busy = false;
                    active = null;
                    failures = 0;
                    changes.sending = left > 0 && started && !state.needLogin;
                    if (!state.needLogin) {
                        changes.lastError = null;   // com a sessão encerrada, o aviso de login continua
                    }
                }
                if (!outcome.local) {
                    lastContact = Date.now();
                    changes.online = true;
                }
                setState(changes);
                if (outcome.kind === 'done') {
                    invoke(settings.onDone, job.task, outcome.response);
                } else if (outcome.kind === 'rejected') {
                    invoke(settings.onRejected, job.task, outcome.response);
                }
                if (alive) {
                    pump();
                }
            });
        }

        function settle(job, outcome) {
            if (outcome.kind === 'done' || outcome.kind === 'rejected') {
                return finish(job, outcome);
            }
            if (outcome.kind === 'login') {
                busy = false;
                active = null;
                loginNeeded({sending: false});
                return null;
            }
            failed(outcome);
            return null;
        }

        /**
         * GET tokenUrl sem tarefa nenhuma: mantém a sessão viva, renova o token e confere se a conexão
         * voltou. Sem conexão, a conferência se repete com a mesma espera crescente.
         */
        function probe() {
            var mine = epoch;

            if (probing) {
                return;
            }
            probing = true;
            requestToken(pingHolder).then(function (result) {
                probing = false;
                if (mine === epoch) {
                    if (result.kind === 'login') {
                        loginNeeded({});
                    } else if (result.kind === 'done') {
                        failures = 0;
                        setState({online: true});
                    } else if (result.online === false) {
                        setState({online: false});
                    }
                }
                if (!state.online && !busy && retryTimer === null) {
                    retryLater();
                }
            });
        }

        /** Fila vazia: sobra conferir a conexão, se ela caiu ou se faz tempo que o servidor não responde. */
        function idle() {
            busy = false;
            active = null;
            setState({sending: false});
            if (!state.online || Date.now() - lastContact >= pingMs) {
                probe();
            }
        }

        /** Envia a primeira tarefa da fila, se nada estiver em andamento. */
        function pump() {
            var job;

            if (!started || state.needLogin || busy) {
                return;
            }
            clearRetry();
            busy = true;
            job = {epoch: epoch, key: null, task: null, swaps: 0, abort: null};
            active = job;

            serialize(head).then(function (entry) {
                if (stale(job)) {
                    return null;
                }
                if (!entry) {
                    idle();
                    return null;
                }
                job.key = entry.key;
                job.task = entry.task;
                if (!sendable(entry.task)) {
                    return finish(job, {kind: 'discard', local: true});     // registro estragado não trava a fila
                }
                setState({sending: true});
                return exchange(job).then(function (outcome) {
                    return stale(job) ? null : settle(job, outcome);
                });
            }).catch(function (error) {
                if (!stale(job)) {
                    failed({message: (error && error.message) || STORE_MESSAGE});
                }
            });
        }

        /** Interrompe o que estiver em andamento; a tarefa interrompida continua na fila. */
        function interrupt() {
            epoch += 1;
            clearRetry();
            abort(active);
            active = null;
            busy = false;
            setState({sending: false});
        }

        // -------------------------------------------------------------- eventos do navegador

        /** Tenta já, sem esperar: pedido da tela, conexão de volta ou aba de novo à vista. */
        function wake() {
            failures = 0;
            clearRetry();
            pump();
        }

        function wentOffline() {
            setState({online: false});
            if (!busy && retryTimer === null) {
                retryLater();
            }
        }

        function visibilityChanged() {
            if (!document.hidden) {
                wake();
            }
        }

        function tick() {
            if (state.online && !state.needLogin && !busy) {
                probe();
            }
        }

        function listen(on) {
            var method = on ? 'addEventListener' : 'removeEventListener';

            window[method]('online', wake);
            window[method]('offline', wentOffline);
            document[method]('visibilitychange', visibilityChanged);
        }

        // -------------------------------------------------------------- API da fila

        /** Grava a tarefa no fim da fila. Resolve quando ela está gravada (não quando foi enviada). */
        function push(task) {
            var saved;

            try {
                saved = normalize(task);
            } catch (error) {
                return Promise.reject(error);
            }
            if (!task.id) {
                try {
                    task.id = saved.id;     // id gerado aqui: quem chamou acompanha a tarefa por ele
                } catch (error) {
                    // objeto que não aceita alteração: o id segue na tarefa devolvida
                }
            }
            return serialize(function () {
                return nextSequence().then(function (number) {
                    return store.set(prefix + pad(number), saved);
                });
            }).then(function () {
                setState({pending: state.pending + 1});
                if (retryTimer === null) {
                    pump();     // em espera depois de uma falha, a tarefa nova aguarda a vez sem furar a espera
                }
                return saved;
            });
        }

        /** Liga o envio. Chamar de novo não muda nada, a não ser depois de needLogin: aí retoma. */
        function start() {
            if (started && !state.needLogin) {
                return;
            }
            if (!started) {
                started = true;
                listen(true);
                pingTimer = window.setInterval(tick, pingMs);
            }
            failures = 0;
            if (state.needLogin) {
                setState({needLogin: false, lastError: null});
            }
            pump();
        }

        /** Para o envio e o ping. O pedido em andamento é interrompido e a tarefa fica na fila. */
        function stop() {
            if (!started) {
                return;
            }
            started = false;
            listen(false);
            window.clearInterval(pingTimer);
            pingTimer = null;
            abort(pingHolder);
            interrupt();
        }

        function pendingTasks() {
            return serialize(function () {
                return taskKeys().then(function (keys) {
                    return Promise.all(keys.map(function (key) {
                        return store.get(key);
                    }));
                });
            }).then(function (tasks) {
                return tasks.filter(function (task) {
                    return !!task;
                });
            });
        }

        /** Apaga todas as tarefas pendentes, inclusive a que estava em envio. Os arquivos ficam. */
        function clear() {
            interrupt();
            return serialize(function () {
                return taskKeys().then(function (keys) {
                    return Promise.all(keys.map(function (key) {
                        return store.remove(key);
                    }));
                });
            }).then(function () {
                failures = 0;
                setState({pending: 0, lastError: null});
                pump();
            }, function (error) {
                pump();
                throw error;
            });
        }

        // Tarefas que ficaram de outra visita: a tela já vê quantas são antes de start()
        serialize(function () {
            return taskKeys().then(function (keys) {
                setState({pending: keys.length});
            });
        }).catch(noop);

        return {
            push: push,
            start: start,
            stop: stop,
            retryNow: wake,
            status: snapshot,
            pending: pendingTasks,
            clear: clear
        };
    }

    window.ChecklistOffline = {
        open: open,
        createQueue: createQueue,
        uuid: uuid
    };
})();
