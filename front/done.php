<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\ItemProvider;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_USAGE, CREATE);

$action = ($_GET['action'] ?? '') === 'checkin' ? 'checkin' : 'checkout';
$usage  = new Usage();

if (!$usage->getFromDB((int) ($_GET['id'] ?? 0)) || (int) $usage->fields['users_id'] !== (int) Session::getLoginUserID()) {
    Html::redirect(Ui::url('front/home.php'));
}

$item    = ItemProvider::describe((string) $usage->fields['itemtype'], (int) $usage->fields['items_id']);
$blocked = $action === 'checkin' && (int) $usage->fields['checkin_is_ok'] === 0;

Ui::header(__('Registro salvo', 'checklistitens'), 'home');
Ui::render('done.html.twig', [
    'action'       => $action,
    'item'         => $item,
    'blocked'      => $blocked,
    'logout_url'   => Ui::logoutUrl(),
    'checkout_url' => Ui::url('front/kiosk.php?step=checkout'),
    'logout_after' => 5,
    'idle_seconds' => Config::getIdleSeconds(),
]);
Ui::footer();
