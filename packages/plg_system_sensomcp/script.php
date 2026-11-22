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
use Joomla\CMS\Table\Extension;
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
            // Table « Extension » du cœur (J5/J6), comme l'installeur de Joomla : pas de contrôle d'ACL,
            // indispensable en installation CLI (aucun utilisateur connecté).
            $table = new Extension($db);

            if ($table->load(['type' => 'plugin', 'folder' => self::FOLDER, 'element' => self::ELEMENT])) {
                // Épingler en TÊTE des plugins système (avant tout plugin qui court-circuiterait tôt
                // onAfterInitialise). EXCEPTION SQL assumée : le cœur n'offre aucune API pour lire le plus
                // petit `ordering` d'un groupe de plugins (Table::getNextOrder() ne donne que le max).
                $min = $db->setQuery(
                    $db->getQuery(true)
                        ->select('MIN(' . $db->quoteName('ordering') . ')')
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                        ->where($db->quoteName('folder') . ' = ' . $db->quote(self::FOLDER))
                        ->where($db->quoteName('element') . ' <> ' . $db->quote(self::ELEMENT))
                )->loadResult();

                $table->enabled  = 1;
                $table->ordering = $min === null ? 0 : ((int) $min - 1);
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
