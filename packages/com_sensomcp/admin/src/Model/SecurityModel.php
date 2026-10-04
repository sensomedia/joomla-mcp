<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Model;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolState;

\defined('_JEXEC') or die;

/**
 * Données de la vue Sécurité : kill-switch lecture seule, arrêt d'urgence et
 * limitation de débit. (La gestion plugins/outils/catalogue est dans PluginsModel.)
 */
class SecurityModel extends BaseDatabaseModel
{
    public function getReadOnly(): bool
    {
        return ToolState::isReadOnly();
    }

    /** Arrêt d'urgence actif (serveur MCP verrouillé) ? */
    public function getEmergencyStop(): bool
    {
        return ToolState::isLocked();
    }

    /**
     * Params de rate limiting (lus NON cachés depuis #__extensions, pour refléter
     * la valeur juste enregistrée).
     *
     * @return array{enabled:bool, max:int, window:int}
     */
    public function getRateLimit(): array
    {
        $db     = $this->getDatabase();
        $params = json_decode((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
        )->loadResult(), true) ?: [];

        return [
            'enabled' => (int) ($params['ratelimit_enabled'] ?? 1) === 1,
            'max'     => max(1, (int) ($params['ratelimit_max'] ?? 120)),
            'window'  => max(1, (int) ($params['ratelimit_window'] ?? 60)),
        ];
    }
}
