<?php

use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

if (!Usage::canView()) {
    Html::displayRightError();
}

Ui::header(Usage::getTypeName(Session::getPluralNumber()), 'usage');
Search::show(Usage::class);
Ui::footer();
