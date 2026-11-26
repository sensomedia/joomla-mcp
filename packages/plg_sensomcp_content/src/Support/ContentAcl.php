<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Support;

use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Règles ACL com_content partagées par les outils du plugin.
 *
 * Le registre appelle les gardes ACL sans arguments pour construire `tools/list`
 * et l'écran de consentement OAuth (contrôle « l'outil peut-il servir ? »). Sans
 * cible, il faut répondre comme le back-office Joomla : une action accordée sur
 * une seule catégorie (et refusée au niveau global) suffit à proposer l'outil
 * (le bouton « Nouveau » apparaît dès que getAuthorisedCategories() n'est pas
 * vide). Le contrôle fin, par catégorie ou par article, se fait à l'appel.
 */
final class ContentAcl
{
    /**
     * Vrai si l'une des actions est accordée au niveau du composant OU sur au
     * moins une catégorie publiée de com_content.
     */
    public static function anyCategory(User $me, string ...$actions): bool
    {
        foreach ($actions as $action) {
            if ($me->authorise($action, 'com_content')) {
                return true;
            }

            if ($me->getAuthorisedCategories('com_content', $action) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Action sur chacun des articles (asset de l'article, qui hérite de sa catégorie).
     *
     * @param list<int> $ids
     */
    public static function onEachArticle(User $me, string $action, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }

        foreach ($ids as $id) {
            if (!$me->authorise($action, 'com_content.article.' . $id)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    public static function ids(mixed $raw): array
    {
        return array_values(array_filter(array_map('intval', (array) $raw), static fn (int $id): bool => $id > 0));
    }
}
