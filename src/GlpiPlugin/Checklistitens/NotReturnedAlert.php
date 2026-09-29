<?php

namespace GlpiPlugin\Checklistitens;

use CronTask;
use NotificationEvent;

/**
 * Tarefa automática: equipamento retirado num turno que já terminou e ainda não devolvido gera
 * alerta para os gestores do setor e para o TI — um alerta por setor e turno, com a lista.
 * Cada uso é alertado uma vez (date_alert_not_returned); os painéis continuam mostrando.
 */
class NotReturnedAlert
{
    public const CRON_NAME = 'notreturned';

    public static function cronInfo($name)
    {
        if ($name === self::CRON_NAME) {
            return ['description' => __('Checklist uso de equipamentos: alerta de equipamento não devolvido no fim do turno', 'checklistitens')];
        }

        return [];
    }

    public static function cronNotreturned(CronTask $task): int
    {
        global $DB;

        $current_shift = Shift::startFor();

        $iterator = $DB->request([
            'FROM'  => Usage::getTable(),
            'WHERE' => [
                'lock_open'               => 1,
                'date_alert_not_returned' => null,
                'checkout_shift_start'    => ['<', $current_shift],
            ],
            'ORDER' => ['groups_id', 'checkout_shift_start', 'date_checkout'],
        ]);

        // Agrupa por setor e turno de retirada
        $batches = [];
        foreach ($iterator as $row) {
            $key = $row['groups_id'] . '|' . $row['checkout_shift_start'];
            $batches[$key]['groups_id']    = (int) $row['groups_id'];
            $batches[$key]['shift_start']  = (string) $row['checkout_shift_start'];
            $batches[$key]['usages_ids'][] = (int) $row['id'];
        }

        $usage = new Usage();
        $now   = Shift::now();
        $count = 0;

        foreach ($batches as $batch) {
            if (!$usage->getFromDB($batch['usages_ids'][0])) {
                continue;
            }

            NotificationEvent::raiseEvent(NotificationTargetUsage::EVENT_NOT_RETURNED, $usage, [
                'groups_id'   => $batch['groups_id'],
                'shift_start' => $batch['shift_start'],
                'usages_ids'  => $batch['usages_ids'],
            ]);

            foreach ($batch['usages_ids'] as $usage_id) {
                $DB->update(Usage::getTable(), ['date_alert_not_returned' => $now], ['id' => $usage_id]);
                $count++;
            }
        }

        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }
}
