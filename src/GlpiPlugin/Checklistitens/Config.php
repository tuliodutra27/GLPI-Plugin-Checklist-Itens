<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;
use Config as GlpiConfig;
use Dropdown;
use Html;
use ITILCategory;
use Session;
use State;

/**
 * Configuração do plugin, guardada em glpi_configs (contexto plugin:checklistitens).
 *
 * Listas são gravadas como texto simples ("PluginRadiosRadio,Phone", "Phone:0") para não depender
 * de escape de JSON no banco.
 */
class Config extends CommonGLPI
{
    public const CONTEXT = 'plugin:checklistitens';

    /** Mínimo de dias que uma selfie fica guardada antes de poder ser limpa. */
    public const MIN_RETENTION_DAYS = 90;

    public static $rightname = Profile::RIGHT_CONFIG;

    /** Categorias ITIL padrão, achadas pelo nome completo na instalação. */
    public const DEFAULT_CATEGORIES = [
        ItemProvider::RADIO => 'TI > 02 - Equipamentos > Rádio > Falha',
        ItemProvider::PHONE => 'TI > 02 - Equipamentos > Tablet > Falha',
    ];

    public const BATTERY_CATEGORY = 'TI > 02 - Equipamentos > Rádio > Troca de Bateria';

    /** Perfil que, por padrão, abre a tela do plugin logo depois do login. */
    public const DEFAULT_LANDING_PROFILE = 'operador';

    public static function getTypeName($nb = 0)
    {
        return __('Configuração', 'checklistitens');
    }

    /**
     * @return array<string, string>
     */
    private static function getDefaults(): array
    {
        return [
            'itemtypes'             => ItemProvider::RADIO . ',' . ItemProvider::PHONE,
            'states'                => '',
            'limits'                => ItemProvider::RADIO . ':1,' . ItemProvider::PHONE . ':0',
            'inherit_subgroups'     => '1',
            'shift_starts'          => '07:00,19:00',
            'itilcategories'        => '',
            'idle_logout'           => '60',
            'selfie_retention_days' => (string) self::MIN_RETENTION_DAYS,
            'landing_profiles'      => '',
        ];
    }

    /**
     * Grava só as chaves que ainda não existem (preserva o que o TI já configurou).
     */
    public static function installDefaults(): void
    {
        $current  = GlpiConfig::getConfigurationValues(self::CONTEXT);
        $defaults = self::getDefaults();

        if (!isset($current['states'])) {
            $defaults['states'] = implode(',', self::findIdsByName(State::getTable(), 'name', ['Ativo']));
        }
        if (!isset($current['landing_profiles'])) {
            $defaults['landing_profiles'] = implode(',', self::findIdsByName(\Profile::getTable(), 'name', [self::DEFAULT_LANDING_PROFILE]));
        }
        if (!isset($current['itilcategories'])) {
            $pairs = [];
            foreach (self::DEFAULT_CATEGORIES as $itemtype => $completename) {
                $ids = self::findIdsByName(ITILCategory::getTable(), 'completename', [$completename]);
                $pairs[] = $itemtype . ':' . ($ids[0] ?? 0);
            }
            $defaults['itilcategories'] = implode(',', $pairs);
        }

        $missing = array_diff_key($defaults, $current);
        if (count($missing)) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function uninstall(): void
    {
        GlpiConfig::deleteConfigurationValues(self::CONTEXT, array_keys(self::getDefaults()));
    }

    /**
     * Procura ids por nome aceitando o valor cru e o valor com caracteres HTML codificados
     * (o GLPI 10 grava alguns nomes codificados, ex.: ">" como "&#62;").
     *
     * @return int[]
     */
    public static function findIdsByName(string $table, string $field, array $names): array
    {
        global $DB;

        $values = [];
        foreach ($names as $name) {
            $values[] = $name;
            $values[] = htmlspecialchars($name, ENT_NOQUOTES);
            $values[] = str_replace(['<', '>'], ['&#60;', '&#62;'], $name);
        }

        $ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => [$field => array_unique($values)]]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private static function getValue(string $key): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = GlpiConfig::getConfigurationValues(self::CONTEXT) + self::getDefaults();
        }

        return (string) ($cache[$key] ?? '');
    }

    /** @return string[] */
    private static function getList(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::getValue($key))), 'strlen'));
    }

    /** @return array<string, int> */
    private static function getMap(string $key): array
    {
        $map = [];
        foreach (self::getList($key) as $pair) {
            [$k, $v] = array_pad(explode(':', $pair, 2), 2, '0');
            $map[$k] = (int) $v;
        }

        return $map;
    }

    /** @return string[] tipos habilitados cujo plugin/classe existe */
    public static function getEnabledTypes(): array
    {
        return array_values(array_filter(self::getList('itemtypes'), [ItemProvider::class, 'isSupported']));
    }

    /** @return int[] */
    public static function getAvailableStates(): array
    {
        return array_map('intval', self::getList('states'));
    }

    /** 0 = sem limite */
    public static function getLimit(string $itemtype): int
    {
        return self::getMap('limits')[$itemtype] ?? 0;
    }

    public static function inheritSubgroups(): bool
    {
        return self::getValue('inherit_subgroups') === '1';
    }

    /** @return string[] horários "HH:MM" em ordem */
    public static function getShiftStarts(): array
    {
        $starts = array_filter(self::getList('shift_starts'), static fn ($s) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s));
        sort($starts);

        return count($starts) ? array_values($starts) : ['07:00', '19:00'];
    }

    public static function getCategory(string $itemtype): int
    {
        return self::getMap('itilcategories')[$itemtype] ?? 0;
    }

    public static function getIdleSeconds(): int
    {
        return max(0, (int) self::getValue('idle_logout'));
    }

    public static function getRetentionDays(): int
    {
        return max(self::MIN_RETENTION_DAYS, (int) self::getValue('selfie_retention_days'));
    }

    /**
     * Perfis que abrem a tela do plugin logo depois do login. Enquanto a opção nunca foi gravada
     * (plugin instalado antes dela existir), vale o perfil de nome "operador".
     *
     * @return int[]
     */
    public static function getLandingProfiles(): array
    {
        $stored = GlpiConfig::getConfigurationValues(self::CONTEXT, ['landing_profiles']);
        if (!array_key_exists('landing_profiles', $stored)) {
            return self::findIdsByName(\Profile::getTable(), 'name', [self::DEFAULT_LANDING_PROFILE]);
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $stored['landing_profiles']))));
    }

    /**
     * Grava o formulário de configuração (valores já vêm tratados pelo GLPI em $_POST).
     */
    public static function saveFromForm(array $input): void
    {
        $types = array_values(array_filter((array) ($input['itemtypes'] ?? []), [ItemProvider::class, 'isSupported']));

        $limits = $categories = [];
        foreach (ItemProvider::getSupportedTypes() as $itemtype) {
            $limits[]     = $itemtype . ':' . max(0, (int) ($input['limit_' . $itemtype] ?? 0));
            $categories[] = $itemtype . ':' . max(0, (int) ($input['itilcategories_' . $itemtype] ?? 0));
        }

        $starts = array_filter(array_map('trim', explode(',', (string) ($input['shift_starts'] ?? ''))), static fn ($s) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s));
        if (!count($starts)) {
            Session::addMessageAfterRedirect(__('Horários de turno inválidos. Use o formato 07:00,19:00.', 'checklistitens'), false, ERROR);
            return;
        }

        GlpiConfig::setConfigurationValues(self::CONTEXT, [
            'itemtypes'             => implode(',', $types),
            'states'                => implode(',', array_filter(array_map('intval', (array) ($input['states'] ?? [])))),
            'limits'                => implode(',', $limits),
            'inherit_subgroups'     => !empty($input['inherit_subgroups']) ? '1' : '0',
            'shift_starts'          => implode(',', $starts),
            'itilcategories'        => implode(',', $categories),
            'idle_logout'           => (string) max(0, (int) ($input['idle_logout'] ?? 60)),
            'selfie_retention_days' => (string) max(self::MIN_RETENTION_DAYS, (int) ($input['selfie_retention_days'] ?? self::MIN_RETENTION_DAYS)),
            'landing_profiles'      => implode(',', array_filter(array_map('intval', (array) ($input['landing_profiles'] ?? [])))),
        ]);

        Session::addMessageAfterRedirect(__('Configuração salva.', 'checklistitens'));
    }

    public static function showForm(): void
    {
        $canedit = Profile::canAdministrate();

        echo "<form method='post' action='" . Ui::url('front/config.form.php') . "'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Tipos de equipamento habilitados', 'checklistitens') . "</td><td>";
        $enabled = self::getList('itemtypes');
        foreach (ItemProvider::getSupportedTypes() as $itemtype) {
            $checked = in_array($itemtype, $enabled, true) ? 'checked' : '';
            echo "<label class='me-3'><input type='checkbox' class='form-check-input me-1' name='itemtypes[]' value='" . $itemtype . "' $checked> "
                . htmlspecialchars(ItemProvider::getTypeLabel($itemtype)) . "</label>";
        }
        if (!ItemProvider::isSupported(ItemProvider::RADIO)) {
            echo "<div class='text-muted'>" . __('O plugin Radios não está ativo: o tipo Rádio fica indisponível.', 'checklistitens') . "</div>";
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Estados disponíveis para retirada', 'checklistitens') . "</td><td>";
        // Seleção múltipla: no GLPI 10 os valores vão em 'value' (array)
        State::dropdown([
            'name'     => 'states',
            'value'    => self::getAvailableStates(),
            'multiple' => true,
        ]);
        echo "</td></tr>";

        foreach (ItemProvider::getSupportedTypes() as $itemtype) {
            echo "<tr class='tab_bg_1'><td>" . sprintf(__('Limite por pessoa — %s (0 = sem limite)', 'checklistitens'), htmlspecialchars(ItemProvider::getTypeLabel($itemtype))) . "</td><td>";
            echo "<input type='number' min='0' class='form-control' style='max-width:8rem' name='limit_" . $itemtype . "' value='" . self::getLimit($itemtype) . "'>";
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'><td>" . __('Grupo pai vê os itens dos subgrupos', 'checklistitens') . "</td><td>";
        Dropdown::showYesNo('inherit_subgroups', self::inheritSubgroups() ? 1 : 0);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Início dos turnos (HH:MM, separados por vírgula)', 'checklistitens') . "</td><td>";
        echo "<input type='text' class='form-control' style='max-width:16rem' name='shift_starts' value='" . htmlspecialchars(implode(',', self::getShiftStarts()), ENT_QUOTES) . "'>";
        echo "</td></tr>";

        foreach (ItemProvider::getSupportedTypes() as $itemtype) {
            echo "<tr class='tab_bg_1'><td>" . sprintf(__('Categoria do chamado — %s', 'checklistitens'), htmlspecialchars(ItemProvider::getTypeLabel($itemtype))) . "</td><td>";
            ITILCategory::dropdown([
                'name'   => 'itilcategories_' . $itemtype,
                'value'  => self::getCategory($itemtype),
                'entity' => $_SESSION['glpiactive_entity'] ?? 0,
            ]);
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'><td>" . __('Perfis que abrem a tela do plugin logo depois do login', 'checklistitens') . "</td><td>";
        \Profile::dropdown([
            'name'     => 'landing_profiles',
            'value'    => self::getLandingProfiles(),
            'multiple' => true,
        ]);
        echo "<div class='text-muted'>" . __('Só na primeira página depois do login; o Início do GLPI continua acessível.', 'checklistitens') . "</div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Logoff por inatividade nas telas do colaborador (segundos, 0 = desligado)', 'checklistitens') . "</td><td>";
        echo "<input type='number' min='0' class='form-control' style='max-width:8rem' name='idle_logout' value='" . self::getIdleSeconds() . "'>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . sprintf(__('Retenção mínima das selfies (dias, mínimo %d)', 'checklistitens'), self::MIN_RETENTION_DAYS) . "</td><td>";
        echo "<input type='number' min='" . self::MIN_RETENTION_DAYS . "' class='form-control' style='max-width:8rem' name='selfie_retention_days' value='" . self::getRetentionDays() . "'>";
        echo "</td></tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
        }

        echo "</table>";
        Html::closeForm();
    }
}
