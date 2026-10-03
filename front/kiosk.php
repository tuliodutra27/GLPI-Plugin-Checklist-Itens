<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\ItemProvider;
use GlpiPlugin\Checklistitens\LaudoProvider;
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

// Laudos em aberto (plugin Laudo) dos equipamentos com o colaborador, para o alerta na devolução
if ($step === 'checkin') {
    $by_type = [];
    foreach ($my_usages as $u) {
        $by_type[$u['itemtype']][] = $u['items_id'];
    }
    $open = [];
    foreach ($by_type as $itemtype => $ids) {
        $open[$itemtype] = LaudoProvider::getOpenFor($itemtype, $ids);
    }
    foreach ($my_usages as $k => $u) {
        $my_usages[$k]['laudos'] = $open[$u['itemtype']][$u['items_id']] ?? [];
    }
}

$types = [];
foreach (Config::getEnabledTypes() as $itemtype) {
    $limit        = Config::getLimit($itemtype);
    $limitReached = $limit > 0 && Usage::countOpenForUser($users_id, $itemtype) >= $limit;

    $items = ($step === 'checkout' && !$limitReached) ? ItemProvider::getAvailable($itemtype, $sector) : [];

    // Laudos em aberto de todos os itens da lista, numa consulta só: selo e alerta na retirada
    if (count($items)) {
        $open = LaudoProvider::getOpenFor($itemtype, array_column($items, 'id'));
        foreach ($items as $k => $i) {
            $items[$k]['laudos'] = $open[(int) $i['id']] ?? [];
        }
    }

    $types[] = [
        'itemtype'      => $itemtype,
        'label'         => ItemProvider::getTypeLabel($itemtype),
        'short_label'   => ItemProvider::getShortTypeLabel($itemtype),
        'icon'          => ItemProvider::getTypeIcon($itemtype),
        'items'         => $items,
        'limit_reached' => $limitReached,
        'limit'         => $limit,
        'problems'      => ProblemType::getGroupedForType($itemtype),
        // Lista vazia: o TI vê o motivo (setor, estado, em uso, bloqueado)
        'diagnostic'    => ($step === 'checkout' && !$limitReached && !count($items) && Profile::isAdmin())
            ? ItemProvider::diagnose($itemtype, $sector)
            : null,
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
    'sector_names'  => implode(', ', Sector::getNames($sector)),
    'post_url'      => Ui::url('front/usage.form.php'),
    'home_url'      => Ui::url('front/home.php'),
    'checkin_url'   => Ui::url('front/kiosk.php?step=checkin'),
    'logout_url'    => Ui::logoutUrl(),
    'idle_seconds'  => Config::getIdleSeconds(),
]);
Ui::footer();
