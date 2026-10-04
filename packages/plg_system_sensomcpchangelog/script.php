<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 *
 * Auto-activation à la première installation (le plugin doit répondre à
 * l'endpoint de changelog dès l'install ; sans effet chez les clients où
 * aucune catégorie ARS n'existe). Pas en update, pour respecter un choix
 * utilisateur. Épinglé tôt dans l'ordre des plugins système : il fait
 * $app->close() sur sa requête et doit passer avant tout court-circuit précoce.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

class PlgSystemSensomcpchangelogInstallerScript
{
    private const FOLDER  = 'system';
    private const ELEMENT = 'sensomcpchangelog';

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

            Factory::getCache('com_plugins')->clean();
        } catch (\Throwable) {
            // Ne jamais faire échouer l'installation pour ça.
        }

        return true;
    }
}
