<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Plugin\Sensomcp\Content\Support;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/**
 * Construction des URL publiques (SEF) d'une entité de contenu pour les réponses
 * MCP. Une entité `language="*"` étant accessible sous CHAQUE langue de contenu
 * active, on renvoie une URL par langue (map `urls`, clé = code langue) en plus
 * de `url` (langue par défaut). Chaque URL est bâtie par le routeur puis résolue
 * en suivant la redirection 301 canonique du site — de sorte que les alias SEF
 * traduits par un éventuel plugin multilingue (côté parse) soient repris
 * automatiquement, sans couplage. Repli systématique sur l'URL construite.
 *
 * La classe utilisatrice doit exposer `db(): DatabaseInterface`.
 */
trait ResolvesPublicUrls
{
    /** @var list<string>|null Cache par requête des langues de contenu actives. */
    private ?array $activeLanguagesCache = null;

    /**
     * Injecte `url` (+ `urls` si multilingue) dans un résultat, à partir de la
     * requête interne d'une entité (SANS `&lang`) et de sa langue.
     *
     * @param array<string,mixed> $result
     * @param string              $queryBase ex. index.php?option=com_content&view=article&id=5:alias&catid=9
     * @param string              $language  langue de l'entité (`*` = toutes)
     * @return array<string,mixed>
     */
    private function withPublicUrls(array $result, string $queryBase, string $language): array
    {
        $data          = $this->publicUrlData($queryBase, $language);
        $result['url'] = $data['url'];

        if ($data['urls'] !== []) {
            $result['urls'] = $data['urls'];
        }

        return $result;
    }

    /**
     * @return array{url:string, urls:array<string,string>}
     */
    private function publicUrlData(string $queryBase, string $language): array
    {
        $langs = ($language === '*') ? $this->activeContentLanguages() : [(string) $language];
        $built = [];

        foreach ($langs as $code) {
            $query        = $queryBase . ($code !== '' && $code !== '*' ? '&lang=' . $code : '');
            $built[$code] = Route::link('site', $query, false, Route::TLS_IGNORE, true);
        }

        if ($built === []) {
            return ['url' => '', 'urls' => []];
        }

        $urls    = $this->resolvePublicUrls($built);
        $default = $urls[$this->defaultContentLanguage()] ?? reset($urls);

        // Map exposée seulement pour une entité réellement multilingue (`*` + ≥2 langues).
        return ['url' => (string) $default, 'urls' => \count($urls) > 1 ? $urls : []];
    }

    /**
     * Résout en PARALLÈLE (curl_multi) l'URL canonique de chaque URL construite,
     * en suivant les redirections 301 du site (alias SEF traduits côté parse par
     * un éventuel plugin multilingue). Parallèle = le temps total ≈ la requête la
     * plus lente (un rendu), et non leur somme ; chaque appel dispose d'un délai
     * généreux — ce qui supprime les faux time-outs qui, en séquentiel avec un
     * TIMEOUT court, faisaient retomber sur l'URL NON traduite lors des requêtes
     * lourdes (écritures) alors que les lectures passaient. Repli PAR ENTRÉE sur
     * l'URL construite en cas d'échec cURL/HTTP. Ne casse jamais l'appel.
     *
     * @param  array<string,string> $built code langue => URL construite (SEF brut)
     * @return array<string,string>        code langue => URL canonique (ou construite)
     */
    private function resolvePublicUrls(array $built): array
    {
        $out = $built; // repli par défaut, entrée par entrée

        if (!\function_exists('curl_multi_init')) {
            return $out;
        }

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($built as $code => $url) {
            if ($url === '' || !str_starts_with($url, 'http')) {
                continue;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,     // borne dure : coupe toute boucle 301
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 20,    // large : couvre une TRADUCTION LLM à froid (alias/page) ; parallèle → pas cumulé
                CURLOPT_SSL_VERIFYPEER => false, // requête vers notre propre site
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT      => 'SensoMCP-UrlResolver',
            ]);
            $handles[$code] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        try {
            do {
                $status = curl_multi_exec($mh, $running);

                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $code => $ch) {
                $errno = curl_errno($ch);
                $hc    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $eff   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

                if ($errno === 0 && $eff !== '' && $hc >= 200 && $hc < 400) {
                    $out[$code] = $eff;
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        } catch (\Throwable) {
            // repli déjà en place
        } finally {
            curl_multi_close($mh);
        }

        return $out;
    }

    /**
     * Codes des langues de contenu actives (publiées), hors « toutes langues ».
     *
     * @return list<string>
     */
    private function activeContentLanguages(): array
    {
        if ($this->activeLanguagesCache !== null) {
            return $this->activeLanguagesCache;
        }

        $out = [];

        try {
            $db = $this->db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName('lang_code'))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('ordering') . ' ASC');
            $out = array_values(array_filter(
                $db->setQuery($q)->loadColumn() ?: [],
                static fn ($c): bool => $c !== '' && $c !== '*'
            ));
        } catch (\Throwable) {
            // liste vide
        }

        return $this->activeLanguagesCache = $out;
    }

    /** Code de la langue de contenu par défaut du site. */
    private function defaultContentLanguage(): string
    {
        try {
            return (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        } catch (\Throwable) {
            return 'en-GB';
        }
    }
}
