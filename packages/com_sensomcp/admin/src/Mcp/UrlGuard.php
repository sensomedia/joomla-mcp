<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Garde anti-SSRF pour les outils qui font télécharger une URL par le serveur
 * (install_extension, update/upload_media) ou qui rendent une page (get_rendered_page).
 * Contrat public réutilisé par les plugins d'outils.
 */
final class UrlGuard
{
    /**
     * Exige une URL http(s) PUBLIQUE : rejette tout hôte qui résout vers une IP
     * privée/loopback/réservée (accès au réseau interne, endpoints métadonnées cloud…).
     *
     * @throws McpToolException
     */
    public static function assertPublicHttpUrl(string $url): void
    {
        $parts  = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new McpToolException('Only public http(s) URLs are allowed.');
        }

        foreach (self::resolveIps($host) as $ip) {
            // NO_PRIV_RANGE + NO_RES_RANGE : refuse 10/8, 172.16/12, 192.168/16,
            // 127/8, 169.254/16, ::1, fc00::/7, etc.
            if (!filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                throw new McpToolException('URL resolves to a private or reserved address (blocked for security).');
            }
        }
    }

    /**
     * Exige que l'URL soit sur CE site (même hôte que Uri::root()). Comparaison
     * exacte de l'hôte parsé — pas de `str_starts_with` contournable par
     * `https://monsite.tld.attaquant.tld/`.
     *
     * @throws McpToolException
     */
    public static function assertSameSite(string $url): void
    {
        $host     = strtolower((string) parse_url(trim($url), \PHP_URL_HOST));
        $siteHost = strtolower((string) parse_url(Uri::root(), \PHP_URL_HOST));

        if ($host === '' || $siteHost === '' || $host !== $siteHost) {
            throw new McpToolException('The URL must point to this site.');
        }
    }

    /**
     * @return list<string> IP(s) de l'hôte (l'hôte lui-même s'il est déjà une IP).
     * @throws McpToolException si l'hôte ne résout pas.
     */
    private static function resolveIps(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        foreach (@dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        if ($ips === []) {
            throw new McpToolException('Could not resolve the URL host.');
        }

        return $ips;
    }
}
