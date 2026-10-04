<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

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
    public function list(): array
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('created') . ' DESC');

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

        $id = (int) $m[1];
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['title', 'introtext', 'fulltext', 'metadesc']))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($q)->loadAssoc();

        if (!$row) {
            throw new \RuntimeException('Article not found or unpublished: ' . $id);
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
