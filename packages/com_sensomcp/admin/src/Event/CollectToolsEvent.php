<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Administrator\Event;

use Joomla\CMS\User\User;
use Joomla\Event\Event;
use Sensomedia\Component\Sensomcp\Administrator\Mcp\ToolRegistry;

\defined('_JEXEC') or die;

/**
 * Événement de collecte des outils MCP — CONTRAT PUBLIC des plugins.
 *
 * Émis par le cœur avant chaque dispatch MCP. Les plugins du groupe
 * « sensomcp » s'y abonnent (onSensomcpCollectTools) et enregistrent leurs
 * outils via le registre. `getUser()` fournit l'utilisateur authentifié :
 * un plugin DOIT s'appuyer sur son ACL pour toute opération sensible.
 *
 * Exemple de plugin :
 *
 *   public static function getSubscribedEvents(): array
 *   {
 *       return ['onSensomcpCollectTools' => 'onCollectTools'];
 *   }
 *
 *   public function onCollectTools(CollectToolsEvent $event): void
 *   {
 *       $user = $event->getUser();
 *       $event->getRegistry()->register('ma_fonction', 'Description', $schema,
 *           fn (array $args) => $this->faire($args, $user));
 *   }
 */
final class CollectToolsEvent extends Event
{
    public function __construct(ToolRegistry $registry, User $user)
    {
        parent::__construct('onSensomcpCollectTools', [
            'registry' => $registry,
            'user'     => $user,
            'subject'  => $registry,
        ]);
    }

    public function getRegistry(): ToolRegistry
    {
        return $this->getArgument('registry');
    }

    public function getUser(): User
    {
        return $this->getArgument('user');
    }
}
