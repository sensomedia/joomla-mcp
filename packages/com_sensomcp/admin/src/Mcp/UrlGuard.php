<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Anti-SSRF : validation d'URL ET requête HTTP sortante contrôlée (audit 2026-10-12, H9).
 *
 * `fetch()` est le seul chemin recommandé pour toute requête sortante déclenchée par
 * un argument d'outil : schéma http(s), pas d'identifiants, port 80/443, chaque
 * adresse résolue testée contre les plages privées/réservées IPv4 ET IPv6 (dont les
 * IPv4 embarquées : mappée, NAT64, 6to4), connexion épinglée sur les adresses
 * validées (CURLOPT_RESOLVE, toutes familles), redirections suivies à la main et
 * revalidées, taille et délais bornés. Sans cURL : repli sur le client Joomla avec
 * suivi désactivé et revalidation à chaque saut (sans épinglage, journalisé).
 */
final class UrlGuard
{
    public const DEFAULT_MAX_REDIRECTS = 3;

    /** Compatibilité : validation seule (préférer fetch()). */
    public static function assertPublicHttpUrl(string $url): void
    {
        self::validate($url, false);
    }

    /** L'URL pointe sur l'hôte exact du site. */
    public static function assertSameSite(string $url): void
    {
        $host     = strtolower((string) parse_url(trim($url), \PHP_URL_HOST));
        $siteHost = strtolower((string) parse_url(Uri::root(), \PHP_URL_HOST));

        if ($host === '' || $siteHost === '' || $host !== $siteHost) {
            throw new McpToolException('The URL must point to this site.');
        }
    }

    /**
     * Requête HTTP sortante contrôlée.
     *
     * Options :
     *  - method        GET (défaut) | HEAD
     *  - headers       array<string,string> en-têtes de requête
     *  - timeout       délai total en secondes (défaut 25)
     *  - connect_timeout secondes (défaut 10)
     *  - max_bytes     taille maximale du corps (0 = illimité) ; dépassement → exception
     *  - max_redirects sauts suivis (défaut 3), chaque Location revalidée
     *  - same_site     true : l'hôte doit être celui du site à chaque saut, pas de contrôle
     *                  d'adresse (le site lui-même peut être privé en dev)
     *  - verify_ssl    défaut true
     *  - to_file       chemin : le corps est écrit dans ce fichier au lieu de la mémoire
     *  - user_agent    défaut « SensoMCP »
     *
     * @return object{code:int, headers:array<string,string>, body:string, url:string, bytes:int}
     */
    public static function fetch(string $url, array $options = []): object
    {
        $method   = strtoupper((string) ($options['method'] ?? 'GET')) === 'HEAD' ? 'HEAD' : 'GET';
        $maxHops  = max(0, (int) ($options['max_redirects'] ?? self::DEFAULT_MAX_REDIRECTS));
        $sameSite = (bool) ($options['same_site'] ?? false);
        $url      = trim($url);

        for ($hop = 0; ; $hop++) {
            $target   = self::validate($url, $sameSite);
            $response = \function_exists('curl_init')
                ? self::curlRequest($target, $method, $options)
                : self::streamRequest($target, $method, $options);

            if (!\in_array($response->code, [301, 302, 303, 307, 308], true) || empty($response->headers['location'])) {
                return $response;
            }

            if ($hop >= $maxHops) {
                throw new McpToolException(sprintf(
                    'Too many redirects (%d) while fetching %s (last: %s).',
                    $hop + 1,
                    self::redact($url),
                    self::redact($response->headers['location'])
                ));
            }

            $url = self::resolveLocation($url, $response->headers['location']);

            if ($response->code === 303) {
                $method = 'GET';
            }
        }
    }

    /**
     * Valide une URL et résout son hôte.
     *
     * @return array{url:string, scheme:string, host:string, port:int, ips:string[]}
     */
    public static function validate(string $url, bool $sameSite = false): array
    {
        $parts  = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if (!\is_array($parts) || !\in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new McpToolException('Only public http(s) URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new McpToolException('URLs with embedded credentials are not allowed.');
        }

        $default = $scheme === 'https' ? 443 : 80;
        $port    = (int) ($parts['port'] ?? $default);

        if ($port !== 80 && $port !== 443) {
            throw new McpToolException("URL port $port is not allowed (only 80 and 443).");
        }

        if ($sameSite) {
            self::assertSameSite($url);

            return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => []];
        }

        $ips = self::resolveIps(trim($host, '[]'));

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new McpToolException(
                    'URL resolves to a private, reserved or internal address (blocked for security): ' . self::redact($url)
                );
            }
        }

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /** Adresse publique routable ? Couvre IPv4, IPv6 et les IPv4 embarquées dans IPv6. */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[] ');

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return self::isPublicIpv4($ip);
        }

        if (!filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return false;
        }

        $bin = inet_pton($ip);

        if ($bin === false || \strlen($bin) !== 16) {
            return false;
        }

        $hex = bin2hex($bin);

        // IPv4 embarquée : mappée ::ffff:a.b.c.d, NAT64 64:ff9b::/96, 6to4 2002:AABB:CCDD::/16
        if (str_starts_with($hex, '00000000000000000000ffff')) {
            return self::isPublicIpv4(long2ip((int) hexdec(substr($hex, 24, 8))));
        }

        if (str_starts_with($hex, '0064ff9b0000000000000000')) {
            return self::isPublicIpv4(long2ip((int) hexdec(substr($hex, 24, 8))));
        }

        if (str_starts_with($hex, '2002')) {
            return self::isPublicIpv4(long2ip((int) hexdec(substr($hex, 4, 8))));
        }

        $blocked = [
            '00000000000000000000000000000000/128', // ::
            '00000000000000000000000000000001/128', // ::1
            '0064ff9b0001/48',                       // 64:ff9b:1::/48 NAT64 local
            '0100000000000000/64',                   // 100::/64 discard
            '20010000/32',                           // 2001::/32 Teredo (IPv4 obfusquée)
            '20010010/28',                           // 2001:10::/28 ORCHID
            '20010db8/32',                           // 2001:db8::/32 documentation
            'fc/7',                                  // fc00::/7 ULA
            'fe80/10',                               // fe80::/10 lien local
            'fec0/10',                               // fec0::/10 site local (obsolète)
            'ff/8',                                  // ff00::/8 multicast
        ];

        $bits = '';

        foreach (str_split($hex) as $h) {
            $bits .= str_pad(base_convert($h, 16, 2), 4, '0', \STR_PAD_LEFT);
        }

        foreach ($blocked as $rule) {
            [$prefixHex, $len] = explode('/', $rule);
            $prefixBits = '';

            foreach (str_split($prefixHex) as $h) {
                $prefixBits .= str_pad(base_convert($h, 16, 2), 4, '0', \STR_PAD_LEFT);
            }

            if (substr($bits, 0, (int) $len) === substr($prefixBits, 0, (int) $len)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIpv4(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        $long = sprintf('%u', $long);

        $blocked = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
            ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ];

        foreach ($blocked as [$net, $len]) {
            $mask = $len === 0 ? 0 : (~0 << (32 - $len)) & 0xFFFFFFFF;

            if (((int) $long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------

    private static function curlRequest(array $t, string $method, array $options): object
    {
        $maxBytes = max(0, (int) ($options['max_bytes'] ?? 0));
        $toFile   = (string) ($options['to_file'] ?? '');
        $headers  = [];
        $body     = '';
        $bytes    = 0;
        $fh       = null;

        if ($toFile !== '') {
            $fh = @fopen($toFile, 'wb');

            if ($fh === false) {
                throw new McpToolException('Cannot write the download target file.');
            }
        }

        $ch   = curl_init($t['url']);
        $opts = [
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 10),
            \CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 25),
            \CURLOPT_USERAGENT      => (string) ($options['user_agent'] ?? 'SensoMCP'),
            \CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_ssl'] ?? true),
            \CURLOPT_SSL_VERIFYHOST => ($options['verify_ssl'] ?? true) ? 2 : 0,
            \CURLOPT_NOBODY         => $method === 'HEAD',
            \CURLOPT_HTTPHEADER     => self::headerLines((array) ($options['headers'] ?? [])),
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                } elseif (trim($line) === '' ) {
                    // fin d'un bloc d'en-têtes : rien à faire (pas de suivi, un seul bloc par requête)
                }

                return \strlen($line);
            },
            \CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$bytes, $maxBytes, $fh): int {
                $bytes += \strlen($chunk);

                if ($maxBytes > 0 && $bytes > $maxBytes) {
                    return -1; // abandon → CURLE_WRITE_ERROR
                }

                if ($fh) {
                    fwrite($fh, $chunk);
                } else {
                    $body .= $chunk;
                }

                return \strlen($chunk);
            },
        ];

        // Protocoles : http(s) seulement, même sans suivi (défense en profondeur).
        if (\defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[\CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (\defined('CURLPROTO_HTTP')) {
            $opts[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
        }

        // Épinglage : la connexion va aux adresses validées (toutes familles), pas à une
        // seconde résolution DNS (anti-rebinding). Liste d'adresses : libcurl ≥ 7.59.
        if ($t['ips'] !== []) {
            $version = curl_version()['version_number'] ?? 0;
            $list    = $version >= 0x073B00 ? implode(',', $t['ips']) : $t['ips'][0];

            $opts[\CURLOPT_RESOLVE] = [$t['host'] . ':' . $t['port'] . ':' . $list];
        }

        if ($maxBytes > 0 && \defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $opts[\CURLOPT_MAXFILESIZE_LARGE] = $maxBytes; // refus précoce sur Content-Length
        }

        curl_setopt_array($ch, $opts);
        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($fh) {
            fclose($fh);
        }

        if ($maxBytes > 0 && ($errno === \CURLE_WRITE_ERROR || $errno === \CURLE_FILESIZE_EXCEEDED || $bytes > $maxBytes)) {
            if ($toFile !== '') {
                @unlink($toFile);
            }

            throw new McpToolException(sprintf(
                'The remote resource exceeds the allowed size (%s).',
                self::humanBytes($maxBytes)
            ));
        }

        if ($ok === false || $errno !== 0) {
            if ($toFile !== '') {
                @unlink($toFile);
            }

            throw new McpToolException('Fetch failed: ' . ($error ?: 'cURL error ' . $errno));
        }

        return (object) ['code' => $code, 'headers' => $headers, 'body' => $body, 'url' => $t['url'], 'bytes' => $bytes];
    }

    /** Repli sans cURL : client Joomla (stream), suivi désactivé, pas d'épinglage possible. */
    private static function streamRequest(array $t, string $method, array $options): object
    {
        static $warned = false;

        if (!$warned) {
            $warned = true;
            Log::add('UrlGuard::fetch: cURL unavailable, falling back to the stream transport without DNS pinning.', Log::WARNING, 'com_sensomcp');
        }

        $maxBytes = max(0, (int) ($options['max_bytes'] ?? 0));
        $registry = new Registry([
            'follow_location' => false,
            'userAgent'       => (string) ($options['user_agent'] ?? 'SensoMCP'),
            'transport.stream' => ['max_redirects' => 0],
        ]);
        $http    = HttpFactory::getHttp($registry, ['stream']);
        $timeout = (int) ($options['timeout'] ?? 25);
        $headers = (array) ($options['headers'] ?? []);

        try {
            $response = $method === 'HEAD' ? $http->head($t['url'], $headers, $timeout) : $http->get($t['url'], $headers, $timeout);
        } catch (\Throwable $e) {
            throw new McpToolException('Fetch failed: ' . $e->getMessage());
        }

        $body = (string) $response->body;

        if ($maxBytes > 0 && \strlen($body) > $maxBytes) {
            throw new McpToolException(sprintf('The remote resource exceeds the allowed size (%s).', self::humanBytes($maxBytes)));
        }

        $out = [];

        foreach ($response->headers as $k => $v) {
            $out[strtolower((string) $k)] = \is_array($v) ? (string) end($v) : (string) $v;
        }

        if (!empty($options['to_file'])) {
            if (@file_put_contents((string) $options['to_file'], $body) === false) {
                throw new McpToolException('Cannot write the download target file.');
            }

            $body = '';
        }

        return (object) ['code' => (int) $response->code, 'headers' => $out, 'body' => $body, 'url' => $t['url'], 'bytes' => \strlen((string) $response->body)];
    }

    private static function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return parse_url($base, \PHP_URL_SCHEME) . ':' . $location;
        }

        $b    = parse_url($base);
        $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($location, '/')) {
            return $root . $location;
        }

        $dir = rtrim(\dirname((string) ($b['path'] ?? '/')), '/');

        return $root . $dir . '/' . $location;
    }

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

        return array_values(array_unique($ips));
    }

    /** @return string[] */
    private static function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $k => $v) {
            $lines[] = \is_int($k) ? (string) $v : $k . ': ' . $v;
        }

        return $lines;
    }

    private static function redact(string $url): string
    {
        return (string) preg_replace('/([?&](key|token|api_key|secret)=)[^&]*/i', '$1***', $url);
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B';
    }

    /**
     * Borne de taille de com_media : `upload_maxsize` (Mo) si > 0, sinon la plus petite
     * des limites PHP `upload_max_filesize` / `post_max_size` (0 = illimitée), comme
     * MediaHelper::canUpload().
     */
    public static function mediaMaxBytes(): int
    {
        $mb = (float) \Joomla\CMS\Component\ComponentHelper::getParams('com_media')->get('upload_maxsize', 0);

        if ($mb > 0) {
            return (int) round($mb * 1024 * 1024);
        }

        return self::phpUploadMaxBytes();
    }

    /** Plus petite des limites PHP d'upload (0 si toutes illimitées). */
    public static function phpUploadMaxBytes(): int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ]);

        return $limits ? (int) min($limits) : 0;
    }

    private static function iniBytes(string $val): int
    {
        $val = trim($val);

        if ($val === '' || $val === '0' || $val === '-1') {
            return 0;
        }

        $unit = strtolower(substr($val, -1));
        $num  = (float) $val;

        return (int) match ($unit) {
            'g'     => $num * 1073741824,
            'm'     => $num * 1048576,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
