<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Site\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolCollector;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\ClientStore;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\CodeStore;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\Metadata;
use Sensomedia\Component\Sensomcp\Administrator\OAuth\TokenService;

\defined('_JEXEC') or die;

/**
 * Endpoint d'autorisation OAuth 2.1 (interactif, app Site).
 *
 *   /index.php?option=com_sensomcp&task=oauth.authorize
 *
 * Authentifie le propriétaire de la ressource via la session Joomla, exige la
 * permission `core.login.api`, affiche un écran de consentement, puis émet un
 * code d'autorisation renvoyé au client.
 */
class OauthController extends BaseController
{
    public function authorize(): void
    {
        $app   = Factory::getApplication();
        $input = $app->getInput();

        // Paramètres OAuth de la requête d'autorisation.
        $params = [
            'response_type'         => (string) $input->getString('response_type', ''),
            'client_id'             => (string) $input->getString('client_id', ''),
            'redirect_uri'          => (string) $input->get('redirect_uri', '', 'raw'),
            'scope'                 => (string) $input->getString('scope', 'mcp'),
            'state'                 => (string) $input->get('state', '', 'raw'),
            'code_challenge'        => (string) $input->getString('code_challenge', ''),
            'code_challenge_method' => (string) $input->getString('code_challenge_method', 'S256'),
            'resource'              => (string) $input->get('resource', '', 'raw'),
        ];

        $client = (new ClientStore())->find($params['client_id']);

        // Erreurs non redirigeables (client/redirect_uri non fiables) → page d'erreur.
        if (!$client) {
            $this->fail(Text::_('COM_SENSOMCP_OAUTH_ERR_UNKNOWN_CLIENT'));
        }

        if (!(new ClientStore())->redirectUriAllowed($client, $params['redirect_uri'])) {
            $this->fail(Text::_('COM_SENSOMCP_OAUTH_ERR_BAD_REDIRECT'));
        }

        if ($params['response_type'] !== 'code') {
            $this->redirectError($params, 'unsupported_response_type');
        }

        if ($params['code_challenge'] === '' || strtoupper($params['code_challenge_method']) !== 'S256') {
            $this->redirectError($params, 'invalid_request', 'PKCE S256 requis');
        }

        // Authentification du propriétaire.
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            $return = base64_encode(Uri::getInstance()->toString());
            $app->redirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));

            return;
        }

        // Politique d'accès : tout compte disposant de core.login.api.
        if (!$user->authorise('core.login.api')) {
            $this->fail(Text::sprintf('COM_SENSOMCP_OAUTH_ERR_NO_PERMISSION', $user->username));
        }

        // Scopes proposables = ceux dont l'utilisateur a réellement l'ACL Joomla
        // (≥ 1 outil autorisé). Ne pas offrir un scope inutilisable ; borne aussi
        // le grant (POST) plus bas via array_intersect avec $available.
        $available = ToolCollector::scopesForUser($user);
        $requested = array_values(array_filter(explode(' ', $params['scope'])));

        // Rien demandé → on propose tout ; puis on borne au catalogue réel.
        if ($requested === []) {
            $requested = array_keys($available);
        }

        $requested = array_values(array_intersect($requested, array_keys($available)));

        // Décision de consentement (POST).
        $decision = $input->getWord('authorize_decision', '');

        if ($decision !== '') {
            if (!Session::checkToken('post')) {
                $this->fail(Text::_('JINVALID_TOKEN'));
            }

            if ($decision !== 'allow') {
                $this->redirectError($params, 'access_denied', 'Access denied by the user');
            }

            // Scopes cochés par l'utilisateur, bornés au catalogue disponible.
            $selected     = (array) $input->get('scopes', [], 'array');
            $granted      = array_values(array_intersect($selected, array_keys($available)));
            $grantedScope = implode(' ', $granted);

            $tokens = new TokenService();
            $code   = (new CodeStore($tokens))->create(
                $params['client_id'],
                (int) $user->id,
                $params['redirect_uri'],
                $grantedScope,
                $params['resource'] !== '' ? $params['resource'] : Metadata::mcpResource(),
                $params['code_challenge'],
                $params['code_challenge_method']
            );

            $app->redirect($this->appendQuery($params['redirect_uri'], [
                'code'  => $code,
                'state' => $params['state'],
            ]));

            return;
        }

        // Affichage de l'écran de consentement.
        $input->set('view', 'authorize');
        $view = $this->getView('Authorize', 'html');
        $view->clientName      = (string) $client->client_name;
        $view->redirectHost    = (string) (parse_url($params['redirect_uri'], \PHP_URL_HOST) ?: $params['redirect_uri']);
        $view->username        = (string) $user->name;
        $view->availableScopes = $available;
        $view->requestedScopes = $requested;
        $view->oauth           = $params;
        $view->display();
    }

    private function appendQuery(string $url, array $params): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        $q   = http_build_query(array_filter($params, static fn ($v) => $v !== ''));

        return $url . $sep . $q;
    }

    private function redirectError(array $params, string $error, string $description = ''): void
    {
        Factory::getApplication()->redirect($this->appendQuery($params['redirect_uri'], [
            'error'             => $error,
            'error_description' => $description,
            'state'             => $params['state'],
        ]));

        Factory::getApplication()->close();
    }

    private function fail(string $message): void
    {
        throw new \RuntimeException($message, 400);
    }
}
