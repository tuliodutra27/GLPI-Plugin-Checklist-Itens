<?php

namespace GlpiPlugin\Checklistitens;

use CommonDropdown;
use Session;

/**
 * Embarcações que recebem rádios na entrega feita pelo gestor. O TI cadastra; o gestor só escolhe
 * uma embarcação ativa na lista. A entrega guarda uma cópia do nome, por isso alterar ou apagar o
 * cadastro não muda o histórico (o recomendado é desativar).
 */
class Vessel extends CommonDropdown
{
    public static $rightname = Profile::RIGHT_CONFIG;

    public $dohistory = true;

    // Nome próprio não tem tradução: as listas do plugin leem o nome direto da tabela.
    public $can_be_translated = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Embarcação', 'Embarcações', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-ship';
    }

    public static function canView()
    {
        return Profile::isAdmin();
    }

    // O direito de administração só tem "ver" e "configurar". O padrão do core pediria os bits de
    // criar e de apagar, que esse direito não tem: quem configura cria, altera e apaga.
    public static function canCreate()
    {
        return Profile::canAdministrate();
    }

    public static function canUpdate()
    {
        return Profile::canAdministrate();
    }

    public static function canDelete()
    {
        return Profile::canAdministrate();
    }

    public static function canPurge()
    {
        return Profile::canAdministrate();
    }

    public function getAdditionalFields()
    {
        return [
            [
                'name'  => 'is_active',
                'label' => __('Ativa', 'checklistitens'),
                'type'  => 'bool',
                'list'  => true,
            ],
        ];
    }

    public function rawSearchOptions()
    {
        // Nome (1), observação (16) e datas (19 e 121) já vêm do cadastro padrão do GLPI
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => '101',
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Ativa', 'checklistitens'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    /**
     * O formulário de embarcação nova abre como ativa e visível nas entidades filhas (os padrões
     * da tabela); sem isso o core mostra os dois campos como "Não".
     */
    public function post_getEmpty()
    {
        $this->fields['is_active']    = 1;
        $this->fields['is_recursive'] = 1;
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if (!is_array($input) || !self::hasName($input, true)) {
            return false;
        }

        // Embarcação nova nasce ativa, mesmo quando o campo não vem no formulário
        if (!isset($input['is_active']) || $input['is_active'] === '') {
            $input['is_active'] = 1;
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        if (!is_array($input) || !self::hasName($input, false)) {
            return false;
        }

        return $input;
    }

    /**
     * O nome é o que o gestor vê na lista e o que fica copiado na entrega: não pode ficar vazio.
     * Na alteração só é conferido quando o campo vem (ativar ou desativar não manda o nome).
     */
    private static function hasName(array $input, bool $required): bool
    {
        if (!$required && !array_key_exists('name', $input)) {
            return true;
        }
        if (trim((string) ($input['name'] ?? '')) !== '') {
            return true;
        }

        Session::addMessageAfterRedirect(__('Informe o nome da embarcação.', 'checklistitens'), false, ERROR);

        return false;
    }

    /**
     * Embarcações que o gestor pode escolher: ativas e visíveis nas entidades do usuário.
     *
     * @return array<int, string> id => nome em texto puro, em ordem de nome
     */
    public static function getActiveList(): array
    {
        global $DB;

        $table    = self::getTable();
        $criteria = self::getActiveCriteria();
        $criteria['SELECT'] = ["$table.id", "$table.name"];
        $criteria['ORDER']  = ["$table.name", "$table.id"];

        $list = [];
        foreach ($DB->request($criteria) as $row) {
            $list[(int) $row['id']] = Ui::text($row['name']);
        }

        return $list;
    }

    /**
     * Revalida, no momento de salvar, se a embarcação continua valendo: mesma regra da lista
     * (ativa e visível nas entidades do usuário).
     *
     * @return array|null linha crua do banco (o nome vem codificado: Ui::text() para exibir,
     *                    Sanitizer::dbEscape() para regravar) ou null
     */
    public static function getActiveRow(int $id): ?array
    {
        global $DB;

        if ($id <= 0) {
            return null;
        }

        $criteria = self::getActiveCriteria();
        $criteria['WHERE'][self::getTable() . '.id'] = $id;

        $row = $DB->request($criteria)->current();

        return $row ? $row : null;
    }

    /**
     * Nome para exibição, ativa ou não (sem checar entidade).
     *
     * @return string texto puro, ou '' se a embarcação não existe
     */
    public static function getNameById(int $id): string
    {
        global $DB;

        if ($id <= 0) {
            return '';
        }

        $row = $DB->request([
            'SELECT' => 'name',
            'FROM'   => self::getTable(),
            'WHERE'  => ['id' => $id],
        ])->current();

        return $row ? Ui::text($row['name']) : '';
    }

    /**
     * Quem vê todas as entidades recebe uma restrição vazia, que viraria "AND ()" (SQL inválido)
     * se entrasse no WHERE como está; por isso só entra quando tem conteúdo.
     */
    private static function getActiveCriteria(): array
    {
        $table = self::getTable();
        $where = ["$table.is_active" => 1];

        $restriction = getEntitiesRestrictCriteria($table, '', '', true);
        if (count($restriction)) {
            $where[] = $restriction;
        }

        return ['FROM' => $table, 'WHERE' => $where];
    }
}
