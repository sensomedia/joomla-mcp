<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Update;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Détection d'une mise à jour du PACK FREE (Senso MCP) et rendu de la bannière
 * commune à toutes les vues du composant. S'appuie sur la table « Update » du cœur
 * (#__updates, remplie par le vérificateur de mises à jour Joomla).
 */
final class Updates
{
    /**
     * Version disponible du pack free (com_sensomcp / pkg_sensomcp), ou null. Table « Update » du
     * cœur (#__updates, rempli par le vérificateur de mises à jour) ; la plus haute au sens
     * version_compare() (l'ancien ORDER BY version DESC comparait des chaînes : « 1.10 » < « 1.9 »).
     */
    public static function freePackageVersion(): ?string
    {
        try {
            $best = null;

            foreach (['pkg_sensomcp', 'com_sensomcp'] as $element) {
                $t = new Update(Factory::getContainer()->get(DatabaseInterface::class));

                if ($t->load(['element' => $element])) {
                    $v = trim((string) $t->version);

                    if ($v !== '' && ($best === null || version_compare($v, $best, '>'))) {
                        $best = $v;
                    }
                }
            }

            return $best;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Version installée du composant com_sensomcp (manifest_cache, table « Extension » du cœur). */
    public static function componentVersion(): string
    {
        try {
            $row = ExtensionRecords::component('com_sensomcp');

            return (string) (json_decode((string) ($row->manifest_cache ?? ''), true)['version'] ?? '');
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
