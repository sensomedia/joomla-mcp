<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Journal de débogage OPTIONNEL (param `debug` du composant, désactivé par défaut).
 *
 * Quand il est activé, chaque appel d'outil est journalisé avec ses paramètres
 * dans `administrator/logs/com_sensomcp.log.php`, avec deux garde-fous :
 *  - champs sensibles MASQUÉS (mots de passe, jetons, licences, Download ID…) ;
 *  - valeurs longues TRONQUÉES et blobs (base64) remplacés par un repère de taille.
 *
 * Best-effort : n'interrompt jamais le service (tout est encapsulé en try/catch).
 * Le param est lu en base directe (le cache _system de ComponentHelper est périmé).
 */
final class DebugLog
{
    private static ?bool $enabled  = null;
    private static bool  $loggerUp = false;

    /** Longueur max d'une valeur chaîne avant troncature. */
    private const MAX_LEN = 500;

    /** Au-delà, la valeur est remplacée par un simple repère de taille. */
    private const BLOB_LEN = 4000;

    /** Fragments de noms de champs considérés comme sensibles → masqués. */
    private const SENSITIVE = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'authorization',
        'license', 'dlid', 'download_id', 'api_key', 'apikey', 'credential',
        'mfa', 'otp', 'private',
    ];

    /** Le mode débug est-il activé ? (lecture DB directe, mémoïsée) */
    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        self::$enabled = false;

        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $params = json_decode(
                (string) $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName('params'))
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
                )->loadResult(),
                true
            );

            self::$enabled = \is_array($params) && (int) ($params['debug'] ?? 0) === 1;
        } catch (\Throwable) {
            // rien : débug considéré désactivé
        }

        return self::$enabled;
    }

    /** Journalise un appel d'outil avec ses paramètres (rédigés + tronqués). */
    public static function toolCall(string $tool, array $arguments): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            self::ensureLogger();

            $json = json_encode(
                self::sanitize($arguments),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            Log::add('tool=' . $tool . ' args=' . $json, Log::DEBUG, 'com_sensomcp');
        } catch (\Throwable) {
            // Le journal ne doit jamais casser le service.
        }
    }

    /** Journalise l'échec d'un outil (classe, message et origine de l'exception). */
    public static function toolError(string $tool, \Throwable $e): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            self::ensureLogger();

            $file = str_replace(JPATH_ROOT . '/', '', $e->getFile());

            Log::add(
                'tool=' . $tool . ' error=' . $e::class . ': ' . substr($e->getMessage(), 0, self::MAX_LEN)
                . ' at ' . $file . ':' . $e->getLine(),
                Log::ERROR,
                'com_sensomcp'
            );
        } catch (\Throwable) {
            // Le journal ne doit jamais casser le service.
        }
    }

    /**
     * Journalise une exception INATTENDUE (ni McpToolException ni OAuthException)
     * sous un identifiant de corrélation, TOUJOURS (indépendant du mode debug) :
     * le client ne reçoit qu'un message générique portant cet identifiant, le
     * détail (classe, message, fichier:ligne) va dans administrator/logs/com_sensomcp.log.php.
     * Renvoie l'identifiant.
     */
    public static function internal(\Throwable $e, string $context = ''): string
    {
        $ref = bin2hex(random_bytes(4));

        try {
            self::ensureLogger();

            Log::add(
                'ref=' . $ref . ($context !== '' ? ' ' . $context : '')
                . ' error=' . $e::class . ': ' . substr($e->getMessage(), 0, self::MAX_LEN)
                . ' at ' . str_replace(JPATH_ROOT . '/', '', $e->getFile()) . ':' . $e->getLine(),
                Log::ERROR,
                'com_sensomcp'
            );
        } catch (\Throwable) {
            // Le journal ne doit jamais casser le service.
        }

        return $ref;
    }

    /** Résumé court d'une exception pour le journal MCP en base (colonne error, 255 car.). */
    public static function summary(\Throwable $e, string $ref): string
    {
        return 'ref=' . $ref . ' ' . $e::class . ': ' . substr($e->getMessage(), 0, 160)
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    private static function ensureLogger(): void
    {
        if (self::$loggerUp) {
            return;
        }

        Log::addLogger(['text_file' => 'com_sensomcp.log.php'], Log::ALL, ['com_sensomcp']);
        self::$loggerUp = true;
    }

    /** @param mixed $value */
    private static function sanitize($value, string $key = '')
    {
        // Champ sensible → masqué, quelle que soit la valeur.
        if ($key !== '' && self::isSensitive($key)) {
            return '***';
        }

        if (\is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = self::sanitize($v, (string) $k);
            }

            return $out;
        }

        if (\is_string($value)) {
            $len = \strlen($value);

            // Blob (base64 / gros contenu) → repère de taille, jamais la donnée.
            if ($len > self::BLOB_LEN || self::looksBlobKey($key)) {
                $kind = self::looksBlobKey($key) ? 'base64' : 'data';

                return '[' . $kind . ' ' . $len . ' chars]';
            }

            if ($len > self::MAX_LEN) {
                return substr($value, 0, self::MAX_LEN) . '…[+' . ($len - self::MAX_LEN) . ']';
            }
        }

        return $value;
    }

    private static function isSensitive(string $key): bool
    {
        $k = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function looksBlobKey(string $key): bool
    {
        $k = strtolower($key);

        return str_contains($k, 'base64') || str_contains($k, 'data_');
    }
}
