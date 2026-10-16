<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Passage des données d'écriture par le formulaire Joomla du modèle, comme le
 * FormController du cœur : `Form::filter()` (filtres de texte, typage, retrait
 * des champs `filter="unset"` que le modèle pose selon les ACL : state/published,
 * ordering, publish_*, featured*, created_by…) puis `Form::validate()`.
 *
 * Disponible depuis Senso MCP 1.3.0 ; les plugins vendus séparément testent
 * `class_exists(FormGate::class)` et refusent l'écriture sinon.
 */
final class FormGate
{
    /** Clés que le formulaire admin ne poste jamais (filter="unset") : retirées des données existantes. */
    private const STRIP = [
        'hits', 'modified', 'modified_by', 'modified_time', 'modified_user_id', 'checked_out', 'checked_out_time',
        'version', 'asset_id', 'lft', 'rgt', 'level',
    ];

    /** Raisons standard affichées dans `ignored` pour les champs retirés par les ACL du cœur. */
    public static function reasons(): array
    {
        $state = 'core.edit.state required';
        $users = 'core.manage on com_users required';

        return [
            'state'           => $state,
            'published'       => $state,
            'ordering'        => $state,
            'publish_up'      => $state,
            'publish_down'    => $state,
            'featured'        => $state,
            'featured_up'     => $state,
            'featured_down'   => $state,
            'created_by'      => $users,
            'created_user_id' => $users,
        ];
    }

    /**
     * Filtre `$input`, fusionne dans `$current` (groupes tableau fusionnés clé à clé),
     * valide le résultat et le renvoie avec la liste des clés écartées.
     *
     * @param Form                 $form    formulaire déjà chargé (`getForm($data, false)`)
     * @param array<string,mixed>  $input   création : toutes les données ; mise à jour : seuls les champs fournis
     * @param array<string,mixed>  $current données complètes existantes (création : [])
     * @param string[]             $keep    clés recopiées sans filtrage (id, extension, context, client_id…)
     * @param array<string,string> $reasons clé => raison (défaut : self::reasons())
     * @param string[]             $unfiltered clés transmises sans filtre mais soumises au verrou ACL
     *                                         (ex. dates déjà en UTC SQL : le filtre `user_utc` les reconvertirait)
     *
     * @return array{data: array<string,mixed>, ignored: string[]}
     */
    public static function apply(Form $form, array $input, array $current = [], array $keep = [], ?array $reasons = null, array $unfiltered = []): array
    {
        $reasons ??= self::reasons();
        $ignored  = [];

        // Champs verrouillés par le modèle selon les ACL (filter="unset" et/ou disabled, ex.
        // state/publish_*/featured*/ordering/created_by). Le navigateur ne les poste jamais ;
        // ici on les retire nous-mêmes : certains champs (calendar) ignorent `unset` dans
        // leur filter(), et validate() rejette tout champ désactivé présent dans les données.
        $locked = self::lockedKeys($form);

        foreach ($locked as $key) {
            if (\in_array($key, $keep, true)) {
                continue;
            }

            [$g, $sub] = str_contains($key, '.') ? explode('.', $key, 2) : [null, $key];

            if ($g === null && \array_key_exists($key, $input)) {
                unset($input[$key]);
                $ignored[] = self::label($key, $reasons);
            } elseif ($g !== null && isset($input[$g]) && \is_array($input[$g]) && \array_key_exists($sub, $input[$g])) {
                unset($input[$g][$sub]);
                $ignored[] = self::label($key, $reasons);
            }
        }

        $passthru = array_intersect_key($input, array_flip($unfiltered));
        $filtered = (array) $form->filter(array_diff_key($input, $passthru)) + $passthru;

        // Un groupe fourni vide (ex. params: []) disparaît du filtre : on le restitue vide pour
        // que la table reçoive bien la colonne (NOT NULL sans défaut sur #__fields.params…).
        foreach ($input as $k => $v) {
            if (\is_array($v) && !\array_key_exists($k, $filtered) && $form->getGroup((string) $k) !== []) {
                $filtered[$k] = [];
            }
        }

        foreach ($input as $k => $v) {
            if (\in_array($k, $keep, true)) {
                continue;
            }

            if (\is_array($v) && isset($filtered[$k]) && \is_array($filtered[$k])) {
                foreach (array_keys($v) as $sub) {
                    if (!\array_key_exists($sub, $filtered[$k])) {
                        $ignored[] = self::label($k . '.' . $sub, $reasons);
                    }
                }
            } elseif (!\array_key_exists($k, $filtered)) {
                $ignored[] = self::label((string) $k, $reasons);
            }
        }

        $final = array_diff_key($current, array_flip(self::STRIP));

        foreach ($filtered as $k => $v) {
            $final[$k] = \is_array($v) && isset($final[$k]) && \is_array($final[$k]) ? array_replace($final[$k], $v) : $v;
        }

        foreach ($keep as $k) {
            if (\array_key_exists($k, $input)) {
                $final[$k] = $input[$k];
            }
        }

        // Les champs verrouillés ne sont ni validés ni réécrits : la table garde sa valeur (update)
        // ou son défaut (création), comme quand l'admin ne les poste pas.
        foreach ($locked as $key) {
            if (\in_array($key, $keep, true)) {
                continue;
            }

            [$g, $sub] = str_contains($key, '.') ? explode('.', $key, 2) : [null, $key];

            if ($g === null) {
                unset($final[$key]);
            } elseif (isset($final[$g]) && \is_array($final[$g])) {
                unset($final[$g][$sub]);
            }
        }

        self::validate($form, $final);

        return ['data' => $final, 'ignored' => $ignored];
    }

    /**
     * Clés (`name` ou `group.name`) des champs du formulaire portant filter="unset"
     * ou disabled="true".
     *
     * @return string[]
     */
    public static function lockedKeys(Form $form): array
    {
        $keys = [];

        foreach ((array) $form->getFieldset() as $field) {
            $filter   = strtolower((string) $field->getAttribute('filter', ''));
            $disabled = (string) $field->getAttribute('disabled', '');

            if ($filter !== 'unset' && $disabled !== 'true' && $disabled !== 'disabled') {
                continue;
            }

            $name  = (string) $field->fieldname;
            $group = (string) $field->group;

            if ($name !== '') {
                $keys[] = $group !== '' ? $group . '.' . $name : $name;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Met les formulaires ADMIN du composant en tête de la recherche de fichiers.
     * `FormHelper::addPath()` n'insère un chemin qu'une fois : dès qu'un chemin
     * site (ex. `components/com_content/forms`, ajouté par le routeur site lors
     * de la génération d'URL) entre après lui, `Path::find('article.xml')` prend
     * le formulaire site, qui porte un champ captcha → validation impossible.
     */
    public static function useAdminForms(string $component): void
    {
        $forms  = JPATH_ADMINISTRATOR . '/components/' . $component . '/forms';
        $fields = JPATH_ADMINISTRATOR . '/components/' . $component . '/src/Field';

        $front = \Closure::bind(static function (string $entity, string $dir): void {
            if (!isset(self::$paths[$entity])) {
                self::$paths[$entity] = [];
            }

            $paths = &self::$paths[$entity];
            $paths = array_values(array_diff($paths, [$dir]));
            array_unshift($paths, $dir);
        }, null, FormHelper::class);

        $front('form', $forms);

        if (is_dir($fields)) {
            $front('field', $fields);
        }
    }

    /** `Form::validate()` avec les messages du cœur (champ requis, valeur invalide…). */
    public static function validate(Form $form, array $data): void
    {
        $ok = $form->validate($data);

        if ($ok instanceof \Throwable) {
            throw new McpToolException('Validation failed: ' . $ok->getMessage());
        }

        if ($ok === false) {
            $msgs = array_filter(array_map(
                static fn ($e) => $e instanceof \Throwable ? $e->getMessage() : (string) $e,
                $form->getErrors()
            ));

            throw new McpToolException('Validation failed: ' . (implode(' | ', $msgs) ?: 'unknown error'));
        }
    }

    /**
     * Complète un groupe (ex. `params`) avec les `default` du formulaire pour les
     * clés absentes, comme le poste l'admin (certaines règles refusent le vide).
     */
    public static function fillDefaults(Form $form, array &$data, string $group): void
    {
        $data[$group] = (array) ($data[$group] ?? []);

        foreach ((array) $form->getGroup($group) as $field) {
            $name = (string) $field->fieldname;

            if ($name === '' || \array_key_exists($name, $data[$group])) {
                continue;
            }

            $default = (string) $field->getAttribute('default', '');

            if ($default !== '') {
                $data[$group][$name] = $default;
            }
        }
    }

    /** Données « formulaire » d'un item de modèle : scalaires et tableaux, Registry/JSON décodés, objets ignorés. */
    public static function itemData(object $item): array
    {
        $out = [];

        foreach (get_object_vars($item) as $k => $v) {
            if ($v instanceof \Joomla\Registry\Registry) {
                $out[$k] = $v->toArray();
            } elseif (\is_scalar($v) || $v === null || \is_array($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Un `core.edit` ou `core.edit.own` (si auteur) sur un asset. */
    public static function canEdit(User $me, string $asset, int $createdBy): bool
    {
        return $me->authorise('core.edit', $asset)
            || ($me->authorise('core.edit.own', $asset) && $createdBy > 0 && $createdBy === (int) $me->id);
    }

    private static function label(string $key, array $reasons): string
    {
        $base = strstr($key, '.', true) ?: $key;

        return isset($reasons[$base]) ? $key . ' (' . $reasons[$base] . ')' : $key;
    }
}
