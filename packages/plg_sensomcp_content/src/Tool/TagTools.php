<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;
use Sensomedia\Plugin\Sensomcp\Content\Support\ResolvesPublicUrls;

\defined('_JEXEC') or die;

/**
 * Outils MCP pour les tags (com_tags). Écritures via le modèle Administrator
 * de com_tags (nested set géré par le modèle).
 */
final class TagTools
{
    use ResolvesPublicUrls;

    public function __construct(private bool $allowWrite)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->declareScope('content.read', 'PLG_SENSOMCP_CONTENT_SCOPE_READ');

        $registry->register(
            'list_tags',
            'List published tags (id, title, level, path).',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Optional text to filter titles.'],
                ],
            ],
            fn (array $a) => $this->listTags((string) ($a['query'] ?? '')),
            'content.read'
        );

        $registry->register(
            'get_tag',
            'Get a tag by id (title, alias, parent, path, state, description, language).',
            [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'Tag id.']],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->getTag((int) ($a['id'] ?? 0)),
            'content.read'
        );

        if (!$this->allowWrite) {
            return;
        }

        $registry->declareScope('content.write', 'PLG_SENSOMCP_CONTENT_SCOPE_WRITE');

        $registry->register(
            'create_tag',
            'Create a tag. parent_id defaults to 1 (root). state: 0 unpublished, 1 published.',
            [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Tag title.'],
                    'parent_id'   => ['type' => 'integer', 'description' => 'Parent tag id (default 1 = root).'],
                    'alias'       => ['type' => 'string', 'description' => 'Alias (auto-generated if empty).'],
                    'description' => ['type' => 'string', 'description' => 'Tag description (HTML).'],
                    'state'       => ['type' => 'integer', 'enum' => [0, 1], 'description' => '0 unpublished, 1 published.'],
                    'language'    => ['type' => 'string', 'description' => 'Language code or * (default *).'],
                ],
                'required'   => ['title'],
            ],
            fn (array $a) => $this->createTag($a),
            'content.write',
            ['acl' => ['core.create', 'com_tags']]
        );

        $registry->register(
            'update_tag',
            'Update an existing tag. Only provided fields are changed.',
            [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer', 'description' => 'Tag id.'],
                    'title'       => ['type' => 'string'],
                    'parent_id'   => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'state'       => ['type' => 'integer', 'enum' => [0, 1]],
                    'language'    => ['type' => 'string'],
                ],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->updateTag($a),
            'content.write',
            ['acl' => ['core.edit', 'com_tags']]
        );

        $registry->register(
            'delete_tag',
            'Delete one or more tags. Trashed first, then removed.',
            [
                'type'       => 'object',
                'properties' => [
                    'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids.'],
                    'permanent' => ['type' => 'boolean', 'description' => 'true = permanent deletion; false (default) = trash.'],
                ],
                'required'   => ['ids'],
            ],
            fn (array $a) => $this->deleteTag($a),
            'content.write',
            ['acl' => ['core.delete', 'com_tags']]
        );
    }

    // ---------------------------------------------------------------------

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function model()
    {
        return Factory::getApplication()->bootComponent('com_tags')
            ->getMVCFactory()->createModel('Tag', 'Administrator', ['ignore_request' => true]);
    }

    private function form($model, array $data): Form
    {
        FormGate::useAdminForms('com_tags');

        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the tag form: ' . $this->modelErrors($model));
        }

        return $form;
    }

    private function listTags(string $query): array
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'level', 'path', 'language']))
            ->from($db->quoteName('#__tags'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('id') . ' > 1')
            ->order($db->quoteName('lft') . ' ASC');

        if ($query !== '') {
            $q->where($db->quoteName('title') . ' LIKE :search')
                ->bind(':search', $search, ParameterType::STRING);
            $search = '%' . $query . '%';
        }

        return ['tags' => $db->setQuery($q, 0, 200)->loadAssocList()];
    }

    private function getTag(int $id): array
    {
        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'alias', 'parent_id', 'level', 'path', 'published', 'language', 'description']))
            ->from($db->quoteName('#__tags'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($q)->loadAssoc();

        if (!$row) {
            throw new McpToolException('Tag not found: ' . $id);
        }

        return $this->withTagUrls($row, $id);
    }

    /**
     * Injecte `url` (+ `urls` multilingue) pour un tag (com_tags), via le trait
     * partagé {@see ResolvesPublicUrls}.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withTagUrls(array $result, int $id): array
    {
        try {
            $db  = $this->db();
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'alias', 'language']))
                    ->from($db->quoteName('#__tags'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $id, ParameterType::INTEGER)
            )->loadObject();
        } catch (\Throwable) {
            $row = null;
        }

        if (!$row) {
            $result['url'] = '';

            return $result;
        }

        $slug = $row->id . (($row->alias ?? '') !== '' ? ':' . $row->alias : '');
        $base = 'index.php?option=com_tags&view=tag&id[0]=' . $slug;

        return $this->withPublicUrls($result, $base, (string) $row->language);
    }

    private function createTag(array $a): array
    {
        $title = trim((string) ($a['title'] ?? ''));

        if ($title === '') {
            throw new McpToolException("The 'title' parameter is required.");
        }

        $data = [
            'id'          => 0,
            'title'       => $title,
            'alias'       => (string) ($a['alias'] ?? ''),
            'parent_id'   => (int) ($a['parent_id'] ?? 1) ?: 1,
            'description' => (string) ($a['description'] ?? ''),
            'published'   => \array_key_exists('state', $a) ? (int) $a['state'] : 1,
            'language'    => (string) ($a['language'] ?? '*'),
            'access'      => (int) Factory::getApplication()->get('access', 1),
            'params'      => [],
            'metadata'    => [],
        ];

        $model = $this->model();
        $gate  = FormGate::apply($this->form($model, $data), $data, [], ['id']);
        $data  = $gate['data'];

        if (!$model->save($data)) {
            throw new McpToolException('Create failed: ' . $this->modelErrors($model));
        }

        $tid    = (int) $model->getState('tag.id');
        $result = $this->withTagUrls(['ok' => true, 'id' => $tid, 'title' => $title], $tid);

        return $gate['ignored'] ? $result + ['ignored' => $gate['ignored']] : $result;
    }

    private function updateTag(array $a): array
    {
        $id = (int) ($a['id'] ?? 0);

        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $model   = $this->model();
        $current = $model->getItem($id);

        if (!$current || !$current->id) {
            throw new McpToolException('Tag not found: ' . $id);
        }

        $data = [
            'id'          => $id,
            'title'       => (string) ($a['title'] ?? $current->title),
            'parent_id'   => (int) ($a['parent_id'] ?? $current->parent_id),
            'description' => (string) ($a['description'] ?? $current->description),
            'published'   => \array_key_exists('state', $a) ? (int) $a['state'] : (int) $current->published,
            'language'    => (string) ($a['language'] ?? $current->language),
        ];

        $input = $data;

        foreach (['title', 'parent_id', 'description', 'published', 'language'] as $k) {
            if (!\array_key_exists($k === 'published' ? 'state' : $k, $a)) {
                unset($input[$k]);
            }
        }

        $gate = FormGate::apply($this->form($model, $data), $input, FormGate::itemData($current), ['id']);
        $data = $gate['data'];

        if (!$model->save($data)) {
            throw new McpToolException('Update failed: ' . $this->modelErrors($model));
        }

        $result = $this->withTagUrls(['ok' => true, 'id' => $id], $id);

        return $gate['ignored'] ? $result + ['ignored' => $gate['ignored']] : $result;
    }

    private function deleteTag(array $a): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($a['ids'] ?? []))));

        if (!$ids) {
            throw new McpToolException("The 'ids' parameter is required (non-empty list).");
        }

        $permanent = (bool) ($a['permanent'] ?? false);
        $model     = $this->model();

        // publish() ET delete() reçoivent $pks PAR RÉFÉRENCE et en retirent
        // (unset) les items déjà dans l'état cible ou refusés → copies obligatoires
        // pour préserver $ids, puis vérification de l'état réel en base.
        $trash = $ids;

        if (!$model->publish($trash, -2)) {
            throw new McpToolException('Trashing failed: ' . $this->modelErrors($model));
        }

        if (!$permanent) {
            return ['ok' => true, 'ids' => $ids, 'trashed' => true];
        }

        $pks = $ids;
        $model->delete($pks);

        $remaining = $this->existingIds($ids);
        $deleted   = array_values(array_diff($ids, $remaining));

        if ($remaining) {
            throw new McpToolException(
                'Could not permanently delete tag(s) ' . implode(', ', $remaining)
                . '. A tag can only be deleted once it is trashed and has no child tags, '
                . 'and requires the core.delete permission.'
                . ($deleted ? ' Deleted: ' . implode(', ', $deleted) . '.' : '')
            );
        }

        return ['ok' => true, 'ids' => $deleted, 'deleted' => true];
    }

    /** Sous-ensemble des ids de tags encore présents en base. */
    private function existingIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__tags'))
            ->whereIn($db->quoteName('id'), $ids);

        return array_map('intval', $db->setQuery($q)->loadColumn());
    }

    private function modelErrors($model): string
    {
        $errors = method_exists($model, 'getErrors') ? $model->getErrors() : [];

        if (!$errors && method_exists($model, 'getError')) {
            $errors = [$model->getError()];
        }

        $errors = array_filter(array_map(
            static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
            $errors
        ));

        return $errors ? implode(' | ', $errors) : 'unknown error';
    }
}
