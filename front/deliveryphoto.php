<?php

use GlpiPlugin\Checklistitens\DeliveryPhoto;
use GlpiPlugin\Checklistitens\Install;

include('../../../inc/includes.php');

Session::checkLoginUser();

global $DB;

$id = (int) ($_GET['id'] ?? 0);

$photo = $id > 0
    ? $DB->request(['FROM' => Install::TABLE_DELIVERYPHOTOS, 'WHERE' => ['id' => $id]])->current()
    : null;

if (!$photo) {
    http_response_code(404);
    exit;
}

// O TI, quem fez a entrega (ou o recebimento da foto) e o gestor do setor de algum rádio da entrega
if (!DeliveryPhoto::canViewPhoto($photo)) {
    Html::displayRightError();
}

DeliveryPhoto::send((string) $photo['filepath']);
