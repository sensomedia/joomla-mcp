<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Aide au ré-ordonnancement d'éléments partageant une colonne `ordering` —
 * CONTRAT PUBLIC pour les plugins. « Placer X avant/après Y » : on réinsère X
 * dans la liste ordonnée des frères puis on renumérote séquentiellement.
 */
final class Ordering
{
    /**
     * @param int[]    $siblings ids des frères dans l'ordre courant
     * @param int|null $before   placer moveId juste AVANT cet id
     * @param int|null $after    placer moveId juste APRÈS cet id
     *
     * @return int[] nouvel ordre des ids
     */
    public static function apply(
        DatabaseInterface $db,
        string $table,
        string $idColumn,
        string $orderColumn,
        array $siblings,
        int $moveId,
        ?int $before,
        ?int $after
    ): array {
        $siblings = array_values(array_unique(array_map('intval', $siblings)));

        if (!\in_array($moveId, $siblings, true)) {
            throw new McpToolException("Item $moveId is not part of the reorderable set.");
        }

        $ref = $before ?: $after;

        if (!$ref) {
            throw new McpToolException("Provide 'before_id' or 'after_id'.");
        }

        $ref = (int) $ref;

        if ($ref === $moveId) {
            throw new McpToolException('The reference must be a different item.');
        }

        if (!\in_array($ref, $siblings, true)) {
            throw new McpToolException(
                "Reference $ref is not in the same set as $moveId (different category, position or group?)."
            );
        }

        // Retire l'élément déplacé, puis l'insère avant/après la référence.
        $siblings = array_values(array_filter($siblings, static fn ($x) => $x !== $moveId));
        $pos      = array_search($ref, $siblings, true);
        $insertAt = $before ? $pos : $pos + 1;
        array_splice($siblings, $insertAt, 0, [$moveId]);

        // Renumérotation séquentielle (1..N).
        foreach ($siblings as $i => $sid) {
            $order = $i + 1;
            $q     = $db->getQuery(true)
                ->update($db->quoteName($table))
                ->set($db->quoteName($orderColumn) . ' = ' . $order)
                ->where($db->quoteName($idColumn) . ' = ' . (int) $sid);
            $db->setQuery($q)->execute();
        }

        return $siblings;
    }
}
