<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\UpdateSite;
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
            // Clé de licence (lue en direct, non cachée).
            $key    = trim((string) (ComponentParams::get()['license_key'] ?? ''));
            $domain = Uri::getInstance()->getHost();
            $extra  = $key !== '' ? 'dlid=' . rawurlencode($key) . '&domain=' . rawurlencode($domain) : '';

            // Update sites des plugins du groupe sensomcp : modèle « Updatesites » de com_installer
            // (J5/J6, lecture sans ACL), filtré sur les plugins du dossier sensomcp.
            $list = Factory::getApplication()->bootComponent('com_installer')
                ->getMVCFactory()->createModel('Updatesites', 'Administrator', ['ignore_request' => true]);
            $list->setState('filter.type', 'plugin');
            $list->setState('filter.folder', 'sensomcp');
            $list->setState('filter.enabled', '');
            $list->setState('list.ordering', 'update_site_id');
            $list->setState('list.direction', 'ASC');
            $list->setState('list.start', 0);
            $list->setState('list.limit', 0);

            $ids = array_values(array_unique(array_map(static fn ($i) => (int) $i->update_site_id, (array) $list->getItems())));

            // Écriture : table « UpdateSite » du cœur, seulement si la valeur change.
            foreach ($ids as $id) {
                $site = new UpdateSite(Factory::getContainer()->get(DatabaseInterface::class));

                if ($site->load($id) && (string) $site->extra_query !== $extra) {
                    $site->extra_query = $extra;
                    $site->store();
                }
            }
        } catch (\Throwable) {
            // best-effort
        }
    }
}
