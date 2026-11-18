<?php

use GlpiPlugin\Checklistitens\Db;
use GlpiPlugin\Checklistitens\DefectPhoto;
use GlpiPlugin\Checklistitens\Selfie;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

Session::checkLoginUser();

$id = (int) ($_GET['id'] ?? 0);

$photo = Db::row(DefectPhoto::getTable(), $id);
$usage = $photo ? Db::row(Usage::getTable(), (int) $photo['plugin_checklistitens_usages_id']) : null;

if (!$photo || !$usage) {
    http_response_code(404);
    exit;
}

// Mesmas regras da selfie: o próprio colaborador, o gestor do setor ou o TI
if (!Selfie::canView((int) $usage['users_id'], (int) $usage['groups_id'])) {
    Html::displayRightError();
}

DefectPhoto::folder()->send((string) $photo['filepath']);
