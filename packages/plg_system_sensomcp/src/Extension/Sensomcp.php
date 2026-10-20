<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\System\Sensomcp\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;

\defined('_JEXEC') or die;

/**
 * Sert les documents de découverte OAuth 2.1 à la RACINE du domaine, depuis
 * l'application site :
 *
 *   GET /.well-known/oauth-protected-resource   → Metadata::protectedResource()
 *   GET /.well-known/oauth-authorization-server → Metadata::authorizationServer()
 *
 * Pourquoi ici (et pas via un rewrite nginx vers /api) : ces URLs vivent à la
 * racine, hors /api/. Les servir depuis PHP évite d'imposer à l'admin un bloc
 * `fastcgi_pass` avec le socket PHP-FPM en dur (propre à chaque serveur) — la
 * règle web devient alors sans socket : `try_files $uri /index.php;` (nginx),
 * voire rien du tout sur les serveurs dont le catch-all route déjà vers
 * index.php. Joomla conserve REQUEST_URI intact, ce plugin le reconnaît.
 */
final class Sensomcp extends CMSPlugin implements SubscriberInterface
{
    /** Chemin racine (exact) → méthode de la classe Metadata. */
    private const ROUTES = [
        '/.well-known/oauth-protected-resource'   => 'protectedResource',
        '/.well-known/oauth-authorization-server' => 'authorizationServer',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'      => 'onAfterInitialise',
            'onExtensionAfterInstall' => 'onExtensionChanged',
            'onExtensionAfterUpdate'  => 'onExtensionChanged',
        ];
    }

    /**
     * Un plugin d'outils « sensomcp » (ou un package Senso MCP) vient d'être
     * installé / mis à jour : le catalogue d'outils a probablement changé, les
     * connexions MCP déjà établies ne le verront qu'après reconnexion → message
     * à l'administrateur (le tableau de bord liste les connexions concernées).
     */
    public function onExtensionChanged(Event $event): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $installer = $event->getArgument('installer');
        $manifest  = \is_object($installer) && method_exists($installer, 'getManifest') ? $installer->getManifest() : null;

        if (!$manifest instanceof \SimpleXMLElement) {
            return;
        }

        $type  = (string) $manifest->attributes()->type;
        $group = (string) $manifest->attributes()->group;
        $name  = strtolower((string) ($manifest->packagename ?? $manifest->name ?? ''));

        $isToolPlugin = $type === 'plugin' && $group === 'sensomcp';
        $isPackage    = $type === 'package' && str_contains($name, 'sensomcp');

        if (!$isToolPlugin && !$isPackage) {
            return;
        }

        $this->loadLanguage();
        $app->enqueueMessage(Text::_('PLG_SYSTEM_SENSOMCP_CATALOG_CHANGED'), 'notice');
    }

    public function onAfterInitialise(): void
    {
        $app = Factory::getApplication();

        // Uniquement l'app site : l'admin et l'API app ne servent pas la racine.
        if (!$app->isClient('site')) {
            return;
        }

        $path   = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        $path   = '/' . trim($path, '/');
        $method = self::ROUTES[$path] ?? null;

        if ($method === null) {
            return;
        }

        // Le composant (classe Metadata) doit être installé.
        if (!class_exists(Metadata::class)) {
            return;
        }

        $payload = json_encode(Metadata::{$method}(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Access-Control-Allow-Origin', '*', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();

        echo $payload;

        $app->close();
    }
}
