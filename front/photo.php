<?php

use GlpiPlugin\Checklistitens\DefectPhoto;
use GlpiPlugin\Checklistitens\Install;
use GlpiPlugin\Checklistitens\Selfie;

include('../../../inc/includes.php');

Session::checkLoginUser();

global $DB;

$id = (int) ($_GET['id'] ?? 0);

$photo = $DB->request(['FROM' => Install::TABLE_DEFECTPHOTOS, 'WHERE' => ['id' => $id]])->current();
$usage = $photo
    ? $DB->request(['FROM' => Install::TABLE_USAGES, 'WHERE' => ['id' => (int) $photo['plugin_checklistitens_usages_id']]])->current()
    : null;

if (!$photo || !$usage) {
    http_response_code(404);
    exit;
}

// Mesmas regras da selfie: o próprio colaborador, o gestor do setor ou o TI
if (!Selfie::canView((int) $usage['users_id'], (int) $usage['groups_id'])) {
    Html::displayRightError();
}

DefectPhoto::send((string) $photo['filepath']);
