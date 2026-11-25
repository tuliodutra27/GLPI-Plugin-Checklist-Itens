<?php

use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

if (!Usage::canView()) {
    Html::displayRightError();
}

Ui::header(Usage::getTypeName(Session::getPluralNumber()), 'usage');
Ui::render('usage_filters.html.twig', ['filters' => Usage::getQuickFilters()]);
Search::show(Usage::class);
Ui::footer();
