<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

if (!Profile::canUse() && !Profile::isManager() && !Profile::isAdmin()) {
    Html::displayRightError();
}

$cards = [];

if (Profile::isAdmin()) {
    $cards[] = [
        'title'       => ProblemType::getTypeName(2),
        'description' => __('Lista de problemas que o colaborador marca, por tipo de equipamento.', 'checklistitens'),
        'url'         => ProblemType::getSearchURL(),
        'icon'        => ProblemType::getIcon(),
        'color'       => 'secondary',
    ];
    $cards[] = [
        'title'       => Config::getTypeName(),
        'description' => __('Tipos, estados, limites, turnos, categorias e retenção de selfies.', 'checklistitens'),
        'url'         => Ui::url('front/config.form.php'),
        'icon'        => 'ti ti-settings',
        'color'       => 'secondary',
    ];
}

Ui::header(__('Checklist uso de equipamentos', 'checklistitens'), 'home');
Ui::render('home.html.twig', [
    'cards' => $cards,
]);
Ui::footer();
