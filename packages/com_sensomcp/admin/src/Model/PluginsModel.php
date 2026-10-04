<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolCollector;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolState;

\defined('_JEXEC') or die;

/**
 * Données de l'écran « Plugins & outils » : plugins de la famille sensomcp
 * (activables), leurs outils (activables un par un via #__sensomcp_tool_state),
 * et le catalogue distant (dérivé d'ARS sur sensomcp.com).
 */
class PluginsModel extends BaseDatabaseModel
{
    /**
     * Plugins d'OUTILS MCP (groupe `sensomcp` uniquement), avec état d'activation.
     *
     * @return list<array{id:int, element:string, folder:string, label:string, enabled:bool}>
     */
    public function getPlugins(): array
    {
        $db  = $this->getDatabase();
        $q   = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'element', 'name', 'enabled', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('sensomcp'))
            ->order($db->quoteName('element') . ' ASC');

        $lang = Factory::getApplication()->getLanguage();
        $out  = [];

        foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r) {
            $element  = (string) $r['element'];
            $manifest = json_decode((string) ($r['manifest_cache'] ?? ''), true);
            $lang->load('plg_sensomcp_' . $element, JPATH_ADMINISTRATOR);

            $out[] = [
                'id'      => (int) $r['extension_id'],
                'element' => $element,
                'folder'  => 'sensomcp',
                'label'   => Text::_((string) $r['name']),
                'enabled' => (bool) $r['enabled'],
                'version' => (string) ($manifest['version'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Outils exposés, groupés par plugin d'origine, avec risque et état effectif.
     *
     * @return array<string, list<array>>
     */
    public function getToolsByOrigin(): array
    {
        $guest    = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);
        $registry = ToolCollector::collect($guest);
        $registry->setToolPolicy(ToolState::getMap());

        $byOrigin = [];

        foreach ($registry->describe() as $tool) {
            $byOrigin[$tool['origin']][] = $tool;
        }

        return $byOrigin;
    }

    private const CATALOG_URL = 'https://www.sensomcp.com/index.php?sensomcp_catalog=1';
    private const CATALOG_TTL = 60; // 60 s — juste de quoi absorber une rafale de rechargements

    /**
     * Catalogue distant des plugins (dérivé d'ARS sur sensomcp.com). Stratégie
     * LIVE-FIRST : au-delà d'un court TTL (60 s, anti-rafale), on re-fetch en
     * direct à chaque affichage — les données de licence (couverture, versions)
     * doivent rester fraîches. La copie stockée dans les params du composant ne
     * sert plus que de REPLI hors-ligne, quand la boutique est injoignable.
     *
     * @return list<array<string,mixed>>
     */
    public function getCatalog(bool $force = false): array
    {
        $params = $this->componentParams();
        $age    = time() - (int) ($params['catalog_at'] ?? 0);
        $cached = json_decode((string) ($params['catalog_json'] ?? ''), true);
        $key    = (string) ($params['license_key'] ?? '');
        $lang   = $this->currentLang();

        // Le cache n'est valide que s'il a été bâti avec la clé de licence ET la langue
        // courantes : changer l'une ou l'autre l'invalide aussitôt (noms traduits par langue).
        if (!$force
            && \is_array($cached)
            && $age < self::CATALOG_TTL
            && (string) ($params['catalog_key'] ?? '') === $key
            && (string) ($params['catalog_lang'] ?? '') === $lang) {
            return $cached;
        }

        $fresh = $this->fetchCatalogRemote($params);

        if ($fresh !== null) {
            $this->storeCatalogCache($params, $fresh);

            return $fresh;
        }

        return \is_array($cached) ? $cached : [];
    }

    /** @return list<array<string,mixed>>|null null = échec réseau */
    private function fetchCatalogRemote(array $params): ?array
    {
        try {
            $url = self::CATALOG_URL
                . '&key=' . urlencode((string) ($params['license_key'] ?? ''))
                . '&domain=' . urlencode(Uri::getInstance()->getHost())
                . '&lang=' . urlencode($this->currentLang());

            $res = HttpFactory::getHttp()->get($url, [], 6);

            if ((int) $res->code !== 200) {
                return null;
            }

            $data = json_decode((string) $res->body, true);

            return isset($data['plugins']) && \is_array($data['plugins']) ? $data['plugins'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Langue courante du back-office (2 lettres) pour la localisation du catalogue. */
    private function currentLang(): string
    {
        return strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2)) ?: 'en';
    }

    /** Clé de licence (Download ID) configurée dans le composant. */
    public function getLicenseKey(): string
    {
        return trim((string) ($this->componentParams()['license_key'] ?? ''));
    }

    /** Params du composant lus NON cachés. */
    private function componentParams(): array
    {
        $db = $this->getDatabase();

        return json_decode((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
        )->loadResult(), true) ?: [];
    }

    private function storeCatalogCache(array $params, array $plugins): void
    {
        $params['catalog_json'] = json_encode($plugins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $params['catalog_at']   = time();
        $params['catalog_key']  = (string) ($params['license_key'] ?? '');
        $params['catalog_lang'] = $this->currentLang();
        $json                   = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $db = $this->getDatabase();
        $u  = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :p')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
            ->bind(':p', $json, ParameterType::STRING);

        $db->setQuery($u)->execute();
    }

    /** Retire le préfixe « Senso MCP » (+ tiret/espaces) d'un nom de plugin. */
    private function stripBrand(string $name): string
    {
        return trim(preg_replace('/^Senso\s*MCP\b[\s\-–—:]*/u', '', $name)) ?: $name;
    }

    /**
     * Libellé affiché d'un plugin : chaîne de langue `PLG_SENSOMCP_<ELEMENT>`
     * (sans le préfixe « Senso MCP - »). Repli sur `$fallback` (nom du catalogue
     * distant) pour un plugin NON installé, dont le pack de langue n'est pas présent.
     */
    private function pluginLabel(string $element, string $fallback): string
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_sensomcp_' . $element, JPATH_ADMINISTRATOR);

        $key = 'PLG_SENSOMCP_' . strtoupper($element);
        $val = Text::_($key);

        return $this->stripBrand($val !== $key ? $val : $fallback);
    }

    /** Plugins installés du groupe sensomcp, indexés par element. */
    private function installedByElement(): array
    {
        $out = [];

        foreach ($this->getPlugins() as $p) {
            $out[$p['element']] = ['id' => $p['id'], 'enabled' => $p['enabled'], 'name' => $p['label'], 'version' => $p['version']];
        }

        return $out;
    }

    /**
     * Liste UNIFIÉE pour la vue : catalogue distant (payants) + plugins free
     * installés, croisés avec l'état local et leurs outils.
     *
     * @return list<array<string,mixed>>
     */
    public function getCatalogPlugins(bool $force = false): array
    {
        $catalog   = $this->getCatalog($force);
        $installed = $this->installedByElement();
        $tools     = $this->getToolsByOrigin();
        $order     = ['free' => 0, 'pro' => 1, 'premium' => 2, 'addon' => 3];
        $map       = [];

        foreach ($catalog as $c) {
            $el     = (string) ($c['element'] ?? '');
            $inst   = $installed[$el] ?? null;
            $latest = (string) ($c['version'] ?? '');
            $instV  = (string) ($inst['version'] ?? '');
            $map[$el] = [
                'element'           => $el,
                'tier'              => (string) ($c['tier'] ?? 'addon'),
                'name'              => $this->pluginLabel($el, (string) ($c['name'] ?? $el)),
                'version'           => $latest,
                'latest_version'    => $latest,
                'installed_version' => $instV,
                'update_available'  => $inst !== null && $latest !== '' && $instV !== '' && version_compare($instV, $latest, '<'),
                'stream_id'         => (int) ($c['stream_id'] ?? 0),
                'buy_url'           => (string) ($c['buy_url'] ?? ''),
                'installable'       => (bool) ($c['installable'] ?? false),
                'installed'         => $inst !== null,
                'id'                => (int) ($inst['id'] ?? 0),
                'enabled'           => (bool) ($inst['enabled'] ?? false),
                'tools'             => $inst !== null ? ($tools[$el] ?? []) : [],
            ];
        }

        // Plugins installés hors catalogue = socle free (site, content).
        foreach ($installed as $el => $inst) {
            if (isset($map[$el])) {
                continue;
            }
            $map[$el] = [
                'element'           => $el,
                'tier'              => 'free',
                'name'              => $this->pluginLabel($el, $inst['name']),
                'version'           => '',
                'latest_version'    => '',
                'installed_version' => (string) ($inst['version'] ?? ''),
                'update_available'  => false,
                'stream_id'         => 0,
                'buy_url'           => '',
                'installable'       => false,
                'installed'         => true,
                'id'                => $inst['id'],
                'enabled'           => (bool) $inst['enabled'],
                'tools'             => $tools[$el] ?? [],
            ];
        }

        $list = array_values($map);
        usort($list, static fn ($a, $b) => [$order[$a['tier']] ?? 9, $a['name']] <=> [$order[$b['tier']] ?? 9, $b['name']]);

        return $list;
    }
}
