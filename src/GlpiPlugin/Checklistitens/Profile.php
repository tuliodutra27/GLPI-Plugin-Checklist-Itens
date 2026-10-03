<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;
use Glpi\Toolbox\Sanitizer;
use Html;
use ITILFollowup;
use KnowbaseItem;
use Profile as GlpiProfile;
use ProfileRight;
use Session;
use Ticket;
use TicketTask;
use Toolbox;

/**
 * Direitos do plugin, exibidos numa aba do Perfil do GLPI.
 *
 * - usage:   retirada e devolução (todos os perfis);
 * - manager: conferência do setor e registro de uso dos equipamentos do setor (gestor operacional);
 * - config:  catálogo, configuração, todos os setores, liberação manual e limpeza de selfies (TI).
 *
 * A instalação também cria os perfis "Operador" e "Gestor Operacional" quando ainda não existem
 * (perfis existentes nunca são alterados, nem apagados na desinstalação).
 */
class Profile extends GlpiProfile
{
    public const RIGHT_USAGE   = 'plugin_checklistitens_usage';
    public const RIGHT_MANAGER = 'plugin_checklistitens_manager';
    public const RIGHT_CONFIG  = 'plugin_checklistitens_config';

    /** Chaves do array devolvido por createDefaultProfiles(). */
    public const DEFAULT_OPERATOR = 'operator';
    public const DEFAULT_MANAGER  = 'manager';

    /** Nomes usados ao criar os perfis padrão. */
    public const OPERATOR_PROFILE_NAME = 'Operador';
    public const MANAGER_PROFILE_LABEL = 'Gestor Operacional';

    /** Nome do perfil que recebe o direito de gestor na instalação (comparação sem maiúsculas). */
    public const MANAGER_PROFILE_NAME = 'gestor operacional';

    /** Campos do Self-Service copiados para o Gestor Operacional (além de interface e direitos). */
    private const SELF_SERVICE_FIELDS = [
        'helpdesk_hardware',
        'helpdesk_item_type',
        'ticket_status',
        'problem_status',
        'change_status',
        'create_ticket_on_login',
        'tickettemplates_id',
        'changetemplates_id',
        'problemtemplates_id',
        'managed_domainrecordtypes',
    ];

    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Checklist uso de equipamentos', 'checklistitens');
    }

    /** @return string[] */
    public static function getRightNames(): array
    {
        return [self::RIGHT_USAGE, self::RIGHT_MANAGER, self::RIGHT_CONFIG];
    }

    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => self::class,
                'label'    => __('Retirada e devolução de equipamentos', 'checklistitens'),
                'field'    => self::RIGHT_USAGE,
                'rights'   => [CREATE => __('Usar', 'checklistitens')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Conferência do setor (gestor)', 'checklistitens'),
                'field'    => self::RIGHT_MANAGER,
                'rights'   => [
                    READ   => __('Ver', 'checklistitens'),
                    UPDATE => __('Conferir e abrir chamado', 'checklistitens'),
                ],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Administração (TI)', 'checklistitens'),
                'field'    => self::RIGHT_CONFIG,
                'rights'   => [
                    READ   => __('Ver todos os setores', 'checklistitens'),
                    UPDATE => __('Configurar, liberar itens e limpar selfies', 'checklistitens'),
                ],
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('id')) {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile) {
            self::showForProfile((int) $item->getField('id'));
        }

        return true;
    }

    public static function showForProfile(int $profiles_id): void
    {
        $profile = new GlpiProfile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = Session::haveRight('profile', UPDATE);

        echo "<div class='firstbloc'>";
        // O formulário vai para o controlador de Perfil do core, que grava os direitos postados.
        echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(GlpiProfile::class) . "'>";

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
        }

        Html::closeForm();
        echo "</div>";
    }

    /**
     * Cria os perfis "Operador" e "Gestor Operacional" se ainda não houver perfil com esses nomes
     * (comparação sem maiúsculas). Perfis existentes nunca são alterados.
     *
     * - Operador: interface simplificada, só a FAQ;
     * - Gestor Operacional: cópia do Self-Service (interface e direitos), sem os direitos do plugin.
     *
     * Os direitos do plugin são dados depois, por installRights().
     *
     * @return array<string, int> id do perfil criado agora, ou 0 se já existia (ou se falhou)
     */
    public static function createDefaultProfiles(): array
    {
        $created = [self::DEFAULT_OPERATOR => 0, self::DEFAULT_MANAGER => 0];

        $names = [];
        foreach ((new GlpiProfile())->find() as $data) {
            $names[] = mb_strtolower(trim((string) $data['name']));
        }

        if (!in_array(mb_strtolower(self::OPERATOR_PROFILE_NAME), $names, true)) {
            $created[self::DEFAULT_OPERATOR] = self::addProfile(
                ['name' => self::OPERATOR_PROFILE_NAME, 'interface' => 'helpdesk'],
                ['knowbase' => KnowbaseItem::READFAQ]
            );
        }

        if (!in_array(self::MANAGER_PROFILE_NAME, $names, true)) {
            [$fields, $rights] = self::getSelfServiceTemplate();
            $created[self::DEFAULT_MANAGER] = self::addProfile(['name' => self::MANAGER_PROFILE_LABEL] + $fields, $rights);
        }

        return $created;
    }

    /**
     * Cria o perfil e grava os direitos depois do add(): o post_addItem() do core descarta os
     * direitos recebidos no add() e grava todos vazios.
     *
     * @param array<string, mixed> $input
     * @param array<string, int>   $rights
     */
    private static function addProfile(array $input, array $rights): int
    {
        $input = array_merge($input, [
            // Com 1, o core tira o "padrão" de todos os outros perfis
            'is_default' => 0,
            'comment'    => __('Criado pelo plugin Checklist uso de equipamentos.', 'checklistitens'),
        ]);

        $id = (int) (new GlpiProfile())->add(Sanitizer::sanitize($input));
        if ($id <= 0) {
            Toolbox::logError(sprintf('checklistitens: erro ao criar o perfil "%s"', $input['name']));
            return 0;
        }

        ProfileRight::updateProfileRights($id, $rights);

        return $id;
    }

    /**
     * Campos e direitos do Self-Service para o Gestor Operacional, sem os direitos do próprio
     * plugin. Se não houver Self-Service, um mínimo equivalente ao padrão do GLPI.
     *
     * @return array{0: array<string, mixed>, 1: array<string, int>}
     */
    private static function getSelfServiceTemplate(): array
    {
        $source = self::findSelfService();
        if ($source === null) {
            return [
                ['interface' => 'helpdesk'],
                [
                    'ticket'          => Ticket::READMY | CREATE,
                    'followup'        => ITILFollowup::SEEPUBLIC | ITILFollowup::ADDMYTICKET,
                    'task'            => TicketTask::SEEPUBLIC,
                    'password_update' => READ,
                    'personalization' => READ | UPDATE,
                    'knowbase'        => KnowbaseItem::READFAQ,
                ],
            ];
        }

        $fields = ['interface' => 'helpdesk'];
        foreach (self::SELF_SERVICE_FIELDS as $field) {
            // Vazio no Self-Service: fica o padrão do core/da tabela
            if (isset($source[$field])) {
                $fields[$field] = $source[$field];
            }
        }
        // O add() do core recebe estes dois como lista e os grava em JSON
        foreach (['helpdesk_item_type', 'managed_domainrecordtypes'] as $field) {
            if (isset($fields[$field])) {
                $fields[$field] = importArrayFromDB($fields[$field]);
            }
        }
        // Sem _cycle_ticket, o add() de perfil helpdesk troca ticket_status pelo ciclo todo fechado
        if (isset($fields['ticket_status'])) {
            $fields['_cycle_ticket'] = 1;
        }

        $rights = [];
        foreach (ProfileRight::getProfileRights((int) $source['id']) as $name => $value) {
            if (strpos((string) $name, 'plugin_checklistitens_') !== 0) {
                $rights[$name] = (int) $value;
            }
        }

        return [$fields, $rights];
    }

    /**
     * Self-Service: pelo nome; senão o perfil de id 1; senão o perfil padrão. Sempre helpdesk.
     *
     * @return array<string, mixed>|null
     */
    private static function findSelfService(): ?array
    {
        $by_id      = null;
        $by_default = null;

        foreach ((new GlpiProfile())->find(['interface' => 'helpdesk'], ['id']) as $data) {
            if (mb_strtolower(trim((string) $data['name'])) === 'self-service') {
                return $data;
            }
            if ((int) $data['id'] === 1) {
                $by_id = $data;
            }
            if ($by_default === null && (int) $data['is_default'] === 1) {
                $by_default = $data;
            }
        }

        return $by_id ?? $by_default;
    }

    /**
     * Registra os direitos no core. Só na primeira instalação aplica a concessão padrão:
     * uso para todos os perfis, gestor para "gestor operacional" e tudo para o Super-Admin.
     * Numa atualização, os direitos já ajustados pelos administradores são preservados.
     *
     * Os perfis recém-criados por createDefaultProfiles() recebem seus direitos em qualquer caso
     * (o hook de criação de perfil do plugin não roda enquanto ele está sendo instalado):
     * Operador → uso; Gestor Operacional → uso e gestor.
     *
     * @param array<string, int> $created retorno de createDefaultProfiles()
     */
    public static function installRights(array $created = []): void
    {
        $first_install = countElementsInTable(ProfileRight::getTable(), ['name' => self::RIGHT_USAGE]) === 0;

        ProfileRight::addProfileRights([self::RIGHT_USAGE, self::RIGHT_MANAGER, self::RIGHT_CONFIG]);

        if ($first_install) {
            foreach ((new GlpiProfile())->find() as $data) {
                $rights = [self::RIGHT_USAGE => CREATE];
                $name   = mb_strtolower(trim((string) $data['name']));

                if ($name === self::MANAGER_PROFILE_NAME) {
                    $rights[self::RIGHT_MANAGER] = READ | UPDATE;
                }
                if ($name === 'super-admin') {
                    $rights[self::RIGHT_MANAGER] = READ | UPDATE;
                    $rights[self::RIGHT_CONFIG]  = READ | UPDATE;
                }

                ProfileRight::updateProfileRights((int) $data['id'], $rights);
            }
        }

        // Na primeira instalação o laço acima já cobre; repetir é inofensivo
        $grants = [
            self::DEFAULT_OPERATOR => [self::RIGHT_USAGE => CREATE],
            self::DEFAULT_MANAGER  => [self::RIGHT_USAGE => CREATE, self::RIGHT_MANAGER => READ | UPDATE],
        ];
        foreach ($grants as $key => $rights) {
            $profiles_id = (int) ($created[$key] ?? 0);
            if ($profiles_id > 0) {
                ProfileRight::updateProfileRights($profiles_id, $rights);
            }
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT_USAGE, self::RIGHT_MANAGER, self::RIGHT_CONFIG]);
    }

    public static function grantUsage(int $profiles_id): void
    {
        if ($profiles_id > 0) {
            ProfileRight::updateProfileRights($profiles_id, [self::RIGHT_USAGE => CREATE]);
        }
    }

    public static function canUse(): bool
    {
        return Session::haveRight(self::RIGHT_USAGE, CREATE);
    }

    public static function isManager(): bool
    {
        return Session::haveRight(self::RIGHT_MANAGER, READ);
    }

    public static function canConfirm(): bool
    {
        return Session::haveRight(self::RIGHT_MANAGER, UPDATE);
    }

    /** TI: vê todos os setores. */
    public static function isAdmin(): bool
    {
        return Session::haveRight(self::RIGHT_CONFIG, READ);
    }

    /** TI: configura, libera itens bloqueados e limpa selfies. */
    public static function canAdministrate(): bool
    {
        return Session::haveRight(self::RIGHT_CONFIG, UPDATE);
    }
}
