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
use Sensomedia\Component\Sensomcp\Administrator\Helper\Htaccess;
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

        // LiteSpeed lit les .htaccess comme Apache : mêmes directives.
        if (str_contains($sw, 'litespeed')) {
            return 'litespeed';
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

    /** Résultat des sondes, calculé une fois par requête. */
    private ?array $checks = null;

    /** Sondes : routage /api, rewrite /.well-known, transmission Authorization. */
    public function getChecks(bool $fresh = false): array
    {
        if ($this->checks !== null && !$fresh) {
            return $this->checks;
        }

        $root = rtrim(Uri::root(), '/');
        $base = $this->basePath();

        $checks = [
            'api'            => $this->probeJson($root . '/api/index.php/v1/mcp/oauth/protected-resource', 'resource'),
            'wellknown'      => $this->probeJson($root . '/.well-known/oauth-protected-resource', 'resource'),
            'auth_forwarded' => $this->probeAuthForwarded($root . '/api/index.php/v1/mcp/oauth/authcheck'),
        ];

        // Site en sous-dossier : les clients cherchent les métadonnées du serveur
        // d'autorisation à la racine du DOMAINE (RFC 8414, « /.well-known/…/dev »).
        if ($base !== '') {
            $checks['wellknown_domain'] = $this->probeJson($this->origin() . '/.well-known/oauth-authorization-server' . $base, 'issuer');
        }

        return $this->checks = $checks;
    }

    /** Sous-dossier du site (« /dev »), chaîne vide à la racine du domaine. */
    public function basePath(): string
    {
        return rtrim(Uri::root(true), '/');
    }

    /** Schéma + hôte, sans chemin (https://example.com). */
    private function origin(): string
    {
        $root = rtrim(Uri::root(), '/');
        $base = $this->basePath();

        return $base === '' ? $root : substr($root, 0, -\strlen($base));
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

    /** Code HTTP d'une page (0 si injoignable), pour détecter une erreur 500 après écriture d'un .htaccess. */
    private function status(string $url): int
    {
        try {
            return (int) $this->http()->get($url, [], 8)->code;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Directives serveur à ajouter — seulement celles réellement manquantes.
     * Chaque entrée porte les deux variantes : bloc .htaccess (Apache, LiteSpeed,
     * et Plesk où nginx passe à Apache les URL sans .php) et directive nginx.
     *
     * @return list<array{id:string, level:string, title:string, nginx:string, nginxWhere:string, nginxPlesk:string, htaccess:string, file:?string, exists:bool, present:bool, writable:bool, domainRoot:bool}>
     */
    public function getDirectives(): array
    {
        $checks = $this->getChecks();
        $host   = Uri::getInstance()->getHost();
        $base   = $this->basePath();
        $out    = [];

        // Transmission de Authorization — requise pour TOUS les clients.
        if (!$checks['auth_forwarded']) {
            $out[] = array_merge(
                $this->directive('auth', 'all', 'COM_SENSOMCP_DIR_AUTH', $this->nginxAuth(), $base),
                ['nginxWhere' => 'COM_SENSOMCP_DIR_NGINX_WHERE_AUTH', 'nginxPlesk' => $this->nginxAuthPlesk($host, $base)]
            );
        }

        // Rewrite /.well-known — requis SEULEMENT pour OAuth (claude.ai, Claude Desktop).
        if (!$checks['wellknown']) {
            $out[] = $this->directive('wellknown', 'oauth', 'COM_SENSOMCP_DIR_WELLKNOWN', $this->nginxWellKnown($base), $base);
        }

        // Sous-dossier : règle à poser à la racine du DOMAINE (hors Joomla).
        if ($base !== '' && empty($checks['wellknown_domain'])) {
            $out[] = $this->directive('wellknown_domain', 'oauth', 'COM_SENSOMCP_DIR_WELLKNOWN_DOMAIN', $this->nginxWellKnownDomain($base), $base);
        }

        return $out;
    }

    private function directive(string $id, string $level, string $title, string $nginx, string $base): array
    {
        $file    = Htaccess::file($id, $base);
        $exists  = $file !== null && is_file($file);
        $content = $exists ? (string) @file_get_contents($file) : '';

        return [
            'id'         => $id,
            'level'      => $level,
            'title'      => $title,
            'nginx'      => $nginx,
            'htaccess'   => Htaccess::block($id, $base),
            'file'       => $file,
            'exists'     => $exists,
            'present'    => Htaccess::has($content, $id),
            'writable'   => $file !== null && ($exists ? is_writable($file) : is_writable(\dirname($file))),
            'domainRoot' => $id === 'wellknown_domain',
            'nginxWhere' => 'COM_SENSOMCP_DIR_NGINX_WHERE',
            'nginxPlesk' => '',
        ];
    }

    /**
     * Ajoute le bloc .htaccess d'une directive, puis vérifie qu'il a l'effet voulu
     * sans casser le site. Sinon le fichier est remis dans son état d'origine.
     *
     * @return array{status:string, file:?string, backup:?string}
     */
    public function applyHtaccess(string $id): array
    {
        if (!\in_array($id, Htaccess::IDS, true)) {
            return ['status' => 'nodir', 'file' => null, 'backup' => null];
        }

        $base  = $this->basePath();
        $pages = array_unique([Uri::root(), $this->origin() . '/']);
        $before = [];

        foreach ($pages as $url) {
            $before[$url] = $this->status($url);
        }

        $res = Htaccess::add($id, $base);

        if ($res['status'] !== 'ok') {
            return ['status' => $res['status'], 'file' => $res['file'], 'backup' => $res['backup']];
        }

        // Le site répondait et ne répond plus (erreur 500 sur une directive refusée) → retour arrière.
        foreach ($pages as $url) {
            if ($before[$url] > 0 && $before[$url] < 500 && $this->status($url) >= 500) {
                Htaccess::restore((string) $res['file'], $res['old']);

                return ['status' => 'broke', 'file' => $res['file'], 'backup' => $res['backup']];
            }
        }

        $check = ['auth' => 'auth_forwarded', 'wellknown' => 'wellknown', 'wellknown_domain' => 'wellknown_domain'][$id];

        if (empty($this->getChecks(true)[$check])) {
            Htaccess::restore((string) $res['file'], $res['old']);

            return ['status' => 'noeffect', 'file' => $res['file'], 'backup' => $res['backup']];
        }

        return ['status' => 'ok', 'file' => $res['file'], 'backup' => $res['backup']];
    }

    /** @return array{status:string, file:?string, backup:?string} */
    public function removeHtaccess(string $id): array
    {
        if (!\in_array($id, Htaccess::IDS, true)) {
            return ['status' => 'nodir', 'file' => null, 'backup' => null];
        }

        return Htaccess::remove($id, $this->basePath());
    }

    /** Cas général : une ligne dans le bloc location qui transmet PHP à PHP-FPM. */
    private function nginxAuth(): string
    {
        return 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;';
    }

    /**
     * Plesk : le bloc PHP du domaine n'est pas modifiable, d'où un bloc dédié à
     * /api/index.php (socket PHP-FPM du domaine, propre à Plesk).
     */
    private function nginxAuthPlesk(string $host, string $base): string
    {
        return <<<NGINX
location ^~ $base/api/index.php {
    fastcgi_split_path_info ^((?U).+\.php)(/?.+)\$;
    try_files \$uri \$fastcgi_script_name =404;
    fastcgi_param PATH_INFO \$fastcgi_path_info;
    fastcgi_pass "unix:/var/www/vhosts/system/$host/php-fpm.sock";
    include /etc/nginx/fastcgi.conf;
    fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
}
NGINX;
    }

    private function nginxWellKnown(string $base = ''): string
    {
        // Sans socket : plg_system_sensomcp sert les métadonnées côté app site,
        // on renvoie simplement vers le handler PHP du site (son propre socket).
        return <<<NGINX
location = $base/.well-known/oauth-protected-resource   { try_files \$uri $base/index.php; }
location = $base/.well-known/oauth-authorization-server  { try_files \$uri $base/index.php; }
NGINX;
    }

    private function nginxWellKnownDomain(string $base): string
    {
        return <<<NGINX
location ^~ /.well-known/oauth-protected-resource$base   { try_files \$uri $base/index.php; }
location ^~ /.well-known/oauth-authorization-server$base  { try_files \$uri $base/index.php; }
NGINX;
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
