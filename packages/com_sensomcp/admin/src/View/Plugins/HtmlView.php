<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\View\Plugins;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sensomedia\Component\Sensomcp\Administrator\Helper\ThemeHelper;
use Sensomedia\Component\Sensomcp\Administrator\Helper\UpdateSitesHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public string $theme          = '';
    public string $themeMode      = '';
    public array $catalogPlugins  = [];

    public function display($tpl = null)
    {
        $this->catalogPlugins = $this->getModel()->getCatalogPlugins();

        // Maintient dlid+domain sur les update sites des plugins (gating update par domaine).
        UpdateSitesHelper::sync();

        $this->theme     = ThemeHelper::key();
        $this->themeMode = ThemeHelper::mode();
        ThemeHelper::load();

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        // Pas de bouton « Enregistrer » : tout est enregistré en AJAX (toggles immédiats).
        ToolbarHelper::title('Senso MCP', 'puzzle-piece');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            ToolbarHelper::preferences('com_sensomcp');
        }
    }
}
