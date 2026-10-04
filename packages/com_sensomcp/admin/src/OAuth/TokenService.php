<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Émission et validation des jetons.
 *
 * Access token = JWT compact signé HS256 avec le secret du site — auto-porté
 * (aucune table). Le claim `aud` porte l'URI canonique du serveur MCP : le
 * serveur de ressource DOIT valider cette audience (spec MCP / RFC 8707).
 *
 * Codes d'autorisation et refresh tokens = chaînes opaques aléatoires,
 * stockées hachées (SHA-256) côté serveur.
 */
final class TokenService
{
    /** Durée de vie de l'access token (secondes). */
    public const ACCESS_TTL = 3600;

    private function secret(): string
    {
        $secret = (string) Factory::getApplication()->get('secret', '');

        if ($secret === '') {
            throw new OAuthException('server_error', 'Secret du site absent', 500);
        }

        return $secret;
    }

    /**
     * Émet un access token signé.
     *
     * @return array{0:string,1:int}  [token, expires_in]
     */
    public function issueAccessToken(int $userId, string $clientId, string $scope, string $audience): array
    {
        $now = time();

        $payload = [
            'iss'       => rtrim(\Joomla\CMS\Uri\Uri::root(), '/'),
            'sub'       => (string) $userId,
            'aud'       => $audience,
            'client_id' => $clientId,
            'scope'     => $scope,
            'iat'       => $now,
            'nbf'       => $now,
            'exp'       => $now + self::ACCESS_TTL,
            'jti'       => bin2hex(random_bytes(8)),
        ];

        $header  = ['alg' => 'HS256', 'typ' => 'at+jwt'];
        $segments = [
            Pkce::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            Pkce::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signing   = implode('.', $segments);
        $signature = Pkce::base64UrlEncode(hash_hmac('sha256', $signing, $this->secret(), true));

        return [$signing . '.' . $signature, self::ACCESS_TTL];
    }

    /**
     * Valide un access token et renvoie ses claims.
     *
     * @throws OAuthException  si signature/expiration/audience invalides.
     */
    public function verifyAccessToken(string $token, string $expectedAudience): array
    {
        $parts = explode('.', $token);

        if (\count($parts) !== 3) {
            throw new OAuthException('invalid_token', 'Format de jeton invalide', 401);
        }

        [$h, $p, $sig] = $parts;

        $expected = Pkce::base64UrlEncode(hash_hmac('sha256', $h . '.' . $p, $this->secret(), true));

        if (!hash_equals($expected, $sig)) {
            throw new OAuthException('invalid_token', 'Signature invalide', 401);
        }

        $payload = json_decode(self::base64UrlDecode($p), true);

        if (!\is_array($payload)) {
            throw new OAuthException('invalid_token', 'Charge utile illisible', 401);
        }

        $now = time();

        if (($payload['exp'] ?? 0) < $now) {
            throw new OAuthException('invalid_token', 'Jeton expiré', 401);
        }

        if (($payload['nbf'] ?? 0) > $now + 60) {
            throw new OAuthException('invalid_token', 'Jeton pas encore valide', 401);
        }

        if (($payload['aud'] ?? '') !== $expectedAudience) {
            throw new OAuthException('invalid_token', 'Audience invalide', 401);
        }

        return $payload;
    }

    /** Chaîne opaque aléatoire (base64url), pour codes et refresh tokens. */
    public function opaque(int $bytes = 32): string
    {
        return Pkce::base64UrlEncode(random_bytes($bytes));
    }

    /** Hachage de stockage d'une chaîne opaque. */
    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function base64UrlDecode(string $data): string
    {
        $pad = \strlen($data) % 4;

        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
