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

        // O item tinha laudo em aberto e o colaborador disse que era outro problema: o TI vê o histórico
        $laudos = UsageLaudo::getForUsages([(int) $usage->getID()])[(int) $usage->getID()][$phase] ?? [];
        if (count($laudos)) {
            $html .= '<p><strong>' . htmlspecialchars(__('Laudos em aberto do equipamento (informado como outro problema)', 'checklistitens')) . ':</strong></p><ul>';
            foreach ($laudos as $laudo) {
                $html .= '<li>' . htmlspecialchars(trim(sprintf(
                    '%s · %s · %s %s',
                    $laudo['name'],
                    $laudo['status'],
                    $laudo['date'] !== '' ? Ui::datetime($laudo['date']) : '',
                    $laudo['items'] !== '' ? '— ' . $laudo['items'] : ''
                ))) . '</li>';
            }
            $html .= '</ul>';
        }

        $has_photos = count(DefectPhoto::getForUsages([(int) $usage->getID()])[(int) $usage->getID()][$phase] ?? []) > 0;
        $html .= '<p><em>' . htmlspecialchars(__('Chamado aberto na conferência do gestor (Checklist uso de equipamentos). O equipamento fica bloqueado para retirada até este chamado ser solucionado.', 'checklistitens'))
            . ($has_photos ? ' ' . htmlspecialchars(__('As fotos do defeito estão anexadas.', 'checklistitens')) : '') . '</em></p>';

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

        // Primeiro liga o chamado ao bloqueio: se o anexo das fotos falhar no meio, a próxima
        // conferência não abre um chamado repetido
        $block->update([
            'id'         => $block->getID(),
            'tickets_id' => $tickets_id,
            'status'     => ItemBlock::STATUS_REPAIR,
        ]);

        // Fotos do defeito como documentos do chamado, na entidade do chamado (uma regra pode
        // tê-la mudado); uma falha aqui não desfaz o chamado
        $entities_id = (int) ($ticket->fields['entities_id'] ?? $block->fields['entities_id']);
        DefectPhoto::attachToTicket((int) $usage->getID(), $phase, (int) $tickets_id, $entities_id);

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
