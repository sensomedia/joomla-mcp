<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Sensomedia\Plugin\System\SensomcpChangelog\Extension\SensomcpChangelog;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new SensomcpChangelog(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'sensomcpchangelog')
                );

                return $plugin;
            }
        );
    }
};
