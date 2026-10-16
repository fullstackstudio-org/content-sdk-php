<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

/** Builds the sitemap from `GET /api/v1/sitemap` (mirror of `contentSitemap` in `next.ts`). */
final class Sitemap
{
    /**
     * @param array{baseUrl?: string, locale?: string, localePrefix?: callable(string): string, entryPath?: callable(array<string, mixed>): ?string, extra?: list<array{url: string, lastmod?: string|null}>} $options
     *   `baseUrl` defaults to the project's `canonicalOrigin`; `localePrefix` maps a locale to a path prefix (Content returns paths without one);
     *   `entryPath` gives your own path per entry (null skips it); `extra` are entries of your own (the homepage, ...).
     * @return list<array{url: string, lastmod: string|null}> empty when `robotsIndex` is off (a test site)
     */
    public static function entries(ContentClient $client, array $options = []): array
    {
        $sitemap = $client->getSitemap(isset($options['locale']) ? ['locale' => $options['locale']] : []);

        if (empty($sitemap['robotsIndex'])) {
            return [];
        }

        $origin = rtrim((string) ($options['baseUrl'] ?? $sitemap['canonicalOrigin'] ?? ''), '/');

        if ($origin === '') {
            throw new ContentException(0, 'invalid_config', "Sitemap: pass `baseUrl`, or set a `canonicalOrigin` in the project's SEO settings.");
        }

        $entries = [];

        foreach ($options['extra'] ?? [] as $extra) {
            $entries[] = ['url' => $extra['url'], 'lastmod' => $extra['lastmod'] ?? null];
        }

        foreach ($sitemap['entries'] ?? [] as $entry) {
            $path = isset($options['entryPath']) ? ($options['entryPath'])($entry) : ($entry['path'] ?? null);

            if ($path === null) {
                continue;
            }

            $prefix = isset($options['localePrefix']) ? ($options['localePrefix'])((string) ($entry['locale'] ?? '')) : '';
            $entries[] = ['url' => $origin . $prefix . (str_starts_with($path, '/') ? '' : '/') . $path, 'lastmod' => $entry['lastmod'] ?? null];
        }

        return $entries;
    }

    /** `sitemap.xml` for these entries (everything escaped). @param list<array{url: string, lastmod?: string|null}> $entries */
    public static function xml(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $entry) {
            $xml .= '  <url><loc>' . htmlspecialchars($entry['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';

            if (!empty($entry['lastmod'])) {
                $xml .= '<lastmod>' . htmlspecialchars((string) $entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
            }

            $xml .= "</url>\n";
        }

        return $xml . "</urlset>\n";
    }
}
