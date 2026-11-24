<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Toolbox\Sanitizer;

/**
 * Leitura dos campos enviados por POST (já tratados pelo GLPI), validada.
 */
class Input
{
    public static function text(string $name): string
    {
        $value = $_POST[$name] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function int(string $name): int
    {
        return (int) self::text($name);
    }

    public static function list(string $name): array
    {
        $value = $_POST[$name] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Resposta "Sim/Não": 1, 0, ou null quando não veio.
     */
    public static function yesNo(string $name): ?int
    {
        $value = $_POST[$name] ?? null;
        if ($value === '1' || $value === 1) {
            return 1;
        }
        if ($value === '0' || $value === 0) {
            return 0;
        }

        return null;
    }

    /**
     * Quantidade de 0 a 99 (campo vazio conta como 0); null quando o valor não serve.
     */
    public static function quantity(string $name): ?int
    {
        $value = $_POST[$name] ?? '';
        $value = is_scalar($value) ? trim((string) $value) : 'x';
        if ($value === '') {
            return 0;
        }

        return preg_match('/^\d{1,2}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Texto livre pronto para gravar: texto puro, cortado em $max caracteres. O corte é feito no
     * texto original, para não partir no meio a codificação que o GLPI aplica.
     */
    public static function comment(string $name, int $max): string
    {
        $raw = $_POST[$name] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        $text = trim((string) Sanitizer::unsanitize($raw));
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        return (string) Sanitizer::sanitize($text);
    }

    /**
     * Ids inteiros, positivos e sem repetição, na ordem em que vieram.
     *
     * @return int[]
     */
    public static function ids(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }
}
