<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\EquipmentRecord;
use GlpiPlugin\Checklistitens\ItemProvider;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

$itemtype = (string) ($_GET['itemtype'] ?? '');
$items_id = (int) ($_GET['items_id'] ?? 0);

if (!in_array($itemtype, ItemProvider::getSupportedTypes(), true) || $items_id <= 0
    || !EquipmentRecord::canViewItem($itemtype, $items_id)) {
    Html::displayRightError();
}

$filters = [
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to'   => (string) ($_GET['date_to'] ?? ''),
    'users_id'  => (int) ($_GET['users_id'] ?? 0),
];

if (isset($_GET['csv'])) {
    EquipmentRecord::sendCsv($itemtype, $items_id, $filters);
}

$record = EquipmentRecord::getRecord($itemtype, $items_id, $filters);

Ui::header($record['label'], 'equipment');
Ui::render('equipment_record.html.twig', $record + [
    'embedded'     => false,
    'page_url'     => Ui::url('front/equipment.form.php'),
    'list_url'     => Ui::url('front/equipment.php'),
    'release_url'  => Ui::url('front/itemblock.php'),
]);
Ui::footer();
