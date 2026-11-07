<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Écrit des params de com_sensomcp hors du formulaire Options (boutons des vues).
 * Les autres clés sont conservées. Le cache `_system` est vidé : ComponentHelper
 * y garde les params, sans quoi les requêtes suivantes liraient l'ancienne valeur.
 */
final class ComponentParams
{
    public static function set(array $changes): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $q = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'));

        $params = json_decode((string) $db->setQuery($q)->loadResult(), true) ?: [];

        foreach ($changes as $key => $value) {
            $params[$key] = $value;
        }

        $json = (string) json_encode($params);

        $u = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
            ->bind(':params', $json, ParameterType::STRING);

        $db->setQuery($u)->execute();

        try {
            Factory::getCache('_system')->clean();
        } catch (\Throwable) {
            // best-effort
        }
    }
}
