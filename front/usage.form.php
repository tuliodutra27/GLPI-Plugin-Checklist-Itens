<?php

use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_USAGE, CREATE);

$action   = (string) ($_POST['action'] ?? '');
$problems = (array) ($_POST['problems'] ?? []);

if ($action === 'checkout') {
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $items_id = (int) ($_POST['items_id'] ?? 0);

    if (isset($_POST['refuse'])) {
        $result = Usage::refuse($itemtype, $items_id, $problems);
        Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? WARNING : ERROR);
        Html::redirect(Ui::url('front/kiosk.php?step=checkout&itemtype=' . urlencode($itemtype)));
    }

    $result = Usage::checkout($itemtype, $items_id);
    if ($result['ok']) {
        Html::redirect(Ui::url('front/done.php?action=checkout&id=' . $result['id']));
    }
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, ERROR);
    Html::redirect(Ui::url('front/kiosk.php?step=checkout&itemtype=' . urlencode($itemtype)));
}

if ($action === 'checkin') {
    $is_ok  = isset($_POST['is_ok']) ? (int) $_POST['is_ok'] : -1;
    $result = Usage::checkin((int) ($_POST['usage_id'] ?? 0), $is_ok, $problems);
    if ($result['ok']) {
        Html::redirect(Ui::url('front/done.php?action=checkin&id=' . $result['id']));
    }
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, ERROR);
    Html::redirect(Ui::url('front/kiosk.php?step=checkin'));
}

Html::redirect(Ui::url('front/home.php'));
