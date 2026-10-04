<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;

\defined('_JEXEC') or die;

/**
 * Habillage de l'admin : charge la base CSS + le thème choisi, et découvre les
 * thèmes disponibles. Un thème = un fichier `media/com_sensomcp/css/themes/<clé>.css`
 * (voir en-tête `/* @name … @mode dark|light *​/`). Déposer un CSS = nouveau thème.
 */
final class ThemeHelper
{
    private const REL_DIR = '/media/com_sensomcp/css/themes';

    /** Clé du thème actif (param composant), validée ; '' = normal. */
    public static function key(): string
    {
        $key = trim((string) ComponentHelper::getParams('com_sensomcp')->get('theme', ''));

        return self::exists($key) ? $key : '';
    }

    /** Mode du thème actif : 'dark' | 'light' | '' (normal). */
    public static function mode(): string
    {
        $key = self::key();

        return $key === '' ? '' : (self::available()[$key]['mode'] ?? '');
    }

    /** Charge la base CSS (toujours) + le thème actif (si défini). */
    public static function load(): void
    {
        HTMLHelper::_('stylesheet', 'com_sensomcp/admin.css', ['version' => 'auto', 'relative' => true]);

        $key = self::key();

        if ($key !== '') {
            HTMLHelper::_('stylesheet', 'com_sensomcp/themes/' . $key . '.css', ['version' => 'auto', 'relative' => true]);
        }
    }

    /**
     * Thèmes disponibles (scan du dossier).
     *
     * @return array<string, array{name: string, mode: string}>
     */
    public static function available(): array
    {
        $out = [];

        foreach (glob(JPATH_ROOT . self::REL_DIR . '/*.css') ?: [] as $file) {
            $key    = basename($file, '.css');
            $header = (string) file_get_contents($file, false, null, 0, 400);

            preg_match('/@name\s+([^\r\n*]+)/', $header, $mName);
            preg_match('/@mode\s+(dark|light)/', $header, $mMode);

            $out[$key] = [
                'name' => trim($mName[1] ?? $key),
                'mode' => $mMode[1] ?? 'light',
            ];
        }

        ksort($out);

        return $out;
    }

    /** Le thème existe-t-il (clé sûre + fichier présent) ? */
    private static function exists(string $key): bool
    {
        if ($key === '' || !preg_match('/^[a-z0-9._-]+$/i', $key)) {
            return false;
        }

        return is_file(JPATH_ROOT . self::REL_DIR . '/' . $key . '.css');
    }
}
