<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Journalisation des requêtes MCP (pour le tableau de bord admin).
 * Best-effort : n'interrompt jamais une requête en cas d'erreur.
 */
final class RequestLog
{
    public static function record(int $userId, string $ip, string $method, ?string $tool, bool $success, ?string $error = null): void
    {
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = (object) [
                'created' => Factory::getDate()->toSql(),
                'user_id' => $userId,
                'ip'      => substr($ip, 0, 45),
                'method'  => substr($method, 0, 100),
                'tool'    => $tool !== null ? substr($tool, 0, 100) : null,
                'success' => $success ? 1 : 0,
                'error'   => $error !== null ? substr($error, 0, 255) : null,
            ];

            $db->insertObject('#__sensomcp_log', $row);
        } catch (\Throwable) {
            // Le journal ne doit jamais casser le service.
        }
    }
}
