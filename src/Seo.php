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
     * @param array{baseUrl?: string, path?: string, type?: 'website'|'article', fallbackImage?: array<string, mixed>|null} $options `fallbackImage`
     *        (`{url, width?, height?, alt?}`, e.g. `site.social.ogImage` from `getSite()`) is used when neither the page nor the SEO settings have an image
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
        $media = ($page['cover'] ?? null) ?: ($seo['defaultOgImage'] ?? null) ?: ($options['fallbackImage'] ?? null);
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

    /**
     * Applies site overrides (`title`, `description`, `image`, `canonical`, `noindex` (bool or a robots string), `ogType`) over a meta array from {@see self::meta()}. Overrides are
     * literal: the title template is not applied again. `image` is a URL string or `{url, width?, height?, alt?}`. Empty values are ignored.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function override(array $meta, array $overrides, string $baseUrl = ClientOptions::DEFAULT_BASE_URL): array
    {
        $og = is_array($meta['openGraph'] ?? null) ? $meta['openGraph'] : [];
        $twitter = is_array($meta['twitter'] ?? null) ? $meta['twitter'] : [];

        foreach (['title', 'description'] as $key) {
            $value = self::filled($overrides[$key] ?? null);

            if ($value !== null) {
                $meta[$key] = $value;
                $og[$key] = $value;
                $twitter[$key] = $value;
            }
        }

        $canonical = self::filled($overrides['canonical'] ?? null);

        if ($canonical !== null) {
            $meta['canonical'] = $canonical;
            $og['url'] = $canonical;
        }

        $image = $overrides['image'] ?? null;
        $image = is_string($image) && $image !== '' ? ['url' => $image] : $image;

        if (is_array($image) && self::filled($image['url'] ?? null) !== null) {
            $image['url'] = self::absolute((string) $image['url'], $baseUrl);
            $og['image'] = self::clean(array_intersect_key($image, array_flip(['url', 'width', 'height', 'alt'])));
            $twitter['image'] = $image['url'];
            $twitter['card'] = 'summary_large_image';
        }

        // `noindex` is true (`noindex, nofollow`) or the robots value itself (`noindex, follow`).
        $noindex = $overrides['noindex'] ?? false;

        if ($noindex === true) {
            $meta['robots'] = 'noindex, nofollow';
        } elseif (is_string($noindex) && trim($noindex) !== '') {
            $meta['robots'] = trim($noindex);
        }

        $ogType = self::filled($overrides['ogType'] ?? null);

        if ($ogType !== null) {
            $og['type'] = $ogType;
        }

        $meta['openGraph'] = self::clean($og);
        $meta['twitter'] = self::clean($twitter);

        return self::clean($meta);
    }

    /**
     * `<link rel="alternate" hreflang>` tags. `$alternates` is a list of `{locale, url}` (absolute URLs); `$default` is the URL of the default
     * locale version, printed as `x-default` when there is one. Fewer than two versions give an empty string (one version is no alternate).
     *
     * @param list<array{locale: string, url: string}> $alternates
     */
    public static function hreflang(array $alternates, ?string $default = null): string
    {
        if (count($alternates) < 2) {
            return '';
        }

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = array_map(
            static fn (array $alternate): string => '<link rel="alternate" hreflang="' . $e($alternate['locale']) . '" href="' . $e($alternate['url']) . '">',
            $alternates,
        );

        if ($default !== null && $default !== '') {
            $lines[] = '<link rel="alternate" hreflang="x-default" href="' . $e($default) . '">';
        }

        return implode("\n", $lines);
    }

    /**
     * Schema.org JSON-LD for an article or an event, as a complete `<script type="application/ld+json">` tag, or '' when the resource has no
     * usable title. Only fields that exist are printed. `$url` is the absolute canonical URL (`mainEntityOfPage` for articles, `url` for events).
     *
     * @param 'article'|'event' $type
     * @param array<string, mixed> $resource
     */
    public static function jsonLd(string $type, array $resource, ?string $url = null, string $baseUrl = ClientOptions::DEFAULT_BASE_URL, ?string $nonce = null): string
    {
        $title = self::filled($resource['title'] ?? null);

        if ($title === null) {
            return '';
        }

        $cover = is_array($resource['cover'] ?? null) ? self::filled($resource['cover']['url'] ?? null) : null;
        $image = $cover !== null ? self::absolute($cover, $baseUrl) : null;

        if ($type === 'article') {
            $author = self::filled($resource['authorName'] ?? null);
            $data = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $title,
                'datePublished' => self::filled($resource['publishedAt'] ?? null),
                'dateModified' => self::filled($resource['updatedAt'] ?? null),
                'author' => $author !== null ? ['@type' => 'Person', 'name' => $author] : null,
                'image' => $image,
                'mainEntityOfPage' => $url !== null && $url !== '' ? ['@type' => 'WebPage', '@id' => $url] : null,
            ];
        } else {
            $location = self::filled($resource['location'] ?? null);
            $data = [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $title,
                'startDate' => self::filled($resource['startsAt'] ?? null),
                'endDate' => self::filled($resource['endsAt'] ?? null),
                'location' => $location !== null ? ['@type' => 'Place', 'name' => $location] : null,
                'image' => $image,
                'eventStatus' => ($resource['status'] ?? null) === 'cancelled' ? 'https://schema.org/EventCancelled' : null,
                'url' => $url,
            ];
        }

        $json = json_encode(self::clean($data), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (! is_string($json)) {
            return '';
        }

        $attribute = $nonce !== null && $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';

        return '<script type="application/ld+json"' . $attribute . '>' . $json . '</script>';
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
