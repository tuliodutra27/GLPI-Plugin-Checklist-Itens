<?php

use GlpiPlugin\Checklistitens\Config;
use GlpiPlugin\Checklistitens\Delivery;
use GlpiPlugin\Checklistitens\DeliveryReceipt;
use GlpiPlugin\Checklistitens\DeliveryReport;
use GlpiPlugin\Checklistitens\ItemProvider;
use GlpiPlugin\Checklistitens\ProblemType;
use GlpiPlugin\Checklistitens\Ui;
use GlpiPlugin\Checklistitens\Vessel;

include('../../../inc/includes.php');

// Entrega de rádios para embarcação e recebimento de volta: só o gestor (ou o TI) que confere
if (!DeliveryReport::isFeatureAvailable() || !Delivery::canDeliver()) {
    Html::displayRightError();
}

$users_id = (int) Session::getLoginUserID();
$receive  = isset($_REQUEST['receive']);
$self_url = Ui::url('front/delivery.form.php');

// Recebimento: o gestor escolhe a entrega e o recebimento é aberto (precisa de conexão)
if (isset($_POST['start_receipt'])) {
    $hex    = bin2hex(random_bytes(16));
    $uuid   = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    $result = DeliveryReceipt::start((int) $_POST['start_receipt'], $uuid);
    if (!$result['ok']) {
        Session::addMessageAfterRedirect(htmlspecialchars($result['message']), false, ERROR);
    }
    Html::redirect($self_url . '?receive=1');
}

if ($receive) {
    $draft = DeliveryReceipt::getDraftFor($users_id);

    // Sem recebimento em andamento: primeiro escolhe a entrega
    if ($draft === null) {
        Ui::header(__('Receber da embarcação', 'checklistitens'), 'delivery');
        Ui::render('delivery_choose.html.twig', [
            'deliveries' => DeliveryReport::getOpenDeliveries(Delivery::getDeliveryGroups()),
            'post_url'   => $self_url . '?receive=1',
            'home_url'   => Ui::url('front/home.php'),
        ]);
        Ui::footer();
        exit;
    }

    $mode  = 'receipt';
    $state = DeliveryReceipt::getState($draft);
    $title = __('Receber da embarcação', 'checklistitens');
} else {
    $draft = Delivery::getDraftFor($users_id);
    $mode  = 'delivery';
    $state = $draft !== null ? Delivery::getState($draft) : null;
    $title = __('Entrega para embarcação', 'checklistitens');
}

$vessels = [];
foreach (Vessel::getActiveList() as $id => $name) {
    $vessels[] = ['id' => (int) $id, 'name' => $name];
}

// Dados que a tela usa no aparelho. O estado vem do servidor; o que ainda não foi enviado fica
// guardado no navegador e é juntado pelo JavaScript.
$data = [
    'mode'      => $mode,
    'user'      => $users_id,
    'token'     => Session::getNewCSRFToken(true),
    'ajaxUrl'   => Ui::url('ajax/delivery.php'),
    'homeUrl'   => Ui::url('front/home.php'),
    'vessels'   => $vessels,
    'available' => $mode === 'delivery' ? Delivery::getAvailableRadios() : [],
    // recebimento: problemas do catálogo para cada bateria e capa com defeito
    'accessoryProblems' => $mode === 'receipt' ? [
        'battery' => ProblemType::getGroupedForType(ProblemType::ACCESSORY_BATTERY),
        'cover'   => ProblemType::getGroupedForType(ProblemType::ACCESSORY_COVER),
    ] : [],
    'state'     => $state,
    'limits'    => [
        'max_items'  => Config::getDeliveryMaxItems(),
        'min_photos' => $mode === 'delivery' ? Config::getDeliveryMinPhotos() : Config::getReceiptMinPhotos(),
        'max_photos' => Config::getDeliveryMaxPhotos(),
    ],
];
// Os sinais de HTML saem como \u00XX: o JSON vai dentro de uma tag <script>
$json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

Ui::header($title, 'delivery');
Ui::script('js/offline.js');
Ui::script('js/delivery.js');
Ui::render('delivery_form.html.twig', [
    'mode'       => $mode,
    'title'      => $title,
    'data_json'  => $json !== false ? $json : '{}',
    'vessels'    => $vessels,
    'has_draft'  => $state !== null,
    'problems'   => ProblemType::getGroupedForType(ItemProvider::RADIO),
    'limits'     => $data['limits'],
    'home_url'   => Ui::url('front/home.php'),
    'list_url'   => Ui::url('front/delivery.php'),
    'vessel_url' => Vessel::getSearchURL(),
    'is_admin'   => GlpiPlugin\Checklistitens\Profile::canAdministrate(),
]);
Ui::footer();
