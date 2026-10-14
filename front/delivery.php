<?php

use GlpiPlugin\Checklistitens\Delivery;
use GlpiPlugin\Checklistitens\DeliveryReport;
use GlpiPlugin\Checklistitens\Profile;
use GlpiPlugin\Checklistitens\Ui;

include('../../../inc/includes.php');

// Entregas de rádios para embarcação: lista, detalhe, CSV e a tela final depois de concluir
if (!DeliveryReport::canView()) {
    Html::displayRightError();
}

$id       = (int) ($_GET['id'] ?? 0);
$list_url = Ui::url('front/delivery.php');
$form_url = Ui::url('front/delivery.form.php');

// O TI pode cancelar a separação aberta de qualquer gestor: os rádios são liberados na hora.
// (Quem a iniciou cancela pela própria tela da entrega, que também limpa o que está no aparelho.)
if (isset($_POST['cancel_delivery'])) {
    if (!Profile::canAdministrate()) {
        Html::displayRightError();
    }
    $cancel_id = (int) $_POST['cancel_delivery'];
    $result    = Delivery::cancel($cancel_id);
    $message   = $result['message'] !== '' ? $result['message'] : __('Separação cancelada.', 'checklistitens');
    Session::addMessageAfterRedirect(htmlspecialchars($message), false, $result['ok'] ? INFO : ERROR);
    Html::redirect($list_url . '?id=' . $cancel_id);
}

if ($id > 0) {
    if (isset($_GET['csv'])) {
        DeliveryReport::sendCsv($id);
    }

    // getDetail já confere se o usuário pode ver esta entrega
    $detail = DeliveryReport::getDetail($id);
    if ($detail === null) {
        Html::displayRightError();
    }

    // Tela final da entrega ou do recebimento: resumo e logoff, como na retirada comum
    $done = (string) ($_GET['done'] ?? '');
    if ($done === 'delivery' || $done === 'receipt') {
        $counts  = $detail['counts'];
        $summary = $done === 'receipt'
            ? sprintf(__('%1$d rádio(s) devolvido(s); %2$d ainda com a embarcação.', 'checklistitens'), $counts['returned'], $counts['on_vessel'])
            : sprintf(__('%d rádio(s) com a embarcação.', 'checklistitens'), $counts['on_vessel']);
        if ($counts['blocked'] > 0) {
            $summary .= ' ' . sprintf(__('%d saíram da entrega por problema e ficaram bloqueados.', 'checklistitens'), $counts['blocked']);
        }

        Ui::header(__('Registro salvo', 'checklistitens'), 'delivery');
        Ui::render('delivery_done.html.twig', [
            'kind'         => $done,
            'vessel'       => $detail['vessel'],
            'summary'      => $summary,
            'logout_url'   => Ui::logoutUrl(),
            'logout_after' => 5,
            'detail_url'   => DeliveryReport::getUrl($id),
        ]);
        Ui::footer();
        exit;
    }

    Ui::header($detail['vessel'], 'delivery');
    Ui::render('delivery_detail.html.twig', $detail + [
        'form_url'   => $form_url,
        'csv_url'    => Ui::url('front/delivery.php?id=' . $id . '&csv=1'),
        'list_url'   => $list_url,
        'can_cancel' => $detail['status'] === Delivery::STATUS_DRAFT && Profile::canAdministrate(),
    ]);
    Ui::footer();
    exit;
}

$filters = [
    'status'     => (int) ($_GET['status'] ?? 0),
    'vessels_id' => (int) ($_GET['vessels_id'] ?? 0),
    'groups_id'  => (int) ($_GET['groups_id'] ?? 0),
    'date_from'  => (string) ($_GET['date_from'] ?? ''),
    'date_to'    => (string) ($_GET['date_to'] ?? ''),
];

Ui::header(Delivery::getTypeName(Session::getPluralNumber()), 'delivery');
Ui::render('delivery_list.html.twig', [
    'page_url'    => $list_url,
    'statuses'    => Delivery::getStatusLabels(),
    'vessels'     => DeliveryReport::getVesselOptions(),
    'sectors'     => DeliveryReport::getSectorOptions(),
    'filters'     => $filters,
    'can_deliver' => Delivery::canDeliver(),
    'form_url'    => $form_url,
    'deliveries'  => DeliveryReport::getList($filters),
    'home_url'    => Ui::url('front/home.php'),
]);
Ui::footer();
