<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 *
 * Installer script du composant. preflight() : garde-fous PHP/Joomla.
 * postflight() : lit CHANGELOG.md (installé dans admin/) et affiche la section
 * de la version du manifest sur la page de résultat d'installation.
 * Voir CLAUDE.md (« Changelog d'install/update ») pour la convention.
 *
 * NB : le nom de classe attendu par Joomla est `com_sensomcpInstallerScript`
 * (élément + InstallerScript) ; PHP étant insensible à la casse des classes,
 * ce `Com_SensomcpInstallerScript` correspond.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

class Com_SensomcpInstallerScript
{
    private const EXTENSION_DIR  = '/administrator/components/com_sensomcp';
    private const DISPLAY_TITLE  = 'Senso MCP';
    private const CHANGELOG_NAME = 'CHANGELOG.md';
    private const MIN_PHP        = '8.2';
    private const MIN_JOOMLA     = '5.0';

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(\PHP_VERSION, self::MIN_PHP, '<')) {
            $this->error(sprintf('Senso MCP requires PHP %s or newer (found %s).', self::MIN_PHP, \PHP_VERSION));

            return false;
        }

        if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
            $this->error(sprintf('Senso MCP requires Joomla %s or newer (found %s).', self::MIN_JOOMLA, JVERSION));

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        // Schéma idempotent (table ajoutée après 0.3.0 sans bump : garantit sa
        // présence sur les installs existantes à chaque (ré)installation/màj).
        $this->ensureSchema();

        $manifest = $adapter->getManifest();
        $version  = trim((string) ($manifest->version ?? ''));

        if ($version === '') {
            return true;
        }

        $path = JPATH_ROOT . self::EXTENSION_DIR . '/' . self::CHANGELOG_NAME;

        if (!is_readable($path)) {
            return true;
        }

        $section = $this->extractVersionSection((string) @file_get_contents($path), $version);

        if ($section === '') {
            return true;
        }

        echo $this->renderHtml($version, $section, $type);

        return true;
    }

    /** Crée les tables ajoutées après la version initiale, si absentes. */
    private function ensureSchema(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__sensomcp_tool_state') . ' ('
                . $db->quoteName('tool') . ' VARCHAR(100) NOT NULL, '
                . $db->quoteName('enabled') . ' TINYINT(1) NOT NULL DEFAULT 1, '
                . 'PRIMARY KEY (' . $db->quoteName('tool') . ')'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            )->execute();

            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__sensomcp_ratelimit') . ' ('
                . $db->quoteName('rl_key') . ' VARCHAR(191) NOT NULL, '
                . $db->quoteName('window_start') . ' INT NOT NULL DEFAULT 0, '
                . $db->quoteName('hits') . ' INT NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (' . $db->quoteName('rl_key') . ')'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            )->execute();

            // Élargit la colonne scope (nombreux scopes → la chaîne consentie
            // dépassait VARCHAR(255) et provoquait « Data too long » à l'autorisation).
            foreach (['#__sensomcp_oauth_codes', '#__sensomcp_oauth_refresh'] as $t) {
                $db->setQuery(
                    'ALTER TABLE ' . $db->quoteName($t) . ' MODIFY COLUMN '
                    . $db->quoteName('scope') . " VARCHAR(1024) NOT NULL DEFAULT ''"
                )->execute();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function error(string $message): void
    {
        try {
            Factory::getApplication()->enqueueMessage($message, 'error');
        } catch (\Throwable) {
            echo $message;
        }
    }

    /** Corps sous un titre `## <version> …`, jusqu'au prochain `## ` ou EOF. */
    private function extractVersionSection(string $changelog, string $version): string
    {
        $pattern = '/^##\s+' . preg_quote($version, '/') . '\b[^\n]*\n(.*?)(?=^##\s|\z)/ms';

        if (preg_match($pattern, $changelog, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /** Sous-ensemble Markdown → HTML : `### Titre`, `- item`, sinon `<p>`. */
    private function renderHtml(string $version, string $markdown, string $type): string
    {
        $verb   = $type === 'install' ? 'installed' : 'updated';
        $body   = '';
        $inList = false;

        foreach (preg_split('/\R/', $markdown) as $line) {
            $line = rtrim($line);

            if ($line === '') {
                if ($inList) {
                    $body .= "</ul>\n";
                    $inList = false;
                }
                continue;
            }

            if (preg_match('/^###\s+(.+)$/', $line, $m)) {
                if ($inList) {
                    $body  .= "</ul>\n";
                    $inList = false;
                }
                $body .= '<h5 style="margin-top:1em;margin-bottom:.25em">'
                    . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . "</h5>\n";
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $m)) {
                if (!$inList) {
                    $body  .= "<ul style=\"margin:0 0 .5em 1.5em\">\n";
                    $inList = true;
                }
                $body .= '<li>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . "</li>\n";
                continue;
            }

            if ($inList) {
                $body  .= "</ul>\n";
                $inList = false;
            }
            $body .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "</p>\n";
        }

        if ($inList) {
            $body .= "</ul>\n";
        }

        return '<div class="alert alert-info" style="margin:1em 0;padding:1em;border-left:4px solid #5bc0de">'
            . '<h3 style="margin-top:0">'
            . htmlspecialchars(self::DISPLAY_TITLE . ' ' . $version, ENT_QUOTES, 'UTF-8')
            . ' — ' . $verb . '</h3>'
            . $body
            . '</div>';
    }
}
