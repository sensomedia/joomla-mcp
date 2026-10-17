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
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\UrlGuard;

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
            ['acl' => $this->aclArticle()]
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
            ['acl' => $this->aclVersion()]
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
            ['acl' => $this->aclVersion()]
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
            ['acl' => $this->aclVersion()]
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
            ['acl' => $this->aclVersion()]
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
            ['acl' => $this->aclVersion()]
        );
    }

    // ---------------------------------------------------------------------

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** ACL : l'outil cible un article (`article_id`) → core.edit, ou core.edit.own pour l'auteur. */
    private function aclArticle(): \Closure
    {
        return function (User $me, array $a): bool {
            $id = (int) ($a['article_id'] ?? 0);

            if ($id <= 0) {
                return $me->authorise('core.edit', 'com_content') || $me->authorise('core.edit.own', 'com_content');
            }

            return $this->canEditArticle($me, $id);
        };
    }

    /** ACL : l'outil cible une version (`version_id`) → même règle sur l'article auquel elle appartient. */
    private function aclVersion(): \Closure
    {
        return function (User $me, array $a): bool {
            $vid = (int) ($a['version_id'] ?? $a['version_id_1'] ?? 0);

            if ($vid <= 0) {
                return $me->authorise('core.edit', 'com_content') || $me->authorise('core.edit.own', 'com_content');
            }

            $ok = $this->canEditArticle($me, $this->articleOfVersion($vid));

            if ($ok && isset($a['version_id_2'])) {
                $ok = $this->canEditArticle($me, $this->articleOfVersion((int) $a['version_id_2']));
            }

            return $ok;
        };
    }

    private function canEditArticle(User $me, int $articleId): bool
    {
        $db = $this->db();
        $by = (int) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('created_by'))->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = :id')->bind(':id', $articleId, ParameterType::INTEGER)
        )->loadResult();

        return FormGate::canEdit($me, 'com_content.article.' . $articleId, $by);
    }

    /**
     * Article auquel appartient une version : la table d'historique est partagée par tous les
     * composants, on n'accepte que `com_content.article.<id>` (audit M5). Lève si la version
     * n'existe pas ou n'est pas celle d'un article.
     */
    private function articleOfVersion(int $versionId): int
    {
        if ($versionId <= 0) {
            throw new McpToolException('A valid version_id is required.');
        }

        $db     = $this->db();
        $itemId = (string) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('item_id'))->from($db->quoteName('#__history'))
                ->where($db->quoteName('version_id') . ' = :id')->bind(':id', $versionId, ParameterType::INTEGER)
        )->loadResult();

        if ($itemId === '') {
            throw new McpToolException('Version not found: ' . $versionId);
        }

        if (!preg_match('/^' . preg_quote(self::TYPE_ALIAS, '/') . '\.(\d+)$/', $itemId, $m)) {
            throw new McpToolException('Version ' . $versionId . ' is not an article version.');
        }

        return (int) $m[1];
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
        $articleId = $this->articleOfVersion($versionId);

        return ['version_id' => $versionId, 'article_id' => $articleId, 'data' => $this->versionData($versionId)];
    }

    private function diffVersions(int $v1, int $v2): array
    {
        if ($this->articleOfVersion($v1) !== $this->articleOfVersion($v2)) {
            throw new McpToolException('Both versions must belong to the same article.');
        }

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
        $articleId = $this->articleOfVersion($versionId);
        $data      = $this->versionData($versionId);
        $id        = (int) ($data['id'] ?? 0);

        if ($id !== $articleId) {
            throw new McpToolException('Version ' . $versionId . ' does not match its article (' . $id . ' ≠ ' . $articleId . ').');
        }

        // On rejoue le snapshot via le modèle Administrator (déclenche events/workflow), en
        // passant par le formulaire d'article : filtres de texte, état/dates réservés à
        // core.edit.state, created_by à core.manage com_users — comme une modification (audit M5).
        $model = Factory::getApplication()->bootComponent('com_content')
            ->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);
        $current = $model->getItem($id);

        if (!$current || !$current->id) {
            throw new McpToolException('Article not found: ' . $id);
        }

        FormGate::useAdminForms('com_content');
        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the article form.');
        }

        $db   = $this->db();
        $tags = array_map('strval', $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('tag_id'))->from($db->quoteName('#__contentitem_tag_map'))
                ->where($db->quoteName('type_alias') . ' = ' . $db->quote(self::TYPE_ALIAS))
                ->where($db->quoteName('content_item_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadColumn() ?: []);

        // Les liens A/B/C (`urls`) sont restaurés hors formulaire, comme update_article les
        // écrit (liens relatifs permis), avec le même nettoyage de schéma.
        $snapshot = array_intersect_key($data, array_flip([
            'id', 'title', 'alias', 'introtext', 'fulltext', 'catid', 'state', 'language', 'metadesc', 'metakey',
            'images', 'attribs', 'metadata', 'access', 'publish_up', 'publish_down', 'featured', 'created', 'created_by', 'created_by_alias', 'note',
        ]));
        $snapshot['tags'] = $tags;
        $gate    = FormGate::apply($form, $snapshot, FormGate::itemData($current), ['id', 'tags'], null, ['created', 'publish_up', 'publish_down']);
        $data    = $gate['data'];
        $ignored = $gate['ignored'];
        unset($data['urls']);

        if (!$model->save($data)) {
            $errors = method_exists($model, 'getErrors') ? $model->getErrors() : [$model->getError()];
            $errors = array_filter(array_map(static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e, $errors));

            throw new McpToolException('Restore failed: ' . ($errors ? implode(' | ', $errors) : 'unknown error'));
        }

        $raw  = $this->versionData($versionId)['urls'] ?? null;
        $raw  = \is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;

        if ($raw !== []) {
            foreach (['a', 'b', 'c'] as $k) {
                if (isset($raw['url' . $k]) && (string) $raw['url' . $k] !== '') {
                    $raw['url' . $k] = UrlGuard::cleanEditorialLink((string) $raw['url' . $k], 'link ' . $k);
                }
            }

            $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->setQuery(
                $db->getQuery(true)->update($db->quoteName('#__content'))
                    ->set($db->quoteName('urls') . ' = :urls')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':urls', $json)->bind(':id', $id, ParameterType::INTEGER)
            )->execute();
        }

        $out = ['ok' => true, 'article_id' => $id, 'restored_from_version' => $versionId];

        return $ignored ? $out + ['ignored' => $ignored] : $out;
    }

    private function keepVersion(int $versionId, bool $keep): array
    {
        $this->articleOfVersion($versionId);

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
        $this->articleOfVersion($versionId);

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
