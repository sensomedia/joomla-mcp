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
 * Persistance de l'état actif/inactif par outil (table #__sensomcp_tool_state).
 * Source de vérité unique du panneau « Plugins et sécurité », appliquée par le
 * moteur (ToolRegistry::setToolPolicy). Une entrée absente = état par défaut de
 * l'outil (déclaré à l'enregistrement).
 */
final class ToolState
{
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Overrides explicites tool => bool. Best-effort : renvoie [] si la table
     * n'existe pas encore (install en cours de mise à niveau).
     *
     * @return array<string,bool>
     */
    public static function getMap(): array
    {
        try {
            $db = self::db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName(['tool', 'enabled']))
                ->from($db->quoteName('#__sensomcp_tool_state'));

            $map = [];

            foreach ($db->setQuery($q)->loadAssocList() ?: [] as $row) {
                $map[(string) $row['tool']] = (bool) $row['enabled'];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Mode lecture seule global, lu SANS cache (directement dans les params du
     * composant). Un kill-switch de sécurité doit prendre effet immédiatement,
     * pas après expiration du cache _system utilisé par ComponentHelper.
     */
    public static function isReadOnly(): bool
    {
        return !empty(self::componentParams()['readonly']);
    }

    /**
     * Arrêt d'urgence : quand actif, le point d'accès MCP refuse TOUTE requête
     * (verrou global, réversible). Lu NON caché pour prise en compte immédiate.
     */
    public static function isLocked(): bool
    {
        return !empty(self::componentParams()['emergency_stop']);
    }

    /**
     * Point de révocation OAuth (timestamp Unix) : tout access token JWT émis
     * AVANT est rejeté. 0 = pas de révocation. Lu NON caché (effet immédiat).
     */
    public static function oauthRevokeBefore(): int
    {
        return (int) (self::componentParams()['oauth_revoke_before'] ?? 0);
    }

    /** Params composant lus SANS cache (kill-switches instantanés). */
    private static function componentParams(): array
    {
        try {
            $db = self::db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'));

            $params = json_decode((string) $db->setQuery($q)->loadResult(), true);

            return \is_array($params) ? $params : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Fixe l'état d'un outil (upsert). */
    public static function set(string $tool, bool $enabled): void
    {
        $db      = self::db();
        $enabled = $enabled ? 1 : 0;

        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__sensomcp_tool_state'))
                ->where($db->quoteName('tool') . ' = :tool')
                ->bind(':tool', $tool, ParameterType::STRING)
        )->loadResult();

        if ($exists) {
            $q = $db->getQuery(true)
                ->update($db->quoteName('#__sensomcp_tool_state'))
                ->set($db->quoteName('enabled') . ' = :enabled')
                ->where($db->quoteName('tool') . ' = :tool')
                ->bind(':enabled', $enabled, ParameterType::INTEGER)
                ->bind(':tool', $tool, ParameterType::STRING);
        } else {
            $q = $db->getQuery(true)
                ->insert($db->quoteName('#__sensomcp_tool_state'))
                ->columns($db->quoteName(['tool', 'enabled']))
                ->values(':tool, :enabled')
                ->bind(':tool', $tool, ParameterType::STRING)
                ->bind(':enabled', $enabled, ParameterType::INTEGER);
        }

        $db->setQuery($q)->execute();
    }
}
