<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/**
 * Vue Réglages : ajout / retrait automatique des blocs .htaccess des directives
 * serveur (voir Helper\Htaccess). L'ajout est vérifié par les sondes et annulé
 * s'il casse le site ou reste sans effet.
 */
class SettingsController extends BaseController
{
    public function htaccessAdd(): void
    {
        $this->run('applyHtaccess');
    }

    public function htaccessRemove(): void
    {
        $this->run('removeHtaccess');
    }

    private function run(string $method): void
    {
        $this->checkToken();

        $back = Route::_('index.php?option=com_sensomcp&view=settings', false);

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->setRedirect($back, Text::_('JERROR_ALERTNOAUTHOR'), 'error');

            return;
        }

        /** @var \Sensomedia\Component\Sensomcp\Administrator\Model\SettingsModel $model */
        $model = $this->getModel('Settings');
        $res   = $model->{$method}($this->app->input->getCmd('directive', ''));
        $file  = (string) ($res['file'] ?? '');

        [$key, $type] = match ($res['status']) {
            'ok'          => [$method === 'applyHtaccess' ? 'COM_SENSOMCP_HTACCESS_OK' : 'COM_SENSOMCP_HTACCESS_REMOVED', 'message'],
            'noeffect'    => ['COM_SENSOMCP_HTACCESS_NOEFFECT', 'warning'],
            'broke'       => ['COM_SENSOMCP_HTACCESS_BROKE', 'error'],
            'notwritable' => ['COM_SENSOMCP_HTACCESS_NOTWRITABLE', 'error'],
            'nodir'       => ['COM_SENSOMCP_HTACCESS_NODIR', 'error'],
            default       => ['COM_SENSOMCP_HTACCESS_FAILED', 'error'],
        };

        $this->app->enqueueMessage(Text::sprintf($key, $file), $type);

        if (!empty($res['backup']) && $res['status'] === 'ok') {
            $this->app->enqueueMessage(Text::sprintf('COM_SENSOMCP_HTACCESS_BACKUP', $res['backup']), 'message');
        }

        $this->setRedirect($back);
    }
}
