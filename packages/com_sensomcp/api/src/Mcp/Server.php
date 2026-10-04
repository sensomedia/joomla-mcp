<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Mcp;

use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Dispatcher JSON-RPC 2.0 minimal implémentant les méthodes MCP nécessaires :
 * server/discover, initialize, ping, tools/list, tools/call (+ notifications ignorées).
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
                // Découverte MCP 2026-07-28 : les clients récents (Claude) l'envoient
                // avant tout. On répond honnêtement notre version réelle dans
                // `supportedVersions` : le client négocie alors sur 2025-06-18 et
                // poursuit avec `initialize`. Sans ce handler → -32601 (bruit de log).
                case 'server/discover':
                    return $this->result($id, [
                        'resultType'        => 'complete',
                        'supportedVersions' => [self::DEFAULT_PROTOCOL_VERSION],
                        'capabilities'      => $this->serverCapabilities(),
                        '_meta'             => [
                            'io.modelcontextprotocol/serverInfo' => [
                                'name'    => $this->serverName,
                                'version' => $this->serverVersion,
                            ],
                        ],
                        'ttlMs'      => 3600000,
                        'cacheScope' => 'public',
                    ]);

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
        } catch (\Throwable $e) {
            if ($isNotification) {
                return null;
            }

            return $this->error($id, -32603, 'Erreur interne : ' . $e->getMessage());
        }
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
