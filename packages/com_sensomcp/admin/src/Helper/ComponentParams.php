<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Params de com_sensomcp lus et écrits hors du formulaire Options (boutons des vues, réglages
 * instantanés), via la table « Extension » du cœur ({@see ExtensionRecords}).
 *
 * - get() lit SANS cache : ComponentHelper::getParams() garde les params en cache (_system) ; les
 *   kill-switches, la clé de licence et les réglages de sécurité doivent prendre effet immédiatement.
 * - set() fusionne : les autres clés sont conservées. Le cache `_system` est vidé, sans quoi les
 *   requêtes suivantes liraient l'ancienne valeur via ComponentHelper.
 */
final class ComponentParams
{
    private const ELEMENT = 'com_sensomcp';

    /** @return array<string,mixed> */
    public static function get(): array
    {
        try {
            return ExtensionRecords::params(ExtensionRecords::component(self::ELEMENT));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function set(array $changes): void
    {
        $row = ExtensionRecords::component(self::ELEMENT);

        if (!$row) {
            return;
        }

        ExtensionRecords::setParams($row, array_merge(ExtensionRecords::params($row), $changes));

        try {
            Factory::getCache('_system')->clean();
        } catch (\Throwable) {
            // best-effort
        }
    }
}
