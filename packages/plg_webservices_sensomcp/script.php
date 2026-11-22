<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 *
 * Auto-activation à la première installation : le plugin s'active lui-même
 * pour que le MCP soit opérationnel dès l'install (rien à activer à la main).
 * Pas en update, pour respecter un éventuel choix utilisateur.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Table\Extension;
use Joomla\Database\DatabaseInterface;

class PlgWebservicesSensomcpInstallerScript
{
    private const FOLDER  = 'webservices';
    private const ELEMENT = 'sensomcp';

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!\in_array($type, ['install', 'discover_install'], true)) {
            return true;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            // Table « Extension » du cœur (J5/J6), comme l'installeur : sans ACL (installation CLI).
            $table = new Extension($db);

            if ($table->load(['type' => 'plugin', 'folder' => self::FOLDER, 'element' => self::ELEMENT])) {
                $table->enabled = 1;
                $table->store();
            }

            // Prise en compte immédiate (le cache com_plugins masque sinon le plugin).
            Factory::getCache('com_plugins')->clean();
        } catch (\Throwable) {
            // Ne jamais faire échouer l'installation pour ça.
        }

        return true;
    }
}
