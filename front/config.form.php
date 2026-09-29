<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

if (isset($_POST['update'])) {
    Session::checkRight(Profile::RIGHT_CONFIG, UPDATE);
    Config::saveFromForm($_POST);
    Html::back();
}

Ui::header(Config::getTypeName(), 'config');
Config::showForm();
Ui::footer();
