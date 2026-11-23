<?php

namespace GlpiPlugin\Checklistitens;

use Document;
use Document_Item;
use Glpi\Toolbox\Sanitizer;
use Item_Ticket;
use ITILFollowup;
use Session;
use Ticket;
use Throwable;
use Toolbox;

/**
 * Chamados do TI abertos pelo plugin, com texto gerado automaticamente (o gestor não digita nada)
 * e as fotos anexadas como documentos do GLPI: o do item bloqueado, que ganha também o laudo
 * inicial do equipamento, e o das baterias e capas que voltaram com defeito da embarcação.
 */
class TicketFactory
{
    /**
     * Chamado do item bloqueado, vinculado ao registro de uso (e ao ativo, quando o tipo aceita).
     *
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

        $usages_id = (int) $usage->getID();
        $itemtype  = (string) $block->fields['itemtype'];
        $items_id  = (int) $block->fields['items_id'];
        $phase     = ItemBlock::phaseOf($block->fields);
        $problems  = UsageProblem::getFor($usages_id, $phase);
        $item      = ItemProvider::describe($itemtype, $items_id);
        $names     = array_column($problems, 'name');
        $facts     = self::blockFacts($block, $usage, $item);

        $html = '';
        foreach ($facts as $label => $value) {
            $html .= self::line($label, $value);
        }
        $html .= '<p><strong>' . htmlspecialchars(__('Problemas marcados', 'checklistitens')) . ':</strong></p>' . self::bullets($names);

        // O item tinha laudo em aberto e o colaborador disse que era outro problema: o TI vê o histórico
        $laudos = UsageLaudo::getForUsages([$usages_id])[$usages_id][$phase] ?? [];
        if (count($laudos)) {
            $html .= '<p><strong>' . htmlspecialchars(__('Laudos em aberto do equipamento (informado como outro problema)', 'checklistitens')) . ':</strong></p>';
            $html .= self::bullets(array_map(static fn ($laudo) => trim(sprintf(
                '%s · %s · %s %s',
                $laudo['name'],
                $laudo['status'],
                $laudo['date'] !== '' ? Ui::datetime($laudo['date']) : '',
                $laudo['items'] !== '' ? '— ' . $laudo['items'] : ''
            )), $laudos));
        }

        $photos = DefectPhoto::forTicket($usages_id, $phase);
        $html  .= '<p><em>' . htmlspecialchars(__('Chamado aberto na conferência do gestor (Checklist uso de equipamentos). O equipamento fica bloqueado para retirada até este chamado ser solucionado.', 'checklistitens'))
            . (count($photos) ? ' ' . htmlspecialchars(__('As fotos do defeito estão anexadas.', 'checklistitens')) : '') . '</em></p>';

        $ticket = self::create(
            sprintf('[Checklist] %s — %s', $item, implode(', ', $names)),
            $html,
            self::getCategory($itemtype, $problems),
            (int) $block->fields['entities_id'],
            (int) Session::getLoginUserID()
        );
        if ($ticket === null) {
            return 0;
        }
        $tickets_id = (int) $ticket->getID();

        $link = new Item_Ticket();
        $link->add(['tickets_id' => $tickets_id, 'itemtype' => Usage::class, 'items_id' => $usages_id]);
        if (ItemProvider::linksToTicket($itemtype)) {
            $link->add(['tickets_id' => $tickets_id, 'itemtype' => $itemtype, 'items_id' => $items_id]);
        }

        // Primeiro liga o chamado ao bloqueio: se o laudo ou o anexo das fotos falhar no meio, a
        // próxima conferência não abre um chamado repetido
        $block->update([
            'id'         => $block->getID(),
            'tickets_id' => $tickets_id,
            'status'     => ItemBlock::STATUS_REPAIR,
        ]);

        // O problema vai primeiro: é o começo da ocorrência que aparece no alerta de problema
        // conhecido da próxima retirada
        $occurrence = __('Problemas marcados', 'checklistitens') . ': ' . implode('; ', $names);
        foreach ($facts as $label => $value) {
            $occurrence .= "\n" . $label . ': ' . $value;
        }
        $users_id = (int) $usage->fields['users_id'];

        self::quietly(static function () use ($ticket, $block, $users_id, $occurrence, $photos, $usages_id): void {
            $laudo = LaudoProvider::createInitial($block, $users_id, (int) $ticket->getID(), $occurrence);
            if ($laudo !== null) {
                self::addPrivateFollowup($ticket, '<p>' . sprintf(
                    htmlspecialchars(__('Laudo do equipamento criado junto com este chamado: %s.', 'checklistitens')),
                    '<a href="' . htmlspecialchars($laudo['url']) . '">' . htmlspecialchars($laudo['name']) . '</a>'
                ) . '</p>');
            }

            foreach ($photos as $photo) {
                if ($photo['documents_id'] > 0 || $photo['path'] === null) {
                    continue;
                }
                $documents_id = self::attach(
                    $ticket,
                    $photo['path'],
                    sprintf('foto-defeito-%d-%d.%s', $usages_id, $photo['number'], pathinfo($photo['path'], PATHINFO_EXTENSION)),
                    sprintf(__('Foto do defeito %d - Checklist', 'checklistitens'), $photo['number'])
                );
                if ($documents_id > 0) {
                    DefectPhoto::setDocument($photo['id'], $documents_id);
                }
            }
        });

        return $tickets_id;
    }

    /**
     * Relato do problema do item bloqueado, o mesmo no chamado e no laudo: equipamento, setor,
     * colaborador, quando e, na entrega para embarcação, a embarcação. Valores em texto puro.
     *
     * @return array<string, string> rótulo => valor
     */
    private static function blockFacts(ItemBlock $block, Usage $usage, string $item): array
    {
        $phase = ItemBlock::phaseOf($block->fields);
        $when  = $phase === Usage::PHASE_CHECKIN
            ? sprintf(__('Devolução em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkin']), Shift::label($usage->fields['checkin_shift_start']))
            : sprintf(__('Retirada em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkout']), Shift::label($usage->fields['checkout_shift_start']));

        // Problema visto numa entrega para embarcação (na separação ou no recebimento de volta)
        $vessel      = '';
        $delivery_id = (int) ($usage->fields['plugin_checklistitens_deliveries_id'] ?? 0);
        if ($delivery_id > 0) {
            $delivery = Delivery::getRow($delivery_id);
            $vessel   = $delivery ? Ui::text($delivery['vessel_name']) : '';
            $when     = $phase === Usage::PHASE_CHECKIN
                ? sprintf(__('Recebimento da embarcação em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkin']), Shift::label($usage->fields['checkin_shift_start']))
                : sprintf(__('Separação para a embarcação em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($usage->fields['date_checkout']), Shift::label($usage->fields['checkout_shift_start']));
        }

        $facts = [
            __('Equipamento', 'checklistitens') => $item,
            __('Setor', 'checklistitens')       => Sector::getName((int) $block->fields['groups_id']),
            __('Colaborador', 'checklistitens') => Ui::text(getUserName((int) $usage->fields['users_id'])),
            __('Quando', 'checklistitens')      => $when,
        ];
        if ($vessel !== '') {
            $facts[__('Embarcação', 'checklistitens')] = $vessel;
        }

        return $facts;
    }

    /**
     * Acompanhamento privado (só o TI vê), sem e-mail de "chamado atualizado" e sem contar como
     * o chamado atendido, mesmo quando quem confere tem direitos de técnico.
     */
    private static function addPrivateFollowup(Ticket $ticket, string $html): void
    {
        try {
            $added = (new ITILFollowup())->add([
                'itemtype'                        => Ticket::class,
                'items_id'                        => (int) $ticket->getID(),
                'content'                         => Sanitizer::sanitize($html),
                'is_private'                      => 1,
                'users_id'                        => (int) Session::getLoginUserID(),
                '_disablenotif'                   => true,
                '_do_not_compute_takeintoaccount' => true,
            ]);
            if (!$added) {
                Toolbox::logError('checklistitens: não foi possível registrar o acompanhamento no chamado ' . $ticket->getID());
            }
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: erro no acompanhamento do chamado ' . $ticket->getID() . ': ' . $e->getMessage());
        }
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

        $receipt = DeliveryReceipt::getRow($receipts_id);
        if ($receipt === null) {
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
        $delivery      = Delivery::getRow($deliveries_id);
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

        $html  = self::line(__('Embarcação', 'checklistitens'), $vessel);
        $html .= '<p><strong>' . htmlspecialchars(__('Entrega', 'checklistitens')) . ':</strong> <a href="' . htmlspecialchars(DeliveryReport::getUrl($deliveries_id)) . '">#' . $deliveries_id . '</a></p>';
        $html .= self::line(__('Recebido por', 'checklistitens'), Ui::text(getUserName((int) $receipt['users_id'])));
        $html .= self::line(__('Quando', 'checklistitens'), sprintf(__('Recebimento da embarcação em %1$s (turno %2$s)', 'checklistitens'), Ui::datetime($receipt['date_receipt']), Shift::label($receipt['shift_start'])));
        $html .= self::line(__('Setor', 'checklistitens'), Sector::getName((int) $receipt['groups_id']));
        foreach ([[__('Baterias devolvidas', 'checklistitens'), (int) $receipt['returned_batteries'], $batteries], [__('Capas devolvidas', 'checklistitens'), (int) $receipt['returned_covers'], $covers]] as [$title, $returned, $list]) {
            if (!count($list)) {
                continue;
            }
            $html .= self::line($title, (string) $returned) . '<ul>';
            foreach ($list as $piece) {
                $html .= '<li><strong>' . htmlspecialchars($piece['label']) . ':</strong> ' . htmlspecialchars(implode(', ', $piece['problems'])) . '</li>';
            }
            $html .= '</ul>';
        }
        $comment = Ui::text($receipt['comment'] ?? '');
        if ($comment !== '') {
            $html .= self::line(__('Observações do gestor', 'checklistitens'), $comment);
        }
        $html .= '<p><em>' . htmlspecialchars(__('Chamado aberto automaticamente no recebimento da embarcação (Checklist uso de equipamentos). As fotos das peças com defeito estão anexadas.', 'checklistitens')) . '</em></p>';

        // Categoria: a dos problemas marcados, se for uma só; senão, a padrão do rádio
        $problems = [];
        foreach ($pieces as $piece) {
            foreach ($piece['problemtypes_ids'] as $problemtypes_id) {
                $problems[] = ['problemtypes_id' => $problemtypes_id];
            }
        }

        $ticket = self::create(
            sprintf('[Checklist] %s — %s', implode(' e ', $what), $vessel),
            $html,
            self::getCategory(ItemProvider::RADIO, $problems),
            (int) $receipt['entities_id'],
            (int) $receipt['users_id']
        );
        if ($ticket === null) {
            return 0;
        }
        $tickets_id = (int) $ticket->getID();

        // Primeiro liga o chamado ao recebimento: se o anexo das fotos falhar, não abre outro
        $DB->update(DeliveryReceipt::getTable(), ['tickets_id' => $tickets_id], ['id' => $receipts_id]);

        self::quietly(static function () use ($ticket, $deliveries_id, $receipts_id): void {
            $number = 0;
            foreach (DeliveryPhoto::getDefectFiles($deliveries_id, $receipts_id) as $ref => $paths) {
                foreach ($paths as $index => $path) {
                    $number++;
                    $display = sprintf('foto-%s-%d.%s', str_replace(':', '-', $ref), $index + 1, pathinfo($path, PATHINFO_EXTENSION));
                    self::attach($ticket, $path, $display, sprintf(__('Foto %1$d - %2$s - Checklist', 'checklistitens'), $number, $display));
                }
            }
        });

        return $tickets_id;
    }

    /**
     * Abre o chamado de incidente com o texto gerado. O título é cortado no tamanho do campo.
     */
    private static function create(string $name, string $html, int $itilcategories_id, int $entities_id, int $requester): ?Ticket
    {
        if (mb_strlen($name) > 250) {
            $name = mb_substr($name, 0, 247) . '...';
        }

        $ticket = new Ticket();
        try {
            $tickets_id = $ticket->add([
                'name'                => Sanitizer::sanitize($name),
                'content'             => Sanitizer::sanitize($html),
                'type'                => Ticket::INCIDENT_TYPE,
                'itilcategories_id'   => $itilcategories_id,
                'entities_id'         => $entities_id,
                '_users_id_requester' => $requester,
            ]);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: erro ao abrir chamado "' . $name . '": ' . $e->getMessage());
            $tickets_id = false;
        }

        return $tickets_id ? $ticket : null;
    }

    /**
     * Anexa um arquivo ao chamado como documento do GLPI, pelo mesmo caminho dos anexos da tela
     * (CommonDBTM::addFiles): cópia em GLPI_TMP_DIR, Document com _filename e _prefix_filename, e o
     * vínculo Document_Item. O mesmo arquivo que já existe como documento na entidade é
     * reaproveitado, como o GLPI faz. Falhas vão para o log e não desfazem o chamado; o documento
     * fica na entidade do chamado (uma regra pode tê-la mudado).
     *
     * @return int id do documento, ou 0
     */
    private static function attach(Ticket $ticket, string $source, string $display, string $name): int
    {
        global $CFG_GLPI;

        $tickets_id  = (int) $ticket->getID();
        $entities_id = (int) $ticket->fields['entities_id'];
        if (!is_dir(GLPI_TMP_DIR)) {
            Toolbox::logError('checklistitens: diretório temporário do GLPI não existe; foto não anexada ao chamado ' . $tickets_id);
            return 0;
        }

        $prefix   = bin2hex(random_bytes(8)) . '_';
        $tmp_path = GLPI_TMP_DIR . '/' . $prefix . $display;
        try {
            if (!copy($source, $tmp_path)) {
                Toolbox::logError('checklistitens: não foi possível copiar a foto para ' . $tmp_path);
                return 0;
            }

            $doc = new Document();
            if ($doc->getDuplicateOf($entities_id, $tmp_path) && is_file(GLPI_DOC_DIR . '/' . $doc->fields['filepath'])) {
                $documents_id = (int) $doc->fields['id'];
            } else {
                $documents_id = (int) $doc->add([
                    'name'                    => Sanitizer::sanitize($name),
                    'entities_id'             => $entities_id,
                    'is_recursive'            => 0,
                    'tickets_id'              => $tickets_id,
                    'documentcategories_id'   => (int) ($CFG_GLPI['documentcategories_id_forticket'] ?? 0),
                    '_only_if_upload_succeed' => 1,
                    '_filename'               => [$prefix . $display],
                    '_prefix_filename'        => [$prefix],
                ]);
            }
            if ($documents_id <= 0) {
                Toolbox::logError('checklistitens: não foi possível criar o documento da foto ' . $display . ' (tipo de arquivo permitido?)');
                return 0;
            }

            $linked = countElementsInTable(Document_Item::getTable(), ['documents_id' => $documents_id, 'itemtype' => 'Ticket', 'items_id' => $tickets_id]) > 0;
            if (!$linked) {
                $linked = (bool) (new Document_Item())->add([
                    'documents_id'  => $documents_id,
                    'itemtype'      => 'Ticket',
                    'items_id'      => $tickets_id,
                    'date'          => Shift::now(),
                    // sem e-mail de "chamado atualizado" a cada foto
                    '_do_notif'     => false,
                    '_disablenotif' => true,
                ]);
            }
            if (!$linked) {
                Toolbox::logError(sprintf('checklistitens: não foi possível vincular o documento %d ao chamado %d', $documents_id, $tickets_id));
                return 0;
            }

            return $documents_id;
        } catch (Throwable $e) {
            Toolbox::logError(sprintf('checklistitens: erro ao anexar a foto %s ao chamado %d: %s', $display, $tickets_id, $e->getMessage()));
            return 0;
        } finally {
            if (is_file($tmp_path)) {
                @unlink($tmp_path);
            }
        }
    }

    /**
     * O GLPI avisa "cópia do documento bem sucedida" a cada anexo; quem está registrando o uso não
     * precisa ver isso (os erros vão para o log).
     */
    private static function quietly(callable $work): void
    {
        $had_messages = isset($_SESSION['MESSAGE_AFTER_REDIRECT']);
        $messages     = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];

        try {
            $work();
        } finally {
            if ($had_messages) {
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages;
            } else {
                unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
            }
        }
    }

    private static function line(string $label, string $value): string
    {
        return '<p><strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars($value) . '</p>';
    }

    /** @param string[] $items */
    private static function bullets(array $items): string
    {
        return '<ul>' . implode('', array_map(static fn ($item) => '<li>' . htmlspecialchars($item) . '</li>', $items)) . '</ul>';
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
