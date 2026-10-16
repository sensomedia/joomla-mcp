<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Limiteur de débit du point d'accès MCP — fenêtre fixe en base
 * (#__sensomcp_ratelimit), compteur incrémenté atomiquement via
 * ON DUPLICATE KEY UPDATE. Protège la disponibilité (LLM en boucle, jeton
 * fuité) là où l'OAuth/l'ACL protègent l'autorisation.
 *
 * Clé = identité du porteur (user_id), repli sur l'IP si non authentifié.
 * Fail-open : toute erreur (table absente…) ne doit PAS bloquer le service.
 */
final class RateLimiter
{
    /**
     * @return array{retry_after:int}|null  null = autorisé ; sinon = dépassé.
     */
    public static function check(int $userId, string $ip, int $weight = 1): ?array
    {
        $weight = max(1, $weight);

        $params = ComponentHelper::getParams('com_sensomcp');

        if ((int) $params->get('ratelimit_enabled', 1) !== 1) {
            return null;
        }

        $max    = max(1, (int) $params->get('ratelimit_max', 120));
        $window = max(1, (int) $params->get('ratelimit_window', 60));

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $now      = time();
            $winStart = $now - ($now % $window);
            $key      = $db->quote($userId > 0 ? 'u:' . $userId : 'ip:' . $ip);
            $table    = $db->quoteName('#__sensomcp_ratelimit');

            // Incrément atomique ; réinitialise à 1 quand la fenêtre a tourné.
            $db->setQuery(
                "INSERT INTO $table (" . $db->quoteName('rl_key') . ', '
                . $db->quoteName('window_start') . ', ' . $db->quoteName('hits')
                . ") VALUES ($key, $winStart, $weight) "
                . 'ON DUPLICATE KEY UPDATE '
                . $db->quoteName('hits') . ' = IF(' . $db->quoteName('window_start') . " = $winStart, "
                . $db->quoteName('hits') . " + $weight, $weight), "
                . $db->quoteName('window_start') . " = $winStart"
            )->execute();

            $hits = (int) $db->setQuery(
                "SELECT " . $db->quoteName('hits') . " FROM $table WHERE " . $db->quoteName('rl_key') . " = $key"
            )->loadResult();

            if ($hits > $max) {
                return ['retry_after' => max(1, ($winStart + $window) - $now)];
            }
        } catch (\Throwable) {
            // Fail-open : ne jamais rompre le service à cause du limiteur.
            return null;
        }

        return null;
    }
}
