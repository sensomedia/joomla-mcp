<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\System\SensomcpChangelog\Changelog;

\defined('_JEXEC') or die;

/**
 * Logique partagée du changelog Senso MCP (source unique = CHANGELOG.md,
 * anglais). Sans état, sans dépendance Joomla : réutilisable par le postflight
 * d'installation, la génération des notes ARS, et l'endpoint dynamique du store.
 *
 * Représentation interne d'une version :
 *   ['version' => '1.2.0', 'sections' => [ ['head' => 'Added', 'items' => [...]] ]]
 *
 * Correspondance en-tête Markdown -> catégorie de changelog Joomla :
 *   Added->addition, Fixed->fix, Changed->change, Security->security,
 *   Language->language, Removed->remove, Note(s)->note (défaut : note).
 */
final class ChangelogParser
{
    /** Ordre canonique des catégories dans le XML Joomla. */
    public const XML_CATEGORIES = ['security', 'fix', 'language', 'addition', 'change', 'remove', 'note'];

    private const HEAD_MAP = [
        'added'    => 'addition',
        'fixed'    => 'fix',
        'changed'  => 'change',
        'security' => 'security',
        'language' => 'language',
        'removed'  => 'remove',
        'note'     => 'note',
        'notes'    => 'note',
    ];

    /**
     * Parse un CHANGELOG.md complet en une liste de versions (ordre du fichier,
     * antéchronologique). Gère les puces multi-lignes (continuation indentée).
     *
     * @return array<int, array{version: string, sections: array<int, array{head: string, items: array<int, string>}>}>
     */
    public static function parseMarkdown(string $md): array
    {
        $versions = [];
        $vi = -1;
        $si = -1;

        foreach (explode("\n", $md) as $line) {
            // `## X.Y.Z …` (titre de version) mais pas `### …`
            if (strncmp($line, '###', 3) !== 0 && preg_match('/^##\s+(\S+)/', $line, $m)) {
                $versions[] = ['version' => $m[1], 'sections' => []];
                $vi = \count($versions) - 1;
                $si = -1;
                continue;
            }

            if ($vi < 0) {
                continue;
            }

            if (preg_match('/^###\s+(.+?)\s*$/', $line, $m)) {
                $versions[$vi]['sections'][] = ['head' => trim($m[1]), 'items' => []];
                $si = \count($versions[$vi]['sections']) - 1;
                continue;
            }

            if ($si < 0) {
                continue;
            }

            if (preg_match('/^-\s+(.*)$/', $line, $m)) {
                $versions[$vi]['sections'][$si]['items'][] = trim($m[1]);
            } elseif (trim($line) !== '' && preg_match('/^\s+\S/', $line)
                && $versions[$vi]['sections'][$si]['items']) {
                $last = \count($versions[$vi]['sections'][$si]['items']) - 1;
                $versions[$vi]['sections'][$si]['items'][$last] .= ' ' . trim($line);
            }
        }

        return $versions;
    }

    /** Extrait les sections d'UNE version d'un CHANGELOG.md, [] si absente. */
    public static function sectionsForVersion(string $md, string $version): array
    {
        foreach (self::parseMarkdown($md) as $v) {
            if ($v['version'] === $version) {
                return $v['sections'];
            }
        }

        return [];
    }

    public static function headToTag(string $head): string
    {
        $h = strtolower(trim($head));

        if (isset(self::HEAD_MAP[$h])) {
            return self::HEAD_MAP[$h];
        }

        foreach (self::HEAD_MAP as $word => $tag) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $h)) {
                return $tag;
            }
        }

        return 'note';
    }

    /**
     * Notes ARS ÉPURÉES pour une version (champ #__ars_releases.notes) :
     *   <div class="uk-h5">Added</div><ul><li>…</li></ul>…
     * Pas de wrapper alert, pas de version en titre, catégories en uk-h5
     * (évite les problèmes de séquence h1-h6). Rendu inline `**gras**` / `code`.
     *
     * @param array<int, array{head: string, items: array<int, string>}> $sections
     */
    public static function renderNotesHtml(array $sections): string
    {
        $html = '';

        foreach ($sections as $s) {
            $html .= '<div class="uk-h5">' . self::escHtml($s['head']) . '</div><ul>';

            foreach ($s['items'] as $it) {
                $html .= '<li>' . self::inlineHtml($it) . '</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * changelog.xml Joomla (<changelogs> + un <changelog> par version) pour
     * l'élément donné. `$folder` requis pour un plugin (= groupe).
     *
     * @param array<int, array{version: string, sections: array<int, array{head: string, items: array<int, string>}>}> $versions
     */
    public static function renderChangelogXml(array $versions, string $element, string $type, ?string $folder = null): string
    {
        $out = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<changelogs>\n";

        foreach ($versions as $v) {
            $out .= "\t<changelog>\n";
            $out .= "\t\t<element>" . self::escXml($element) . "</element>\n";
            $out .= "\t\t<type>" . self::escXml($type) . "</type>\n";

            if ($folder !== null && $folder !== '') {
                $out .= "\t\t<folder>" . self::escXml($folder) . "</folder>\n";
            }

            $out .= "\t\t<version>" . self::escXml($v['version']) . "</version>\n";

            $byCat = [];

            foreach ($v['sections'] as $s) {
                $tag = self::headToTag($s['head']);

                foreach ($s['items'] as $it) {
                    $byCat[$tag][] = $it;
                }
            }

            foreach (self::XML_CATEGORIES as $tag) {
                if (empty($byCat[$tag])) {
                    continue;
                }

                $out .= "\t\t<$tag>\n";

                foreach ($byCat[$tag] as $it) {
                    // Items de changelog = texte brut (on retire l'inline markdown/HTML).
                    $out .= "\t\t\t<item>" . self::escXml(self::stripInline($it)) . "</item>\n";
                }

                $out .= "\t\t</$tag>\n";
            }

            $out .= "\t</changelog>\n";
        }

        return $out . "</changelogs>\n";
    }

    /**
     * Inverse de renderNotesHtml : reconstruit les sections d'une version à
     * partir de notes ARS au format épuré. Sert à l'endpoint dynamique du
     * store (source = #__ars_releases.notes). Tolérant : ignore ce qui ne
     * correspond pas au format attendu.
     *
     * @return array<int, array{head: string, items: array<int, string>}>
     */
    public static function parseNotesHtml(string $html): array
    {
        $sections = [];

        if (!preg_match_all(
            '~<div class="uk-h5">(.*?)</div>\s*<ul>(.*?)</ul>~is',
            $html,
            $blocks,
            PREG_SET_ORDER
        )) {
            return $sections;
        }

        foreach ($blocks as $b) {
            $items = [];

            if (preg_match_all('~<li>(.*?)</li>~is', $b[2], $lis)) {
                foreach ($lis[1] as $li) {
                    $items[] = trim(html_entity_decode(strip_tags($li), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }

            $sections[] = [
                'head'  => trim(html_entity_decode(strip_tags($b[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'items' => $items,
            ];
        }

        return $sections;
    }

    // --- Rendu / échappement ------------------------------------------------

    private static function escXml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Contenu d'élément HTML : & < > seulement (les guillemets sont sûrs). */
    private static function escHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    }

    /** Échappe puis rend `code` et **gras** (marqueurs ASCII intacts après échappement). */
    private static function inlineHtml(string $s): string
    {
        $s = self::escHtml($s);
        $s = preg_replace_callback('/`([^`]+)`/', static fn ($m) => '<code>' . $m[1] . '</code>', $s) ?? $s;

        return preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    }

    /** Retire l'inline markdown (`code`, **gras**) pour le texte brut du XML. */
    private static function stripInline(string $s): string
    {
        $s = preg_replace('/`([^`]+)`/', '$1', $s) ?? $s;

        return preg_replace('/\*\*([^*]+)\*\*/', '$1', $s) ?? $s;
    }
}
