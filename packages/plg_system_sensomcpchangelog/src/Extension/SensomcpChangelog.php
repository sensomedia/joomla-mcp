<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\System\SensomcpChangelog\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Sensomedia\Plugin\System\SensomcpChangelog\Changelog\ChangelogParser;
use Sensomedia\Plugin\System\SensomcpChangelog\CliCommand\NotesCommand;

\defined('_JEXEC') or die;

/**
 * Sert dynamiquement le changelog.xml Joomla depuis les données ARS du store.
 *
 *   GET /index.php?sensomcp_changelog=<element>&type=<type>&catid=<id>[&folder=<f>]
 *
 * -> lit les releases publiées de la catégorie ARS <id>, convertit les notes
 *    (HTML épuré, cf. ChangelogParser) en entrées <changelog> catégorisées, et
 *    renvoie le document <changelogs> attendu par la vue « Mettre à jour » de
 *    Joomla (#__updates.changelogurl). Aucun fichier statique, toujours en
 *    phase avec ce qui est publié.
 *
 * Résultat mis en cache via le cache natif Joomla (callback controller, groupe
 * dédié) : l'endpoint est sollicité en polling par tous les sites clients.
 *
 * Le même plugin est bundlé chez les clients (il y porte surtout la classe
 * ChangelogParser, réutilisée par le postflight d'install) ; l'endpoint n'y
 * répond simplement jamais faute de catégorie ARS.
 */
final class SensomcpChangelog extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'              => 'onAfterInitialise',
            ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
        ];
    }

    /** Enregistre la commande console de génération des notes (app console uniquement). */
    public function registerCommands(): void
    {
        $app = Factory::getApplication();

        if ($app instanceof ConsoleApplication) {
            $app->addCommand(new NotesCommand());
        }
    }

    public function onAfterInitialise(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $element = $app->input->getCmd('sensomcp_changelog', '');

        if ($element === '') {
            return;
        }

        $catid = $app->input->getInt('catid', 0);

        // Le flux de mise à jour ARS de la catégorie est la source de vérité : élément, type
        // et dossier en sont tirés (Joomla ne lit que la version dans ce XML) et la clé de
        // cache se réduit à `catid` — au plus une entrée par flux, rien n'est mis en cache
        // pour une catégorie inconnue (audit M7).
        $stream = self::streamFor($catid);

        if ($stream === null) {
            $app->setHeader('status', 404, true);
            $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $app->sendHeaders();
            echo 'Unknown changelog.';
            $app->close();
        }

        $xml = $this->render($catid, $stream['element'], $stream['type'], $stream['folder']);

        $app->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        $app->setHeader('Access-Control-Allow-Origin', '*', true);
        $app->sendHeaders();

        echo $xml;

        $app->close();
    }

    /** Rend le XML (mis en cache natif Joomla si activé). */
    private function render(int $catid, string $element, string $type, string $folder): string
    {
        $caching  = (int) $this->params->get('caching', 1) === 1;
        $lifetime = max(1, (int) $this->params->get('cache_time', 360)); // minutes

        if (!$caching) {
            return self::buildXml($catid, $element, $type, $folder);
        }

        try {
            /** @var CallbackController $cache */
            $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'plg_sensomcpchangelog',
                    'lifetime'     => $lifetime,
                    'caching'      => true,
                ]);

            return (string) $cache->get(
                [self::class, 'buildXml'],
                [$catid, $element, $type, $folder],
                'cat' . $catid
            );
        } catch (\Throwable) {
            return self::buildXml($catid, $element, $type, $folder);
        }
    }

    /** Types ARS (pluriel) → type de manifeste Joomla. */
    private const STREAM_TYPES = [
        'components' => 'component', 'plugins' => 'plugin', 'modules' => 'module', 'packages' => 'package',
        'libraries' => 'library', 'files' => 'file', 'templates' => 'template', 'languages' => 'language',
    ];

    /**
     * Flux de mise à jour ARS publié d'une catégorie, ou null.
     *
     * @return array{element: string, type: string, folder: string}|null
     */
    private static function streamFor(int $catid): ?array
    {
        if ($catid <= 0) {
            return null;
        }

        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['element', 'type', 'folder']))
                    ->from($db->quoteName('#__ars_updatestreams'))
                    ->where($db->quoteName('category') . ' = :catid')
                    ->where($db->quoteName('published') . ' = 1')
                    ->order($db->quoteName('id') . ' ASC')
                    ->bind(':catid', $catid, \Joomla\Database\ParameterType::INTEGER),
                0,
                1
            )->loadAssoc();
        } catch (\Throwable) {
            return null;
        }

        if (!$row || (string) $row['element'] === '') {
            return null;
        }

        return [
            'element' => (string) $row['element'],
            'type'    => self::STREAM_TYPES[(string) $row['type']] ?? (string) $row['type'],
            'folder'  => (string) $row['folder'],
        ];
    }

    /**
     * Construit le document <changelogs> à partir des releases ARS publiées de
     * la catégorie. Public + statique : appelé directement OU via le cache.
     */
    public static function buildXml(int $catid, string $element, string $type, string $folder): string
    {
        $versions = [];

        if ($catid > 0) {
            try {
                $db    = Factory::getContainer()->get(DatabaseInterface::class);
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['version', 'notes']))
                    ->from($db->quoteName('#__ars_releases'))
                    ->where($db->quoteName('category_id') . ' = :catid')
                    ->where($db->quoteName('published') . ' = 1')
                    ->order($db->quoteName('id') . ' DESC')
                    ->bind(':catid', $catid, \Joomla\Database\ParameterType::INTEGER);
                $db->setQuery($query);
                $rows = $db->loadObjectList() ?: [];

                foreach ($rows as $row) {
                    $versions[] = [
                        'version'  => (string) $row->version,
                        'sections' => ChangelogParser::parseNotesHtml((string) $row->notes),
                    ];
                }
            } catch (\Throwable) {
                $versions = [];
            }
        }

        return ChangelogParser::renderChangelogXml($versions, $element, $type, $folder !== '' ? $folder : null);
    }
}
