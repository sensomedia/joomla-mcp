<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Registre des clients OAuth (Dynamic Client Registration, RFC 7591).
 */
final class ClientStore
{
    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Enregistre un nouveau client et renvoie ses métadonnées (dont client_id).
     *
     * @param array $meta  Données de la requête RFC 7591 (redirect_uris, client_name, …)
     */
    public function register(array $meta, string $ip = ''): array
    {
        $redirectUris = $meta['redirect_uris'] ?? [];

        if (!\is_array($redirectUris) || $redirectUris === []) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris requis');
        }

        foreach ($redirectUris as $uri) {
            if (!$this->isValidRedirectUri((string) $uri)) {
                throw new OAuthException('invalid_redirect_uri', 'redirect_uri non autorisé : ' . $uri);
            }
        }

        $authMethod = (string) ($meta['token_endpoint_auth_method'] ?? 'none');
        $clientId   = 'mcp_' . bin2hex(random_bytes(16));
        $secret     = null;
        $secretHash = null;

        // Client confidentiel → on génère un secret (haché en base).
        if ($authMethod !== 'none') {
            $secret     = bin2hex(random_bytes(24));
            $secretHash = password_hash($secret, PASSWORD_DEFAULT);
        }

        $db  = $this->db();
        $now = Factory::getDate()->toSql();

        $row = (object) [
            'client_id'                  => $clientId,
            'client_secret'              => $secretHash,
            'client_name'                => self::cleanName((string) ($meta['client_name'] ?? '')),
            'redirect_uris'              => json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES),
            'grant_types'                => 'authorization_code refresh_token',
            'token_endpoint_auth_method' => $authMethod,
            'created'                    => $now,
            'created_ip'                 => substr($ip, 0, 45),
        ];

        $db->insertObject('#__sensomcp_oauth_clients', $row);

        $out = [
            'client_id'                  => $clientId,
            'redirect_uris'              => array_values($redirectUris),
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'client_name'                => $row->client_name,
        ];

        if ($secret !== null) {
            $out['client_secret'] = $secret;
        }

        return $out;
    }

    /**
     * Nom de client affiché au consentement : balises et caractères de contrôle retirés,
     * espaces normalisés, 100 caractères max, « MCP Client » si vide (audit M2).
     */
    public static function cleanName(string $name): string
    {
        $name = strip_tags($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? 'MCP Client' : mb_substr($name, 0, 100);
    }

    /** Nombre de clients enregistrés depuis cette IP dans les `$seconds` dernières secondes. */
    public function countRegisteredFromIp(string $ip, int $seconds): int
    {
        if ($ip === '') {
            return 0;
        }

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__sensomcp_oauth_clients'))
            ->where($db->quoteName('created_ip') . ' = :ip')
            ->where($db->quoteName('created') . ' >= ' . $db->quote(Factory::getDate('-' . max(1, $seconds) . ' seconds')->toSql()))
            ->bind(':ip', $ip);

        try {
            return (int) $db->setQuery($q)->loadResult();
        } catch (\Throwable) {
            return 0; // colonne absente (schéma non migré) : fail-open
        }
    }

    public function find(string $clientId): ?object
    {
        $db    = $this->db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__sensomcp_oauth_clients'))
            ->where($db->quoteName('client_id') . ' = :cid')
            ->bind(':cid', $clientId, ParameterType::STRING);

        $row = $db->setQuery($query)->loadObject();

        if ($row) {
            $row->redirect_uris = json_decode($row->redirect_uris, true) ?: [];
        }

        return $row ?: null;
    }

    public function redirectUriAllowed(object $client, string $redirectUri): bool
    {
        // Correspondance EXACTE (spec MCP / OAuth 2.1).
        return \in_array($redirectUri, (array) $client->redirect_uris, true);
    }

    private function isValidRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);

        if ($parts === false || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');

        // HTTPS obligatoire, sauf localhost (OAuth 2.1 §1.5).
        if ($scheme === 'https') {
            return true;
        }

        if ($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }

        return false;
    }
}
