<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Banners\Tool;

use Joomla\CMS\User\User;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Outils MCP des clients annonceurs (com_banners, #__banner_clients) : liste, création,
 * modification. Écriture par ClientModel (admin) + FormGate, comme l'API Joomla
 * (plugins/webservices/banners, route v1/banners/clients). Pas de suppression (rare, et une
 * bannière garde la référence de son client).
 */
final class ClientTools
{
    private const EXT = BannersSupport::EXT;

    public function __construct(private User $user, private BannersSupport $s)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->register(
            'list_banner_clients',
            'List the banner clients (advertisers) with their contact, state, purchase type, tracking settings, number '
            . 'of banners and total impressions and clicks of their banners.',
            [
                'type'       => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Text in the name, contact or e-mail.'],
                    'state'  => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all'], 'description' => 'Default: all except trashed.'],
                ],
            ],
            fn (array $a) => $this->listClients($a),
            'banners.read',
            ['acl' => ['core.manage', self::EXT]]
        );

        $props = [
            'name'              => ['type' => 'string', 'description' => 'Client (company) name.'],
            'contact'           => ['type' => 'string', 'description' => 'Contact person.'],
            'email'             => ['type' => 'string'],
            'published'         => ['type' => 'boolean', 'description' => 'Default true on creation.'],
            'purchase_type'     => ['type' => 'string', 'enum' => array_values(BannersSupport::PURCHASE), 'description' => 'inherit = global option.'],
            'track_impressions' => ['type' => 'string', 'enum' => array_values(BannersSupport::TRACK), 'description' => 'inherit = global option.'],
            'track_clicks'      => ['type' => 'string', 'enum' => array_values(BannersSupport::TRACK)],
            'keywords'          => ['type' => 'string', 'description' => 'Comma-separated keywords.'],
            'notes'             => ['type' => 'string', 'description' => 'Additional information.'],
        ];

        $registry->register(
            'create_banner_client',
            'Create a banner client (advertiser). "name" and "contact" are required, as in the back-end.',
            ['type' => 'object', 'required' => ['name', 'contact'], 'properties' => $props],
            fn (array $a) => $this->save(0, $a),
            'banners.write',
            ['acl' => ['core.create', self::EXT]]
        );

        $registry->register(
            'update_banner_client',
            'Change a banner client. Only the fields you pass are changed; "published": false unpublishes it.',
            ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']] + $props],
            fn (array $a) => $this->save((int) ($a['id'] ?? 0), $a),
            'banners.write',
            ['acl' => ['core.edit', self::EXT]]
        );
    }

    /**
     * EXCEPTION SQL assumée (décision Fred, 22/11/2026) : le modèle Clients du back-office ne cherche
     * que dans le nom et ne fournit pas les totaux de bannières / impressions / clics par client.
     */
    private function listClients(array $a): array
    {
        $db = $this->s->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['c.id', 'c.name', 'c.contact', 'c.email', 'c.state', 'c.purchase_type', 'c.track_impressions', 'c.track_clicks']))
            ->select([
                'COUNT(' . $db->quoteName('b.id') . ') AS ' . $db->quoteName('banners'),
                'COALESCE(SUM(' . $db->quoteName('b.impmade') . '), 0) AS ' . $db->quoteName('impressions'),
                'COALESCE(SUM(' . $db->quoteName('b.clicks') . '), 0) AS ' . $db->quoteName('clicks'),
            ])
            ->from($db->quoteName('#__banner_clients', 'c'))
            ->join('LEFT', $db->quoteName('#__banners', 'b'), $db->quoteName('b.cid') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('b.state') . ' <> -2')
            ->group($db->quoteName(['c.id', 'c.name', 'c.contact', 'c.email', 'c.state', 'c.purchase_type', 'c.track_impressions', 'c.track_clicks']))
            ->order($db->quoteName('c.name') . ' ASC');

        $state = strtolower(trim((string) ($a['state'] ?? '')));

        if ($state === '') {
            $q->where($db->quoteName('c.state') . ' <> -2');
        } elseif ($state !== 'all') {
            $q->where($db->quoteName('c.state') . ' = ' . $this->s->code(BannersSupport::STATES, $state, 'state'));
        }

        if (($search = trim((string) ($a['search'] ?? ''))) !== '') {
            $like = $db->quote('%' . $db->escape($search, true) . '%');
            $q->where('(' . $db->quoteName('c.name') . ' LIKE ' . $like . ' OR ' . $db->quoteName('c.contact') . ' LIKE ' . $like
                . ' OR ' . $db->quoteName('c.email') . ' LIKE ' . $like . ')');
        }

        return ['clients' => array_map(fn (array $r) => [
            'id'                => (int) $r['id'],
            'name'              => (string) $r['name'],
            'contact'           => (string) $r['contact'],
            'email'             => (string) $r['email'],
            'state'             => $this->s->label(BannersSupport::STATES, $r['state']),
            'purchase_type'     => $this->s->label(BannersSupport::PURCHASE, $r['purchase_type']),
            'track_impressions' => $this->s->label(BannersSupport::TRACK, $r['track_impressions']),
            'track_clicks'      => $this->s->label(BannersSupport::TRACK, $r['track_clicks']),
            'banners'           => (int) $r['banners'],
            'impressions'       => (int) $r['impressions'],
            'clicks'            => (int) $r['clicks'],
        ], $db->setQuery($q, 0, 500)->loadAssocList())];
    }

    private function save(int $id, array $a): array
    {
        $model   = $this->s->model('Client');
        $current = [];

        if ($id > 0) {
            $item = $model->getItem($id);

            if (!$item || !(int) ($item->id ?? 0)) {
                throw new McpToolException('Client #' . $id . ' not found.');
            }

            $current = FormGate::itemData($item);
        }

        $input = ['id' => $id];

        if ($id === 0) {
            $input += ['state' => 1, 'purchase_type' => -1, 'track_impressions' => -1, 'track_clicks' => -1,
                // Colonnes NOT NULL sans défaut, toujours postées par le formulaire du back-office.
                'email' => '', 'extrainfo' => '', 'metakey' => '', 'metakey_prefix' => '', 'own_prefix' => 0];
        }

        foreach (['name' => 'name', 'contact' => 'contact', 'email' => 'email', 'keywords' => 'metakey', 'notes' => 'extrainfo'] as $arg => $col) {
            if (\array_key_exists($arg, $a)) {
                $input[$col] = trim((string) $a[$arg]);
            }
        }

        if (\array_key_exists('published', $a)) {
            $input['state'] = $a['published'] ? 1 : 0;
        }

        foreach (['purchase_type' => BannersSupport::PURCHASE, 'track_impressions' => BannersSupport::TRACK, 'track_clicks' => BannersSupport::TRACK] as $k => $map) {
            if (\array_key_exists($k, $a)) {
                $input[$k] = $this->s->code($map, $a[$k], $k);
            }
        }

        $gate = FormGate::apply($this->s->form($model, $input + $current, 'client'), $input, $current, ['id']);

        if (!$model->save($gate['data'])) {
            throw new McpToolException(($id ? 'Update' : 'Create') . ' failed: ' . $this->s->errors($model));
        }

        $newId = $id ?: (int) $model->getState('client.id');
        $row   = $this->s->rows('#__banner_clients', [$newId], ['id', 'name', 'contact', 'email', 'state', 'purchase_type', 'track_impressions', 'track_clicks'])[$newId] ?? [];
        $out   = [
            'ok'                => true,
            'id'                => $newId,
            'name'              => (string) ($row['name'] ?? ''),
            'contact'           => (string) ($row['contact'] ?? ''),
            'email'             => (string) ($row['email'] ?? ''),
            'state'             => $this->s->label(BannersSupport::STATES, $row['state'] ?? 0),
            'purchase_type'     => $this->s->label(BannersSupport::PURCHASE, $row['purchase_type'] ?? -1),
            'track_impressions' => $this->s->label(BannersSupport::TRACK, $row['track_impressions'] ?? -1),
            'track_clicks'      => $this->s->label(BannersSupport::TRACK, $row['track_clicks'] ?? -1),
        ];

        if ($gate['ignored']) {
            $out['ignored'] = $gate['ignored'];
        }

        return $out;
    }
}
