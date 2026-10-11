<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

\defined('_JEXEC') or die;

/**
 * Client HTTP de l'API boutique Senso MCP (sensomcp.com). Appels serveur-à-serveur,
 * le Download ID (clé de licence) sert de bearer. Jamais fatal : null = boutique
 * injoignable / réponse illisible. La cause technique du dernier échec (erreur
 * cURL, code HTTP, réponse non-JSON) est journalisée dans
 * administrator/logs/com_sensomcp.log.php et exposée via lastError() pour le
 * dashboard — sans cela un hébergement qui bloque la sortie HTTPS ne renvoie
 * qu'un « boutique injoignable » impossible à diagnostiquer.
 */
final class LicenseClient
{
    private const STORE = 'https://www.sensomcp.com';
    private const TIMEOUT = 8;
    private const MAX_DETAIL = 200;

    private static ?string $lastError = null;
    private static bool $loggerUp = false;

    /** Cause technique du dernier appel en échec (null si le dernier appel a réussi). */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Vérifie la couverture du domaine pour cette clé.
     *
     * @return array<string,mixed>|null
     */
    public static function verify(string $key, string $host): ?array
    {
        $url = self::STORE . '/index.php?option=com_sensomcpstore&task=api.verify&format=json'
            . '&dlid=' . urlencode($key)
            . '&domain=' . urlencode($host);

        try {
            $res = HttpFactory::getHttp()->get($url, [], self::TIMEOUT);

            return self::decode('verify', $res->code, (string) $res->body);
        } catch (\Throwable $e) {
            return self::fail('verify', $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Déclare / réaffecte le domaine courant sur une licence.
     *
     * @return array<string,mixed>|null
     */
    public static function assign(string $key, string $host, int $licenseId, string $mode = 'declare'): ?array
    {
        $url = self::STORE . '/index.php?option=com_sensomcpstore&task=api.assign&format=json';
        $body = [
            'dlid'       => $key,
            'domain'     => $host,
            'license_id' => $licenseId,
            'mode'       => $mode,
        ];

        try {
            $res = HttpFactory::getHttp()->post($url, $body, [], self::TIMEOUT);

            return self::decode('assign', $res->code, (string) $res->body);
        } catch (\Throwable $e) {
            return self::fail('assign', $e::class . ': ' . $e->getMessage());
        }
    }

    // --- interne -------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private static function decode(string $call, int $code, string $body): ?array
    {
        $data = json_decode($body, true);

        if (\is_array($data)) {
            self::$lastError = null;

            return $data;
        }

        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return self::fail($call, 'HTTP ' . $code . ', non-JSON response: ' . mb_substr($excerpt, 0, self::MAX_DETAIL));
    }

    /** Mémorise + journalise la cause, renvoie toujours null (contrat « jamais fatal »). */
    private static function fail(string $call, string $detail): ?array
    {
        self::$lastError = mb_substr($detail, 0, self::MAX_DETAIL + 60);

        try {
            if (!self::$loggerUp) {
                Log::addLogger(['text_file' => 'com_sensomcp.log.php'], Log::ALL, ['com_sensomcp']);
                self::$loggerUp = true;
            }

            Log::add('license ' . $call . ' -> ' . self::STORE . ' failed: ' . self::$lastError, Log::ERROR, 'com_sensomcp');
        } catch (\Throwable) {
            // Le journal ne doit jamais casser le dashboard.
        }

        return null;
    }
}
