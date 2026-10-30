<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

\defined('_JEXEC') or die;

/**
 * Blocs .htaccess des directives serveur (vue Réglages).
 *
 * Chaque directive vit dans son propre bloc délimité par des marqueurs
 * « # BEGIN Senso MCP (<id>) » / « # END Senso MCP (<id>) », placé EN TÊTE du
 * fichier : il doit passer avant les règles de réécriture déjà présentes (Joomla,
 * WordPress ou tout autre système à la racine du domaine). Le reste du fichier
 * n'est jamais modifié ; un fichier absent est créé.
 *
 *  - auth / wellknown  : .htaccess du dossier Joomla ;
 *  - wellknown_domain  : .htaccess à la racine du DOMAINE (site en sous-dossier),
 *                        hors Joomla, possiblement d'un autre système.
 */
final class Htaccess
{
    public const IDS = ['auth', 'wellknown', 'wellknown_domain'];

    /** Dossier qui porte le .htaccess de la directive, null si indéterminable. */
    public static function directory(string $id, string $base): ?string
    {
        $site = rtrim(str_replace('\\', '/', JPATH_ROOT), '/');

        if ($id !== 'wellknown_domain') {
            return $site;
        }

        // Racine du domaine = dossier Joomla moins le sous-dossier de l'URL. Refus si
        // l'URL ne se retrouve pas dans le chemin disque (alias, lien symbolique…).
        if (!self::safeBase($base) || !str_ends_with($site, $base)) {
            return null;
        }

        $dir = substr($site, 0, -\strlen($base));

        return $dir !== '' && is_dir($dir) ? $dir : null;
    }

    public static function file(string $id, string $base): ?string
    {
        $dir = self::directory($id, $base);

        return $dir === null ? null : $dir . '/.htaccess';
    }

    /** Bloc complet (marqueurs inclus), tel qu'écrit dans le fichier. */
    public static function block(string $id, string $base): string
    {
        return self::begin($id) . "\n" . self::rules($id, $base) . "\n" . self::end($id) . "\n";
    }

    public static function has(string $content, string $id): bool
    {
        return str_contains($content, self::begin($id));
    }

    /** Écrit (ou remplace) le bloc en tête du fichier. @return array{status:string, file:?string, backup:?string, old:?string} */
    public static function add(string $id, string $base): array
    {
        $file = self::file($id, $base);

        if ($file === null) {
            return ['status' => 'nodir', 'file' => null, 'backup' => null, 'old' => null];
        }

        $exists = is_file($file);

        if ($exists ? !is_writable($file) : !is_writable(\dirname($file))) {
            return ['status' => 'notwritable', 'file' => $file, 'backup' => null, 'old' => null];
        }

        $old    = $exists ? (string) file_get_contents($file) : null;
        $backup = $exists ? self::backup($file) : null;

        if ($exists && $backup === null) {
            return ['status' => 'writefail', 'file' => $file, 'backup' => null, 'old' => null];
        }

        $rest = ltrim(self::strip((string) $old, $id));
        $new  = self::block($id, $base) . ($rest === '' ? '' : "\n" . $rest);

        if (file_put_contents($file, $new, LOCK_EX) === false) {
            return ['status' => 'writefail', 'file' => $file, 'backup' => $backup, 'old' => $old];
        }

        return ['status' => 'ok', 'file' => $file, 'backup' => $backup, 'old' => $old];
    }

    /** Remet le fichier dans son état d'avant add() (contenu d'origine, ou suppression s'il n'existait pas). */
    public static function restore(string $file, ?string $old): void
    {
        if ($old === null) {
            @unlink($file);

            return;
        }

        file_put_contents($file, $old, LOCK_EX);
    }

    /** Retire le bloc ; supprime le fichier s'il ne contenait que lui. @return array{status:string, file:?string, backup:?string} */
    public static function remove(string $id, string $base): array
    {
        $file = self::file($id, $base);

        if ($file === null || !is_file($file)) {
            return ['status' => 'nodir', 'file' => $file, 'backup' => null];
        }

        if (!is_writable($file)) {
            return ['status' => 'notwritable', 'file' => $file, 'backup' => null];
        }

        $old = (string) file_get_contents($file);

        if (!self::has($old, $id)) {
            return ['status' => 'ok', 'file' => $file, 'backup' => null];
        }

        $backup = self::backup($file);
        $new    = ltrim(self::strip($old, $id));
        $done   = $new === '' ? @unlink($file) : file_put_contents($file, $new, LOCK_EX) !== false;

        return ['status' => $done ? 'ok' : 'writefail', 'file' => $file, 'backup' => $backup];
    }

    private static function rules(string $id, string $base): string
    {
        $wellKnown = '^\.well-known/oauth-(protected-resource|authorization-server)';

        return match ($id) {
            'auth'      => 'SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1',
            'wellknown' => "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule $wellKnown\$ index.php [L]\n</IfModule>",
            'wellknown_domain' => "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule $wellKnown/"
                . preg_quote(ltrim($base, '/')) . "(/.*)?\$ $base/index.php [L]\n</IfModule>",
        };
    }

    private static function begin(string $id): string
    {
        return '# BEGIN Senso MCP (' . $id . ')';
    }

    private static function end(string $id): string
    {
        return '# END Senso MCP (' . $id . ')';
    }

    private static function strip(string $content, string $id): string
    {
        $pattern = '/^' . preg_quote(self::begin($id), '/') . '.*?^' . preg_quote(self::end($id), '/') . '[^\n]*\n?/ms';

        return (string) preg_replace($pattern, '', $content);
    }

    /** Copie horodatée à côté du fichier (Apache refuse de servir les .ht*). */
    private static function backup(string $file): ?string
    {
        $backup = $file . '.bak-' . date('Ymd-His');

        return @copy($file, $backup) ? $backup : null;
    }

    /** Sous-dossier d'URL exploitable dans une règle (« /dev », « /site/fr »…). */
    private static function safeBase(string $base): bool
    {
        return (bool) preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $base);
    }
}
