<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;
use Sensomedia\Plugin\Sensomcp\Content\Support\ResolvesPublicUrls;

\defined('_JEXEC') or die;

/**
 * Outils MCP pour les catégories com_content (context com_content.article).
 * Écritures via le modèle Administrator de com_categories (gère le nested set).
 */
final class CategoryTools
{
    use ResolvesPublicUrls;

    private const EXTENSION = 'com_content';

    public function __construct(private User $user, private bool $allowWrite)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->declareScope('content.read', 'PLG_SENSOMCP_CONTENT_SCOPE_READ');

        $registry->register(
            'list_categories',
            'List published com_content categories (id, title, level, path). '
            . 'Useful to find the catid before creating an article.',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->listCategories(),
            'content.read'
        );

        $registry->register(
            'get_category',
            'Get a com_content category by id (title, alias, parent, path, state, description, language).',
            [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'Category id.']],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->getCategory((int) ($a['id'] ?? 0)),
            'content.read'
        );

        if (!$this->allowWrite) {
            return;
        }

        $registry->declareScope('content.write', 'PLG_SENSOMCP_CONTENT_SCOPE_WRITE');

        $registry->register(
            'create_category',
            'Create a com_content category. parent_id defaults to 1 (root). state: 0 unpublished, 1 published.',
            [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string', 'description' => 'Category title.'],
                    'parent_id'   => ['type' => 'integer', 'description' => 'Parent category id (default 1 = root).'],
                    'alias'       => ['type' => 'string', 'description' => 'Alias (auto-generated if empty).'],
                    'description' => ['type' => 'string', 'description' => 'Category description (HTML).'],
                    'state'       => ['type' => 'integer', 'enum' => [0, 1], 'description' => '0 unpublished, 1 published.'],
                    'language'    => ['type' => 'string', 'description' => 'Language code or * (default *).'],
                    'metadesc'        => ['type' => 'string', 'description' => 'Meta description (SEO).'],
                    'image'           => ['type' => 'string', 'description' => 'Category image: media path or URL.'],
                    'image_alt'       => ['type' => 'string', 'description' => 'Category image alt text.'],
                    'image_alt_empty' => ['type' => 'boolean', 'description' => 'Mark the alt text as intentionally empty (decorative image).'],
                ],
                'required'   => ['title'],
            ],
            fn (array $a) => $this->createCategory($a),
            'content.write',
            ['acl' => ['core.create', 'com_content']]
        );

        $registry->register(
            'update_category',
            'Update an existing com_content category. Only provided fields are changed.',
            [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer', 'description' => 'Category id.'],
                    'title'       => ['type' => 'string'],
                    'parent_id'   => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'state'       => ['type' => 'integer', 'enum' => [0, 1]],
                    'language'    => ['type' => 'string'],
                    'metadesc'        => ['type' => 'string', 'description' => 'Meta description (SEO).'],
                    'image'           => ['type' => 'string', 'description' => 'Category image: media path or URL. Empty string removes it.'],
                    'image_alt'       => ['type' => 'string', 'description' => 'Category image alt text.'],
                    'image_alt_empty' => ['type' => 'boolean', 'description' => 'Mark the alt text as intentionally empty (decorative image).'],
                ],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->updateCategory($a),
            'content.write',
            ['acl' => ['core.edit', 'com_content']]
        );

        $registry->register(
            'delete_category',
            'Delete one or more com_content categories. Trashed first, then removed. '
            . 'A category that still contains items or subcategories cannot be deleted.',
            [
                'type'       => 'object',
                'properties' => [
                    'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids.'],
                    'permanent' => ['type' => 'boolean', 'description' => 'true = permanent deletion; false (default) = trash.'],
                ],
                'required'   => ['ids'],
            ],
            fn (array $a) => $this->deleteCategory($a),
            'content.write',
            ['acl' => ['core.delete', 'com_content']]
        );
    }

    // ---------------------------------------------------------------------

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function model()
    {
        // CategoryModel::publish() lit l'extension depuis l'input de la requête
        // (absent en contexte API) → on l'injecte, sinon l'événement
        // onCategoryChangeState reçoit un context null (TypeError).
        Factory::getApplication()->getInput()->set('extension', self::EXTENSION);

        $model = Factory::getApplication()->bootComponent('com_categories')
            ->getMVCFactory()->createModel('Category', 'Administrator', ['ignore_request' => true]);

        $model->setState('category.extension', self::EXTENSION);
        $model->setState('category.type', 'other');

        return $model;
    }

    /** Enregistre les chemins de formulaires (comme pour les articles). */
    private function primeForm($model, array $data): void
    {
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_categories/forms');

        if (method_exists($model, 'getForm')) {
            $model->getForm($data + ['extension' => self::EXTENSION], false);
        }
    }

    /**
     * Restreint une requête #__categories aux catégories visibles par l'utilisateur
     * du jeton : niveaux d'accès autorisés + publiées seulement sauf éditeur. §5 ACL.
     */
    private function applyReadAcl($db, $q): void
    {
        $levels = $this->user->getAuthorisedViewLevels() ?: [1];
        $q->whereIn($db->quoteName('access'), array_map('intval', $levels));

        if (!$this->user->authorise('core.edit', 'com_content')
            && !$this->user->authorise('core.edit.state', 'com_content')) {
            $q->where($db->quoteName('published') . ' = 1');
        }
    }

    private function listCategories(): array
    {
        $db    = $this->db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'level', 'path', 'language', 'parent_id']))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote(self::EXTENSION))
            ->order($db->quoteName('lft') . ' ASC');
        $this->applyReadAcl($db, $query);

        return ['categories' => $db->setQuery($query)->loadAssocList()];
    }

    private function getCategory(int $id): array
    {
        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $db  = $this->db();
        $q   = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'alias', 'parent_id', 'level', 'path', 'published', 'language', 'description']))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('extension') . ' = ' . $db->quote(self::EXTENSION))
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->applyReadAcl($db, $q);

        $row = $db->setQuery($q)->loadAssoc();

        if (!$row) {
            throw new McpToolException('Category not found: ' . $id);
        }

        return $this->withCategoryUrls($row, $id);
    }

    /**
     * Injecte `url` (+ `urls` multilingue) pour une catégorie com_content, via le
     * trait partagé {@see ResolvesPublicUrls}.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withCategoryUrls(array $result, int $id): array
    {
        try {
            $db  = $this->db();
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'alias', 'language']))
                    ->from($db->quoteName('#__categories'))
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
        $base = 'index.php?option=com_content&view=category&id=' . $slug;

        return $this->withPublicUrls($result, $base, (string) $row->language);
    }

    private function createCategory(array $a): array
    {
        $title = trim((string) ($a['title'] ?? ''));

        if ($title === '') {
            throw new McpToolException("The 'title' parameter is required.");
        }

        $data = [
            'id'          => 0,
            'extension'   => self::EXTENSION,
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

        if (\array_key_exists('metadesc', $a)) {
            $data['metadesc'] = (string) $a['metadesc'];
        }

        if (\array_key_exists('image', $a) || \array_key_exists('image_alt', $a)
            || \array_key_exists('image_alt_empty', $a)) {
            $params = new Registry();
            $params->set('image', (string) ($a['image'] ?? ''));
            $params->set('image_alt', (string) ($a['image_alt'] ?? ''));

            if (\array_key_exists('image_alt_empty', $a)) {
                $params->set('image_alt_empty', $a['image_alt_empty'] ? '1' : '0');
            }

            $data['params'] = $params->toArray();
        }

        $model = $this->model();
        $this->primeForm($model, $data);

        if (!$model->save($data)) {
            throw new McpToolException('Create failed: ' . $this->modelErrors($model));
        }

        $catid = (int) $model->getState('category.id');

        return $this->withCategoryUrls(['ok' => true, 'id' => $catid, 'title' => $title], $catid);
    }

    private function updateCategory(array $a): array
    {
        $id = (int) ($a['id'] ?? 0);

        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $model   = $this->model();
        $current = $model->getItem($id);

        if (!$current || !$current->id) {
            throw new McpToolException('Category not found: ' . $id);
        }

        $data = [
            'id'          => $id,
            'extension'   => self::EXTENSION,
            'title'       => (string) ($a['title'] ?? $current->title),
            'parent_id'   => (int) ($a['parent_id'] ?? $current->parent_id),
            'description' => (string) ($a['description'] ?? $current->description),
            'published'   => \array_key_exists('state', $a) ? (int) $a['state'] : (int) $current->published,
            'language'    => (string) ($a['language'] ?? $current->language),
        ];

        // SEO : meta description (colonne #__categories.metadesc).
        if (\array_key_exists('metadesc', $a)) {
            $data['metadesc'] = (string) $a['metadesc'];
        }

        // Image de catégorie : params.image / image_alt / image_alt_empty
        // (préserve les autres paramètres existants).
        if (\array_key_exists('image', $a) || \array_key_exists('image_alt', $a)
            || \array_key_exists('image_alt_empty', $a)) {
            $params = new Registry($current->params ?? []);

            if (\array_key_exists('image', $a)) {
                $params->set('image', (string) $a['image']);
            }

            if (\array_key_exists('image_alt', $a)) {
                $params->set('image_alt', (string) $a['image_alt']);
            }

            if (\array_key_exists('image_alt_empty', $a)) {
                $params->set('image_alt_empty', $a['image_alt_empty'] ? '1' : '0');
            }

            $data['params'] = $params->toArray();
        }

        $this->primeForm($model, $data);

        if (!$model->save($data)) {
            throw new McpToolException('Update failed: ' . $this->modelErrors($model));
        }

        return $this->withCategoryUrls(['ok' => true, 'id' => $id], $id);
    }

    private function deleteCategory(array $a): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($a['ids'] ?? []))));

        if (!$ids) {
            throw new McpToolException("The 'ids' parameter is required (non-empty list).");
        }

        $permanent = (bool) ($a['permanent'] ?? false);
        $model     = $this->model();

        // publish() ET delete() reçoivent $pks PAR RÉFÉRENCE et en retirent
        // (unset) les items déjà dans l'état cible ou refusés par l'ACL, tout
        // en renvoyant true. On leur passe donc des COPIES pour préserver $ids,
        // puis on vérifie l'état réel en base plutôt que la valeur de retour.
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
                'Could not permanently delete category(ies) ' . implode(', ', $remaining)
                . '. A category can only be deleted once it is trashed, empty (no articles) and has no '
                . 'subcategories, and requires the core.delete permission.'
                . ($deleted ? ' Deleted: ' . implode(', ', $deleted) . '.' : '')
            );
        }

        return ['ok' => true, 'ids' => $deleted, 'deleted' => true];
    }

    /** Sous-ensemble des ids encore présents en base (extension com_content). */
    private function existingIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__categories'))
            ->whereIn($db->quoteName('id'), $ids)
            ->where($db->quoteName('extension') . ' = ' . $db->quote(self::EXTENSION));

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
