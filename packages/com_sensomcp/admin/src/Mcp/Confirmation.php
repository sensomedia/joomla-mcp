<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Jetons de confirmation des outils « à confirmer » (meta `confirm` du registre).
 *
 * Premier appel d'un tel outil : aperçu + jeton. Second appel avec les MÊMES arguments
 * et `confirmation_token` : exécution. Le jeton est aléatoire, lié à l'utilisateur, à
 * l'outil et à l'empreinte des arguments, valable TTL secondes et à usage unique (la
 * consommation est un DELETE atomique : un rejeu ou un appel concurrent échoue).
 * Seule l'empreinte SHA-256 du jeton est stockée.
 */
final class Confirmation
{
    public const TTL = 300;

    public const ARG = 'confirmation_token';

    private const TABLE = '#__sensomcp_confirmations';

    /** Émet un jeton pour (utilisateur, outil, arguments). */
    public static function issue(int $userId, string $tool, array $args): string
    {
        $db    = self::db();
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $hash  = hash('sha256', $token);
        $args  = self::argsHash($args);
        $exp   = time() + self::TTL;

        // Purge des jetons expirés (table minuscule, pas de tâche dédiée nécessaire).
        $now = time();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('expires') . ' < :now')->bind(':now', $now, ParameterType::INTEGER))->execute();

        $q = $db->getQuery(true)
            ->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName(['token_hash', 'user_id', 'tool', 'args_hash', 'expires']))
            ->values(':h, :u, :t, :a, :e')
            ->bind(':h', $hash)
            ->bind(':u', $userId, ParameterType::INTEGER)
            ->bind(':t', $tool)
            ->bind(':a', $args)
            ->bind(':e', $exp, ParameterType::INTEGER);
        $db->setQuery($q)->execute();

        return $token;
    }

    /**
     * Consomme un jeton (usage unique). Faux si inconnu, expiré, déjà utilisé, émis pour
     * un autre utilisateur ou un autre outil, ou si les arguments ont changé.
     */
    public static function consume(string $token, int $userId, string $tool, array $args): bool
    {
        $token = trim($token);

        if ($token === '') {
            return false;
        }

        $db   = self::db();
        $hash = hash('sha256', $token);
        $argh = self::argsHash($args);
        $now  = time();

        $q = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('token_hash') . ' = :h')
            ->where($db->quoteName('user_id') . ' = :u')
            ->where($db->quoteName('tool') . ' = :t')
            ->where($db->quoteName('args_hash') . ' = :a')
            ->where($db->quoteName('expires') . ' >= :now')
            ->bind(':h', $hash)
            ->bind(':u', $userId, ParameterType::INTEGER)
            ->bind(':t', $tool)
            ->bind(':a', $argh)
            ->bind(':now', $now, ParameterType::INTEGER);
        $db->setQuery($q)->execute();

        return $db->getAffectedRows() === 1;
    }

    /** Empreinte stable des arguments (clés triées récursivement, jeton exclu). */
    public static function argsHash(array $args): string
    {
        unset($args[self::ARG]);

        return hash('sha256', (string) json_encode(self::normalise($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalise(mixed $value): mixed
    {
        if (\is_object($value)) {
            $value = (array) $value;
        }

        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'normalise'], $value);
    }

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
