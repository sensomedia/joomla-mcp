<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Groupes autorisés à utiliser un jeton API Joomla : paramètre `allowedUserGroups`
 * du plugin « User - Joomla API Token » (plg_user_token), lu et écrit directement en
 * base (le plugin peut être désactivé, PluginHelper ne le renverrait pas).
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
        $params                      = self::params();
        $params['allowedUserGroups'] = array_values(array_map('strval', array_unique(array_map('intval', $groups))));

        $db   = self::db();
        $json = (string) json_encode($params);
        $q    = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where(self::where($db))
            ->bind(':params', $json, ParameterType::STRING);

        $db->setQuery($q)->execute();

        foreach (['com_plugins', '_system'] as $group) {
            try {
                Factory::getCache($group)->clean();
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /** L'utilisateur appartient-il à un groupe autorisé (ou tous le sont-ils) ? */
    public static function userAllowed(int $userId): bool
    {
        $allowed = self::allowed();

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
        $db = self::db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where(self::where($db));

        return json_decode((string) $db->setQuery($q)->loadResult(), true) ?: [];
    }

    private static function where(DatabaseInterface $db): array
    {
        return [
            $db->quoteName('type') . ' = ' . $db->quote('plugin'),
            $db->quoteName('folder') . ' = ' . $db->quote('user'),
            $db->quoteName('element') . ' = ' . $db->quote('token'),
        ];
    }

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
