<?php

use GlpiPlugin\Checklistitens\ItemBlock;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

if (isset($_POST['release'])) {
    Session::checkRight(Profile::RIGHT_CONFIG, UPDATE);
    $block = new ItemBlock();
    if ($block->getFromDB((int) $_POST['release']) && $block->release(ItemBlock::RELEASE_MANUAL, (int) Session::getLoginUserID())) {
        Session::addMessageAfterRedirect(__('Item liberado para retirada.', 'checklistitens'));
    } else {
        Session::addMessageAfterRedirect(__('Este bloqueio já estava liberado.', 'checklistitens'), false, WARNING);
    }
    Html::back();
}

global $DB;

$active = $released = [];
foreach ($DB->request(['FROM' => ItemBlock::getTable(), 'WHERE' => ['lock_active' => 1], 'ORDER' => 'date_block']) as $row) {
    $active[] = ItemBlock::present($row);
}
foreach ($DB->request(['FROM' => ItemBlock::getTable(), 'WHERE' => ['status' => ItemBlock::STATUS_RELEASED], 'ORDER' => 'date_release DESC', 'LIMIT' => 30]) as $row) {
    $released[] = ItemBlock::present($row);
}

Ui::header(ItemBlock::getTypeName(Session::getPluralNumber()), 'itemblock');
Ui::render('itemblock.html.twig', [
    'active'      => $active,
    'released'    => $released,
    'can_release' => Profile::canAdministrate(),
    'post_url'    => Ui::url('front/itemblock.php'),
    'home_url'    => Ui::url('front/home.php'),
]);
Ui::footer();
