<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Site\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Sensomedia\Component\Sensomcp\Administrator\Event\CollectToolsEvent;
use Sensomedia\Plugin\Sensomcp\Site\Tool\SiteTools;

\defined('_JEXEC') or die;

/**
 * Plugin SITE (offre gratuite, bundlé dans le package) : ajoute au serveur MCP
 * des outils généraux d'introspection du site (get_site_info…), destinés à
 * donner au LLM le contexte utile avant d'agir (URL, langues, fuseau, compte…).
 *
 * Foyer naturel du scope `site.read` ; vocation à s'étoffer d'autres outils
 * généraux au fil des besoins.
 */
final class Site extends CMSPlugin implements SubscriberInterface
{
    /** Charge le pack de langue du plugin (libellé de scope traduit au consentement). */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onSensomcpCollectTools' => 'onCollectTools'];
    }

    public function onCollectTools(CollectToolsEvent $event): void
    {
        (new SiteTools($event->getUser()))->register($event->getRegistry());
    }
}
