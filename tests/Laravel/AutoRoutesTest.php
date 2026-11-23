<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request as HubRequest;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Http\Transport;
use FullStackStudio\Content\Laravel\Auto\AutoRoutes;
use FullStackStudio\Content\Laravel\Site\HealthController;
use FullStackStudio\Content\Laravel\Site\SiteController;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The automatic routes (plan sdk-out-of-the-box, task 2): registered after the site's routes, never replacing or shadowing a site route, the
 * same with `route:cache`. The content route's action is replaced by a probe that echoes the path, so a test sees which route answered.
 */
final class AutoRoutesTest extends LaravelTestCase
{
    /** @var (Closure(Router): void)|null the site's routes of the next application */
    private ?Closure $siteRoutes = null;

    /** @var array<string, mixed> `content.auto` values of the next application */
    private array $auto = [];

    private FakeTransport $hub;

    private ?string $routeCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakes();

        // CI runs this class twice per Laravel major: as is, and with FSC_TEST_ROUTE_CACHE=1, where every application of a test answers
        // from a compiled route cache (what `route:cache` at container start gives), so each precedence rule is proven both ways.
        if (self::cachedMode() && ! in_array($this->name(), self::OWN_CACHE, true)) {
            $this->site();
        }
    }

    /** Tests that build or fake the route cache themselves. */
    private const OWN_CACHE = ['testCachedRoutesGiveTheSameRoutesAndAnswers', 'testCachedRoutesAreNotRegisteredTwice'];

    private static function cachedMode(): bool
    {
        return getenv('FSC_TEST_ROUTE_CACHE') === '1';
    }

    protected function tearDown(): void
    {
        if ($this->routeCache !== null) {
            unset($_SERVER['APP_ROUTES_CACHE'], $_ENV['APP_ROUTES_CACHE']);
            putenv('APP_ROUTES_CACHE');
            @unlink($this->routeCache);
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->auto as $key => $value) {
            $app['config']->set('content.auto.' . $key, $value);
        }
    }

    protected function defineRoutes($router): void
    {
        if ($this->siteRoutes !== null) {
            ($this->siteRoutes)($router);
        }
    }

    /**
     * A fresh application with these site routes and `content.auto` values. `$later` adds routes after the automatic ones (a package or
     * a booted callback that runs later); in the cached mode `route:cache` saw them too, as it boots the whole application.
     *
     * @param (Closure(Router): void)|null $later
     */
    private function site(?Closure $routes = null, array $auto = [], ?Closure $later = null): void
    {
        $this->siteRoutes = $routes;
        $this->auto = $auto;
        $cached = self::cachedMode() && ! in_array($this->name(), self::OWN_CACHE, true);

        if ($cached) {
            // Boot without the previous cache first, so the cache holds exactly this site's routes.
            unset($_SERVER['APP_ROUTES_CACHE'], $_ENV['APP_ROUTES_CACHE']);
            putenv('APP_ROUTES_CACHE');
        }

        $this->refreshApplication();
        $this->fakes();

        if ($later !== null) {
            $later($this->app['router']);
        }

        if ($cached) {
            $this->writeRouteCache();
            $this->refreshApplication();
            $this->loadRouteCache();
            $this->fakes();
        }
    }

    /** What `php artisan route:cache` writes: the compiled collection of the freshly booted application. */
    private function writeRouteCache(): void
    {
        $collection = $this->app['router']->getRoutes();

        foreach ($collection as $route) {
            $route->prepareForSerialization();
        }

        $this->routeCache ??= sys_get_temp_dir() . '/fsc-auto-routes-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->routeCache, "<?php\n\napp('router')->setCompiledRoutes(\n    " . var_export($collection->compile(), true) . "\n);\n");
        $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = $this->routeCache;
        putenv('APP_ROUTES_CACHE=' . $this->routeCache);
    }

    /** A container start with cached routes: nothing is registered at runtime, the cache is loaded as RouteServiceProvider does. */
    private function loadRouteCache(): void
    {
        self::assertTrue($this->app->routesAreCached());
        require $this->routeCache;
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    private function fakes(): void
    {
        $this->hub = new FakeTransport();
        $hub = $this->hub;
        $this->app->singleton(ContentClient::class, static fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $hub]));
        $this->app->instance(SiteController::class, new AutoRoutesTestProbe());
    }

    /** @return list<string> `METHOD uri name` of every route, in match order */
    private function routeList(): array
    {
        $list = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            // route:cache names unnamed routes `generated::…`.
            $name = $route->getName();
            $name = $name === null || str_starts_with($name, 'generated::') ? '-' : $name;
            $list[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' ' . $name . ($route->isFallback ? ' fallback' : '');
        }

        sort($list);

        return $list;
    }

    private function named(string $name): ?Route
    {
        return $this->app['router']->getRoutes()->getByName($name);
    }

    public function testTheRoutesExistWithTheirNamesGroupsAndControllerActions(): void
    {
        foreach ([AutoRoutes::SITEMAP => 'sitemap.xml', AutoRoutes::ROBOTS => 'robots.txt', AutoRoutes::HEALTH => 'api/health'] as $name => $uri) {
            $route = $this->named($name);
            self::assertNotNull($route, $name);
            self::assertSame($uri, $route->uri());
            self::assertSame([], $route->gatherMiddleware(), $name . ' runs without a middleware group');
            self::assertFalse($route->isFallback);
        }

        $page = $this->named(AutoRoutes::PAGE);
        self::assertNotNull($page);
        self::assertSame('{fscPath}', $page->uri());
        self::assertSame(['GET', 'HEAD'], $page->methods());
        self::assertSame(['web'], $page->gatherMiddleware());
        self::assertSame(['fscPath' => '.*'], $page->wheres);
        self::assertTrue($page->isFallback);
        self::assertSame(SiteController::class, $page->getActionName());
        self::assertSame(HealthController::class, $this->named(AutoRoutes::HEALTH)->getActionName());

        // Names resolve to URLs (the lookups were refreshed after the names were set).
        self::assertSame('http://localhost/sitemap.xml', route(AutoRoutes::SITEMAP));
    }

    public function testHealthAnswersOkWithoutSessionCacheOrHubCall(): void
    {
        $response = $this->get('/api/health');

        $response->assertOk();
        self::assertSame('{"ok":true}', $response->getContent());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame([], $this->hub->requests);
    }

    public function testTheContentRouteServesEveryPathThroughTheWebGroup(): void
    {
        $this->get('/')->assertOk()->assertSeeText('auto:');
        self::assertSame('auto:', $this->get('/')->getContent());
        self::assertSame('auto:over-ons', $this->get('/over-ons')->getContent());
        self::assertSame('auto:blog/2026/een-artikel', $this->get('/blog/2026/een-artikel')->getContent());
        $this->get('/over-ons')->assertCookie((string) config('session.cookie'));
    }

    public function testASiteRouteWithTheSameUriWins(): void
    {
        $this->site(static function (Router $router): void {
            $router->get('sitemap.xml', AutoRoutesTestSite::class);
            $router->get('/robots.txt', AutoRoutesTestSite::class);
            // Registered through the `api` group prefix, as `routes/api.php` does.
            $router->prefix('api')->middleware('api')->get('health', AutoRoutesTestSite::class);
        });

        self::assertSame('site:sitemap.xml', $this->get('/sitemap.xml')->getContent());
        self::assertSame('site:robots.txt', $this->get('/robots.txt')->getContent());
        self::assertSame('site:api/health', $this->get('/api/health')->getContent());
        self::assertNull($this->named(AutoRoutes::SITEMAP));
        self::assertNull($this->named(AutoRoutes::ROBOTS));
        self::assertNull($this->named(AutoRoutes::HEALTH));
        self::assertNotNull($this->named(AutoRoutes::PAGE));

        $routes = $this->app['router']->getRoutes()->getRoutesByMethod()['GET'];
        self::assertSame(AutoRoutesTestSite::class, $routes['sitemap.xml']->getActionName());
        self::assertSame(AutoRoutesTestSite::class, $routes['api/health']->getActionName());
    }

    public function testASiteCatchAllWinsOverTheContentRoute(): void
    {
        $this->site(static function (Router $router): void {
            $router->middleware('web')->get('/{path}', AutoRoutesTestSite::class)->where('path', '.*');
        });

        self::assertSame('site:', $this->get('/')->getContent());
        self::assertSame('site:over-ons', $this->get('/over-ons')->getContent());
        self::assertSame('site:a/b', $this->get('/a/b')->getContent());
        // The utility routes were registered (no identical URI), but the catch-all came first, so it answers them too: the site's order wins.
        self::assertNotNull($this->named(AutoRoutes::SITEMAP));
        self::assertSame('site:sitemap.xml', $this->get('/sitemap.xml')->getContent());
        self::assertSame(['by' => 'site', 'uri' => '{path}', 'action' => AutoRoutesTestSite::class], AutoRoutes::owner('GET', '/over-ons'));
    }

    public function testASiteFallbackWinsOverTheContentRoute(): void
    {
        $this->site(static function (Router $router): void {
            $router->middleware('web')->get('/contact', AutoRoutesTestSite::class);
            $router->fallback(AutoRoutesTestSite::class);
        });

        self::assertSame('site:contact', $this->get('/contact')->getContent());
        self::assertSame('site:over-ons', $this->get('/over-ons')->getContent());
        self::assertSame('site:', $this->get('/')->getContent());
        self::assertSame('site', AutoRoutes::owner('GET', '/iets')['by']);
    }

    public function testASiteStaticRouteWinsWhetherDefinedBeforeOrAfter(): void
    {
        $this->site(static function (Router $router): void {
            $router->middleware('web')->get('/contact', AutoRoutesTestSite::class);
            $router->middleware('web')->get('/', AutoRoutesTestSite::class);
        }, [], static function (Router $router): void {
            // Added after the automatic routes (a package or a booted callback that runs later).
            $router->middleware('web')->get('/later', AutoRoutesTestSite::class);
            $router->get('/robots.txt', AutoRoutesTestSite::class);
        });

        self::assertSame('site:contact', $this->get('/contact')->getContent());
        self::assertSame('site:', $this->get('/')->getContent());
        self::assertSame('site:later', $this->get('/later')->getContent());
        self::assertSame('site:robots.txt', $this->get('/robots.txt')->getContent());
        self::assertSame('auto:over-ons', $this->get('/over-ons')->getContent());
    }

    public function testCachedRoutesGiveTheSameRoutesAndAnswers(): void
    {
        $routes = static function (Router $router): void {
            $router->middleware('web')->get('/contact', AutoRoutesTestSite::class)->name('contact');
            $router->get('/robots.txt', AutoRoutesTestSite::class);
        };
        $this->site($routes);
        $paths = ['/', '/contact', '/over-ons', '/a/b/c', '/robots.txt', '/sitemap.xml', '/api/health', '/_fsc/a/fsc.js'];
        $before = $this->routeList();
        $answers = $this->answers($paths);

        $this->writeRouteCache();
        $this->site($routes);
        $this->loadRouteCache();

        self::assertInstanceOf(\Illuminate\Routing\CompiledRouteCollection::class, $this->app['router']->getRoutes());
        self::assertSame($before, $this->routeList());
        self::assertSame($answers, $this->answers($paths));
        self::assertSame('sdk', AutoRoutes::owner('GET', '/over-ons')['by']);
        self::assertSame('site', AutoRoutes::owner('GET', '/contact')['by']);
    }

    public function testCachedRoutesAreNotRegisteredTwice(): void
    {
        $this->routeCache = sys_get_temp_dir() . '/fsc-auto-routes-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->routeCache, "<?php\n");
        $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = $this->routeCache;
        putenv('APP_ROUTES_CACHE=' . $this->routeCache);

        $this->site();

        self::assertTrue($this->app->routesAreCached());
        self::assertNull($this->named(AutoRoutes::PAGE));
        self::assertNull($this->named(AutoRoutes::HEALTH));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function utilityFeatures(): array
    {
        return [
            'sitemap' => ['sitemap', AutoRoutes::SITEMAP],
            'robots' => ['robots', AutoRoutes::ROBOTS],
            'health' => ['health', AutoRoutes::HEALTH],
        ];
    }

    #[DataProvider('utilityFeatures')]
    public function testDisablingAFeatureRemovesOnlyItsRoute(string $feature, string $name): void
    {
        $all = [AutoRoutes::SITEMAP, AutoRoutes::ROBOTS, AutoRoutes::HEALTH, AutoRoutes::PAGE];
        // `sitemap` has options, so it is switched off through its `enabled` key; the others are plain bools.
        $this->site(null, [$feature => $feature === 'sitemap' ? ['enabled' => false] : false]);

        self::assertNull($this->named($name));

        foreach (array_diff($all, [$name]) as $other) {
            self::assertNotNull($this->named($other), $other);
        }
    }

    public function testWithPagesOffTheContentRouteStillRunsTheWebGroup(): void
    {
        $this->site(null, ['pages' => false]);

        $page = $this->named(AutoRoutes::PAGE);
        self::assertNotNull($page);
        self::assertSame(['web'], $page->gatherMiddleware());

        // The real (placeholder) controller answers 404 after the group ran.
        $this->app->forgetInstance(SiteController::class);
        $this->get('/over-ons')->assertNotFound()->assertCookie((string) config('session.cookie'));
    }

    public function testTheMasterSwitchRemovesEveryAutomaticRoute(): void
    {
        $this->site(null, ['enabled' => false]);

        foreach ([AutoRoutes::SITEMAP, AutoRoutes::ROBOTS, AutoRoutes::HEALTH, AutoRoutes::PAGE] as $name) {
            self::assertNull($this->named($name), $name);
        }

        $this->get('/over-ons')->assertNotFound();
        self::assertSame(['by' => 'none', 'uri' => null, 'action' => null], AutoRoutes::owner('GET', '/over-ons'));
    }

    public function testTheExistingPackageRoutesAreUnaffected(): void
    {
        $this->get('/_fsc/a/fsc.js')->assertOk();
        self::assertSame('_fsc/a/fsc.js', AutoRoutes::owner('GET', '/_fsc/a/fsc.js')['uri']);
        self::assertSame('sdk', AutoRoutes::owner('GET', '/_fsc/a/fsc.js')['by']);
        self::assertSame('content/webhook', AutoRoutes::owner('POST', '/content/webhook')['uri']);
        self::assertNotNull($this->named('content.analytics.helper'));
        self::assertNotNull($this->named('content.webhook'));
    }

    public function testOwnerTellsWhoAnswers(): void
    {
        $this->site(static function (Router $router): void {
            $router->middleware('web')->get('/contact', AutoRoutesTestSite::class);
        });

        self::assertSame(['by' => 'site', 'uri' => 'contact', 'action' => AutoRoutesTestSite::class], AutoRoutes::owner('GET', '/contact'));
        self::assertSame(['by' => 'sdk', 'uri' => 'api/health', 'action' => HealthController::class], AutoRoutes::owner('GET', '/api/health'));
        self::assertSame(['by' => 'sdk', 'uri' => '{fscPath}', 'action' => SiteController::class], AutoRoutes::owner('GET', 'over-ons'));
        self::assertSame(['by' => 'sdk', 'uri' => '{fscPath}', 'action' => SiteController::class], AutoRoutes::owner('GET', '/'));
        self::assertSame('none', AutoRoutes::owner('POST', '/contact')['by']);
        self::assertSame('none', AutoRoutes::owner('DELETE', '/iets')['by']);
    }

    public function testOtherMethodsOnUnknownPathsAre404AsWithoutThePackage(): void
    {
        $this->site(static function (Router $router): void {
            $router->middleware('web')->get('/contact', AutoRoutesTestSite::class);
            $router->post('/aanmelden', AutoRoutesTestSite::class);
        });

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, '/bestaat-niet')->assertNotFound();
            $this->call($method, '/')->assertNotFound();
        }

        // A known path with another method stays 405, a site's own route still answers, and nothing ran the web group (no 419, no cookie).
        $this->call('POST', '/contact')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD');
        self::assertSame('site:aanmelden', $this->call('POST', '/aanmelden')->getContent());
        self::assertSame([], $this->call('POST', '/bestaat-niet')->headers->getCookies());
        self::assertSame('none', AutoRoutes::owner('POST', '/bestaat-niet')['by'], 'the 404 route answers nothing');
    }

    public function testWithASiteFallbackForOtherMethodsTheSiteWins(): void
    {
        $this->site(static function (Router $router): void {
            $router->any('{any}', AutoRoutesTestSite::class)->where('any', '.*')->fallback();
        });

        self::assertSame('site:iets', $this->call('POST', '/iets')->getContent());
    }

    public function testThePortalPreviewReachesTheContentRoute(): void
    {
        $token = 'fscpv_' . str_repeat('A', 43);
        $preview = [
            'version' => 3,
            'path' => '/over-ons',
            'embedOrigin' => 'https://portal.fullstackstudio.nl',
            'page' => ['id' => '11111111-2222-3333-4444-555555555555', 'title' => 'Concept', 'slug' => 'over-ons', 'status' => 'draft', 'preview' => true, 'media' => [], 'blocks' => []],
        ];
        $transport = new class ($preview) implements Transport {
            public function __construct(private readonly array $preview)
            {
            }

            public function send(HubRequest $request): HubResponse
            {
                return parse_url($request->url, PHP_URL_PATH) === '/api/v1/pages/_preview'
                    ? FakeTransport::json(['preview' => $this->preview], 200, ['cache-control' => 'no-store'])
                    : FakeTransport::json([]);
            }
        };
        $this->app->singleton(ContentClient::class, static fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $transport]));
        $this->app->instance(SiteController::class, new AutoRoutesTestProbe(html: true));

        $response = $this->call('POST', '/_fsc/p', ['token' => $token], [], [], ['REMOTE_ADDR' => '203.0.113.7']);

        $response->assertOk();
        self::assertStringContainsString('auto:over-ons', (string) $response->getContent());
        self::assertStringContainsString('data-fsc-preview', (string) $response->getContent());
    }

    /** @param list<string> $paths @return array<string, array{int, string}> */
    private function answers(array $paths): array
    {
        $answers = [];

        foreach ($paths as $path) {
            $response = $this->get($path);
            $answers[$path] = [$response->status(), (string) $response->getContent()];
        }

        return $answers;
    }
}

/** Stands in for the content route's controller: echoes the path it was given. */
final class AutoRoutesTestProbe
{
    public function __construct(private readonly bool $html = false)
    {
    }

    public function __invoke(Request $request): string
    {
        $text = 'auto:' . $request->route('fscPath');

        return $this->html ? '<!doctype html><html><head></head><body>' . $text . '</body></html>' : $text;
    }
}

/** A site's own controller. */
final class AutoRoutesTestSite
{
    public function __invoke(Request $request): string
    {
        return 'site:' . ltrim($request->path() === '/' ? '' : $request->path(), '/');
    }
}
