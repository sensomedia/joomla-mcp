<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\UserGroupsHelper;
use Joomla\CMS\User\UserFactoryInterface;

\defined('_JEXEC') or die;

/**
 * Groupes autorisés à utiliser un jeton API Joomla : paramètre `allowedUserGroups`
 * du plugin « User - Joomla API Token » (plg_user_token), lu et écrit par la table « Extension »
 * du cœur ({@see ExtensionRecords} : le plugin peut être désactivé, PluginHelper ne le renverrait pas).
 *
 * Mêmes règles que le cœur Joomla (plg_user_token / plg_api-authentication_token) :
 * réglage absent → [8] (Super Users) ; liste vide → tous les groupes autorisés.
 */
final class TokenGroups
{
    /** @return int[] */
    public static function allowed(): array
    {
        $params = self::params();

        if (!\array_key_exists('allowedUserGroups', $params)) {
            return [8];
        }

        $groups = $params['allowedUserGroups'];

        if (empty($groups)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', (array) $groups)));
    }

    /** @param int[] $groups */
    public static function set(array $groups): void
    {
        $row = ExtensionRecords::plugin('user', 'token');

        if (!$row) {
            return;
        }

        $params                      = ExtensionRecords::params($row);
        $params['allowedUserGroups'] = array_values(array_map('strval', array_unique(array_map('intval', $groups))));
        ExtensionRecords::setParams($row, $params);

        foreach (['com_plugins', '_system'] as $group) {
            try {
                Factory::getCache($group)->clean();
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /**
     * Groupes à ne jamais proposer : Public (racine, dont tout utilisateur hérite → revient
     * à autoriser tout le monde) et le groupe invité de com_users (jamais d'utilisateur
     * connecté, donc jamais de jeton).
     *
     * @return int[]
     */
    public static function forbidden(): array
    {
        // Groupes racine (parent_id = 0) : UserGroupsHelper du cœur.
        $ids = [];

        foreach (UserGroupsHelper::getInstance()->getAll() as $group) {
            if ((int) $group->parent_id === 0) {
                $ids[] = (int) $group->id;
            }
        }

        $ids[] = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 9);

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * L'utilisateur appartient-il à un groupe autorisé (ou tous le sont-ils) ?
     * Comme plg_api-authentication_token : plugin « User - Joomla API Token » désactivé
     * → réglage ignoré, seuls les Super Users [8] restent autorisés.
     */
    public static function userAllowed(int $userId): bool
    {
        $row     = ExtensionRecords::plugin('user', 'token');
        $allowed = $row !== null && (int) $row->enabled === 1 ? self::allowed() : [8];

        if ($allowed === []) {
            return true;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        if ((int) $user->id !== $userId || $user->guest) {
            return false;
        }

        return array_intersect(array_map('intval', $user->getAuthorisedGroups()), $allowed) !== [];
    }

    private static function params(): array
    {
        return ExtensionRecords::params(ExtensionRecords::plugin('user', 'token'));
    }
}
