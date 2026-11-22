<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Extension;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Lignes #__extensions via la table « Extension » du cœur (J5/J6), NON mises en cache
 * (contrairement à ExtensionHelper::getExtensionRecord(), cache statique par requête) :
 * un état lu juste après une installation ou une bascule est donc toujours à jour.
 *
 * Écritures SANS contrôle d'ACL (comme l'installeur de Joomla) : n'appeler setEnabled() /
 * setParams() qu'APRÈS le contrôle d'accès de l'appelant. Pour une action d'administration
 * soumise aux droits com_plugins, préférer le modèle Plugin de com_plugins (publish()).
 *
 * @since 1.7.0
 */
final class ExtensionRecords
{
    public static function table(): Extension
    {
        return new Extension(Factory::getContainer()->get(DatabaseInterface::class));
    }

    public static function plugin(string $folder, string $element): ?Extension
    {
        $t = self::table();

        return $t->load(['type' => 'plugin', 'folder' => $folder, 'element' => $element]) ? $t : null;
    }

    public static function component(string $element): ?Extension
    {
        $t = self::table();

        return $t->load(['type' => 'component', 'element' => $element]) ? $t : null;
    }

    public static function byId(int $id): ?Extension
    {
        $t = self::table();

        return $id > 0 && $t->load($id) ? $t : null;
    }

    /** Params décodés d'une ligne (tableau vide si absente / JSON invalide). */
    public static function params(?Extension $row): array
    {
        $p = $row ? json_decode((string) $row->params, true) : null;

        return \is_array($p) ? $p : [];
    }

    /** Active / désactive une extension (table, sans ACL). */
    public static function setEnabled(int $id, bool $enabled): bool
    {
        $row = self::byId($id);

        if (!$row) {
            return false;
        }

        $row->enabled = $enabled ? 1 : 0;

        return $row->store();
    }

    /** Remplace les params d'une ligne (table, sans ACL). */
    public static function setParams(Extension $row, array $params): bool
    {
        $row->params = (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $row->store();
    }
}
