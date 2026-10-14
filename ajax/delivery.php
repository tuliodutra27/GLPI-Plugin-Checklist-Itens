<?php

use GlpiPlugin\Checklistitens\DefectPhoto;
use GlpiPlugin\Checklistitens\Delivery;
use GlpiPlugin\Checklistitens\DeliveryPhoto;
use GlpiPlugin\Checklistitens\DeliveryReceipt;
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
    $respond(Delivery::fail(__('O servidor não recebeu os dados do pedido (o envio pode ser grande demais).', 'checklistitens')));
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

$text = static function (string $name): string {
    $value = $_POST[$name] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
};
$int  = static fn (string $name): int => (int) $text($name);
$list = static function (string $name): array {
    $value = $_POST[$name] ?? [];

    return is_array($value) ? $value : [];
};

$invalid = __('Pedido inválido.', 'checklistitens');

/**
 * Separação ou recebimento em andamento do usuário que recebe (ou perde) uma foto.
 *
 * @return array{deliveries_id?: int, receipts_id?: int, error?: array}
 */
$photo_target = static function (string $kind, int $id) use ($users_id, $invalid): array {
    if ($kind === 'delivery') {
        $row = Delivery::getRow($id);
        $row = ($row !== null && (int) $row['users_id'] === $users_id) ? $row : null;
        if ($row === null || (int) $row['status'] !== Delivery::STATUS_DRAFT) {
            return ['error' => Delivery::gone($row)];
        }

        return ['deliveries_id' => $id, 'receipts_id' => 0];
    }
    if ($kind === 'receipt') {
        $row = DeliveryReceipt::getRow($id);
        $row = ($row !== null && (int) $row['users_id'] === $users_id) ? $row : null;
        if ($row === null || (int) $row['status'] !== DeliveryReceipt::STATUS_DRAFT) {
            return ['error' => DeliveryReceipt::gone($row)];
        }

        return ['deliveries_id' => (int) $row['plugin_checklistitens_deliveries_id'], 'receipts_id' => $id];
    }

    return ['error' => Delivery::fail($invalid)];
};

try {
    if (!$is_post) {
        switch ($action) {
            case 'state':
                $kind = $_GET['kind'] ?? 'delivery';
                if ($kind === 'receipt') {
                    $draft  = DeliveryReceipt::getDraftFor($users_id);
                    $result = ['ok' => true, 'message' => '', 'receipt' => $draft !== null ? DeliveryReceipt::getState($draft) : null];
                } elseif ($kind === 'delivery') {
                    $draft  = Delivery::getDraftFor($users_id);
                    $result = ['ok' => true, 'message' => '', 'delivery' => $draft !== null ? Delivery::getState($draft) : null];
                } else {
                    $result = Delivery::fail($invalid);
                }
                break;

            case 'available':
                $result = ['ok' => true, 'message' => '', 'items' => Delivery::getAvailableRadios()];
                break;

            default:
                // inclui as ações de gravação pedidas por GET
                $result = Delivery::fail($invalid);
        }
    } else {
        switch ($action) {
            case 'reserve':
                $result = Delivery::reserve($text('client_uuid'), $int('vessels_id'), $list('items'));
                break;

            case 'add_items':
                $result = Delivery::addItems($int('delivery_id'), $list('items'));
                break;

            case 'remove_item':
                $result = Delivery::removeItem($int('delivery_id'), $int('items_id'));
                break;

            case 'set_vessel':
                $result = Delivery::setVessel($int('delivery_id'), $int('vessels_id'));
                break;

            case 'photo':
            case 'photo_delete':
                $target = $photo_target($text('kind'), $int('id'));
                if (isset($target['error'])) {
                    $result = $target['error'];
                    break;
                }
                if ($action === 'photo') {
                    $result = DeliveryPhoto::storeUploaded($target['deliveries_id'], $target['receipts_id'], $text('uuid'));
                    break;
                }
                // Foto que já não existe conta como apagada (pedido reenviado)
                $result = DeliveryPhoto::remove($target['deliveries_id'], $target['receipts_id'], $text('uuid'))
                    ? ['ok' => true, 'message' => '']
                    : Delivery::retry(__('Não foi possível apagar a foto. Tente de novo.', 'checklistitens'));
                $result['count'] = DeliveryPhoto::countFor($target['deliveries_id'], $target['receipts_id']);
                break;

            case 'defect_photo':
                $usage_id      = $int('usage_id');
                $phase         = $int('phase');
                $usage         = $usage_id > 0 ? $DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['id' => $usage_id]])->current() : null;
                $deliveries_id = $usage ? (int) $usage['plugin_checklistitens_deliveries_id'] : 0;

                if ($phase === Usage::PHASE_CHECKOUT) {
                    // Na separação: rádio reservado na separação aberta do usuário
                    $draft = Delivery::getDraftFor($users_id);
                    if ($draft === null) {
                        $result = Delivery::gone();
                        break;
                    }
                    $in_list = $usage
                        && $deliveries_id === (int) $draft['id']
                        && (int) $usage['status'] === Usage::STATUS_DELIVERY_DRAFT;
                } elseif ($phase === Usage::PHASE_CHECKIN) {
                    // No recebimento: rádio com a embarcação, da entrega que o usuário está recebendo
                    $draft = DeliveryReceipt::getDraftFor($users_id);
                    if ($draft === null) {
                        $result = DeliveryReceipt::gone();
                        break;
                    }
                    $in_list = $usage
                        && $deliveries_id === (int) $draft['plugin_checklistitens_deliveries_id']
                        && (int) $usage['status'] === Usage::STATUS_ON_VESSEL;
                } else {
                    $result = Delivery::fail($invalid);
                    break;
                }
                if (!$in_list) {
                    $result = Delivery::fail(__('Este rádio não está mais na lista. Atualize a tela.', 'checklistitens'));
                    break;
                }

                $result = DefectPhoto::storeUploaded($usage_id, $phase, $text('uuid'));
                if ($result['ok']) {
                    // a foto conta como atividade: o que expira é o processo parado
                    if ($phase === Usage::PHASE_CHECKOUT) {
                        Delivery::touch((int) $draft['id']);
                    } else {
                        DeliveryReceipt::touch((int) $draft['id']);
                    }
                }
                break;

            case 'item_problem':
                $result = Delivery::itemProblem($int('delivery_id'), $int('items_id'), $list('problems'), $text('known') === '1');
                break;

            case 'finish':
                $result = Delivery::finish($int('delivery_id'));
                break;

            case 'cancel':
                $result = Delivery::cancel($int('delivery_id'));
                break;

            case 'receipt_start':
                $result = DeliveryReceipt::start($int('deliveries_id'), $text('client_uuid'));
                break;

            case 'receipt_finish':
                $result = DeliveryReceipt::finish($int('receipt_id'), $list('usages'), $list('problems'), $list('known'));
                break;

            case 'receipt_cancel':
                $result = DeliveryReceipt::cancel($int('receipt_id'));
                break;

            default:
                $result = Delivery::fail($invalid);
        }
    }
} catch (Throwable $e) {
    // Erro inesperado (banco fora do ar, disco cheio): o aparelho guarda o pedido e tenta de novo
    Toolbox::logError(sprintf('checklistitens: %s: %s em %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    $result = Delivery::retry(__('Não foi possível concluir o pedido. Tente de novo.', 'checklistitens'));
}

$respond($result);
