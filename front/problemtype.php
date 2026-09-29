<?php

use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_CONFIG, READ);

Ui::header(ProblemType::getTypeName(Session::getPluralNumber()), 'problemtype');
Search::show(ProblemType::class);
Ui::footer();
