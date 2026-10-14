<?php

use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Vessel;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

$item = new Vessel();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $item->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    // Entregas já feitas guardam a cópia do nome da embarcação; o recomendado é desativar.
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    $item->redirectToList();
} else {
    Ui::header(Vessel::getTypeName(1), 'vessel');
    $item->display(['id' => (int) ($_GET['id'] ?? 0)]);
    Ui::footer();
}
