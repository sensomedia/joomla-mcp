<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Helper\LicenseClient;
use Sensomedia\Component\Sensomcp\Administrator\Helper\UpdateSitesHelper;

\defined('_JEXEC') or die;

/**
 * Section Licence du dashboard client : saisie de la clé, vérification et
 * activation (déclaration) du domaine courant auprès de la boutique. Tout en AJAX.
 */
class LicenseController extends BaseController
{
    /** État courant (clé + couverture du domaine). */
    public function status(): void
    {
        $this->ajaxGuard();
        $this->ajaxOut(true, '', $this->fetchStatus());
    }

    /** Enregistre la clé de licence puis renvoie l'état vérifié. */
    public function saveKey(): void
    {
        $this->ajaxGuard();

        $key = trim((string) $this->input->post->getString('key', ''));
        // Changer la clé change la licence (couverture + versions du catalogue) :
        // on invalide le catalogue local (catalog_at = 0) pour forcer un re-fetch
        // au prochain affichage des plugins, sans attendre l'expiration du TTL.
        $this->setComponentParams(['license_key' => $key, 'catalog_at' => 0]);
        UpdateSitesHelper::sync();

        $this->ajaxOut(true, '', $this->fetchStatus());
    }

    /** Active (déclare/réaffecte) le domaine courant sur la licence choisie. */
    public function activate(): void
    {
        $this->ajaxGuard();

        $licenseId = (int) $this->input->post->getInt('license_id', 0);
        $mode      = $this->input->post->getWord('mode', 'declare');
        $key       = $this->currentKey();

        $res = LicenseClient::assign($key, Uri::getInstance()->getHost(), $licenseId, $mode);
        UpdateSitesHelper::sync();

        if ($res === null) {
            $this->ajaxOut(false, Text::_('COM_SENSOMCP_LICENSE_STORE_UNREACHABLE'), $this->fetchStatus());
        }

        $ok  = !empty($res['ok']);

        if ($ok) {
            // Le domaine courant vient d'être déclaré/réaffecté côté boutique : la
            // couverture (installable par plugin) a changé, mais le catalogue local
            // reste figé jusqu'à l'expiration du TTL et n'est PAS invalidé par un
            // changement de domaine côté serveur. On l'invalide donc ici (catalog_at
            // = 0) pour un re-fetch immédiat au prochain affichage des plugins.
            $this->setComponentParams(['catalog_at' => 0]);
        }

        $tag = strtoupper((string) ($ok ? ($res['result'] ?? 'ok') : ($res['error'] ?? 'generic')));
        $msg = Text::_(($ok ? 'COM_SENSOMCP_LICENSE_MSG_' : 'COM_SENSOMCP_LICENSE_ERR_') . $tag);

        $this->ajaxOut($ok, $ok ? '' : $msg, ['message' => $msg] + $this->fetchStatus());
    }

    // --- interne -------------------------------------------------------------

    /** @return array<string,mixed> */
    private function fetchStatus(): array
    {
        $key  = $this->currentKey();
        $host = Uri::getInstance()->getHost();

        if ($key === '') {
            return ['key_set' => false, 'key_masked' => '', 'host' => $host, 'effective_tier' => 'free', 'action' => 'none', 'candidates' => []];
        }

        // Semi-masquée : seuls les 4 derniers caractères en clair.
        $masked = \strlen($key) >= 4 ? '••••••••' . substr($key, -4) : '••••';
        $base   = ['key_set' => true, 'key_masked' => $masked, 'host' => $host];

        $v = LicenseClient::verify($key, $host);

        if ($v === null) {
            // Cause technique (erreur cURL, HTTP, non-JSON) pour l'admin : sans elle,
            // un hébergement qui bloque la sortie HTTPS est indiagnosticable.
            return $base + [
                'unreachable'    => true,
                'detail'         => LicenseClient::lastError(),
                'effective_tier' => 'unknown',
                'action'         => 'none',
                'candidates'     => [],
            ];
        }

        if (empty($v['ok']) && ($v['error'] ?? '') === 'invalid_key') {
            return $base + ['invalid' => true, 'effective_tier' => 'free', 'action' => 'none', 'candidates' => []];
        }

        return $base + array_intersect_key(
            $v,
            array_flip(['effective_tier', 'resolved', 'action', 'candidates', 'addons', 'rebind_pattern'])
        );
    }

    private function currentKey(): string
    {
        return trim((string) ($this->componentParams()['license_key'] ?? ''));
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** Params du composant lus NON cachés (effet immédiat). */
    private function componentParams(): array
    {
        $db = $this->db();

        return json_decode((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
        )->loadResult(), true) ?: [];
    }

    private function setComponentParams(array $changes): void
    {
        $params = array_merge($this->componentParams(), $changes);
        $json   = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_sensomcp'))
            ->bind(':params', $json, ParameterType::STRING);

        $db->setQuery($q)->execute();
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
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo json_encode(['ok' => $ok, 'error' => $error] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->app->close();
    }
}
