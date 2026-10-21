<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/**
 * Cache tags (mirror of tags.ts). One place, so the client (which tags its read calls) and the webhook receiver (which
 * invalidates them) use the same names.
 *
 *   list or detail             tags
 *   project                    content:project
 *   articles (list)            content:articles
 *   article (detail)           content:article:<slug or id>, content:categories
 *   categories                 content:categories
 *   pages (list)               content:pages
 *   page (detail)              content:page:<path without slashes or id>, content:media
 *   form                       content:form:<key>
 *   media                      content:media
 *   redirects                  content:redirects
 *   seo                        content:seo
 *   sitemap                    content:sitemap
 *   site standards             content:site
 *   products / product / product categories / appointment services / faq / legal: see the constants below.
 */
final class Tags
{
    public const PROJECT = 'content:project';
    public const ARTICLES = 'content:articles';
    public const CATEGORIES = 'content:categories';
    public const PAGES = 'content:pages';
    public const MEDIA = 'content:media';
    public const REDIRECTS = 'content:redirects';
    public const SEO = 'content:seo';
    public const SITEMAP = 'content:sitemap';
    public const PRODUCTS = 'content:products';
    public const PRODUCT_CATEGORIES = 'content:product-categories';
    public const APPOINTMENT_SERVICES = 'content:appointment-services';
    public const FAQ = 'content:faq';
    public const LEGAL = 'content:legal';
    public const ANALYTICS = 'content:analytics';
    public const SITE = 'content:site';

    /** Tags are case-insensitive: slugs and UUIDs are in the API too. */
    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** `/Services/Web-Design/` becomes `services/web-design`. */
    public static function normalizePagePath(string $path): string
    {
        return trim(self::key($path), '/');
    }

    public static function article(string $slugOrId): string
    {
        return 'content:article:' . self::key($slugOrId);
    }

    public static function page(string $pathOrId): string
    {
        return 'content:page:' . self::normalizePagePath($pathOrId);
    }

    public static function form(string $formKey): string
    {
        return 'content:form:' . self::key($formKey);
    }

    public static function product(string $slugOrId): string
    {
        return 'content:product:' . self::key($slugOrId);
    }

    public static function legalDocument(string $kindSlugOrId): string
    {
        return 'content:legal:' . self::key($kindSlugOrId);
    }

    /**
     * Which tags does this event invalidate? Empty = nothing to do (`webhook.test`, `form.submission.created`, an unknown type
     * from a future module). An event contains only ids and keys; the pages fetch the current state themselves.
     *
     * @param array{type?: mixed, data?: mixed} $event
     * @return list<string>
     */
    public static function forEvent(array $event): array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $type = (string) ($event['type'] ?? '');
        $tags = [];
        $add = static function (array $values) use (&$tags): void {
            foreach ($values as $value) {
                if ($value !== null && !in_array($value, $tags, true)) {
                    $tags[] = $value;
                }
            }
        };
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $withRef = static function (callable $build, mixed ...$refs) use ($text): array {
            return array_map(static fn (mixed $ref): ?string => ($t = $text($ref)) === null ? null : $build($t), $refs);
        };

        if (str_starts_with($type, 'article.')) {
            $add([self::ARTICLES, ...$withRef([self::class, 'article'], $data['slug'] ?? null, $data['previousSlug'] ?? null, $data['id'] ?? null), self::CATEGORIES, self::SITEMAP, self::PROJECT]);
        } elseif (str_starts_with($type, 'category.')) {
            $add([self::CATEGORIES, self::ARTICLES]);
        } elseif (str_starts_with($type, 'page.')) {
            $add([self::PAGES, ...$withRef([self::class, 'page'], $data['slug'] ?? null, $data['previousSlug'] ?? null, $data['id'] ?? null), self::SITEMAP]);
        } elseif (str_starts_with($type, 'media.')) {
            $add([self::MEDIA, self::ARTICLES, self::PAGES]);
        } elseif (str_starts_with($type, 'product.category.')) {
            $add([self::PRODUCT_CATEGORIES, self::PRODUCTS]);
        } elseif (str_starts_with($type, 'product.')) {
            $add([self::PRODUCTS, ...$withRef([self::class, 'product'], $data['slug'] ?? null, $data['previousSlug'] ?? null, $data['id'] ?? null), self::SITEMAP]);
        } elseif (str_starts_with($type, 'shipping.method.') || str_starts_with($type, 'discount.')) {
            // Read per call, never cached: nothing to invalidate.
        } elseif (str_starts_with($type, 'review.')) {
            $add([self::PRODUCTS, ...$withRef([self::class, 'product'], $data['slug'] ?? null, $data['productId'] ?? null)]);
        } elseif (str_starts_with($type, 'faq.')) {
            $add([self::FAQ]);
        } elseif ($type === 'form.created' || $type === 'form.updated' || $type === 'form.deleted') {
            $add([...$withRef([self::class, 'form'], $data['key'] ?? null)]);
        } elseif (str_starts_with($type, 'redirect.')) {
            $add([self::REDIRECTS]);
        } elseif ($type === 'site.updated') {
            $add([self::SITE]);
        } elseif ($type === 'seo.updated') {
            $add([self::SEO, self::SITEMAP]);
        } elseif (str_starts_with($type, 'legal.document.') || $type === 'legal.version.published') {
            $add([self::LEGAL, ...$withRef([self::class, 'legalDocument'], $data['kind'] ?? null, $data['slug'] ?? null, $data['previousSlug'] ?? null, $data['id'] ?? null)]);
        }

        return $tags;
    }
}
