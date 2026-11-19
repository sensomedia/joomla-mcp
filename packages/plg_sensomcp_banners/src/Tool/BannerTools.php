<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Banners\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ActionLogContext;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\Pagination;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Outils MCP des bannières (com_banners) : statut, liste, fiche, statistiques, catégories,
 * création / modification / état / remise à zéro / suppression.
 *
 * Aligné sur l'API Joomla (plugins/webservices/banners → api/components/com_banners) : champs
 * des vues JSON:API, écritures par BannerModel (admin) + FormGate. Écarts volontaires :
 * code personnalisé (custombannercode, filter="raw" au back-office) réservé aux utilisateurs
 * autorisés à publier sans filtrage ; catid validé avant save() (sinon le modèle crée une catégorie).
 */
final class BannerTools
{
    private const EXT = BannersSupport::EXT;

    private const COLS = ['id', 'name', 'alias', 'catid', 'cid', 'type', 'state', 'sticky', 'imptotal', 'impmade', 'clicks',
        'clickurl', 'purchase_type', 'track_impressions', 'track_clicks', 'publish_up', 'publish_down', 'language', 'created_by'];

    public function __construct(private User $user, private BannersSupport $s)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        $read = ['core.manage', self::EXT];

        $registry->register(
            'get_banners_status',
            'Overview of the Joomla Banners component: global options (purchase type, impression and click tracking, '
            . 'robots), number of banners per state, banners that are expired or have used up their impressions, number '
            . 'of clients, and the published Banners modules with their positions (banners are only shown where such a module is published).',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->status(),
            'banners.read',
            ['acl' => $read]
        );

        $registry->register(
            'list_banners',
            'List banners (paginated, default 20, max 100) with impressions, quota, clicks and click-through rate. Filters: '
            . 'text search, state (default: all except trashed), category, client, type (image/custom), language, sticky.',
            [
                'type'       => 'object',
                'properties' => [
                    'search'    => ['type' => 'string', 'description' => 'Text in the name, alias or click URL. "id:N" finds one id.'],
                    'state'     => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all']],
                    'catid'     => ['type' => 'integer'],
                    'client_id' => ['type' => 'integer'],
                    'type'      => ['type' => 'string', 'enum' => ['image', 'custom']],
                    'language'  => ['type' => 'string'],
                    'sticky'    => ['type' => 'boolean'],
                    'sort'      => ['type' => 'string', 'enum' => ['name', 'ordering', 'impressions', 'clicks', 'created', 'id'], 'description' => 'Default: name.'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'limit'     => ['type' => 'integer'],
                    'offset'    => ['type' => 'integer'],
                ],
            ],
            fn (array $a) => $this->listBanners($a),
            'banners.read',
            ['acl' => $read]
        );

        $registry->register(
            'get_banner',
            'Get one banner with all its settings: type, image (path, size, alt) or custom code, click URL, description, '
            . 'client, category, state, sticky, schedule, impressions/quota/clicks, purchase type, effective tracking and keywords.',
            ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
            fn (array $a) => $this->getBanner((int) ($a['id'] ?? 0)),
            'banners.read',
            ['acl' => $read]
        );

        $registry->register(
            'get_banner_stats',
            'Impressions and clicks over time, from the Joomla banner tracking table, per day or per month (default: last '
            . '30 days, per day). Filter by banner ids, client or category. Only what was tracked is counted: the response '
            . 'says which banners have impression or click tracking turned off. Current totals are in list_banners.',
            [
                'type'       => 'object',
                'properties' => [
                    'banner_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'client_id'  => ['type' => 'integer'],
                    'catid'      => ['type' => 'integer'],
                    'from'       => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default: 30 days ago).'],
                    'to'         => ['type' => 'string', 'description' => 'End date YYYY-MM-DD, included (default: today).'],
                    'group'      => ['type' => 'string', 'enum' => ['day', 'month', 'total'], 'description' => 'Default: day.'],
                ],
            ],
            fn (array $a) => $this->stats($a),
            'banners.read',
            ['acl' => $read]
        );

        $registry->register(
            'list_banner_categories',
            'List the categories of the Banners component (id, title, path, state, number of banners).',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => ['categories' => $this->s->categories()],
            'banners.read',
            ['acl' => $read]
        );

        $props = [
            'name'              => ['type' => 'string'],
            'alias'             => ['type' => 'string', 'description' => 'Unique within the category; generated from the name if empty.'],
            'catid'             => ['type' => 'integer', 'description' => 'Banners category id (see list_banner_categories).'],
            'type'              => ['type' => 'string', 'enum' => ['image', 'custom'], 'description' => 'image (default) or custom code.'],
            'image'             => ['type' => 'string', 'description' => 'Image path, e.g. images/banners/spring.jpg (type image).'],
            'width'             => ['type' => 'integer'],
            'height'            => ['type' => 'integer'],
            'alt'               => ['type' => 'string', 'description' => 'Alternative text of the image.'],
            'custom_code'       => ['type' => 'string', 'description' => 'Custom HTML/JavaScript (type custom), inserted as is in the pages. Only for users allowed to publish unfiltered HTML (Joomla text filters).'],
            'click_url'         => ['type' => 'string', 'description' => 'Destination when the banner is clicked.'],
            'description'       => ['type' => 'string', 'description' => 'Internal description (HTML).'],
            'client_id'         => ['type' => 'integer', 'description' => 'Client id (see list_banner_clients), 0 = none.'],
            'published'         => ['type' => 'boolean', 'description' => 'Default true on creation.'],
            'sticky'            => ['type' => 'boolean', 'description' => 'Shown before the other banners of the module.'],
            'language'          => ['type' => 'string', 'description' => 'Language code or * (default).'],
            'publish_up'        => ['type' => 'string', 'description' => 'Start of display, YYYY-MM-DD HH:MM in the site time zone ("" = none).'],
            'publish_down'      => ['type' => 'string', 'description' => 'End of display ("" = none).'],
            'max_impressions'   => ['type' => 'integer', 'description' => 'Total impressions purchased (0 = unlimited).'],
            'purchase_type'     => ['type' => 'string', 'enum' => array_values(BannersSupport::PURCHASE), 'description' => 'inherit = client setting.'],
            'track_impressions' => ['type' => 'string', 'enum' => array_values(BannersSupport::TRACK), 'description' => 'inherit = client setting.'],
            'track_clicks'      => ['type' => 'string', 'enum' => array_values(BannersSupport::TRACK)],
            'keywords'          => ['type' => 'string', 'description' => 'Comma-separated keywords (used by Banners modules set to match the page keywords).'],
        ];

        $registry->register(
            'create_banner',
            'Create a banner, with the same checks as the back-end form. An image banner needs "image" (path under images/); a '
            . 'custom banner needs "custom_code" and is allowed only to users who may publish unfiltered HTML. The banner is '
            . 'displayed by the Banners modules published on the site (see get_banners_status).',
            ['type' => 'object', 'required' => ['name'], 'properties' => $props],
            fn (array $a) => $this->save(0, $a),
            'banners.write',
            ['acl' => fn (User $me, array $a) => $me->authorise('core.manage', self::EXT)
                && $me->authorise('core.create', ((int) ($a['catid'] ?? 0)) > 0 ? self::EXT . '.category.' . (int) $a['catid'] : self::EXT)]
        );

        $registry->register(
            'update_banner',
            'Change a banner. Only the fields you pass are changed. Moving to another category requires the right to create '
            . 'banners there; changing the custom code requires the right to publish unfiltered HTML.',
            ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']] + $props],
            fn (array $a) => $this->save((int) ($a['id'] ?? 0), $a),
            'banners.write',
            ['acl' => fn (User $me, array $a) => $me->authorise('core.manage', self::EXT) && $this->canEdit($me, (int) ($a['id'] ?? 0))]
        );

        $registry->register(
            'set_banner_state',
            'Publish, unpublish, archive or trash banners, and/or pin them (sticky) (max ' . BannersSupport::MAX_IDS . ' ids). '
            . 'Permissions are checked per category; refused ids are reported.',
            [
                'type'       => 'object',
                'required'   => ['ids'],
                'properties' => [
                    'ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'state'  => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed']],
                    'sticky' => ['type' => 'boolean'],
                ],
            ],
            fn (array $a) => $this->setState($a),
            'banners.write',
            ['acl' => ['core.edit.state', self::EXT]]
        );

        $registry->register(
            'reset_banner_stats',
            'Reset the impression and click counters of banners to zero (max ' . BannersSupport::MAX_IDS . ' ids), as with '
            . 'the reset buttons of the back-end form. With clear_tracking=true, also delete their detailed tracking history '
            . '(get_banner_stats). Irreversible.',
            [
                'type'       => 'object',
                'required'   => ['ids'],
                'properties' => [
                    'ids'            => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'clear_tracking' => ['type' => 'boolean', 'description' => 'Also delete the tracking history (default false).'],
                ],
            ],
            fn (array $a) => $this->resetStats($a),
            'banners.write',
            ['risk' => 'danger', 'acl' => ['core.edit', self::EXT], 'confirm' => fn (array $a) => $this->resetPreview($a)]
        );

        $registry->register(
            'delete_banners',
            'Permanently delete banners that are in the trash (max ' . BannersSupport::MAX_IDS . ' ids), as in the Joomla '
            . 'back-end: trash them first with set_banner_state. Banners not in the trash are listed and left untouched. Irreversible.',
            ['type' => 'object', 'required' => ['ids'], 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
            fn (array $a) => $this->delete($a),
            'banners.write',
            ['risk' => 'danger', 'acl' => ['core.delete', self::EXT], 'confirm' => fn (array $a) => $this->deletePreview($a)]
        );

        $registry->register(
            'create_banner_category',
            'Create a category in the Banners component (needed before the first banner, or to organise campaigns).',
            [
                'type'       => 'object',
                'required'   => ['title'],
                'properties' => [
                    'title'       => ['type' => 'string'],
                    'alias'       => ['type' => 'string'],
                    'parent_id'   => ['type' => 'integer', 'description' => 'Parent Banners category id (default: top level).'],
                    'description' => ['type' => 'string'],
                    'published'   => ['type' => 'boolean', 'description' => 'Default true.'],
                    'language'    => ['type' => 'string'],
                ],
            ],
            fn (array $a) => $this->createCategory($a),
            'banners.write',
            ['acl' => ['core.create', self::EXT]]
        );
    }

    // ------------------------------------------------------------------ lecture

    private function status(): array
    {
        $db     = $this->s->db();
        $params = $this->s->params();
        $now    = $db->quote(Factory::getDate()->toSql());

        $counts = ['published' => 0, 'unpublished' => 0, 'archived' => 0, 'trashed' => 0];

        foreach ($db->setQuery(
            $db->getQuery(true)->select([$db->quoteName('state'), 'COUNT(*) AS n'])->from($db->quoteName('#__banners'))->group($db->quoteName('state'))
        )->loadAssocList() as $r) {
            $counts[$this->s->label(BannersSupport::STATES, $r['state'])] = (int) $r['n'];
        }

        $base = $db->getQuery(true)->select($db->quoteName(['id', 'name']))->from($db->quoteName('#__banners'))
            ->where($db->quoteName('state') . ' = 1');

        $expired = (clone $base)->where($db->quoteName('publish_down') . ' IS NOT NULL')->where($db->quoteName('publish_down') . ' < ' . $now);
        $quota   = (clone $base)->where($db->quoteName('imptotal') . ' > 0')->where($db->quoteName('impmade') . ' >= ' . $db->quoteName('imptotal'));

        $modules = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['m.id', 'm.title', 'm.position', 'm.published', 'm.language', 'm.params']))
                ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__modules_menu', 'mm') . ' WHERE ' . $db->quoteName('mm.moduleid') . ' = '
                    . $db->quoteName('m.id') . ' AND ' . $db->quoteName('mm.menuid') . ' = 0) AS ' . $db->quoteName('all_pages'))
                ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__modules_menu', 'mm2') . ' WHERE ' . $db->quoteName('mm2.moduleid') . ' = '
                    . $db->quoteName('m.id') . ') AS ' . $db->quoteName('assignments'))
                ->from($db->quoteName('#__modules', 'm'))
                ->where($db->quoteName('m.module') . ' = ' . $db->quote('mod_banners'))
                ->where($db->quoteName('m.client_id') . ' = 0')
                ->where($db->quoteName('m.published') . ' IN (0, 1)')
        )->loadAssocList() as $m) {
            $p = new Registry($m['params']);
            $modules[] = [
                'id'        => (int) $m['id'],
                'title'     => (string) $m['title'],
                'position'  => (string) $m['position'],
                'state'     => (int) $m['published'] === 1 ? 'published' : 'unpublished',
                'pages'     => (int) $m['all_pages'] > 0 ? 'all' : ((int) $m['assignments'] > 0 ? 'selected' : 'none'),
                'count'     => (int) $p->get('count', 5),
                'catid'     => array_values(array_filter((array) $p->get('catid', []))),
                'client_id' => (int) $p->get('cid', 0),
                'keywords'  => (bool) $p->get('tag_search', 0),
                'language'  => (string) $m['language'],
            ];
        }

        $warnings = [];

        if (!array_filter($modules, static fn ($m) => $m['state'] === 'published' && $m['position'] !== '' && $m['pages'] !== 'none')) {
            $warnings[] = 'No published Banners module with a position and pages: banners are not displayed anywhere. Create one with the Modules tools (module type mod_banners).';
        }

        return [
            'options' => [
                'purchase_type'            => $this->s->label(BannersSupport::PURCHASE, $params->get('purchase_type', 1)),
                'track_impressions'        => (bool) $params->get('track_impressions', 0),
                'track_clicks'             => (bool) $params->get('track_clicks', 0),
                'track_robots_impressions' => (bool) $params->get('track_robots_impressions', 1),
            ],
            'banners'          => $counts,
            'expired'          => $db->setQuery($expired)->loadAssocList(),
            'quota_reached'    => $db->setQuery($quota)->loadAssocList(),
            'clients'          => (int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__banner_clients'))->where($db->quoteName('state') . ' <> -2'))->loadResult(),
            'categories'       => \count($this->s->categories()),
            'modules'          => $modules,
            'warnings'         => $warnings,
        ];
    }

    private function listBanners(array $a): array
    {
        [$offset, $limit] = Pagination::bounds($a, 20, 100);
        $db = $this->s->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(array_map(static fn ($c) => 'a.' . $c, self::COLS), self::COLS))
            ->select($db->quoteName(['c.title', 'cl.name'], ['category', 'client']))
            ->from($db->quoteName('#__banners', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
            ->join('LEFT', $db->quoteName('#__banner_clients', 'cl'), $db->quoteName('cl.id') . ' = ' . $db->quoteName('a.cid'));

        $state = strtolower(trim((string) ($a['state'] ?? '')));

        if ($state === '') {
            $q->where($db->quoteName('a.state') . ' <> -2');
        } elseif ($state !== 'all') {
            $q->where($db->quoteName('a.state') . ' = ' . $this->s->code(BannersSupport::STATES, $state, 'state'));
        }

        $search = trim((string) ($a['search'] ?? ''));

        if (stripos($search, 'id:') === 0) {
            $q->where($db->quoteName('a.id') . ' = ' . (int) substr($search, 3));
        } elseif ($search !== '') {
            $like = $db->quote('%' . $db->escape($search, true) . '%');
            $q->where('(' . $db->quoteName('a.name') . ' LIKE ' . $like . ' OR ' . $db->quoteName('a.alias') . ' LIKE ' . $like
                . ' OR ' . $db->quoteName('a.clickurl') . ' LIKE ' . $like . ')');
        }

        foreach (['catid' => 'a.catid', 'client_id' => 'a.cid'] as $k => $col) {
            if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) {
                $q->where($db->quoteName($col) . ' = ' . (int) $a[$k]);
            }
        }

        if (($a['type'] ?? '') !== '') {
            $q->where($db->quoteName('a.type') . ' = ' . $this->s->code(BannersSupport::TYPES, $a['type'], 'type'));
        }

        if (($a['language'] ?? '') !== '') {
            $q->where($db->quoteName('a.language') . ' = ' . $db->quote((string) $a['language']));
        }

        if (\array_key_exists('sticky', $a) && $a['sticky'] !== null) {
            $q->where($db->quoteName('a.sticky') . ' = ' . ($a['sticky'] ? 1 : 0));
        }

        $sorts = ['name' => 'a.name', 'ordering' => 'a.ordering', 'impressions' => 'a.impmade', 'clicks' => 'a.clicks', 'created' => 'a.created', 'id' => 'a.id'];
        $sort  = $sorts[strtolower((string) ($a['sort'] ?? 'name'))] ?? 'a.name';
        $dir   = strtolower((string) ($a['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $q->order($db->quoteName($sort) . ' ' . $dir)->order($db->quoteName('a.id') . ' ASC');

        $now = Factory::getDate()->toSql();

        return Pagination::envelope($db, $q, $offset, $limit, 'banners', fn (array $r) => [
            'id'            => (int) $r['id'],
            'name'          => (string) $r['name'],
            'type'          => $this->s->label(BannersSupport::TYPES, $r['type']),
            'catid'         => (int) $r['catid'],
            'category'      => (string) $r['category'],
            'client_id'     => (int) $r['cid'],
            'client'        => (string) ($r['client'] ?? ''),
            'state'         => $this->s->label(BannersSupport::STATES, $r['state']),
            'sticky'        => (bool) $r['sticky'],
            'impressions'   => (int) $r['impmade'],
            'max_impressions' => (int) $r['imptotal'],
            'clicks'        => (int) $r['clicks'],
            'ctr_percent'   => (int) $r['impmade'] > 0 ? round(100 * (int) $r['clicks'] / (int) $r['impmade'], 2) : null,
            'publish_up'    => $r['publish_up'],
            'publish_down'  => $r['publish_down'],
            'expired'       => $r['publish_down'] !== null && $r['publish_down'] < $now,
            'quota_reached' => (int) $r['imptotal'] > 0 && (int) $r['impmade'] >= (int) $r['imptotal'],
            'language'      => (string) $r['language'],
        ]);
    }

    private function getBanner(int $id): array
    {
        $item = $id > 0 ? $this->s->model('Banner')->getItem($id) : null;

        if (!$item || !(int) ($item->id ?? 0)) {
            throw new McpToolException('Banner #' . $id . ' not found.');
        }

        $params = $item->params instanceof Registry ? $item->params->toArray() : (array) (new Registry($item->params ?? '{}'))->toArray();
        $client = (int) $item->cid > 0 ? ($this->s->rows('#__banner_clients', [(int) $item->cid], ['id', 'name', 'track_impressions', 'track_clicks', 'purchase_type'])[(int) $item->cid] ?? null) : null;
        $data   = FormGate::itemData($item);

        return [
            'id'                => (int) $item->id,
            'name'              => (string) $item->name,
            'alias'             => (string) $item->alias,
            'type'              => $this->s->label(BannersSupport::TYPES, $item->type),
            'image'             => (string) ($params['imageurl'] ?? ''),
            'width'             => ($params['width'] ?? '') !== '' ? (int) $params['width'] : null,
            'height'            => ($params['height'] ?? '') !== '' ? (int) $params['height'] : null,
            'alt'               => (string) ($params['alt'] ?? ''),
            'custom_code'       => (string) ($item->custombannercode ?? ''),
            'click_url'         => (string) ($item->clickurl ?? ''),
            'description'       => (string) ($item->description ?? ''),
            'catid'             => (int) $item->catid,
            'category'          => (string) ($this->categoryTitle((int) $item->catid)),
            'client'            => $client ? ['id' => (int) $client['id'], 'name' => (string) $client['name']] : null,
            'state'             => $this->s->label(BannersSupport::STATES, $item->state),
            'sticky'            => (bool) $item->sticky,
            'language'          => (string) $item->language,
            'publish_up'        => $item->publish_up ?: null,
            'publish_down'      => $item->publish_down ?: null,
            'impressions'       => (int) $item->impmade,
            'max_impressions'   => (int) $item->imptotal,
            'clicks'            => (int) $item->clicks,
            'purchase_type'     => $this->s->label(BannersSupport::PURCHASE, $item->purchase_type),
            'next_reset'        => $item->reset ?: null,
            'track_impressions' => $this->s->label(BannersSupport::TRACK, $item->track_impressions),
            'track_clicks'      => $this->s->label(BannersSupport::TRACK, $item->track_clicks),
            'tracking'          => $this->s->effectiveTracking($data, $client),
            'keywords'          => (string) ($item->metakey ?? ''),
            'created'           => (string) ($item->created ?? ''),
            'modified'          => (string) ($item->modified ?? ''),
        ];
    }

    private function stats(array $a): array
    {
        $db    = $this->s->db();
        $group = strtolower((string) ($a['group'] ?? 'day'));
        $from  = trim((string) ($a['from'] ?? '')) ?: gmdate('Y-m-d', time() - 30 * 86400);
        $to    = trim((string) ($a['to'] ?? '')) ?: gmdate('Y-m-d');

        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                throw new McpToolException('"' . $k . '" must be a date YYYY-MM-DD.');
            }
        }

        $period = match ($group) {
            'day'   => 'DATE(' . $db->quoteName('t.track_date') . ')',
            'month' => 'DATE_FORMAT(' . $db->quoteName('t.track_date') . ", '%Y-%m')",
            'total' => $db->quote('total'),
            default => throw new McpToolException('"group" must be day, month or total.'),
        };

        $q = $db->getQuery(true)
            ->select([$period . ' AS ' . $db->quoteName('period'),
                'SUM(CASE WHEN ' . $db->quoteName('t.track_type') . ' = 1 THEN ' . $db->quoteName('t.count') . ' ELSE 0 END) AS ' . $db->quoteName('impressions'),
                'SUM(CASE WHEN ' . $db->quoteName('t.track_type') . ' = 2 THEN ' . $db->quoteName('t.count') . ' ELSE 0 END) AS ' . $db->quoteName('clicks')])
            ->from($db->quoteName('#__banner_tracks', 't'))
            ->join('INNER', $db->quoteName('#__banners', 'b'), $db->quoteName('b.id') . ' = ' . $db->quoteName('t.banner_id'))
            ->where($db->quoteName('t.track_date') . ' >= ' . $db->quote($from . ' 00:00:00'))
            ->where($db->quoteName('t.track_date') . ' <= ' . $db->quote($to . ' 23:59:59'))
            ->group($period)
            ->order($period . ' ASC');

        $scope = $db->getQuery(true)->select($db->quoteName(['b.id', 'b.name', 'b.track_impressions', 'b.track_clicks', 'b.cid']))->from($db->quoteName('#__banners', 'b'))
            ->where($db->quoteName('b.state') . ' <> -2');

        if (!empty($a['banner_ids'])) {
            $ids = $this->s->ids($a['banner_ids'], 'banner_ids');
            $q->whereIn($db->quoteName('b.id'), $ids);
            $scope->whereIn($db->quoteName('b.id'), $ids);
        }

        foreach (['client_id' => 'b.cid', 'catid' => 'b.catid'] as $k => $col) {
            if (!empty($a[$k])) {
                $q->where($db->quoteName($col) . ' = ' . (int) $a[$k]);
                $scope->where($db->quoteName($col) . ' = ' . (int) $a[$k]);
            }
        }

        $rows   = $db->setQuery($q, 0, 400)->loadAssocList();
        $series = array_map(static fn ($r) => [
            'period'      => (string) $r['period'],
            'impressions' => (int) $r['impressions'],
            'clicks'      => (int) $r['clicks'],
            'ctr_percent' => (int) $r['impressions'] > 0 ? round(100 * (int) $r['clicks'] / (int) $r['impressions'], 2) : null,
        ], $rows);

        // Bannières du périmètre dont le suivi est coupé (rien n'est enregistré pour elles).
        $banners = $db->setQuery($scope, 0, 1000)->loadAssocList();
        $clients = $this->s->rows('#__banner_clients', array_values(array_unique(array_filter(array_map(static fn ($b) => (int) $b['cid'], $banners)))), ['id', 'track_impressions', 'track_clicks']);
        $off     = ['impressions' => [], 'clicks' => []];

        foreach ($banners as $b) {
            $eff = $this->s->effectiveTracking($b, $clients[(int) $b['cid']] ?? null);

            foreach ($off as $k => $_) {
                if (!$eff[$k]['enabled']) {
                    $off[$k][] = ['id' => (int) $b['id'], 'name' => (string) $b['name']];
                }
            }
        }

        $totals = ['impressions' => array_sum(array_column($series, 'impressions')), 'clicks' => array_sum(array_column($series, 'clicks'))];

        return [
            'from'         => $from,
            'to'           => $to,
            'group'        => $group,
            'totals'       => $totals + ['ctr_percent' => $totals['impressions'] > 0 ? round(100 * $totals['clicks'] / $totals['impressions'], 2) : null],
            'series'       => $group === 'total' ? null : $series,
            'tracking_off' => array_filter($off),
            'note'         => 'Tracking dates are stored in UTC, per hour.',
        ];
    }

    // ----------------------------------------------------------------- écriture

    /** Création (id 0) ou modification. */
    private function save(int $id, array $a): array
    {
        $model   = $this->s->model('Banner');
        $current = [];

        if ($id > 0) {
            $item = $model->getItem($id);

            if (!$item || !(int) ($item->id ?? 0)) {
                throw new McpToolException('Banner #' . $id . ' not found.');
            }

            $current = FormGate::itemData($item);
        }

        $input = ['id' => $id];

        if ($id === 0) {
            $name = trim((string) ($a['name'] ?? ''));

            if ($name === '') {
                throw new McpToolException('"name" is required.');
            }

            $input += [
                'catid' => $this->s->resolveCatid($a), 'state' => 1, 'type' => 0, 'language' => '*', 'sticky' => 0,
                'purchase_type' => -1, 'track_impressions' => -1, 'track_clicks' => -1, 'imptotal' => 0, 'params' => [],
                // Colonnes NOT NULL sans défaut, toujours postées par le formulaire du back-office.
                'description' => '', 'custombannercode' => '', 'clickurl' => '', 'metakey' => '', 'metakey_prefix' => '', 'own_prefix' => 0,
            ];
        } elseif (\array_key_exists('catid', $a)) {
            $input['catid'] = $this->s->resolveCatid($a);

            if ($input['catid'] !== (int) $current['catid'] && !$this->user->authorise('core.create', self::EXT . '.category.' . $input['catid'])) {
                throw new McpToolException('Insufficient permission: core.create on category ' . $input['catid'] . ' is required to move the banner there.');
            }
        }

        foreach (['name' => 'name', 'alias' => 'alias', 'click_url' => 'clickurl', 'description' => 'description', 'language' => 'language', 'keywords' => 'metakey'] as $arg => $col) {
            if (\array_key_exists($arg, $a)) {
                $input[$col] = trim((string) $a[$arg]);
            }
        }

        if (\array_key_exists('type', $a)) {
            $input['type'] = $this->s->code(BannersSupport::TYPES, $a['type'], 'type');
        }

        if (\array_key_exists('published', $a)) {
            $input['state'] = $a['published'] ? 1 : 0;
        }

        if (\array_key_exists('sticky', $a)) {
            $input['sticky'] = $a['sticky'] ? 1 : 0;
        }

        if (\array_key_exists('client_id', $a)) {
            $cid = (int) $a['client_id'];

            if ($cid > 0 && !$this->s->rows('#__banner_clients', [$cid], ['id'])) {
                throw new McpToolException('Client #' . $cid . ' not found (see list_banner_clients).');
            }

            $input['cid'] = $cid;
        }

        if (\array_key_exists('max_impressions', $a)) {
            $input['imptotal'] = max(0, (int) $a['max_impressions']);
        }

        if (\array_key_exists('purchase_type', $a)) {
            $input['purchase_type'] = $this->s->code(BannersSupport::PURCHASE, $a['purchase_type'], 'purchase_type');
        }

        foreach (['track_impressions', 'track_clicks'] as $k) {
            if (\array_key_exists($k, $a)) {
                $input[$k] = $this->s->code(BannersSupport::TRACK, $a[$k], $k);
            }
        }

        $unfiltered = [];

        foreach (['publish_up', 'publish_down'] as $k) {
            if (\array_key_exists($k, $a)) {
                $input[$k]    = $this->s->utcDate($a[$k]);
                $unfiltered[] = $k;
            }
        }

        // Image : groupe params (imageurl/width/height/alt), fusionné clé à clé par FormGate.
        $img = [];

        foreach (['image' => 'imageurl', 'width' => 'width', 'height' => 'height', 'alt' => 'alt'] as $arg => $key) {
            if (\array_key_exists($arg, $a)) {
                $img[$key] = $arg === 'width' || $arg === 'height' ? ((string) $a[$arg] === '' ? '' : (string) (int) $a[$arg]) : trim((string) $a[$arg]);
            }
        }

        if ($img !== []) {
            $input['params'] = $img;
        }

        // Code personnalisé : brut au back-office (filter="raw") ; via MCP réservé au HTML non filtré.
        if (\array_key_exists('custom_code', $a)) {
            if (!$this->s->canUseRawHtml()) {
                throw new McpToolException('Custom code is inserted as is in the pages: only users allowed to publish unfiltered '
                    . 'HTML (Global Configuration → Text Filters: "No Filtering") can set it. Use an image banner instead.');
            }

            $input['custombannercode'] = (string) $a['custom_code'];
        }

        // Cohérence du type : une bannière image a une image, une bannière personnalisée a du code.
        $type = (int) ($input['type'] ?? $current['type'] ?? 0);
        $hasImage = trim((string) (($input['params']['imageurl'] ?? null) ?? ((array) ($current['params'] ?? []))['imageurl'] ?? '')) !== '';
        $hasCode  = trim((string) ($input['custombannercode'] ?? $current['custombannercode'] ?? '')) !== '';

        if ($type === 0 && !$hasImage) {
            throw new McpToolException('An image banner needs "image" (path under images/, e.g. images/banners/spring.jpg).');
        }

        if ($type === 1 && !$hasCode) {
            throw new McpToolException('A custom banner needs "custom_code".');
        }

        $form = $this->s->form($model, $input + $current, 'banner');
        $gate = FormGate::apply($form, $input, $current, ['id'], null, $unfiltered);

        if (!$model->save($gate['data'])) {
            throw new McpToolException(($id ? 'Update' : 'Create') . ' failed: ' . $this->s->errors($model));
        }

        $newId = $id ?: (int) $model->getState('banner.id');
        $out   = ['ok' => true] + $this->getBanner($newId);

        if ($gate['ignored']) {
            $out['ignored'] = $gate['ignored'];
        }

        return $out;
    }

    private function setState(array $a): array
    {
        $ids = $this->s->ids($a['ids'] ?? null);

        if (!\array_key_exists('state', $a) && !\array_key_exists('sticky', $a)) {
            throw new McpToolException('Pass "state" and/or "sticky".');
        }

        $before = $this->s->rows('#__banners', $ids, ['id', 'state', 'sticky', 'catid']);
        $out    = ['ok' => true];

        if ($missing = array_values(array_diff($ids, array_keys($before)))) {
            $out['not_found'] = $missing;
        }

        $ids   = array_keys($before);
        $model = $this->s->model('Banner');

        if (\array_key_exists('state', $a) && $ids !== []) {
            $state = $this->s->code(BannersSupport::STATES, $a['state'], 'state');
            $pks   = $ids;
            ActionLogContext::run(self::EXT, static function () use ($model, &$pks, $state) {
                return $model->publish($pks, $state);
            });
            $out['state'] = $this->diff($ids, $before, 'state', $state, BannersSupport::STATES[$state]);
        }

        if (\array_key_exists('sticky', $a) && $ids !== []) {
            $value = $a['sticky'] ? 1 : 0;
            $pks   = $ids;
            ActionLogContext::run(self::EXT, static function () use ($model, &$pks, $value) {
                return $model->stick($pks, $value);
            });
            $out['sticky'] = $this->diff($ids, $before, 'sticky', $value, (bool) $value);
        }

        return $out;
    }

    /** Compare l'état en base avant/après : changés, déjà dans l'état, refusés (droits). */
    private function diff(array $ids, array $before, string $col, int $target, mixed $label): array
    {
        $after = $this->s->rows('#__banners', $ids, ['id', $col]);

        return [
            'value'   => $label,
            'changed' => array_values(array_filter($ids, static fn ($i) => (int) $before[$i][$col] !== $target && (int) ($after[$i][$col] ?? -99) === $target)),
            'already' => array_values(array_filter($ids, static fn ($i) => (int) $before[$i][$col] === $target)),
            'refused' => array_values(array_filter($ids, static fn ($i) => (int) $before[$i][$col] !== $target && (int) ($after[$i][$col] ?? -99) !== $target)),
        ];
    }

    private function resetPreview(array $a): array
    {
        $rows = $this->s->rows('#__banners', $this->s->ids($a['ids'] ?? null), ['id', 'name', 'impmade', 'clicks']);
        $db   = $this->s->db();
        $hist = $rows === [] ? 0 : (int) $db->setQuery(
            $db->getQuery(true)->select('COALESCE(SUM(' . $db->quoteName('count') . '), 0)')->from($db->quoteName('#__banner_tracks'))
                ->whereIn($db->quoteName('banner_id'), array_keys($rows))
        )->loadResult();

        return [
            'banners'           => array_values(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'impressions' => (int) $r['impmade'], 'clicks' => (int) $r['clicks']], $rows)),
            'tracking_history'  => !empty($a['clear_tracking']) ? $hist . ' tracked impressions/clicks will be deleted' : 'kept',
            'irreversible'      => true,
        ];
    }

    private function resetStats(array $a): array
    {
        $ids   = array_keys($this->s->rows('#__banners', $this->s->ids($a['ids'] ?? null), ['id']));
        $reset = [];
        $fail  = [];

        foreach ($ids as $id) {
            try {
                if (!$this->canEdit($this->user, $id)) {
                    throw new McpToolException('permission denied');
                }

                $model = $this->s->model('Banner');
                $cur   = FormGate::itemData($model->getItem($id));
                $data  = ['id' => $id, 'impmade' => 0, 'clicks' => 0];
                $gate  = FormGate::apply($this->s->form($model, $data + $cur, 'banner'), $data, $cur, ['id']);

                if (!$model->save($gate['data'])) {
                    throw new McpToolException($this->s->errors($model));
                }

                $reset[] = $id;
            } catch (\Throwable $e) {
                $fail[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        $cleared = 0;

        if (!empty($a['clear_tracking']) && $reset !== []) {
            $db = $this->s->db();
            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__banner_tracks'))->whereIn($db->quoteName('banner_id'), $reset))->execute();
            $cleared = (int) $db->getAffectedRows();
        }

        return ['ok' => $fail === [], 'reset' => $reset, 'failed' => $fail, 'tracking_rows_deleted' => $cleared];
    }

    private function deletePreview(array $a): array
    {
        [$trashed, $other] = $this->splitTrashed($this->s->ids($a['ids'] ?? null));

        return [
            'will_delete'         => \count($trashed),
            'banners'             => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], \array_slice(array_values($trashed), 0, 20)),
            'more'                => max(0, \count($trashed) - 20),
            'ignored_not_trashed' => $other,
            'irreversible'        => true,
        ];
    }

    private function delete(array $a): array
    {
        [$trashed, $other] = $this->splitTrashed($this->s->ids($a['ids'] ?? null));
        $deleted = [];

        if ($trashed !== []) {
            $model = $this->s->model('Banner');
            $pks   = array_keys($trashed);
            ActionLogContext::run(self::EXT, static function () use ($model, &$pks) {
                return $model->delete($pks);
            });

            $left    = array_keys($this->s->rows('#__banners', array_keys($trashed), ['id']));
            $deleted = array_values(array_diff(array_keys($trashed), $left));

            foreach ($left as $id) {
                $other[] = ['id' => $id, 'reason' => 'refused by Joomla (permissions on the category)'];
            }
        }

        return ['ok' => true, 'deleted' => $deleted, 'ignored_not_trashed' => $other];
    }

    private function createCategory(array $a): array
    {
        $title = trim((string) ($a['title'] ?? ''));

        if ($title === '') {
            throw new McpToolException('"title" is required.');
        }

        $parent = (int) ($a['parent_id'] ?? 1) ?: 1;

        if ($parent > 1 && !$this->s->isCategory($parent)) {
            throw new McpToolException('Parent #' . $parent . ' is not a Banners category.');
        }

        $data = [
            'id' => 0, 'extension' => self::EXT, 'title' => $title, 'alias' => (string) ($a['alias'] ?? ''), 'parent_id' => $parent,
            'description' => (string) ($a['description'] ?? ''), 'published' => ($a['published'] ?? true) === false ? 0 : 1,
            'language' => (string) ($a['language'] ?? '*'), 'access' => (int) Factory::getApplication()->get('access', 1),
            'params' => [], 'metadata' => [],
        ];

        Factory::getApplication()->getInput()->set('extension', self::EXT);
        $model = Factory::getApplication()->bootComponent('com_categories')->getMVCFactory()
            ->createModel('Category', 'Administrator', ['ignore_request' => true]);
        $model->setState('category.extension', self::EXT);
        $model->setState('category.type', 'other');

        FormGate::useAdminForms('com_categories');
        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the category form: ' . $this->s->errors($model));
        }

        $gate = FormGate::apply($form, $data, [], ['id', 'extension']);

        if (!$model->save($gate['data'])) {
            throw new McpToolException('Create failed: ' . $this->s->errors($model));
        }

        return ['ok' => true, 'id' => (int) $model->getState('category.id'), 'title' => $title] + ($gate['ignored'] ? ['ignored' => $gate['ignored']] : []);
    }

    // ------------------------------------------------------------------ helpers

    /** core.edit sur la catégorie de la bannière, ou core.edit.own pour son auteur. */
    private function canEdit(User $me, int $id): bool
    {
        $row = $id > 0 ? ($this->s->rows('#__banners', [$id], ['id', 'catid', 'created_by'])[$id] ?? null) : null;

        if ($row === null) {
            return $me->authorise('core.edit', self::EXT);
        }

        return FormGate::canEdit($me, self::EXT . '.category.' . (int) $row['catid'], (int) $row['created_by']);
    }

    /** @return array{0: array<int, array>, 1: list<array>} */
    private function splitTrashed(array $ids): array
    {
        $rows    = $this->s->rows('#__banners', $ids, ['id', 'name', 'state']);
        $trashed = [];
        $other   = [];

        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;

            if ($r === null) {
                $other[] = ['id' => $id, 'reason' => 'not found'];
            } elseif ((int) $r['state'] !== -2) {
                $other[] = ['id' => $id, 'reason' => 'not in the trash (' . $this->s->label(BannersSupport::STATES, $r['state']) . ')'];
            } else {
                $trashed[$id] = $r;
            }
        }

        return [$trashed, $other];
    }

    private function categoryTitle(int $id): string
    {
        $db = $this->s->db();

        return (string) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('title'))->from($db->quoteName('#__categories'))->where($db->quoteName('id') . ' = ' . $id)
        )->loadResult();
    }
}
