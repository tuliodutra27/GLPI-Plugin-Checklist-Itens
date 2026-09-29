<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

if (!Profile::canUse() && !Profile::isManager() && !Profile::isAdmin()) {
    Html::displayRightError();
}

$cards = [];

if (Profile::canUse()) {
    $open      = Usage::getOpenForUser((int) Session::getLoginUserID());
    $releasable = count(array_filter($open, static fn ($row) => (int) $row['status'] === Usage::STATUS_RELEASED));

    // Quem só usa (ex.: operador) e não está com nenhum equipamento vai direto para a retirada.
    if (!count($open) && !Profile::isManager() && !Profile::isAdmin()) {
        Html::redirect(Ui::url('front/kiosk.php?step=checkout'));
    }

    $cards[] = [
        'title'       => __('Iniciar uso', 'checklistitens'),
        'description' => __('Retirar um rádio ou telefone do setor.', 'checklistitens'),
        'url'         => Ui::url('front/kiosk.php?step=checkout'),
        'icon'        => 'ti ti-login',
        'color'       => 'primary',
    ];
    $cards[] = [
        'title'       => __('Finalizar uso', 'checklistitens'),
        'description' => count($open)
            ? sprintf(_n('%d equipamento com você.', '%d equipamentos com você.', count($open), 'checklistitens'), count($open))
            : __('Você não está com nenhum equipamento.', 'checklistitens'),
        'url'         => Ui::url('front/kiosk.php?step=checkin'),
        'icon'        => 'ti ti-logout',
        'color'       => 'green',
        'badge'       => $releasable ? sprintf(_n('%d liberado', '%d liberados', $releasable, 'checklistitens'), $releasable) : '',
        'badge_color' => 'green',
    ];
}

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
