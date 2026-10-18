<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\RateLimiter;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\ClientStore;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\CodeStore;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\OAuthException;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Pkce;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\RefreshStore;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\TokenService;

\defined('_JEXEC') or die;

/**
 * Endpoints OAuth 2.1 machine-à-machine de l'API application :
 *   - metaResource     : /.well-known/oauth-protected-resource  (via rewrite nginx)
 *   - metaAuthServer   : /.well-known/oauth-authorization-server (via rewrite nginx)
 *   - register         : Dynamic Client Registration (RFC 7591)
 *   - token            : échange code+PKCE / refresh → access token
 *
 * Ces routes sont déclarées PUBLIQUES (public => true) : elles ne doivent pas
 * exiger de token API — c'est ici qu'on en fabrique.
 */
class OauthController extends BaseController
{
    // ------------------------------------------------------------------ Métadonnées

    public function metaResource(): void
    {
        $this->json(200, Metadata::protectedResource());
    }

    public function metaAuthServer(): void
    {
        $this->json(200, Metadata::authorizationServer());
    }

    /**
     * Diagnostic : indique si PHP a reçu l'en-tête Authorization (sert à la
     * vue Réglages pour savoir si la directive de passthrough est nécessaire).
     */
    public function authcheck(): void
    {
        $received = !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $this->json(200, ['authorization_received' => $received]);
    }

    // ------------------------------------------------------------------ Enregistrement

    public function register(): void
    {
        try {
            // Enregistrement dynamique public (RFC 7591) : limité par IP (audit M2) —
            // par heure (rafale) et par 24 h (accumulation lente), valeurs dans les options.
            $app    = Factory::getApplication();
            $ip     = (string) $app->getInput()->server->get('REMOTE_ADDR', '', 'string');
            $params = ComponentHelper::getParams('com_sensomcp');
            $hour   = (int) $params->get('oauth_register_max_hour', 10);
            $day    = (int) $params->get('oauth_register_max_day', 50);

            if ($hour > 0 && ($rl = RateLimiter::hit('dcr:' . $ip, $hour, 3600)) !== null) {
                $this->json(429, ['error' => 'temporarily_unavailable', 'error_description' => 'Too many client registrations, retry later'], ['Retry-After' => (string) $rl['retry_after']]);

                return;
            }

            $store = new ClientStore();

            if ($day > 0 && $store->countRegisteredFromIp($ip, 86400) >= $day) {
                $this->json(429, ['error' => 'temporarily_unavailable', 'error_description' => 'Too many client registrations from this address today'], ['Retry-After' => '3600']);

                return;
            }

            $body = json_decode(file_get_contents('php://input') ?: '', true);

            if (!\is_array($body)) {
                throw new OAuthException('invalid_client_metadata', 'Corps JSON invalide');
            }

            $client = $store->register($body, $ip);
            $this->json(201, $client);
        } catch (OAuthException $e) {
            $this->oauthError($e);
        } catch (\Throwable $e) {
            $this->json(500, ['error' => 'server_error', 'error_description' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ Token

    public function token(): void
    {
        try {
            $input = Factory::getApplication()->getInput();
            $grant = (string) $input->post->get('grant_type', '', 'string');

            match ($grant) {
                'authorization_code' => $this->grantAuthorizationCode($input),
                'refresh_token'      => $this->grantRefreshToken($input),
                default              => throw new OAuthException('unsupported_grant_type', 'grant_type non supporté'),
            };
        } catch (OAuthException $e) {
            $this->oauthError($e);
        } catch (\Throwable $e) {
            $this->json(500, ['error' => 'server_error', 'error_description' => $e->getMessage()]);
        }
    }

    private function grantAuthorizationCode($input): void
    {
        $clients  = new ClientStore();
        $tokens   = new TokenService();
        $codes    = new CodeStore($tokens);
        $refreshS = new RefreshStore($tokens);

        $clientId     = (string) $input->post->get('client_id', '', 'raw');
        $code         = (string) $input->post->get('code', '', 'raw');
        $redirectUri  = (string) $input->post->get('redirect_uri', '', 'raw');
        $codeVerifier = (string) $input->post->get('code_verifier', '', 'raw');

        $client = $clients->find($clientId);

        if (!$client) {
            throw new OAuthException('invalid_client', 'Client inconnu', 401);
        }

        $this->authenticateClient($client, $input);

        $row = $codes->consume($code);

        if (!$row || $row->client_id !== $clientId) {
            throw new OAuthException('invalid_grant', 'Code invalide ou expiré');
        }

        if ($row->redirect_uri !== $redirectUri) {
            throw new OAuthException('invalid_grant', 'redirect_uri ne correspond pas');
        }

        if (!Pkce::verify($codeVerifier, $row->code_challenge, $row->code_challenge_method)) {
            throw new OAuthException('invalid_grant', 'Vérification PKCE échouée');
        }

        $audience = $row->resource !== '' ? $row->resource : Metadata::mcpResource();
        $this->issueTokens($tokens, $refreshS, (int) $row->user_id, $clientId, (string) $row->scope, $audience);
    }

    private function grantRefreshToken($input): void
    {
        $clients  = new ClientStore();
        $tokens   = new TokenService();
        $refreshS = new RefreshStore($tokens);

        $clientId     = (string) $input->post->get('client_id', '', 'raw');
        $refreshToken = (string) $input->post->get('refresh_token', '', 'raw');

        $client = $clients->find($clientId);

        if (!$client) {
            throw new OAuthException('invalid_client', 'Client inconnu', 401);
        }

        $this->authenticateClient($client, $input);

        $row = $refreshS->consume($refreshToken);

        if (!$row || $row->client_id !== $clientId) {
            throw new OAuthException('invalid_grant', 'Refresh token invalide');
        }

        // Droits revérifiés à chaque rotation + durée absolue de la connexion (audit M1).
        if (($why = $refreshS->renewalRefusal($row)) !== null) {
            throw new OAuthException('invalid_grant', $why);
        }

        $audience = $row->resource !== '' ? $row->resource : Metadata::mcpResource();
        $this->issueTokens($tokens, $refreshS, (int) $row->user_id, $clientId, (string) $row->scope, $audience, (string) ($row->origin ?: $row->created), (string) ($row->family ?? ''));
    }

    private function issueTokens(
        TokenService $tokens,
        RefreshStore $refreshS,
        int $userId,
        string $clientId,
        string $scope,
        string $audience,
        ?string $origin = null,
        ?string $family = null
    ): void {
        [$access, $expiresIn] = $tokens->issueAccessToken($userId, $clientId, $scope, $audience);
        $refresh              = $refreshS->create($clientId, $userId, $scope, $audience, $origin, $family);

        $this->json(200, [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'refresh_token' => $refresh,
            'scope'         => $scope,
        ], ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * Authentifie un client confidentiel (secret) ; ne fait rien pour un client
     * public (token_endpoint_auth_method = none, cas de claude.ai via PKCE).
     */
    private function authenticateClient(object $client, $input): void
    {
        if ($client->token_endpoint_auth_method === 'none') {
            return;
        }

        $secret = (string) $input->post->get('client_secret', '', 'raw');

        if ($secret === '' && isset($_SERVER['PHP_AUTH_PW'])) {
            $secret = (string) $_SERVER['PHP_AUTH_PW'];
        }

        if ($client->client_secret === null || !password_verify($secret, $client->client_secret)) {
            throw new OAuthException('invalid_client', 'Authentification client échouée', 401);
        }
    }

    // ------------------------------------------------------------------ Helpers HTTP

    private function oauthError(OAuthException $e): void
    {
        $this->json($e->getHttpStatus(), [
            'error'             => $e->getErrorCode(),
            'error_description' => $e->getMessage(),
        ]);
    }

    private function json(int $status, $payload, array $headers = []): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Status', (string) $status, true);
        http_response_code($status);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

        foreach ($headers as $k => $v) {
            $app->setHeader($k, $v, true);
        }

        $app->sendHeaders();
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $app->close();
    }
}
