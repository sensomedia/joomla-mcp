<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\UserFactoryInterface;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\CatalogState;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolCollector;

\defined('_JEXEC') or die;

/**
 * Statistiques et journal des requêtes MCP pour le tableau de bord.
 */
class DashboardModel extends BaseDatabaseModel
{
    private function q()
    {
        return $this->getDatabase()->getQuery(true);
    }

    /** Chiffres clés. */
    public function getStats(): array
    {
        $db  = $this->getDatabase();
        $log = $db->quoteName('#__sensomcp_log');

        $total = (int) $db->setQuery("SELECT COUNT(*) FROM $log")->loadResult();

        $errors = (int) $db->setQuery("SELECT COUNT(*) FROM $log WHERE " . $db->quoteName('success') . " = 0")->loadResult();

        $authFails = (int) $db->setQuery(
            "SELECT COUNT(*) FROM $log WHERE " . $db->quoteName('method') . " = " . $db->quote('(auth)')
        )->loadResult();

        $since = Factory::getDate('-7 days')->toSql();
        $last7 = (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from('#__sensomcp_log')
                ->where($db->quoteName('created') . ' >= ' . $db->quote($since))
        )->loadResult();

        $clients = (int) $db->setQuery("SELECT COUNT(*) FROM " . $db->quoteName('#__sensomcp_oauth_clients'))->loadResult();

        $tokens = (int) $db->setQuery(
            "SELECT COUNT(*) FROM " . $db->quoteName('#__sensomcp_oauth_refresh')
            . " WHERE " . $db->quoteName('revoked') . " = 0 AND " . $db->quoteName('expires') . " > " . $db->quote(Factory::getDate()->toSql())
        )->loadResult();

        return [
            'total'         => $total,
            'last7'         => $last7,
            'errors'        => $errors,
            'error_rate'    => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
            'auth_failures' => $authFails,
            'clients'       => $clients,
            'active_tokens' => $tokens,
        ];
    }

    /**
     * Connexions MCP dont la liste d'outils chargée ne correspond plus au
     * catalogue courant (outils ajoutés/modifiés depuis) : l'utilisateur doit
     * déconnecter/reconnecter son connecteur. Recalcule l'empreinte avec les
     * droits et scopes de chaque connexion (même registre que le serveur).
     */
    public function getStaleConnections(): array
    {
        $out     = [];
        $factory = Factory::getContainer()->get(UserFactoryInterface::class);
        $cache   = [];

        foreach (CatalogState::all() as $row) {
            $user = $factory->loadUserById((int) $row->user_id);

            if (!$user || !$user->id) {
                continue;
            }

            $scopes   = array_values(array_filter(explode(' ', (string) $row->scope)));
            $cacheKey = $row->user_id . '|' . implode(' ', $scopes);

            if (!isset($cache[$cacheKey])) {
                try {
                    $cache[$cacheKey] = CatalogState::fingerprint(ToolCollector::collectFor($user, $scopes));
                } catch (\Throwable) {
                    $cache[$cacheKey] = null;
                }
            }

            $current = $cache[$cacheKey];

            if ($current === null || $current['hash'] === (string) $row->fingerprint) {
                continue;
            }

            $out[] = [
                'key'         => (string) $row->conn_key,
                'is_oauth'    => (string) $row->client_id !== '',
                'user'        => (string) ($row->user_name ?: $row->username ?: $row->user_id),
                'client'      => (string) ($row->client_name ?: ($row->client_id !== '' ? $row->client_id : '')),
                'listed'      => (string) $row->listed,
                'assumed'     => (bool) $row->assumed,
                'tools_then'  => (int) $row->tool_count,
                'tools_now'   => (int) $current['count'],
                'notified'    => $row->notified,
            ];
        }

        return $out;
    }

    /** Requêtes par jour sur N jours (pour le graphe). @return array<string,int> */
    public function getPerDay(int $days = 14): array
    {
        $db    = $this->getDatabase();
        $since = Factory::getDate('-' . ($days - 1) . ' days')->format('Y-m-d') . ' 00:00:00';

        $query = $db->getQuery(true)
            ->select('DATE(' . $db->quoteName('created') . ') AS d, COUNT(*) AS c')
            ->from($db->quoteName('#__sensomcp_log'))
            ->where($db->quoteName('created') . ' >= ' . $db->quote($since))
            ->group('DATE(' . $db->quoteName('created') . ')');

        $rows = $db->setQuery($query)->loadAssocList('d', 'c') ?: [];

        // Remplir les jours vides.
        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day       = Factory::getDate('-' . $i . ' days')->format('Y-m-d');
            $out[$day] = (int) ($rows[$day] ?? 0);
        }

        return $out;
    }

    /** @return array<string,int> */
    public function getTopTools(int $limit = 8): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('tool') . ', COUNT(*) AS c')
            ->from($db->quoteName('#__sensomcp_log'))
            ->where($db->quoteName('tool') . ' IS NOT NULL')
            ->group($db->quoteName('tool'))
            ->order('c DESC');

        return $db->setQuery($query, 0, $limit)->loadAssocList('tool', 'c') ?: [];
    }

    /** @return array<string,int> */
    public function getTopMethods(int $limit = 8): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('method') . ', COUNT(*) AS c')
            ->from($db->quoteName('#__sensomcp_log'))
            ->group($db->quoteName('method'))
            ->order('c DESC');

        return $db->setQuery($query, 0, $limit)->loadAssocList('method', 'c') ?: [];
    }

    /** Dernières requêtes. */
    public function getRecent(int $limit = 30): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['l.created', 'l.user_id', 'l.ip', 'l.method', 'l.tool', 'l.success', 'l.error']))
            ->select($db->quoteName('u.username'))
            ->from($db->quoteName('#__sensomcp_log', 'l'))
            ->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = l.user_id')
            ->order($db->quoteName('l.id') . ' DESC');

        return $db->setQuery($query, 0, $limit)->loadObjectList() ?: [];
    }
}
