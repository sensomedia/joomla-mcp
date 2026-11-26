<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Tool;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Table\Category as CategoryTable;
use Joomla\CMS\Table\ViewLevel;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\User;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ActionLogContext;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\Ordering;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\Pagination;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\UrlGuard;
use Sensomedia\Plugin\Sensomcp\Content\Support\ContentAcl;
use Sensomedia\Plugin\Sensomcp\Content\Support\ResolvesPublicUrls;
use Sensomedia\Plugin\Sensomcp\Content\Support\SavesArticleModel;

\defined('_JEXEC') or die;

/**
 * Outils MCP opérant sur les articles com_content.
 *
 * Les écritures passent par le modèle Administrator « Article » de com_content
 * (et non par une requête SQL directe) : cela déclenche les événements de
 * contenu, le workflow, la génération d'alias et le respect de l'ACL de
 * l'utilisateur porteur du token.
 */
final class ArticleTools
{
    use ResolvesPublicUrls;
    use SavesArticleModel;

    public function __construct(private User $user, private bool $allowWrite, private int $defaultCatid, private int $defaultState)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        // Scopes OAuth de ce plugin (libellés = clés de langue, traduites au consentement).
        $registry->declareScope('content.read', 'PLG_SENSOMCP_CONTENT_SCOPE_READ');

        // -------- Read (scope content.read) --------

        $registry->register(
            'search_articles',
            'Search articles (same search as the Joomla backend article list: title, alias and note). Returns id, title, alias, catid, state, featured, access, language, dates (created, modified, publish_up, publish_down; UTC). '
            . 'Sortable by creation/modification/publish-up date, id or title.',
            [
                'type'       => 'object',
                'properties' => [
                    'query'     => ['type' => 'string', 'description' => 'Text to search in the title, alias or note (optional). Prefixes: "id:12", "author:name", "content:text" (intro/full text).'],
                    'limit'     => ['type' => 'integer', 'description' => 'Max number of results (default 20, max 100).'],
                    'offset'    => ['type' => 'integer', 'description' => 'Result offset for pagination (default 0).'],
                    'order'     => [
                        'type'        => 'string',
                        'enum'        => ['created', 'modified', 'publish_up', 'id', 'title'],
                        'description' => 'Sort field (default created).',
                    ],
                    'direction' => [
                        'type'        => 'string',
                        'enum'        => ['asc', 'desc'],
                        'description' => 'Sort direction (default desc).',
                    ],
                ],
            ],
            fn (array $a) => $this->searchArticles($a),
            'content.read'
        );

        $registry->register(
            'get_article',
            'Get an article by its id: title, alias, catid, state, access, language, introtext, fulltext, metadesc, metakey, '
            . 'images, url, urls, links, fields, tags, note, attribs (article options), metadata (author, robots, rights). '
            . 'Dates (UTC, as Joomla stores them): created, modified, publish_up, publish_down, featured_up, featured_down '
            . '(+ created_by/created_by_name/created_by_alias, modified_by/modified_by_name, hits, featured, ordering, version, checked_out) '
            . '— handy for Article schema datePublished/dateModified. links = editorial Link A/B/C ({a,b,c} → {url, text, target}).',
            [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'Article id.']],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->getArticle((int) ($a['id'] ?? 0)),
            'content.read'
        );

        $registry->register(
            'list_article_fields',
            'List the custom fields (com_fields) applicable to an article category. '
            . 'Returns each field name, title, type, group, whether required, default and options '
            . '(for list types). Fields are filtered by category assignment. Call this before '
            . 'setting the "fields" argument of create_article / update_article. For a subform field, "subform" '
            . 'gives its sub-fields with the key to use in each row (field<ID>), whether it is repeatable, and an example.',
            [
                'type'       => 'object',
                'properties' => [
                    'catid'      => ['type' => 'integer', 'description' => 'Category id to list applicable fields for.'],
                    'article_id' => ['type' => 'integer', 'description' => 'Alternative: an article id whose category is used.'],
                ],
            ],
            fn (array $a) => $this->listArticleFields($a),
            'content.read'
        );

        if (!$this->allowWrite) {
            return;
        }

        $registry->declareScope('content.write', 'PLG_SENSOMCP_CONTENT_SCOPE_WRITE');

        // -------- Write (scope content.write) --------

        $registry->register(
            'create_article',
            'Create an article. The full HTML goes in "body"; insert '
            . '<hr id="system-readmore"> to split the intro from the full text. '
            . 'state: 0 = unpublished, 1 = published. Optional: dates (created, publish_up, publish_down), access, '
            . 'featured (+ featured_up/down), author (created_by, created_by_alias), note, metadata, attribs, images, links, fields, tags. '
            . 'Returns the public URL and the stored dates (UTC).',
            [
                'type'       => 'object',
                'properties' => [
                    'title'    => ['type' => 'string', 'description' => 'Article title.'],
                    'body'     => ['type' => 'string', 'description' => 'Article HTML content.'],
                    'catid'    => ['type' => 'integer', 'description' => 'Category id (falls back to the default category).'],
                    'state'    => ['type' => 'integer', 'enum' => [0, 1], 'description' => '0 unpublished, 1 published.'],
                    'alias'    => ['type' => 'string', 'description' => 'Alias (auto-generated if empty).'],
                    'language' => ['type' => 'string', 'description' => 'Language code (e.g. fr-FR) or * for all. Default *.'],
                    'metadesc' => ['type' => 'string', 'description' => 'Meta description.'],
                    'metakey'  => ['type' => 'string', 'description' => 'Meta keywords (comma-separated).'],
                    'created'      => ['type' => 'string', 'description' => 'Creation date (' . self::DATE_HINT . '). Default: now.'],
                    'publish_up'   => ['type' => 'string', 'description' => 'Start publishing date (' . self::DATE_HINT . '). Default: now when published.'],
                    'publish_down' => ['type' => 'string', 'description' => 'Finish publishing date (' . self::DATE_HINT . '). Default: never.'],
                    'fields'   => [
                        'type'        => 'object',
                        'description' => 'Custom field values keyed by field name (discover with list_article_fields). '
                            . 'Only fields assigned to the category are applied; others are reported as ignored.'
                            . ' Subform fields: list_article_fields returns their "subform" structure; send a list of rows keyed by the sub-field key (field<ID>), e.g. [{"field13": "Question?", "field14": "<p>Answer</p>"}]. A subform with unknown keys is refused ("invalid") and not saved.',
                    ],
                    'tags'     => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Tag ids to assign to the article (discover them with list_tags). '
                            . 'Unknown ids are reported as ignored.',
                    ],
                    'links'    => self::linksSchema(false),
                ] + self::publishingSchema(false) + self::imagesSchema(),
                'required'   => ['title', 'body'],
            ],
            fn (array $a) => $this->createArticle($a),
            'content.write',
            ['acl' => function (User $me, array $a): bool {
                // Sans arguments (tools/list, consentement) : une catégorie autorisée suffit.
                if ($a === []) {
                    return ContentAcl::anyCategory($me, 'core.create');
                }

                $catid = (int) ($a['catid'] ?? 0) ?: $this->defaultCatid;

                return (bool) $me->authorise('core.create', $catid > 0 ? 'com_content.category.' . $catid : 'com_content');
            }]
        );

        $registry->register(
            'update_article',
            'Update an existing article. Only the provided fields are changed (metadata, attribs and images are merged key by key). '
            . 'Dates (created, publish_up, publish_down, featured_up, featured_down) can be set; publish_*/featured_* accept null to clear them. '
            . 'Returns the stored dates (UTC).',
            [
                'type'       => 'object',
                'properties' => [
                    'id'       => ['type' => 'integer', 'description' => 'Id of the article to update.'],
                    'title'    => ['type' => 'string'],
                    'alias'    => ['type' => 'string', 'description' => 'New alias; "" regenerates it from the title.'],
                    'body'     => ['type' => 'string', 'description' => 'New full HTML content.'],
                    'catid'    => ['type' => 'integer'],
                    'state'    => ['type' => 'integer', 'enum' => [0, 1]],
                    'language' => ['type' => 'string'],
                    'metadesc' => ['type' => 'string'],
                    'metakey'  => ['type' => 'string', 'description' => 'Meta keywords (comma-separated).'],
                    'created'      => ['type' => 'string', 'description' => 'Creation date (' . self::DATE_HINT . ').'],
                    'publish_up'   => ['type' => ['string', 'null'], 'description' => 'Start publishing date (' . self::DATE_HINT . '). null or "" clears it.'],
                    'publish_down' => ['type' => ['string', 'null'], 'description' => 'Finish publishing date (' . self::DATE_HINT . '). null or "" clears it (never expires).'],
                    'fields'   => [
                        'type'        => 'object',
                        'description' => 'Custom field values keyed by field name (see list_article_fields). '
                            . 'Only fields assigned to the category are applied.'
                            . ' Subform fields: list_article_fields returns their "subform" structure; send a list of rows keyed by the sub-field key (field<ID>), e.g. [{"field13": "Question?", "field14": "<p>Answer</p>"}]. A subform with unknown keys is refused ("invalid") and not saved.',
                    ],
                    'tags'     => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Tag ids to assign (discover with list_tags). Replaces the current tag set. '
                            . 'Omit to keep existing tags; pass [] to remove all.',
                    ],
                    'links'    => self::linksSchema(true),
                ] + self::publishingSchema(true) + self::imagesSchema(),
                'required'   => ['id'],
            ],
            fn (array $a) => $this->updateArticle($a),
            'content.write',
            ['acl' => function (User $me, array $a): bool {
                if ($a === []) {
                    return ContentAcl::anyCategory($me, 'core.edit', 'core.edit.own');
                }

                $id = (int) ($a['id'] ?? 0);

                if ($id <= 0) {
                    return $me->authorise('core.edit', 'com_content') || $me->authorise('core.edit.own', 'com_content');
                }

                return FormGate::canEdit($me, 'com_content.article.' . $id, $this->createdBy($id));
            }]
        );

        $registry->register(
            'set_article_state',
            'Set the publication state of one or more articles: 1 publish, 0 unpublish, 2 archive, -2 trash.',
            [
                'type'       => 'object',
                'properties' => [
                    'ids'   => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'List of article ids.',
                    ],
                    'state' => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 = publish, 0 = unpublish, 2 = archive, -2 = trash (recoverable).'],
                ],
                'required'   => ['ids', 'state'],
            ],
            fn (array $a) => $this->setArticleState($a),
            'content.write',
            ['acl' => static fn (User $me, array $a): bool => $a === []
                ? ContentAcl::anyCategory($me, 'core.edit.state')
                : ContentAcl::onEachArticle($me, 'core.edit.state', ContentAcl::ids($a['ids'] ?? []))]
        );

        $registry->register(
            'delete_article',
            'Delete one or more articles. By default they are moved to the trash '
            . '(recoverable). Permanent deletion (permanent=true) is irreversible and REQUIRES confirm=true.',
            [
                'type'       => 'object',
                'properties' => [
                    'ids'       => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'List of article ids.',
                    ],
                    'permanent' => [
                        'type'        => 'boolean',
                        'description' => 'true = permanent (irreversible) deletion; false (default) = trash (recoverable).',
                    ],
                    'confirm'   => [
                        'type'        => 'boolean',
                        'description' => 'Mandatory safeguard: must be true when permanent=true, otherwise the permanent deletion is refused.',
                    ],
                ],
                'required'   => ['ids'],
            ],
            fn (array $a) => $this->deleteArticle($a),
            'content.write',
            ['acl' => static fn (User $me, array $a): bool => $a === []
                ? ContentAcl::anyCategory($me, 'core.delete')
                : ContentAcl::onEachArticle($me, 'core.delete', ContentAcl::ids($a['ids'] ?? []))]
        );

        $registry->register(
            'reorder_article',
            'Move an article before or after another article within the same category (updates the '
            . 'ordering). Provide before_id or after_id.',
            [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer', 'description' => 'Article to move.'],
                    'before_id' => ['type' => 'integer', 'description' => 'Place it just before this article.'],
                    'after_id'  => ['type' => 'integer', 'description' => 'Place it just after this article.'],
                ],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->reorderArticle($a),
            'content.write',
            ['acl' => static fn (User $me, array $a): bool => $a === []
                ? ContentAcl::anyCategory($me, 'core.edit.state')
                : (bool) $me->authorise(
                    'core.edit.state',
                    (int) ($a['id'] ?? 0) > 0 ? 'com_content.article.' . (int) $a['id'] : 'com_content'
                )]
        );

        $registry->register(
            'reorder_article_field',
            'Move a custom field before or after another field within the same context and group '
            . '(updates the ordering). Provide before_id or after_id.',
            [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer', 'description' => 'Field to move.'],
                    'before_id' => ['type' => 'integer', 'description' => 'Place it just before this field.'],
                    'after_id'  => ['type' => 'integer', 'description' => 'Place it just after this field.'],
                ],
                'required'   => ['id'],
            ],
            fn (array $a) => $this->reorderField($a),
            'content.write',
            ['acl' => ['core.edit.state', 'com_content']]
        );
    }

    // ---------------------------------------------------------------------
    // Implémentations
    // ---------------------------------------------------------------------

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function fields(): FieldsSupport
    {
        return new FieldsSupport();
    }

    /**
     * Ligne #__content d'un article via la table du modèle Article (null si absente).
     * Lecture légère (sans les jointures de getItem) pour catid / created_by.
     */
    private function articleRow(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $table = $this->articleModel()->getTable();

        return $table->load($id) ? $table : null;
    }

    /**
     * Article complet via ArticleModel::getItem() (J5/J6) : images, urls, attribs et
     * metadata décodés, featured_up/down (jointure frontpage), ids de tags (TagsHelper).
     * null si l'article n'existe pas.
     */
    private function loadArticle(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $item = $this->articleModel()->getItem($id);

        return ($item && !empty($item->id)) ? $item : null;
    }

    private function articleCatid(int $id): int
    {
        return (int) ($this->articleRow($id)->catid ?? 0);
    }

    /** Ids des tags affectés à un article (getItem() les fournit via TagsHelper::getTagIds). */
    private function tagIdsOf(object $item): array
    {
        $tags = $item->tags ?? null;
        $csv  = \is_object($tags) ? (string) ($tags->tags ?? '') : (string) $tags;

        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    /**
     * Tags affectés (id + titre) d'un article, pour la lecture. TagsHelper::getTags()
     * et non getItemTags() : ce dernier ne garde que les tags publiés et accessibles
     * (logique d'affichage front), alors qu'un outil d'édition doit voir tout ce qui
     * est affecté.
     */
    private function articleTags(object $item): array
    {
        $ids = $this->tagIdsOf($item);
        $out = [];

        foreach ($ids ? (new TagsHelper())->getTags($ids) : [] as $tid => $title) {
            $out[] = ['id' => (int) $tid, 'title' => (string) $title];
        }

        return $out;
    }

    /**
     * Valide une liste d'ids de tags (TagsHelper::getTags : ne renvoie que les tags existants).
     *
     * @return array{applied: string[], ignored: int[]} applied = ids existants en
     *         chaînes (format attendu par le champ tags) ; ignored = ids inconnus.
     */
    private function resolveTags(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return ['applied' => [], 'ignored' => []];
        }

        $exist = array_map('intval', array_keys((new TagsHelper())->getTags($ids)));

        return [
            'applied' => array_map('strval', $exist),
            'ignored' => array_values(array_diff($ids, $exist)),
        ];
    }

    /** Compte rendu tags pour la réponse : tags réellement affectés + ids ignorés. */
    private function tagResult(?object $item, array $ignored): array
    {
        $result = ['assigned' => $item ? $this->articleTags($item) : []];

        if ($ignored) {
            $result['ignored'] = array_values($ignored);
        }

        return $result;
    }

    /**
     * Injecte `url` (+ `urls` multilingue) pour un article, via le trait partagé
     * {@see ResolvesPublicUrls}. Lien interne bâti par RouteHelper::getArticleRoute()
     * du cœur, SANS langue (le trait ajoute `&lang` par langue de contenu active pour
     * un article `language="*"`).
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withUrls(array $result, ?object $item): array
    {
        if (!$item) {
            $result['url'] = '';

            return $result;
        }

        $slug     = $item->id . (((string) ($item->alias ?? '')) !== '' ? ':' . $item->alias : '');
        $category = new CategoryTable($this->db());
        $public   = $this->isPubliclyVisible(
            (int) $item->state,
            (int) $item->access,
            $item->publish_up ?? null,
            $item->publish_down ?? null,
            $category->load((int) $item->catid) ? $category : null
        );

        return $this->withPublicUrls($result, RouteHelper::getArticleRoute($slug, (int) $item->catid), (string) $item->language, $public);
    }

    /**
     * Liens éditoriaux A/B/C d'un article (onglet Publication → colonne
     * #__content.urls, clés urla/urlatext/targeta, idem b et c).
     */
    private const LINK_KEYS = ['a', 'b', 'c'];

    /** Cibles autorisées du navigateur (targeta/b/c). "" = valeur globale. */
    private const LINK_TARGETS = ['0', '1', '2', '3'];

    /**
     * Schéma JSON du paramètre `links`. La structure imbriquée est DÉCLARÉE
     * ENTIÈREMENT (url/text/target) car le client MCP élague les clés non
     * déclarées : sans ça, les sous-champs sont supprimés avant d'atteindre le
     * serveur (a/b/c arrivent en objets vides → interprétés comme « effacer »).
     *
     * @param bool $allowClear true (update) → un lien peut valoir null pour l'effacer.
     */
    private static function linksSchema(bool $allowClear): array
    {
        $link = [
            'type'       => $allowClear ? ['object', 'null'] : 'object',
            'properties' => [
                'url'    => ['type' => 'string', 'description' => 'Target URL (absolute or relative). Empty string removes it.'],
                'text'   => ['type' => 'string', 'description' => 'Link label shown to visitors.'],
                'target' => [
                    'type'        => 'string',
                    'enum'        => ['', '0', '1', '2', '3'],
                    'description' => '"" global default, "0" same window, "1" new window, "2" popup, "3" modal.',
                ],
            ],
        ];

        return [
            'type'        => 'object',
            'description' => 'Editorial Link A/B/C (Publishing tab). Keys "a", "b", "c"; each an object '
                . '{url, text, target}.'
                . ($allowClear ? ' Only the links you pass are changed; pass a link as null to clear it.' : ''),
            'properties'  => ['a' => $link, 'b' => $link, 'c' => $link],
        ];
    }

    /** @param string|array|object|null $urls */
    private function decodeLinks($urls): array
    {
        $raw = \is_string($urls) ? (array) json_decode($urls, true) : (array) $urls;
        $out = [];

        foreach (self::LINK_KEYS as $k) {
            $out[$k] = [
                'url'    => (string) ($raw['url' . $k] ?? ''),
                'text'   => (string) ($raw['url' . $k . 'text'] ?? ''),
                'target' => (string) ($raw['target' . $k] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Normalise l'argument `links` : certains clients MCP (schéma périmé)
     * transmettent l'objet sous forme de CHAÎNE JSON au lieu d'un objet. On
     * accepte les deux (objet/tableau natif OU chaîne JSON).
     */
    private function normalizeLinksArg($v): array
    {
        if (\is_string($v)) {
            $decoded = json_decode($v, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return (array) $v;
    }

    /** Liens A/B/C tels que stockés (getItem() les fournit décodés). */
    private function storedLinks(?object $item): array
    {
        return $this->decodeLinks($item ? ($item->urls ?? []) : []);
    }

    /**
     * Construit la valeur #__content.urls à partir de l'entrée `links` (clés a/b/c),
     * en fusionnant avec l'existant : un lien absent est inchangé, un lien à null (ou
     * objet vide) est effacé, sinon seules les sous-clés fournies (url/text/target)
     * sont écrasées. Retourne toujours les 9 clés dans l'ordre attendu par Joomla.
     *
     * @param string|array|object|null $existing
     */
    private function buildUrls(array $links, $existing): array
    {
        $raw = \is_string($existing) ? (array) json_decode($existing, true) : (array) $existing;

        foreach (self::LINK_KEYS as $k) {
            if (!\array_key_exists($k, $links)) {
                continue;
            }

            $link = $links[$k];

            if ($link === null || $link === []) {
                $raw['url' . $k] = $raw['url' . $k . 'text'] = $raw['target' . $k] = '';

                continue;
            }

            if (!\is_array($link)) {
                throw new McpToolException("Link '$k' must be an object {url, text, target} or null.");
            }

            if (\array_key_exists('url', $link)) {
                // Même nettoyage que le filtre URL du formulaire + refus des schémas exécutables (audit M5).
                $raw['url' . $k] = UrlGuard::cleanEditorialLink((string) $link['url'], 'link ' . $k);
            }

            if (\array_key_exists('text', $link)) {
                $raw['url' . $k . 'text'] = (string) $link['text'];
            }

            if (\array_key_exists('target', $link)) {
                $t                  = (string) $link['target'];
                $raw['target' . $k] = \in_array($t, self::LINK_TARGETS, true) ? $t : '';
            }
        }

        $out = [];

        foreach (self::LINK_KEYS as $k) {
            $out['url' . $k]          = (string) ($raw['url' . $k] ?? '');
            $out['url' . $k . 'text'] = (string) ($raw['url' . $k . 'text'] ?? '');
            $out['target' . $k]       = (string) ($raw['target' . $k] ?? '');
        }

        return $out;
    }

    /**
     * Dates d'un article exposées en écriture (created, publish_up, publish_down).
     * `modified` reste géré par la table (horodatage du save). Les dates de mise
     * en avant (featured_up/down) passent par $model->featured(), voir
     * {@see applyFeaturedArgs}.
     */
    private const DATE_ARGS = ['created', 'publish_up', 'publish_down'];

    /** Dates déjà converties en UTC SQL par sqlDate() : hors filtre `user_utc` du formulaire (double conversion). */
    private const UTC_DATES = ['created', 'publish_up', 'publish_down', 'featured_up', 'featured_down'];

    /** Valeurs admises pour metadata.robots (formulaire article de com_content). */
    private const ROBOTS = ['', 'index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];

    /** Clés JSON de #__content.images (onglet Images et liens). */
    private const IMAGE_KEYS = [
        'image_intro', 'image_intro_alt', 'image_intro_caption', 'float_intro',
        'image_fulltext', 'image_fulltext_alt', 'image_fulltext_caption', 'float_fulltext',
    ];

    /**
     * Schéma JSON des champs Publication / Métadonnées / Options communs à
     * create_article et update_article.
     *
     * @param bool $allowClear true (update) → featured_up/down acceptent null.
     */
    private static function publishingSchema(bool $allowClear): array
    {
        $date = $allowClear ? ['string', 'null'] : 'string';

        return [
            'access'           => ['type' => 'integer', 'description' => 'Access level id (discover with list_access_levels; 1 = Public). Default: site default.'],
            'featured'         => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = featured (home), 0 = not featured. Requires edit.state permission.'],
            'featured_up'      => ['type' => $date, 'description' => 'Start featured date (' . self::DATE_HINT . ').' . ($allowClear ? ' null or "" clears it.' : '')],
            'featured_down'    => ['type' => $date, 'description' => 'Finish featured date (' . self::DATE_HINT . ').' . ($allowClear ? ' null or "" clears it.' : '')],
            'created_by'       => ['type' => 'integer', 'description' => 'Author user id (discover with search_users). Default: the token user.'],
            'created_by_alias' => ['type' => 'string', 'description' => 'Author alias shown instead of the user name.'],
            'note'             => ['type' => 'string', 'description' => 'Editorial note (backend only).'],
            'metadata'         => [
                'type'        => 'object',
                'description' => 'Metadata: {author, robots, rights}. robots: "" (global), "index, follow", "noindex, follow", "index, nofollow", "noindex, nofollow".'
                    . ($allowClear ? ' Merged with the existing metadata; only the keys you pass change.' : ''),
                'properties'  => [
                    'author' => ['type' => 'string', 'description' => 'Meta author.'],
                    'robots' => ['type' => 'string', 'enum' => self::ROBOTS, 'description' => 'Meta robots.'],
                    'rights' => ['type' => 'string', 'description' => 'Content rights.'],
                ],
            ],
            'attribs'          => [
                'type'        => 'object',
                'description' => 'Article options (Options tab, #__content.attribs), e.g. {"show_title": "0", "show_author": "1"}. '
                    . 'Values as Joomla stores them: "" = use global, "0"/"1" = hide/show.'
                    . ($allowClear ? ' Merged with the existing options; only the keys you pass change.' : ''),
            ],
        ];
    }

    /** Schéma JSON des 8 clés images (onglet Images et liens). */
    private static function imagesSchema(): array
    {
        return [
            'image_intro'            => ['type' => 'string', 'description' => 'Intro image: media path or URL (e.g. images/foo.jpg). Upload one with upload_media first. "" removes it.'],
            'image_intro_alt'        => ['type' => 'string', 'description' => 'Intro image alt text.'],
            'image_intro_caption'    => ['type' => 'string', 'description' => 'Intro image caption.'],
            'float_intro'            => ['type' => 'string', 'description' => 'Intro image CSS class(es) (Joomla "Image Class").'],
            'image_fulltext'         => ['type' => 'string', 'description' => 'Full-article image: media path or URL. "" removes it.'],
            'image_fulltext_alt'     => ['type' => 'string', 'description' => 'Full-article image alt text.'],
            'image_fulltext_caption' => ['type' => 'string', 'description' => 'Full-article image caption.'],
            'float_fulltext'         => ['type' => 'string', 'description' => 'Full-article image CSS class(es).'],
        ];
    }

    /** Un argument est fourni s'il figure dans l'appel (même vide). */
    private static function hasAnyArg(array $a, array $keys): bool
    {
        return (bool) array_intersect($keys, array_keys($a));
    }

    /**
     * Normalise un argument objet (metadata/attribs) : objet/tableau natif OU
     * chaîne JSON (clients MCP au schéma périmé, cf. normalizeLinksArg).
     */
    private function objectArg($v, string $arg): array
    {
        if ($v === null) {
            return [];
        }

        if (\is_string($v)) {
            $decoded = json_decode($v, true);

            if (!\is_array($decoded)) {
                throw new McpToolException("'$arg' must be an object.");
            }

            return $decoded;
        }

        if (!\is_array($v) && !\is_object($v)) {
            throw new McpToolException("'$arg' must be an object.");
        }

        return (array) $v;
    }

    private function userExists(int $id): bool
    {
        return $id > 0 && (int) Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id)->id === $id;
    }

    private function accessLevelExists(int $id): bool
    {
        return $id > 0 && (new ViewLevel($this->db()))->load($id);
    }

    /**
     * Reporte access, auteur, note, metadata et attribs dans $data pour le
     * modèle. metadata/attribs sont fusionnés clé par clé avec $current (update)
     * : la table remplace le JSON entier au bind, sans ça un objet partiel
     * effacerait les autres clés.
     *
     * @param array<string,mixed> $currentMetadata valeurs actuelles (update) ou []
     * @param array<string,mixed> $currentAttribs  valeurs actuelles (update) ou []
     */
    private function applyPublishingArgs(array $a, array &$data, array $currentMetadata, array $currentAttribs): void
    {
        if (\array_key_exists('access', $a)) {
            $access = (int) $a['access'];

            if ($access <= 0 || !$this->accessLevelExists($access)) {
                throw new McpToolException('Unknown access level: ' . $access . ' (see list_access_levels).');
            }

            $data['access'] = $access;
        }

        if (\array_key_exists('created_by', $a)) {
            $by = (int) $a['created_by'];

            if ($by <= 0 || !$this->userExists($by)) {
                throw new McpToolException('Unknown user for created_by: ' . $by . ' (see search_users).');
            }

            $data['created_by'] = $by;
        }

        if (\array_key_exists('created_by_alias', $a)) {
            $data['created_by_alias'] = trim((string) $a['created_by_alias']);
        }

        if (\array_key_exists('note', $a)) {
            $data['note'] = (string) $a['note'];
        }

        if (\array_key_exists('metadata', $a)) {
            $meta = $this->objectArg($a['metadata'], 'metadata');

            if (\array_key_exists('robots', $meta) && !\in_array((string) $meta['robots'], self::ROBOTS, true)) {
                throw new McpToolException(
                    'Invalid metadata.robots: ' . $meta['robots'] . '. Allowed: "' . implode('", "', self::ROBOTS) . '".'
                );
            }

            $data['metadata'] = array_merge(
                $currentMetadata,
                array_map(static fn ($v) => \is_scalar($v) || $v === null ? (string) $v : $v, $meta)
            );
        }

        if (\array_key_exists('attribs', $a)) {
            $data['attribs'] = array_merge($currentAttribs, $this->objectArg($a['attribs'], 'attribs'));
        }
    }

    /**
     * Reporte featured / featured_up / featured_down dans $data. Le modèle
     * Article ne traite les dates de mise en avant que si `featured` est
     * présent : on le réinjecte depuis l'état courant quand seules les dates
     * changent. Gardé par core.edit.state (comme la tâche featured du backend).
     *
     * @param array{featured:int, featured_up:?string, featured_down:?string}|null $current
     */
    private function applyFeaturedArgs(array $a, array &$data, ?array $current): void
    {
        if (!self::hasAnyArg($a, ['featured', 'featured_up', 'featured_down'])) {
            return;
        }

        if (!$this->user->authorise('core.edit.state', 'com_content')) {
            throw new McpToolException('Changing featured requires the core.edit.state permission on com_content.');
        }

        $featured = \array_key_exists('featured', $a) ? (int) $a['featured'] : (int) ($current['featured'] ?? 0);

        if (!\in_array($featured, [0, 1], true)) {
            throw new McpToolException("Invalid 'featured'. Allowed: 0 or 1.");
        }

        $data['featured']      = $featured;
        $data['featured_up']   = \array_key_exists('featured_up', $a) ? $this->sqlDate($a['featured_up'], 'featured_up') : ($current['featured_up'] ?? null);
        $data['featured_down'] = \array_key_exists('featured_down', $a) ? $this->sqlDate($a['featured_down'], 'featured_down') : ($current['featured_down'] ?? null);
    }


    /** Noms d'utilisateurs (id → name) pour created_by / modified_by (UserFactory du cœur). */
    private function userNames(array $ids): array
    {
        $factory = Factory::getContainer()->get(UserFactoryInterface::class);
        $out     = [];

        foreach (array_unique(array_filter(array_map('intval', $ids))) as $uid) {
            $user = $factory->loadUserById($uid);

            if ((int) $user->id === $uid) {
                $out[$uid] = (string) $user->name;
            }
        }

        return $out;
    }

    /** Fragment de description commun aux arguments de date. */
    private const DATE_HINT = 'ISO 8601, e.g. 2026-10-03T09:30:00+02:00 or 2026-10-03 09:30; without UTC offset = site timezone';

    /**
     * Convertit un argument de date en valeur SQL UTC (format #__content).
     *
     * Le modèle Article est appelé sans passer par le filtrage de formulaire
     * (filter="user_utc" du champ calendar) : une valeur brute irait telle
     * quelle en base, interprétée comme UTC. On reproduit donc la conversion :
     * une date SANS fuseau explicite est lue dans le fuseau du site (config
     * `offset`), une date avec offset/« Z » est prise telle quelle, puis tout est
     * ramené en UTC. Vide/null → null (= effacer, pour publish_up/publish_down).
     */
    private function sqlDate($value, string $arg): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        try {
            $parsed = date_parse($value);

            if (!\is_array($parsed) || $parsed['error_count'] > 0 || $parsed['year'] === false) {
                throw new \InvalidArgumentException('unparsable');
            }

            // is_localtime = un fuseau figure dans la chaîne ; sinon fuseau du site.
            $tz = !empty($parsed['is_localtime'])
                ? null
                : new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC'));

            return (new Date($value, $tz))->toSql();
        } catch (\Throwable) {
            throw new McpToolException("Invalid date for '$arg': $value (expected " . self::DATE_HINT . ').');
        }
    }

    /**
     * Reporte les arguments de date fournis dans $data pour le modèle.
     * `created` ne peut pas être effacé (vide → ignoré) ; publish_up/down à
     * vide ou null sont effacés (la table accepte NULL).
     */
    private function applyDateArgs(array $a, array &$data): void
    {
        foreach (self::DATE_ARGS as $k) {
            if (!\array_key_exists($k, $a)) {
                continue;
            }

            $sql = $this->sqlDate($a[$k], $k);

            if ($sql === null && $k === 'created') {
                continue;
            }

            $data[$k] = $sql;
        }
    }

    /** Dates stockées (UTC) + featured d'un article (lues sur getItem()), pour les retourner après un save. */
    private function storedDates(?object $item): array
    {
        return [
            'created'       => $item->created ?? null,
            'modified'      => $item->modified ?? null,
            'publish_up'    => $item->publish_up ?? null,
            'publish_down'  => $item->publish_down ?? null,
            'featured'      => (int) ($item->featured ?? 0),
            'featured_up'   => $item->featured_up ?? null,
            'featured_down' => $item->featured_down ?? null,
        ];
    }

    /**
     * En contexte API application, les chemins de formulaires/champs de
     * com_content ne sont pas enregistrés automatiquement. On les enregistre
     * explicitement et on amorce le formulaire, faute de quoi le save() du
     * modèle Administrator échoue sur « Form::loadForm could not load file ».
     */
    private function form($model, array $data): Form
    {
        FormGate::useAdminForms('com_content');

        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the article form: ' . $this->modelErrors($model));
        }

        return $form;
    }

    /** created_by d'un article (0 si inconnu), pour la règle core.edit.own. */
    private function createdBy(int $id): int
    {
        return (int) ($this->articleRow($id)->created_by ?? 0);
    }

    /** Déplacer vers une autre catégorie exige core.create sur la cible (comme le batch « move » du cœur). */
    private function assertCanMoveTo(int $newCatid, int $currentCatid): void
    {
        if ($newCatid !== $currentCatid && !$this->user->authorise('core.create', 'com_content.category.' . $newCatid)) {
            throw new McpToolException("Insufficient permission: core.create on category $newCatid is required to move the article there.");
        }
    }

    /**
     * Niveaux d'accès : l'article ET sa catégorie doivent être dans les niveaux de
     * l'utilisateur du jeton (mêmes contrôles que le modèle Articles du back-office,
     * utilisé par search_articles). §5 audit ACL.
     */
    private function canViewAccess(object $item): bool
    {
        $levels = array_map('intval', $this->user->getAuthorisedViewLevels() ?: [1]);

        if (!\in_array((int) $item->access, $levels, true)) {
            return false;
        }

        $category = new CategoryTable($this->db());

        return !$category->load((int) $item->catid) || \in_array((int) $category->access, $levels, true);
    }

    /**
     * Lecture d'un article non publié : droits d'édition (ou d'édition de l'état)
     * évalués sur l'asset de l'article, donc en tenant compte des permissions
     * posées sur sa catégorie ou sur l'article lui-même ; core.edit.own ne vaut
     * que pour l'auteur. Même règle que update_article.
     */
    private function canReadUnpublished(int $id, int $createdBy): bool
    {
        $asset = 'com_content.article.' . $id;

        return $this->user->authorise('core.edit.state', $asset)
            || FormGate::canEdit($this->user, $asset, $createdBy);
    }

    private function searchArticles(array $a): array
    {
        [$offset, $limit] = Pagination::bounds($a, 20, 100);
        $query            = (string) ($a['query'] ?? '');

        // Colonnes de tri autorisées (jamais l'entrée brute → pas d'injection).
        $columns = ['created' => 'created', 'modified' => 'modified', 'publish_up' => 'publish_up', 'id' => 'id', 'title' => 'title'];
        $column  = $columns[strtolower((string) ($a['order'] ?? 'created'))] ?? 'created';
        $dir     = strtolower((string) ($a['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        // Modèle Administrator « Articles » (celui de la liste du back-office, J5/J6) :
        // recherche (titre, alias, note, préfixes id:/author:/content:), tri, total, et
        // niveaux d'accès de l'article ET de sa catégorie.
        $canEdit = $this->user->authorise('core.edit', 'com_content') || $this->user->authorise('core.edit.state', 'com_content');
        $uid     = (int) $this->user->id;

        if ($canEdit || $uid <= 0 || !$this->user->authorise('core.edit.own', 'com_content')) {
            // Éditeur : tous les états ; sinon : publiés seulement.
            [$items, $total] = $this->queryArticles($query, $canEdit ? '*' : '1', null, $column, $dir, $offset, $limit);
        } else {
            // Auteur « edit.own » seul : publiés OU les siens. Les filtres du modèle se
            // cumulent (ET) : on interroge deux ensembles disjoints — publiés des autres,
            // puis tous les siens — et on fusionne selon le tri demandé.
            [$others, $t1] = $this->queryArticles($query, '1', [$uid, false], $column, $dir, 0, $offset + $limit);
            [$mine, $t2]   = $this->queryArticles($query, '*', [$uid, true], $column, $dir, 0, $offset + $limit);
            $items         = $this->mergeSorted($others, $mine, $column, $dir === 'ASC');
            $items         = \array_slice($items, $offset, $limit);
            $total         = $t1 + $t2;
        }

        return Pagination::fromPage($items, $total, $offset, $limit, 'articles');
    }

    /**
     * Une page du modèle Articles, réduite aux colonnes exposées par l'outil.
     *
     * @param string          $published '1' ou '*' (tous états)
     * @param array{0:int,1:bool}|null $author [user id, true = seulement lui / false = sauf lui]
     *
     * @return array{0: list<array<string,mixed>>, 1: int} lignes, total
     */
    private function queryArticles(string $search, string $published, ?array $author, string $column, string $dir, int $start, int $limit): array
    {
        $model = Factory::getApplication()->bootComponent('com_content')
            ->getMVCFactory()->createModel('Articles', 'Administrator', ['ignore_request' => true]);

        $model->setState('filter.search', $search);
        $model->setState('filter.published', $published);

        if ($author !== null) {
            $model->setState('filter.author_id', $author[0]);
            $model->setState('filter.author_id.include', $author[1]);
        }

        $model->setState('list.ordering', 'a.' . $column);
        $model->setState('list.direction', $dir);
        $model->setState('list.start', $start);
        $model->setState('list.limit', $limit);

        $rows = [];

        foreach ((array) $model->getItems() as $item) {
            $rows[] = [
                'id'           => (int) $item->id,
                'title'        => (string) $item->title,
                'alias'        => (string) $item->alias,
                'catid'        => (int) $item->catid,
                'state'        => (int) $item->state,
                'featured'     => (int) $item->featured,
                'access'       => (int) $item->access,
                'language'     => (string) $item->language,
                'created'      => $item->created,
                'modified'     => $item->modified,
                'publish_up'   => $item->publish_up,
                'publish_down' => $item->publish_down,
            ];
        }

        return [$rows, (int) $model->getTotal()];
    }

    /**
     * Fusionne deux listes déjà triées par la même colonne (départage par id).
     * NULL en premier en ASC, en dernier en DESC, comme MySQL.
     */
    private function mergeSorted(array $a, array $b, string $column, bool $asc): array
    {
        $all = array_merge($a, $b);

        usort($all, static function (array $x, array $y) use ($column, $asc): int {
            $vx = $x[$column];
            $vy = $y[$column];

            if ($vx === null || $vy === null) {
                $cmp = ($vx === null) <=> ($vy === null);
                $cmp = -$cmp; // NULL < valeur
            } elseif ($column === 'id') {
                $cmp = $vx <=> $vy;
            } elseif ($column === 'title') {
                $cmp = strcmp(mb_strtolower((string) $vx), mb_strtolower((string) $vy));
            } else {
                $cmp = strcmp((string) $vx, (string) $vy);
            }

            $cmp = $cmp ?: ($x['id'] <=> $y['id']);

            return $asc ? $cmp : -$cmp;
        });

        return $all;
    }

    private function getArticle(int $id): array
    {
        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $item = $this->loadArticle($id);

        // Même réponse « introuvable » si l'accès (article ou catégorie) manque, ou pour un article
        // non publié non lisible : ne révèle pas son existence.
        if (!$item || !$this->canViewAccess($item)
            || ((int) $item->state !== 1 && !$this->canReadUnpublished((int) $item->id, (int) $item->created_by))) {
            throw new McpToolException('Article not found: ' . $id);
        }

        $row = [];

        foreach ([
            'id', 'title', 'alias', 'catid', 'state', 'featured', 'access', 'language', 'introtext', 'fulltext',
            'metadesc', 'metakey', 'note',
            'created', 'created_by', 'created_by_alias', 'modified', 'modified_by', 'publish_up', 'publish_down',
            'hits', 'ordering', 'version', 'checked_out', 'checked_out_time',
        ] as $k) {
            $row[$k] = $item->{$k} ?? null;
        }

        foreach (['id', 'catid', 'state', 'featured', 'access', 'created_by', 'modified_by', 'hits', 'ordering', 'version', 'checked_out'] as $int) {
            $row[$int] = (int) $row[$int];
        }

        $names                   = $this->userNames([$row['created_by'], $row['modified_by']]);
        $row['created_by_name']  = (string) ($names[$row['created_by']] ?? '');
        $row['modified_by_name'] = (string) ($names[$row['modified_by']] ?? '');

        // getItem() renvoie images / metadata / attribs / urls déjà décodés (Registry::toArray).
        $row['images']        = $this->toArray($item->images ?? []) ?: new \stdClass();
        $row['metadata']      = $this->toArray($item->metadata ?? []) ?: new \stdClass();
        $row['attribs']       = $this->toArray($item->attribs ?? []) ?: new \stdClass();
        $row['links']         = $this->decodeLinks($item->urls ?? []);
        $row['featured_up']   = $item->featured_up ?? null;
        $row['featured_down'] = $item->featured_down ?? null;
        $row                  = $this->withUrls($row, $item);
        $row['fields']        = $this->fields()->valuesFor($id, (int) $row['catid']);
        $row['tags']          = $this->articleTags($item);

        return $row;
    }

    private function listArticleFields(array $a): array
    {
        $catid = (int) ($a['catid'] ?? 0);

        if ($catid <= 0 && !empty($a['article_id'])) {
            $catid = $this->articleCatid((int) $a['article_id']);
        }

        if ($catid <= 0) {
            throw new McpToolException("Provide 'catid' or 'article_id' to list applicable fields.");
        }

        return ['catid' => $catid, 'fields' => $this->fields()->listFor($catid)];
    }

    private function createArticle(array $a): array
    {
        $title = trim((string) ($a['title'] ?? ''));

        if ($title === '') {
            throw new McpToolException("The 'title' parameter is required.");
        }

        $catid = (int) ($a['catid'] ?? 0) ?: $this->defaultCatid;

        if ($catid <= 0) {
            throw new McpToolException(
                'No category provided and no default category configured (default_catid parameter).'
            );
        }

        $body  = (string) ($a['body'] ?? '');
        $state = \array_key_exists('state', $a) ? (int) $a['state'] : $this->defaultState;

        [$introtext, $fulltext] = $this->splitReadmore($body);

        $data = [
            'id'          => 0,
            'title'       => $title,
            'alias'       => (string) ($a['alias'] ?? ''),
            'catid'       => $catid,
            'introtext'   => $introtext,
            'fulltext'    => $fulltext,
            'articletext' => $body,
            'state'       => \in_array($state, [0, 1], true) ? $state : 0,
            'language'    => (string) ($a['language'] ?? '*'),
            'access'      => (int) Factory::getApplication()->get('access', 1),
            'metadesc'    => (string) ($a['metadesc'] ?? ''),
            'metakey'     => (string) ($a['metakey'] ?? ''),
            'attribs'     => [],
            'metadata'    => [],
            'images'      => $this->buildImages($a, []),
            'urls'        => [],
        ];

        // Liens A/B/C : passés au modèle avec le reste (événements, historique, punycode).
        $urls = null;

        if (\array_key_exists('links', $a)) {
            $urls         = $this->buildUrls($this->normalizeLinksArg($a['links']), []);
            $data['urls'] = $urls;
        }

        // Dates : non fournies → la table pose created=now et le modèle
        // publish_up=now si l'article est publié.
        $this->applyDateArgs($a, $data);
        $this->applyPublishingArgs($a, $data, [], []);
        $this->applyFeaturedArgs($a, $data, null);

        // Tags : AdminModel::save() reporte $data['tags'] (tableau d'ids) sur
        // $table->newTags, que l'observateur Tags mappe dans #__contentitem_tag_map.
        $tagInfo = null;

        if (\array_key_exists('tags', $a)) {
            $tagInfo       = $this->resolveTags((array) $a['tags']);
            $data['tags']  = $tagInfo['applied'];
        }

        $model = $this->articleModel();
        $form  = $this->form($model, $data);

        // Création : tout passe au filtre du formulaire (filtres de texte, retrait de
        // state/publish_*/featured* sans core.edit.state, created_by sans core.manage com_users).
        $gate    = FormGate::apply($form, $data, [], ['id', 'tags'], null, self::UTC_DATES);
        $data    = $gate['data'];
        $ignored = $gate['ignored'];

        if (!$this->saveWithPostedUrls($model, $data, $urls)) {
            throw new McpToolException('Create failed: ' . $this->modelErrors($model));
        }

        $id = (int) $model->getState('article.id');

        // Une seule relecture (getItem) pour les dates, l'URL, les liens et les tags.
        $item   = $this->loadArticle($id);
        $result = $this->withUrls([
            'ok'    => true,
            'id'    => $id,
            'title' => $title,
            'catid' => $catid,
            'state' => (int) ($data['state'] ?? 0),
        ] + $this->storedDates($item), $item);

        if ($ignored) {
            $result['ignored'] = $ignored;
        }

        if (!empty($a['fields']) && \is_array($a['fields'])) {
            $result['fields'] = $this->fields()->setValues($id, $catid, $a['fields']);
        }

        if ($urls !== null) {
            $result['links'] = $this->storedLinks($item);
        }

        if ($tagInfo !== null) {
            $result['tags'] = $this->tagResult($item, $tagInfo['ignored']);
        }

        return $result;
    }

    private function updateArticle(array $a): array
    {
        $id = (int) ($a['id'] ?? 0);

        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $model   = $this->articleModel();
        $current = $model->getItem($id);

        if (!$current || !$current->id) {
            throw new McpToolException('Article not found: ' . $id);
        }

        $data = [
            'id'       => $id,
            'title'    => (string) ($a['title'] ?? $current->title),
            'catid'    => (int) ($a['catid'] ?? $current->catid),
            'state'    => \array_key_exists('state', $a) ? (int) $a['state'] : (int) $current->state,
            'language' => (string) ($a['language'] ?? $current->language),
            'metadesc' => (string) ($a['metadesc'] ?? $current->metadesc),
            'metakey'  => (string) ($a['metakey'] ?? $current->metakey),
        ];

        // Alias : non fourni → inchangé ; "" → la table le régénère depuis le titre.
        if (\array_key_exists('alias', $a)) {
            $data['alias'] = trim((string) $a['alias']);
        }

        if (\array_key_exists('body', $a)) {
            [$introtext, $fulltext] = $this->splitReadmore((string) $a['body']);
            $data['introtext']   = $introtext;
            $data['fulltext']    = $fulltext;
            $data['articletext'] = (string) $a['body'];
        }

        // getItem() renvoie images/metadata/attribs déjà décodés en tableaux
        // (Registry::toArray) ; on tolère aussi le JSON brut par sécurité.
        if ($this->hasImageArgs($a)) {
            $data['images'] = $this->buildImages($a, $this->toArray($current->images ?? []));
        }

        // Liens A/B/C : fusionnés avec ceux de getItem() (déjà décodés) et sauvés par le modèle.
        $urls = null;

        if (\array_key_exists('links', $a)) {
            $urls         = $this->buildUrls($this->normalizeLinksArg($a['links']), $this->toArray($current->urls ?? []));
            $data['urls'] = $urls;
        }

        // Dates : seules celles fournies changent (bind partiel → les autres
        // colonnes gardent leur valeur en base).
        $this->applyDateArgs($a, $data);
        $this->applyPublishingArgs(
            $a,
            $data,
            $this->toArray($current->metadata ?? []),
            $this->toArray($current->attribs ?? [])
        );
        $this->applyFeaturedArgs($a, $data, [
            'featured'      => (int) ($current->featured ?? 0),
            'featured_up'   => $current->featured_up ?? null,
            'featured_down' => $current->featured_down ?? null,
        ]);

        // Tags : si l'appelant en fournit, on remplace le jeu ; sinon on réinjecte
        // les tags actuels, car l'observateur Tags les efface quand $data['tags']
        // est présent mais vide et les laisse tels quels quand la clé est réinjectée.
        $tagInfo = null;

        if (\array_key_exists('tags', $a)) {
            $tagInfo      = $this->resolveTags((array) $a['tags']);
            $data['tags'] = $tagInfo['applied'];
        } else {
            $data['tags'] = array_map('strval', $this->tagIdsOf($current));
        }

        $this->assertCanMoveTo((int) $data['catid'], (int) $current->catid);

        // Mise à jour : seuls les champs fournis passent au filtre, fusionnés dans l'item
        // courant (un corps existant n'est pas re-filtré).
        $input = $data;

        foreach (['title', 'catid', 'state', 'language', 'metadesc', 'metakey'] as $k) {
            if (!\array_key_exists($k, $a)) {
                unset($input[$k]);
            }
        }

        $form    = $this->form($model, $data);
        $gate    = FormGate::apply($form, $input, FormGate::itemData($current), ['id', 'tags'], null, self::UTC_DATES);
        $data    = $gate['data'];
        $ignored = $gate['ignored'];

        if (!$this->saveWithPostedUrls($model, $data, $urls)) {
            throw new McpToolException('Update failed: ' . $this->modelErrors($model));
        }

        $item   = $this->loadArticle($id);
        $result = $this->withUrls(['ok' => true, 'id' => $id, 'state' => (int) ($data['state'] ?? $current->state)] + $this->storedDates($item), $item);

        if ($ignored) {
            $result['ignored'] = $ignored;
        }

        if (!empty($a['fields']) && \is_array($a['fields'])) {
            $result['fields'] = $this->fields()->setValues($id, (int) $data['catid'], $a['fields']);
        }

        if ($urls !== null) {
            $result['links'] = $this->storedLinks($item);
        }

        if ($tagInfo !== null) {
            $result['tags'] = $this->tagResult($item, $tagInfo['ignored']);
        }

        return $result;
    }

    private function setArticleState(array $a): array
    {
        $ids   = array_values(array_filter(array_map('intval', (array) ($a['ids'] ?? []))));
        $state = (int) ($a['state'] ?? 1);

        if (!$ids) {
            throw new McpToolException("The 'ids' parameter is required (non-empty list).");
        }

        if (!\in_array($state, [1, 0, 2, -2], true)) {
            throw new McpToolException("Invalid 'state'. Allowed: 1 (publish), 0 (unpublish), 2 (archive), -2 (trash).");
        }

        $model = $this->articleModel();

        // ActionLogContext : sans lui, le changement d'état n'apparaît pas dans le journal des actions.
        if (!ActionLogContext::run('com_content', static function () use ($model, &$ids, $state) {
            return $model->publish($ids, $state);
        })) {
            throw new McpToolException('State change failed: ' . $this->modelErrors($model));
        }

        return ['ok' => true, 'ids' => $ids, 'state' => $state];
    }

    private function deleteArticle(array $a): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($a['ids'] ?? []))));

        if (!$ids) {
            throw new McpToolException("The 'ids' parameter is required (non-empty list).");
        }

        $permanent = (bool) ($a['permanent'] ?? false);
        $confirm   = (bool) ($a['confirm'] ?? false);

        // Garde-fou : une suppression définitive est irréversible → confirmation obligatoire.
        if ($permanent && !$confirm) {
            throw new McpToolException(
                'Permanent deletion is irreversible and requires confirm=true. '
                . 'Without confirm=true the articles are only moved to the trash (recoverable).'
            );
        }

        $model = $this->articleModel();

        // Joomla exige la corbeille (état -2) avant toute suppression définitive.
        if (!ActionLogContext::run('com_content', static function () use ($model, &$ids) {
            return $model->publish($ids, -2);
        })) {
            throw new McpToolException('Trashing failed: ' . $this->modelErrors($model));
        }

        if (!$permanent) {
            return ['ok' => true, 'ids' => $ids, 'trashed' => true];
        }

        // delete() reçoit les pks par référence et nettoie assets/associations.
        $pks = $ids;

        if (!ActionLogContext::run('com_content', static function () use ($model, &$pks) {
            return $model->delete($pks);
        })) {
            throw new McpToolException('Permanent deletion failed: ' . $this->modelErrors($model));
        }

        return ['ok' => true, 'ids' => $ids, 'deleted' => true];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Sépare intro / texte complet sur le marqueur readmore de Joomla.
     *
     * @return array{0:string, 1:string}
     */
    private function splitReadmore(string $body): array
    {
        if (preg_match('/<hr\s+id=(["\'])system-readmore\1\s*\/?>/i', $body)) {
            $parts = preg_split('/<hr\s+id=(["\'])system-readmore\1\s*\/?>/i', $body, 2);

            return [$parts[0] ?? '', $parts[1] ?? ''];
        }

        return [$body, ''];
    }

    /** URL publique du site → chemin relatif (images/…) ; laisse un chemin tel quel. */
    private function normalizeImagePath(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $path = (string) parse_url($value, \PHP_URL_PATH);

            return ltrim($path !== '' ? $path : $value, '/');
        }

        return ltrim($value, '/');
    }

    private function hasImageArgs(array $a): bool
    {
        return self::hasAnyArg($a, self::IMAGE_KEYS);
    }

    /** Tableau depuis un tableau/objet déjà décodé ou une chaîne JSON. */
    private function toArray($v): array
    {
        if (\is_string($v)) {
            return (array) json_decode($v, true);
        }

        return (array) $v;
    }

    /**
     * Construit le champ « images » de com_content (fusion avec l'existant) :
     * chemins normalisés pour image_intro/image_fulltext, chaînes brutes pour
     * alt/caption/float (classe CSS).
     *
     * @param array<string,mixed> $existing valeurs images actuelles décodées
     */
    private function buildImages(array $a, array $existing): array
    {
        $images = array_merge(array_fill_keys(self::IMAGE_KEYS, ''), $existing);

        foreach (self::IMAGE_KEYS as $k) {
            if (!\array_key_exists($k, $a)) {
                continue;
            }

            $images[$k] = \in_array($k, ['image_intro', 'image_fulltext'], true)
                ? $this->normalizeImagePath((string) $a[$k])
                : (string) $a[$k];
        }

        return $images;
    }

    private function reorderArticle(array $a): array
    {
        $id = (int) ($a['id'] ?? 0);

        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $catid = $this->articleCatid($id);

        if ($catid <= 0) {
            throw new McpToolException('Article not found: ' . $id);
        }

        // EXCEPTION SQL assumée : l'ordre complet de la catégorie, indépendamment des droits de
        // lecture. Le modèle Articles masquerait les articles hors des niveaux d'accès de
        // l'utilisateur ; les renuméroter sans eux créerait des collisions d'ordering.
        // L'écriture, elle, passe par saveorder().
        $db       = $this->db();
        $siblings = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('catid') . ' = ' . $catid)
                ->where($db->quoteName('state') . ' >= 0')
                ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC')
        )->loadColumn());

        $order = Ordering::compute(
            $siblings,
            $id,
            isset($a['before_id']) ? (int) $a['before_id'] : null,
            isset($a['after_id']) ? (int) $a['after_id'] : null
        );

        $this->saveOrder($this->articleModel(), $order);

        return ['ok' => true, 'id' => $id, 'catid' => $catid, 'order' => $order];
    }

    /**
     * Persiste un ordre via AdminModel::saveorder() (identique J5/J6) : ACL
     * core.edit.state par élément, store() de la table, reorder() selon les
     * conditions du modèle (catégorie, contexte…) et vidage du cache.
     * saveorder() écarte en silence les éléments non autorisés : on le signale.
     *
     * @param int[] $order ids dans le nouvel ordre
     */
    private function saveOrder($model, array $order): void
    {
        $pks = $order;

        if (!$model->saveorder($pks, range(1, \count($order)))) {
            throw new McpToolException('Reorder failed: ' . $this->modelErrors($model));
        }

        $denied = array_values(array_diff($order, array_map('intval', $pks)));

        if ($denied) {
            throw new McpToolException(
                'Reorder incomplete: core.edit.state is denied on item(s) ' . implode(', ', $denied) . '.'
            );
        }
    }

    private function reorderField(array $a): array
    {
        $id = (int) ($a['id'] ?? 0);

        if ($id <= 0) {
            throw new McpToolException("The 'id' parameter is required.");
        }

        $model = Factory::getApplication()->bootComponent('com_fields')
            ->getMVCFactory()->createModel('Field', 'Administrator', ['ignore_request' => true]);
        $table = $model->getTable();

        if (!$table->load($id)) {
            throw new McpToolException('Field not found: ' . $id);
        }

        $row = ['context' => (string) $table->context, 'group_id' => (int) $table->group_id];
        $db  = $this->db();

        // EXCEPTION SQL assumée (même raison que reorder_article) : liste complète des champs
        // du groupe ; l'écriture passe par saveorder().
        $siblings = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote((string) $row['context']))
                ->where($db->quoteName('group_id') . ' = ' . (int) $row['group_id'])
                ->where($db->quoteName('state') . ' >= 0')
                ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC')
        )->loadColumn());

        $order = Ordering::compute(
            $siblings,
            $id,
            isset($a['before_id']) ? (int) $a['before_id'] : null,
            isset($a['after_id']) ? (int) $a['after_id'] : null
        );

        $this->saveOrder($model, $order);

        return ['ok' => true, 'id' => $id, 'context' => (string) $row['context'], 'order' => $order];
    }
}
