<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Contact\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Sensomedia\Component\Sensomcp\Administrator\Event\CollectToolsEvent;
use Sensomedia\Plugin\Sensomcp\Contact\Tool\ContactTools;

\defined('_JEXEC') or die;

/**
 * Plugin CONTACT (offre gratuite, bundlé dans le package depuis 1.6.0) : outils MCP du
 * composant Contacts de Joomla (com_contact).
 *
 * Préfixe de scope = élément du plugin (`contact.*`) : rattache les outils à ce plugin dans
 * l'écran Plugins de Senso MCP.
 */
final class Contact extends CMSPlugin implements SubscriberInterface
{
    /** Charge le pack de langue du plugin (libellés de scope traduits au consentement). */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onSensomcpCollectTools' => 'onCollectTools'];
    }

    public function onCollectTools(CollectToolsEvent $event): void
    {
        (new ContactTools($event->getUser()))->register($event->getRegistry());
    }
}
