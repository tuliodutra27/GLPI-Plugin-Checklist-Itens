<?php

namespace GlpiPlugin\Checklistitens;

use CommonDropdown;
use Dropdown;
use Glpi\Toolbox\Sanitizer;
use ITILCategory;

/**
 * Catálogo de problemas por tipo de equipamento. É a lista que o colaborador marca quando
 * responde "Equipamento ok? Não" (ninguém digita nada). O TI pode ajustar pelo GLPI.
 */
class ProblemType extends CommonDropdown
{
    public const CATEGORY_FUNCTION  = 1;
    public const CATEGORY_BATTERY   = 2;
    public const CATEGORY_STRUCTURE = 3;
    public const CATEGORY_OTHER     = 4;

    // Peças que vão com os rádios para a embarcação: não são ativos do GLPI, só têm o catálogo
    // de problemas (o valor fica na coluna itemtype, como os tipos de equipamento)
    public const ACCESSORY_BATTERY = 'PluginChecklistitensBattery';
    public const ACCESSORY_COVER   = 'PluginChecklistitensCover';

    public static $rightname = Profile::RIGHT_CONFIG;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Problema do checklist', 'Problemas do checklist', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
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

    /**
     * Tipos do catálogo: os equipamentos disponíveis e as peças da entrega para embarcação.
     *
     * @return array<string, string> tipo => rótulo
     */
    public static function getTypeOptions(): array
    {
        $types = [];
        foreach (ItemProvider::getSupportedTypes() as $itemtype) {
            $types[$itemtype] = ItemProvider::getTypeLabel($itemtype);
        }
        $types[self::ACCESSORY_BATTERY] = __('Bateria (entrega para embarcação)', 'checklistitens');
        $types[self::ACCESSORY_COVER]   = __('Capa (entrega para embarcação)', 'checklistitens');

        return $types;
    }

    public static function getTypeLabel(string $itemtype): string
    {
        return self::getTypeOptions()[$itemtype] ?? ItemProvider::getTypeLabel($itemtype);
    }

    /** @return array<int, string> */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_FUNCTION  => __('Funcionamento', 'checklistitens'),
            self::CATEGORY_BATTERY   => __('Bateria', 'checklistitens'),
            self::CATEGORY_STRUCTURE => __('Estrutura e acessórios', 'checklistitens'),
            self::CATEGORY_OTHER     => __('Outros', 'checklistitens'),
        ];
    }

    public function getAdditionalFields()
    {
        return [
            [
                'name'  => 'itemtype',
                'label' => __('Tipo de equipamento', 'checklistitens'),
                'type'  => 'specific_itemtype',
                'list'  => true,
            ],
            [
                'name'  => 'category',
                'label' => __('Grupo do problema', 'checklistitens'),
                'type'  => 'specific_category',
                'list'  => true,
            ],
            [
                'name'  => 'itilcategories_id',
                'label' => __('Categoria do chamado (vazio = padrão do tipo)', 'checklistitens'),
                'type'  => 'dropdownValue',
                'list'  => true,
            ],
            [
                'name'  => 'ranking',
                'label' => __('Ordem', 'checklistitens'),
                'type'  => 'integer',
                'list'  => true,
            ],
            [
                'name'  => 'is_active',
                'label' => __('Ativo', 'checklistitens'),
                'type'  => 'bool',
                'list'  => true,
            ],
        ];
    }

    public function displaySpecificTypeField($ID, $field = [], array $options = [])
    {
        switch ($field['type'] ?? '') {
            case 'specific_itemtype':
                Dropdown::showFromArray('itemtype', self::getTypeOptions(), ['value' => $this->fields['itemtype'] ?? ItemProvider::RADIO]);
                break;

            case 'specific_category':
                Dropdown::showFromArray('category', self::getCategories(), ['value' => $this->fields['category'] ?? self::CATEGORY_FUNCTION]);
                break;
        }
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'         => '101',
            'table'      => $this->getTable(),
            'field'      => 'itemtype',
            'name'       => __('Tipo de equipamento', 'checklistitens'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id'         => '102',
            'table'      => $this->getTable(),
            'field'      => 'category',
            'name'       => __('Grupo do problema', 'checklistitens'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id'       => '103',
            'table'    => ITILCategory::getTable(),
            'field'    => 'completename',
            'name'     => __('Categoria do chamado', 'checklistitens'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => '104',
            'table'    => $this->getTable(),
            'field'    => 'ranking',
            'name'     => __('Ordem', 'checklistitens'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '105',
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Ativo', 'checklistitens'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'itemtype':
                return htmlspecialchars(self::getTypeLabel((string) $values[$field]));
            case 'category':
                return htmlspecialchars(self::getCategories()[(int) $values[$field]] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        switch ($field) {
            case 'itemtype':
                return Dropdown::showFromArray($name, self::getTypeOptions(), $options + ['value' => $values[$field]]);
            case 'category':
                return Dropdown::showFromArray($name, self::getCategories(), $options + ['value' => $values[$field]]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Problemas ativos de um tipo, agrupados pelo grupo do problema.
     *
     * @return array<int, array{label: string, problems: array<int, array{id: int, name: string}>}>
     */
    public static function getGroupedForType(string $itemtype): array
    {
        global $DB;

        $groups = [];
        foreach (self::getCategories() as $category => $label) {
            $groups[$category] = ['label' => $label, 'problems' => []];
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'is_active' => 1],
            'ORDER' => ['category', 'ranking', 'name'],
        ]);
        foreach ($iterator as $row) {
            $category = isset($groups[(int) $row['category']]) ? (int) $row['category'] : self::CATEGORY_OTHER;
            $groups[$category]['problems'][] = [
                'id'   => (int) $row['id'],
                'name' => Ui::text($row['name']),
            ];
        }

        return array_values(array_filter($groups, static fn ($g) => count($g['problems']) > 0));
    }

    /**
     * Valida os ids marcados: só aceita problemas ativos do tipo informado.
     *
     * @param int[] $ids
     * @return array<int, array> linhas do catálogo, indexadas pelo id
     */
    public static function getValidForType(string $itemtype, array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!count($ids)) {
            return [];
        }

        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $ids, 'itemtype' => $itemtype, 'is_active' => 1]]) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Pré-carga do catálogo: por tipo, só quando o tipo não tem nenhum problema cadastrado. Assim a
     * atualização traz os tipos novos (bateria e capa) sem mexer nos que o TI já ajustou. Os
     * problemas de bateria abrem chamado na categoria "Rádio > Troca de Bateria", se ela existir.
     */
    public static function installDefaults(): void
    {
        $battery_ids      = Config::findIdsByName(ITILCategory::getTable(), 'completename', [Config::BATTERY_CATEGORY]);
        $radio_battery_id = $battery_ids[0] ?? 0;

        $item = new self();
        foreach (self::getDefaultValues() as $itemtype => $categories) {
            if (countElementsInTable(self::getTable(), ['itemtype' => $itemtype]) > 0) {
                continue;
            }
            $is_battery = $itemtype === self::ACCESSORY_BATTERY;
            $ranking    = 0;
            foreach ($categories as $category => $names) {
                foreach ($names as $name) {
                    $ranking += 10;
                    $item->add([
                        'name'              => Sanitizer::sanitize($name),
                        'itemtype'          => $itemtype,
                        'category'          => $category,
                        'ranking'           => $ranking,
                        'is_active'         => 1,
                        'is_recursive'      => 1,
                        'entities_id'       => 0,
                        'itilcategories_id' => ($is_battery || ($itemtype === ItemProvider::RADIO && $category === self::CATEGORY_BATTERY)) ? $radio_battery_id : 0,
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, array<int, string[]>>
     */
    private static function getDefaultValues(): array
    {
        return [
            ItemProvider::RADIO => [
                self::CATEGORY_FUNCTION => [
                    'Rádio não liga',
                    'Rádio desliga sozinho',
                    'Botão PTT ruim (não transmite ou falha)',
                    'Não transmite (os outros não escutam)',
                    'Não recebe (não escuta os outros)',
                    'Áudio ruim (chiado, baixo ou cortando)',
                    'Microfone ruim (voz abafada ou cortando)',
                    'Seletor de canal com defeito',
                    'Botão de volume com defeito',
                    'Display ou luz (LED) com defeito',
                ],
                self::CATEGORY_BATTERY => [
                    'Bateria descarregada ou não segura carga',
                    'Bateria solta ou mal encaixada',
                    'Bateria estufada ou danificada',
                ],
                self::CATEGORY_STRUCTURE => [
                    'Antena quebrada, torta ou solta',
                    'Carcaça quebrada ou trincada',
                    'Clip de cinto quebrado ou ausente',
                    'Capa danificada ou ausente',
                    'Fone ou microfone de lapela com defeito',
                    'Sinais de umidade ou oxidação',
                ],
                self::CATEGORY_OTHER => [
                    'Outro problema (avisar o gestor)',
                ],
            ],
            ItemProvider::PHONE => [
                self::CATEGORY_FUNCTION => [
                    'Aparelho não liga',
                    'Desliga ou reinicia sozinho',
                    'Tela trincada ou quebrada',
                    'Touch com falha (não responde ao toque)',
                    'Tela com manchas, linhas ou sem imagem',
                    'Botões físicos com defeito (liga/desliga, volume)',
                    'Alto-falante ruim (som baixo, chiado ou mudo)',
                    'Microfone ruim',
                    'Câmera com defeito',
                    'Sem sinal ou sem internet (chip, Wi-Fi ou dados)',
                    'Aparelho travando ou aplicativo não abre',
                ],
                self::CATEGORY_BATTERY => [
                    'Bateria descarregada ou não segura carga',
                    'Não carrega (conector de carga com defeito)',
                ],
                self::CATEGORY_STRUCTURE => [
                    'Capa danificada ou ausente',
                    'Película danificada ou ausente',
                    'Carcaça amassada, trincada ou com peça solta',
                    'Carregador ou cabo com defeito ou ausente',
                    'Sinais de umidade',
                ],
                self::CATEGORY_OTHER => [
                    'Outro problema (avisar o gestor)',
                ],
            ],
            // Peças que vão com os rádios para a embarcação (não são ativos do GLPI)
            self::ACCESSORY_BATTERY => [
                self::CATEGORY_FUNCTION => [
                    'Não carrega',
                    'Não liga o rádio ou não segura carga',
                ],
                self::CATEGORY_STRUCTURE => [
                    'Trava quebrada',
                    'Polo ou contato queimado ou oxidado',
                    'Estufada',
                    'Carcaça trincada ou quebrada',
                ],
                self::CATEGORY_OTHER => [
                    'Outro problema (descrever nas observações)',
                ],
            ],
            self::ACCESSORY_COVER => [
                self::CATEGORY_STRUCTURE => [
                    'Rasgada ou furada',
                    'Presilha ou clipe quebrado',
                    'Costura solta',
                    'Fecho ou velcro com defeito',
                    'Alça arrebentada',
                ],
                self::CATEGORY_OTHER => [
                    'Outro problema (descrever nas observações)',
                ],
            ],
        ];
    }
}
