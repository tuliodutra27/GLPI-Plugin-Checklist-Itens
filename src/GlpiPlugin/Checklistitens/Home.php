<?php

namespace GlpiPlugin\Checklistitens;

use Session;

/**
 * Telas do plugin que cada perfil pode abrir. Da mesma lista saem os cartões da tela inicial do
 * plugin, os atalhos na página inicial do GLPI (interface simplificada) e o menu Ativos da
 * interface padrão (Menu).
 */
class Home
{
    public static function canSee(): bool
    {
        return Profile::canUse() || Profile::isManager() || Profile::isAdmin();
    }

    /**
     * Telas que o perfil pode abrir, na ordem dos cartões. "file" é a página do plugin; "class", a
     * busca do GLPI de um cadastro. "menu": a tela também fica no menu Ativos (as ações do
     * colaborador e a entrega abrem pela tela inicial). "shortcut": entra nos atalhos da página
     * inicial do GLPI. "search" e "add": a tela é uma lista, e o menu mostra a busca e, se o perfil
     * pode, o botão de acrescentar.
     *
     * @return array<string, array{title: string, description: string, file?: string, class?: class-string, icon: string, color: string, menu: bool, shortcut: bool}>
     */
    public static function pages(): array
    {
        $pages = [];

        if (Profile::canUse()) {
            $pages['checkout'] = [
                'title'       => __('Iniciar uso', 'checklistitens'),
                'description' => __('Retirar um rádio, celular ou tablet do setor.', 'checklistitens'),
                'file'        => 'front/kiosk.php?step=checkout',
                'icon'        => 'ti ti-login',
                'color'       => 'primary',
                'menu'        => false,
                'shortcut'    => true,
            ];
            $pages['checkin'] = [
                'title'       => __('Finalizar uso', 'checklistitens'),
                'description' => '',
                'file'        => 'front/kiosk.php?step=checkin',
                'icon'        => 'ti ti-logout',
                'color'       => 'green',
                'menu'        => false,
                'shortcut'    => true,
            ];
        }

        if (Profile::isManager() || Profile::isAdmin()) {
            $pages['manager'] = [
                'title'       => __('Conferência do setor', 'checklistitens'),
                'description' => __('Conferir os checklists do turno, abrir chamados e liberar a devolução.', 'checklistitens'),
                'file'        => 'front/manager.php',
                'icon'        => Confirmation::getIcon(),
                'color'       => 'orange',
                'menu'        => true,
                'shortcut'    => true,
            ];

            // Entrega de rádios para embarcação (só com o tipo Rádio habilitado)
            if (DeliveryReport::isFeatureAvailable()) {
                if (Delivery::canDeliver()) {
                    $pages['deliver'] = [
                        'title'       => __('Entrega para embarcação', 'checklistitens'),
                        'description' => __('Separar vários rádios e as baterias, fotografar e entregar a uma embarcação.', 'checklistitens'),
                        'file'        => 'front/delivery.form.php',
                        'icon'        => Delivery::getIcon(),
                        'color'       => 'primary',
                        'menu'        => false,
                        'shortcut'    => true,
                    ];
                    $pages['receive'] = [
                        'title'       => __('Receber da embarcação', 'checklistitens'),
                        'description' => __('Receber de volta os rádios entregues, com fotos e selfie.', 'checklistitens'),
                        'file'        => 'front/delivery.form.php?receive=1',
                        'icon'        => DeliveryReceipt::getIcon(),
                        'color'       => 'green',
                        'menu'        => false,
                        'shortcut'    => true,
                    ];
                }
                $pages['delivery'] = [
                    'title'       => Delivery::getTypeName(2),
                    'description' => __('O que foi entregue a cada embarcação, o que já voltou, fotos e observações.', 'checklistitens'),
                    'file'        => 'front/delivery.php',
                    'icon'        => 'ti ti-list-details',
                    'color'       => 'azure',
                    'menu'        => true,
                    'shortcut'    => false,
                    'search'      => true,
                    'add'         => Delivery::canDeliver() ? Ui::path('front/delivery.form.php') : null,
                ];
            }

            $pages['equipment'] = [
                'title'       => __('Equipamentos', 'checklistitens'),
                'description' => __('Situação atual e registro de uso completo de cada rádio, celular ou tablet.', 'checklistitens'),
                'file'        => 'front/equipment.php',
                'icon'        => EquipmentRecord::getIcon(),
                'color'       => 'blue',
                'menu'        => true,
                'shortcut'    => false,
            ];
            $pages['usage'] = [
                'title'       => Usage::getTypeName(2),
                'description' => __('Histórico de todas as retiradas e devoluções, com filtros e exportação.', 'checklistitens'),
                'file'        => 'front/usage.php',
                'icon'        => Usage::getIcon(),
                'color'       => 'azure',
                'menu'        => true,
                'shortcut'    => false,
                'search'      => true,
            ];
        }

        if (Profile::isAdmin()) {
            $pages['itemblock'] = [
                'title'       => __('Itens bloqueados', 'checklistitens'),
                'description' => __('Todos os setores. O item volta a circular quando o chamado é solucionado.', 'checklistitens'),
                'file'        => 'front/itemblock.php',
                'icon'        => ItemBlock::getIcon(),
                'color'       => 'red',
                'menu'        => true,
                'shortcut'    => false,
            ];
            $pages['selfiepurge'] = [
                'title'       => SelfiePurge::getTypeName(1),
                'description' => __('Apagar selfies e localizações antigas, depois do prazo de guarda.', 'checklistitens'),
                'file'        => 'front/selfiepurge.php',
                'icon'        => SelfiePurge::getIcon(),
                'color'       => 'secondary',
                'menu'        => true,
                'shortcut'    => false,
            ];
            $pages['problemtype'] = [
                'title'       => ProblemType::getTypeName(2),
                'description' => __('Lista de problemas que o colaborador marca, por tipo de equipamento.', 'checklistitens'),
                'class'       => ProblemType::class,
                'icon'        => ProblemType::getIcon(),
                'color'       => 'secondary',
                'menu'        => true,
                'shortcut'    => false,
                'search'      => true,
                'add'         => Profile::canAdministrate() ? ProblemType::getFormURL(false) : null,
            ];
            if (DeliveryReport::isFeatureAvailable()) {
                $pages['vessel'] = [
                    'title'       => Vessel::getTypeName(2),
                    'description' => __('Embarcações que recebem rádios. É a lista que o gestor vê na entrega.', 'checklistitens'),
                    'class'       => Vessel::class,
                    'icon'        => Vessel::getIcon(),
                    'color'       => 'secondary',
                    'menu'        => true,
                    'shortcut'    => false,
                    'search'      => true,
                    'add'         => Profile::canAdministrate() ? Vessel::getFormURL(false) : null,
                ];
            }
            $pages['config'] = [
                'title'       => Config::getTypeName(),
                'description' => __('Tipos, estados, limites, turnos, categorias e retenção de selfies.', 'checklistitens'),
                'file'        => 'front/config.form.php',
                'icon'        => 'ti ti-settings',
                'color'       => 'secondary',
                'menu'        => true,
                'shortcut'    => false,
            ];
        }

        return $pages;
    }

    /**
     * Endereço de uma tela. $full = false: sem o root_doc, no formato do menu do GLPI.
     */
    public static function url(array $page, bool $full = true): string
    {
        if (isset($page['class'])) {
            return $page['class']::getSearchURL($full);
        }

        return $full ? Ui::url($page['file']) : Ui::path($page['file']);
    }

    /**
     * Cartões da tela inicial, com o que muda a cada abertura (selos e avisos).
     *
     * @return array{cards: array<int, array<string, mixed>>, notices: array<int, array<string, mixed>>}
     */
    public static function build(): array
    {
        $cards   = [];
        $notices = [];
        foreach (self::pages() as $key => $page) {
            $cards[] = self::live($key, $notices) + ['url' => self::url($page)] + $page;
        }

        return ['cards' => $cards, 'notices' => $notices];
    }

    /**
     * Selo, descrição do momento e avisos de uma tela.
     *
     * @param array<int, array> $notices recebe os avisos da tela inicial
     * @return array<string, string>
     */
    private static function live(string $key, array &$notices): array
    {
        $users_id = (int) Session::getLoginUserID();

        switch ($key) {
            case 'checkin':
                $open       = Usage::getOpenForUser($users_id);
                $releasable = count(array_filter($open, static fn ($row) => (int) $row['status'] === Usage::STATUS_RELEASED));

                return [
                    'description' => count($open)
                        ? sprintf(_n('%d equipamento com você.', '%d equipamentos com você.', count($open), 'checklistitens'), count($open))
                        : __('Você não está com nenhum equipamento.', 'checklistitens'),
                    'badge'       => $releasable ? sprintf(_n('%d liberado', '%d liberados', $releasable, 'checklistitens'), $releasable) : '',
                    'badge_color' => 'green',
                ];

            case 'manager':
                $pending = Confirmation::countPending(Sector::forCurrentUser());

                return [
                    'badge'       => $pending ? sprintf(_n('%d pendente', '%d pendentes', $pending, 'checklistitens'), $pending) : '',
                    'badge_color' => 'orange',
                ];

            case 'deliver':
                $draft = Delivery::getDraftFor($users_id);
                if ($draft === null) {
                    return [];
                }
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

                return ['badge' => __('em separação', 'checklistitens'), 'badge_color' => 'yellow'];

            case 'receive':
                if (DeliveryReceipt::getDraftFor($users_id) !== null) {
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

                return [
                    'badge'       => $on_vessel ? sprintf(_n('%d entrega aberta', '%d entregas abertas', $on_vessel, 'checklistitens'), $on_vessel) : '',
                    'badge_color' => 'azure',
                ];

            case 'itemblock':
                $blocked = countElementsInTable(ItemBlock::getTable(), ['lock_active' => 1]);

                return ['badge' => $blocked ? (string) $blocked : '', 'badge_color' => 'red'];

            case 'selfiepurge':
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

                return [
                    'badge'       => ($old_selfies + $old_locations) ? (string) ($old_selfies + $old_locations) : '',
                    'badge_color' => 'azure',
                ];
        }

        return [];
    }

    /**
     * Bloco de atalhos na página inicial do GLPI (hook display_central). Só na interface
     * simplificada (na padrão o mesmo hook aparece no painel do TI, que já tem o menu Ativos), com a
     * opção ligada na configuração e para os perfis escolhidos nela.
     */
    public static function displayShortcuts(): void
    {
        if (!Ui::isHelpdesk() || !Config::isHomeShortcutsEnabled()) {
            return;
        }
        $profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!in_array($profile, Config::getHomeShortcutProfiles(), true) || !self::canSee()) {
            return;
        }

        $home  = self::build();
        $cards = array_values(array_filter($home['cards'], static fn ($card) => $card['shortcut']));
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
