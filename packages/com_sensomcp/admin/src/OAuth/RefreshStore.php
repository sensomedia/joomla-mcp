<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Refresh tokens : stockés hachés, avec rotation (OAuth 2.1 impose la rotation
 * pour les clients publics).
 */
final class RefreshStore
{
    /** Durée de vie d'un refresh token (secondes) — 30 jours. */
    public const REFRESH_TTL = 2592000;

    public function __construct(private TokenService $tokens)
    {
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * `$origin` = date du PREMIER refresh token de la chaîne (recopiée à chaque rotation) ;
     * null = nouvelle chaîne (consentement). Sert à la durée absolue d'une connexion.
     */
    public function create(string $clientId, int $userId, string $scope, string $resource, ?string $origin = null, ?string $family = null): string
    {
        $token = $this->tokens->opaque(32);
        $db    = $this->db();
        $now   = Factory::getDate()->toSql();

        $row = (object) [
            'token_hash' => $this->tokens->hash($token),
            'client_id'  => $clientId,
            'user_id'    => $userId,
            'scope'      => $scope,
            'resource'   => $resource,
            'expires'    => Factory::getDate('+' . self::REFRESH_TTL . ' seconds')->toSql(),
            'revoked'    => 0,
            'created'    => $now,
            'origin'     => $origin ?: $now,
            // Lignée : identifiant partagé par toute la chaîne de rotation d'une autorisation.
            'family'     => $family ?: bin2hex(random_bytes(16)),
        ];

        $db->insertObject('#__sensomcp_oauth_refresh', $row);

        return $token;
    }

    /**
     * La chaîne de cette ligne peut-elle encore être prolongée ? Renvoie null si oui,
     * sinon la raison (`invalid_grant`) : durée absolue dépassée (param `oauth_max_days`,
     * 0 = illimitée), compte bloqué / non activé, ou droit `core.login.api` perdu.
     * Appelé APRÈS consume() : en cas de refus la ligne est déjà révoquée → le client
     * doit repasser par le consentement (audit 2026-10-12, M1).
     */
    public function renewalRefusal(object $row): ?string
    {
        $maxDays = (int) ComponentHelper::getParams('com_sensomcp')->get('oauth_max_days', 90);
        $origin  = (string) ($row->origin ?: $row->created);

        if ($maxDays > 0 && $origin !== '' && Factory::getDate($origin)->toUnix() < time() - $maxDays * 86400) {
            return 'OAuth session older than ' . $maxDays . ' days: please authorize again';
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $row->user_id);

        if (!$user || !$user->id || $user->get('block') || !\in_array((string) $user->get('activation'), ['', '0'], true)) {
            return 'Account blocked or not activated';
        }

        if (!$user->authorise('core.login.api')) {
            return 'Account no longer allowed to use the API (core.login.api)';
        }

        return null;
    }

    /**
     * Consomme un refresh token (le révoque) et renvoie sa ligne si valide.
     * La rotation (émission d'un nouveau) est faite par l'appelant.
     */
    public function consume(string $token): ?object
    {
        $db   = $this->db();
        $hash = $this->tokens->hash($token);

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__sensomcp_oauth_refresh'))
            ->where($db->quoteName('token_hash') . ' = :h')
            ->bind(':h', $hash, ParameterType::STRING);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        // Jeton déjà consommé représenté à nouveau : rejeu (audit, réutilisation).
        if ((int) $row->revoked === 1) {
            $this->onReplay($row);

            return null;
        }

        // `expires` en UTC → reparse UTC via Factory::getDate() (cf. CodeStore) :
        // strtotime() utiliserait le fuseau local → tokens vus « expirés » hors UTC.
        if (Factory::getDate($row->expires)->toUnix() < time()) {
            return null;
        }

        // Rotation : on révoque l'ancien et on date sa consommation.
        $now = Factory::getDate()->toSql();
        $upd = $db->getQuery(true)
            ->update($db->quoteName('#__sensomcp_oauth_refresh'))
            ->set($db->quoteName('revoked') . ' = 1')
            ->set($db->quoteName('consumed') . ' = :now')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $row->id, ParameterType::INTEGER);
        $db->setQuery($upd)->execute();

        return $row;
    }

    /**
     * Un jeton consommé est présenté une seconde fois. Dans le délai de grâce
     * (`oauth_replay_grace`, 60 s) : client qui rejoue après une réponse perdue →
     * simple refus. Au-delà : le jeton a été copié → toute la lignée (même
     * utilisateur, même client, même autorisation d'origine) est révoquée, le client
     * légitime devra réautoriser. Une ligne révoquée sans date de consommation
     * (révocation de lignée, déconnexion) n'est pas un rejeu.
     */
    private function onReplay(object $row): void
    {
        if (empty($row->consumed) || str_starts_with((string) $row->consumed, '0000')) {
            return;
        }

        $grace = max(0, (int) ComponentHelper::getParams('com_sensomcp')->get('oauth_replay_grace', 60));

        if (Factory::getDate($row->consumed)->toUnix() + $grace >= time()) {
            return;
        }

        $db     = $this->db();
        $family = (string) ($row->family ?? '');

        if ($family === '') {
            return;
        }

        $upd = $db->getQuery(true)
            ->update($db->quoteName('#__sensomcp_oauth_refresh'))
            ->set($db->quoteName('revoked') . ' = 1')
            ->where($db->quoteName('revoked') . ' = 0')
            ->where($db->quoteName('family') . ' = :family')
            ->bind(':family', $family, ParameterType::STRING);
        $db->setQuery($upd)->execute();
        $n = (int) $db->getAffectedRows();

        try {
            Log::add(
                \sprintf('OAuth refresh token replay: user %d, client %s, family %s — %d active token(s) of the chain revoked', (int) $row->user_id, (string) $row->client_id, $family, $n),
                Log::WARNING,
                'com_sensomcp'
            );
        } catch (\Throwable) {
            // journalisation best-effort
        }
    }
}
