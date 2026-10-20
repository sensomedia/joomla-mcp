<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\CatalogState;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\DebugLog;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\RequestLog;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolCollector;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolState;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\RateLimiter;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\BearerAuthenticator;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;
use Sensomedia\Component\Sensomcp\Api\Mcp\ResourceProvider;
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

    /** Taille maximale d'un lot JSON-RPC (messages). */
    private const MAX_BATCH = 20;
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

        $registry = ToolCollector::collectFor($user, $auth['scopes']);
        $server   = $this->buildServer($user, $auth['scopes'], $registry);

        // Suivi du catalogue vu par cette connexion (avertissement de reconnexion).
        $connKey     = CatalogState::key($auth);
        $fingerprint = CatalogState::fingerprint($registry);

        // Lot JSON-RPC (tableau de messages) ou message unique.
        $isBatch  = \is_array($decoded) && array_is_list($decoded) && $decoded !== [];
        $messages = $isBatch ? $decoded : [$decoded];
        $out      = [];

        // Lot JSON-RPC borné (audit 2026-10-12) : au-delà, requête invalide.
        if ($isBatch && \count($messages) > self::MAX_BATCH) {
            RequestLog::record((int) $user->id, $ip, '(batch)', null, false, 'batch too large');
            $this->respond($app, [
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32600, 'message' => 'Batch too large (max ' . self::MAX_BATCH . ' messages).'],
            ]);

            return;
        }

        // Rate limiting (protection de disponibilité) — après auth et décodage, chaque
        // message du lot compte pour un. Clé = identité du porteur ; fail-open si indisponible.
        $rl = RateLimiter::check((int) $auth['user_id'], $ip, \count($messages));

        if ($rl !== null) {
            RequestLog::record((int) $auth['user_id'], $ip, '(ratelimit)', null, false, 'rate limited');
            $this->tooManyRequests($app, (int) $rl['retry_after']);

            return;
        }

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

            // Catalogue : mémorise ce que la connexion vient de charger, ou, sur un
            // appel d'outil, prévient l'assistant si la liste chargée est périmée.
            if ($method === 'tools/list' && isset($response['result'])) {
                CatalogState::recordListed($connKey, $auth, $fingerprint);
            } elseif ($method === 'tools/call' && isset($response['result']['content']) && \is_array($response['result']['content'])) {
                $notice = CatalogState::staleNotice($connKey, $auth, $fingerprint);

                if ($notice !== null) {
                    array_unshift($response['result']['content'], ['type' => 'text', 'text' => $notice]);
                }
            }

            // La sonde `server/discover` reçoit -32601 par conception (serveur à
            // handshake, cf. Server::handle()) : ce n'est pas une erreur à journaliser.
            if ($method !== '' && $method !== 'server/discover') {
                $tool    = $method === 'tools/call' ? (string) ($message['params']['name'] ?? '') : '';
                $isError = isset($response['error']) || (($response['result']['isError'] ?? false) === true);
                $err     = null;

                if (isset($response['error'])) {
                    // Exception inattendue : le journal MCP garde le détail (classe,
                    // message, fichier) que le client n'a pas reçu, sous le même ref.
                    $err = $server->takeInternalError() ?? (string) ($response['error']['message'] ?? 'error');
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

        // EXPÉRIMENTAL (option list_changed_sse) : si la connexion est périmée et que le
        // client accepte le SSE, la réponse est envoyée en flux d'événements précédée
        // de `notifications/tools/list_changed` (spec MCP) — pour mesurer si le client
        // recharge alors sa liste d'outils (journal : un tools/list doit suivre).
        if ($this->sseNotifyEnabled() && CatalogState::staleInfo($connKey, $fingerprint) !== null) {
            $this->respondSse($app, $isBatch ? $out : $out[0], [
                'jsonrpc' => '2.0',
                'method'  => 'notifications/tools/list_changed',
            ]);

            return;
        }

        $this->respond($app, $isBatch ? $out : $out[0]);
    }

    /**
     * @param string[] $scopes Scopes accordés au porteur du jeton.
     */
    private function buildServer(User $user, array $scopes, ToolRegistry $registry): Server
    {
        // Moteur pur : TOUS les outils viennent des plugins « sensomcp » ; le registre
        // (scopes, lecture seule, états par outil) est construit par ToolCollector::collectFor.
        // Ressources (articles) : mêmes règles de lecture que les outils (scope content.read,
        // niveaux d'accès, état/dates) — audit 2026-10-12.
        $server = new Server($registry, self::SERVER_NAME, self::SERVER_VERSION, new ResourceProvider($user, $scopes));
        $server->setListChanged($this->sseNotifyEnabled());

        return $server;
    }

    /** Option expérimentale : notification list_changed en SSE (voir dispatch()). */
    private function sseNotifyEnabled(): bool
    {
        if (!(bool) ComponentHelper::getParams('com_sensomcp')->get('list_changed_sse', 0)) {
            return false;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $sse    = stripos($accept, 'text/event-stream') !== false;

        // Option expérimentale : on trace ce que le client accepte, pour savoir si
        // la voie SSE a pu être prise (diagnostic, journal com_sensomcp.log.php).
        DebugLog::note('list_changed_sse: client accept="' . $accept . '" ua="' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') . '" sse=' . ($sse ? 'yes' : 'no'));

        return $sse;
    }

    /** Réponse en flux SSE (transport Streamable HTTP) : notification(s) puis la réponse JSON-RPC. */
    private function respondSse($app, $payload, array ...$notifications): void
    {
        while (ob_get_level() > $this->shieldObLevel) {
            ob_end_clean();
        }

        restore_error_handler();

        $app->setHeader('Content-Type', 'text/event-stream; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-cache', true);
        $app->setHeader('X-Accel-Buffering', 'no', true);
        $app->sendHeaders();

        DebugLog::note('list_changed_sse: SSE response sent with notifications/tools/list_changed');

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        foreach ($notifications as $n) {
            echo "event: message\ndata: " . json_encode($n, $flags) . "\n\n";
        }

        echo "event: message\ndata: " . json_encode($payload, $flags) . "\n\n";

        $app->close();
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
