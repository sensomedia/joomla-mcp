<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Helper\PluginRow;
use Sensomedia\Component\Sensomcp\Administrator\Helper\UpdateSitesHelper;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolState;
use Sensomedia\Component\Sensomcp\Administrator\Model\PluginsModel;

\defined('_JEXEC') or die;

/**
 * Écran « Plugins & outils » : toggles immédiats (plugins / outils), install en
 * un clic depuis la boutique, rafraîchissement du catalogue. TOUT en AJAX — pas
 * de bouton « Enregistrer » sur cette vue.
 */
class PluginsController extends BaseController
{
    public function ajaxTogglePlugin(): void
    {
        $this->ajaxGuard();
        $id = $this->app->input->getInt('id');
        $on = $this->app->input->getInt('enabled') === 1;

        if ($id <= 0) {
            $this->ajaxOut(false, 'bad id');
        }

        $element = $this->app->input->getCmd('element', '');

        $this->setPluginEnabled($id, $on);
        $this->cleanCaches();
        $this->ajaxOut(true, '', ['id' => $id, 'enabled' => $on, 'html' => $this->rowHtml($element)]);
    }

    public function ajaxToggleTool(): void
    {
        $this->ajaxGuard();
        $tool = (string) $this->app->input->getString('tool', '');
        $on   = $this->app->input->getInt('enabled') === 1;

        if (!preg_match('/^[a-z0-9_]+$/', $tool)) {
            $this->ajaxOut(false, 'bad tool');
        }

        ToolState::set($tool, $on);
        $this->ajaxOut(true, '', ['tool' => $tool, 'enabled' => $on]);
    }

    public function ajaxRefreshCatalog(): void
    {
        $this->ajaxGuard();

        /** @var PluginsModel $model */
        $model = $this->getModel('Plugins');
        $model->getCatalog(true);

        $this->ajaxOut(true, '', ['count' => \count($model->getCatalogPlugins())]);
    }

    /**
     * Install/reinstall en un clic : télécharge le ZIP du plugin depuis ARS
     * (authentifié par le Download ID) et l'installe via l'Installer Joomla.
     */
    public function ajaxInstall(): void
    {
        $this->ajaxGuard();
        $element = $this->app->input->getCmd('element', '');

        /** @var PluginsModel $model */
        $model = $this->getModel('Plugins');
        $entry = null;

        foreach ($model->getCatalog() as $p) {
            if (($p['element'] ?? '') === $element) {
                $entry = $p;
                break;
            }
        }

        if ($element === '' || $entry === null || empty($entry['download_url'])) {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_CATALOG_INSTALL_UNKNOWN'));
        }
        if (empty($entry['installable'])) {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_CATALOG_INSTALL_NOT_LICENSED'));
        }
        $key = $model->getLicenseKey();
        if ($key === '') {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_CATALOG_INSTALL_NO_KEY'));
        }

        $ok  = false;
        $err = '';
        $pkg = '';
        $dir = '';

        try {
            // Le domaine du site est REQUIS par la boutique (contrôle du siège) : sans
            // lui, la boutique refuse le téléchargement (une requête distante sans
            // domaine = pas de contournement du siège). Même clé que la MAJ auto.
            $domain = Uri::getInstance()->getHost();
            $tmp    = InstallerHelper::downloadPackage(
                $entry['download_url'] . '&dlid=' . urlencode($key) . '&domain=' . urlencode($domain)
            );

            if ($tmp === false) {
                $err = Text::_('COM_SENSOMCP_CATALOG_INSTALL_DOWNLOAD_FAILED');
            } else {
                $pkg     = rtrim((string) $this->app->get('tmp_path'), '/') . '/' . $tmp;
                $package = InstallerHelper::unpack($pkg, true);

                if (!\is_array($package) || empty($package['dir'])) {
                    $err = Text::_('COM_SENSOMCP_CATALOG_INSTALL_FAILED');
                } else {
                    $dir       = $package['dir'];
                    $installer = new Installer();
                    $installer->setDatabase($this->db()); // J5/6 : sinon « Database not set »
                    $ok        = $installer->install($dir);
                    $err       = $ok ? '' : Text::_('COM_SENSOMCP_CATALOG_INSTALL_FAILED');
                }
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        if ($pkg !== '' || $dir !== '') {
            InstallerHelper::cleanupInstall($pkg, $dir);
        }

        // Auto-activation du plugin fraîchement installé.
        if ($ok) {
            $db = $this->db();
            $id = (int) $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote('sensomcp'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote($element))
            )->loadResult();

            if ($id > 0) {
                $this->setPluginEnabled($id, true);
            }

            UpdateSitesHelper::sync();

            // La map des namespaces PSR-4 a été bâtie au boot (avant l'install) : sans ça,
            // la classe du plugin fraîchement installé n'est pas autoloadable dans CETTE
            // requête → la collecte de ses outils échoue et rowHtml() renverrait ''.
            $this->registerPluginNamespace($element);
        }

        $this->cleanCaches();
        $this->ajaxOut((bool) $ok, $err, ['element' => $element, 'html' => $ok ? $this->rowHtml($element) : '']);
    }

    /** Désinstalle un plugin du groupe sensomcp (icône corbeille, confirmée côté JS). */
    public function ajaxUninstall(): void
    {
        $this->ajaxGuard();
        $element = $this->app->input->getCmd('element', '');

        if ($element === '') {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_CATALOG_INSTALL_UNKNOWN'));
        }

        // Le cœur gratuit (content, site) ne se désinstalle pas via cet écran.
        if (\in_array($element, ['content', 'site'], true)) {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_CATALOG_UNINSTALL_CORE'));
        }

        $db = $this->db();
        $id = (int) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('sensomcp'))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element))
        )->loadResult();

        if ($id <= 0) {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_CATALOG_INSTALL_UNKNOWN'));
        }

        $ok  = false;
        $err = '';

        try {
            $installer = new Installer();
            $installer->setDatabase($db);
            $ok = $installer->uninstall('plugin', $id);
            $err = $ok ? '' : Text::_('COM_SENSOMCP_CATALOG_UNINSTALL_FAILED');
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        if ($ok) {
            UpdateSitesHelper::sync();
        }

        $this->cleanCaches();
        $this->ajaxOut((bool) $ok, $err, ['element' => $element, 'html' => $ok ? $this->rowHtml($element) : '']);
    }

    /** Renvoie le HTML rafraîchi d'une ligne de plugin (remplacement DOM sans reload). */
    public function ajaxRow(): void
    {
        $this->ajaxGuard();
        $element = $this->app->input->getCmd('element', '');
        $html    = $this->rowHtml($element);

        $this->ajaxOut($html !== '', $html === '' ? Text::_('COM_SENSOMCP_CATALOG_INSTALL_UNKNOWN') : '', ['element' => $element, 'html' => $html]);
    }

    // --- interne -------------------------------------------------------------

    /** HTML d'une ligne pour l'element donné (état installé lu à chaud), ou ''. */
    private function rowHtml(string $element): string
    {
        if ($element === '') {
            return '';
        }

        // La liste des plugins chargés est mémoïsée en statique dès le boot (import
        // des plugins système) et survit à cleanCaches() : sans ce flush, un plugin
        // qu'on vient d'activer/désactiver/installer garde son état PÉRIMÉ ici →
        // outils et compteurs non rafraîchis. On réinitialise pour forcer une relecture.
        $this->flushPluginCache();

        try {
            /** @var PluginsModel $model */
            $model = $this->getModel('Plugins');

            foreach ($model->getCatalogPlugins() as $p) {
                if (($p['element'] ?? '') === $element) {
                    return PluginRow::render($p);
                }
            }
        } catch (\Throwable) {
            // Ne jamais casser la réponse AJAX : ligne non rafraîchie plutôt qu'erreur.
        }

        return '';
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function setPluginEnabled(int $extensionId, bool $enabled): void
    {
        $db = $this->db();
        $en = $enabled ? 1 : 0;

        $q = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = :en')
            ->where($db->quoteName('extension_id') . ' = :id')
            ->bind(':en', $en, ParameterType::INTEGER)
            ->bind(':id', $extensionId, ParameterType::INTEGER);

        $db->setQuery($q)->execute();
    }

    /** Enregistre l'autoloader PSR-4 d'un plugin sensomcp (classe utilisable de suite). */
    private function registerPluginNamespace(string $element): void
    {
        try {
            $dir = JPATH_PLUGINS . '/sensomcp/' . $element;
            $xml = $dir . '/' . $element . '.xml';

            if (!is_file($xml)) {
                return;
            }

            $manifest = @simplexml_load_file($xml);

            if ($manifest === false) {
                return;
            }

            $ns   = trim((string) $manifest->namespace);
            $path = trim((string) ($manifest->namespace['path'] ?? 'src')) ?: 'src';

            if ($ns !== '') {
                \JLoader::registerNamespace($ns, $dir . '/' . $path);
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** Réinitialise le cache statique en mémoire de PluginHelper (relecture DB fraîche). */
    private function flushPluginCache(): void
    {
        try {
            $prop = new \ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function cleanCaches(): void
    {
        foreach (['com_plugins', 'com_modules', '_system'] as $group) {
            try {
                Factory::getCache($group)->clean();
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    private function ajaxGuard(): void
    {
        if (!Session::checkToken('request')) {
            $this->ajaxOut(false, Text::_('JINVALID_TOKEN'));
        }

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->ajaxOut(false, Text::_('JERROR_ALERTNOAUTHOR'));
        }
    }

    private function ajaxOut(bool $ok, string $error = '', array $data = []): void
    {
        $payload = json_encode(['ok' => $ok, 'error' => $error] + $data);

        // Purge de toute sortie parasite déjà tamponnée (echo du changelog par le postflight
        // d'un plugin, warnings…) pour garantir un corps de réponse = JSON SEUL.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo $payload;
        $this->app->close();
    }
}
