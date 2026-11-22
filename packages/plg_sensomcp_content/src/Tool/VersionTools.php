<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Table\ContentHistory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\UrlGuard;
use Sensomedia\Plugin\Sensomcp\Content\Support\SavesArticleModel;

\defined('_JEXEC') or die;

/**
 * Outils MCP pour l'historique de contenu des articles (#__history) : lister,
 * lire, comparer, restaurer, conserver et supprimer des versions.
 * item_id Joomla = « com_content.article.<id> ».
 */
final class VersionTools
{
    use SavesArticleModel;

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
    // Tout passe par les classes du cœur, identiques en J5 et J6 :
    //  - table Joomla\CMS\Table\ContentHistory (lecture d'une version) ;
    //  - HistoryModel de com_contenthistory (liste, garder, supprimer) ;
    //  - modèle Article de com_content (restauration via son save()).
    // loadHistory() n'est PAS utilisé : sa signature et son effet diffèrent
    // (J5 : bind dans la table ; J6 : save() direct, sans le formulaire, donc
    // sans les verrous ACL de FormGate).
    // ---------------------------------------------------------------------

    /** Table #__history (classe du cœur, constructeur identique J5/J6). */
    private function historyTable(): ContentHistory
    {
        return new ContentHistory(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /** HistoryModel de com_contenthistory, sans lecture de la requête. */
    private function historyModel()
    {
        return Factory::getApplication()->bootComponent('com_contenthistory')
            ->getMVCFactory()->createModel('History', 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Exécute $fn en accordant au modèle d'historique le droit « edit.own » sur l'article.
     *
     * HistoryModel::canEdit() accepte core.edit sur l'asset, sinon il cherche l'id
     * dans l'état utilisateur `com_content.edit.article.id` : c'est ce que pose le
     * FormController du cœur (holdEditId) quand un auteur ouvre son article. Nos
     * ACL d'outil ont déjà vérifié core.edit ou core.edit.own : on reproduit ce
     * marquage le temps de l'appel, puis on restaure l'état précédent.
     */
    private function withEditHold(int $articleId, callable $fn): mixed
    {
        if ($this->user->authorise('core.edit', self::TYPE_ALIAS . '.' . $articleId)) {
            return $fn();
        }

        $app      = Factory::getApplication();
        $key      = 'com_content.edit.article.id';
        $previous = $app->getUserState($key);
        $app->setUserState($key, array_values(array_unique(array_merge((array) $previous, [$articleId]))));

        try {
            return $fn();
        } finally {
            $app->setUserState($key, $previous);
        }
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
        $table = $this->articleModel()->getTable();
        $by    = $table->load($articleId) ? (int) $table->created_by : 0;

        return FormGate::canEdit($me, self::TYPE_ALIAS . '.' . $articleId, $by);
    }

    /** Version chargée (table du cœur), ou exception si absente. */
    private function loadVersion(int $versionId): ContentHistory
    {
        if ($versionId <= 0) {
            throw new McpToolException('A valid version_id is required.');
        }

        $table = $this->historyTable();

        if (!$table->load($versionId)) {
            throw new McpToolException('Version not found: ' . $versionId);
        }

        return $table;
    }

    /**
     * Article auquel appartient une version : la table d'historique est partagée par tous les
     * composants, on n'accepte que `com_content.article.<id>` (audit M5). Lève si la version
     * n'existe pas ou n'est pas celle d'un article.
     */
    private function articleOfVersion(int $versionId): int
    {
        $itemId = (string) $this->loadVersion($versionId)->item_id;

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

        // HistoryModel : la requête suit le schéma de la version de Joomla
        // (is_current n'existe qu'en J6). ignore_request → état posé à la main.
        $model = $this->historyModel();
        $model->setState('item_id', self::TYPE_ALIAS . '.' . $articleId);
        $model->setState('list.ordering', 'h.save_date');
        $model->setState('list.direction', 'DESC');
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);

        $items = $this->withEditHold($articleId, static fn () => $model->getItems());

        if ($items === false) {
            throw new McpToolException('Could not list versions: ' . $this->modelErrors($model));
        }

        $versions = [];

        foreach ((array) $items as $item) {
            $versions[] = [
                'version_id'      => (int) $item->version_id,
                'version_note'    => (string) $item->version_note,
                'save_date'       => (string) $item->save_date,
                'editor_user_id'  => (int) $item->editor_user_id,
                'editor'          => (string) ($item->editor ?? ''),
                'character_count' => (int) $item->character_count,
                'keep_forever'    => (int) $item->keep_forever,
                // J6 uniquement (colonne is_current) ; null en J5.
                'is_current'      => isset($item->is_current) ? (int) $item->is_current : null,
            ];
        }

        // Le modèle ne trie que par save_date (à la seconde) : départage stable par version_id,
        // sinon des versions enregistrées dans la même seconde sortent dans un ordre aléatoire.
        usort($versions, static fn (array $x, array $y): int => [$y['save_date'], $y['version_id']] <=> [$x['save_date'], $x['version_id']]);

        return ['article_id' => $articleId, 'versions' => $versions];
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

        // On rejoue le snapshot via le modèle Administrator (déclenche events/workflow/historique),
        // en passant par le formulaire d'article : filtres de texte, état/dates réservés à
        // core.edit.state, created_by à core.manage com_users — comme une modification (audit M5).
        $model   = $this->articleModel();
        $current = $model->getItem($id);

        if (!$current || !$current->id) {
            throw new McpToolException('Article not found: ' . $id);
        }

        FormGate::useAdminForms('com_content');
        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the article form.');
        }

        $snapshot = array_intersect_key($data, array_flip([
            'id', 'title', 'alias', 'introtext', 'fulltext', 'catid', 'state', 'language', 'metadesc', 'metakey',
            'images', 'attribs', 'metadata', 'access', 'publish_up', 'publish_down', 'featured', 'created', 'created_by', 'created_by_alias', 'note',
        ]));

        // Le snapshot stocke ces groupes en JSON (colonnes de la table) ; le formulaire les
        // attend en tableaux, sinon le filtre les écarte et ils ne sont pas restaurés.
        foreach (['images', 'attribs', 'metadata'] as $group) {
            if (isset($snapshot[$group]) && \is_string($snapshot[$group])) {
                $decoded = json_decode($snapshot[$group], true);

                if (\is_array($decoded)) {
                    $snapshot[$group] = $decoded;
                } else {
                    unset($snapshot[$group]);
                }
            } elseif (isset($snapshot[$group]) && \is_object($snapshot[$group])) {
                $snapshot[$group] = json_decode((string) json_encode($snapshot[$group]), true);
            }
        }

        // Tags actuels conservés (getItem() les fournit via TagsHelper::getTagIds) : sans la
        // clé, l'observateur Tags les effacerait.
        $snapshot['tags'] = array_values(array_filter(explode(',', (string) ($current->tags->tags ?? ''))));

        // Liens A/B/C : restaurés avec le reste par le modèle, après le même nettoyage de
        // schéma qu'update_article (refus des schémas exécutables).
        $urls = $this->snapshotUrls($data['urls'] ?? null);

        if ($urls !== null) {
            $snapshot['urls'] = $urls;
        }

        $gate    = FormGate::apply($form, $snapshot, FormGate::itemData($current), ['id', 'tags'], null, ['created', 'publish_up', 'publish_down']);
        $ignored = $gate['ignored'];

        if (!$this->saveWithPostedUrls($model, $gate['data'], $urls)) {
            throw new McpToolException('Restore failed: ' . $this->modelErrors($model));
        }

        $out = ['ok' => true, 'article_id' => $id, 'restored_from_version' => $versionId];

        return $ignored ? $out + ['ignored' => $ignored] : $out;
    }

    /** Liens A/B/C d'un snapshot (JSON ou tableau), nettoyés ; null si absents. */
    private function snapshotUrls($raw): ?array
    {
        $raw = \is_string($raw) ? json_decode($raw, true) : (\is_object($raw) ? (array) $raw : $raw);

        if (!\is_array($raw) || $raw === []) {
            return null;
        }

        foreach (['a', 'b', 'c'] as $k) {
            if (isset($raw['url' . $k]) && (string) $raw['url' . $k] !== '') {
                $raw['url' . $k] = UrlGuard::cleanEditorialLink((string) $raw['url' . $k], 'link ' . $k);
            }
        }

        return array_map(static fn ($v) => (string) $v, $raw);
    }

    private function keepVersion(int $versionId, bool $keep): array
    {
        $articleId = $this->articleOfVersion($versionId);

        // HistoryModel::keep() BASCULE le drapeau : on ne l'appelle que s'il doit changer.
        if ((bool) $this->loadVersion($versionId)->keep_forever !== $keep) {
            $model = $this->historyModel();
            $pks   = [$versionId];

            // keep() élague en silence les versions refusées : on juge sur l'état relu.
            $this->withEditHold($articleId, static fn () => $model->keep($pks));

            if ((bool) $this->loadVersion($versionId)->keep_forever !== $keep) {
                throw new McpToolException('Keep forever change failed: ' . $this->modelErrors($model));
            }
        }

        return ['ok' => true, 'version_id' => $versionId, 'keep_forever' => $keep];
    }

    private function deleteVersion(int $versionId): array
    {
        $articleId = $this->articleOfVersion($versionId);

        // Comme le cœur : une version « garder pour toujours » n'est pas supprimable.
        if ((int) $this->loadVersion($versionId)->keep_forever === 1) {
            throw new McpToolException(
                'Version ' . $versionId . ' is marked "keep forever" and cannot be deleted. '
                . 'Unset it first with keep_article_version (keep=false).'
            );
        }

        $model = $this->historyModel();
        $pks   = [$versionId];

        // delete() élague aussi en silence : on juge sur la présence en base.
        $this->withEditHold($articleId, static fn () => $model->delete($pks));

        if ($this->historyTable()->load($versionId)) {
            throw new McpToolException('Delete failed: ' . $this->modelErrors($model));
        }

        return ['ok' => true, 'version_id' => $versionId, 'deleted' => true];
    }

    /** Snapshot décodé d'une version. */
    private function versionData(int $versionId): array
    {
        return (array) json_decode((string) $this->loadVersion($versionId)->version_data, true);
    }
}
