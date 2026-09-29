<?php

namespace GlpiPlugin\Checklistitens;

use Group_User;
use NotificationTarget;
use Profile_User;
use ProfileRight;
use QueryExpression;
use User;

/**
 * Destinatários e dados da notificação "Equipamento não devolvido no fim do turno".
 * O GLPI acha esta classe pelo nome (NotificationTarget + Usage, no mesmo namespace).
 */
class NotificationTargetUsage extends NotificationTarget
{
    public const EVENT_NOT_RETURNED = 'not_returned';

    /** Gestores operacionais cujo setor (com herança) inclui o grupo do equipamento. */
    public const SECTOR_MANAGERS = 5301;

    /** Usuários com o direito de administração do plugin (TI). */
    public const PLUGIN_ADMINS = 5302;

    public function getEvents()
    {
        return [
            self::EVENT_NOT_RETURNED => __('Equipamento não devolvido no fim do turno', 'checklistitens'),
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::SECTOR_MANAGERS, __('Gestores do setor (Checklist uso de equipamentos)', 'checklistitens'));
        $this->addTarget(self::PLUGIN_ADMINS, __('Administradores do Checklist uso de equipamentos (TI)', 'checklistitens'));
    }

    public function addSpecificTargets($data, $options)
    {
        switch ((int) $data['items_id']) {
            case self::SECTOR_MANAGERS:
                $groups   = Sector::withAncestors((int) ($options['groups_id'] ?? 0));
                $managers = self::getUsersWithRight(Profile::RIGHT_MANAGER, READ);
                $this->addUsers(array_values(array_intersect($managers, self::getGroupMembers($groups))));
                break;

            case self::PLUGIN_ADMINS:
                $this->addUsers(self::getUsersWithRight(Profile::RIGHT_CONFIG, READ));
                break;
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        $groups_id = (int) ($options['groups_id'] ?? 0);
        $usage     = new Usage();
        $items     = [];

        foreach (array_map('intval', (array) ($options['usages_ids'] ?? [])) as $usage_id) {
            if (!$usage->getFromDB($usage_id)) {
                continue;
            }
            $items[] = [
                '##item.type##'     => ItemProvider::getShortTypeLabel((string) $usage->fields['itemtype']),
                '##item.label##'    => ItemProvider::describe((string) $usage->fields['itemtype'], (int) $usage->fields['items_id']),
                '##item.user##'     => Ui::text(getUserName((int) $usage->fields['users_id'])),
                '##item.checkout##' => Ui::datetime($usage->fields['date_checkout']),
                '##item.status##'   => Usage::getStatusLabels()[(int) $usage->fields['status']] ?? '',
            ];
        }

        $this->data['##checklist.sector##'] = Sector::getName($groups_id);
        $this->data['##checklist.shift##']  = Shift::label($options['shift_start'] ?? null);
        $this->data['##checklist.count##']  = count($items);
        $this->data['##checklist.url##']    = $CFG_GLPI['url_base'] . '/plugins/checklistitens/front/manager.php?groups_id=' . $groups_id;
        $this->data['items']                = $items;

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'checklist.sector'   => __('Setor', 'checklistitens'),
            'checklist.shift'    => __('Turno da retirada', 'checklistitens'),
            'checklist.count'    => __('Quantidade de equipamentos', 'checklistitens'),
            'checklist.url'      => __('Link da conferência do setor', 'checklistitens'),
            'item.type'          => __('Tipo', 'checklistitens'),
            'item.label'         => __('Equipamento', 'checklistitens'),
            'item.user'          => __('Colaborador', 'checklistitens'),
            'item.checkout'      => __('Retirada', 'checklistitens'),
            'item.status'        => __('Situação', 'checklistitens'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        $this->addTagToList([
            'tag'     => 'items',
            'label'   => __('Equipamentos não devolvidos', 'checklistitens'),
            'value'   => false,
            'foreach' => true,
        ]);

        asort($this->tag_descriptions);
    }

    /**
     * @param int[] $users_ids
     */
    private function addUsers(array $users_ids): void
    {
        global $DB;

        $users_ids = array_values(array_unique(array_filter(array_map('intval', $users_ids))));
        if (!count($users_ids)) {
            return;
        }

        $criteria = $this->getDistinctUserCriteria() + $this->getProfileJoinCriteria();
        $criteria['FROM'] = User::getTable();
        $criteria['WHERE'][User::getTable() . '.id'] = $users_ids;

        foreach ($DB->request($criteria) as $data) {
            $this->addToRecipientsList($data);
        }
    }

    /**
     * @return int[] usuários que têm, em algum perfil, o direito informado
     */
    private static function getUsersWithRight(string $right, int $value): array
    {
        global $DB;

        $users = [];
        $iterator = $DB->request([
            'SELECT'     => [Profile_User::getTable() . '.users_id'],
            'DISTINCT'   => true,
            'FROM'       => Profile_User::getTable(),
            'INNER JOIN' => [
                ProfileRight::getTable() => ['ON' => [ProfileRight::getTable() => 'profiles_id', Profile_User::getTable() => 'profiles_id']],
            ],
            'WHERE'      => [
                ProfileRight::getTable() . '.name' => $right,
                new QueryExpression($DB->quoteName(ProfileRight::getTable() . '.rights') . ' & ' . $value . ' > 0'),
            ],
        ]);
        foreach ($iterator as $row) {
            $users[] = (int) $row['users_id'];
        }

        return $users;
    }

    /**
     * @param int[] $groups
     * @return int[]
     */
    private static function getGroupMembers(array $groups): array
    {
        global $DB;

        if (!count($groups)) {
            return [];
        }

        $users = [];
        foreach ($DB->request(['SELECT' => 'users_id', 'DISTINCT' => true, 'FROM' => Group_User::getTable(), 'WHERE' => ['groups_id' => $groups]]) as $row) {
            $users[] = (int) $row['users_id'];
        }

        return $users;
    }
}
