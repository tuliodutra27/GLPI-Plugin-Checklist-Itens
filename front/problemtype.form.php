<?php

use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

$item = new ProblemType();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $item->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    // Problemas já usados ficam no histórico pela cópia do texto; o recomendado é desativar.
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    $item->redirectToList();
} else {
    Ui::header(ProblemType::getTypeName(1), 'problemtype');
    $item->display(['id' => (int) ($_GET['id'] ?? 0)]);
    Ui::footer();
}
