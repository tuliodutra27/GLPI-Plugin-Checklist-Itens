<?php

use GlpiPlugin\Checklistitens\Confirmation;
use GlpiPlugin\Checklistitens\Delivery;
use GlpiPlugin\Checklistitens\DeliveryReceipt;
use GlpiPlugin\Checklistitens\Selfie;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

Session::checkLoginUser();

global $DB;

$kind = (string) ($_GET['kind'] ?? '');
$id   = (int) ($_GET['id'] ?? 0);

switch ($kind) {
    case Selfie::KIND_CHECKOUT:
    case Selfie::KIND_CHECKIN:
        $row   = $DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['id' => $id]])->current();
        $field = $kind . '_selfie';
        break;

    case Selfie::KIND_CONFIRMATION:
        $row   = $DB->request(['FROM' => Confirmation::getTable(), 'WHERE' => ['id' => $id]])->current();
        $field = 'selfie';
        break;

    // Selfie do gestor na entrega para embarcação e no recebimento de volta
    case Selfie::KIND_DELIVERY:
        $row   = $DB->request(['FROM' => Delivery::getTable(), 'WHERE' => ['id' => $id]])->current();
        $field = 'selfie';
        break;

    case Selfie::KIND_RECEIPT:
        $row   = $DB->request(['FROM' => DeliveryReceipt::getTable(), 'WHERE' => ['id' => $id]])->current();
        $field = 'selfie';
        break;

    default:
        $row = null;
}

if (!$row) {
    http_response_code(404);
    exit;
}

if (!Selfie::canView((int) $row['users_id'], (int) $row['groups_id'])) {
    Html::displayRightError();
}

Selfie::folder()->send((string) $row[$field]);
