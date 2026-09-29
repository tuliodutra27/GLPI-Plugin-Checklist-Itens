<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\Confirmation;
use GlpiPlugin\Checklistitens\EquipmentRecord;
use GlpiPlugin\Checklistitens\ItemProvider;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

if (!EquipmentRecord::canView()) {
    Html::displayRightError();
}

$filters = [
    'itemtype'  => (string) ($_GET['itemtype'] ?? ''),
    'groups_id' => (int) ($_GET['groups_id'] ?? 0),
    'situation' => (string) ($_GET['situation'] ?? ''),
    'q'         => (string) ($_GET['q'] ?? ''),
];

$types = [];
foreach (Config::getEnabledTypes() as $itemtype) {
    $types[$itemtype] = ItemProvider::getShortTypeLabel($itemtype);
}
$situations = [];
foreach (EquipmentRecord::getSituations() as $key => $situation) {
    $situations[$key] = $situation['label'];
}

Ui::header(__('Equipamentos', 'checklistitens'), 'equipment');
Ui::render('equipment_list.html.twig', [
    'items'      => EquipmentRecord::getList($filters),
    'filters'    => ['q' => \Glpi\Toolbox\Sanitizer::getVerbatimValue($filters['q'])] + $filters,
    'types'      => $types,
    'groups'     => Confirmation::getSelectableGroups(),
    'situations' => $situations,
    'page_url'   => Ui::url('front/equipment.php'),
    'home_url'   => Ui::url('front/home.php'),
]);
Ui::footer();
