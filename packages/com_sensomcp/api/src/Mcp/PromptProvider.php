<?php
/**
 * @copyright (C) 2026 Sensomedia. All rights reserved.
 * @license   GNU General Public License version 2 or later
 */

namespace Sensomedia\Component\Sensomcp\Api\Mcp;

\defined('_JEXEC') or die;

/**
 * Fournisseur de prompts MCP : workflows guidés pré-écrits (rédaction, audit
 * SEO, traduction d'article) que l'utilisateur lance depuis son client MCP.
 * Textes en anglais (destinés au LLM). Minimal, pour la parité.
 */
final class PromptProvider
{
    /** @return list<array{name:string, description:string, arguments:array}> */
    public function list(): array
    {
        return [
            [
                'name'        => 'draft_article',
                'description' => 'Guided workflow to draft and (optionally) publish a Joomla article.',
                'arguments'   => [
                    ['name' => 'topic', 'description' => 'What the article is about.', 'required' => true],
                    ['name' => 'category', 'description' => 'Target category name or id.', 'required' => false],
                ],
            ],
            [
                'name'        => 'seo_audit',
                'description' => 'Audit articles for SEO issues and propose fixes.',
                'arguments'   => [
                    ['name' => 'category_id', 'description' => 'Restrict the audit to a category id.', 'required' => false],
                ],
            ],
            [
                'name'        => 'translate_article',
                'description' => 'Translate an article into another language and link the two as associations.',
                'arguments'   => [
                    ['name' => 'article_id', 'description' => 'Article to translate.', 'required' => true],
                    ['name' => 'target_language', 'description' => 'Target language code (e.g. en-GB).', 'required' => true],
                ],
            ],
        ];
    }

    /**
     * @return array{description:string, messages: list<array{role:string, content:array}>}
     */
    public function get(string $name, array $args): array
    {
        $text = match ($name) {
            'draft_article'     => $this->draftArticle($args),
            'seo_audit'         => $this->seoAudit($args),
            'translate_article' => $this->translateArticle($args),
            default             => throw new \RuntimeException('Unknown prompt: ' . $name),
        };

        return [
            'description' => 'Senso MCP guided workflow.',
            'messages'    => [[
                'role'    => 'user',
                'content' => ['type' => 'text', 'text' => $text],
            ]],
        ];
    }

    private function draftArticle(array $args): string
    {
        $topic    = trim((string) ($args['topic'] ?? ''));
        $category = trim((string) ($args['category'] ?? ''));

        $text = "Draft a new Joomla article about: \"$topic\".\n\n"
            . "Steps:\n"
            . "1. Use list_categories to pick a suitable category"
            . ($category !== '' ? " (prefer \"$category\")" : '') . ".\n"
            . "2. Check the custom fields for that category with list_article_fields.\n"
            . "3. Write a well-structured article (intro, headings, conclusion) with an SEO meta description "
            . "(<= 160 chars) and a clear title.\n"
            . "4. Create it UNPUBLISHED with create_article (set metadesc, catid, and fields). Insert "
            . "<hr id=\"system-readmore\"> after the intro.\n"
            . "5. Show me the draft and the public URL, and ask me before publishing (set_article_state).";

        return $text;
    }

    private function seoAudit(array $args): string
    {
        $catid = (int) ($args['category_id'] ?? 0);

        return "Run an SEO audit of the articles"
            . ($catid > 0 ? " in category $catid" : '') . ".\n\n"
            . "Steps:\n"
            . "1. Call seo_audit_articles" . ($catid > 0 ? " with catid=$catid" : '') . ".\n"
            . "2. Call check_internal_links to find broken internal article links.\n"
            . "3. Summarize the findings as a prioritized list. For each article with a missing or too-long "
            . "meta description, propose a better one (<= 160 chars).\n"
            . "4. Ask me which fixes to apply (via update_article) before changing anything.";
    }

    private function translateArticle(array $args): string
    {
        $id   = (int) ($args['article_id'] ?? 0);
        $lang = trim((string) ($args['target_language'] ?? ''));

        return "Translate article #$id into $lang.\n\n"
            . "Steps:\n"
            . "1. Read the source with get_article (id=$id).\n"
            . "2. Create the translation with create_article: same catid, language=\"$lang\", translated "
            . "title/introtext/fulltext/metadesc, keep images and tags.\n"
            . "3. Link the two versions with set_associations (type=article, ids=[$id, <new id>]).\n"
            . "4. Report both article ids and their public URLs. Keep the translation unpublished until I confirm.";
    }
}
