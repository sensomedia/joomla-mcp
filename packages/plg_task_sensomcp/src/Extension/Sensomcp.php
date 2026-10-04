<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Task\Sensomcp\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Tâche planifiée d'entretien de Senso MCP : purge des codes d'autorisation
 * OAuth expirés, des refresh tokens expirés/révoqués et des journaux au-delà
 * d'une rétention configurable. Apparaît dans Système → Tâches planifiées.
 *
 * DatabaseAwareTrait importé depuis Joomla\Database (déplacé en Joomla 6 ;
 * CMSPlugin ne l'inclut plus).
 */
final class Sensomcp extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    protected const TASKS_MAP = [
        'sensomcp.cleanup' => [
            'langConstPrefix' => 'PLG_TASK_SENSOMCP_CLEANUP',
            'form'            => 'cleanup',
            'method'          => 'runCleanup',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function runCleanup(ExecuteTaskEvent $event): int
    {
        $params  = $event->getArgument('params') ?? new \stdClass();
        $logDays = max(0, (int) ($params->log_retention_days ?? 30));

        $db  = $this->getDatabase();
        $log = [];

        // Les DATETIME sont stockés en UTC → comparaison à UTC_TIMESTAMP().
        $codes = $this->deleteWhere(
            '#__sensomcp_oauth_codes',
            $db->quoteName('expires') . ' < UTC_TIMESTAMP()'
        );
        $log[] = "OAuth codes expirés purgés : $codes";

        $refresh = $this->deleteWhere(
            '#__sensomcp_oauth_refresh',
            '(' . $db->quoteName('expires') . ' < UTC_TIMESTAMP() OR ' . $db->quoteName('revoked') . ' = 1)'
        );
        $log[] = "Refresh tokens expirés/révoqués purgés : $refresh";

        if ($logDays > 0) {
            // $logDays est un entier casté → interpolation sûre dans l'INTERVAL.
            $logs  = $this->deleteWhere(
                '#__sensomcp_log',
                $db->quoteName('created') . ' < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $logDays . ' DAY)'
            );
            $log[] = "Journaux (> $logDays j) purgés : $logs";
        } else {
            $log[] = 'Journaux : rétention illimitée (0)';
        }

        // Fenêtres de rate limiting périmées (> 1 jour) — la table est de toute
        // façon minuscule (1 ligne par identité active), mais on nettoie les
        // identités inactives. window_start = timestamp Unix (secondes).
        if ($this->tableExists('#__sensomcp_ratelimit')) {
            $rl    = $this->deleteWhere(
                '#__sensomcp_ratelimit',
                $db->quoteName('window_start') . ' < ' . (time() - 86400)
            );
            $log[] = "Fenêtres de débit périmées purgées : $rl";
        }

        $this->logTask(implode(' | ', $log));

        return TaskStatus::OK;
    }

    /** La table existe-t-elle (installs antérieures au rate limiting) ? */
    private function tableExists(string $table): bool
    {
        $db     = $this->getDatabase();
        $prefix = $db->getPrefix();
        $real   = str_replace('#__', $prefix, $table);

        return \in_array($real, $db->getTableList(), true);
    }

    /** DELETE ... WHERE ; renvoie le nombre de lignes supprimées. */
    private function deleteWhere(string $table, string $where): int
    {
        $db = $this->getDatabase();
        $q  = $db->getQuery(true)
            ->delete($db->quoteName($table))
            ->where($where);

        $db->setQuery($q)->execute();

        return (int) $db->getAffectedRows();
    }
}
