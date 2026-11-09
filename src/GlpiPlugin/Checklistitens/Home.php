<?php

namespace GlpiPlugin\Checklistitens;

use Session;

/**
 * Funções do plugin que cada perfil pode usar, em cartões: a tela inicial do plugin mostra todos e
 * o bloco de atalhos na página inicial do GLPI (interface simplificada) mostra só os marcados com
 * "shortcut".
 */
class Home
{
    public static function canSee(): bool
    {
        return Profile::canUse() || Profile::isManager() || Profile::isAdmin();
    }

    /**
     * @return array{cards: array<int, array<string, mixed>>, notices: array<int, array<string, mixed>>}
     */
    public static function build(): array
    {
        $cards   = [];
        $notices = [];

        if (Profile::canUse()) {
            $open       = Usage::getOpenForUser((int) Session::getLoginUserID());
            $releasable = count(array_filter($open, static fn ($row) => (int) $row['status'] === Usage::STATUS_RELEASED));

            $cards[] = [
                'title'       => __('Iniciar uso', 'checklistitens'),
                'description' => __('Retirar um rádio, celular ou tablet do setor.', 'checklistitens'),
                'url'         => Ui::url('front/kiosk.php?step=checkout'),
                'icon'        => 'ti ti-login',
                'color'       => 'primary',
                'shortcut'    => true,
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
                'shortcut'    => true,
            ];
        }

        if (Profile::isManager() || Profile::isAdmin()) {
            $pending = 0;
            $scope   = Sector::forCurrentUser();
            if (count($scope)) {
                $pending = countElementsInTable(Usage::getTable(), ['groups_id' => $scope, 'status' => Usage::STATUS_IN_USE])
                    + countElementsInTable(Usage::getTable(), [
                        'groups_id' => $scope,
                        'status'    => Usage::STATUS_RETURNED,
                        'plugin_checklistitens_confirmations_id_checkin' => 0,
                        // rádio recebido de volta da embarcação não passa pela conferência do turno
                        'plugin_checklistitens_deliveries_id' => 0,
                    ])
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
                'shortcut'    => true,
            ];

            // Entrega de rádios para embarcação (só com o tipo Rádio habilitado)
            if (DeliveryReport::isFeatureAvailable()) {
                $users_id = (int) Session::getLoginUserID();
                if (Delivery::canDeliver()) {
                    $draft = Delivery::getDraftFor($users_id);
                    if ($draft !== null) {
                        $notices[] = [
                            'type'     => 'warning',
                            'text'     => sprintf(
                                __('Você tem uma entrega em separação para %1$s, iniciada em %2$s.', 'checklistitens'),
                                Ui::text($draft['vessel_name']),
                                Ui::datetime($draft['date_start'])
                            ),
                            'action'   => __('Continuar', 'checklistitens'),
                            'url'      => Ui::url('front/delivery.form.php'),
                            // o cancelamento passa pela tela da entrega, que também limpa o que está no aparelho
                            'action2'  => __('Cancelar separação', 'checklistitens'),
                            'url2'     => Ui::url('front/delivery.form.php?cancel=1'),
                            'shortcut' => true,
                        ];
                    }
                    $receipt = DeliveryReceipt::getDraftFor($users_id);
                    if ($receipt !== null) {
                        $notices[] = [
                            'type'     => 'warning',
                            'text'     => __('Você tem um recebimento de embarcação em andamento.', 'checklistitens'),
                            'action'   => __('Continuar', 'checklistitens'),
                            'url'      => Ui::url('front/delivery.form.php?receive=1'),
                            'action2'  => __('Cancelar recebimento', 'checklistitens'),
                            'url2'     => Ui::url('front/delivery.form.php?receive=1&cancel=1'),
                            'shortcut' => true,
                        ];
                    }

                    $on_vessel = DeliveryReport::countOpenDeliveries();
                    $cards[] = [
                        'title'       => __('Entrega para embarcação', 'checklistitens'),
                        'description' => __('Separar vários rádios e as baterias, fotografar e entregar a uma embarcação.', 'checklistitens'),
                        'url'         => Ui::url('front/delivery.form.php'),
                        'icon'        => Delivery::getIcon(),
                        'color'       => 'primary',
                        'badge'       => $draft !== null ? __('em separação', 'checklistitens') : '',
                        'badge_color' => 'yellow',
                        'shortcut'    => true,
                    ];
                    $cards[] = [
                        'title'       => __('Receber da embarcação', 'checklistitens'),
                        'description' => __('Receber de volta os rádios entregues, com fotos e selfie.', 'checklistitens'),
                        'url'         => Ui::url('front/delivery.form.php?receive=1'),
                        'icon'        => 'ti ti-arrow-back-up',
                        'color'       => 'green',
                        'badge'       => $on_vessel ? sprintf(_n('%d entrega aberta', '%d entregas abertas', $on_vessel, 'checklistitens'), $on_vessel) : '',
                        'badge_color' => 'azure',
                        'shortcut'    => true,
                    ];
                }
                $cards[] = [
                    'title'       => Delivery::getTypeName(2),
                    'description' => __('O que foi entregue a cada embarcação, o que já voltou, fotos e observações.', 'checklistitens'),
                    'url'         => Ui::url('front/delivery.php'),
                    'icon'        => 'ti ti-list-details',
                    'color'       => 'azure',
                ];
            }

            $cards[] = [
                'title'       => __('Equipamentos', 'checklistitens'),
                'description' => __('Situação atual e registro de uso completo de cada rádio, celular ou tablet.', 'checklistitens'),
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
            $old_selfies   = count(SelfiePurge::getEligible(SelfiePurge::getMaxLimitDate()));
            $old_locations = count(SelfiePurge::getEligibleLocations(SelfiePurge::getLocationMaxLimitDate()));
            if ($old_selfies > 0) {
                $notices[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        __('Há %1$d selfie(s) com mais de %2$d dias. Elas podem ser limpas em "Limpeza de selfies e localizações".', 'checklistitens'),
                        $old_selfies,
                        Config::getRetentionDays()
                    ),
                ];
            }
            if ($old_locations > 0) {
                $notices[] = [
                    'type' => 'info',
                    'text' => sprintf(
                        __('Há %1$d localização(ões) com mais de %2$d dias. Elas podem ser limpas em "Limpeza de selfies e localizações".', 'checklistitens'),
                        $old_locations,
                        Config::getLocationRetentionDays()
                    ),
                ];
            }
            $cards[] = [
                'title'       => SelfiePurge::getTypeName(1),
                'description' => __('Apagar selfies e localizações antigas, depois do prazo de guarda.', 'checklistitens'),
                'url'         => Ui::url('front/selfiepurge.php'),
                'icon'        => SelfiePurge::getIcon(),
                'color'       => 'secondary',
                'badge'       => ($old_selfies + $old_locations) ? (string) ($old_selfies + $old_locations) : '',
                'badge_color' => 'azure',
            ];
            $cards[] = [
                'title'       => ProblemType::getTypeName(2),
                'description' => __('Lista de problemas que o colaborador marca, por tipo de equipamento.', 'checklistitens'),
                'url'         => ProblemType::getSearchURL(),
                'icon'        => ProblemType::getIcon(),
                'color'       => 'secondary',
            ];
            if (DeliveryReport::isFeatureAvailable()) {
                $cards[] = [
                    'title'       => Vessel::getTypeName(2),
                    'description' => __('Embarcações que recebem rádios. É a lista que o gestor vê na entrega.', 'checklistitens'),
                    'url'         => Vessel::getSearchURL(),
                    'icon'        => Vessel::getIcon(),
                    'color'       => 'secondary',
                ];
            }
            $cards[] = [
                'title'       => Config::getTypeName(),
                'description' => __('Tipos, estados, limites, turnos, categorias e retenção de selfies.', 'checklistitens'),
                'url'         => Ui::url('front/config.form.php'),
                'icon'        => 'ti ti-settings',
                'color'       => 'secondary',
            ];
        }

        return ['cards' => $cards, 'notices' => $notices];
    }

    /**
     * Bloco de atalhos na página inicial do GLPI (hook display_central). Só na interface
     * simplificada: na padrão o mesmo hook aparece no painel do TI, que já tem o menu Ativos.
     */
    public static function displayShortcuts(): void
    {
        if (!Ui::isHelpdesk() || !self::canSee()) {
            return;
        }

        $home  = self::build();
        $cards = array_values(array_filter($home['cards'], static fn ($card) => !empty($card['shortcut'])));
        if (!count($cards)) {
            return;
        }

        // A página inicial monta o hook dentro de uma tabela: o bloco vai numa linha dela
        echo '<tr><td>';
        Ui::render('home_shortcuts.html.twig', [
            'cards'    => $cards,
            'notices'  => array_values(array_filter($home['notices'], static fn ($notice) => !empty($notice['shortcut']))),
            'home_url' => Ui::url('front/home.php'),
            'has_more' => count($cards) < count($home['cards']),
        ]);
        echo '</td></tr>';
    }
}
