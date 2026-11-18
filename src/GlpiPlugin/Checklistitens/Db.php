<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Throwable;
use Toolbox;

/**
 * Gravação no banco do jeito que os fluxos do plugin precisam: erro de banco (ex.: índice único)
 * não vira erro de tela, várias gravações valem juntas ou nenhuma, e ações concorrentes sobre o
 * mesmo registro esperam a vez.
 */
class Db
{
    /**
     * add() que devolve 0 em vez de deixar o erro de banco chegar à tela.
     */
    public static function add(CommonDBTM $item, array $input): int
    {
        try {
            $id = $item->add($input);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
            $id = false;
        }

        return $id ? (int) $id : 0;
    }

    /**
     * Inserção direta (tabela sem formulário no GLPI); devolve o id criado, ou 0.
     */
    public static function insert(string $table, array $fields): int
    {
        global $DB;

        try {
            $ok = $DB->insert($table, $fields);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
            $ok = false;
        }

        return $ok ? (int) $DB->insertId() : 0;
    }

    /**
     * Grava várias coisas como uma só: ou todas ficam, ou nenhuma.
     *
     * @param callable(): bool $work devolve false para desfazer
     */
    public static function atomically(callable $work): bool
    {
        global $DB;

        if ($DB->inTransaction()) {
            return (bool) $work();
        }

        $DB->beginTransaction();
        try {
            $done = (bool) $work();
        } catch (Throwable $e) {
            $DB->rollBack();
            Toolbox::logError('checklistitens: ' . $e->getMessage());

            return false;
        }
        if (!$done) {
            $DB->rollBack();

            return false;
        }

        return (bool) $DB->commit();
    }

    /**
     * Executa $work com uma trava do banco: duas sessões não fazem a mesma ação ao mesmo tempo.
     * Sem como pedir a trava (erro do banco), segue sem ela: os índices únicos e as conferências de
     * situação continuam valendo.
     *
     * @param callable(): array $work
     * @param callable(): array $busy resposta quando outra sessão está com a trava
     */
    public static function locked(string $name, callable $work, callable $busy): array
    {
        global $DB;

        try {
            $free = $DB->getLock($name);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());

            return $work();
        }
        if (!$free) {
            return $busy();
        }

        try {
            return $work();
        } finally {
            try {
                $DB->releaseLock($name);
            } catch (Throwable $e) {
                // a trava termina com a conexão de qualquer forma
            }
        }
    }

    /**
     * Resposta Sim/Não (ou vazia) para gravar com update(). Vai como texto: o update() do GLPI
     * trata coluna vazia (NULL) e o número 0 como iguais, então o "Não" em número não chegaria ao
     * banco.
     */
    public static function yesNo(?int $value): string
    {
        return $value === null ? 'NULL' : ($value ? '1' : '0');
    }

    /**
     * Restrição das entidades do usuário para uma tabela. Quem vê todas as entidades recebe uma
     * restrição vazia, que viraria "AND ()" (SQL inválido) se entrasse no WHERE como está; por isso
     * só entra quando tem conteúdo.
     */
    public static function addEntityRestriction(array &$where, string $table, bool $recursive): void
    {
        $restriction = getEntitiesRestrictCriteria($table, '', '', $recursive);
        if (count($restriction)) {
            $where[] = $restriction;
        }
    }
}
