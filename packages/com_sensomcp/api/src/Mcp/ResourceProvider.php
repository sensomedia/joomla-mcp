<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Mcp;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Table\Category;
use Joomla\CMS\User\User;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Fournisseur de ressources MCP : expose les articles publiés récents comme
 * ressources adressables `joomla://article/{id}` (lecture seule). Minimal, pour
 * la parité avec les serveurs MCP Joomla existants.
 */
final class ResourceProvider
{
    private const MAX = 50;

    /** Modèle Administrator de com_content (J5/J6), exécuté avec les droits du porteur du jeton. */
    private function contentModel(string $name)
    {
        $model = Factory::getApplication()->bootComponent('com_content')
            ->getMVCFactory()->createModel($name, 'Administrator', ['ignore_request' => true]);

        if ($this->user !== null && method_exists($model, 'setCurrentUser')) {
            $model->setCurrentUser($this->user);
        }

        return $model;
    }

    /** @return list<array{uri:string, name:string, description:string, mimeType:string}> */
    /**
     * @param User|null     $user   porteur du jeton (null = aucun filtrage, catalogue)
     * @param string[]|null $scopes scopes accordés (null = pas de filtrage)
     */
    public function __construct(private ?User $user = null, private ?array $scopes = null, private ?ToolRegistry $registry = null)
    {
    }

    /** Le porteur a-t-il le scope de lecture du contenu ? */
    private function canRead(): bool
    {
        return $this->scopes === null || \in_array('*', $this->scopes, true) || \in_array('content.read', $this->scopes, true);
    }

    /**
     * Article « visible par le porteur » : publié, dans ses dates de publication, catégorie publiée,
     * niveaux d'accès de l'article et de sa catégorie (porteur null = pas de contrôle d'accès).
     * Le modèle Articles du back-office ne filtre ni les dates ni l'état de la catégorie : contrôle ici.
     */
    private function isVisible(object $a, ?int $categoryPublished, ?int $categoryAccess): bool
    {
        $now = Factory::getDate()->toSql();

        if ((int) $a->state !== 1
            || (!empty($a->publish_up) && (string) $a->publish_up > $now)
            || (!empty($a->publish_down) && (string) $a->publish_down < $now)
            || ($categoryPublished !== null && $categoryPublished !== 1)) {
            return false;
        }

        if ($this->user === null) {
            return true;
        }

        $levels = array_map('intval', $this->user->getAuthorisedViewLevels() ?: [1]);

        return \in_array((int) $a->access, $levels, true)
            && ($categoryAccess === null || \in_array($categoryAccess, $levels, true));
    }

    public function list(): array
    {
        // Ressources déclarées par les plugins (scope + ACL contrôlés par le registre).
        $plugin = $this->registry?->resources() ?? [];

        if (!$this->canRead()) {
            return $plugin;
        }

        // Modèle Articles du back-office (publiés, niveaux d'accès de l'article et de la catégorie pour
        // le porteur), par pages, jusqu'à MAX articles visibles (dates / catégorie filtrées ici).
        $out   = [];
        $start = 0;
        $page  = 100;

        do {
            $model = $this->contentModel('Articles');
            $model->setState('filter.published', '1');
            $model->setState('list.ordering', 'a.created');
            $model->setState('list.direction', 'DESC');
            $model->setState('list.start', $start);
            $model->setState('list.limit', $page);
            $items = (array) $model->getItems();

            foreach ($items as $a) {
                $catPublished = isset($a->category_published) ? (int) $a->category_published : null;

                if (\count($out) < self::MAX && $this->isVisible($a, $catPublished, null)) {
                    $out[] = [
                        'uri'         => 'joomla://article/' . (int) $a->id,
                        'name'        => (string) $a->title,
                        'description' => 'Joomla article #' . (int) $a->id,
                        'mimeType'    => 'text/plain',
                    ];
                }
            }

            $start += $page;
        } while (\count($items) === $page && \count($out) < self::MAX && $start < 2000);

        return array_merge($plugin, $out);
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
        if ($this->registry?->hasResource($uri)) {
            return $this->registry->readResource($uri);
        }

        if (!preg_match('#^joomla://article/(\d+)$#', $uri, $m)) {
            throw new McpToolException('Unknown resource URI: ' . $uri);
        }

        if (!$this->canRead()) {
            throw new McpToolException('Insufficient scope: content.read is required to read resources.');
        }

        $id   = (int) $m[1];
        $item = $id > 0 ? $this->contentModel('Article')->getItem($id) : null;
        $row  = null;

        if ($item && !empty($item->id)) {
            $cat = new Category(Factory::getContainer()->get(DatabaseInterface::class));
            $has = (int) $item->catid > 0 && $cat->load((int) $item->catid);

            if ($this->isVisible($item, $has ? (int) $cat->published : null, $has ? (int) $cat->access : null)) {
                $row = ['title' => $item->title, 'introtext' => $item->introtext, 'fulltext' => $item->fulltext, 'metadesc' => $item->metadesc];
            }
        }

        if (!$row) {
            throw new McpToolException('Article not found, unpublished or not accessible: ' . $id);
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
