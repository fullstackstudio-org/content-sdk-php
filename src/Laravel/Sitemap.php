<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Site\Urls;
use FullStackStudio\Content\Sitemap as SitemapBuilder;
use Illuminate\Http\Response;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `Route::get('sitemap.xml', fn () => Sitemap::response([...]))`: the sitemap of the published content as XML.
 *
 * Never throws: with the SEO module off it lists only your `extra` entries; when Content is down it answers 503 with `Retry-After` (a
 * crawler tries again later) and logs a warning. Without a `canonicalOrigin` in Content and without `baseUrl`, the app's own origin is used.
 */
final class Sitemap
{
    /** Route default set by `->noSitemap()`. A default is part of the route, so it survives `route:cache`. */
    public const NO_SITEMAP = '_fsc_no_sitemap';

    /** `Cache-Control` of an answered sitemap or robots.txt (the plan's cache table). */
    public const PUBLIC_CACHE = 'public, max-age=900';

    /** Registers `Route::get(...)->noSitemap()`: keeps a route out of the automatic sitemap. Called by the service provider. */
    public static function registerRouteMacro(): void
    {
        Route::macro('noSitemap', function (): Route {
            /** @var Route $this */
            return $this->defaults(Sitemap::NO_SITEMAP, true);
        });
    }

    /** @param array<string, mixed> $options see {@see SitemapBuilder::entries()} */
    public static function response(array $options = []): Response
    {
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];

        try {
            $entries = SitemapBuilder::entries(app(ContentClient::class), ['fallbackBaseUrl' => url('/'), ...$options]);
        } catch (ContentException $error) {
            if (!$error->isModuleDisabled()) {
                return self::unavailable($error, $headers);
            }

            $entries = [];

            foreach ($options['extra'] ?? [] as $extra) {
                $entries[] = ['url' => $extra['url'], 'lastmod' => $extra['lastmod'] ?? null];
            }
        } catch (Throwable $error) {
            return self::unavailable($error, $headers);
        }

        return new Response(SitemapBuilder::xml($entries), 200, $headers);
    }

    /**
     * The sitemap of the out of the box site (`content.auto.sitemap`): the site's own static pages ({@see ownPaths()}) plus the Hub entries, every
     * URL built by {@see Urls} (locale prefix, trailing slash) and de-duplicated by URL.
     *
     * While the project's `robotsIndex` is off the `urlset` stays empty and says so in an XML comment. A Hub failure gives 503 with
     * `Retry-After` and `no-store`; an answered sitemap is `public, max-age=900`. Never throws.
     */
    public static function automatic(): Response
    {
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];

        try {
            $client = app(ContentClient::class);

            try {
                $hub = $client->getSitemap();
            } catch (ContentException $error) {
                if (!$error->isModuleDisabled()) {
                    throw $error;
                }

                // No SEO module: like a site without settings, indexable, and only the own pages are known.
                $hub = ['robotsIndex' => true, 'entries' => []];
            }

            if (empty($hub['robotsIndex'])) {
                $xml = SitemapBuilder::xml([], 'Empty on purpose: robotsIndex is off for this project (a test site), so no URL is announced.');

                return new Response($xml, 200, [...$headers, 'Cache-Control' => self::PUBLIC_CACHE]);
            }

            $urls = app(Urls::class);
            $origin = rtrim(is_string($hub['canonicalOrigin'] ?? null) && $hub['canonicalOrigin'] !== '' ? $hub['canonicalOrigin'] : url('/'), '/');
            $entries = [];

            foreach (self::ownPaths() as $path) {
                $entries[$origin . $urls->path($path)] = null;
            }

            foreach ($hub['entries'] ?? [] as $entry) {
                $path = $entry['path'] ?? null;

                if (!is_string($path) || $path === '') {
                    continue;
                }

                $locale = is_string($entry['locale'] ?? null) && $entry['locale'] !== '' ? $entry['locale'] : null;
                $url = self::hubUrl($urls, $origin, $path, $locale);
                $lastmod = isset($entry['lastmod']) && $entry['lastmod'] !== '' ? (string) $entry['lastmod'] : null;
                // The first entry of a URL stays; a later one only brings a missing lastmod.
                $entries[$url] ??= $lastmod;
            }

            $list = [];

            foreach ($entries as $url => $lastmod) {
                $list[] = ['url' => (string) $url, 'lastmod' => $lastmod];
            }
        } catch (Throwable $error) {
            return self::unavailable($error, $headers);
        }

        return new Response(SitemapBuilder::xml($list), 200, [...$headers, 'Cache-Control' => self::PUBLIC_CACHE]);
    }

    /**
     * Paths (`/over-ons`) of the site's own static pages, sorted (rule D11): every parameterless GET route in the `web` group, minus the
     * routes of this package (`_fsc/*`, `content/*`, names `content.*`), redirects, routes behind `auth`/`can`/`verified`, routes that are
     * not pages (`api/*`, a file extension such as `.xml`, Laravel's `up`), `content.auto.sitemap.exclude` patterns and `->noSitemap()`.
     * Reads the router as it is, so it gives the same list with cached routes. No Hub call.
     *
     * @return list<string>
     */
    public static function ownPaths(): array
    {
        $patterns = array_map(static fn ($pattern): string => trim((string) $pattern, '/'), (array) Auto::option('sitemap', 'exclude', []));
        $paths = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = trim($route->uri(), '/');

            if (!self::isOwnPage($route, $uri) || ($patterns !== [] && Str::is($patterns, $uri))) {
                continue;
            }

            $paths['/' . $uri] = true;
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    private static function isOwnPage(Route $route, string $uri): bool
    {
        if (!in_array('GET', $route->methods(), true) || $route->isFallback || str_contains($uri, '{') || ($route->defaults[self::NO_SITEMAP] ?? false)) {
            return false;
        }

        if ($uri === 'up' || $uri === '_fsc' || str_starts_with($uri, '_fsc/') || str_starts_with($uri, 'content/') || str_starts_with($uri, 'api/')
            || preg_match('/\.[A-Za-z0-9]{2,5}$/', $uri) === 1) {
            return false;
        }

        $name = $route->getName();

        if (is_string($name) && str_starts_with($name, 'content.')) {
            return false;
        }

        $action = ltrim($route->getActionName(), '\\');

        if (str_starts_with($action, RedirectController::class) || str_starts_with($action, 'FullStackStudio\\Content\\Laravel\\')) {
            return false;
        }

        $middleware = array_map(static fn ($item): string => is_string($item) ? $item : '', $route->gatherMiddleware());

        if (!in_array('web', $middleware, true)) {
            return false;
        }

        foreach ($middleware as $item) {
            if ($item === 'verified' || $item === 'auth' || str_starts_with($item, 'auth:') || str_starts_with($item, 'can:')) {
                return false;
            }
        }

        return true;
    }

    /** A Hub path (without locale prefix, may carry a query) as an absolute URL; a path that already starts with its locale is not prefixed twice. */
    private static function hubUrl(Urls $urls, string $origin, string $path, ?string $locale): string
    {
        $suffix = '';

        if (preg_match('/^([^?#]*)(.*)$/s', $path, $parts) === 1) {
            [, $path, $suffix] = $parts;
        }

        if ($locale !== null && ($segment = explode('/', trim($path, '/'))[0]) === $locale && $locale !== $urls->defaultLocale()) {
            $locale = null;
        }

        return $origin . $urls->path($path, $locale) . $suffix;
    }

    /** @param array<string, string> $headers */
    private static function unavailable(Throwable $error, array $headers): Response
    {
        try {
            Log::warning('[content] sitemap.xml unavailable: Content could not be reached.', [
                'status' => $error instanceof ContentException ? $error->status() : null,
                'code' => $error instanceof ContentException ? $error->errorCode() : $error::class,
            ]);
        } catch (Throwable) {
        }

        return new Response('', 503, [...$headers, 'Retry-After' => '300', 'Cache-Control' => 'no-store']);
    }
}
