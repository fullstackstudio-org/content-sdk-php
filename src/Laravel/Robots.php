<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Http\Response;
use Throwable;

/**
 * `Route::get('robots.txt', fn () => Robots::response())`: robots.txt from the project's SEO settings (delete `public/robots.txt`, the
 * web server serves a static file first).
 *
 * - `robotsIndex` off (a test site): `Disallow: /` for every crawler.
 * - Otherwise everything is allowed and the sitemap is announced (`sitemap` option, default `url('/sitemap.xml')`; `false` leaves it out).
 * - SEO module off: allowed, like a site without Content settings.
 * - Content down: also allowed (200, not cached by the client), so a short outage never stops crawling; a test site is still covered
 *   by the `noindex` of `<x-content::meta>`, which uses the cached SEO settings. Never throws.
 *
 * `extra`: your own lines, added below `User-agent: *` (for example `Disallow: /account`).
 */
final class Robots
{
    /** @param array{sitemap?: string|false, extra?: list<string>} $options */
    public static function response(array $options = []): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];

        try {
            $index = (app(ContentClient::class)->getSeo()['robotsIndex'] ?? true) !== false;
        } catch (ContentException $error) {
            $index = true;

            if (!$error->isModuleDisabled()) {
                $headers['Cache-Control'] = 'no-store';
            }
        } catch (Throwable) {
            $index = true;
            $headers['Cache-Control'] = 'no-store';
        }

        return new Response(self::text($index, $options), 200, $headers);
    }

    /** @param array{sitemap?: string|false, extra?: list<string>} $options */
    public static function text(bool $index, array $options = []): string
    {
        if (!$index) {
            return "User-agent: *\nDisallow: /\n";
        }

        $lines = ['User-agent: *'];

        foreach ($options['extra'] ?? [] as $line) {
            $line = trim(str_replace(["\r", "\n"], ' ', (string) $line));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $lines[] = 'Allow: /';
        $sitemap = $options['sitemap'] ?? url('/sitemap.xml');

        if (is_string($sitemap) && $sitemap !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . str_replace(["\r", "\n"], '', $sitemap);
        }

        return implode("\n", $lines) . "\n";
    }
}
