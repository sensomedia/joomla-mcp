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

    /** Carte Connexion OAuth : .htaccess d'abord, découverte sous /api/ sinon. */
    public function oauthRepair(): void
    {
        $model = $this->guard();

        if ($model === null) {
            return;
        }

        $res  = $model->repairOauth();
        $back = 'index.php?option=com_sensomcp&view=settings';

        switch ($res['status']) {
            case 'htaccess':
                $this->app->enqueueMessage(Text::sprintf('COM_SENSOMCP_OAUTH_REPAIRED_HTACCESS', (string) $res['file']), 'message');

                if (!empty($res['backup'])) {
                    $this->app->enqueueMessage(Text::sprintf('COM_SENSOMCP_HTACCESS_BACKUP', $res['backup']), 'message');
                }
                break;

            case 'fallback':
                $this->app->enqueueMessage(Text::_('COM_SENSOMCP_OAUTH_REPAIRED_FALLBACK'), 'message');
                break;

            case 'failed':
                $this->app->enqueueMessage(Text::_('COM_SENSOMCP_OAUTH_REPAIR_FAILED'), 'error');
                $back .= '&oauth_manual=1';
                break;

            default:
                $this->app->enqueueMessage(Text::_('COM_SENSOMCP_SERVER_OK'), 'message');
        }

        $this->setRedirect(Route::_($back, false));
    }

    public function oauthFallbackOn(): void
    {
        $model = $this->guard();

        if ($model === null) {
            return;
        }

        $ok = $model->enableDiscoveryFallback();

        $this->setRedirect(
            Route::_('index.php?option=com_sensomcp&view=settings' . ($ok ? '' : '&oauth_manual=1'), false),
            Text::_($ok ? 'COM_SENSOMCP_OAUTH_FALLBACK_ENABLED' : 'COM_SENSOMCP_OAUTH_FALLBACK_NOEFFECT'),
            $ok ? 'message' : 'error'
        );
    }

    public function oauthFallbackOff(): void
    {
        $model = $this->guard();

        if ($model === null) {
            return;
        }

        $model->disableDiscoveryFallback();

        $this->setRedirect(
            Route::_('index.php?option=com_sensomcp&view=settings', false),
            Text::_('COM_SENSOMCP_OAUTH_FALLBACK_DISABLED'),
            'message'
        );
    }

    /** Active un plugin requis (`plugin` = folder.element) ou tous (`plugin` vide). */
    public function pluginsEnable(): void
    {
        $model = $this->guard('core.edit.state');

        if ($model === null) {
            return;
        }

        $count = $model->enableRequiredPlugins($this->app->input->getString('plugin', ''));

        $this->setRedirect(
            Route::_('index.php?option=com_sensomcp&view=settings', false),
            Text::plural('COM_SENSOMCP_PLUGINS_ENABLED_N', $count),
            $count > 0 ? 'message' : 'warning'
        );
    }

    public function tokenGroupAdd(): void
    {
        $this->tokenGroup('add');
    }

    public function tokenGroupRemove(): void
    {
        $this->tokenGroup('remove');
    }

    private function tokenGroup(string $action): void
    {
        $model = $this->guard('core.edit');

        if ($model === null) {
            return;
        }

        $res = $model->changeTokenGroup($action, $this->app->input->getInt('group_id', 0));

        [$key, $type] = match ($res) {
            'ok'    => [$action === 'add' ? 'COM_SENSOMCP_TOKEN_GROUP_ADDED' : 'COM_SENSOMCP_TOKEN_GROUP_REMOVED', 'message'],
            'last'  => ['COM_SENSOMCP_TOKEN_GROUP_LAST', 'warning'],
            default => ['COM_SENSOMCP_TOKEN_GROUP_INVALID', 'error'],
        };

        $this->setRedirect(Route::_('index.php?option=com_sensomcp&view=settings', false), Text::_($key), $type);
    }

    /**
     * Jeton CSRF + core.admin sur Senso MCP (+ $pluginsAction sur com_plugins quand
     * l'action modifie un autre plugin) ; renvoie le modèle, ou null après redirection.
     */
    private function guard(string $pluginsAction = ''): ?\Sensomedia\Component\Sensomcp\Administrator\Model\SettingsModel
    {
        $this->checkToken();

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.admin', 'com_sensomcp') || ($pluginsAction !== '' && !$user->authorise($pluginsAction, 'com_plugins'))) {
            $this->setRedirect(Route::_('index.php?option=com_sensomcp&view=settings', false), Text::_('JERROR_ALERTNOAUTHOR'), 'error');

            return null;
        }

        return $this->getModel('Settings');
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
