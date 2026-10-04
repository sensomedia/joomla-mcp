<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

\defined('_JEXEC') or die;

/**
 * Aide à la pagination des outils de liste — CONTRAT PUBLIC pour les plugins.
 *
 * Enveloppe cohérente : { <itemsKey>: [...], pagination: {total, count, offset,
 * limit, has_more, next_offset} }. Le total est calculé par un COUNT(*) sur la
 * même requête (clone sans select/order/limit) → pas de double maintenance.
 * Ne pas utiliser sur une requête avec GROUP BY (le COUNT renverrait par groupe).
 */
final class Pagination
{
    /** Borne offset/limit depuis les arguments d'un outil. @return array{0:int,1:int} */
    public static function bounds(array $a, int $default = 100, int $max = 500): array
    {
        $offset = max(0, (int) ($a['offset'] ?? 0));
        $limit  = max(1, min((int) ($a['limit'] ?? $default), $max));

        return [$offset, $limit];
    }

    /**
     * @param callable|null $map  transformation optionnelle de chaque ligne
     */
    public static function envelope(
        DatabaseInterface $db,
        QueryInterface $query,
        int $offset,
        int $limit,
        string $itemsKey,
        ?callable $map = null
    ): array {
        $countQuery = clone $query;
        $countQuery->clear('select')->clear('order')->clear('limit')->select('COUNT(*)');
        $total = (int) $db->setQuery($countQuery)->loadResult();

        $rows = $db->setQuery($query, $offset, $limit)->loadAssocList() ?: [];

        if ($map !== null) {
            $rows = array_map($map, $rows);
        }

        $count = \count($rows);

        return [
            $itemsKey    => $rows,
            'pagination' => [
                'total'       => $total,
                'count'       => $count,
                'offset'      => $offset,
                'limit'       => $limit,
                'has_more'    => ($offset + $count) < $total,
                'next_offset' => ($offset + $count) < $total ? $offset + $count : null,
            ],
        ];
    }

    /** Variante pour une liste déjà en mémoire (ex. fichiers média). */
    public static function slice(array $items, int $offset, int $limit, string $itemsKey): array
    {
        $total = \count($items);
        $page  = \array_slice($items, $offset, $limit);
        $count = \count($page);

        return [
            $itemsKey    => $page,
            'pagination' => [
                'total'       => $total,
                'count'       => $count,
                'offset'      => $offset,
                'limit'       => $limit,
                'has_more'    => ($offset + $count) < $total,
                'next_offset' => ($offset + $count) < $total ? $offset + $count : null,
            ],
        ];
    }
}
