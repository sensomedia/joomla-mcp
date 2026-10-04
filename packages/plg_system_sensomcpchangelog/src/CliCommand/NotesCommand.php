<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\System\SensomcpChangelog\CliCommand;

use Joomla\Console\Command\AbstractCommand;
use Sensomedia\Plugin\System\SensomcpChangelog\Changelog\ChangelogParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

\defined('_JEXEC') or die;

/**
 * Rend les notes ARS ÉPURÉES (HTML) d'UNE version, à partir d'un CHANGELOG.md.
 * Utilisée au moment de la publication ARS : la sortie brute (OUTPUT_RAW) est
 * capturée et insérée dans #__ars_releases.notes. Génération 100 % dans Joomla.
 *
 *   php cli/joomla.php sensomcp:changelog:notes --changelog=/chemin/CHANGELOG.md --release=1.2.0
 */
final class NotesCommand extends AbstractCommand
{
    protected static $defaultName = 'sensomcp:changelog:notes';

    protected function configure(): void
    {
        $this->setDescription('Rend les notes ARS épurées (HTML) d\'une version depuis un CHANGELOG.md.');
        $this->addOption('changelog', null, InputOption::VALUE_REQUIRED, 'Chemin du CHANGELOG.md');
        $this->addOption('release', null, InputOption::VALUE_REQUIRED, 'Version à rendre (ex. 1.2.0)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $path    = (string) $input->getOption('changelog');
        $version = (string) $input->getOption('release');

        if ($path === '' || $version === '') {
            $output->writeln('<error>--changelog et --release sont requis.</error>');

            return 1;
        }

        if (!is_readable($path)) {
            $output->writeln('<error>CHANGELOG illisible : ' . $path . '</error>');

            return 1;
        }

        $sections = ChangelogParser::sectionsForVersion((string) file_get_contents($path), $version);

        // Sortie brute : le HTML ne doit pas être interprété comme balises de style Symfony.
        $output->write(ChangelogParser::renderNotesHtml($sections), false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
