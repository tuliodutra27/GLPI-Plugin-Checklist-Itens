<?php

use GlpiPlugin\Checklistitens\DefectPhoto;
use GlpiPlugin\Checklistitens\Delivery;
use GlpiPlugin\Checklistitens\DeliveryPhoto;
use GlpiPlugin\Checklistitens\DeliveryReceipt;
use GlpiPlugin\Checklistitens\Input;
use GlpiPlugin\Checklistitens\Reply;
use GlpiPlugin\Checklistitens\Usage;

/**
 * Recebe, em partes, a entrega de rádios para embarcação e o recebimento de volta: o aparelho
 * guarda o que o gestor faz e envia um pedido por vez (reserva, cada foto, conclusão), repetindo o
 * mesmo pedido quando a conexão cai. Por isso a resposta é sempre JSON e toda ação aceita ser
 * repetida.
 *
 * Leitura por GET (action em $_GET); gravação só por POST (action em $_POST). O token dos POST vem
 * no cabeçalho X-Glpi-Csrf-Token e é conferido pelo próprio GLPI, que o mantém válido até expirar.
 */

// O que o GLPI ou o PHP escreverem pelo caminho (avisos do modo de depuração) fica retido e é
// descartado: a resposta precisa ser só o JSON
ob_start();
header('Content-Type: application/json; charset=UTF-8');

$AJAX_INCLUDE = 1;
include('../../../inc/includes.php');

global $DB;

/**
 * Envia a resposta e encerra.
 */
$respond = static function (array $data, int $http = 200): void {
    while (ob_get_level() > 0 && @ob_end_clean()) {
        // descarta tudo o que foi escrito até aqui
    }

    // Erro de regra sempre diz se vale reenviar o mesmo pedido
    if ($http === 200 && ($data['ok'] ?? null) === false) {
        $data += ['message' => '', 'retry' => false];
    }

    http_response_code($http);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    Html::header_nocache();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
};

// Sem sessão: o aparelho guarda os pedidos e pede o login de novo. Não dá para usar
// Session::checkLoginUser() aqui, porque ele responde com um redirecionamento em HTML
$users_id = (int) Session::getLoginUserID();
$logged   = $users_id > 0
    && isset($_SESSION['valid_id'], $_SESSION['glpiname'])
    && $_SESSION['valid_id'] === session_id()
    && countElementsInTable(User::getTable(), ['id' => $users_id, 'is_active' => 1, 'is_deleted' => 0]) > 0;
if (!$logged) {
    $respond(['ok' => false, 'code' => 'login'], 401);
}

$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// POST maior que o limite do PHP chega sem campo nenhum (e o GLPI só confere o token quando há
// campos): nada é executado
if ($is_post && !count($_POST)) {
    $respond(Reply::fail(__('O servidor não recebeu os dados do pedido (o envio pode ser grande demais).', 'checklistitens')));
}

$action = $is_post ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
$action = is_string($action) ? $action : '';

// O token novo só depende da sessão: é com ele que o aparelho mantém a sessão viva e repete o
// pedido recusado por token vencido
if (!$is_post && $action === 'token') {
    $respond(['ok' => true, 'token' => Session::getNewCSRFToken(true)]);
}

if (!Delivery::canDeliver()) {
    $respond(['ok' => false, 'code' => 'right', 'message' => Delivery::noRightMessage()], 403);
}

$invalid = __('Pedido inválido.', 'checklistitens');

// Os dois processos que o aparelho envia: por tipo ("kind") e pela fase das fotos do defeito
$processes = ['delivery' => Delivery::class, 'receipt' => DeliveryReceipt::class];
$by_phase  = [Delivery::DEFECT_PHASE => Delivery::class, DeliveryReceipt::DEFECT_PHASE => DeliveryReceipt::class];

try {
    if (!$is_post) {
        switch ($action) {
            case 'state':
                $kind    = (string) ($_GET['kind'] ?? 'delivery');
                $process = $processes[$kind] ?? null;
                if ($process === null) {
                    $result = Reply::fail($invalid);
                    break;
                }
                $draft  = $process::getDraftFor($users_id);
                $result = Reply::ok([$kind => $draft !== null ? $process::getState($draft) : null]);
                break;

            case 'available':
                $result = Reply::ok(['items' => Delivery::getAvailableRadios()]);
                break;

            default:
                // inclui as ações de gravação pedidas por GET
                $result = Reply::fail($invalid);
        }
    } else {
        switch ($action) {
            case 'reserve':
                $result = Delivery::reserve(Input::text('client_uuid'), Input::int('vessels_id'), Input::list('items'));
                break;

            case 'add_items':
                $result = Delivery::addItems(Input::int('delivery_id'), Input::list('items'));
                break;

            case 'remove_item':
                $result = Delivery::removeItem(Input::int('delivery_id'), Input::int('items_id'));
                break;

            case 'set_vessel':
                $result = Delivery::setVessel(Input::int('delivery_id'), Input::int('vessels_id'));
                break;

            case 'photo':
            case 'photo_delete':
                $process = $processes[Input::text('kind')] ?? null;
                if ($process === null) {
                    $result = Reply::fail($invalid);
                    break;
                }
                $id     = Input::int('id');
                $result = $process::requireOwnDraft($id, $draft);
                if ($result !== null) {
                    break;
                }
                [$deliveries_id, $receipts_id] = $process::photoOwner($draft);
                if ($action === 'photo') {
                    // "ref": foto de bateria ou capa com defeito, só no recebimento
                    $ref    = $receipts_id > 0 ? Input::text('ref') : '';
                    $result = DeliveryPhoto::storeUploaded($deliveries_id, $receipts_id, Input::text('uuid'), $ref);
                } else {
                    // Foto que já não existe conta como apagada (pedido reenviado)
                    $result = DeliveryPhoto::remove($deliveries_id, $receipts_id, Input::text('uuid'))
                        ? Reply::ok()
                        : Reply::retry(__('Não foi possível apagar a foto. Tente de novo.', 'checklistitens'));
                    $result['count'] = DeliveryPhoto::countFor($deliveries_id, $receipts_id);
                }
                // a foto conta como atividade: o que expira é o processo parado
                if ($result['ok']) {
                    $process::touch($id);
                }
                break;

            case 'defect_photo':
                $phase   = Input::int('phase');
                $process = $by_phase[$phase] ?? null;
                if ($process === null) {
                    $result = Reply::fail($invalid);
                    break;
                }
                $draft = $process::getDraftFor($users_id);
                if ($draft === null) {
                    $result = $process::gone();
                    break;
                }
                $usage_id = Input::int('usage_id');
                $usage    = $usage_id > 0 ? $DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['id' => $usage_id]])->current() : null;
                if (!$usage || !$process::holdsItem($draft, $usage)) {
                    $result = Reply::fail(__('Este rádio não está mais na lista. Atualize a tela.', 'checklistitens'));
                    break;
                }

                $result = DefectPhoto::storeUploaded($usage_id, $phase, Input::text('uuid'));
                if ($result['ok']) {
                    $process::touch((int) $draft['id']);
                }
                break;

            case 'item_problem':
                $result = Delivery::itemProblem(Input::int('delivery_id'), Input::int('items_id'), Input::list('problems'), Input::text('known') === '1');
                break;

            case 'finish':
                $result = Delivery::finish(Input::int('delivery_id'));
                break;

            case 'cancel':
                $result = Delivery::cancel(Input::int('delivery_id'));
                break;

            case 'receipt_start':
                $result = DeliveryReceipt::start(Input::int('deliveries_id'), Input::text('client_uuid'));
                break;

            case 'receipt_finish':
                $result = DeliveryReceipt::finish(Input::int('receipt_id'), Input::list('usages'), Input::list('problems'), Input::list('known'), Input::list('acc'));
                break;

            case 'receipt_cancel':
                $result = DeliveryReceipt::cancel(Input::int('receipt_id'));
                break;

            default:
                $result = Reply::fail($invalid);
        }
    }
} catch (Throwable $e) {
    // Erro inesperado (banco fora do ar, disco cheio): o aparelho guarda o pedido e tenta de novo
    Toolbox::logError(sprintf('checklistitens: %s: %s em %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    $result = Reply::retry(__('Não foi possível concluir o pedido. Tente de novo.', 'checklistitens'));
}

$respond($result);
