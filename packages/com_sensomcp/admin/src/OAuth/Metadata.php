<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Construit les documents de métadonnées OAuth et centralise les URLs des
 * endpoints (source unique de vérité, notamment pour l'URI canonique du
 * serveur MCP servant d'audience aux jetons).
 */
final class Metadata
{
    /** Racine du site sans slash final, ex. https://dev.sensomedia.com */
    public static function root(): string
    {
        return rtrim(Uri::root(), '/');
    }

    /** Émetteur (issuer) OAuth = racine du site. */
    public static function issuer(): string
    {
        return self::root();
    }

    /** URI canonique du serveur MCP (= audience des access tokens). */
    public static function mcpResource(): string
    {
        return self::root() . '/api/index.php/v1/mcp';
    }

    public static function authorizationEndpoint(): string
    {
        return self::root() . '/index.php?option=com_sensomcp&task=oauth.authorize';
    }

    public static function tokenEndpoint(): string
    {
        return self::root() . '/api/index.php/v1/mcp/oauth/token';
    }

    public static function registrationEndpoint(): string
    {
        return self::root() . '/api/index.php/v1/mcp/oauth/register';
    }

    /**
     * Scope « parapluie » : équivaut, à l'autorisation, à l'ensemble des scopes
     * que l'utilisateur peut réellement utiliser (voir OauthController::authorize).
     */
    public const UMBRELLA_SCOPE = 'mcp';

    /**
     * Scopes annoncés dans les documents de découverte publics (RFC 8414 / 9728).
     *
     * Volontairement STATIQUE : seul le scope parapluie est publié. Lister les
     * scopes réels (`yootheme.write`, `extensions.install`…) revenait à publier,
     * à tout anonyme, l'inventaire des plugins payants installés, et à instancier
     * tous les plugins MCP à chaque requête anonyme sur `.well-known`. Le détail
     * n'est révélé qu'après authentification (écran de consentement, tools/list).
     * Un client qui demande `scope=mcp` (ou rien) se voit proposer tous les
     * scopes disponibles ; un client qui demande des scopes précis est servi
     * comme avant.
     */
    public static function scopesSupported(): array
    {
        return [self::UMBRELLA_SCOPE];
    }

    /** Métadonnées de ressource protégée (RFC 9728). */
    public static function protectedResource(): array
    {
        return [
            'resource'                 => self::mcpResource(),
            'authorization_servers'    => [self::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported'         => self::scopesSupported(),
        ];
    }

    /** Métadonnées du serveur d'autorisation (RFC 8414). */
    public static function authorizationServer(): array
    {
        return [
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => self::authorizationEndpoint(),
            'token_endpoint'                        => self::tokenEndpoint(),
            'registration_endpoint'                 => self::registrationEndpoint(),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported'                      => self::scopesSupported(),
        ];
    }
}
