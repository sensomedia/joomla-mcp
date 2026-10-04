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
 * Codes d'autorisation : à usage unique, courte durée de vie.
 */
final class CodeStore
{
    /** Durée de vie d'un code (secondes). */
    public const CODE_TTL = 60;

    public function __construct(private TokenService $tokens)
    {
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Crée un code d'autorisation et renvoie sa valeur en clair.
     */
    public function create(
        string $clientId,
        int $userId,
        string $redirectUri,
        string $scope,
        string $resource,
        string $codeChallenge,
        string $codeChallengeMethod
    ): string {
        $code = $this->tokens->opaque(32);
        $db   = $this->db();

        $row = (object) [
            'code_hash'             => $this->tokens->hash($code),
            'client_id'             => $clientId,
            'user_id'               => $userId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => $scope,
            'resource'              => $resource,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod ?: 'S256',
            'expires'               => Factory::getDate('+' . self::CODE_TTL . ' seconds')->toSql(),
            'created'               => Factory::getDate()->toSql(),
        ];

        $db->insertObject('#__sensomcp_oauth_codes', $row);

        return $code;
    }

    /**
     * Consomme un code (le supprime) et renvoie sa ligne si valide et non expiré.
     */
    public function consume(string $code): ?object
    {
        $db   = $this->db();
        $hash = $this->tokens->hash($code);

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__sensomcp_oauth_codes'))
            ->where($db->quoteName('code_hash') . ' = :h')
            ->bind(':h', $hash, ParameterType::STRING);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        // Usage unique : suppression immédiate quelle que soit la suite.
        $del = $db->getQuery(true)
            ->delete($db->quoteName('#__sensomcp_oauth_codes'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $row->id, ParameterType::INTEGER);
        $db->setQuery($del)->execute();

        // `expires` est stocké en UTC (Factory::getDate()->toSql()). On le reparse
        // en UTC via Factory::getDate() : strtotime() l'interpréterait dans le fuseau
        // local du serveur → décalage → codes vus « expirés » sur un serveur non-UTC.
        if (Factory::getDate($row->expires)->toUnix() < time()) {
            return null;
        }

        return $row;
    }

    /** Purge des codes expirés (housekeeping best-effort). */
    public function purgeExpired(): void
    {
        $db  = $this->db();
        $now = Factory::getDate()->toSql();
        $q   = $db->getQuery(true)
            ->delete($db->quoteName('#__sensomcp_oauth_codes'))
            ->where($db->quoteName('expires') . ' < :now')
            ->bind(':now', $now, ParameterType::STRING);
        $db->setQuery($q)->execute();
    }
}
