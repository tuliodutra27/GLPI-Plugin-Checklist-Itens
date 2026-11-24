<?php

namespace GlpiPlugin\Checklistitens;

/**
 * Respostas das ações que o aparelho envia e repete (entrega, recebimento, fotos): sempre com "ok"
 * e "message"; os erros dizem em "retry" se vale reenviar o mesmo pedido.
 */
class Reply
{
    public static function ok(array $extra = []): array
    {
        return $extra + ['ok' => true, 'message' => ''];
    }

    /**
     * Erro de regra: o aparelho não deve reenviar o mesmo pedido.
     */
    public static function fail(string $message, array $extra = []): array
    {
        return ['ok' => false, 'message' => $message, 'retry' => false] + $extra;
    }

    /**
     * Falha passageira (banco, concorrência, envio cortado): o aparelho tenta de novo sozinho.
     */
    public static function retry(string $message, array $extra = []): array
    {
        return ['ok' => false, 'message' => $message, 'retry' => true] + $extra;
    }
}
