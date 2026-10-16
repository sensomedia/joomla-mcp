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
        $logDays    = max(0, (int) ($params->log_retention_days ?? 30));
        $clientDays = max(0, (int) ($params->oauth_client_retention_days ?? 90));

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

        // Clients OAuth enregistrés dynamiquement (auth « none »), inactifs : aucun refresh
        // token valide ni code en attente, créés il y a plus de N jours (audit 2026-10-12).
        if ($clientDays > 0) {
            $clients = $this->purgeIdleClients($clientDays, false);
            $log[]   = "Clients OAuth inactifs (> $clientDays j) purgés : $clients";
        } else {
            $log[] = 'Clients OAuth : rétention illimitée (0)';
        }

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

    /**
     * Clients OAuth dynamiques inactifs : auth « none », aucun refresh token valide,
     * aucun code d'autorisation en attente, créés il y a plus de `$days` jours.
     * `$dryRun` = compter sans supprimer. Renvoie le nombre de lignes concernées.
     */
    public function purgeIdleClients(int $days, bool $dryRun): int
    {
        $db   = $this->getDatabase();
        $days = max(1, $days);
        $from = $db->quoteName('#__sensomcp_oauth_clients', 'c')
            . ' LEFT JOIN ' . $db->quoteName('#__sensomcp_oauth_refresh', 'r')
            . ' ON ' . $db->quoteName('r.client_id') . ' = ' . $db->quoteName('c.client_id')
            . ' AND ' . $db->quoteName('r.revoked') . ' = 0 AND ' . $db->quoteName('r.expires') . ' >= UTC_TIMESTAMP()'
            . ' LEFT JOIN ' . $db->quoteName('#__sensomcp_oauth_codes', 'k')
            . ' ON ' . $db->quoteName('k.client_id') . ' = ' . $db->quoteName('c.client_id')
            . ' AND ' . $db->quoteName('k.expires') . ' >= UTC_TIMESTAMP()';
        $where = $db->quoteName('r.id') . ' IS NULL AND ' . $db->quoteName('k.id') . ' IS NULL'
            . ' AND ' . $db->quoteName('c.token_endpoint_auth_method') . ' = ' . $db->quote('none')
            . ' AND ' . $db->quoteName('c.created') . ' < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)';

        if ($dryRun) {
            return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where)->loadResult();
        }

        $db->setQuery('DELETE c FROM ' . $from . ' WHERE ' . $where)->execute();

        return (int) $db->getAffectedRows();
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
