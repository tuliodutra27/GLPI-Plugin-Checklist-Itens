<?php

namespace GlpiPlugin\Checklistitens;

use Session;

/**
 * Turnos: começam nos horários configurados (padrão 07:00 e 19:00) e vão até o próximo início.
 * Um turno pertence à data em que começou: 02h de 30/09 é do turno das 19h de 29/09.
 */
class Shift
{
    public static function now(): string
    {
        return Session::getCurrentTime() ?? date('Y-m-d H:i:s');
    }

    /** Início do turno que contém a data/hora informada (padrão: agora). */
    public static function startFor(?string $datetime = null): string
    {
        $ts   = strtotime($datetime ?? self::now());
        $best = null;

        foreach ([-1, 0] as $offset) {
            $day = date('Y-m-d', strtotime(sprintf('%+d day', $offset), $ts));
            foreach (Config::getShiftStarts() as $hm) {
                $candidate = strtotime("$day $hm:00");
                if ($candidate <= $ts && ($best === null || $candidate > $best)) {
                    $best = $candidate;
                }
            }
        }

        return date('Y-m-d H:i:s', $best ?? $ts);
    }

    /** Fim do turno (= início do turno seguinte). */
    public static function endFor(string $shift_start): string
    {
        $ts   = strtotime($shift_start);
        $best = null;

        foreach ([0, 1] as $offset) {
            $day = date('Y-m-d', strtotime(sprintf('%+d day', $offset), $ts));
            foreach (Config::getShiftStarts() as $hm) {
                $candidate = strtotime("$day $hm:00");
                if ($candidate > $ts && ($best === null || $candidate < $best)) {
                    $best = $candidate;
                }
            }
        }

        return date('Y-m-d H:i:s', $best ?? ($ts + 12 * HOUR_TIMESTAMP));
    }

    /** Ex.: "29/09, 07h–19h" */
    public static function label(?string $shift_start): string
    {
        if (!$shift_start) {
            return '';
        }

        $start = strtotime($shift_start);
        $end   = strtotime(self::endFor($shift_start));

        return sprintf('%s, %s–%s', date('d/m', $start), self::formatHour($start), self::formatHour($end));
    }

    public static function isCurrent(?string $shift_start): bool
    {
        return $shift_start !== null && $shift_start === self::startFor();
    }

    private static function formatHour(int $ts): string
    {
        $minutes = date('i', $ts);

        return date('H', $ts) . 'h' . ($minutes !== '00' ? $minutes : '');
    }
}
