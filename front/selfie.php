<?php

use GlpiPlugin\Checklistitens\Install;
use GlpiPlugin\Checklistitens\Selfie;

include('../../../inc/includes.php');

Session::checkLoginUser();

global $DB;

$kind = (string) ($_GET['kind'] ?? '');
$id   = (int) ($_GET['id'] ?? 0);

switch ($kind) {
    case Selfie::KIND_CHECKOUT:
    case Selfie::KIND_CHECKIN:
        $row   = $DB->request(['FROM' => Install::TABLE_USAGES, 'WHERE' => ['id' => $id]])->current();
        $field = $kind . '_selfie';
        break;

    case Selfie::KIND_CONFIRMATION:
        $row   = $DB->request(['FROM' => Install::TABLE_CONFIRMATIONS, 'WHERE' => ['id' => $id]])->current();
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

Selfie::send((string) $row[$field]);
