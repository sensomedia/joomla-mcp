<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\RequestLog;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolCollector;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolState;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\RateLimiter;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\BearerAuthenticator;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;
use Sensomedia\Component\Sensomcp\Api\Mcp\Server;

\defined('_JEXEC') or die;

/**
 * Point d'entrée MCP (transport HTTP « streamable », mode JSON sans SSE).
 *
 * Route déclarée par plg_webservices_sensomcp :
 *   POST /api/index.php/v1/mcp   →  controller « mcp », task « dispatch »
 *
 * L'authentification (Bearer token API Joomla) est assurée en amont par
 * l'API application ; la route est non publique, donc un utilisateur valide
 * est déjà connecté quand on arrive ici.
 */
class McpController extends BaseController
{
    private const SERVER_NAME    = 'senso-mcp';
    private const SERVER_VERSION = '0.1.0';

    /** Niveau de tampon de sortie au moment de l'armement du blindage. */
    private int $shieldObLevel = 0;

    /**
     * Reçoit un message (ou un lot) JSON-RPC et renvoie la ou les réponses.
     */
    public function dispatch(): void
    {
        // Blindage de la sortie : aucun warning/notice/deprecation PHP ni echo
        // parasite (émis par le core Joomla ou un plugin tiers pendant un
        // handler d'outil) ne doit polluer le corps de la réponse. Sans ça, un
        // simple E_WARNING (ex. rebuild nested-set de com_categories/com_menus,
        // getimagesize() sur un dossier scanné par list_media) est écrit en
        // tête du corps HTTP, devant le JSON, et le client MCP échoue au
        // parsing (« Unexpected token '<', "<br />\n<b>"... is not valid JSON »).
        $this->beginOutputShield();

        $app = Factory::getApplication();
        $ip  = (string) $app->getInput()->server->get('REMOTE_ADDR', '', 'string');

        // Arrêt d'urgence : verrou global (réponse à incident). Refuse TOUTE
        // requête, quel que soit le jeton, avant même l'authentification.
        if (ToolState::isLocked()) {
            RequestLog::record(0, $ip, '(locked)', null, false, 'emergency stop');
            $this->serviceUnavailable($app);

            return;
        }

        // Authentification Bearer (JWT OAuth émis par nous, ou jeton API Joomla).
        // La route MCP est publique côté API app : on porte nous-mêmes l'auth.
        $auth = (new BearerAuthenticator())->authenticate();

        if ($auth === null) {
            RequestLog::record(0, $ip, '(auth)', null, false, 'unauthorized');
            $this->unauthorized($app);

            return;
        }

        // Rate limiting (protection de disponibilité) — après auth, avant tout
        // travail lourd. Clé = identité du porteur ; fail-open si indisponible.
        $rl = RateLimiter::check((int) $auth['user_id'], $ip);

        if ($rl !== null) {
            RequestLog::record((int) $auth['user_id'], $ip, '(ratelimit)', null, false, 'rate limited');
            $this->tooManyRequests($app, (int) $rl['retry_after']);

            return;
        }

        // On charge l'identité pour que l'ACL com_content (création d'articles…)
        // s'applique au compte authentifié. On pose AUSSI l'utilisateur de
        // session : beaucoup de composants (com_fields canEditFieldValue, etc.)
        // lisent le legacy Factory::getUser() = utilisateur de session, que
        // loadIdentity() seul ne met pas à jour (sinon → invité → ACL refusée).
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($auth['user_id']);
        $app->loadIdentity($user);
        $app->getSession()->set('user', $user);

        $raw     = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        // Erreur de parsing JSON.
        if ($raw !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->respond($app, [
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error'],
            ]);

            return;
        }

        $server = $this->buildServer($user, $auth['scopes']);

        // Lot JSON-RPC (tableau de messages) ou message unique.
        $isBatch  = \is_array($decoded) && array_is_list($decoded) && $decoded !== [];
        $messages = $isBatch ? $decoded : [$decoded];
        $out      = [];

        foreach ($messages as $message) {
            if (!\is_array($message)) {
                $out[] = [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => ['code' => -32600, 'message' => 'Invalid Request'],
                ];

                continue;
            }

            $response = $server->handle($message);

            $method = (string) ($message['method'] ?? '');

            if ($method !== '') {
                $tool    = $method === 'tools/call' ? (string) ($message['params']['name'] ?? '') : '';
                $isError = isset($response['error']) || (($response['result']['isError'] ?? false) === true);
                $err     = null;

                if (isset($response['error'])) {
                    $err = (string) ($response['error']['message'] ?? 'error');
                } elseif ($isError) {
                    $err = (string) ($response['result']['content'][0]['text'] ?? 'tool error');
                }

                RequestLog::record((int) $user->id, $ip, $method, $tool !== '' ? $tool : null, !$isError, $err);
            }

            if ($response !== null) {
                $out[] = $response;
            }
        }

        // Uniquement des notifications → 202 sans corps.
        if ($out === []) {
            $app->setHeader('Status', '202', true);
            http_response_code(202);
            $this->closeRaw($app, '');

            return;
        }

        $this->respond($app, $isBatch ? $out : $out[0]);
    }

    /**
     * @param string[] $scopes Scopes accordés au porteur du jeton.
     */
    /**
     * @param string[] $scopes Scopes accordés au porteur du jeton.
     */
    private function buildServer(User $user, array $scopes): Server
    {
        // Moteur pur : TOUS les outils viennent des plugins « sensomcp ».
        $registry = ToolCollector::collect($user);

        // Application des scopes : list()/call() sont filtrés en conséquence.
        $registry->setGrantedScopes($scopes);

        // Politique de sécurité : mode lecture seule global + états par outil
        // (panneau « Plugins et sécurité »). list()/call() les respectent aussi.
        $registry->setReadOnly(ToolState::isReadOnly());
        $registry->setToolPolicy(ToolState::getMap());

        return new Server($registry, self::SERVER_NAME, self::SERVER_VERSION);
    }

    /**
     * 401 conforme au spec MCP : en-tête WWW-Authenticate pointant vers les
     * métadonnées de ressource protégée, qui amorcent la découverte OAuth côté
     * client (claude.ai).
     */
    private function unauthorized($app): void
    {
        $metaUrl = Metadata::root() . '/.well-known/oauth-protected-resource';

        $app->setHeader('Status', '401', true);
        http_response_code(401);
        $app->setHeader('WWW-Authenticate', 'Bearer resource_metadata="' . $metaUrl . '"', true);

        $this->closeRaw($app, (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => -32001, 'message' => 'Unauthorized'],
        ], JSON_UNESCAPED_SLASHES));
    }

    /** Réponse 503 : serveur MCP verrouillé par l'administrateur (arrêt d'urgence). */
    private function serviceUnavailable($app): void
    {
        $app->setHeader('Status', '503', true);
        http_response_code(503);

        $this->closeRaw($app, (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => -32000, 'message' => 'MCP server is temporarily disabled by the administrator (emergency stop).'],
        ], JSON_UNESCAPED_SLASHES));
    }

    /** Réponse 429 (débit dépassé) avec en-tête Retry-After. */
    private function tooManyRequests($app, int $retryAfter): void
    {
        $app->setHeader('Status', '429', true);
        http_response_code(429);
        $app->setHeader('Retry-After', (string) $retryAfter, true);

        $this->closeRaw($app, (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => -32000, 'message' => 'Rate limit exceeded. Retry after ' . $retryAfter . 's.'],
        ], JSON_UNESCAPED_SLASHES));
    }

    private function respond($app, $payload): void
    {
        $this->closeRaw(
            $app,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Arme le blindage de sortie : coupe l'affichage HTML des erreurs PHP,
     * détourne les E_WARNING/E_NOTICE/E_DEPRECATED vers le journal (jamais vers
     * la sortie) et ouvre un tampon qui absorbe toute écriture parasite émise
     * avant le JSON. Les erreurs fatales (E_ERROR…) ne passent pas par ce
     * handler et restent gérées par le try/catch de Server::handle().
     */
    private function beginOutputShield(): void
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
            // Erreur explicitement supprimée par l'opérateur @ : ne rien émettre.
            if ((error_reporting() & $errno) === 0) {
                return true;
            }

            try {
                Log::add(\sprintf('%s in %s:%d', $errstr, $errfile, $errline), Log::WARNING, 'com_sensomcp');
            } catch (\Throwable) {
                // La journalisation ne doit jamais casser la réponse.
            }

            // Traité : PHP n'imprime rien.
            return true;
        });

        $this->shieldObLevel = ob_get_level();
        ob_start();
    }

    /**
     * Émet une réponse brute et coupe court au rendu JSON:API de l'API app.
     */
    private function closeRaw($app, string $body): void
    {
        // Jette tout octet parasite accumulé dans notre tampon (warnings, echo
        // de debug d'un plugin tiers…) et rétablit le handler d'erreurs, pour
        // ne laisser passer que le JSON ci-dessous.
        while (ob_get_level() > $this->shieldObLevel) {
            ob_end_clean();
        }

        restore_error_handler();

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        echo $body;

        $app->close();
    }
}
