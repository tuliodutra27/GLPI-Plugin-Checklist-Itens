<?php

use GlpiPlugin\Checklistitens\Confirmation;
use GlpiPlugin\Checklistitens\ItemBlock;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Sector;
use GlpiPlugin\Checklistitens\TicketFactory;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

if (!Profile::isManager() && !Profile::isAdmin()) {
    Html::displayRightError();
}

$groups    = Confirmation::getSelectableGroups();
$groups_id = (int) ($_REQUEST['groups_id'] ?? 0);
if (!isset($groups[$groups_id])) {
    $groups_id = (int) (array_key_first($groups) ?? 0);
}
$scope    = $groups_id > 0 ? Sector::expand([$groups_id]) : [];
$back_url = Ui::url('front/manager.php?groups_id=' . $groups_id);

if (isset($_POST['open_ticket'])) {
    Session::checkRight(Profile::RIGHT_MANAGER, UPDATE);
    $block = new ItemBlock();
    if ($block->getFromDB((int) $_POST['open_ticket']) && in_array((int) $block->fields['groups_id'], $scope, true)) {
        $tickets_id = TicketFactory::openForBlock($block);
        if ($tickets_id) {
            Session::addMessageAfterRedirect(sprintf(__('Chamado %d aberto.', 'checklistitens'), $tickets_id));
        } else {
            Session::addMessageAfterRedirect(__('Não foi possível abrir o chamado.', 'checklistitens'), false, ERROR);
        }
    }
    Html::redirect($back_url);
}

if (isset($_POST['confirm'])) {
    Session::checkRight(Profile::RIGHT_MANAGER, UPDATE);
    $result = Confirmation::confirm(
        $groups_id,
        $scope,
        (array) ($_POST['block_ids'] ?? []),
        (array) ($_POST['checkout_ids'] ?? []),
        (array) ($_POST['checkin_ids'] ?? [])
    );
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? INFO : ERROR);
    Html::redirect($back_url);
}

$data = Confirmation::getPanelData($groups_id, $scope);

Ui::header(__('Conferência do setor', 'checklistitens'), 'manager');
Ui::render('manager.html.twig', $data + [
    'groups'      => $groups,
    'groups_id'   => $groups_id,
    'can_confirm' => Profile::canConfirm(),
    'post_url'    => Ui::url('front/manager.php'),
    'home_url'    => Ui::url('front/home.php'),
]);
Ui::footer();
