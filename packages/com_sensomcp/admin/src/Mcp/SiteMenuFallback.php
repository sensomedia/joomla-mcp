<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\CMS\Menu\MenuFactoryInterface;

\defined('_JEXEC') or die;

/**
 * Menu « api » → menu du site, pendant une requête MCP.
 *
 * L'application API de Joomla n'a pas de menu : `getMenu()` y lève
 * « Error loading menu: api ». Des plugins tiers qui supposent tourner sur le
 * site l'appellent pendant un enregistrement (SpeedCache `clearArticleCache`
 * sur `onContentAfterSave`, j2storeoptimized via l'indexation Smart Search…).
 * `AdminModel::save()` attrape l'exception APRÈS l'écriture en base : l'élément
 * est enregistré mais l'outil répond « failed », et les plugins suivants sont sautés.
 *
 * On enveloppe la fabrique de menus de l'application (API publique
 * `setMenuFactory`) : seul le client `api` est remplacé par `site`, ce que ces
 * plugins attendaient. Aucun élément n'est actif (`getActive()` = null), ce qui
 * reflète la réalité d'une requête API. Testé sur kilogram.lu (J6.2) le 20/11/2026.
 */
final class SiteMenuFallback
{
    public static function install(CMSApplication $app): void
    {
        if (!$app->isClient('api') || !method_exists($app, 'setMenuFactory')) {
            return;
        }

        try {
            $inner = Factory::getContainer()->get(MenuFactoryInterface::class);

            $app->setMenuFactory(new class ($inner) implements MenuFactoryInterface {
                public function __construct(private MenuFactoryInterface $inner)
                {
                }

                public function createMenu(string $client, array $options = []): AbstractMenu
                {
                    if ($client === 'api') {
                        if (DebugLog::enabled()) {
                            DebugLog::note('Menu "api" requested during an MCP request: site menu returned instead. Caller: '
                                . SiteMenuFallback::caller());
                        }

                        $client = 'site';
                    }

                    return $this->inner->createMenu($client, $options);
                }
            });
        } catch (\Throwable) {
            // Comportement Joomla d'origine si la fabrique est indisponible.
        }
    }

    /** Premier appelant hors core (fichier:ligne), pour le journal de débogage. */
    public static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = (string) ($frame['file'] ?? '');

            if ($file !== '' && !str_contains($file, '/libraries/') && !str_contains($file, 'SiteMenuFallback')) {
                return str_replace(JPATH_ROOT . '/', '', $file) . ':' . ($frame['line'] ?? '?');
            }
        }

        return 'unknown';
    }
}
