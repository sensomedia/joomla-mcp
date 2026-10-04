<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolCollector;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;

\defined('_JEXEC') or die;

/**
 * Données de la vue Réglages. La partie « directives serveur » est adaptative :
 * on détecte le serveur (nginx/apache) et on sonde ce qui manque réellement
 * (routage /api, rewrite /.well-known, transmission de l'en-tête Authorization),
 * pour n'afficher QUE les directives nécessaires.
 */
class SettingsModel extends BaseDatabaseModel
{
    public function getEndpoint(): string
    {
        return Metadata::mcpResource();
    }

    /** 'nginx' | 'apache' | 'unknown' */
    public function getServer(): string
    {
        $sw = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        if (str_contains($sw, 'nginx')) {
            return 'nginx';
        }

        if (str_contains($sw, 'apache')) {
            return 'apache';
        }

        return 'unknown';
    }

    /** Statut des plugins requis. */
    public function getPluginStatus(): array
    {
        $required = [
            ['folder' => 'webservices',        'element' => 'sensomcp', 'label' => 'Web Services - Senso MCP'],
            ['folder' => 'system',             'element' => 'sensomcp', 'label' => 'System - Senso MCP'],
            ['folder' => 'api-authentication', 'element' => 'token',    'label' => 'API Authentication - Web Services Joomla Token'],
            ['folder' => 'user',               'element' => 'token',    'label' => 'User - Joomla API Token'],
        ];

        $db  = $this->getDatabase();
        $out = [];

        foreach ($required as $p) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':folder', $p['folder'])
                ->bind(':element', $p['element']);

            $enabled = $db->setQuery($query)->loadResult();

            $out[] = [
                'label'     => $p['label'],
                'installed' => $enabled !== null,
                'enabled'   => (int) $enabled === 1,
            ];
        }

        return $out;
    }

    /** Sondes : routage /api, rewrite /.well-known, transmission Authorization. */
    public function getChecks(): array
    {
        $root = rtrim(Uri::root(), '/');

        return [
            'api'            => $this->probeJson($root . '/api/index.php/v1/mcp/oauth/protected-resource', 'resource'),
            'wellknown'      => $this->probeJson($root . '/.well-known/oauth-protected-resource', 'resource'),
            'auth_forwarded' => $this->probeAuthForwarded($root . '/api/index.php/v1/mcp/oauth/authcheck'),
        ];
    }

    private function http()
    {
        return HttpFactory::getHttp([], ['curl', 'stream']);
    }

    private function probeJson(string $url, string $expectKey): bool
    {
        try {
            $response = $this->http()->get($url, [], 8);

            if ((int) $response->code !== 200) {
                return false;
            }

            $data = json_decode((string) $response->body, true);

            return \is_array($data) && isset($data[$expectKey]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function probeAuthForwarded(string $url): bool
    {
        try {
            $response = $this->http()->get($url, ['Authorization' => 'Bearer probe'], 8);
            $data     = json_decode((string) $response->body, true);

            return \is_array($data) && !empty($data['authorization_received']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Directives serveur à ajouter — seulement celles réellement manquantes,
     * adaptées au serveur détecté.
     *
     * @return list<array{level:string, title:string, code:string}>
     */
    public function getDirectives(): array
    {
        $server = $this->getServer();
        $checks = $this->getChecks();
        $host   = Uri::getInstance()->getHost();
        $apache = $server === 'apache';
        $out    = [];

        // Transmission de Authorization — requise pour TOUS les clients.
        if (!$checks['auth_forwarded']) {
            $out[] = [
                'level' => 'all',
                'title' => 'COM_SENSOMCP_DIR_AUTH',
                'code'  => $apache ? $this->apacheAuth() : $this->nginxAuth($host),
            ];
        }

        // Rewrite /.well-known — requis SEULEMENT pour OAuth (claude.ai web).
        if (!$checks['wellknown']) {
            $out[] = [
                'level' => 'oauth',
                'title' => 'COM_SENSOMCP_DIR_WELLKNOWN',
                'code'  => $apache ? $this->apacheWellKnown() : $this->nginxWellKnown(),
            ];
        }

        return $out;
    }

    private function nginxAuth(string $host): string
    {
        return <<<NGINX
location ^~ /api/index.php {
    fastcgi_split_path_info ^((?U).+\.php)(/?.+)\$;
    try_files \$uri \$fastcgi_script_name =404;
    fastcgi_param PATH_INFO \$fastcgi_path_info;
    fastcgi_pass "unix:/var/www/vhosts/system/$host/php-fpm.sock";
    include /etc/nginx/fastcgi.conf;
    fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
}
NGINX;
    }

    private function nginxWellKnown(): string
    {
        // Sans socket : plg_system_sensomcp sert les métadonnées côté app site,
        // on renvoie simplement vers le handler PHP du site (son propre socket).
        return <<<NGINX
location = /.well-known/oauth-protected-resource   { try_files \$uri /index.php; }
location = /.well-known/oauth-authorization-server  { try_files \$uri /index.php; }
NGINX;
    }

    private function apacheAuth(): string
    {
        return <<<APACHE
# .htaccess (racine du site) ou vhost Apache
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=\$1
APACHE;
    }

    private function apacheWellKnown(): string
    {
        // Sans socket : on renvoie vers index.php (app site) → plg_system_sensomcp
        // sert les métadonnées. Souvent inutile (Joomla route déjà les chemins
        // inexistants vers index.php).
        return <<<APACHE
# .htaccess (racine du site)
RewriteEngine On
RewriteRule ^\.well-known/oauth-(protected-resource|authorization-server)\$ index.php [L]
APACHE;
    }

    /** JSON de config client (jeton statique). */
    public function getConfigJson(): string
    {
        $config = [
            'mcpServers' => [
                'senso-mcp' => [
                    'type'    => 'http',
                    'url'     => $this->getEndpoint(),
                    'headers' => ['Authorization' => 'Bearer YOUR_JOOMLA_API_TOKEN'],
                ],
            ],
        ];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Liste exhaustive des outils exposés (nom, description, scope). */
    public function getTools(): array
    {
        $guest = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);

        return ToolCollector::collect($guest)->describe();
    }
}
