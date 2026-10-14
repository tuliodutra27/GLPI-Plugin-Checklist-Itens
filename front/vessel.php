<?php

use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Vessel;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

Ui::header(Vessel::getTypeName(Session::getPluralNumber()), 'vessel');
Search::show(Vessel::class);
Ui::footer();
