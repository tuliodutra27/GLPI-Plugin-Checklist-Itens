<?php

use GlpiPlugin\Checklistitens\Selfie;

include('../../../inc/includes.php');

Session::checkLoginUser();

$found = Selfie::find((string) ($_GET['kind'] ?? ''), (int) ($_GET['id'] ?? 0));
if ($found === null) {
    http_response_code(404);
    exit;
}

if (!Selfie::canView((int) $found['row']['users_id'], (int) $found['row']['groups_id'])) {
    Html::displayRightError();
}

Selfie::folder()->send($found['path']);
