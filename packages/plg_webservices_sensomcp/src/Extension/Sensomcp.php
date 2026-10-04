<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\WebServices\Sensomcp\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

\defined('_JEXEC') or die;

/**
 * Enregistre la route MCP dans l'API application de Joomla.
 *
 *   GET|POST /api/index.php/v1/mcp  →  com_sensomcp, controller « mcp », task « dispatch »
 *
 * La route est déclarée non publique (public => false) : l'API application
 * exige donc un token API Joomla valide (en-tête Authorization: Bearer …).
 */
final class Sensomcp extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    public function onBeforeApiRoute($event): void
    {
        $router = $this->resolveRouter($event);

        if ($router === null) {
            return;
        }

        // format : l'API app impose application/vnd.api+json par défaut et
        // renvoie 406 sinon. Les clients OAuth/MCP (claude.ai) envoient
        // Accept: application/json → on déclare les deux comme acceptables.
        $defaults = [
            'component' => 'com_sensomcp',
            'public'    => true,
            'format'    => ['application/json', 'application/vnd.api+json'],
        ];

        // Endpoint MCP : public côté API app, l'auth Bearer est portée par le
        // composant (JWT OAuth ou jeton API Joomla).
        $router->addRoute(new Route(['GET', 'POST'], 'v1/mcp', 'mcp.dispatch', [], $defaults));

        // Endpoints OAuth 2.1 (tous publics : ils fabriquent/décrivent les jetons).
        $router->addRoute(new Route(['POST'], 'v1/mcp/oauth/register', 'oauth.register', [], $defaults));
        $router->addRoute(new Route(['POST'], 'v1/mcp/oauth/token', 'oauth.token', [], $defaults));
        $router->addRoute(new Route(['GET'], 'v1/mcp/oauth/protected-resource', 'oauth.metaResource', [], $defaults));
        $router->addRoute(new Route(['GET'], 'v1/mcp/oauth/authorization-server', 'oauth.metaAuthServer', [], $defaults));
        $router->addRoute(new Route(['GET'], 'v1/mcp/oauth/authcheck', 'oauth.authcheck', [], $defaults));
    }

    /**
     * Récupère l'ApiRouter quelle que soit la forme de l'événement (J5/J6).
     */
    private function resolveRouter($event)
    {
        if (\is_object($event) && method_exists($event, 'getRouter')) {
            return $event->getRouter();
        }

        if (\is_object($event) && method_exists($event, 'getArgument')) {
            return $event->getArgument('subject') ?? $event->getArgument(0);
        }

        // Rétrocompat éventuelle : événement passé comme tableau [&$router, $app].
        if (\is_array($event) && isset($event[0])) {
            return $event[0];
        }

        return null;
    }
}
