<?php

use GlpiPlugin\Checklistitens\EquipmentRecord;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

// Link de um registro (busca, itens do chamado): abre o registro de uso do equipamento
if (isset($_GET['id']) && !isset($_POST['action'])) {
    $usage = new Usage();
    if ($usage->getFromDB((int) $_GET['id']) && EquipmentRecord::canViewItem((string) $usage->fields['itemtype'], (int) $usage->fields['items_id'])) {
        Html::redirect(EquipmentRecord::getUrl((string) $usage->fields['itemtype'], (int) $usage->fields['items_id']) . '#usage-' . (int) $usage->getID());
    }
    Html::displayRightError();
}

Session::checkRight(Profile::RIGHT_USAGE, CREATE);

$action   = (string) ($_POST['action'] ?? '');
$problems = (array) ($_POST['problems'] ?? []);
$is_ok    = isset($_POST['is_ok']) ? (int) $_POST['is_ok'] : -1;
// "É o problema do laudo": o item com laudo em aberto não é bloqueado
$known    = $is_ok === 0 && (string) ($_POST['known_issue'] ?? '') === '1';

if ($action === 'checkout') {
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $items_id = (int) ($_POST['items_id'] ?? 0);

    // Bloquear só com "Não"; levar só com "Sim" ou com o problema do laudo
    if ((isset($_POST['refuse']) && $is_ok !== 0) || (!isset($_POST['refuse']) && $is_ok !== 1 && !$known)) {
        Session::addMessageAfterRedirect(__('Responda se o equipamento está ok.', 'checklistitens'), false, ERROR);
        Html::redirect(Ui::url('front/kiosk.php?step=checkout&itemtype=' . urlencode($itemtype)));
    }

    if (isset($_POST['refuse'])) {
        $result = Usage::refuse($itemtype, $items_id, $problems);
        Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? WARNING : ERROR);
        Html::redirect(Ui::url('front/kiosk.php?step=checkout&itemtype=' . urlencode($itemtype)));
    }

    $result = Usage::checkout($itemtype, $items_id, $known, $problems);
    if (!empty($result['refused'])) {
        // O laudo foi concluído enquanto o colaborador respondia: virou recusa com bloqueio
        Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? WARNING : ERROR);
        Html::redirect(Ui::url('front/kiosk.php?step=checkout&itemtype=' . urlencode($itemtype)));
    }
    if ($result['ok']) {
        Html::redirect(Ui::url('front/done.php?action=checkout&id=' . $result['id']));
    }
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, ERROR);
    Html::redirect(Ui::url('front/kiosk.php?step=checkout&itemtype=' . urlencode($itemtype)));
}

if ($action === 'checkin') {
    $result = Usage::checkin((int) ($_POST['usage_id'] ?? 0), $is_ok, $problems, $known);
    if ($result['ok']) {
        if ($result['message'] !== '') {
            Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, WARNING);
        }
        Html::redirect(Ui::url('front/done.php?action=checkin&id=' . $result['id']));
    }
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, ERROR);
    Html::redirect(Ui::url('front/kiosk.php?step=checkin'));
}

Html::redirect(Ui::url('front/home.php'));
