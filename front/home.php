<?php

use GlpiPlugin\Checklistitens\Home;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Usage;

include('../../../inc/includes.php');

if (!Home::canSee()) {
    Html::displayRightError();
}

// Quem só usa (ex.: operador) e não está com nenhum equipamento vai direto para a retirada.
if (
    Profile::canUse() && !Profile::isManager() && !Profile::isAdmin()
    && !count(Usage::getOpenForUser((int) Session::getLoginUserID()))
) {
    Html::redirect(Ui::url('front/kiosk.php?step=checkout'));
}

Ui::header(__('Checklist uso de equipamentos', 'checklistitens'), 'home');
Ui::render('home.html.twig', Home::build());
Ui::footer();
