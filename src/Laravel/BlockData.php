<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Site\Urls;
use Throwable;

/**
 * The data behind the default views of the blocks that only describe what to show (`articleList`, `legalDocument`). Each method fetches
 * through the (cached) client and returns null on any failure, so a block view can render nothing instead of breaking the page.
 *
 * @internal used by `content::blocks.articleList` and `content::blocks.legalDocument`
 */
final class BlockData
{
    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    private const LOCALE = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D';

    private const LEGAL_KIND = '/^[a-z][a-z_]{0,39}$/D';

    /**
     * Published articles for an `articleList` block: `[{title, excerpt, date (ISO or null), cover (media or null), href}]`, at most the block's
     * `limit` (1 to 20, default 6). The link follows the Hub's SEO `articlePathTemplate` (default `/blog/{slug}`). Null on failure or when empty.
     *
     * @param array<string, mixed> $block
     * @return list<array{title: string, excerpt: string, date: ?string, cover: ?array<string, mixed>, href: string}>|null
     */
    public static function articleList(array $block): ?array
    {
        $limit = is_int($block['limit'] ?? null) ? max(1, min(20, $block['limit'])) : 6;
        $category = is_string($block['category'] ?? null) && preg_match(self::SLUG, $block['category']) === 1 ? $block['category'] : null;
        $locale = is_string($block['locale'] ?? null) && preg_match(self::LOCALE, $block['locale']) === 1 ? $block['locale'] : null;

        try {
            $client = app(ContentClient::class);
            // A server token also sees drafts: ask for published articles only.
            $list = $client->listArticles(['status' => 'published', 'category' => $category, 'locale' => $locale, 'limit' => $limit]);
            $urls = app(Urls::class);
            $urls->articleTemplate();
        } catch (Throwable) {
            return null;
        }

        $cards = [];

        foreach (array_slice($list['articles'] ?? [], 0, $limit) as $article) {
            $slug = is_array($article) ? ($article['slug'] ?? null) : null;

            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $cards[] = [
                'title' => (string) ($article['title'] ?? ''),
                'excerpt' => (string) ($article['excerpt'] ?? ''),
                'date' => is_string($article['publishedAt'] ?? null) ? $article['publishedAt'] : null,
                'cover' => is_array($article['cover'] ?? null) ? $article['cover'] : null,
                'href' => $urls->article($article),
            ];
        }

        return $cards === [] ? null : $cards;
    }

    /**
     * The current version of the legal document of a `legalDocument` block (`kind`: `terms`, `privacy_statement`, ...): `{title, bodyHtml,
     * effectiveAt}`. Null when the module is off, nothing is in force yet, or the Hub cannot be reached.
     *
     * @param array<string, mixed> $block
     * @return array{title: string, bodyHtml: string, effectiveAt: ?string}|null
     */
    public static function legalDocument(array $block): ?array
    {
        $kind = $block['kind'] ?? null;

        if (!is_string($kind) || preg_match(self::LEGAL_KIND, $kind) !== 1) {
            return null;
        }

        try {
            $document = app(ContentClient::class)->legal->get($kind);
        } catch (Throwable) {
            return null;
        }

        $body = is_string($document['bodyHtml'] ?? null) ? $document['bodyHtml'] : '';

        if ($body === '') {
            return null;
        }

        return [
            'title' => (string) ($document['title'] ?? ''),
            'bodyHtml' => $body,
            'effectiveAt' => is_string($document['effectiveAt'] ?? null) ? $document['effectiveAt'] : null,
        ];
    }

    /** A date as `12 november 2026` in the app locale; the raw value when it cannot be parsed. */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($iso)->locale(app()->getLocale() ?: 'nl')->isoFormat('D MMMM YYYY');
        } catch (Throwable) {
            return $iso;
        }
    }
}
