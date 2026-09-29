<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\Confirmation;
use GlpiPlugin\Checklistitens\EquipmentRecord;
use GlpiPlugin\Checklistitens\ItemBlock;
use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Sector;
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

if (Profile::isManager() || Profile::isAdmin()) {
    $pending = 0;
    $scope   = Sector::forCurrentUser();
    if (count($scope)) {
        $pending = countElementsInTable(Usage::getTable(), ['groups_id' => $scope, 'status' => Usage::STATUS_IN_USE])
            + countElementsInTable(Usage::getTable(), ['groups_id' => $scope, 'status' => Usage::STATUS_RETURNED, 'plugin_checklistitens_confirmations_id_checkin' => 0])
            + countElementsInTable(ItemBlock::getTable(), ['groups_id' => $scope, 'plugin_checklistitens_confirmations_id' => 0]);
    }
    $cards[] = [
        'title'       => __('Conferência do setor', 'checklistitens'),
        'description' => __('Conferir os checklists do turno, abrir chamados e liberar a devolução.', 'checklistitens'),
        'url'         => Ui::url('front/manager.php'),
        'icon'        => Confirmation::getIcon(),
        'color'       => 'orange',
        'badge'       => $pending ? sprintf(_n('%d pendente', '%d pendentes', $pending, 'checklistitens'), $pending) : '',
        'badge_color' => 'orange',
    ];
    $cards[] = [
        'title'       => __('Equipamentos', 'checklistitens'),
        'description' => __('Situação atual e registro de uso completo de cada rádio e telefone.', 'checklistitens'),
        'url'         => Ui::url('front/equipment.php'),
        'icon'        => EquipmentRecord::getIcon(),
        'color'       => 'blue',
    ];
    $cards[] = [
        'title'       => Usage::getTypeName(2),
        'description' => __('Histórico de todas as retiradas e devoluções, com filtros e exportação.', 'checklistitens'),
        'url'         => Ui::url('front/usage.php'),
        'icon'        => Usage::getIcon(),
        'color'       => 'azure',
    ];
}

if (Profile::isAdmin()) {
    $blocked = countElementsInTable(ItemBlock::getTable(), ['lock_active' => 1]);
    $cards[] = [
        'title'       => __('Itens bloqueados', 'checklistitens'),
        'description' => __('Todos os setores. O item volta a circular quando o chamado é solucionado.', 'checklistitens'),
        'url'         => Ui::url('front/itemblock.php'),
        'icon'        => ItemBlock::getIcon(),
        'color'       => 'red',
        'badge'       => $blocked ? (string) $blocked : '',
        'badge_color' => 'red',
    ];
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
