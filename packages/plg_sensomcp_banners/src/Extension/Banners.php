<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Banners\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Sensomedia\Component\Sensomcp\Administrator\Event\CollectToolsEvent;
use Sensomedia\Plugin\Sensomcp\Banners\Tool\BannersSupport;
use Sensomedia\Plugin\Sensomcp\Banners\Tool\BannerTools;
use Sensomedia\Plugin\Sensomcp\Banners\Tool\ClientTools;

\defined('_JEXEC') or die;

/**
 * Plugin BANNERS (offre gratuite, bundlé dans le package depuis 1.6.0) : outils MCP du
 * composant Bannières de Joomla (com_banners) — bannières, clients, catégories, statistiques.
 *
 * Préfixe de scope = élément du plugin (`banners.*`).
 */
final class Banners extends CMSPlugin implements SubscriberInterface
{
    /** Charge le pack de langue du plugin (libellés de scope traduits au consentement). */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onSensomcpCollectTools' => 'onCollectTools'];
    }

    public function onCollectTools(CollectToolsEvent $event): void
    {
        $support = new BannersSupport($event->getUser());

        if (!$support->componentEnabled()) {
            return;
        }

        $registry = $event->getRegistry();
        $registry->declareScope('banners.read', 'PLG_SENSOMCP_BANNERS_SCOPE_READ');
        $registry->declareScope('banners.write', 'PLG_SENSOMCP_BANNERS_SCOPE_WRITE');

        (new BannerTools($event->getUser(), $support))->register($registry);
        (new ClientTools($event->getUser(), $support))->register($registry);
    }
}
