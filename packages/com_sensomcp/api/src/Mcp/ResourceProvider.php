<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Fournisseur de ressources MCP : expose les articles publiés récents comme
 * ressources adressables `joomla://article/{id}` (lecture seule). Minimal, pour
 * la parité avec les serveurs MCP Joomla existants.
 */
final class ResourceProvider
{
    private const MAX = 50;

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** @return list<array{uri:string, name:string, description:string, mimeType:string}> */
    /**
     * @param User|null     $user   porteur du jeton (null = aucun filtrage, catalogue)
     * @param string[]|null $scopes scopes accordés (null = pas de filtrage)
     */
    public function __construct(private ?User $user = null, private ?array $scopes = null)
    {
    }

    /** Le porteur a-t-il le scope de lecture du contenu ? */
    private function canRead(): bool
    {
        return $this->scopes === null || \in_array('*', $this->scopes, true) || \in_array('content.read', $this->scopes, true);
    }

    /** Filtre « article visible par le porteur » : publié, dates, catégorie publiée, niveau d'accès. */
    private function applyReadAcl($db, $q): void
    {
        $now = $db->quote(Factory::getDate()->toSql());

        $q->where($db->quoteName('a.state') . ' = 1')
            ->where('(' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= ' . $now . ')')
            ->where('(' . $db->quoteName('a.publish_down') . ' IS NULL OR ' . $db->quoteName('a.publish_down') . ' >= ' . $now . ')')
            ->join('LEFT', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
            ->where('(' . $db->quoteName('c.id') . ' IS NULL OR ' . $db->quoteName('c.published') . ' = 1)');

        if ($this->user !== null) {
            $levels = array_map('intval', $this->user->getAuthorisedViewLevels() ?: [1]);
            $q->whereIn($db->quoteName('a.access'), $levels);
        }
    }

    public function list(): array
    {
        if (!$this->canRead()) {
            return [];
        }

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.title']))
            ->from($db->quoteName('#__content', 'a'))
            ->order($db->quoteName('a.created') . ' DESC');
        $this->applyReadAcl($db, $q);

        $out = [];

        foreach ($db->setQuery($q, 0, self::MAX)->loadAssocList() ?: [] as $r) {
            $out[] = [
                'uri'         => 'joomla://article/' . (int) $r['id'],
                'name'        => (string) $r['title'],
                'description' => 'Joomla article #' . (int) $r['id'],
                'mimeType'    => 'text/plain',
            ];
        }

        return $out;
    }

    /** @return list<array{uriTemplate:string, name:string, description:string, mimeType:string}> */
    public function templates(): array
    {
        return [[
            'uriTemplate' => 'joomla://article/{id}',
            'name'        => 'Joomla article',
            'description' => 'A published article by its id.',
            'mimeType'    => 'text/plain',
        ]];
    }

    /**
     * Lit une ressource par URI.
     *
     * @return array{contents: list<array{uri:string, mimeType:string, text:string}>}
     */
    public function read(string $uri): array
    {
        if (!preg_match('#^joomla://article/(\d+)$#', $uri, $m)) {
            throw new \RuntimeException('Unknown resource URI: ' . $uri);
        }

        if (!$this->canRead()) {
            throw new \RuntimeException('Insufficient scope: content.read is required to read resources.');
        }

        $id = (int) $m[1];
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['a.title', 'a.introtext', 'a.fulltext', 'a.metadesc']))
            ->from($db->quoteName('#__content', 'a'))
            ->where($db->quoteName('a.id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->applyReadAcl($db, $q);
        $row = $db->setQuery($q)->loadAssoc();

        if (!$row) {
            throw new \RuntimeException('Article not found, unpublished or not accessible: ' . $id);
        }

        $html = trim((string) $row['introtext'] . "\n\n" . (string) $row['fulltext']);
        $text = trim(preg_replace('/\n{3,}/', "\n\n", strip_tags($html)));

        $body = (string) $row['title'] . "\n\n";

        if (trim((string) $row['metadesc']) !== '') {
            $body .= (string) $row['metadesc'] . "\n\n";
        }

        $body .= $text;

        return [
            'contents' => [[
                'uri'      => $uri,
                'mimeType' => 'text/plain',
                'text'     => $body,
            ]],
        ];
    }
}
