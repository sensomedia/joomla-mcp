<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Model;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Sensomedia\Component\Sensomcp\Administrator\Helper\ComponentParams;
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
     * Params de rate limiting (lus NON cachés via ComponentParams, pour refléter
     * la valeur juste enregistrée).
     *
     * @return array{enabled:bool, max:int, window:int}
     */
    public function getRateLimit(): array
    {
        $params = ComponentParams::get();

        return [
            'enabled' => (int) ($params['ratelimit_enabled'] ?? 1) === 1,
            'max'     => max(1, (int) ($params['ratelimit_max'] ?? 120)),
            'window'  => max(1, (int) ($params['ratelimit_window'] ?? 60)),
        ];
    }
}
