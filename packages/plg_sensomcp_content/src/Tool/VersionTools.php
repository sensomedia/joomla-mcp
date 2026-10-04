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
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Outils MCP pour l'historique de contenu des articles (#__history) : lister,
 * lire, comparer, restaurer, conserver et supprimer des versions.
 * item_id Joomla = « com_content.article.<id> ».
 */
final class VersionTools
{
    private const TYPE_ALIAS = 'com_content.article';

    public function __construct(private User $user, private bool $allowWrite)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->declareScope('content.read', 'PLG_SENSOMCP_CONTENT_SCOPE_READ');

        $registry->register(
            'list_article_versions',
            'List the saved versions (history) of an article (version_id, date, editor, note, '
            . 'keep_forever, is_current).',
            [
                'type'       => 'object',
                'properties' => ['article_id' => ['type' => 'integer', 'description' => 'Article id.']],
                'required'   => ['article_id'],
            ],
            fn (array $a) => $this->listVersions((int) ($a['article_id'] ?? 0)),
            'content.read',
            ['acl' => ['core.edit', 'com_content']]
        );

        $registry->register(
            'get_article_version',
            'Get the stored snapshot of one article version (title, introtext, fulltext, state, etc.).',
            [
                'type'       => 'object',
                'properties' => ['version_id' => ['type' => 'integer', 'description' => 'Version id.']],
                'required'   => ['version_id'],
            ],
            fn (array $a) => $this->getVersion((int) ($a['version_id'] ?? 0)),
            'content.read',
            ['acl' => ['core.edit', 'com_content']]
        );

        $registry->register(
            'diff_article_versions',
            'Compare two article versions and return the fields that changed (title, alias, catid, state, '
            . 'language, metadesc, introtext, fulltext), with old and new values (long text truncated).',
            [
                'type'       => 'object',
                'properties' => [
                    'version_id_1' => ['type' => 'integer', 'description' => 'First (older) version id.'],
                    'version_id_2' => ['type' => 'integer', 'description' => 'Second (newer) version id.'],
                ],
                'required'   => ['version_id_1', 'version_id_2'],
            ],
            fn (array $a) => $this->diffVersions((int) ($a['version_id_1'] ?? 0), (int) ($a['version_id_2'] ?? 0)),
            'content.read',
            ['acl' => ['core.edit', 'com_content']]
        );

        if (!$this->allowWrite) {
            return;
        }

        $registry->declareScope('content.write', 'PLG_SENSOMCP_CONTENT_SCOPE_WRITE');

        $registry->register(
            'restore_article_version',
            'Restore an article to a previous version (its snapshot is saved back onto the article).',
            [
                'type'       => 'object',
                'properties' => ['version_id' => ['type' => 'integer', 'description' => 'Version id to restore.']],
                'required'   => ['version_id'],
            ],
            fn (array $a) => $this->restoreVersion((int) ($a['version_id'] ?? 0)),
            'content.write',
            ['acl' => ['core.edit', 'com_content']]
        );

        $registry->register(
            'keep_article_version',
            'Toggle the "keep forever" flag on an article version (protects it from history pruning).',
            [
                'type'       => 'object',
                'properties' => [
                    'version_id' => ['type' => 'integer', 'description' => 'Version id.'],
                    'keep'       => ['type' => 'boolean', 'description' => 'true to keep forever, false to unset.'],
                ],
                'required'   => ['version_id', 'keep'],
            ],
            fn (array $a) => $this->keepVersion((int) ($a['version_id'] ?? 0), (bool) ($a['keep'] ?? true)),
            'content.write',
            ['acl' => ['core.edit', 'com_content']]
        );

        $registry->register(
            'delete_article_version',
            'Delete an article version from the history.',
            [
                'type'       => 'object',
                'properties' => ['version_id' => ['type' => 'integer', 'description' => 'Version id.']],
                'required'   => ['version_id'],
            ],
            fn (array $a) => $this->deleteVersion((int) ($a['version_id'] ?? 0)),
            'content.write',
            ['acl' => ['core.edit', 'com_content']]
        );
    }

    // ---------------------------------------------------------------------

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function listVersions(int $articleId): array
    {
        if ($articleId <= 0) {
            throw new McpToolException("The 'article_id' parameter is required.");
        }

        $itemId = self::TYPE_ALIAS . '.' . $articleId;

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['version_id', 'version_note', 'save_date', 'editor_user_id', 'character_count', 'keep_forever', 'is_current']))
            ->from($db->quoteName('#__history'))
            ->where($db->quoteName('item_id') . ' = :item')
            ->bind(':item', $itemId, ParameterType::STRING)
            ->order($db->quoteName('save_date') . ' DESC');

        return ['article_id' => $articleId, 'versions' => $db->setQuery($q)->loadAssocList() ?: []];
    }

    private function getVersion(int $versionId): array
    {
        return ['version_id' => $versionId, 'data' => $this->versionData($versionId)];
    }

    private function diffVersions(int $v1, int $v2): array
    {
        $a = $this->versionData($v1);
        $b = $this->versionData($v2);

        $fields  = ['title', 'alias', 'catid', 'state', 'language', 'metadesc', 'introtext', 'fulltext'];
        $changed = [];

        foreach ($fields as $f) {
            $oldV = (string) ($a[$f] ?? '');
            $newV = (string) ($b[$f] ?? '');

            if ($oldV !== $newV) {
                $changed[] = [
                    'field' => $f,
                    'old'   => mb_substr($oldV, 0, 2000),
                    'new'   => mb_substr($newV, 0, 2000),
                ];
            }
        }

        return ['version_id_1' => $v1, 'version_id_2' => $v2, 'changed' => $changed];
    }

    private function restoreVersion(int $versionId): array
    {
        $data = $this->versionData($versionId);
        $id   = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            throw new McpToolException('Version has no article id.');
        }

        // On rejoue le snapshot via le modèle Administrator (déclenche events/workflow).
        $model = Factory::getApplication()->bootComponent('com_content')
            ->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);

        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');

        if (method_exists($model, 'getForm')) {
            $model->getForm($data, false);
        }

        if (!$model->save($data)) {
            $errors = method_exists($model, 'getErrors') ? $model->getErrors() : [$model->getError()];
            $errors = array_filter(array_map(static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e, $errors));

            throw new McpToolException('Restore failed: ' . ($errors ? implode(' | ', $errors) : 'unknown error'));
        }

        return ['ok' => true, 'article_id' => $id, 'restored_from_version' => $versionId];
    }

    private function keepVersion(int $versionId, bool $keep): array
    {
        $this->assertVersionExists($versionId);

        $db  = $this->db();
        $val = $keep ? 1 : 0;
        $q   = $db->getQuery(true)
            ->update($db->quoteName('#__history'))
            ->set($db->quoteName('keep_forever') . ' = :v')
            ->where($db->quoteName('version_id') . ' = :id')
            ->bind(':v', $val, ParameterType::INTEGER)
            ->bind(':id', $versionId, ParameterType::INTEGER);

        $db->setQuery($q)->execute();

        return ['ok' => true, 'version_id' => $versionId, 'keep_forever' => (bool) $keep];
    }

    private function deleteVersion(int $versionId): array
    {
        $this->assertVersionExists($versionId);

        $db = $this->db();
        $q  = $db->getQuery(true)
            ->delete($db->quoteName('#__history'))
            ->where($db->quoteName('version_id') . ' = :id')
            ->bind(':id', $versionId, ParameterType::INTEGER);

        $db->setQuery($q)->execute();

        return ['ok' => true, 'version_id' => $versionId, 'deleted' => true];
    }

    /** Snapshot décodé d'une version. */
    private function versionData(int $versionId): array
    {
        if ($versionId <= 0) {
            throw new McpToolException("A valid version_id is required.");
        }

        $db  = $this->db();
        $q   = $db->getQuery(true)
            ->select($db->quoteName('version_data'))
            ->from($db->quoteName('#__history'))
            ->where($db->quoteName('version_id') . ' = :id')
            ->bind(':id', $versionId, ParameterType::INTEGER);

        $raw = $db->setQuery($q)->loadResult();

        if ($raw === null) {
            throw new McpToolException('Version not found: ' . $versionId);
        }

        return (array) json_decode((string) $raw, true);
    }

    private function assertVersionExists(int $versionId): void
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName('version_id'))
            ->from($db->quoteName('#__history'))
            ->where($db->quoteName('version_id') . ' = :id')
            ->bind(':id', $versionId, ParameterType::INTEGER);

        if (!$db->setQuery($q, 0, 1)->loadResult()) {
            throw new McpToolException('Version not found: ' . $versionId);
        }
    }
}
