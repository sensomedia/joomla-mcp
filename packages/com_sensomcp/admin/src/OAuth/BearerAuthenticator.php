<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolState;

\defined('_JEXEC') or die;

/**
 * Authentification Bearer unifiée pour l'endpoint MCP.
 *
 * Accepte deux types de jetons dans l'en-tête `Authorization: Bearer …` :
 *   1. un access token OAuth 2.1 émis par ce serveur (JWT signé, audience = MCP) ;
 *   2. un jeton API Joomla natif (compat Claude Desktop / clients à en-tête).
 *
 * Renvoie l'ID utilisateur authentifié, ou null.
 */
final class BearerAuthenticator
{
    private const ALLOWED_ALGOS = ['sha256', 'sha512'];

    /**
     * @return array{user_id:int, scopes:string[], client_id:string}|null  client_id vide pour un jeton API Joomla.
     */
    public function authenticate(): ?array
    {
        $bearer = $this->bearerToken();

        if ($bearer === '') {
            return null;
        }

        // 1) Access token OAuth émis par nous → scopes issus du claim `scope`.
        try {
            $claims = (new TokenService())->verifyAccessToken($bearer, Metadata::mcpResource());
            $uid    = (int) ($claims['sub'] ?? 0);

            if ($uid <= 0 || !$this->userIsUsable($uid)) {
                return null;
            }

            // Le droit d'utiliser l'API est revérifié à CHAQUE requête, pas seulement au
            // consentement : un compte retiré du groupe autorisé perd l'accès tout de suite
            // (audit 2026-10-12, M1 ; même règle que le chemin jeton Joomla ci-dessous).
            $jwtUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($uid);

            if (!$jwtUser || !$jwtUser->id || !$jwtUser->authorise('core.login.api')) {
                return null;
            }

            // Révocation globale OAuth (réponse à incident) : les JWT étant sans
            // état, le seul moyen de tuer d'un coup tous les jetons déjà émis est
            // de rejeter ceux dont l'`iat` précède le point de révocation.
            $revokeBefore = ToolState::oauthRevokeBefore();

            if ($revokeBefore > 0 && (int) ($claims['iat'] ?? 0) < $revokeBefore) {
                return null;
            }

            $scopes = array_values(array_filter(explode(' ', (string) ($claims['scope'] ?? ''))));

            return ['user_id' => $uid, 'scopes' => $scopes, 'client_id' => (string) ($claims['client_id'] ?? '')];
        } catch (OAuthException) {
            // Pas un JWT valide → on tente le jeton Joomla.
        }

        // 2) Jeton API Joomla natif = jeton de confiance → accès total (scope *),
        //    mais SEULEMENT si l'utilisateur a réellement le droit de login API
        //    (défense en profondeur, comme l'exige le consentement OAuth). Le gate
        //    ACL par outil (ToolRegistry) borne ensuite ce que ce compte peut faire.
        $uid = $this->validateJoomlaToken($bearer);

        if ($uid === null) {
            return null;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($uid);

        if (!$user || $user->get('block') || !$user->authorise('core.login.api')) {
            return null;
        }

        return ['user_id' => $uid, 'scopes' => ['*'], 'client_id' => ''];
    }

    private function bearerToken(): string
    {
        $header = (string) Factory::getApplication()->getInput()->server->get('HTTP_AUTHORIZATION', '', 'string');

        if ($header === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }

        return trim(substr($header, 7));
    }

    private function validateJoomlaToken(string $token): ?int
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), false);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        $parts = explode(':', $decoded, 3);

        if (\count($parts) !== 3) {
            return null;
        }

        [$algo, $uid, $hmac] = $parts;
        $uid = (int) $uid;

        if (!\in_array($algo, self::ALLOWED_ALGOS, true) || $uid <= 0) {
            return null;
        }

        $secret = (string) Factory::getApplication()->get('secret', '');

        if ($secret === '') {
            return null;
        }

        [$seed, $enabled] = $this->tokenSeed($uid);

        if ($seed === '' || !$enabled) {
            return null;
        }

        $reference = hash_hmac($algo, base64_decode($seed), $secret);

        if (!hash_equals($reference, $hmac)) {
            return null;
        }

        return $this->userIsUsable($uid) ? $uid : null;
    }

    /**
     * @return array{0:string,1:bool}  [seed base64, enabled]
     */
    private function tokenSeed(int $uid): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['profile_key', 'profile_value']))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :uid')
            ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote('joomlatoken.%'))
            ->bind(':uid', $uid, ParameterType::INTEGER);

        $rows    = $db->setQuery($query)->loadAssocList('profile_key', 'profile_value');
        $seed    = (string) ($rows['joomlatoken.token'] ?? '');
        $enabled = (string) ($rows['joomlatoken.enabled'] ?? '0') === '1';

        return [$seed, $enabled];
    }

    private function userIsUsable(int $uid): bool
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['block', 'activation']))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :uid')
            ->bind(':uid', $uid, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();

        if (!$row || (int) $row->block === 1) {
            return false;
        }

        // activation vide, '0' ou null = compte actif.
        return \in_array((string) $row->activation, ['', '0'], true);
    }
}
