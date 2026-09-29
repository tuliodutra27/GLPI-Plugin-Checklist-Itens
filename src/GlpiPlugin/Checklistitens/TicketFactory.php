<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Toolbox\Sanitizer;
use Item_Ticket;
use Session;
use Ticket;
use Throwable;
use Toolbox;

/**
 * Abre o chamado do TI para um item bloqueado, com texto gerado automaticamente (o gestor não
 * digita nada) e vinculado ao registro de uso (e ao telefone, quando é telefone).
 */
class TicketFactory
{
    /**
     * @return int id do chamado, ou 0 se não foi possível abrir
     */
    public static function openForBlock(ItemBlock $block): int
    {
        if ((int) $block->fields['tickets_id'] > 0) {
            return (int) $block->fields['tickets_id'];
        }

        $usage = new Usage();
        if (!$usage->getFromDB((int) $block->fields['plugin_checklistitens_usages_id'])) {
            return 0;
        }

        $itemtype = (string) $block->fields['itemtype'];
        $items_id = (int) $block->fields['items_id'];
        $phase    = (int) $block->fields['reason'] === ItemBlock::REASON_CHECKIN ? Usage::PHASE_CHECKIN : Usage::PHASE_CHECKOUT;
        $problems = UsageProblem::getFor((int) $usage->getID(), $phase);
        $item     = ItemProvider::describe($itemtype, $items_id);
        $names    = array_column($problems, 'name');

        $when = $phase === Usage::PHASE_CHECKIN
            ? sprintf(__('Devolução em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkin']), Shift::label($usage->fields['checkin_shift_start']))
            : sprintf(__('Retirada em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkout']), Shift::label($usage->fields['checkout_shift_start']));

        $name = sprintf('[Checklist] %s — %s', $item, implode(', ', $names));
        if (mb_strlen($name) > 250) {
            $name = mb_substr($name, 0, 247) . '...';
        }

        $html  = '<p><strong>' . htmlspecialchars(__('Equipamento', 'checklistitens')) . ':</strong> ' . htmlspecialchars($item) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Setor', 'checklistitens')) . ':</strong> ' . htmlspecialchars(Sector::getName((int) $block->fields['groups_id'])) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Colaborador', 'checklistitens')) . ':</strong> ' . htmlspecialchars(Ui::text(getUserName((int) $usage->fields['users_id']))) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Quando', 'checklistitens')) . ':</strong> ' . htmlspecialchars($when) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Problemas marcados', 'checklistitens')) . ':</strong></p><ul>';
        foreach ($names as $problem) {
            $html .= '<li>' . htmlspecialchars($problem) . '</li>';
        }
        $html .= '</ul>';
        $html .= '<p><em>' . htmlspecialchars(__('Chamado aberto na conferência do gestor (Checklist uso de equipamentos). O equipamento fica bloqueado para retirada até este chamado ser solucionado.', 'checklistitens')) . '</em></p>';

        $ticket = new Ticket();
        try {
            $tickets_id = $ticket->add([
                'name'                => Sanitizer::sanitize($name),
                'content'             => Sanitizer::sanitize($html),
                'type'                => Ticket::INCIDENT_TYPE,
                'itilcategories_id'   => self::getCategory($itemtype, $problems),
                'entities_id'         => (int) $block->fields['entities_id'],
                '_users_id_requester' => (int) Session::getLoginUserID(),
            ]);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: erro ao abrir chamado: ' . $e->getMessage());
            $tickets_id = false;
        }

        if (!$tickets_id) {
            return 0;
        }

        $link = new Item_Ticket();
        $link->add(['tickets_id' => $tickets_id, 'itemtype' => Usage::class, 'items_id' => (int) $usage->getID()]);
        if ($itemtype === ItemProvider::PHONE) {
            $link->add(['tickets_id' => $tickets_id, 'itemtype' => ItemProvider::PHONE, 'items_id' => $items_id]);
        }

        $block->update([
            'id'         => $block->getID(),
            'tickets_id' => $tickets_id,
            'status'     => ItemBlock::STATUS_REPAIR,
        ]);

        return (int) $tickets_id;
    }

    /**
     * Categoria do chamado: se todos os problemas marcados têm a mesma categoria (a do problema
     * ou, na falta, a padrão do tipo), usa essa; se forem diferentes, usa a padrão do tipo.
     * Ex.: só bateria do rádio → "Rádio > Troca de Bateria"; bateria + PTT → "Rádio > Falha".
     */
    private static function getCategory(string $itemtype, array $problems): int
    {
        global $DB;

        $default = Config::getCategory($itemtype);
        $ids     = array_filter(array_column($problems, 'problemtypes_id'));
        if (!count($ids)) {
            return $default;
        }

        $categories = [];
        foreach ($DB->request(['SELECT' => ['id', 'itilcategories_id'], 'FROM' => ProblemType::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
            $category = (int) $row['itilcategories_id'];
            $categories[$category > 0 ? $category : $default] = true;
        }

        return count($categories) === 1 ? (int) array_key_first($categories) : $default;
    }
}
