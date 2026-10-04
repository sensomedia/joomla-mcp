<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Mcp;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Sensomedia\Component\Sensomcp\Administrator\Event\CollectToolsEvent;

\defined('_JEXEC') or die;

/**
 * Construit un ToolRegistry en collectant les outils/scopes de tous les
 * plugins du groupe « sensomcp ». Utilisé par le moteur (exécution), les
 * métadonnées OAuth et l'écran de consentement (catalogue de scopes).
 */
final class ToolCollector
{
    public static function collect(User $user): ToolRegistry
    {
        $registry   = new ToolRegistry();
        $registry->setUser($user);
        $dispatcher = Factory::getApplication()->getDispatcher();

        PluginHelper::importPlugin('sensomcp', null, true, $dispatcher);
        $dispatcher->dispatch('onSensomcpCollectTools', new CollectToolsEvent($registry, $user));

        return $registry;
    }

    /**
     * Catalogue des scopes disponibles (scopeId => libellé traduit), tel que
     * déclaré par les plugins installés. Construit avec un utilisateur invité —
     * la déclaration des scopes ne dépend pas de l'ACL.
     *
     * @return array<string,string>
     */
    public static function availableScopes(): array
    {
        $guest = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);

        return self::collect($guest)->scopes();
    }

    /**
     * Scopes réellement utilisables par $user (≥ 1 outil autorisé par son ACL
     * Joomla) — pour l'écran de consentement OAuth : n'offrir que ce que
     * l'utilisateur a le droit de faire.
     *
     * @return array<string,string> scopeId => libellé
     */
    public static function scopesForUser(User $user): array
    {
        return self::collect($user)->authorisedScopes();
    }
}
