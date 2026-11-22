<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Contact\Tool;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Category as CategoryTable;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ActionLogContext;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\Pagination;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Outils MCP du composant Contacts (com_contact).
 *
 * Aligné sur l'API Joomla (plugins/webservices/contact → api/components/com_contact) :
 * champs exposés repris des vues JSON:API, écritures par ContactModel (admin) + FormGate
 * (filtres et verrous ACL du formulaire du back-office), champs personnalisés passés en
 * `com_fields` au save() (comme ContactController::preprocessSaveData), enregistrés par le
 * plugin système Fields. L'envoi d'un message par le formulaire de contact (route API
 * `contacts/form/:id`) n'est volontairement PAS exposé (risque de spam).
 *
 * Pièges : ContactModel::save() CRÉE une catégorie si `catid` n'est pas un id valide → catid
 * validé ici avant ; ACL au niveau de la catégorie (`com_contact.category.<id>`, les contacts
 * n'ont pas d'asset) ; tags réinjectés en mise à jour sinon effacés ; publish/delete/featured
 * via ActionLogContext (journal des actions).
 */
final class ContactTools
{
    private const EXT = 'com_contact';

    private const STATES = [1 => 'published', 0 => 'unpublished', 2 => 'archived', -2 => 'trashed'];

    private const MAX_IDS = 500;

    /** Champs simples du formulaire (même nom côté outil et côté Joomla). */
    private const TEXT_FIELDS = [
        'name', 'alias', 'con_position', 'email_to', 'telephone', 'mobile', 'fax', 'webpage', 'address',
        'suburb', 'postcode', 'country', 'misc', 'image', 'metadesc', 'metakey', 'language',
        'sortname1', 'sortname2', 'sortname3', 'created_by_alias',
    ];

    public function __construct(private User $user)
    {
    }

    public function register(ToolRegistry $registry): void
    {
        if (!$this->componentEnabled()) {
            return;
        }

        $registry->declareScope('contact.read', 'PLG_SENSOMCP_CONTACT_SCOPE_READ');
        $registry->declareScope('contact.write', 'PLG_SENSOMCP_CONTACT_SCOPE_WRITE');

        $read = ['core.manage', self::EXT];

        $registry->register(
            'list_contacts',
            'List the contacts of the Joomla Contacts component (paginated, default 20, max 100; "pagination.total" '
            . 'gives the full count). Filters: text search (name, alias, position, e-mail), category, state '
            . '(default: everything except trashed), featured, language, linked user account.',
            [
                'type'       => 'object',
                'properties' => [
                    'search'    => ['type' => 'string', 'description' => 'Text in the name, alias, position or e-mail. "id:N" finds one id.'],
                    'catid'     => ['type' => 'integer', 'description' => 'Category id (see list_contact_categories).'],
                    'state'     => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all'], 'description' => 'Default: all except trashed.'],
                    'featured'  => ['type' => 'boolean', 'description' => 'Only featured (true) or non-featured (false) contacts.'],
                    'language'  => ['type' => 'string', 'description' => 'Language code (e.g. fr-FR) or * for all languages.'],
                    'user_id'   => ['type' => 'integer', 'description' => 'Contacts linked to this user account.'],
                    'sort'      => ['type' => 'string', 'enum' => ['name', 'ordering', 'created', 'modified', 'id', 'hits'], 'description' => 'Default: name.'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Default: asc.'],
                    'limit'     => ['type' => 'integer', 'description' => '1-100, default 20.'],
                    'offset'    => ['type' => 'integer', 'description' => 'Default 0.'],
                ],
            ],
            fn (array $a) => $this->listContacts($a),
            'contact.read',
            ['acl' => $read]
        );

        $registry->register(
            'get_contact',
            'Get one contact with all its details: position, e-mail, phones, address, website, free text (misc), image, '
            . 'linked user account, category, state, featured, access, language, tags, custom field values, contact form '
            . 'settings and the other display options (params), metadata and the public URL.',
            [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => ['id' => ['type' => 'integer', 'description' => 'Contact id.']],
            ],
            fn (array $a) => $this->getContact((int) ($a['id'] ?? 0)),
            'contact.read',
            ['acl' => $read]
        );

        $registry->register(
            'list_contact_categories',
            'List the categories of the Contacts component (id, title, path, state, language, number of contacts). '
            . 'Use an id as "catid" when creating or moving contacts.',
            ['type' => 'object', 'properties' => new \stdClass()],
            fn (array $a) => $this->listCategories(),
            'contact.read',
            ['acl' => $read]
        );

        $contactProps = [
            'name'             => ['type' => 'string', 'description' => 'Contact name (person, team or office).'],
            'alias'            => ['type' => 'string', 'description' => 'URL alias (generated from the name if empty).'],
            'catid'            => ['type' => 'integer', 'description' => 'Category id of the Contacts component (see list_contact_categories).'],
            'con_position'     => ['type' => 'string', 'description' => 'Position / job title.'],
            'email_to'         => ['type' => 'string', 'description' => 'E-mail address (also the recipient of the contact form).'],
            'telephone'        => ['type' => 'string'],
            'mobile'           => ['type' => 'string'],
            'fax'              => ['type' => 'string'],
            'webpage'          => ['type' => 'string', 'description' => 'Website URL.'],
            'address'          => ['type' => 'string', 'description' => 'Street address.'],
            'suburb'           => ['type' => 'string', 'description' => 'City or suburb.'],
            'region'           => ['type' => 'string', 'description' => 'State, county or region of the address (Joomla column "state").'],
            'postcode'         => ['type' => 'string'],
            'country'          => ['type' => 'string'],
            'misc'             => ['type' => 'string', 'description' => 'Miscellaneous information (HTML, filtered like the back-end editor).'],
            'image'            => ['type' => 'string', 'description' => 'Image path, e.g. images/team/jane.jpg.'],
            'user_id'          => ['type' => 'integer', 'description' => 'Linked user account id (0 = none).'],
            'published'        => ['type' => 'boolean', 'description' => 'Published (default true on creation).'],
            'featured'         => ['type' => 'boolean', 'description' => 'Featured contact.'],
            'access'           => ['type' => 'integer', 'description' => 'View access level id (see list_access_levels).'],
            'language'         => ['type' => 'string', 'description' => 'Language code, or * for all (default).'],
            'tags'             => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids (replaces the current tags). Existing tags only.'],
            'metadesc'         => ['type' => 'string', 'description' => 'Meta description.'],
            'metakey'          => ['type' => 'string', 'description' => 'Meta keywords.'],
            'sortname1'        => ['type' => 'string', 'description' => 'Sort field 1 (e.g. last name).'],
            'sortname2'        => ['type' => 'string'],
            'sortname3'        => ['type' => 'string'],
            'created_by_alias' => ['type' => 'string'],
            'contact_form'     => ['type' => 'boolean', 'description' => 'Show the contact form on the contact page (params.show_email_form).'],
            'redirect'         => ['type' => 'string', 'description' => 'URL to go to after the contact form is sent (params.redirect).'],
            'params'           => ['type' => 'object', 'description' => 'Other display options by Joomla key (show_email, show_telephone, allow_vcard, show_links, linka…). Values "", "0" or "1"; "" = use the global setting. See get_contact.'],
            'fields'           => ['type' => 'object', 'description' => 'Custom field values by field name ({"field-name": value}).'],
        ];

        $registry->register(
            'create_contact',
            'Create a contact in the Joomla Contacts component, with the same checks as the back-end form (text '
            . 'filters, permissions). "catid" must be an existing Contacts category (see list_contact_categories, or '
            . 'create one with create_contact_category); if omitted and the component has a single category, that one is used.',
            ['type' => 'object', 'required' => ['name'], 'properties' => $contactProps],
            fn (array $a) => $this->createContact($a),
            'contact.write',
            // Callable : la règle core.manage des composants d'administration du registre ne s'applique
            // qu'à la forme [action, composant] → on l'exige ici explicitement.
            ['acl' => fn (User $me, array $a) => $me->authorise('core.manage', self::EXT)
                && $me->authorise('core.create', ((int) ($a['catid'] ?? 0)) > 0 ? self::EXT . '.category.' . (int) $a['catid'] : self::EXT)]
        );

        $registry->register(
            'update_contact',
            'Change an existing contact. Only the fields you pass are changed; "params" is merged key by key with the '
            . 'current display options; "tags" replaces the tags. Moving to another category requires the right to '
            . 'create contacts in that category.',
            ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'description' => 'Contact id.']] + $contactProps],
            fn (array $a) => $this->updateContact($a),
            'contact.write',
            ['acl' => fn (User $me, array $a) => $me->authorise('core.manage', self::EXT) && $this->canEdit($me, (int) ($a['id'] ?? 0))]
        );

        $registry->register(
            'set_contact_state',
            'Publish, unpublish, archive or trash contacts, and/or set or remove the featured flag (max '
            . self::MAX_IDS . ' ids). Permissions are checked per category, as in the back-end; refused ids are reported.',
            [
                'type'       => 'object',
                'required'   => ['ids'],
                'properties' => [
                    'ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Contact ids.'],
                    'state'    => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed'], 'description' => 'New state.'],
                    'featured' => ['type' => 'boolean', 'description' => 'Featured flag.'],
                ],
            ],
            fn (array $a) => $this->setState($a),
            'contact.write',
            ['acl' => ['core.edit.state', self::EXT]]
        );

        $registry->register(
            'delete_contacts',
            'Permanently delete contacts that are in the trash (max ' . self::MAX_IDS . ' ids), as in the Joomla '
            . 'back-end: trash them first with set_contact_state. Contacts not in the trash are listed and left untouched. Irreversible.',
            [
                'type'       => 'object',
                'required'   => ['ids'],
                'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids of trashed contacts.']],
            ],
            fn (array $a) => $this->deleteContacts($a),
            'contact.write',
            ['risk' => 'danger', 'acl' => ['core.delete', self::EXT], 'confirm' => fn (array $a) => $this->deletePreview($a)]
        );

        $registry->register(
            'create_contact_category',
            'Create a category in the Contacts component (needed before the first contact, or to organise them).',
            [
                'type'       => 'object',
                'required'   => ['title'],
                'properties' => [
                    'title'       => ['type' => 'string'],
                    'alias'       => ['type' => 'string'],
                    'parent_id'   => ['type' => 'integer', 'description' => 'Parent Contacts category id (default: top level).'],
                    'description' => ['type' => 'string', 'description' => 'HTML description.'],
                    'published'   => ['type' => 'boolean', 'description' => 'Default true.'],
                    'language'    => ['type' => 'string', 'description' => 'Language code, or * (default).'],
                    'access'      => ['type' => 'integer', 'description' => 'View access level id.'],
                ],
            ],
            fn (array $a) => $this->createCategory($a),
            'contact.write',
            ['acl' => ['core.create', self::EXT]]
        );
    }

    // ------------------------------------------------------------------ lecture

    /**
     * EXCEPTION SQL assumée (décision Fred, 22/11/2026) : le modèle Contacts du back-office ne sait pas
     * chercher dans le poste / l'e-mail, filtrer par utilisateur lié, trier par clics ou modification, ni
     * lister « tout sauf la corbeille » (archivés inclus) ; ses filtres ne s'étendent pas sans casser la
     * pagination. Lecture seule ; toutes les écritures passent par ContactModel.
     */
    private function listContacts(array $a): array
    {
        [$offset, $limit] = Pagination::bounds($a, 20, 100);
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(
                ['a.id', 'a.name', 'a.alias', 'a.con_position', 'a.email_to', 'a.telephone', 'a.mobile', 'a.catid', 'c.title',
                 'a.published', 'a.featured', 'a.language', 'a.access', 'a.user_id', 'a.modified', 'a.hits'],
                ['id', 'name', 'alias', 'con_position', 'email_to', 'telephone', 'mobile', 'catid', 'category',
                 'published', 'featured', 'language', 'access', 'user_id', 'modified', 'hits']
            ))
            ->from($db->quoteName('#__contact_details', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'));

        $state = strtolower(trim((string) ($a['state'] ?? '')));

        if ($state === '') {
            $q->where($db->quoteName('a.published') . ' <> -2');
        } elseif ($state !== 'all') {
            $q->where($db->quoteName('a.published') . ' = ' . $this->stateCode($state));
        }

        $search = trim((string) ($a['search'] ?? ''));

        if (stripos($search, 'id:') === 0) {
            $q->where($db->quoteName('a.id') . ' = ' . (int) substr($search, 3));
        } elseif ($search !== '') {
            $like = '%' . $db->escape($search, true) . '%';
            $q->where('(' . implode(' OR ', array_map(
                static fn ($c) => $db->quoteName('a.' . $c) . ' LIKE ' . $db->quote($like),
                ['name', 'alias', 'con_position', 'email_to']
            )) . ')');
        }

        foreach (['catid', 'user_id'] as $k) {
            if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) {
                $q->where($db->quoteName('a.' . $k) . ' = ' . (int) $a[$k]);
            }
        }

        if (\array_key_exists('featured', $a) && $a['featured'] !== null) {
            $q->where($db->quoteName('a.featured') . ' = ' . ($a['featured'] ? 1 : 0));
        }

        if (($a['language'] ?? '') !== '') {
            $q->where($db->quoteName('a.language') . ' = ' . $db->quote((string) $a['language']));
        }

        $sorts = ['name' => 'a.name', 'ordering' => 'a.ordering', 'created' => 'a.created', 'modified' => 'a.modified', 'id' => 'a.id', 'hits' => 'a.hits'];
        $sort  = $sorts[strtolower((string) ($a['sort'] ?? 'name'))] ?? 'a.name';
        $dir   = strtolower((string) ($a['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $q->order($db->quoteName($sort) . ' ' . $dir)->order($db->quoteName('a.id') . ' ASC');

        return Pagination::envelope($db, $q, $offset, $limit, 'contacts', fn (array $r) => [
            'id'           => (int) $r['id'],
            'name'         => (string) $r['name'],
            'alias'        => (string) $r['alias'],
            'position'     => (string) $r['con_position'],
            'email'        => (string) $r['email_to'],
            'telephone'    => (string) $r['telephone'],
            'mobile'       => (string) $r['mobile'],
            'catid'        => (int) $r['catid'],
            'category'     => (string) $r['category'],
            'state'        => self::STATES[(int) $r['published']] ?? (string) $r['published'],
            'featured'     => (bool) $r['featured'],
            'language'     => (string) $r['language'],
            'access'       => (int) $r['access'],
            'user_id'      => (int) $r['user_id'],
            'modified'     => (string) $r['modified'],
            'hits'         => (int) $r['hits'],
        ]);
    }

    private function getContact(int $id): array
    {
        $item = $id > 0 ? $this->model()->getItem($id) : null;

        if (!$item || !(int) ($item->id ?? 0)) {
            throw new McpToolException('Contact #' . $id . ' not found.');
        }

        $params = $item->params instanceof Registry ? $item->params->toArray() : (new Registry($item->params ?? '{}'))->toArray();
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null && $v !== []);

        $out = [
            'id'               => (int) $item->id,
            'name'             => (string) $item->name,
            'alias'            => (string) $item->alias,
            'catid'            => (int) $item->catid,
            'category'         => $this->categoryTitle((int) $item->catid),
            'state'            => self::STATES[(int) $item->published] ?? (string) $item->published,
            'featured'         => (bool) $item->featured,
            'access'           => (int) $item->access,
            'language'         => (string) $item->language,
        ];

        foreach (['con_position', 'email_to', 'telephone', 'mobile', 'fax', 'webpage', 'address', 'suburb', 'state', 'postcode',
                  'country', 'misc', 'image', 'sortname1', 'sortname2', 'sortname3', 'metadesc', 'metakey', 'created_by_alias'] as $k) {
            $out[$k === 'state' ? 'region' : $k] = (string) ($item->{$k} ?? '');
        }

        $out['user'] = (int) $item->user_id > 0 ? $this->userSummary((int) $item->user_id) : null;
        $out['contact_form'] = [
            'enabled'  => (string) ($params['show_email_form'] ?? '') === '' ? 'global setting' : (bool) $params['show_email_form'],
            'redirect' => (string) ($params['redirect'] ?? ''),
        ];
        $out['params']   = $params;
        $out['metadata'] = array_filter((array) ($item->metadata ?? []), static fn ($v) => $v !== '' && $v !== null);
        $out['tags']     = $this->tagSummary($item);
        $out['fields']   = $this->fieldValues($item);
        $out['created']  = (string) ($item->created ?? '');
        $out['modified'] = (string) ($item->modified ?? '');
        $out['hits']     = (int) ($item->hits ?? 0);
        $out['url']      = $this->publicUrl((int) $item->id, (int) $item->catid, (string) $item->language);

        return $out;
    }

    /**
     * Catégories com_contact (hors corbeille) via le modèle « Categories » de com_categories (J5/J6).
     * Le nombre de contacts vient de son countItems() (comptes par état fournis par le composant) :
     * publiés + dépubliés + archivés, comme avant (corbeille exclue).
     */
    private function listCategories(): array
    {
        $model = Factory::getApplication()->bootComponent('com_categories')
            ->getMVCFactory()->createModel('Categories', 'Administrator', ['ignore_request' => true]);
        $model->setState('filter.extension', self::EXT);
        $model->setState('filter.published', '*');
        $model->setState('list.ordering', 'a.lft');
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);

        $out = [];

        foreach ((array) $model->getItems() as $c) {
            if ((int) $c->published === -2) {
                continue;
            }

            $out[] = [
                'id'        => (int) $c->id,
                'title'     => (string) $c->title,
                'alias'     => (string) $c->alias,
                'path'      => (string) $c->path,
                'level'     => (int) $c->level,
                'parent_id' => (int) $c->parent_id,
                'state'     => self::STATES[(int) $c->published] ?? (string) $c->published,
                'language'  => (string) $c->language,
                'access'    => (int) $c->access,
                'contacts'  => (int) ($c->count_published ?? 0) + (int) ($c->count_unpublished ?? 0) + (int) ($c->count_archived ?? 0),
            ];
        }

        return ['categories' => $out];
    }

    // ----------------------------------------------------------------- écriture

    private function createContact(array $a): array
    {
        $name = trim((string) ($a['name'] ?? ''));

        if ($name === '') {
            throw new McpToolException('"name" is required.');
        }

        $catid = $this->resolveCatid($a);
        $data  = [
            'id'        => 0,
            'name'      => $name,
            'catid'     => $catid,
            'published' => 1,
            'featured'  => 0,
            'language'  => '*',
            'access'    => (int) Factory::getApplication()->get('access', 1),
            'params'    => [],
            'metadata'  => [],
        ];
        $data = $this->mapArgs($a, $data, []);

        $model   = $this->model();
        $form    = $this->form($model, $data);
        $gate    = FormGate::apply($form, $data, [], ['id', 'tags']);
        $saveRaw = $gate['data'];

        if (!$model->save($saveRaw)) {
            throw new McpToolException('Create failed: ' . $this->modelErrors($model));
        }

        $id = (int) $model->getState('contact.id');

        return $this->writeResult($id, $gate['ignored']);
    }

    private function updateContact(array $a): array
    {
        $id      = (int) ($a['id'] ?? 0);
        $model   = $this->model();
        $current = $id > 0 ? $model->getItem($id) : null;

        if (!$current || !(int) ($current->id ?? 0)) {
            throw new McpToolException('Contact #' . $id . ' not found.');
        }

        $input = ['id' => $id];

        if (\array_key_exists('catid', $a)) {
            $input['catid'] = $this->resolveCatid($a);

            if ($input['catid'] !== (int) $current->catid
                && !$this->user->authorise('core.create', self::EXT . '.category.' . $input['catid'])) {
                throw new McpToolException('Insufficient permission: core.create on category ' . $input['catid'] . ' is required to move the contact there.');
            }
        }

        $currentData = FormGate::itemData($current);
        $input       = $this->mapArgs($a, $input, (array) ($currentData['params'] ?? []));

        // Tags : sans argument, on réinjecte les tags actuels (sinon l'observateur Tags les efface).
        if (!\array_key_exists('tags', $input)) {
            $input['tags'] = $this->currentTagIds($current);
        }

        $form = $this->form($model, $input + $currentData);
        $gate = FormGate::apply($form, $input, $currentData, ['id', 'tags']);

        if (!$model->save($gate['data'])) {
            throw new McpToolException('Update failed: ' . $this->modelErrors($model));
        }

        return $this->writeResult($id, $gate['ignored']);
    }

    private function setState(array $a): array
    {
        $ids = $this->ids($a['ids'] ?? null);

        if (!\array_key_exists('state', $a) && !\array_key_exists('featured', $a)) {
            throw new McpToolException('Pass "state" and/or "featured".');
        }

        $before = $this->rowsById($ids);
        $model  = $this->model();
        $out    = ['ok' => true];

        foreach (array_diff($ids, array_keys($before)) as $missing) {
            $out['not_found'][] = $missing;
        }

        $ids = array_keys($before);

        if (\array_key_exists('state', $a) && $ids !== []) {
            $state = $this->stateCode((string) $a['state']);
            $pks   = $ids;
            ActionLogContext::run(self::EXT, static function () use ($model, &$pks, $state) {
                return $model->publish($pks, $state);
            });
            $after = $this->rowsById($ids);
            $out['state'] = [
                'value'   => self::STATES[$state],
                'changed' => array_values(array_filter($ids, static fn ($i) => (int) $before[$i]['published'] !== $state && (int) ($after[$i]['published'] ?? -99) === $state)),
                'already' => array_values(array_filter($ids, static fn ($i) => (int) $before[$i]['published'] === $state)),
                'refused' => array_values(array_filter($ids, static fn ($i) => (int) $before[$i]['published'] !== $state && (int) ($after[$i]['published'] ?? -99) !== $state)),
            ];
        }

        if (\array_key_exists('featured', $a) && $ids !== []) {
            $value = $a['featured'] ? 1 : 0;
            // featured() ne vérifie pas l'ACL par catégorie : on filtre comme canEditState().
            $allowed = array_values(array_filter($ids, fn ($i) => $this->user->authorise('core.edit.state', self::EXT . '.category.' . (int) $before[$i]['catid'])));

            if ($allowed !== []) {
                ActionLogContext::run(self::EXT, static function () use ($model, $allowed, $value) {
                    return $model->featured($allowed, $value);
                });
            }

            $after = $this->rowsById($ids);
            $out['featured'] = [
                'value'   => (bool) $value,
                'changed' => array_values(array_filter($allowed, static fn ($i) => (int) $before[$i]['featured'] !== $value && (int) ($after[$i]['featured'] ?? -1) === $value)),
                'refused' => array_values(array_diff($ids, $allowed)),
            ];
        }

        return $out;
    }

    private function deletePreview(array $a): array
    {
        [$trashed, $other] = $this->splitTrashed($this->ids($a['ids'] ?? null));

        return [
            'will_delete'         => \count($trashed),
            'contacts'            => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'catid' => (int) $r['catid']], \array_slice(array_values($trashed), 0, 20)),
            'more'                => max(0, \count($trashed) - 20),
            'ignored_not_trashed' => $other,
            'irreversible'        => true,
        ];
    }

    private function deleteContacts(array $a): array
    {
        [$trashed, $other] = $this->splitTrashed($this->ids($a['ids'] ?? null));
        $deleted = [];

        if ($trashed !== []) {
            $model = $this->model();
            $pks   = array_keys($trashed);
            ActionLogContext::run(self::EXT, static function () use ($model, &$pks) {
                return $model->delete($pks);
            });

            $left    = array_keys($this->rowsById(array_keys($trashed)));
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

        if ($parent > 1 && !$this->isContactCategory($parent)) {
            throw new McpToolException('Parent #' . $parent . ' is not a Contacts category.');
        }

        $data = [
            'id'          => 0,
            'extension'   => self::EXT,
            'title'       => $title,
            'alias'       => (string) ($a['alias'] ?? ''),
            'parent_id'   => $parent,
            'description' => (string) ($a['description'] ?? ''),
            'published'   => ($a['published'] ?? true) === false ? 0 : 1,
            'language'    => (string) ($a['language'] ?? '*'),
            'access'      => (int) ($a['access'] ?? Factory::getApplication()->get('access', 1)),
            'params'      => [],
            'metadata'    => [],
        ];

        // CategoryModel lit l'extension dans l'input (absent en contexte API).
        Factory::getApplication()->getInput()->set('extension', self::EXT);
        $model = Factory::getApplication()->bootComponent('com_categories')->getMVCFactory()
            ->createModel('Category', 'Administrator', ['ignore_request' => true]);
        $model->setState('category.extension', self::EXT);
        $model->setState('category.type', 'other');

        FormGate::useAdminForms('com_categories');
        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the category form: ' . $this->modelErrors($model));
        }

        $gate = FormGate::apply($form, $data, [], ['id', 'extension']);

        if (!$model->save($gate['data'])) {
            throw new McpToolException('Create failed: ' . $this->modelErrors($model));
        }

        $id  = (int) $model->getState('category.id');
        $out = ['ok' => true, 'id' => $id, 'title' => $title];

        if ($gate['ignored']) {
            $out['ignored'] = $gate['ignored'];
        }

        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Arguments de l'outil → données du formulaire Joomla.
     *
     * @param array $currentParams params actuels (mise à jour : fusion clé à clé)
     */
    private function mapArgs(array $a, array $data, array $currentParams): array
    {
        foreach (self::TEXT_FIELDS as $k) {
            if (\array_key_exists($k, $a) && $k !== 'name') {
                $data[$k] = (string) $a[$k];
            }
        }

        if (\array_key_exists('name', $a)) {
            $data['name'] = trim((string) $a['name']);
        }

        // « state » d'un contact Joomla = région de l'adresse ; exposé « region » pour éviter la confusion avec l'état de publication.
        if (\array_key_exists('region', $a)) {
            $data['state'] = (string) $a['region'];
        }

        foreach (['user_id', 'access'] as $k) {
            if (\array_key_exists($k, $a)) {
                $data[$k] = (int) $a[$k];
            }
        }

        if (\array_key_exists('published', $a)) {
            $data['published'] = $a['published'] ? 1 : 0;
        }

        if (\array_key_exists('featured', $a)) {
            $data['featured'] = $a['featured'] ? 1 : 0;
        }

        if (\array_key_exists('tags', $a)) {
            $data['tags'] = $this->validTagIds((array) $a['tags']);
        }

        $params = \is_array($a['params'] ?? null) ? $a['params'] : (\is_string($a['params'] ?? null) ? (array) json_decode($a['params'], true) : []);

        if (\array_key_exists('contact_form', $a)) {
            $params['show_email_form'] = $a['contact_form'] ? '1' : '0';
        }

        if (\array_key_exists('redirect', $a)) {
            $params['redirect'] = (string) $a['redirect'];
        }

        if ($params !== []) {
            $data['params'] = array_map(static fn ($v) => \is_bool($v) ? ($v ? '1' : '0') : $v, $params) + $currentParams;
        }

        $fields = \is_array($a['fields'] ?? null) ? $a['fields'] : (\is_string($a['fields'] ?? null) ? (array) json_decode($a['fields'], true) : []);

        if ($fields !== []) {
            $data['com_fields'] = $this->fieldsInput($fields);
        }

        return $data;
    }

    /** Valeurs de champs perso par nom → com_fields (comme ContactController::preprocessSaveData de l'API). */
    private function fieldsInput(array $fields): array
    {
        $known = [];

        foreach (FieldsHelper::getFields(self::EXT . '.contact') as $field) {
            $known[(string) $field->name] = true;
        }

        $unknown = array_diff(array_keys($fields), array_keys($known));

        if ($unknown !== []) {
            throw new McpToolException('Unknown custom field(s): ' . implode(', ', $unknown)
                . '. Available: ' . (implode(', ', array_keys($known)) ?: 'none') . '.');
        }

        return $fields;
    }

    private function fieldValues(object $item): array
    {
        $out = [];

        try {
            foreach (FieldsHelper::getFields(self::EXT . '.contact', $item, true) as $field) {
                $out[] = [
                    'name'  => (string) $field->name,
                    'label' => (string) $field->label,
                    'type'  => (string) $field->type,
                    'value' => $field->apivalue ?? $field->rawvalue ?? null,
                ];
            }
        } catch (\Throwable) {
            // Fields désactivé ou indisponible : pas de valeurs.
        }

        return $out;
    }

    /** catid fourni (validé) ou unique catégorie du composant. */
    private function resolveCatid(array $a): int
    {
        $catid = (int) ($a['catid'] ?? 0);

        if ($catid > 0) {
            if (!$this->isContactCategory($catid)) {
                throw new McpToolException('Category #' . $catid . ' is not a Contacts category (see list_contact_categories).');
            }

            return $catid;
        }

        $cats = array_filter($this->listCategories()['categories'], static fn ($c) => $c['state'] === 'published');

        if (\count($cats) === 1) {
            return (int) reset($cats)['id'];
        }

        throw new McpToolException($cats === []
            ? 'The Contacts component has no published category: create one with create_contact_category.'
            : '"catid" is required: the Contacts component has several categories (see list_contact_categories).');
    }

    /** Catégorie (table Category du cœur) de l'extension com_contact ? null si absente. */
    private function category(int $id): ?CategoryTable
    {
        $table = new CategoryTable(Factory::getContainer()->get(DatabaseInterface::class));

        return ($id > 0 && $table->load($id)) ? $table : null;
    }

    private function isContactCategory(int $id): bool
    {
        return (string) ($this->category($id)->extension ?? '') === self::EXT;
    }

    /** Droit d'édition d'un contact : core.edit sur sa catégorie, ou core.edit.own pour son auteur. */
    private function canEdit(User $me, int $id): bool
    {
        $row = $id > 0 ? ($this->rowsById([$id])[$id] ?? null) : null;

        if ($row === null) {
            return $me->authorise('core.edit', self::EXT);
        }

        return FormGate::canEdit($me, self::EXT . '.category.' . (int) $row['catid'], (int) $row['created_by']);
    }

    private function writeResult(int $id, array $ignored): array
    {
        $row = $this->rowsById([$id])[$id] ?? [];
        $out = [
            'ok'       => true,
            'id'       => $id,
            'name'     => (string) ($row['name'] ?? ''),
            'alias'    => (string) ($row['alias'] ?? ''),
            'catid'    => (int) ($row['catid'] ?? 0),
            'state'    => self::STATES[(int) ($row['published'] ?? 0)] ?? null,
            'featured' => (bool) ($row['featured'] ?? 0),
            'url'      => $this->publicUrl($id, (int) ($row['catid'] ?? 0), (string) ($row['language'] ?? '*')),
        ];

        if ($ignored) {
            $out['ignored'] = $ignored;
        }

        return $out;
    }

    private function publicUrl(int $id, int $catid, string $language): string
    {
        try {
            $route = \Joomla\Component\Contact\Site\Helper\RouteHelper::getContactRoute($id, $catid, $language);

            return Route::link('site', $route, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return '';
        }
    }

    private function tagSummary(object $item): array
    {
        $ids = $this->currentTagIds($item);
        $out = [];

        // TagsHelper::getTags() (J5/J6) : id => titre des tags existants, tous états.
        foreach ($ids ? (new TagsHelper())->getTags(array_map('intval', $ids)) : [] as $tid => $title) {
            $out[] = ['id' => (int) $tid, 'title' => (string) $title];
        }

        return $out;
    }

    /** @return string[] */
    private function currentTagIds(object $item): array
    {
        $tags = $item->tags ?? null;
        $csv  = \is_object($tags) ? (string) ($tags->tags ?? '') : (\is_string($tags) ? $tags : '');

        return array_values(array_filter(explode(',', $csv), static fn ($v) => (int) $v > 0));
    }

    /** Ids de tags existants seulement (une chaîne ferait créer un tag « #new# » par Joomla). */
    private function validTagIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 1)));

        if ($ids === []) {
            return [];
        }

        // Table Tag de com_tags (J5/J6) : tag existant et hors corbeille.
        $table = Factory::getApplication()->bootComponent('com_tags')->getMVCFactory()->createTable('Tag', 'Administrator');
        $found = [];

        foreach ($ids as $tid) {
            if ($table->load($tid) && (int) $table->published !== -2) {
                $found[] = $tid;
            }
        }

        $missing = array_diff($ids, $found);

        if ($missing !== []) {
            throw new McpToolException('Unknown tag id(s): ' . implode(', ', $missing) . ' (tags must exist; see list_tags).');
        }

        return array_map('strval', $ids);
    }

    private function userSummary(int $id): ?array
    {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);

        return (int) $user->id === $id
            ? ['id' => $id, 'name' => (string) $user->name, 'username' => (string) $user->username]
            : ['id' => $id, 'missing' => true];
    }

    private function categoryTitle(int $id): string
    {
        return (string) ($this->category($id)->title ?? '');
    }

    /** @return array<int, array> */
    private function rowsById(array $ids): array
    {
        // Table du modèle Contact (J5/J6), une ligne par id (≤ MAX_IDS).
        $table = $this->model()->getTable();
        $out   = [];

        foreach ($ids as $id) {
            if ($table->load((int) $id)) {
                $out[(int) $id] = [
                    'id'         => (int) $table->id,
                    'name'       => (string) $table->name,
                    'alias'      => (string) $table->alias,
                    'catid'      => (int) $table->catid,
                    'published'  => (int) $table->published,
                    'featured'   => (int) $table->featured,
                    'language'   => (string) $table->language,
                    'created_by' => (int) $table->created_by,
                ];
            }
        }

        return $out;
    }

    /** @return array{0: array<int, array>, 1: list<array>} */
    private function splitTrashed(array $ids): array
    {
        $rows    = $this->rowsById($ids);
        $trashed = [];
        $other   = [];

        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;

            if ($r === null) {
                $other[] = ['id' => $id, 'reason' => 'not found'];
            } elseif ((int) $r['published'] !== -2) {
                $other[] = ['id' => $id, 'reason' => 'not in the trash (' . (self::STATES[(int) $r['published']] ?? $r['published']) . ')'];
            } else {
                $trashed[$id] = $r;
            }
        }

        return [$trashed, $other];
    }

    private function stateCode(string $state): int
    {
        $code = array_search(strtolower(trim($state)), self::STATES, true);

        if ($code === false) {
            throw new McpToolException('Invalid state "' . $state . '". Use published, unpublished, archived or trashed.');
        }

        return (int) $code;
    }

    /** @return int[] */
    private function ids(mixed $ids): array
    {
        if (\is_string($ids)) {
            $ids = json_decode($ids, true) ?? preg_split('/[\s,]+/', $ids);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), static fn (int $i) => $i > 0)));

        if ($ids === []) {
            throw new McpToolException('"ids" must be a non-empty list of contact ids.');
        }

        if (\count($ids) > self::MAX_IDS) {
            throw new McpToolException('Too many ids (' . \count($ids) . '); the maximum is ' . self::MAX_IDS . ' per call.');
        }

        return $ids;
    }

    private function model()
    {
        return Factory::getApplication()->bootComponent(self::EXT)->getMVCFactory()
            ->createModel('Contact', 'Administrator', ['ignore_request' => true]);
    }

    private function form($model, array $data): Form
    {
        FormGate::useAdminForms(self::EXT);
        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the contact form: ' . $this->modelErrors($model));
        }

        return $form;
    }

    private function modelErrors($model): string
    {
        $msgs = array_filter(array_map(
            static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
            (array) $model->getErrors()
        ));

        return implode(' | ', $msgs) ?: 'unknown error';
    }

    private function componentEnabled(): bool
    {
        return ComponentHelper::isEnabled(self::EXT);
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
