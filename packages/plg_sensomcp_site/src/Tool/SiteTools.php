<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Site\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\UrlGuard;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Outils MCP généraux sur le site (introspection). Conçus pour TOUT site Joomla :
 * API standard uniquement (Uri, Multilanguage, #__languages, ACL) et replis
 * propres (offset vide → UTC, aucune langue publiée → liste vide, invité géré).
 */
final class SiteTools
{
    public function __construct(private User $user)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->declareScope('site.read', 'PLG_SENSOMCP_SITE_SCOPE_READ');

        $registry->register(
            'get_site_info',
            'Return a compact snapshot of the Joomla site to ground later actions: name, base URL, '
            . 'SEF settings, timezone, current server time, active template, content languages (and '
            . 'whether the site is actually multilingual), the authenticated account (groups, super-admin), '
            . 'the granted capabilities (scopes) and Joomla/PHP versions. Call this first when you need '
            . 'the site URL, the current date, or which language codes to use.',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->getSiteInfo($registry),
            'site.read'
        );

        $registry->register(
            'list_access_levels',
            'List the view access levels (id and title) defined on the site (e.g. Public, Registered, '
            . 'Special). Use an id for the "access" argument when creating articles, categories or menu items.',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->listAccessLevels(),
            'site.read'
        );

        $registry->register(
            'list_installed_components',
            'List the enabled site components (element and human name), e.g. com_content, com_rsform. '
            . 'Useful to know what the site can do before building menu items or links.',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->listInstalledComponents(),
            'site.read'
        );

        $registry->register(
            'list_installed_languages',
            'List installed language packs (code, name, client site/administrator).',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->listInstalledLanguages(),
            'site.read'
        );

        $registry->register(
            'get_rendered_page',
            'Fetch a front-end page as an anonymous visitor and return its HTTP status, title and text '
            . '(HTML tags stripped, truncated). Useful to verify how content actually renders or to audit '
            . 'a page. Pass a relative path (e.g. /blog/foo) or a full URL of this site.',
            [
                'type'       => 'object',
                'properties' => ['url' => ['type' => 'string', 'description' => 'Relative path or absolute URL on this site.']],
                'required'   => ['url'],
            ],
            fn (array $a) => $this->getRenderedPage((string) ($a['url'] ?? '')),
            'site.read'
        );

        $registry->register(
            'clear_cache',
            'Clear Joomla caches. Pass a group (e.g. com_content, com_plugins) to clear only that one, '
            . 'or omit to clear the main caches. Safe maintenance operation.',
            [
                'type'       => 'object',
                'properties' => ['group' => ['type' => 'string', 'description' => 'Cache group to clear (optional).']],
            ],
            fn (array $a) => $this->clearCache((string) ($a['group'] ?? '')),
            'site.read',
            ['risk' => 'write', 'acl' => ['core.manage', 'com_cache']]
        );

        // Outils JCH Optimize : exposés uniquement si le composant est présent et actif.
        if ($this->jchInstalled()) {
            $registry->register(
                'clear_jch_cache',
                'Clear the JCH Optimize cache (combined CSS/JS files, page cache in files or Redis, and '
                . 'Cloudflare if configured) via JCH\'s own cleaner. Optionally rebuild afterwards (rebuild=true, '
                . 'JCH Pro only). Safe maintenance operation. First make sure both JCH system plugins are enabled '
                . '(the response reports jch_plugins state and warns if not — use set_jch_cache_state to enable). '
                . 'Only available when JCH Optimize is installed.',
                [
                    'type'       => 'object',
                    'properties' => ['rebuild' => ['type' => 'boolean', 'description' => 'After clearing, trigger a background rebuild (JCH Pro only).']],
                ],
                fn (array $a) => $this->clearJch($a),
                'site.read',
                ['risk' => 'write', 'acl' => ['core.manage', 'com_cache']]
            );

            $registry->register(
                'rebuild_jch_cache',
                'Rebuild (recache) the JCH Optimize cache: clears then regenerates the optimized files/page '
                . 'cache by crawling the site, so visitors do not pay the first-render cost. Asynchronous — '
                . 'returns immediately; the crawl runs in the background (check the response "method"). Uses '
                . 'JCH\'s own signed frontend trigger (the "Rebuild cache" button mechanism), falling back to a '
                . 'background CLI spawn. Requires JCH Optimize Pro and both JCH system plugins enabled (the '
                . 'response reports jch_plugins state and warns if not — use set_jch_cache_state to enable); the '
                . 'Console plugin is only needed for the CLI fallback. Pass crawl_limit to cap how many pages '
                . 'are crawled (default: JCH setting, ~500).',
                [
                    'type'       => 'object',
                    'properties' => ['crawl_limit' => ['type' => 'integer', 'description' => 'Max pages to crawl (optional).']],
                ],
                fn (array $a) => $this->rebuildJch($a),
                'site.read',
                ['risk' => 'write', 'acl' => ['core.manage', 'com_cache']]
            );

            $registry->register(
                'set_jch_cache_state',
                'Enable or disable JCH Optimize caching by toggling its two system plugins '
                . '(System - JCH Optimize and System - JCH Optimize Page Cache). Pass enabled=true to turn '
                . 'optimization/page-cache on, enabled=false to turn it off (e.g. while debugging front-end '
                . 'assets). Reports each plugin\'s resulting state. Only available when JCH Optimize is installed.',
                [
                    'type'       => 'object',
                    'required'   => ['enabled'],
                    'properties' => ['enabled' => ['type' => 'boolean', 'description' => 'true = enable JCH caching, false = disable it.']],
                ],
                fn (array $a) => $this->setJchState($a),
                'site.read',
                ['risk' => 'write', 'acl' => ['core.edit.state', 'com_plugins']]
            );
        }
    }

    // ---------------------------------------------------------------------

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function getSiteInfo(ToolRegistry $registry): array
    {
        $app      = Factory::getApplication();
        $timezone = (string) ($app->get('offset') ?: 'UTC');

        return [
            'site_name'      => (string) $app->get('sitename'),
            'url'            => Uri::root(),
            'sef'            => [
                'enabled' => (bool) $app->get('sef', 0),
                'rewrite' => (bool) $app->get('sef_rewrite', 0),
                'suffix'  => (bool) $app->get('sef_suffix', 0),
            ],
            'timezone'       => $timezone,
            'server_time'    => $this->serverTime($timezone),
            'template'       => $this->activeTemplate(),
            'languages'      => $this->languages(),
            'current_user'   => $this->currentUser(),
            'capabilities'   => [
                'granted_scopes' => $registry->grantedScopes(),
            ],
            'joomla_version' => (new Version())->getShortVersion(),
            'php_version'    => \PHP_VERSION,
        ];
    }

    /** Heure serveur courante, en fuseau du site + UTC (repli UTC si offset invalide). */
    private function serverTime(string $timezone): array
    {
        $utc = new \DateTimeZone('UTC');

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz       = $utc;
            $timezone = 'UTC';
        }

        $now   = new \DateTime('now', $utc);
        $local = (clone $now)->setTimezone($tz);

        return [
            'iso'      => $local->format('c'),
            'utc'      => $now->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $timezone,
        ];
    }

    /** Nom du template site actif (style par défaut, ex. « yootheme »). */
    private function activeTemplate(): ?string
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('template'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('home') . ' <> ' . $db->quote('0'))
            ->order('CASE WHEN ' . $db->quoteName('home') . ' = ' . $db->quote('1') . ' THEN 0 ELSE 1 END ASC');

        $tpl = (string) $db->setQuery($q, 0, 1)->loadResult();

        return $tpl !== '' ? $tpl : null;
    }

    private function listAccessLevels(): array
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__viewlevels'))
            ->order($db->quoteName('ordering') . ' ASC');

        $rows = array_map(static fn ($r) => [
            'id'    => (int) $r['id'],
            'title' => (string) $r['title'],
        ], $db->setQuery($q)->loadAssocList() ?: []);

        return ['access_levels' => $rows];
    }

    private function listInstalledComponents(): array
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['element', 'name']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('enabled') . ' = 1')
            ->order($db->quoteName('element') . ' ASC');

        $lang = Factory::getApplication()->getLanguage();
        $out  = [];

        foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r) {
            $element = (string) $r['element'];
            // Le nom en base est souvent une clé de langue (ex. com_content) → traduire.
            $lang->load($element, JPATH_ADMINISTRATOR);
            $name = Text::_((string) $r['name']);

            $out[] = ['element' => $element, 'name' => $name];
        }

        return ['components' => $out];
    }

    /**
     * Langues de contenu publiées + statut multilingue réel du site.
     * `multilingual` s'appuie sur Multilanguage::isEnabled() (plugin languagefilter
     * actif + langues publiées) — le même critère que Joomla lui-même.
     */
    private function languages(): array
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['lang_code', 'title', 'sef']))
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('ordering') . ' ASC');

        $content = array_map(static fn ($r) => [
            'code' => $r['lang_code'],
            'sef'  => $r['sef'],
            'name' => $r['title'],
        ], $db->setQuery($q)->loadAssocList() ?: []);

        return [
            'multilingual' => Multilanguage::isEnabled(),
            'site_default' => $this->defaultLanguage($content),
            'content'      => $content,
        ];
    }

    /**
     * Langue par défaut du site, de la plus fiable à la plus générique :
     * 1. config `language` (admin) — en API app `$app->get('language')` est souvent
     *    vide, donc repli sur la valeur pristine de configuration.php ;
     * 2. sinon, site monolingue → l'unique langue de contenu publiée fait office
     *    de défaut (le cas où configuration.php ne stocke pas `language`) ;
     * 3. dernier repli : langue active de l'application (jamais vide).
     *
     * @param array<int, array{code:string}> $content langues de contenu publiées
     */
    private function defaultLanguage(array $content): string
    {
        $lang = (string) Factory::getApplication()->get('language');

        if ($lang === '') {
            try {
                if (class_exists('\JConfig')) {
                    $config = new \JConfig();
                    $lang   = (string) ($config->language ?? '');
                }
            } catch (\Throwable) {
                // repli silencieux
            }
        }

        if ($lang !== '') {
            return $lang;
        }

        if (\count($content) === 1) {
            return (string) $content[0]['code'];
        }

        return (string) Factory::getApplication()->getLanguage()->getTag();
    }

    private function currentUser(): array
    {
        $user = $this->user;
        $id   = (int) $user->id;

        return [
            'id'             => $id,
            'username'       => (string) $user->username,
            'name'           => (string) $user->name,
            'guest'          => $id === 0,
            'groups'         => $id === 0 ? [] : $this->groupNames($user),
            'is_super_admin' => (bool) $user->authorise('core.admin'),
        ];
    }

    /** Noms des groupes auxquels appartient l'utilisateur (héritage inclus). */
    private function groupNames(User $user): array
    {
        $ids = array_map('intval', (array) $user->getAuthorisedGroups());

        if (!$ids) {
            return [];
        }

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('title'))
            ->from($db->quoteName('#__usergroups'))
            ->whereIn($db->quoteName('id'), $ids)
            ->order($db->quoteName('lft') . ' ASC');

        return array_map('strval', $db->setQuery($q)->loadColumn());
    }

    // ---------------------------------------------------------------------
    // Outils généraux additionnels
    // ---------------------------------------------------------------------

    private function listInstalledLanguages(): array
    {
        $out = [];

        foreach (['site', 'administrator'] as $client) {
            foreach (LanguageHelper::getInstalledLanguages($client === 'site' ? 0 : 1) as $lang) {
                $out[] = [
                    'code'   => (string) $lang->element,
                    'name'   => (string) $lang->name,
                    'client' => $client,
                ];
            }
        }

        return ['languages' => $out];
    }

    private function getRenderedPage(string $url): array
    {
        if (trim($url) === '') {
            throw new McpToolException("The 'url' parameter is required.");
        }

        // Chemin relatif → URL absolue du site. Refuse un hôte externe.
        if (!str_contains($url, '://')) {
            $url = rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
        } else {
            // Hôte EXACT du site uniquement (anti-SSRF, non contournable par
            // `https://monsite.tld.attaquant.tld/` comme l'ancien str_starts_with).
            UrlGuard::assertSameSite($url);
        }

        try {
            $response = HttpFactory::getHttp([], ['curl', 'stream'])->get($url, [], 25);
        } catch (\Throwable $e) {
            throw new McpToolException('Fetch failed: ' . $e->getMessage());
        }

        $html  = (string) $response->body;
        $title = preg_match('#<title>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode($m[1])) : '';

        // Texte : on retire script/style puis les balises, tronqué.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        $trunc = mb_substr($text, 0, 12000);

        return [
            'url'       => $url,
            'status'    => (int) $response->code,
            'bytes'     => \strlen($html),
            'title'     => $title,
            'text'      => $trunc,
            'truncated' => mb_strlen($text) > 12000,
        ];
    }

    private function clearCache(string $group): array
    {
        $groups = $group !== ''
            ? [$group]
            : ['_system', 'com_plugins', 'com_modules', 'com_content', 'com_menus', 'mod_menu', 'page', 'com_templates'];

        $cleared = [];

        foreach ($groups as $g) {
            try {
                Factory::getCache($g)->clean();
                $cleared[] = $g;
            } catch (\Throwable) {
                // best-effort par groupe
            }
        }

        return ['ok' => true, 'cleared' => $cleared];
    }

    // ------------------------------------------------------------- JCH Optimize

    /** JCH Optimize (composant) présent et actif ? */
    private function jchInstalled(): bool
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_jchoptimize'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('enabled') . ' = 1');

        return (int) $db->setQuery($q)->loadResult() > 0;
    }

    /** Boot du composant JCH (autoloading + constante JCH_PRO) → ComponentInterface. */
    private function bootJch()
    {
        return Factory::getApplication()->bootComponent('com_jchoptimize');
    }

    /** Conteneur DI interne de JCH (partage CacheMaintainer, etc.). */
    private function jchContainer()
    {
        $this->bootJch();
        $cf = '\\CodeAlfa\\Component\\JchOptimize\\Administrator\\Container\\ContainerFactory';

        if (!class_exists($cf)) {
            throw new McpToolException('JCH Optimize container unavailable (unexpected version?).');
        }

        return $cf::getInstance(Factory::getContainer());
    }

    private function jchIsPro(): bool
    {
        $this->bootJch();

        return \defined('JCH_PRO') && \constant('JCH_PRO') && \constant('JCH_PRO') !== '0';
    }

    private function clearJch(array $a): array
    {
        // Clé du conteneur = FQCN SANS backslash de tête (comme ::class).
        $maintainer = 'JchOptimize\\Core\\Model\\CacheMaintainer';

        try {
            $cm = $this->jchContainer()->get($maintainer);
            $ok = (bool) $cm->cleanCache();
        } catch (\Throwable $e) {
            throw new McpToolException('JCH clean cache failed: ' . $e->getMessage());
        }

        $result = ['ok' => $ok, 'jch_cache_cleared' => $ok] + $this->jchPluginsReport();

        if (!empty($a['rebuild'])) {
            $result['rebuild'] = $this->triggerRecache();
        }

        return $result;
    }

    private function rebuildJch(array $a): array
    {
        return ['ok' => true] + $this->triggerRecache((int) ($a['crawl_limit'] ?? 0)) + $this->jchPluginsReport();
    }

    /** État des 2 plugins système de cache JCH + avertissement si l'un est désactivé. */
    private function jchPluginsReport(): array
    {
        $p       = $this->jchCachePlugins();
        $report  = [
            'jch_plugins' => [
                'jchoptimize'  => $p['jchoptimize']['enabled'],
                'jchpagecache' => $p['jchpagecache']['enabled'],
            ],
        ];

        if (!$p['jchoptimize']['enabled'] || !$p['jchpagecache']['enabled']) {
            $report['warning'] = 'JCH caching is (partly) disabled — enable BOTH system plugins with '
                . 'set_jch_cache_state(enabled=true) for clearing/rebuilding to have any effect.';
        }

        return $report;
    }

    /** État courant des 2 plugins système : [element => {found, enabled}]. */
    private function jchCachePlugins(): array
    {
        $db     = $this->db();
        $inList = implode(',', array_map([$db, 'quote'], ['jchoptimize', 'jchpagecache']));
        $q      = $db->getQuery(true)
            ->select($db->quoteName(['element', 'enabled']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' IN (' . $inList . ')');
        $rows   = $db->setQuery($q)->loadObjectList('element') ?: [];

        $out = [];
        foreach (['jchoptimize', 'jchpagecache'] as $el) {
            $out[$el] = isset($rows[$el])
                ? ['found' => true, 'enabled' => (bool) $rows[$el]->enabled]
                : ['found' => false, 'enabled' => false];
        }

        return $out;
    }

    /** Active (enabled=true) ou désactive les 2 plugins système de cache JCH. */
    private function setJchState(array $a): array
    {
        if (!\array_key_exists('enabled', $a)) {
            throw new McpToolException('Parameter "enabled" (boolean) is required.');
        }

        $enabled = (bool) $a['enabled'];
        $before  = $this->jchCachePlugins();
        $db      = $this->db();

        $changed = [];
        foreach (['jchoptimize', 'jchpagecache'] as $el) {
            if ($before[$el]['found'] && $before[$el]['enabled'] !== $enabled) {
                $changed[] = $el;
            }
        }

        if ($changed !== []) {
            $u = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = ' . (int) $enabled)
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' IN (' . implode(',', array_map([$db, 'quote'], $changed)) . ')');
            $db->setQuery($u)->execute();

            // La liste des plugins publiés est mise en cache : purger.
            foreach (['com_plugins', '_system'] as $g) {
                try {
                    Factory::getCache($g)->clean();
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        $plugins = [];
        foreach (['jchoptimize', 'jchpagecache'] as $el) {
            $plugins[$el] = $before[$el]['found']
                ? ['found' => true, 'enabled' => $enabled]
                : ['found' => false];
        }

        return [
            'ok'      => true,
            'enabled' => $enabled,
            'changed' => $changed,
            'plugins' => $plugins,
        ];
    }

    /** URL publique du site pour la commande recache (live_site si défini, sinon schéma+hôte). */
    private function siteUrl(): string
    {
        $live = (string) Factory::getApplication()->get('live_site');

        if ($live === '') {
            $live = Uri::getInstance()->toString(['scheme', 'host', 'port']);
        }

        return rtrim($live, '/');
    }

    /**
     * Déclenche le recache JCH. JCH réserve le recache au CONTEXTE CLI (depuis le
     * web : « PathsUtils is not set »). On lance donc la commande CLI en tâche de
     * fond ; repli sur des instructions (commande + cron) si `exec` indisponible.
     */
    private function triggerRecache(int $crawlLimit = 0): array
    {
        $root  = rtrim(JPATH_ROOT, '/');
        $cli   = $root . '/cli/joomla.php';
        $live  = $this->siteUrl();
        // La commande recache EXIGE l'URL du site (sinon « must set the live-site option »).
        $limit = $crawlLimit > 0 ? ' --crawl-limit=' . $crawlLimit : '';
        $opt   = ' --live-site=' . $live . $limit;
        $base  = [
            'cli_command' => 'php cli/joomla.php jchoptimize:recache' . $opt,
            'cron'        => '0 4 * * * cd ' . $root . ' && php cli/joomla.php jchoptimize:recache' . $opt . ' >/dev/null 2>&1',
        ];

        if (!$this->jchIsPro()) {
            return ['started' => false, 'reason' => 'Rebuild (recache) requires JCH Optimize Pro.'] + $base;
        }

        // 1) Voie PRIMAIRE, web-native : ping async signé de l'endpoint frontend
        //    supporté par JCH (task=recache.run) — exactement le mécanisme du bouton
        //    « Reconstruire le cache » (JCH UtilityController::pingRecacheAsync). Ne
        //    dépend NI d'exec NI d'un binaire PHP visible (insensible à open_basedir),
        //    et ne requiert PAS le plugin Console (le crawl tourne via ReCacheModel::reCache()).
        if ($this->pingRecacheAsync()) {
            return [
                'started' => true,
                'method'  => 'web-async-ping',
                'note'    => 'Recache triggered via JCH\'s own signed frontend endpoint (task=recache.run) — the same path as the "Rebuild cache" button. The crawl runs in the background on the site; give it a moment.',
            ] + $base;
        }

        // 2) Repli : spawn du CLI en tâche de fond (exige exec + un binaire PHP + plugin Console).
        $php = $this->phpCli();

        if ($php !== null && is_file($cli) && $this->canExec()) {
            if (!$this->jchConsolePluginEnabled()) {
                $base['note'] = 'Enable the "Console - JCH Optimize" plugin (plg_console_jchoptimize) so the recache CLI command exists.';
            }

            // Tâche de fond détachée : rend la main immédiatement (URL shell-échappée).
            $execOpt = ' --live-site=' . escapeshellarg($live) . $limit;
            @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' jchoptimize:recache' . $execOpt . ' > /dev/null 2>&1 &');

            return [
                'started' => true,
                'method'  => 'cli-background',
                'note'    => 'Recache launched via background CLI (best-effort). If it does not take effect, run the command below or add the cron.',
            ] + $base;
        }

        // 3) Dernier recours : instructions manuelles (commande + cron dans $base).
        return [
            'started' => false,
            'reason'  => 'Could not trigger recache automatically (web self-ping and background CLI both unavailable). Run the command below, or add the cron.',
        ] + $base;
    }

    /**
     * Repli WEB natif de JCH : ping async signé de l'endpoint frontend supporté
     * `task=recache.run` (RecacheController::run), calqué sur
     * UtilityController::pingRecacheAsync() de JCH 9.3.1. L'endpoint valide
     * `hash_hmac('sha256', 'recache.run|'.ts, <secret Joomla>)` dans une fenêtre
     * de 5 min, répond 202 immédiatement (fastcgi_finish_request) puis lance le
     * crawl en contexte SITE via ReCacheModel::reCache(). Aucun exec/CLI requis,
     * insensible à open_basedir (les sockets n'y sont pas soumis).
     *
     * @return bool true si la requête POST a bien été émise (best-effort).
     */
    private function pingRecacheAsync(): bool
    {
        if (!\function_exists('stream_socket_client')) {
            return false;
        }

        $secret = (string) Factory::getApplication()->get('secret');

        if ($secret === '') {
            return false;
        }

        $ts  = (string) time();
        $sig = hash_hmac('sha256', 'recache.run|' . $ts, $secret);
        $url = $this->siteUrl() . '/index.php?option=com_jchoptimize&task=recache.run'
            . '&ts=' . rawurlencode($ts) . '&sig=' . rawurlencode($sig);

        $parts = parse_url($url);

        if (empty($parts['host'])) {
            return false;
        }

        $https     = (($parts['scheme'] ?? 'http') === 'https');
        $host      = (string) $parts['host'];
        $port      = (int) ($parts['port'] ?? ($https ? 443 : 80));
        $transport = $https ? 'ssl://' : '';
        $errno     = 0;
        $errstr    = '';

        // Connexion bloquante courte : garantit que la poignée de main TLS aboutit et
        // que le POST part réellement (l'endpoint détache ensuite le crawl via 202 +
        // fastcgi_finish_request, donc on ne bloque pas sur le crawl lui-même).
        $fp = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            3.0,
            \STREAM_CLIENT_CONNECT
        );

        if (!$fp) {
            return false;
        }

        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $req  = 'POST ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $host . "\r\n"
            . "Connection: Close\r\n"
            . "Content-Length: 0\r\n\r\n";

        $ok = @fwrite($fp, $req) !== false;
        @fclose($fp);

        return $ok;
    }

    /** Le plugin Console de JCH (qui fournit la commande jchoptimize:recache) est-il actif ? */
    private function jchConsolePluginEnabled(): bool
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('console'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('jchoptimize'))
            ->where($db->quoteName('enabled') . ' = 1');

        return (int) $db->setQuery($q)->loadResult() > 0;
    }

    /** Binaire PHP CLI adapté (dérivé de PHP_BINARY côté FPM, sinon chemins Plesk usuels). */
    private function phpCli(): ?string
    {
        $candidates = [];

        if (\defined('PHP_BINARY') && \PHP_BINARY !== '') {
            $candidates[] = preg_replace('#/[^/]*php-fpm[^/]*$#', '/php', str_replace('/sbin/', '/bin/', \PHP_BINARY));
            $candidates[] = str_replace('php-fpm', 'php', \PHP_BINARY);
        }

        foreach (['/opt/plesk/php/8.4/bin/php', '/opt/plesk/php/8.3/bin/php', '/usr/local/bin/php', '/usr/bin/php'] as $c) {
            $candidates[] = $c;
        }

        $probe = $this->canExec();

        foreach ($candidates as $c) {
            if ($c === '') {
                continue;
            }

            if (@is_executable($c)) {
                return $c;
            }

            // open_basedir (standard Plesk) empêche is_executable() de voir un
            // binaire hors du vhost (ex. /opt/plesk/php/*/bin/php) — alors qu'exec()
            // n'y est PAS soumis. On sonde donc le binaire via `php -v` : si exec est
            // dispo et que la commande répond « PHP … », le chemin est valide.
            if ($probe) {
                $out = [];
                $rc  = 1;
                @exec(escapeshellarg($c) . ' -v 2>/dev/null', $out, $rc);

                if ($rc === 0 && isset($out[0]) && stripos($out[0], 'php') !== false) {
                    return $c;
                }
            }
        }

        return null;
    }

    /** exec() disponible (pas dans disable_functions) ? */
    private function canExec(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !\in_array('exec', $disabled, true);
    }
}
