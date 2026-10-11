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
            $out[] = [
                'name'     => $f->name,
                'title'    => $f->title,
                'type'     => $f->type,
                'group'    => $groups[$f->group_id] ?? null,
                'required' => (bool) $f->required,
                'default'  => $f->default_value,
                'options'  => $this->options($f),
            ];
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
     * @return array{applied:list<string>, ignored:list<string>, denied:list<string>}
     */
    public function setValues(int $itemId, int $catid, array $values): array
    {
        if ($values === []) {
            return ['applied' => [], 'ignored' => [], 'denied' => []];
        }

        $map = [];

        foreach ($this->fieldsFor($catid) as $f) {
            $map[$f->name] = (int) $f->id;
        }

        $model = Factory::getApplication()->bootComponent('com_fields')
            ->getMVCFactory()->createModel('Field', 'Administrator', ['ignore_request' => true]);

        $applied = [];
        $ignored = [];
        $denied  = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            // Champ non applicable à la catégorie (ou inexistant).
            if (!isset($map[$name])) {
                $ignored[] = $name;

                continue;
            }

            // setFieldValue renvoie false si l'ACL (core.edit.value) refuse.
            if ($model->setFieldValue($map[$name], $itemId, self::encodeValue($value))) {
                $applied[] = $name;
            } else {
                $denied[] = $name;
            }
        }

        return ['applied' => $applied, 'ignored' => $ignored, 'denied' => $denied];
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
