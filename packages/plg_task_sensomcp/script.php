<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 *
 * Installer script du plugin task. À la PREMIÈRE installation seulement
 * (pas en update, pour respecter un éventuel choix utilisateur) :
 *   1. active le plugin lui-même ;
 *   2. crée et programme une tâche planifiée « sensomcp.cleanup » (quotidienne),
 *      de façon idempotente (aucun doublon si une tâche du type existe déjà).
 * Objectif : rien à faire manuellement après l'installation.
 *
 * Classe attendue par Joomla : Plg + groupe + élément + InstallerScript
 * (insensible à la casse).
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

class PlgTaskSensomcpInstallerScript
{
    private const TASK_TYPE = 'sensomcp.cleanup';

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!\in_array($type, ['install', 'discover_install'], true)) {
            return true;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $this->enableSelf($db);
            $created = $this->ensureTask($db);

            Factory::getApplication()->enqueueMessage(
                $created
                    ? 'Senso MCP : plugin de maintenance activé, tâche planifiée quotidienne créée. Rien à configurer.'
                    : 'Senso MCP : plugin de maintenance activé (une tâche de maintenance existe déjà).',
                'info'
            );
        } catch (\Throwable $e) {
            // Ne jamais faire échouer l'installation pour ça.
            try {
                Factory::getApplication()->enqueueMessage(
                    'Senso MCP : activation auto de la tâche impossible (' . $e->getMessage()
                    . '). Activez le plugin « Task - Maintenance Senso MCP » et créez une tâche planifiée.',
                    'warning'
                );
            } catch (\Throwable) {
                // silencieux
            }
        }

        return true;
    }

    /** Active le plugin task/sensomcp (installé désactivé par défaut). */
    private function enableSelf(DatabaseInterface $db): void
    {
        $q = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('sensomcp'));

        $db->setQuery($q)->execute();

        // Prise en compte immédiate (le cache com_plugins masque sinon le plugin).
        Factory::getCache('com_plugins')->clean();
    }

    /** Crée la tâche planifiée si aucune du type n'existe. @return bool créée ? */
    private function ensureTask(DatabaseInterface $db): bool
    {
        $q = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__scheduler_tasks'))
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TASK_TYPE));

        if ((int) $db->setQuery($q)->loadResult() > 0) {
            return false;
        }

        $utc  = new \DateTimeZone('UTC');
        $now  = new \DateTime('now', $utc);
        $next = (new \DateTime('now', $utc))->setTime(3, 30, 0);

        if ($next <= $now) {
            $next->modify('+1 day');
        }

        $columns = [
            'asset_id', 'title', 'type', 'execution_rules', 'cron_rules', 'state', 'last_exit_code',
            'next_execution', 'times_executed', 'times_failed', 'locked', 'priority', 'ordering',
            'cli_exclusive', 'params', 'note', 'created', 'created_by',
        ];

        $values = implode(',', [
            0,
            $db->quote('Senso MCP maintenance'),
            $db->quote(self::TASK_TYPE),
            $db->quote(json_encode(['rule-type' => 'interval-days', 'interval-days' => 1, 'exec-time' => '03:30', 'exec-day' => '01'])),
            $db->quote(json_encode(['type' => 'interval', 'exp' => 'P1D'])),
            1,
            0,
            $db->quote($next->format('Y-m-d H:i:s')),
            0,
            0,
            'NULL',
            0,
            0,
            0,
            $db->quote(json_encode(['log_retention_days' => 30])),
            $db->quote(''),
            $db->quote($now->format('Y-m-d H:i:s')),
            0,
        ]);

        $q = $db->getQuery(true)
            ->insert($db->quoteName('#__scheduler_tasks'))
            ->columns($db->quoteName($columns))
            ->values($values);

        $db->setQuery($q)->execute();

        return true;
    }
}
