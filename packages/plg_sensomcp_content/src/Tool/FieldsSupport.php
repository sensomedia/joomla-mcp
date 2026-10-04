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
 * Support des champs personnalisés (com_fields) pour les articles.
 *
 * L'affectation par catégorie est gérée nativement par FieldsHelper::getFields()
 * dès qu'on lui passe un item portant le catid : il ne renvoie que les champs
 * assignés à cette catégorie (+ ceux assignés à « toutes »).
 */
final class FieldsSupport
{
    private const CONTEXT = 'com_content.article';

    /**
     * Définitions des champs applicables à une catégorie (pour la découverte).
     *
     * @return list<array<string,mixed>>
     */
    public function listForCategory(int $catid): array
    {
        $groups = $this->groupTitles();
        $out    = [];

        foreach ($this->fieldsForCategory($catid) as $f) {
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
     * Valeurs des champs d'un article (nom => valeur), filtrées par sa catégorie.
     *
     * @return array<string,mixed>
     */
    public function valuesForArticle(int $articleId, int $catid): array
    {
        $item = (object) ['id' => $articleId, 'catid' => $catid];
        $out  = [];

        foreach (FieldsHelper::getFields(self::CONTEXT, $item, true) as $f) {
            $value = $f->rawvalue ?? ($f->value ?? null);

            // Les champs de type liste renvoient un tableau même mono-valeur.
            if (\is_array($value)) {
                $value = \count($value) === 1 ? reset($value) : array_values($value);
            }

            $out[$f->name] = $value;
        }

        return $out;
    }

    /**
     * Écrit des valeurs de champ (nom => valeur) sur un article.
     * Seuls les champs applicables à la catégorie sont acceptés.
     *
     * @return array{applied:list<string>, ignored:list<string>, denied:list<string>}
     */
    public function setValues(int $articleId, int $catid, array $values): array
    {
        if ($values === []) {
            return ['applied' => [], 'ignored' => [], 'denied' => []];
        }

        $map = [];

        foreach ($this->fieldsForCategory($catid) as $f) {
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
            if ($model->setFieldValue($map[$name], $articleId, $value)) {
                $applied[] = $name;
            } else {
                $denied[] = $name;
            }
        }

        return ['applied' => $applied, 'ignored' => $ignored, 'denied' => $denied];
    }

    /** @return object[] */
    private function fieldsForCategory(int $catid): array
    {
        $item = (object) ['id' => 0, 'catid' => $catid];

        return FieldsHelper::getFields(self::CONTEXT, $item) ?: [];
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
            ->where($db->quoteName('context') . ' = ' . $db->quote(self::CONTEXT));

        return $db->setQuery($query)->loadAssocList('id', 'title') ?: [];
    }
}
