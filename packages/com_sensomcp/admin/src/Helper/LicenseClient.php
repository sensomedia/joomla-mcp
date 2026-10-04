<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Helper;

use Joomla\CMS\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Client HTTP de l'API boutique Senso MCP (sensomcp.com). Appels serveur-à-serveur,
 * le Download ID (clé de licence) sert de bearer. Jamais fatal : null = boutique
 * injoignable / réponse illisible.
 */
final class LicenseClient
{
    private const STORE = 'https://www.sensomcp.com';
    private const TIMEOUT = 8;

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
            $data = json_decode((string) $res->body, true);

            return \is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
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
            $res  = HttpFactory::getHttp()->post($url, $body, [], self::TIMEOUT);
            $data = json_decode((string) $res->body, true);

            return \is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
