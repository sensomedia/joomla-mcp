<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Empreinte du catalogue d'outils vue par chaque connexion MCP.
 *
 * Les clients MCP (claude.ai…) chargent la liste des outils à la connexion
 * et ne la rechargent pas d'eux-mêmes : un plugin installé, un outil ajouté
 * ou une description retouchée restent invisibles tant que l'utilisateur n'a
 * pas déconnecté/reconnecté le connecteur. Ici :
 *
 *  - à chaque `tools/list`, on mémorise l'empreinte (sha1 des noms, descriptions
 *    et schémas visibles par ce jeton) de la connexion (`#__sensomcp_catalog`) ;
 *  - à chaque `tools/call`, on compare à l'empreinte courante : si elle diffère,
 *    un avertissement est ajouté en tête du résultat pour que l'assistant le
 *    répète à l'utilisateur (au plus une fois par NOTIFY_INTERVAL) ;
 *  - le tableau de bord liste les connexions périmées (et permet de les révoquer).
 *
 * Connexion = `oauth:<client_id>:<user_id>` (jeton OAuth) ou `api:<user_id>`
 * (jeton API Joomla). Une connexion sans ligne (établie avant cette version)
 * est enregistrée au premier `tools/call` avec l'empreinte courante (supposée
 * vue) : on ne peut rien affirmer sur son passé, mais tout changement ultérieur
 * sera détecté.
 */
final class CatalogState
{
    /** Délai minimal entre deux avertissements pour une même connexion (s). */
    public const NOTIFY_INTERVAL = 3600;

    /** Message ajouté au résultat d'un outil quand la connexion est périmée (destiné à l'assistant). */
    public const NOTICE = 'NOTICE FROM THE MCP SERVER: the tool catalogue of this site changed since this connection '
        . 'loaded it (%d tools now, %d when loaded). New or updated tools and parameters are NOT visible until the '
        . 'connector reloads its tool list. Please tell the user: in claude.ai, open the connector settings, click '
        . '"Disconnect" then "Connect" (no need to remove the connector). Then continue with the current request.' . "\n\n";

    /**
     * Message de RATTRAPAGE, envoyé une seule fois à une connexion établie avant
     * que le suivi existe : on ignore ce qu'elle a chargé, on lui demande donc une
     * reconnexion par précaution ; ensuite le suivi est exact.
     */
    public const NOTICE_FIRST = 'NOTICE FROM THE MCP SERVER: this site was updated and now tracks the tool list each '
        . 'connection has loaded. This connection was established before that, so the server cannot tell whether '
        . 'its tool list is current (%d tools are exposed now). Please tell the user, once: to be sure to have the '
        . 'latest tools and parameters, open the connector settings in claude.ai and click "Disconnect" then '
        . '"Connect" (no need to remove the connector). Then continue with the current request.' . "\n\n";

    /** Clé de connexion à partir du résultat de BearerAuthenticator. */
    public static function key(array $auth): string
    {
        $clientId = (string) ($auth['client_id'] ?? '');
        $uid      = (int) ($auth['user_id'] ?? 0);

        return $clientId !== '' ? 'oauth:' . $clientId . ':' . $uid : 'api:' . $uid;
    }

    /**
     * Empreinte du catalogue tel que ce registre l'expose (après scopes, politique
     * et ACL) : ['hash' => sha1, 'count' => n].
     */
    public static function fingerprint(ToolRegistry $registry): array
    {
        $list = $registry->list();

        return [
            'hash'  => sha1((string) json_encode($list, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)),
            'count' => \count($list),
        ];
    }

    /** Mémorise l'empreinte chargée par la connexion (tools/list), ou supposée (1er tools/call). */
    public static function recordListed(string $key, array $auth, array $fp, bool $assumed = false): void
    {
        try {
            $db  = self::db();
            $now = Factory::getDate()->toSql();

            $q = 'INSERT INTO ' . $db->quoteName('#__sensomcp_catalog')
                . ' (' . implode(',', $db->quoteName(['conn_key', 'user_id', 'client_id', 'scope', 'fingerprint', 'tool_count', 'listed', 'assumed', 'notified'])) . ')'
                . ' VALUES (:k, :u, :c, :s, :f, :n, :l, :a, NULL)'
                . ' ON DUPLICATE KEY UPDATE '
                . $db->quoteName('scope') . ' = VALUES(' . $db->quoteName('scope') . '), '
                . $db->quoteName('fingerprint') . ' = VALUES(' . $db->quoteName('fingerprint') . '), '
                . $db->quoteName('tool_count') . ' = VALUES(' . $db->quoteName('tool_count') . '), '
                . $db->quoteName('listed') . ' = VALUES(' . $db->quoteName('listed') . '), '
                . $db->quoteName('assumed') . ' = VALUES(' . $db->quoteName('assumed') . '), '
                . $db->quoteName('notified') . ' = NULL';

            $uid      = (int) ($auth['user_id'] ?? 0);
            $clientId = (string) ($auth['client_id'] ?? '');
            $scope    = implode(' ', (array) ($auth['scopes'] ?? []));
            $hash     = (string) $fp['hash'];
            $count    = (int) $fp['count'];
            $flag     = $assumed ? 1 : 0;

            $db->setQuery(
                $db->getQuery(true)->setQuery($q)
                ->bind(':k', $key, ParameterType::STRING)
                ->bind(':u', $uid, ParameterType::INTEGER)
                ->bind(':c', $clientId, ParameterType::STRING)
                ->bind(':s', $scope, ParameterType::STRING)
                ->bind(':f', $hash, ParameterType::STRING)
                ->bind(':n', $count, ParameterType::INTEGER)
                ->bind(':l', $now, ParameterType::STRING)
                ->bind(':a', $flag, ParameterType::INTEGER)
            )->execute();
        } catch (\Throwable) {
            // Le suivi ne doit jamais casser le service.
        }
    }

    /**
     * La connexion est-elle périmée ? Renvoie ['count' => n chargé, 'listed' => date]
     * si l'empreinte courante diffère de celle mémorisée, null sinon (ou si inconnue).
     */
    public static function staleInfo(string $key, array $fp): ?array
    {
        $row = self::row($key);

        if ($row === null || (string) $row->fingerprint === (string) $fp['hash']) {
            return null;
        }

        return ['count' => (int) $row->tool_count, 'listed' => (string) $row->listed, 'notified' => $row->notified];
    }

    /**
     * Avertissement à injecter dans un résultat d'outil, ou null. Enregistre la
     * connexion si elle est inconnue (empreinte supposée vue), et ne répète
     * l'avertissement qu'après NOTIFY_INTERVAL.
     */
    public static function staleNotice(string $key, array $auth, array $fp): ?string
    {
        $row = self::row($key);

        if ($row === null) {
            // Connexion antérieure au suivi : on mémorise le catalogue courant comme
            // « supposé vu » et on demande UNE reconnexion de rattrapage (marquée
            // notifiée pour ne pas la répéter ; ensuite seule une vraie différence
            // d'empreinte déclenche l'avis habituel).
            self::recordListed($key, $auth, $fp, true);
            self::markNotified($key);

            return \sprintf(self::NOTICE_FIRST, (int) $fp['count']);
        }

        if ((string) $row->fingerprint === (string) $fp['hash']) {
            return null;
        }

        if ($row->notified !== null && (time() - strtotime((string) $row->notified)) < self::NOTIFY_INTERVAL) {
            return null;
        }

        self::markNotified($key);

        return \sprintf(self::NOTICE, (int) $fp['count'], (int) $row->tool_count);
    }

    private static function markNotified(string $key): void
    {
        try {
            $db  = self::db();
            $now = Factory::getDate()->toSql();
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'UPDATE ' . $db->quoteName('#__sensomcp_catalog') . ' SET ' . $db->quoteName('notified') . ' = :n'
                    . ' WHERE ' . $db->quoteName('conn_key') . ' = :k'
                )->bind(':n', $now, ParameterType::STRING)->bind(':k', $key, ParameterType::STRING)
            )->execute();
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** Oublie une connexion (révocation, ou « ignorer » côté admin). */
    public static function forget(string $key): void
    {
        try {
            $db = self::db();
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'DELETE FROM ' . $db->quoteName('#__sensomcp_catalog') . ' WHERE ' . $db->quoteName('conn_key') . ' = :k'
                )->bind(':k', $key, ParameterType::STRING)
            )->execute();
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** Toutes les connexions suivies (tableau de bord). */
    public static function all(): array
    {
        try {
            $db = self::db();

            return $db->setQuery(
                'SELECT c.*, u.name AS user_name, u.username, k.client_name'
                . ' FROM ' . $db->quoteName('#__sensomcp_catalog') . ' c'
                . ' LEFT JOIN ' . $db->quoteName('#__users') . ' u ON u.id = c.user_id'
                . ' LEFT JOIN ' . $db->quoteName('#__sensomcp_oauth_clients') . ' k ON k.client_id = c.client_id'
                . ' ORDER BY c.listed DESC'
            )->loadObjectList() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function row(string $key): ?object
    {
        try {
            $db = self::db();

            return $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'SELECT * FROM ' . $db->quoteName('#__sensomcp_catalog') . ' WHERE ' . $db->quoteName('conn_key') . ' = :k'
                )->bind(':k', $key, ParameterType::STRING)
            )->loadObject() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
