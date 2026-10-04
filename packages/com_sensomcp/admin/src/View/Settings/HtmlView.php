<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\View\Settings;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sensomedia\Component\Sensomcp\Administrator\Helper\ThemeHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public string $theme      = '';
    public string $themeMode = '';
    public string $endpoint   = '';
    public string $server     = '';
    public array $plugins     = [];
    public array $checks      = [];
    public array $directives  = [];
    public string $configJson = '';
    public array $tools       = [];

    public function display($tpl = null)
    {
        $model            = $this->getModel();
        $this->endpoint   = $model->getEndpoint();
        $this->server     = $model->getServer();
        $this->plugins    = $model->getPluginStatus();
        $this->checks     = $model->getChecks();
        $this->directives = $model->getDirectives();
        $this->configJson = $model->getConfigJson();
        $this->tools      = $model->getTools();

        $this->theme = ThemeHelper::key();
        $this->themeMode = ThemeHelper::mode();
        ThemeHelper::load();

        ToolbarHelper::title('Senso MCP', 'cog');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            ToolbarHelper::preferences('com_sensomcp');
        }

        parent::display($tpl);
    }
}
