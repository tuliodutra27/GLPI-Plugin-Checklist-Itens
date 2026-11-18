<?php

use GlpiPlugin\Checklistitens\DefectPhoto;
use GlpiPlugin\Checklistitens\Selfie;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

Session::checkLoginUser();

global $DB;

$id = (int) ($_GET['id'] ?? 0);

$photo = $DB->request(['FROM' => DefectPhoto::getTable(), 'WHERE' => ['id' => $id]])->current();
$usage = $photo
    ? $DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['id' => (int) $photo['plugin_checklistitens_usages_id']]])->current()
    : null;

if (!$photo || !$usage) {
    http_response_code(404);
    exit;
}

// Mesmas regras da selfie: o próprio colaborador, o gestor do setor ou o TI
if (!Selfie::canView((int) $usage['users_id'], (int) $usage['groups_id'])) {
    Html::displayRightError();
}

DefectPhoto::folder()->send((string) $photo['filepath']);
