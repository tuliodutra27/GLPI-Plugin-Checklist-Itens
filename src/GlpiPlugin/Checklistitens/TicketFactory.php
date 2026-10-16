<?php

namespace GlpiPlugin\Checklistitens;

use Document;
use Document_Item;
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

        // Problema visto numa entrega para embarcação (na separação ou no recebimento de volta)
        $vessel      = '';
        $delivery_id = (int) ($usage->fields['plugin_checklistitens_deliveries_id'] ?? 0);
        if ($delivery_id > 0) {
            global $DB;

            $delivery = $DB->request(['SELECT' => 'vessel_name', 'FROM' => Install::TABLE_DELIVERIES, 'WHERE' => ['id' => $delivery_id]])->current();
            $vessel   = $delivery ? Ui::text($delivery['vessel_name']) : '';
            $when     = $phase === Usage::PHASE_CHECKIN
                ? sprintf(__('Recebimento da embarcação em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkin']), Shift::label($usage->fields['checkin_shift_start']))
                : sprintf(__('Separação para a embarcação em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkout']), Shift::label($usage->fields['checkout_shift_start']));
        }

        $name = sprintf('[Checklist] %s — %s', $item, implode(', ', $names));
        if (mb_strlen($name) > 250) {
            $name = mb_substr($name, 0, 247) . '...';
        }

        $html  = '<p><strong>' . htmlspecialchars(__('Equipamento', 'checklistitens')) . ':</strong> ' . htmlspecialchars($item) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Setor', 'checklistitens')) . ':</strong> ' . htmlspecialchars(Sector::getName((int) $block->fields['groups_id'])) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Colaborador', 'checklistitens')) . ':</strong> ' . htmlspecialchars(Ui::text(getUserName((int) $usage->fields['users_id']))) . '</p>';
        $html .= '<p><strong>' . htmlspecialchars(__('Quando', 'checklistitens')) . ':</strong> ' . htmlspecialchars($when) . '</p>';
        if ($vessel !== '') {
            $html .= '<p><strong>' . htmlspecialchars(__('Embarcação', 'checklistitens')) . ':</strong> ' . htmlspecialchars($vessel) . '</p>';
        }
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
     * Chamado do TI para as baterias e capas que voltaram com defeito num recebimento da
     * embarcação, aberto na conclusão do recebimento (o gestor não digita nada).
     *
     * @return int id do chamado, ou 0 se não foi possível abrir
     */
    public static function openForAccessories(int $receipts_id): int
    {
        global $DB;

        $receipt = $DB->request(['FROM' => Install::TABLE_DELIVERYRECEIPTS, 'WHERE' => ['id' => $receipts_id]])->current();
        if (!$receipt) {
            return 0;
        }
        if ((int) $receipt['tickets_id'] > 0) {
            return (int) $receipt['tickets_id'];
        }
        $pieces = AccessoryDefect::getForReceipts([$receipts_id])[$receipts_id] ?? [];
        if (!count($pieces)) {
            return 0;
        }

        $deliveries_id = (int) $receipt['plugin_checklistitens_deliveries_id'];
        $delivery      = $DB->request(['FROM' => Install::TABLE_DELIVERIES, 'WHERE' => ['id' => $deliveries_id]])->current();
        $vessel        = $delivery ? Ui::text($delivery['vessel_name']) : '';
        $batteries     = array_values(array_filter($pieces, static fn ($p) => $p['kind'] === AccessoryDefect::KIND_BATTERY));
        $covers        = array_values(array_filter($pieces, static fn ($p) => $p['kind'] === AccessoryDefect::KIND_COVER));

        $what = [];
        if (count($batteries)) {
            $what[] = sprintf(_n('%d bateria com defeito', '%d baterias com defeito', count($batteries), 'checklistitens'), count($batteries));
        }
        if (count($covers)) {
            $what[] = sprintf(_n('%d capa com dano', '%d capas com dano', count($covers), 'checklistitens'), count($covers));
        }
        $name = sprintf('[Checklist] %s — %s', implode(' e ', $what), $vessel);
        if (mb_strlen($name) > 250) {
            $name = mb_substr($name, 0, 247) . '...';
        }

        $line = static fn (string $label, string $value): string => '<p><strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars($value) . '</p>';
        $html  = $line(__('Embarcação', 'checklistitens'), $vessel);
        $html .= '<p><strong>' . htmlspecialchars(__('Entrega', 'checklistitens')) . ':</strong> <a href="' . htmlspecialchars(DeliveryReport::getUrl($deliveries_id)) . '">#' . $deliveries_id . '</a></p>';
        $html .= $line(__('Recebido por', 'checklistitens'), Ui::text(getUserName((int) $receipt['users_id'])));
        $html .= $line(__('Quando', 'checklistitens'), sprintf(__('Recebimento da embarcação em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($receipt['date_receipt']), Shift::label($receipt['shift_start'])));
        $html .= $line(__('Setor', 'checklistitens'), Sector::getName((int) $receipt['groups_id']));
        foreach ([[__('Baterias devolvidas', 'checklistitens'), (int) $receipt['returned_batteries'], $batteries], [__('Capas devolvidas', 'checklistitens'), (int) $receipt['returned_covers'], $covers]] as [$title, $returned, $list]) {
            if (!count($list)) {
                continue;
            }
            $html .= $line($title, (string) $returned) . '<ul>';
            foreach ($list as $piece) {
                $html .= '<li><strong>' . htmlspecialchars($piece['label']) . ':</strong> ' . htmlspecialchars(implode(', ', $piece['problems'])) . '</li>';
            }
            $html .= '</ul>';
        }
        $comment = Ui::text($receipt['comment'] ?? '');
        if ($comment !== '') {
            $html .= $line(__('Observações do gestor', 'checklistitens'), $comment);
        }
        $html .= '<p><em>' . htmlspecialchars(__('Chamado aberto automaticamente no recebimento da embarcação (Checklist uso de equipamentos). As fotos das peças com defeito estão anexadas.', 'checklistitens')) . '</em></p>';

        // Categoria: a dos problemas marcados, se for uma só; senão, a padrão do rádio
        $problems = [];
        foreach ($pieces as $piece) {
            foreach ($piece['problemtypes_ids'] as $problemtypes_id) {
                $problems[] = ['problemtypes_id' => $problemtypes_id];
            }
        }

        $ticket = new Ticket();
        try {
            $tickets_id = $ticket->add([
                'name'                => Sanitizer::sanitize($name),
                'content'             => Sanitizer::sanitize($html),
                'type'                => Ticket::INCIDENT_TYPE,
                'itilcategories_id'   => self::getCategory(ItemProvider::RADIO, $problems),
                'entities_id'         => (int) $receipt['entities_id'],
                '_users_id_requester' => (int) $receipt['users_id'],
            ]);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: erro ao abrir chamado das peças com defeito: ' . $e->getMessage());
            $tickets_id = false;
        }
        if (!$tickets_id) {
            return 0;
        }

        // Primeiro liga o chamado ao recebimento: se o anexo das fotos falhar, não abre outro
        $DB->update(Install::TABLE_DELIVERYRECEIPTS, ['tickets_id' => (int) $tickets_id], ['id' => $receipts_id]);

        $files = [];
        foreach (DeliveryPhoto::getDefectFiles($deliveries_id, $receipts_id) as $ref => $paths) {
            foreach ($paths as $index => $path) {
                $files[] = [$path, sprintf('foto-%s-%d.%s', str_replace(':', '-', $ref), $index + 1, pathinfo($path, PATHINFO_EXTENSION))];
            }
        }
        self::attachFiles((int) $tickets_id, (int) ($ticket->fields['entities_id'] ?? $receipt['entities_id']), $files);

        return (int) $tickets_id;
    }

    /**
     * Anexa arquivos ao chamado como documentos do GLPI (cópia na pasta temporária, como um envio
     * pela tela). Falhas vão para o log e não desfazem o chamado.
     *
     * @param array<int, array{0: string, 1: string}> $files caminho completo e nome exibido
     */
    private static function attachFiles(int $tickets_id, int $entities_id, array $files): int
    {
        global $CFG_GLPI;

        if (!is_dir(GLPI_TMP_DIR)) {
            Toolbox::logError('checklistitens: diretório temporário do GLPI não existe; fotos não anexadas ao chamado ' . $tickets_id);
            return 0;
        }

        // O GLPI avisa "cópia do documento bem sucedida" a cada arquivo: quem registrou não precisa ver
        $had_messages = isset($_SESSION['MESSAGE_AFTER_REDIRECT']);
        $messages     = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];

        $attached = 0;
        foreach ($files as $index => [$source, $display]) {
            $prefix   = bin2hex(random_bytes(8)) . '_';
            $tmp_path = GLPI_TMP_DIR . '/' . $prefix . $display;
            try {
                if (!copy($source, $tmp_path)) {
                    Toolbox::logError('checklistitens: não foi possível copiar a foto para ' . $tmp_path);
                    continue;
                }
                $documents_id = (int) (new Document())->add([
                    'name'                    => Sanitizer::sanitize(sprintf(__('Foto %1$d - %2$s - Checklist', 'checklistitens'), $index + 1, $display)),
                    'entities_id'             => $entities_id,
                    'is_recursive'            => 0,
                    'tickets_id'              => $tickets_id,
                    'documentcategories_id'   => (int) ($CFG_GLPI['documentcategories_id_forticket'] ?? 0),
                    '_only_if_upload_succeed' => 1,
                    '_filename'               => [$prefix . $display],
                    '_prefix_filename'        => [$prefix],
                ]);
                if ($documents_id <= 0) {
                    Toolbox::logError('checklistitens: não foi possível criar o documento da foto ' . $display . ' (tipo de arquivo permitido?)');
                    continue;
                }
                if (!countElementsInTable(Document_Item::getTable(), ['documents_id' => $documents_id, 'itemtype' => 'Ticket', 'items_id' => $tickets_id])) {
                    (new Document_Item())->add([
                        'documents_id'  => $documents_id,
                        'itemtype'      => 'Ticket',
                        'items_id'      => $tickets_id,
                        'date'          => Shift::now(),
                        '_do_notif'     => false,
                        '_disablenotif' => true,
                    ]);
                }
                $attached++;
            } catch (Throwable $e) {
                Toolbox::logError(sprintf('checklistitens: erro ao anexar a foto %s ao chamado %d: %s', $display, $tickets_id, $e->getMessage()));
            } finally {
                if (is_file($tmp_path)) {
                    @unlink($tmp_path);
                }
            }
        }

        if ($had_messages) {
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages;
        } else {
            unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
        }

        return $attached;
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
