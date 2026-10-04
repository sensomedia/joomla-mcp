<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

\defined('_JEXEC') or die;

/**
 * Vérification PKCE (RFC 7636). Seule la méthode S256 est acceptée
 * (obligatoire en OAuth 2.1 pour les clients publics).
 */
final class Pkce
{
    public static function verify(string $codeVerifier, string $codeChallenge, string $method = 'S256'): bool
    {
        if ($codeVerifier === '' || $codeChallenge === '') {
            return false;
        }

        if (strtoupper($method) !== 'S256') {
            return false;
        }

        $computed = self::base64UrlEncode(hash('sha256', $codeVerifier, true));

        return hash_equals($codeChallenge, $computed);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
