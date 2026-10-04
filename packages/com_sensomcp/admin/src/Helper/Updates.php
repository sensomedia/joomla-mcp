<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Détection d'une mise à jour du PACK FREE (Senso MCP) et rendu de la bannière
 * commune à toutes les vues du composant. S'appuie sur `#__updates` (rempli par
 * le vérificateur de mises à jour Joomla).
 */
final class Updates
{
    /** Version disponible du pack free (com_sensomcp / pkg_sensomcp), ou null. */
    public static function freePackageVersion(): ?string
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $q  = $db->getQuery(true)
                ->select($db->quoteName('version'))
                ->from($db->quoteName('#__updates'))
                ->whereIn($db->quoteName('element'), ['com_sensomcp', 'pkg_sensomcp'], \Joomla\Database\ParameterType::STRING)
                ->order($db->quoteName('version') . ' DESC')
                ->setLimit(1);

            $v = (string) ($db->setQuery($q)->loadResult() ?: '');

            return $v !== '' ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Version installée du composant com_sensomcp (depuis manifest_cache). */
    public static function componentVersion(): string
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $mc = (string) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('manifest_cache'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
            )->loadResult();

            return (string) (json_decode($mc, true)['version'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** Bannière « Mise à jour X.Y.Z disponible » (lien vers les MAJ Joomla), ou ''. */
    public static function banner(): string
    {
        $v = self::freePackageVersion();

        if ($v === null) {
            return '';
        }

        $url  = Route::_('index.php?option=com_installer&view=update');
        $text = Text::sprintf('COM_SENSOMCP_FREE_UPDATE_AVAILABLE', htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));

        return '<div class="alert alert-warning d-flex align-items-center justify-content-between gap-2 flex-wrap smcp-free-update">'
            . '<span><span class="icon-download me-2" aria-hidden="true"></span>' . $text . '</span>'
            . '<a class="btn btn-sm btn-warning" href="' . $url . '">' . Text::_('COM_SENSOMCP_FREE_UPDATE_GOTO') . '</a>'
            . '</div>';
    }
}
