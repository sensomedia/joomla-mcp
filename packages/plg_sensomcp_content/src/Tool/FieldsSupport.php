<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Tool;

use Joomla\CMS\Factory;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Support des VALEURS de champs personnalisés (com_fields) pour un contexte
 * com_content : `com_content.article` (articles) ou `com_content.categories`
 * (catégories). Les définitions restent du ressort du plugin fields.
 *
 * L'affectation par catégorie est gérée nativement par FieldsHelper::getFields()
 * dès qu'on lui passe un item portant `catid` : il ne renvoie que les champs
 * assignés à cette catégorie (+ ceux assignés à « toutes »). Pour une
 * catégorie, `catid` = son propre id (même convention que plg_system_fields,
 * qui pose `$item->catid = $item->id` sur le contexte categories).
 */
final class FieldsSupport
{
    public const ARTICLE  = 'com_content.article';
    public const CATEGORY = 'com_content.categories';

    public function __construct(private string $context = self::ARTICLE)
    {
    }

    /**
     * Définitions des champs applicables (pour la découverte). $catid = 0 →
     * tous les champs du contexte (sans filtre d'affectation).
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(int $catid): array
    {
        $groups = $this->groupTitles();
        $out    = [];

        foreach ($this->fieldsFor($catid) as $f) {
            $entry = [
                'name'     => $f->name,
                'title'    => $f->title,
                'type'     => $f->type,
                'group'    => $groups[$f->group_id] ?? null,
                'required' => (bool) $f->required,
                'default'  => $f->default_value,
                'options'  => $this->options($f),
            ];

            if ($f->type === 'subform') {
                $entry['subform'] = $this->describeSubform($f);
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Valeurs des champs d'un item (nom => valeur), filtrées par affectation.
     * Les valeurs complexes stockées en JSON (subform, galeries…) sont décodées.
     *
     * @return array<string,mixed>
     */
    public function valuesFor(int $itemId, int $catid): array
    {
        $item = (object) ['id' => $itemId, 'catid' => $catid];
        $out  = [];

        foreach (FieldsHelper::getFields($this->context, $item, true) as $f) {
            $out[$f->name] = self::decodeValue($f->rawvalue ?? ($f->value ?? null));
        }

        return $out;
    }

    /**
     * Écrit des valeurs de champ (nom => valeur) sur un item.
     * Seuls les champs applicables (affectation par catégorie) sont acceptés.
     *
     * Un subform est validé contre sa définition (voir normalizeSubform) : en cas d'erreur il n'est PAS écrit
     * et figure dans `invalid` (nom => message listant les clés attendues).
     *
     * @return array{applied:list<string>, ignored:list<string>, denied:list<string>, invalid?:array<string,string>}
     */
    public function setValues(int $itemId, int $catid, array $values): array
    {
        if ($values === []) {
            return ['applied' => [], 'ignored' => [], 'denied' => []];
        }

        $map    = [];
        $fields = [];

        foreach ($this->fieldsFor($catid) as $f) {
            $map[$f->name]    = (int) $f->id;
            $fields[$f->name] = $f;
        }

        $model = Factory::getApplication()->bootComponent('com_fields')
            ->getMVCFactory()->createModel('Field', 'Administrator', ['ignore_request' => true]);

        $applied = [];
        $ignored = [];
        $denied  = [];
        $invalid = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            // Champ non applicable à la catégorie (ou inexistant).
            if (!isset($map[$name])) {
                $ignored[] = $name;

                continue;
            }

            if ($fields[$name]->type === 'subform') {
                [$value, $error] = $this->normalizeSubform($fields[$name], $value);

                if ($error !== null) {
                    $invalid[$name] = $error;

                    continue;
                }
            }

            // setFieldValue renvoie false si l'ACL (core.edit.value) refuse.
            if ($model->setFieldValue($map[$name], $itemId, self::encodeValue($value))) {
                $applied[] = $name;
            } else {
                $denied[] = $name;
            }
        }

        $result = ['applied' => $applied, 'ignored' => $ignored, 'denied' => $denied];

        return $invalid ? $result + ['invalid' => $invalid] : $result;
    }

    /**
     * Structure attendue d'un champ subform (pour list_*_fields) : sous-champs, clé de stockage, répétable, exemple.
     * Joomla (plg_fields_subform::getSubfieldsFromField) lit chaque sous-champ sous la clé `field<ID>`, jamais
     * sous son nom ; les lignes d'un subform répétable sont des objets `row0`, `row1`… (clés libres).
     */
    private function describeSubform(object $field): array
    {
        $subs    = $this->subfields($field);
        $repeat  = (string) self::param($field, 'repeat', '1') !== '0';
        $max     = (int) self::param($field, 'max_rows', 0);
        $example = [];

        foreach ($subs as $sub) {
            $example[$sub['key']] = '…';
        }

        return [
            'repeatable' => $repeat,
            'max_rows'   => $max > 0 ? $max : null,
            'fields'     => $subs,
            'value_format' => $repeat
                ? 'A list of rows, each row keyed by the sub-field "key" (field<ID>); stored as {"row0": {...}, "row1": {...}}. '
                    . 'Sub-field names or titles are also accepted as keys and converted. Sending the field replaces all rows.'
                : 'One row object keyed by the sub-field "key" (field<ID>). Sub-field names or titles are also accepted.',
            'example'    => $repeat ? [$example, $example] : $example,
        ];
    }

    /**
     * Valide et met au format Joomla la valeur d'un subform. Lignes acceptées en liste, en objet `rowN` ou (non
     * répétable / ligne unique) en objet ; clés de sous-champ acceptées : `field<ID>`, `<ID>`, nom, libellé
     * (casse et -/_ ignorés). Clé inconnue, ligne non-objet ou trop de lignes → [null, message].
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function normalizeSubform(object $field, mixed $value): array
    {
        $subs = $this->subfields($field);

        if ($subs === []) {
            return [$value, null]; // définition illisible : comportement antérieur
        }

        $expected = implode(', ', array_map(
            static fn ($s) => $s['key'] . ' (' . $s['title'] . ', name "' . $s['name'] . '")',
            $subs
        ));

        if (\is_string($value) && ($decoded = json_decode($value, true)) !== null) {
            $value = $decoded;
        }

        $value = json_decode((string) json_encode($value), true); // objets → tableaux

        if (!\is_array($value)) {
            return [null, "Subform \"{$field->name}\" expects " . 'rows of sub-field values. Expected keys: ' . $expected . '.'];
        }

        $aliases = [];

        foreach ($subs as $s) {
            foreach ([$s['key'], (string) $s['id'], $s['name'], $s['title']] as $alias) {
                $aliases[self::aliasKey($alias)] = $s['key'];
            }
        }

        // Une seule ligne envoyée telle quelle : uniquement des valeurs scalaires, ou uniquement des clés de sous-champ.
        $known = static fn ($k) => isset($aliases[self::aliasKey((string) $k)]);
        $isRow = $value !== [] && (
            array_filter($value, 'is_array') === []
            || \count(array_filter(array_keys($value), $known)) === \count($value)
        );
        $repeat = (string) self::param($field, 'repeat', '1') !== '0';
        $rows   = $isRow ? [$value] : array_values($value);

        if (!$repeat && \count($rows) > 1) {
            return [null, "Subform \"{$field->name}\" is not repeatable: send a single row object. Expected keys: $expected."];
        }

        $max = (int) self::param($field, 'max_rows', 0);

        if ($max > 0 && \count($rows) > $max) {
            return [null, "Subform \"{$field->name}\" accepts at most $max rows (" . \count($rows) . ' sent).'];
        }

        $out     = [];
        $unknown = [];

        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                return [null, "Subform \"{$field->name}\": row $i is not an object of sub-field values. Expected keys: $expected."];
            }

            $clean = [];

            foreach ($row as $key => $v) {
                $target = $aliases[self::aliasKey((string) $key)] ?? null;

                if ($target === null) {
                    $unknown[(string) $key] = true;

                    continue;
                }

                $clean[$target] = $v;
            }

            $out['row' . \count($out)] = $clean;
        }

        if ($unknown) {
            return [null, 'Unknown sub-field key(s) ' . implode(', ', array_map(static fn ($k) => '"' . $k . '"', array_keys($unknown)))
                . " in subform \"{$field->name}\" (nothing was saved for this field). Expected keys: $expected."];
        }

        return [$repeat ? $out : ($out['row0'] ?? []), null];
    }

    /** Sous-champs d'un subform : [{key: field<ID>, id, name, title, type}] dans l'ordre de la définition. */
    private function subfields(object $field): array
    {
        $options = self::param($field, 'options', []);
        $ids     = [];

        foreach ((array) $options as $o) {
            $o  = (array) $o;
            $id = (int) ($o['customfield'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'title', 'type']))
            ->from($db->quoteName('#__fields'))
            ->whereIn($db->quoteName('id'), $ids);
        $rows = $db->setQuery($query)->loadAssocList('id') ?: [];
        $out  = [];

        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $out[] = [
                    'key'   => 'field' . $id,
                    'id'    => $id,
                    'name'  => (string) $rows[$id]['name'],
                    'title' => (string) $rows[$id]['title'],
                    'type'  => (string) $rows[$id]['type'],
                ];
            }
        }

        return $out;
    }

    private static function aliasKey(string $key): string
    {
        return str_replace(['-', ' '], '_', mb_strtolower(trim($key)));
    }

    /** Paramètre de fieldparams (Registry ou tableau) puis de params. */
    private static function param(object $field, string $key, mixed $default): mixed
    {
        foreach (['fieldparams', 'params'] as $prop) {
            $p = $field->{$prop} ?? null;

            if (\is_object($p) && method_exists($p, 'get')) {
                $v = $p->get($key);
            } elseif (\is_array($p)) {
                $v = $p[$key] ?? null;
            } elseif (\is_string($p) && $p !== '') {
                $v = json_decode($p, true)[$key] ?? null;
            } else {
                $v = null;
            }

            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return $default;
    }

    /**
     * Même règle que plg_system_fields::onContentAfterSave : un tableau imbriqué
     * ou associatif (subform, types tiers à structure) est stocké en JSON ; un
     * tableau plat à clés numériques (liste multiple, cases à cocher) reste un
     * tableau → une ligne #__fields_values par valeur. Sans ça, setFieldValue()
     * insère « Array » pour chaque ligne d'un subform.
     */
    public static function encodeValue(mixed $value): mixed
    {
        if (\is_object($value)) {
            $value = (array) $value;
        }

        if (\is_array($value)
            && (\count($value, COUNT_NORMAL) !== \count($value, COUNT_RECURSIVE)
                || !\count(array_filter(array_keys($value), 'is_numeric')))) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    /**
     * Inverse de encodeValue pour la lecture : une valeur multiple revient en
     * tableau (mono-valeur aplatie), une chaîne JSON objet/tableau est décodée.
     */
    public static function decodeValue(mixed $value): mixed
    {
        if (\is_array($value)) {
            $value = array_map([self::class, 'decodeValue'], array_values($value));

            return \count($value) === 1 ? $value[0] : $value;
        }

        if (\is_string($value)) {
            $t = ltrim($value);

            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($value, true);

                if (\is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return $value;
    }

    /** @return object[] */
    private function fieldsFor(int $catid): array
    {
        $item = $catid > 0 ? (object) ['id' => 0, 'catid' => $catid] : null;

        return FieldsHelper::getFields($this->context, $item) ?: [];
    }

    private function options(object $field): ?array
    {
        if (!\in_array($field->type, ['list', 'radio', 'checkboxes', 'usergrouplist'], true)) {
            return null;
        }

        $params  = $field->fieldparams ?? null;
        $options = $params && method_exists($params, 'get') ? $params->get('options') : null;

        if (empty($options)) {
            return null;
        }

        $out = [];

        foreach ((array) $options as $o) {
            $out[] = ['value' => $o->value ?? null, 'label' => $o->name ?? null];
        }

        return $out;
    }

    /** @return array<int,string> group_id => titre */
    private function groupTitles(): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__fields_groups'))
            ->where($db->quoteName('context') . ' = ' . $db->quote($this->context));

        return $db->setQuery($query)->loadAssocList('id', 'title') ?: [];
    }
}
