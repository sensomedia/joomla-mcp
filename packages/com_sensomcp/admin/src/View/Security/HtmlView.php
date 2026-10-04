<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\View\Security;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sensomedia\Component\Sensomcp\Administrator\Helper\ThemeHelper;

\defined('_JEXEC') or die;

/**
 * Vue Sécurité : arrêt d'urgence, mode lecture seule, limitation de débit,
 * révocation OAuth. (La gestion des plugins/outils est dans la vue Plugins.)
 */
class HtmlView extends BaseHtmlView
{
    public string $theme     = '';
    public string $themeMode = '';
    public bool $readonly    = false;
    public bool $locked      = false;
    public array $rateLimit  = [];

    public function display($tpl = null)
    {
        $model           = $this->getModel();
        $this->readonly  = $model->getReadOnly();
        $this->locked    = $model->getEmergencyStop();
        $this->rateLimit = $model->getRateLimit();

        $this->theme     = ThemeHelper::key();
        $this->themeMode = ThemeHelper::mode();
        ThemeHelper::load();

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title('Senso MCP', 'lock');
        // Bouton unique « Enregistrer » (on reste sur la vue : le contrôleur y redirige).
        ToolbarHelper::custom('security.save', 'save', 'save', 'COM_SENSOMCP_SAVE', false);

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            ToolbarHelper::preferences('com_sensomcp');
        }
    }
}
