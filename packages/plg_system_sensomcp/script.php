<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 *
 * Auto-activation à la première installation : le plugin s'active lui-même
 * pour que la découverte OAuth (.well-known) réponde dès l'install.
 * Pas en update, pour respecter un éventuel choix utilisateur.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

class PlgSystemSensomcpInstallerScript
{
    private const FOLDER  = 'system';
    private const ELEMENT = 'sensomcp';

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!\in_array($type, ['install', 'discover_install'], true)) {
            return true;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote(self::FOLDER))
                    ->where($db->quoteName('element') . ' = ' . $db->quote(self::ELEMENT))
            )->execute();

            // Épingler en TÊTE des plugins système : ce plugin sert /.well-known/
            // et fait $app->close() ; le passer avant tout plugin qui court-
            // circuiterait tôt onAfterInitialise (maintenance, redirection, cache
            // qui sert la page). Robustesse par construction, sans action admin.
            $min = $db->setQuery(
                $db->getQuery(true)
                    ->select('MIN(' . $db->quoteName('ordering') . ')')
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote(self::FOLDER))
                    ->where($db->quoteName('element') . ' <> ' . $db->quote(self::ELEMENT))
            )->loadResult();

            $ordering = $min === null ? 0 : ((int) $min - 1);

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('ordering') . ' = ' . $ordering)
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote(self::FOLDER))
                    ->where($db->quoteName('element') . ' = ' . $db->quote(self::ELEMENT))
            )->execute();

            // Prise en compte immédiate (le cache com_plugins masque sinon le plugin).
            Factory::getCache('com_plugins')->clean();
        } catch (\Throwable) {
            // Ne jamais faire échouer l'installation pour ça.
        }

        return true;
    }
}
