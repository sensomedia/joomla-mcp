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
            $q  = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote(self::FOLDER))
                ->where($db->quoteName('element') . ' = ' . $db->quote(self::ELEMENT));
            $db->setQuery($q)->execute();

            // Prise en compte immédiate (le cache com_plugins masque sinon le plugin).
            Factory::getCache('com_plugins')->clean();
        } catch (\Throwable) {
            // Ne jamais faire échouer l'installation pour ça.
        }

        return true;
    }
}
