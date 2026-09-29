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

$max_limit = SelfiePurge::getMaxLimitDate();
$limit     = (string) ($_GET['date_limit'] ?? $max_limit);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $limit) || $limit > $max_limit) {
    $limit = $max_limit;
}
$summary = SelfiePurge::getSummary($limit);

Ui::header(SelfiePurge::getTypeName(1), 'selfiepurge');
Ui::render('selfiepurge.html.twig', [
    'limit'          => $limit,
    'max_limit'      => $max_limit,
    'retention_days' => Config::getRetentionDays(),
    'count'          => $summary['count'],
    'size'           => Toolbox::getSize($summary['bytes']),
    'history'        => SelfiePurge::getHistory(),
    'can_purge'      => Profile::canAdministrate(),
    'page_url'       => Ui::url('front/selfiepurge.php'),
    'home_url'       => Ui::url('front/home.php'),
]);
Ui::footer();
