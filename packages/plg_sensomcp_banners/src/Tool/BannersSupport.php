<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Banners\Tool;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\FormGate;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\McpToolException;

\defined('_JEXEC') or die;

/**
 * Logique partagée des outils com_banners : correspondances de valeurs (état, type, achat,
 * suivi), suivi effectif (bannière → client → composant), modèles admin, droits.
 */
final class BannersSupport
{
    public const EXT = 'com_banners';

    public const STATES = [1 => 'published', 0 => 'unpublished', 2 => 'archived', -2 => 'trashed'];

    public const TYPES = [0 => 'image', 1 => 'custom'];

    /** purchase_type : -1 = hériter (client pour une bannière, options globales pour un client). */
    public const PURCHASE = [-1 => 'inherit', 1 => 'unlimited', 2 => 'yearly', 3 => 'monthly', 4 => 'weekly', 5 => 'daily'];

    /** track_impressions / track_clicks : -1 = hériter. */
    public const TRACK = [-1 => 'inherit', 0 => 'no', 1 => 'yes'];

    public const MAX_IDS = 500;

    public function __construct(private User $user)
    {
    }

    public function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    public function componentEnabled(): bool
    {
        $db = $this->db();

        try {
            return (int) $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('enabled'))->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote(self::EXT))
            )->loadResult() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function params(): \Joomla\Registry\Registry
    {
        return ComponentHelper::getParams(self::EXT);
    }

    /**
     * L'utilisateur peut-il publier du HTML NON FILTRÉ ? Mêmes règles que les filtres de texte de
     * Joomla (Configuration globale → Filtres de texte) : au moins un de ses groupes en « Aucun
     * filtrage ». Le formulaire du back-office ne filtre pas le code personnalisé d'une bannière
     * (filter="raw") ; via MCP on l'exige, car un assistant peut être manipulé par un contenu.
     */
    public function canUseRawHtml(): bool
    {
        if ($this->user->authorise('core.admin')) {
            return true;
        }

        $filters = ComponentHelper::getParams('com_config')->get('filters');

        if (!$filters) {
            return false;
        }

        foreach (Access::getGroupsByUser((int) $this->user->id) as $gid) {
            $f = $filters->{$gid} ?? null;

            if ($f && strtoupper((string) ($f->filter_type ?? '')) === 'NONE') {
                return true;
            }
        }

        return false;
    }

    /** Valeur symbolique → code (accepte aussi le code numérique). */
    public function code(array $map, mixed $value, string $what): int
    {
        if (\is_int($value) || (\is_string($value) && preg_match('/^-?\d+$/', $value))) {
            if (\array_key_exists((int) $value, $map)) {
                return (int) $value;
            }
        } else {
            $code = array_search(strtolower(trim((string) $value)), $map, true);

            if ($code !== false) {
                return (int) $code;
            }
        }

        throw new McpToolException('Invalid ' . $what . ' "' . $value . '". Allowed: ' . implode(', ', $map) . '.');
    }

    public function label(array $map, mixed $code): string
    {
        return $map[(int) $code] ?? (string) $code;
    }

    /**
     * Suivi effectif (impressions/clics) d'une bannière : sa valeur, sinon celle de son client,
     * sinon l'option du composant.
     */
    public function effectiveTracking(array $banner, ?array $client): array
    {
        $out = [];

        foreach (['track_impressions', 'track_clicks'] as $k) {
            $v      = (int) ($banner[$k] ?? -1);
            $source = 'banner';

            if ($v < 0 && $client !== null) {
                $v      = (int) ($client[$k] ?? -1);
                $source = 'client';
            }

            if ($v < 0) {
                $v      = (int) $this->params()->get($k, 0);
                $source = 'global options';
            }

            $out[$k === 'track_impressions' ? 'impressions' : 'clicks'] = ['enabled' => $v === 1, 'from' => $source];
        }

        return $out;
    }

    /** Une catégorie com_banners existe-t-elle ? */
    public function isCategory(int $id): bool
    {
        $db = $this->db();

        return $id > 0 && (bool) $db->setQuery(
            $db->getQuery(true)->select('1')->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = ' . $id)
                ->where($db->quoteName('extension') . ' = ' . $db->quote(self::EXT))
        )->loadResult();
    }

    public function categories(): array
    {
        $db = $this->db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['c.id', 'c.title', 'c.alias', 'c.path', 'c.level', 'c.parent_id', 'c.published', 'c.language']))
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__banners', 'b') . ' WHERE ' . $db->quoteName('b.catid') . ' = '
                . $db->quoteName('c.id') . ' AND ' . $db->quoteName('b.state') . ' <> -2) AS ' . $db->quoteName('banners'))
            ->from($db->quoteName('#__categories', 'c'))
            ->where($db->quoteName('c.extension') . ' = ' . $db->quote(self::EXT))
            ->where($db->quoteName('c.published') . ' <> -2')
            ->order($db->quoteName('c.lft') . ' ASC');

        return array_map(fn (array $r) => [
            'id'        => (int) $r['id'],
            'title'     => (string) $r['title'],
            'alias'     => (string) $r['alias'],
            'path'      => (string) $r['path'],
            'level'     => (int) $r['level'],
            'parent_id' => (int) $r['parent_id'],
            'state'     => $this->label(self::STATES, $r['published']),
            'language'  => (string) $r['language'],
            'banners'   => (int) $r['banners'],
        ], $db->setQuery($q)->loadAssocList());
    }

    /** catid fourni (validé), sinon l'unique catégorie publiée. ⚠ BannerModel::save() CRÉE une catégorie si l'id est invalide. */
    public function resolveCatid(array $a): int
    {
        $catid = (int) ($a['catid'] ?? 0);

        if ($catid > 0) {
            if (!$this->isCategory($catid)) {
                throw new McpToolException('Category #' . $catid . ' is not a Banners category (see list_banner_categories).');
            }

            return $catid;
        }

        $cats = array_values(array_filter($this->categories(), static fn ($c) => $c['state'] === 'published'));

        if (\count($cats) === 1) {
            return $cats[0]['id'];
        }

        throw new McpToolException($cats === []
            ? 'The Banners component has no published category: create one with create_banner_category.'
            : '"catid" is required: the Banners component has several categories (see list_banner_categories).');
    }

    /** @return array<int, array> lignes par id */
    public function rows(string $table, array $ids, array $cols): array
    {
        if ($ids === []) {
            return [];
        }

        $db  = $this->db();
        $out = [];

        foreach ($db->setQuery(
            $db->getQuery(true)->select($db->quoteName($cols))->from($db->quoteName($table))
                ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER)
        )->loadAssocList() as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    /** @return int[] */
    public function ids(mixed $ids, string $what = 'ids'): array
    {
        if (\is_string($ids)) {
            $ids = json_decode($ids, true) ?? preg_split('/[\s,]+/', $ids);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), static fn (int $i) => $i > 0)));

        if ($ids === []) {
            throw new McpToolException('"' . $what . '" must be a non-empty list of ids.');
        }

        if (\count($ids) > self::MAX_IDS) {
            throw new McpToolException('Too many ids (' . \count($ids) . '); the maximum is ' . self::MAX_IDS . ' per call.');
        }

        return $ids;
    }

    /**
     * Date fournie (heure du site, « YYYY-MM-DD HH:MM » ou ISO 8601) → UTC SQL ; '' ou null = aucune.
     * Passée hors filtre (`user_utc` la reconvertirait une seconde fois).
     */
    public function utcDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $tz = new \DateTimeZone((string) (Factory::getApplication()->get('offset') ?: 'UTC'));

            return Factory::getDate($value, $tz)->toSql();
        } catch (\Throwable) {
            throw new McpToolException('Invalid date "' . $value . '": use YYYY-MM-DD HH:MM (site time zone) or ISO 8601.');
        }
    }

    public function model(string $name)
    {
        return Factory::getApplication()->bootComponent(self::EXT)->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
    }

    public function form($model, array $data, string $what): Form
    {
        FormGate::useAdminForms(self::EXT);
        $form = $model->getForm($data, false);

        if (!$form instanceof Form) {
            throw new McpToolException('Could not load the ' . $what . ' form: ' . $this->errors($model));
        }

        return $form;
    }

    public function errors($model): string
    {
        $msgs = array_filter(array_map(
            static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
            (array) $model->getErrors()
        ));

        return implode(' | ', $msgs) ?: 'unknown error';
    }
}
