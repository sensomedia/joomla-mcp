<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\OAuth;

use Joomla\CMS\Factory;
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

    public function create(string $clientId, int $userId, string $scope, string $resource): string
    {
        $token = $this->tokens->opaque(32);
        $db    = $this->db();

        $row = (object) [
            'token_hash' => $this->tokens->hash($token),
            'client_id'  => $clientId,
            'user_id'    => $userId,
            'scope'      => $scope,
            'resource'   => $resource,
            'expires'    => Factory::getDate('+' . self::REFRESH_TTL . ' seconds')->toSql(),
            'revoked'    => 0,
            'created'    => Factory::getDate()->toSql(),
        ];

        $db->insertObject('#__sensomcp_oauth_refresh', $row);

        return $token;
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

        // `expires` en UTC → reparse UTC via Factory::getDate() (cf. CodeStore) :
        // strtotime() utiliserait le fuseau local → tokens vus « expirés » hors UTC.
        if (!$row || (int) $row->revoked === 1 || Factory::getDate($row->expires)->toUnix() < time()) {
            return null;
        }

        // Rotation : on révoque l'ancien.
        $upd = $db->getQuery(true)
            ->update($db->quoteName('#__sensomcp_oauth_refresh'))
            ->set($db->quoteName('revoked') . ' = 1')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $row->id, ParameterType::INTEGER);
        $db->setQuery($upd)->execute();

        return $row;
    }
}
