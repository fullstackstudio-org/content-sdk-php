<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use FullStackStudio\Content\Laravel\Site\HealthController;
use FullStackStudio\Content\Laravel\Site\NotFoundController;
use FullStackStudio\Content\Laravel\Site\RobotsController;
use FullStackStudio\Content\Laravel\Site\SiteController;
use FullStackStudio\Content\Laravel\Site\SitemapController;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Throwable;

/**
 * The out of the box routes: `sitemap.xml`, `robots.txt`, `api/health` and the fallback route that serves Hub content.
 *
 * The site's own code always wins (plan decision D2), by construction:
 * - Registration runs in `$app->booted()`, after the site's route files are loaded.
 * - A route whose method and URI the site already registered is skipped (adding it would replace the site's).
 * - The content route is a fallback route, matched after every normal route, also after routes added later; a site's own
 *   `Route::fallback()` was registered first and wins among the fallbacks.
 * - A second fallback for POST, PUT, PATCH and DELETE keeps Laravel's answers for those methods (404 for an unknown path, 405 for a known
 *   one) instead of the 405 the GET content route would cause.
 * - With cached routes nothing is registered at runtime: `route:cache` booted the app, so the cache holds these routes in the same order.
 *
 * Every action is a controller class (no closures), so `route:cache` works. No Hub call here.
 */
final class AutoRoutes
{
    public const PAGE = 'content.auto.page';

    public const SITEMAP = 'content.auto.sitemap';

    public const ROBOTS = 'content.auto.robots';

    public const HEALTH = 'content.auto.health';

    public const NOT_FOUND = 'content.auto.not-found';

    /** Methods the second fallback answers with Laravel's own 404/405: the content route is GET only. */
    private const OTHER_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Route parameter of the content route: the whole path. */
    public const PATH_PARAMETER = 'fscPath';

    /** Called once from the service provider's `boot()`. */
    public static function register(Application $app): void
    {
        $app->booted(static function (Application $app): void {
            self::registerRoutes($app);
        });
    }

    /** Adds the routes of every feature that is on and that the site did not register itself. */
    public static function registerRoutes(Application $app): void
    {
        if ((method_exists($app, 'routesAreCached') && $app->routesAreCached()) || ! Auto::on('enabled')) {
            return;
        }

        /** @var Router $router */
        $router = $app->make('router');
        $added = false;

        // Utility routes: no middleware group, so no session, cookies or CSRF, and cacheable answers.
        foreach ([
            'sitemap' => ['sitemap.xml', SitemapController::class, self::SITEMAP],
            'robots' => ['robots.txt', RobotsController::class, self::ROBOTS],
            'health' => ['api/health', HealthController::class, self::HEALTH],
        ] as $feature => [$uri, $action, $name]) {
            if (Auto::on($feature) && ! self::taken($router, 'GET', $uri)) {
                $router->get($uri, $action)->name($name);
                $added = true;
            }
        }

        // The content route stays registered when `pages` is off: it must still run the `web` group (Hub redirects for unknown paths) and
        // then answer 404. The controller decides per type which features are on.
        $uri = '{' . self::PATH_PARAMETER . '}';

        if (! self::taken($router, 'GET', $uri)) {
            $router->middleware('web')
                ->get($uri, SiteController::class)
                ->where(self::PATH_PARAMETER, '.*')
                ->name(self::PAGE)
                ->fallback();
            $added = true;
        }

        // Without this, the GET fallback makes Laravel answer every other method on an unknown path with a 405. Registered without a
        // middleware group (no CSRF 419, no session), only for the methods the site did not catch itself with the same URI.
        $methods = array_values(array_filter(self::OTHER_METHODS, static fn (string $method): bool => ! self::taken($router, $method, $uri)));

        if ($methods !== []) {
            $router->match($methods, $uri, NotFoundController::class)
                ->where(self::PATH_PARAMETER, '.*')
                ->name(self::NOT_FOUND)
                ->fallback();
            $added = true;
        }

        if ($added) {
            // Names are set after `add()`, so the lookups must be rebuilt for `route()` and `Route::has()`.
            $routes = $router->getRoutes();
            $routes->refreshNameLookups();
            $routes->refreshActionLookups();
        }
    }

    /**
     * Who answers a request, for `fsc:doctor`: `sdk` (a route of this package), `site` (the site's own route) or `none` (no route matches
     * the method and path). Matches against the router as it is, so it is also right with cached routes.
     *
     * @return array{by: 'sdk'|'site'|'none', uri: ?string, action: ?string}
     */
    public static function owner(string $method, string $path): array
    {
        $none = ['by' => 'none', 'uri' => null, 'action' => null];

        try {
            /** @var Router $router */
            $router = app('router');
            $route = $router->getRoutes()->match(Request::create('/' . ltrim($path, '/'), strtoupper($method)));
        } catch (Throwable) {
            // No route for this method and path (404 or 405), or a router that cannot match outside a request.
            return $none;
        }

        // The 404 fallback for other methods answers nothing.
        if ($route->getName() === self::NOT_FOUND) {
            return $none;
        }

        return [
            'by' => self::isPackageRoute($route) ? 'sdk' : 'site',
            'uri' => $route->uri(),
            'action' => $route->getActionName(),
        ];
    }

    /** Whether the router already holds a route with this method and URI (any middleware group, any prefix that led to it). */
    private static function taken(Router $router, string $method, string $uri): bool
    {
        return isset($router->getRoutes()->getRoutesByMethod()[$method][$uri]);
    }

    private static function isPackageRoute(Route $route): bool
    {
        $name = $route->getName();

        if (is_string($name) && str_starts_with($name, 'content.')) {
            return true;
        }

        return str_starts_with(ltrim($route->getActionName(), '\\'), 'FullStackStudio\\Content\\Laravel\\');
    }
}
