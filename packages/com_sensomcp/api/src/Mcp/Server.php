<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Mcp;

use Sensomedia\Component\Sensomcp\Administrator\Mcp\DebugLog;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Dispatcher JSON-RPC 2.0 minimal implémentant les méthodes MCP nécessaires :
 * initialize, ping, tools/list, tools/call, resources/*, prompts/* (+ notifications
 * ignorées). Protocole à handshake (2025-06-18) : la sonde `server/discover` de
 * l'ère 2026-07-28 reçoit volontairement -32601 (voir handle()).
 *
 * Serveur volontairement « stateless » (pas de session Mcp-Session-Id) pour
 * maximiser la compatibilité avec l'ensemble des clients MCP.
 */
class Server
{
    private const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private ToolRegistry $tools,
        private string $serverName,
        private string $serverVersion,
        private ResourceProvider $resources = new ResourceProvider(),
        private PromptProvider $prompts = new PromptProvider()
    ) {
    }

    /**
     * Traite un message JSON-RPC unique.
     *
     * @return array|null  La réponse JSON-RPC, ou null pour une notification
     *                     (aucune réponse à renvoyer).
     */
    public function handle(array $message): ?array
    {
        $id     = $message['id']     ?? null;
        $method = $message['method'] ?? '';
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        // Une notification n'a pas d'« id ».
        $isNotification = !\array_key_exists('id', $message);

        try {
            switch ($method) {
                // Sonde de découverte MCP 2026-07-28 (« ère moderne », sans
                // handshake). Les clients bi-ères (Claude, ChatGPT) l'envoient en
                // premier pour détecter l'ère du serveur : un `DiscoverResult`
                // signifie « serveur moderne » et le client ne redescend JAMAIS vers
                // `initialize`. Entre 1.2.0 et 1.2.11 on répondait un DiscoverResult
                // n'annonçant que 2025-06-18 (version héritée) : Claude tolérait,
                // ChatGPT s'arrêtait net après la sonde (aucun `tools/list`).
                // Ce serveur n'implémente que le protocole à handshake : il doit
                // donc répondre comme n'importe quel serveur hérité, par
                // « méthode inconnue » (-32601), ce que tout client bi-ère traite
                // en repliant sur `initialize`.
                case 'server/discover':
                    return $this->error(
                        $id,
                        -32601,
                        'Method not found: server/discover (legacy MCP server, protocol '
                        . self::DEFAULT_PROTOCOL_VERSION . ' — start with initialize)'
                    );

                case 'initialize':
                    return $this->result($id, [
                        'protocolVersion' => $params['protocolVersion'] ?? self::DEFAULT_PROTOCOL_VERSION,
                        'capabilities'    => $this->serverCapabilities(),
                        'serverInfo' => [
                            'name'    => $this->serverName,
                            'version' => $this->serverVersion,
                        ],
                    ]);

                case 'ping':
                    return $this->result($id, new \stdClass());

                case 'tools/list':
                    return $this->result($id, ['tools' => $this->tools->list()]);

                case 'tools/call':
                    $name = (string) ($params['name'] ?? '');
                    $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

                    try {
                        return $this->result($id, $this->tools->call($name, $args));
                    } catch (McpToolException $e) {
                        // Erreur d'outil → résultat isError, pas erreur JSON-RPC.
                        return $this->result($id, [
                            'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                            'isError' => true,
                        ]);
                    }

                case 'resources/list':
                    return $this->result($id, ['resources' => $this->resources->list()]);

                case 'resources/templates/list':
                    return $this->result($id, ['resourceTemplates' => $this->resources->templates()]);

                case 'resources/read':
                    return $this->result($id, $this->resources->read((string) ($params['uri'] ?? '')));

                case 'prompts/list':
                    return $this->result($id, ['prompts' => $this->prompts->list()]);

                case 'prompts/get':
                    return $this->result($id, $this->prompts->get(
                        (string) ($params['name'] ?? ''),
                        \is_array($params['arguments'] ?? null) ? $params['arguments'] : []
                    ));

                default:
                    // Notifications (notifications/initialized, .../cancelled, …) : rien à renvoyer.
                    if ($isNotification) {
                        return null;
                    }

                    return $this->error($id, -32601, 'Méthode inconnue : ' . $method);
            }
        } catch (McpToolException $e) {
            // Refus VOLONTAIRE hors tools/call (ressource inconnue ou hors scope,
            // prompt inconnu…) : message rédigé pour le client, renvoyé tel quel.
            if ($isNotification) {
                return null;
            }

            return $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            // Exception inattendue (PHP, SQL, fichier…) : jamais le message brut au
            // client (fuite de chemins, de noms de tables, de messages d'API tierces).
            // Détail dans le journal fichier + résumé dans le journal MCP (base),
            // tous deux reliés par l'identifiant de corrélation renvoyé au client.
            $ref = DebugLog::internal($e, 'method=' . $method);

            $this->internalErrors[] = DebugLog::summary($e, $ref);

            if ($isNotification) {
                return null;
            }

            return $this->error($id, -32603, 'Internal error (ref ' . $ref . '). Details are in the site MCP log.');
        }
    }

    /** @var string[] Résumés des exceptions inattendues du dernier handle() (consommés par le contrôleur). */
    private array $internalErrors = [];

    /** Rend et vide le résumé de la dernière exception inattendue (pour le journal MCP). */
    public function takeInternalError(): ?string
    {
        $last = $this->internalErrors === [] ? null : end($this->internalErrors);
        $this->internalErrors = [];

        return $last === false ? null : $last;
    }

    /** Capacités annoncées (identiques pour `server/discover` et `initialize`). */
    private function serverCapabilities(): array
    {
        return [
            'tools'     => ['listChanged' => false],
            'resources' => ['listChanged' => false],
            'prompts'   => ['listChanged' => false],
        ];
    }

    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
