<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use FullStackStudio\Content\Laravel\Auto\Auto;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Throwable;

/**
 * The Hub's visitor pages on the site's own domain (plan sdk-out-of-the-box, section 8, V1 to V3): a closed table of paths and methods that
 * {@see VisitorProxyController} forwards to the Hub with the server token. Nothing outside this table can be reached through the proxy.
 *
 * - Same paths as on the Hub, at the site root (V1). Every route parameter is matched against a strict character class (no `/`, no `..`).
 * - Registered in the provider's `boot()`, before the site's route files, so a site catch-all does not shadow them (V2). A site route with
 *   the identical method and URI is added later and replaces ours, so it still wins.
 * - No middleware group: no session, cookie or CSRF token of the site (V8). Controller class only, so `route:cache` works.
 * - `VERSION` is the proxy version the site reports to the Hub at verification (V3). A new Hub page type means a new version.
 */
final class VisitorRoutes
{
    /** Proxy version of this table, reported to the Hub (`proxy: 1`). */
    public const VERSION = 1;

    /** Route name prefix: `content.visitor.<group>.<n>`. */
    public const NAME_PREFIX = 'content.visitor.';

    /** A capability token or signed value: URL-safe characters, dots only between other characters (so never `.` or `..`). */
    private const TOKEN = '[A-Za-z0-9_~-]+(?:\.[A-Za-z0-9_~-]+)*';

    /** A slug or an id (project and event slug, service id, attachment id). */
    private const SLUG = '[A-Za-z0-9_-]+';

    /** The Hub's own `downloads/by-order` endpoint is an API call, not a visitor page: never forwarded with the server token. */
    private const DOWNLOAD_TOKEN = '(?!by-order(?![A-Za-z0-9_~.-]))' . self::TOKEN;

    /** @var array<string, string> parameter name => character class */
    private const PARAMETERS = [
        'token' => self::TOKEN,
        'signed' => self::TOKEN,
        'download' => self::DOWNLOAD_TOKEN,
        'slug' => self::SLUG,
        'serviceId' => self::SLUG,
        'projectSlug' => self::SLUG,
        'eventSlug' => self::SLUG,
        'attachmentId' => self::SLUG,
    ];

    /**
     * The table of section 8, in the Hub's own route shapes (`server/routes/native/*-public.php`).
     *
     * @var list<array{0: string, 1: list<string>, 2: string}> URI, methods, group
     */
    public const ROUTES = [
        ['a/b/{slug}', ['GET'], 'appointments'],
        ['a/b/{slug}/{serviceId}', ['GET', 'POST'], 'appointments'],
        ['a/l/{token}', ['GET', 'POST'], 'appointments'],
        ['a/c/{token}', ['GET', 'POST'], 'appointments'],
        ['a/c/{token}/afspraak', ['GET'], 'appointments'],
        ['a/c/{token}/afspraak.ics', ['GET'], 'appointments'],
        ['a/c/{token}/verzetten', ['GET', 'POST'], 'appointments'],
        ['a/f/{token}', ['GET'], 'appointments'],
        ['a/ontvangen', ['GET'], 'appointments'],
        ['e/i/{projectSlug}', ['GET'], 'events'],
        ['e/i/{projectSlug}/{eventSlug}', ['GET', 'POST'], 'events'],
        ['e/t/{token?}', ['GET'], 'events'],
        ['n/c/{token}', ['GET', 'POST'], 'newsletter'],
        ['n/u/{token}', ['GET', 'POST'], 'newsletter'],
        ['n/l/{token}', ['GET'], 'newsletter'],
        ['n/m/{token}', ['GET', 'POST'], 'newsletter'],
        ['t/{token?}', ['GET'], 'tickets'],
        ['t/{token}/reply', ['POST'], 'tickets'],
        ['t/{token}/attachments/{attachmentId}', ['GET'], 'tickets'],
        ['r/{token?}', ['GET'], 'tickets'],
        ['api/invoices/download/{signed}', ['GET'], 'files'],
        ['api/privacy/exports/{token}', ['GET', 'POST'], 'files'],
        ['api/v1/downloads/{download}', ['GET'], 'files'],
    ];

    /** @var array<string, string>|null URI => anchored regex (built once per process) */
    private static ?array $patterns = null;

    /**
     * Called from the service provider's `boot()`. Nothing with cached routes (the cache holds them). With the feature off only the
     * verification route, which answers 404.
     */
    public static function register(Application $app): void
    {
        if (method_exists($app, 'routesAreCached') && $app->routesAreCached()) {
            return;
        }

        /** @var Router $router */
        $router = $app->make('router');

        // The Hub's verification probe: registered with the feature off too, where it answers a clean 404 itself (the Hub unverifies the site
        // on that answer), instead of leaving the path to the site's catch-all.
        $router->get(VerifyController::URI, VerifyController::class)->name(VerifyController::NAME);
        $numbers = [];

        foreach (Auto::on('visitor_pages') ? self::ROUTES : [] as [$uri, $methods, $group]) {
            $numbers[$group] = ($numbers[$group] ?? 0) + 1;

            $router->match($methods, $uri, VisitorProxyController::class)
                ->where(self::wheres($uri))
                ->name(self::NAME_PREFIX . $group . '.' . $numbers[$group]);
        }

        // Names are set after `add()`, so the lookups must be rebuilt for `route()` and `Route::has()`.
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    /**
     * Whether a path falls under the table (any method). Takes the raw or the decoded path, with or without leading or trailing slash,
     * exactly as the router matches it. For other code: the trailing-slash middleware, `fsc:doctor`.
     */
    public static function matches(string $path): bool
    {
        return self::find($path) !== null;
    }

    /**
     * Whether the table has a route for this method and path: the case in which a route of the site on that path is never reached (ours is
     * registered first), as `fsc:doctor` checks. `HEAD` counts as `GET`.
     */
    public static function allows(string $method, string $path): bool
    {
        if (strlen($path) > 4096) {
            return false;
        }

        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = trim(rawurldecode($path), '/');

        foreach (self::ROUTES as [$uri, $methods]) {
            if (in_array($method, $methods, true) && preg_match(self::pattern($uri), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The group of a path (`appointments`, `events`, `newsletter`, `tickets`, `files`), or null outside the table. */
    public static function group(string $path): ?string
    {
        $found = self::find($path);

        return $found === null ? null : $found[0][2];
    }

    /**
     * The path with every parameter replaced by `…` (`/a/l/…`, `/t/…/attachments/…`), for logs and error reports: the parameters are
     * capability tokens. A path outside the table comes back unchanged.
     */
    public static function scrub(string $path): string
    {
        $found = self::find($path);

        if ($found === null) {
            return $path;
        }

        [$route, $values] = $found;
        $segments = [];

        foreach (explode('/', $route[0]) as $segment) {
            if (preg_match('/^\{(\w+)(\?)?\}$/', $segment, $parameter) !== 1) {
                $segments[] = $segment;
            } elseif (($values[$parameter[1]] ?? '') !== '') {
                $segments[] = '…';
            }
        }

        return '/' . implode('/', $segments);
    }

    /** @return list<string> the parameter values of a path in the table (the tokens), empty outside it */
    public static function parameters(string $path): array
    {
        $found = self::find($path);

        return $found === null ? [] : array_values(array_filter($found[1], static fn (string $value): bool => $value !== ''));
    }

    /**
     * Whether this request is (or will be) answered by a visitor route. After routing: by the route name, so a site route with the identical
     * URI counts as the site's. Before routing (global middleware): by the table, while the feature is on.
     */
    public static function handles(Request $request): bool
    {
        try {
            $route = $request->route();
        } catch (Throwable) {
            $route = null;
        }

        if ($route instanceof Route) {
            return str_starts_with((string) $route->getName(), self::NAME_PREFIX);
        }

        return Auto::on('visitor_pages') && self::matches($request->getPathInfo());
    }

    /** @return array<string, string> parameter => character class of one URI */
    private static function wheres(string $uri): array
    {
        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);
        $wheres = [];

        foreach ($matches[1] as $name) {
            $wheres[$name] = self::PARAMETERS[$name];
        }

        return $wheres;
    }

    /** @return array{0: array{0: string, 1: list<string>, 2: string}, 1: array<string, string>}|null the route and its parameter values */
    private static function find(string $path): ?array
    {
        if (strlen($path) > 4096) {
            return null;
        }

        $path = trim(rawurldecode($path), '/');

        foreach (self::ROUTES as $route) {
            if (preg_match(self::pattern($route[0]), $path, $match) === 1) {
                $values = [];

                foreach ($match as $key => $value) {
                    if (is_string($key)) {
                        $values[$key] = $value;
                    }
                }

                return [$route, $values];
            }
        }

        return null;
    }

    /** The anchored regex of a URI, with the same character classes the router uses. */
    private static function pattern(string $uri): string
    {
        if (isset(self::$patterns[$uri])) {
            return self::$patterns[$uri];
        }

        $regex = '';

        foreach (explode('/', $uri) as $index => $segment) {
            $separator = $index === 0 ? '' : '/';

            if (preg_match('/^\{(\w+)(\?)?\}$/', $segment, $parameter) === 1) {
                $group = '(?P<' . $parameter[1] . '>' . self::PARAMETERS[$parameter[1]] . ')';
                $regex .= isset($parameter[2]) ? '(?:' . $separator . $group . ')?' : $separator . $group;
            } else {
                $regex .= $separator . preg_quote($segment, '#');
            }
        }

        return self::$patterns[$uri] = '#^' . $regex . '$#D';
    }
}
