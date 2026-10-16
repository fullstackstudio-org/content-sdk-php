<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/** SEO meta from the project's settings and one page or article (mirror of `contentMetadata` in `next.ts`). */
final class Seo
{
    /**
     * Converts `$client->getSeo()` and optionally the SEO fields of one page or article into a plain meta array: title (with the
     * project's title template), description, canonical, robots, Open Graph and Twitter. Render it with {@see self::html()}.
     *
     * @param array<string, mixed>|null $seo
     * @param array<string, mixed>|null $page a page or article (`title`, `seoTitle`, `seoDescription`, `excerpt`, `cover`, `publishedAt`, `updatedAt`, `authorName`)
     * @param array{baseUrl?: string, path?: string, type?: 'website'|'article'} $options
     * @return array<string, mixed>
     */
    public static function meta(?array $seo, ?array $page = null, array $options = []): array
    {
        $baseUrl = $options['baseUrl'] ?? ClientOptions::DEFAULT_BASE_URL;
        $rawTitle = $page !== null ? (self::filled($page['seoTitle'] ?? null) ?? ($page['title'] ?? null)) : ($seo['siteName'] ?? null);
        $template = $seo['titleTemplate'] ?? null;
        $title = $rawTitle !== null && $rawTitle !== ''
            ? ($page !== null && is_string($template) && str_contains($template, '%s') ? str_replace('%s', (string) $rawTitle, $template) : (string) $rawTitle)
            : null;
        $description = self::filled($page['seoDescription'] ?? null) ?? self::filled($page['excerpt'] ?? null) ?? self::filled($seo['defaultDescription'] ?? null);
        $media = ($page['cover'] ?? null) ?: ($seo['defaultOgImage'] ?? null);
        $image = is_array($media) && isset($media['url'])
            ? array_filter([
                'url' => self::absolute((string) $media['url'], $baseUrl),
                'width' => $media['width'] ?? null,
                'height' => $media['height'] ?? null,
                'alt' => self::filled($media['alt'] ?? null),
            ], static fn (mixed $v): bool => $v !== null)
            : null;
        $path = $options['path'] ?? null;
        $origin = $seo['canonicalOrigin'] ?? null;
        $canonical = $path !== null && $path !== '' ? (is_string($origin) && $origin !== '' ? rtrim($origin, '/') . $path : $path) : null;
        $isArticle = ($options['type'] ?? 'website') === 'article';
        $siteName = $seo['siteName'] ?? null;
        $openGraph = [
            'title' => $title,
            'description' => $description,
            'siteName' => $siteName,
            'type' => $isArticle ? 'article' : 'website',
            'url' => $canonical,
            'image' => $image,
        ];

        if ($isArticle) {
            $openGraph['publishedTime'] = $page['publishedAt'] ?? null;
            $openGraph['modifiedTime'] = $page['updatedAt'] ?? null;
            $openGraph['author'] = $page['authorName'] ?? null;
        }

        return self::clean([
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => ($seo !== null && ($seo['robotsIndex'] ?? true) === false) ? 'noindex, nofollow' : null,
            'openGraph' => self::clean($openGraph),
            'twitter' => self::clean([
                'card' => $image !== null ? 'summary_large_image' : 'summary',
                'title' => $title,
                'description' => $description,
                'site' => $seo['twitterSite'] ?? null,
                'image' => $image['url'] ?? null,
            ]),
        ]);
    }

    /** Renders a meta array as HTML for `<head>` (everything escaped). @param array<string, mixed> $meta */
    public static function html(array $meta): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [];
        $name = static function (string $key, mixed $value) use (&$lines, $e): void {
            if ($value !== null && $value !== '') {
                $lines[] = '<meta name="' . $e($key) . '" content="' . $e($value) . '">';
            }
        };
        $property = static function (string $key, mixed $value) use (&$lines, $e): void {
            if ($value !== null && $value !== '') {
                $lines[] = '<meta property="' . $e($key) . '" content="' . $e($value) . '">';
            }
        };

        if (isset($meta['title'])) {
            $lines[] = '<title>' . $e($meta['title']) . '</title>';
        }

        $name('description', $meta['description'] ?? null);
        $name('robots', $meta['robots'] ?? null);

        if (isset($meta['canonical'])) {
            $lines[] = '<link rel="canonical" href="' . $e($meta['canonical']) . '">';
        }

        $og = $meta['openGraph'] ?? [];

        foreach (['title', 'description', 'type', 'url'] as $key) {
            $property('og:' . $key, $og[$key] ?? null);
        }

        $property('og:site_name', $og['siteName'] ?? null);
        $property('og:image', $og['image']['url'] ?? null);
        $property('og:image:width', $og['image']['width'] ?? null);
        $property('og:image:height', $og['image']['height'] ?? null);
        $property('og:image:alt', $og['image']['alt'] ?? null);
        $property('article:published_time', $og['publishedTime'] ?? null);
        $property('article:modified_time', $og['modifiedTime'] ?? null);
        $property('article:author', $og['author'] ?? null);

        $twitter = $meta['twitter'] ?? [];

        foreach (['card', 'title', 'description', 'site', 'image'] as $key) {
            $name('twitter:' . $key, $twitter[$key] ?? null);
        }

        return implode("\n", $lines);
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function absolute(string $url, string $baseUrl): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : rtrim($baseUrl, '/') . (str_starts_with($url, '/') ? '' : '/') . $url;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function clean(array $value): array
    {
        return array_filter($value, static fn (mixed $item): bool => $item !== null);
    }
}
