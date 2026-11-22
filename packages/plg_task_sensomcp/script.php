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
use Joomla\CMS\Table\Extension;
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
        // Table « Extension » du cœur (J5/J6), comme l'installeur : sans ACL (installation CLI).
        $table = new Extension($db);

        if ($table->load(['type' => 'plugin', 'folder' => 'task', 'element' => 'sensomcp'])) {
            $table->enabled = 1;
            $table->store();
        }

        // Prise en compte immédiate (le cache com_plugins masque sinon le plugin).
        Factory::getCache('com_plugins')->clean();
    }

    /**
     * Crée la tâche planifiée si aucune du type n'existe. Table « Task » de com_scheduler (J5/J6) :
     * encode les règles en JSON et crée l'asset de la tâche (permissions), ce que l'ancien INSERT
     * direct ne faisait pas (asset_id = 0). Pas de TaskModel : il exige un utilisateur autorisé,
     * absent en installation CLI.
     *
     * @return bool créée ?
     */
    private function ensureTask(DatabaseInterface $db): bool
    {
        $table = Factory::getApplication()->bootComponent('com_scheduler')
            ->getMVCFactory()->createTable('Task', 'Administrator');

        if (!$table) {
            throw new \RuntimeException('com_scheduler task table unavailable');
        }

        if ($table->load(['type' => self::TASK_TYPE])) {
            return false;
        }

        $utc  = new \DateTimeZone('UTC');
        $now  = new \DateTime('now', $utc);
        $next = (new \DateTime('now', $utc))->setTime(3, 30, 0);

        if ($next <= $now) {
            $next->modify('+1 day');
        }

        $table->reset();
        $table->id = null;

        $ok = $table->bind([
            'title'           => 'Senso MCP maintenance',
            'type'            => self::TASK_TYPE,
            'execution_rules' => ['rule-type' => 'interval-days', 'interval-days' => 1, 'exec-time' => '03:30', 'exec-day' => '01'],
            'cron_rules'      => ['type' => 'interval', 'exp' => 'P1D'],
            'state'           => 1,
            'last_exit_code'  => 0,
            'next_execution'  => $next->format('Y-m-d H:i:s'),
            'times_executed'  => 0,
            'times_failed'    => 0,
            'locked'          => null,
            'priority'        => 0,
            'ordering'        => 0,
            'cli_exclusive'   => 0,
            'params'          => ['log_retention_days' => 30],
            'note'            => '',
            'created'         => $now->format('Y-m-d H:i:s'),
            'created_by'      => 0,
        ]) && $table->check() && $table->store();

        if (!$ok) {
            throw new \RuntimeException('task not created: ' . $table->getError());
        }

        return true;
    }
}
