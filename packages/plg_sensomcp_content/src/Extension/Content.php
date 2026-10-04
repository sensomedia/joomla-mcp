<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Sensomedia\Component\Sensomcp\Administrator\Event\CollectToolsEvent;
use Sensomedia\Plugin\Sensomcp\Content\Tool\ArticleTools;
use Sensomedia\Plugin\Sensomcp\Content\Tool\CategoryTools;
use Sensomedia\Plugin\Sensomcp\Content\Tool\TagTools;
use Sensomedia\Plugin\Sensomcp\Content\Tool\VersionTools;

\defined('_JEXEC') or die;

/**
 * Plugin CONTENU (offre gratuite) : ajoute les outils MCP de gestion des
 * articles au serveur. Livré bundlé dans le package com_sensomcp.
 *
 * Modèle de tous les plugins « sensomcp » : s'abonne à onSensomcpCollectTools
 * et enregistre ses outils dans le registre fourni.
 */
final class Content extends CMSPlugin implements SubscriberInterface
{
    /** Charge le pack de langue du plugin (libellés de scope traduits au consentement). */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onSensomcpCollectTools' => 'onCollectTools'];
    }

    public function onCollectTools(CollectToolsEvent $event): void
    {
        $registry   = $event->getRegistry();
        $user       = $event->getUser();
        $allowWrite = (bool) $this->params->get('allow_write', 1);

        (new ArticleTools(
            $user,
            $allowWrite,
            (int) $this->params->get('default_catid', 0),
            (int) $this->params->get('default_state', 0)
        ))->register($registry);

        (new CategoryTools($user, $allowWrite))->register($registry);
        (new TagTools($allowWrite))->register($registry);
        (new VersionTools($user, $allowWrite))->register($registry);
    }
}
