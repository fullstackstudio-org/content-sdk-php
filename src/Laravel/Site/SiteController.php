<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Laravel\Auto\AutoRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route;

/**
 * Action of the fallback route `content.auto.page` (`GET {fscPath}`): asks the {@see PathResolver} what the path is, remembers it in the
 * request scoped {@see PageContext} (the head component reads it) and renders it. A path that is nothing is a 404 and a Hub failure a 503
 * ({@see Hub::orAbort()}). A feature that is off is a 404 here too, whatever the resolver found.
 *
 * Per type, a handler of the site (`content.auto.handlers.<type>`, an invokable class that receives the `Request` and the `Resolved`) takes
 * over; else the view `Auto::view(<type>)` is rendered with the variable names sites use in their own views: `$page`, `$article`,
 * `$articles` (a list: the payload of the Hub list), `$category`, `$event`, `$document`, plus `$path` and `$resolved`.
 */
final class SiteController
{
    public function __invoke(Request $request, PageContext $context): mixed
    {
        // Nothing here is served when pages, articles and legal are all off: answer 404 without building the resolver, which needs a
        // Hub client (and so a token that a site without Hub content does not have).
        if (! Auto::on('pages') && ! Auto::on('articles') && ! Auto::on('legal')) {
            abort(404);
        }

        // `//over-ons`, `/over-ons//`, `//evil.example//`: one 308 to the same path with single slashes, always relative to this site.
        if (($normalised = self::doubleSlashTarget($request)) !== null) {
            return new RedirectResponse($normalised, 308);
        }

        // A legal path is registered early (before the site's routes) for the Hub's legal document. Without a published document of that
        // kind the request behaves as it did without that route: the site's own route for the path answers (a catch-all of the site, its
        // own fallback), else the Hub page at that path through the normal resolver below, else a 404.
        if (self::isLegalRoute($request)) {
            $resolved = Hub::orAbort(fn (): ?Resolved => self::resolve($request) ?? self::none());

            if ($resolved->type !== 'legal' && ($response = self::siteRoute($request)) !== null) {
                return $response;
            }

            if ($resolved->type === 'none') {
                abort(404);
            }
        } else {
            $resolved = Hub::orAbort(fn (): ?Resolved => self::resolve($request));
        }

        if (! self::allowed($resolved)) {
            abort(404);
        }

        // The Hub matches page paths case-insensitively: `/Diensten` finds the page `/diensten` and would answer 200 with its own canonical,
        // a duplicate of the real page. Send it to the stored path instead (query kept).
        if (($stored = self::storedPath($resolved)) !== null) {
            $query = (string) $request->getQueryString();
            $target = app(Urls::class)->path($stored, $resolved->locale) . ($query === '' ? '' : '?' . $query);

            return redirect()->to($target, 301);
        }

        $context->resolve($resolved);
        $context->setStatus(200);

        $handler = Auto::handler($resolved->type);

        if ($handler !== null) {
            return app($handler)($request, $resolved);
        }

        return view(Auto::view($resolved->type), self::data($resolved));
    }

    /** Placeholder for "nothing at this path", so a legal route can still offer the path to the site's own route before the 404. */
    private static function none(): Resolved
    {
        return new Resolved('none', [], '', (string) app()->getLocale());
    }

    private static function isLegalRoute(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && str_starts_with((string) $route->getName(), AutoRoutes::LEGAL);
    }

    /**
     * The answer of the site's own route for this request, when it has one besides the SDK's legal route (non-fallback routes first, then
     * the site's own fallback, in the router's order): the route is bound and run through its own middleware, minus the middleware the legal
     * route already ran (the `web` group never runs twice). Null when only the SDK's routes match.
     */
    private static function siteRoute(Request $request): ?\Symfony\Component\HttpFoundation\Response
    {
        $router = app('router');
        $current = $request->route();
        $candidates = [];

        foreach ($router->getRoutes()->get($request->getMethod()) as $route) {
            if ($route === $current || self::isPackageRoute($route)) {
                continue;
            }

            $candidates[$route->isFallback ? 1 : 0][] = $route;
        }

        foreach ([...($candidates[0] ?? []), ...($candidates[1] ?? [])] as $route) {
            if (! $route->matches($request)) {
                continue;
            }

            $route->bind($request);
            $request->setRouteResolver(static fn (): Route => $route);
            $done = $current instanceof Route ? array_filter($router->gatherRouteMiddleware($current), 'is_string') : [];
            $middleware = array_values(array_filter($router->gatherRouteMiddleware($route), static fn ($item): bool => ! is_string($item) || ! in_array($item, $done, true)));

            return (new Pipeline(app()))
                ->send($request)
                ->through($middleware)
                ->then(static fn (Request $request) => $router->prepareResponse($request, $route->run()));
        }

        return null;
    }

    private static function isPackageRoute(Route $route): bool
    {
        $name = $route->getName();

        return (is_string($name) && str_starts_with($name, 'content.'))
            || str_starts_with(ltrim($route->getActionName(), '\\'), 'FullStackStudio\\Content\\Laravel\\');
    }

    /**
     * For a GET or HEAD path with an empty segment (`//x`, `/x//y`, `/x//`): the same path with single slashes (and the trailing slash as
     * `content.auto.trailing_slash` wants it), query kept. Always one leading slash and only URL-safe segment characters, so the target can
     * never be read as another host (`//evil.example`, `/\evil.example`). Null for any other path, or one with unsafe characters (404).
     */
    private static function doubleSlashTarget(Request $request): ?string
    {
        $path = $request->getPathInfo();

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || ! str_contains($path, '//') || strlen($path) > 2048) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9._~%-]+$/D', $segment) !== 1) {
                return null;
            }
        }

        $target = '/' . implode('/', $segments);

        if ($target !== '/' && (bool) config('content.auto.trailing_slash') && ! str_contains((string) end($segments), '.')) {
            $target .= '/';
        }

        $query = (string) $request->getQueryString();

        return $target . ($query === '' ? '' : '?' . $query);
    }

    /**
     * The resolver's answer, with a short negative cache (`content.hub.not_found_ttl`): a path that is nothing in the Hub is remembered in the
     * content cache under the tags of everything a path can be, so a webhook for a page, article, event or legal document clears it at once.
     * Not in a portal preview. A Hub failure is never remembered (it throws).
     */
    private static function resolve(Request $request): ?Resolved
    {
        $resolver = app(PathResolver::class);
        $ttl = (int) config('content.hub.not_found_ttl', 30);
        $page = $request->query('page');
        $path = $request->getPathInfo();

        if ($ttl <= 0 || Preview::isActive() || ! app()->bound(ContentCache::class)) {
            return $resolver->resolve($path);
        }

        $key = 'path404:' . sha1($path . '|' . (is_string($page) ? $page : ''));
        $tags = [Tags::PAGES, Tags::ARTICLES, Tags::CATEGORIES, Tags::EVENTS, Tags::LEGAL, Tags::SEO, Tags::PROJECT];

        try {
            $locale = app(ContentCache::class)->remember($key, $tags, $ttl, static function () use ($resolver, $path): string {
                $resolved = $resolver->resolve($path);

                // Only a miss is stored: a hit leaves through the exception (the client cache already holds the resource itself).
                if ($resolved !== null) {
                    throw new FoundPath($resolved);
                }

                // The language the resolver picked from the path, so the 404 of a remembered miss renders in it too.
                return (string) app()->getLocale();
            });
        } catch (FoundPath $found) {
            return $found->resolved;
        }

        if (is_string($locale) && $locale !== '' && $locale !== app()->getLocale()) {
            app()->setLocale($locale);
        }

        return null;
    }

    /** The path the Hub stores for a page when the request asked for it in other letters (`/Diensten` for `/diensten`), else null. */
    private static function storedPath(Resolved $resolved): ?string
    {
        if ($resolved->type !== 'page' || Preview::isActive()) {
            return null;
        }

        $stored = $resolved->resource['path'] ?? null;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $requested = trim($resolved->path, '/');
        $stored = trim($stored, '/');

        return $stored !== $requested && strcasecmp($stored, $requested) === 0 ? '/' . $stored : null;
    }

    /** The feature that serves a type must be on (the resolver already skips it; this keeps a changed config from leaking a cached type). */
    private static function allowed(Resolved $resolved): bool
    {
        return match ($resolved->type) {
            'page' => Auto::on('pages'),
            'article', 'articles', 'category' => Auto::on('articles'),
            'legal' => Auto::on('legal'),
            default => true,
        };
    }

    /** @return array<string, mixed> */
    private static function data(Resolved $resolved): array
    {
        $data = ['path' => $resolved->path, 'resolved' => $resolved];

        return $data + match ($resolved->type) {
            'page' => ['page' => $resolved->resource],
            'article' => ['article' => $resolved->resource],
            'articles' => ['articles' => $resolved->resource['articles'] ?? [], 'category' => null],
            'category' => ['articles' => $resolved->resource['articles'] ?? [], 'category' => $resolved->extra['category'] ?? null],
            'event' => ['event' => $resolved->resource],
            'legal' => ['document' => $resolved->resource],
            default => [],
        };
    }
}
