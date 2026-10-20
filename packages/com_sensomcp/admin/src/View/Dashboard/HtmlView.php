<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sensomedia\Component\Sensomcp\Administrator\Helper\ThemeHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public string $theme     = '';
    public string $themeMode = '';
    public array $stats      = [];
    public array $perDay     = [];
    public array $topTools   = [];
    public array $topMethods = [];
    public array $recent     = [];
    public array $stale      = [];

    public function display($tpl = null)
    {
        $model            = $this->getModel();
        $this->stats      = $model->getStats();
        $this->perDay     = $model->getPerDay(14);
        $this->topTools   = $model->getTopTools();
        $this->topMethods = $model->getTopMethods();
        $this->recent     = $model->getRecent(30);
        $this->stale      = $model->getStaleConnections();

        $this->theme = ThemeHelper::key();
        $this->themeMode = ThemeHelper::mode();
        ThemeHelper::load();

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title('Senso MCP', 'chart');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            ToolbarHelper::preferences('com_sensomcp');
        }
    }
}
