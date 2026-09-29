<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\ItemProvider;
use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Sector;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_USAGE, CREATE);

$step     = ($_GET['step'] ?? 'checkout') === 'checkin' ? 'checkin' : 'checkout';
$users_id = (int) Session::getLoginUserID();
$sector   = Sector::forCurrentUser();

$my_usages = array_map([Usage::class, 'present'], Usage::getOpenForUser($users_id));

$types = [];
foreach (Config::getEnabledTypes() as $itemtype) {
    $limit        = Config::getLimit($itemtype);
    $limitReached = $limit > 0 && Usage::countOpenForUser($users_id, $itemtype) >= $limit;

    $types[] = [
        'itemtype'      => $itemtype,
        'label'         => ItemProvider::getTypeLabel($itemtype),
        'short_label'   => ItemProvider::getShortTypeLabel($itemtype),
        'icon'          => ItemProvider::getTypeIcon($itemtype),
        'items'         => ($step === 'checkout' && !$limitReached) ? ItemProvider::getAvailable($itemtype, $sector) : [],
        'limit_reached' => $limitReached,
        'limit'         => $limit,
        'problems'      => ProblemType::getGroupedForType($itemtype),
    ];
}

$selected_type = (string) ($_GET['itemtype'] ?? '');
if (count($types) === 1) {
    $selected_type = $types[0]['itemtype'];
}

Ui::header(
    $step === 'checkout' ? __('Iniciar uso de equipamento', 'checklistitens') : __('Finalizar uso de equipamento', 'checklistitens'),
    'home'
);
Ui::render('kiosk.html.twig', [
    'step'          => $step,
    'types'         => $types,
    'selected_type' => $selected_type,
    'my_usages'     => $my_usages,
    'has_sector'    => count($sector) > 0,
    'post_url'      => Ui::url('front/usage.form.php'),
    'home_url'      => Ui::url('front/home.php'),
    'checkin_url'   => Ui::url('front/kiosk.php?step=checkin'),
    'logout_url'    => Ui::logoutUrl(),
    'idle_seconds'  => Config::getIdleSeconds(),
]);
Ui::footer();
