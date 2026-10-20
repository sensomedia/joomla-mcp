<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\CatalogState;

\defined('_JEXEC') or die;

/**
 * Vue Sécurité : arrêt d'urgence, mode lecture seule, limitation de débit,
 * révocation OAuth. (Les toggles plugins/outils sont dans PluginsController, AJAX.)
 */
class SecurityController extends BaseController
{
    public function save(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->setRedirect(
                Route::_('index.php?option=com_sensomcp&view=security', false),
                Text::_('JERROR_ALERTNOAUTHOR'),
                'error'
            );

            return;
        }

        $this->persistForm();
        $this->cleanCaches();

        $this->app->enqueueMessage(Text::_('COM_SENSOMCP_SECURITY_SAVED'), 'message');
        $this->setRedirect(Route::_('index.php?option=com_sensomcp&view=security', false));
    }

    /**
     * Enregistre les réglages (lecture seule + rate limiting). Les boutons
     * d'urgence/révocation soumettent ce même formulaire, donc leurs actions
     * persistent aussi les réglages en cours d'édition. $extraParams = clés de
     * params à fusionner en plus (ex. emergency_stop, oauth_revoke_before).
     */
    private function persistForm(array $extraParams = []): void
    {
        $input = $this->app->input;

        $this->setComponentParams(array_merge([
            'readonly'          => $input->getInt('readonly', 0) === 1 ? 1 : 0,
            'ratelimit_enabled' => $input->getInt('ratelimit_enabled', 0) === 1 ? 1 : 0,
            'ratelimit_max'     => max(1, $input->getInt('ratelimit_max', 120)),
            'ratelimit_window'  => max(1, $input->getInt('ratelimit_window', 60)),
        ], $extraParams));
    }

    /** Arrêt d'urgence : verrouille le serveur MCP (refus de toute requête). */
    public function lock(): void
    {
        $this->toggleEmergency(true);
    }

    /** Réactive le serveur MCP après un arrêt d'urgence. */
    public function unlock(): void
    {
        $this->toggleEmergency(false);
    }

    private function toggleEmergency(bool $on): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->setRedirect(
                Route::_('index.php?option=com_sensomcp&view=security', false),
                Text::_('JERROR_ALERTNOAUTHOR'),
                'error'
            );

            return;
        }

        $this->persistForm(['emergency_stop' => $on ? 1 : 0]);
        $this->cleanCaches();

        $this->app->enqueueMessage(
            Text::_($on ? 'COM_SENSOMCP_EMERGENCY_ON_MSG' : 'COM_SENSOMCP_EMERGENCY_OFF_MSG'),
            $on ? 'warning' : 'message'
        );
        $this->setRedirect(Route::_('index.php?option=com_sensomcp&view=security', false));
    }

    /**
     * Révocation OAuth globale (hard-kill) : supprime clients/codes/refresh et
     * pose un point de révocation → tous les access tokens JWT déjà émis sont
     * rejetés. NE touche PAS les jetons API Joomla statiques.
     */
    public function revokeOauth(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->setRedirect(
                Route::_('index.php?option=com_sensomcp&view=security', false),
                Text::_('JERROR_ALERTNOAUTHOR'),
                'error'
            );

            return;
        }

        $db = $this->db();

        foreach (['#__sensomcp_oauth_codes', '#__sensomcp_oauth_refresh', '#__sensomcp_oauth_clients'] as $table) {
            try {
                $db->setQuery($db->getQuery(true)->delete($db->quoteName($table)))->execute();
            } catch (\Throwable) {
                // best-effort ; on continue pour poser quand même le point de révocation.
            }
        }

        $this->persistForm(['oauth_revoke_before' => time()]);
        $this->cleanCaches();

        $this->app->enqueueMessage(Text::_('COM_SENSOMCP_OAUTH_REVOKE_MSG'), 'warning');
        $this->setRedirect(Route::_('index.php?option=com_sensomcp&view=security', false));
    }

    /**
     * Révoque UNE connexion OAuth (utilisateur + client) dont la liste d'outils
     * chargée est périmée : ses refresh tokens sont marqués révoqués, le client
     * devra se réauthentifier et rechargera ainsi le catalogue. Les access tokens
     * déjà émis expirent d'eux-mêmes (courte durée). Jetons API Joomla : non concernés.
     */
    public function revokeConnection(): void
    {
        $this->checkToken();

        $redirect = Route::_('index.php?option=com_sensomcp&view=dashboard', false);

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->setRedirect($redirect, Text::_('JERROR_ALLOWNOAUTHOR'), 'error');

            return;
        }

        $key = (string) $this->input->getString('key', '');

        if (!preg_match('/^oauth:([^:]+):(\d+)$/', $key, $m)) {
            $this->setRedirect($redirect, Text::_('COM_SENSOMCP_STALE_NOT_OAUTH'), 'warning');

            return;
        }

        $clientId = $m[1];
        $userId   = (int) $m[2];
        $db       = $this->db();

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__sensomcp_oauth_refresh'))
                    ->set($db->quoteName('revoked') . ' = 1')
                    ->where($db->quoteName('client_id') . ' = :c')
                    ->where($db->quoteName('user_id') . ' = :u')
                    ->bind(':c', $clientId, ParameterType::STRING)
                    ->bind(':u', $userId, ParameterType::INTEGER)
            )->execute();
        } catch (\Throwable) {
            // best-effort
        }

        CatalogState::forget($key);

        $this->app->enqueueMessage(Text::_('COM_SENSOMCP_STALE_REVOKE_MSG'), 'warning');
        $this->setRedirect($redirect);
    }

    /** Retire une connexion de la liste des connexions périmées (sans la révoquer). */
    public function forgetConnection(): void
    {
        $this->checkToken();

        $redirect = Route::_('index.php?option=com_sensomcp&view=dashboard', false);

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_sensomcp')) {
            $this->setRedirect($redirect, Text::_('JERROR_ALLOWNOAUTHOR'), 'error');

            return;
        }

        CatalogState::forget((string) $this->input->getString('key', ''));
        $this->setRedirect($redirect);
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** Fusionne des changements dans les params du composant (une lecture, une écriture). */
    private function setComponentParams(array $changes): void
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'));

        $params = json_decode((string) $db->setQuery($q)->loadResult(), true) ?: [];

        foreach ($changes as $key => $value) {
            $params[$key] = $value;
        }

        $json = json_encode($params);

        $u = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
            ->bind(':params', $json, ParameterType::STRING);

        $db->setQuery($u)->execute();
    }

    /** Purge les caches impactés (params, plugins, modules). */
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
}
