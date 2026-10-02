<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\SelfiePurge;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

if (isset($_POST['purge'])) {
    Session::checkRight(Profile::RIGHT_CONFIG, UPDATE);
    $result = SelfiePurge::purge((string) ($_POST['date_limit'] ?? ''), (int) Session::getLoginUserID());
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? INFO : ERROR);
    Html::back();
}

if (isset($_POST['purge_locations'])) {
    Session::checkRight(Profile::RIGHT_CONFIG, UPDATE);
    $result = SelfiePurge::purgeLocations((string) ($_POST['location_limit'] ?? ''), (int) Session::getLoginUserID());
    Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, $result['ok'] ? INFO : ERROR);
    Html::back();
}

/** Data do filtro: só AAAA-MM-DD e nunca dentro do prazo de retenção. */
$pick_date = static function (string $param, string $max): string {
    $value = (string) ($_GET[$param] ?? $max);

    return (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $value > $max) ? $max : $value;
};

$max_limit          = SelfiePurge::getMaxLimitDate();
$limit              = $pick_date('date_limit', $max_limit);
$summary            = SelfiePurge::getSummary($limit);
$location_max_limit = SelfiePurge::getLocationMaxLimitDate();
$location_limit     = $pick_date('location_limit', $location_max_limit);

Ui::header(SelfiePurge::getTypeName(1), 'selfiepurge');
Ui::render('selfiepurge.html.twig', [
    'limit'                   => $limit,
    'max_limit'               => $max_limit,
    'retention_days'          => Config::getRetentionDays(),
    'count'                   => $summary['count'],
    'size'                    => Toolbox::getSize($summary['bytes']),
    'location_limit'          => $location_limit,
    'location_max_limit'      => $location_max_limit,
    'location_retention_days' => Config::getLocationRetentionDays(),
    'location_count'          => count(SelfiePurge::getEligibleLocations($location_limit)),
    'history'                 => SelfiePurge::getHistory(),
    'can_purge'               => Profile::canAdministrate(),
    'page_url'                => Ui::url('front/selfiepurge.php'),
    'home_url'                => Ui::url('front/home.php'),
]);
Ui::footer();
