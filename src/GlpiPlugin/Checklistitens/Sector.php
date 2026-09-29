<?php

namespace GlpiPlugin\Checklistitens;

use Group;
use Group_User;

/**
 * Setor = grupos do GLPI. Com a herança ligada (padrão), quem está num grupo pai também
 * enxerga os itens dos grupos filhos.
 */
class Sector
{
    /** @return int[] grupos a que o usuário pertence diretamente */
    public static function getUserGroups(int $users_id): array
    {
        global $DB;

        $groups = [];
        foreach ($DB->request(['SELECT' => 'groups_id', 'FROM' => Group_User::getTable(), 'WHERE' => ['users_id' => $users_id]]) as $row) {
            $groups[] = (int) $row['groups_id'];
        }

        return $groups;
    }

    /** @return int[] grupos do usuário logado (carregados pelo GLPI no login) */
    public static function getCurrentUserGroups(): array
    {
        return array_values(array_filter(array_map('intval', $_SESSION['glpigroups'] ?? [])));
    }

    /**
     * Acrescenta os subgrupos (quando a herança está ligada).
     *
     * @param int[] $groups
     * @return int[]
     */
    public static function expand(array $groups): array
    {
        $all = [];
        foreach ($groups as $group) {
            $group = (int) $group;
            if ($group <= 0) {
                continue;
            }
            $all[$group] = $group;
            if (Config::inheritSubgroups()) {
                foreach (getSonsOf(Group::getTable(), $group) as $son) {
                    $all[(int) $son] = (int) $son;
                }
            }
        }

        return array_values($all);
    }

    /** @return int[] setor do usuário logado, com subgrupos */
    public static function forCurrentUser(): array
    {
        return self::expand(self::getCurrentUserGroups());
    }

    /** @return int[] setor de qualquer usuário, com subgrupos */
    public static function forUser(int $users_id): array
    {
        return self::expand(self::getUserGroups($users_id));
    }

    /**
     * Grupo e seus ancestrais (quem está num ancestral também enxerga o grupo, pela herança).
     *
     * @return int[]
     */
    public static function withAncestors(int $group): array
    {
        $all = [$group => $group];
        if (Config::inheritSubgroups()) {
            foreach (getAncestorsOf(Group::getTable(), $group) as $ancestor) {
                $all[(int) $ancestor] = (int) $ancestor;
            }
        }

        return array_values($all);
    }

    /**
     * Nomes completos dos grupos, para filtros e rótulos.
     *
     * @param int[] $groups
     * @return array<int, string>
     */
    public static function getNames(array $groups): array
    {
        global $DB;

        $groups = array_values(array_filter(array_map('intval', $groups)));
        if (!count($groups)) {
            return [];
        }

        $names = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => Group::getTable(), 'WHERE' => ['id' => $groups], 'ORDER' => 'completename']) as $row) {
            $names[(int) $row['id']] = Ui::text($row['completename']);
        }

        return $names;
    }

    public static function getName(int $group): string
    {
        static $cache = [];

        if ($group <= 0) {
            return '';
        }
        if (!array_key_exists($group, $cache)) {
            $cache[$group] = self::getNames([$group])[$group] ?? '';
        }

        return $cache[$group];
    }
}
