<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Pose `extra_query = dlid=<clé>&domain=<hôte>` sur les update sites des plugins
 * sensomcp, afin que leurs requêtes de mise à jour portent la clé de licence ET
 * le domaine — que la boutique (sensomcp.com) contrôle avant de servir un update
 * (domaine non déclaré → pas d'update). Sans effet sur le package Free.
 *
 * Appelé quand la clé peut avoir changé (vue admin) et après une install.
 */
final class UpdateSitesHelper
{
    public static function sync(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            // Clé de licence (lue en direct, non cachée).
            $params = json_decode((string) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
            )->loadResult(), true) ?: [];

            $key    = trim((string) ($params['license_key'] ?? ''));
            $domain = Uri::getInstance()->getHost();
            $extra  = $key !== '' ? 'dlid=' . rawurlencode($key) . '&domain=' . rawurlencode($domain) : '';

            // Update sites des plugins du groupe sensomcp.
            $ids = $db->setQuery(
                $db->getQuery(true)
                    ->select('DISTINCT ' . $db->quoteName('se.update_site_id'))
                    ->from($db->quoteName('#__update_sites_extensions', 'se'))
                    ->join('INNER', $db->quoteName('#__extensions', 'e') . ' ON ' . $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('se.extension_id'))
                    ->where($db->quoteName('e.folder') . ' = ' . $db->quote('sensomcp'))
                    ->where($db->quoteName('e.type') . ' = ' . $db->quote('plugin'))
            )->loadColumn() ?: [];

            if ($ids === []) {
                return;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__update_sites'))
                    ->set($db->quoteName('extra_query') . ' = ' . $db->quote($extra))
                    ->whereIn($db->quoteName('update_site_id'), array_map('intval', $ids))
            )->execute();
        } catch (\Throwable) {
            // best-effort
        }
    }
}
