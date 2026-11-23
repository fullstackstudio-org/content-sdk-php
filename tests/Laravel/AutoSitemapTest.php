<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Laravel\Robots;
use FullStackStudio\Content\Laravel\Site\SiteController;
use FullStackStudio\Content\Laravel\Sitemap;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Routing\Router;

/**
 * The automatic sitemap.xml and robots.txt (plan sdk-out-of-the-box, task 6): the site's own static pages (rule D11) plus the Hub entries,
 * the empty state, the cache headers and the same output with cached routes. Fake Hub only.
 */
final class AutoSitemapTest extends LaravelTestCase
{
    /** @var (Closure(Router): void)|null */
    private ?Closure $siteRoutes = null;

    /** @var array<string, mixed> `content.auto` values of the next application */
    private array $auto = [];

    private FakeTransport $hub;

    /** @var array<string, mixed> */
    private array $sitemap = ['robotsIndex' => true, 'canonicalOrigin' => 'https://klant.test', 'entries' => []];

    /** @var array<string, mixed> */
    private array $seo = ['robotsIndex' => true];

    /** @var array<string, array{0: int, 1: array<string, mixed>}> API path => status and body, overrides the defaults */
    private array $failures = [];

    private ?string $routeCache = null;

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

    private function site(?Closure $routes = null, array $auto = []): void
    {
        $this->siteRoutes = $routes;
        $this->auto = $auto;
        $this->refreshApplication();
        $this->fakes();
    }

    private function fakes(): void
    {
        $this->hub = new FakeTransport();
        $this->hub->queue(...array_fill(0, 100, fn (Request $request): HubResponse => $this->answer($request)));
        $hub = $this->hub;
        $this->app->singleton(ContentClient::class, static fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $hub]));
        $this->app->instance(SiteController::class, new class () {
            public function __invoke(): string
            {
                return 'content';
            }
        });
    }

    private function answer(Request $request): HubResponse
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

        if (isset($this->failures[$path])) {
            return FakeTransport::json($this->failures[$path][1], $this->failures[$path][0]);
        }

        return match ($path) {
            '/sitemap' => FakeTransport::json($this->sitemap),
            '/seo' => FakeTransport::json(['seo' => $this->seo]),
            '/project' => FakeTransport::json(['defaultLocale' => 'nl', 'locales' => ['nl', 'en']]),
            default => FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404),
        };
    }

    /** @return list<string> the API paths asked for */
    private function hubPaths(): array
    {
        return array_map(static fn (Request $r): string => substr((string) parse_url($r->url, PHP_URL_PATH), strlen('/api/v1')), $this->hub->requests);
    }

    /** @return list<string> the `<loc>` values of a sitemap body, in order */
    private function locs(string $xml): array
    {
        $document = simplexml_load_string($xml);
        self::assertNotFalse($document, 'well-formed XML');

        $locs = [];

        foreach ($document->url as $url) {
            $locs[] = (string) $url->loc;
        }

        return $locs;
    }

    private function usualSite(): Closure
    {
        return static function (Router $router): void {
            $router->middleware('web')->group(static function () use ($router): void {
                $router->get('/', fn () => 'home');
                $router->get('/over-ons', fn () => 'about');
                $router->get('/contact/', fn () => 'contact');
                $router->get('/diensten/{slug}', fn (string $slug) => $slug);
                $router->get('/zoeken/{term?}', fn () => 'search');
                $router->get('/_fsc/c', fn () => 'consent');
                $router->get('/content/forms', fn () => 'forms');
                $router->redirect('/oud', '/nieuw');
                $router->get('/account', fn () => 'account')->middleware('auth');
                $router->get('/beheer', fn () => 'admin')->middleware('can:manage');
                $router->get('/bedankt', fn () => 'thanks')->noSitemap();
                $router->get('/intern/status', fn () => 'status');
                $router->get('/feed.xml', fn () => 'feed');
                $router->get('/api/ping', fn () => 'ping');
                $router->get('/up', fn () => 'up');
                $router->post('/versturen', fn () => 'sent');
                $router->get('/mijn/pagina', fn () => 'nested')->name('mine');
            });
            // Not in the `web` group: no session, so not a page of the site.
            $router->get('/kale-route', fn () => 'bare');
        };
    }

    private function exclude(): array
    {
        return ['sitemap' => ['enabled' => true, 'exclude' => ['intern/*']]];
    }

    public function testOwnPathsCollectsTheStaticPagesOfTheWebGroupOnly(): void
    {
        $this->site($this->usualSite(), $this->exclude());

        self::assertSame(['/', '/contact', '/mijn/pagina', '/over-ons'], Sitemap::ownPaths());
    }

    public function testExcludePatternsAreOptional(): void
    {
        $this->site($this->usualSite());

        self::assertContains('/intern/status', Sitemap::ownPaths());
    }

    public function testTheNoSitemapMacroSetsARouteDefault(): void
    {
        $this->site(static fn (Router $router) => $router->middleware('web')->get('/geheim', fn () => 'x')->noSitemap());

        $route = collect($this->app['router']->getRoutes()->getRoutes())->first(static fn ($route): bool => $route->uri() === 'geheim');

        self::assertTrue($route->defaults[Sitemap::NO_SITEMAP]);
        self::assertSame([], Sitemap::ownPaths());
    }

    public function testTheSitemapMergesOwnPagesAndHubEntriesWithoutDuplicates(): void
    {
        $this->sitemap['entries'] = [
            ['path' => '/', 'locale' => 'nl', 'lastmod' => '2026-11-01'],
            ['path' => '/over-ons', 'locale' => 'nl', 'lastmod' => null],
            ['path' => '/blog/hallo', 'locale' => 'nl', 'lastmod' => '2026-10-02'],
            ['path' => '/blog/hallo', 'locale' => 'nl', 'lastmod' => '2026-10-03'],
            ['path' => '/over-ons', 'locale' => 'en', 'lastmod' => '2026-10-04'],
            ['path' => '/zoek?x=1&y=2', 'locale' => 'nl'],
            ['path' => '', 'locale' => 'nl'],
        ];
        $this->site($this->usualSite(), $this->exclude());

        $response = $this->get('/sitemap.xml');
        $response->assertOk();

        self::assertSame([
            'https://klant.test/',
            'https://klant.test/contact',
            'https://klant.test/mijn/pagina',
            'https://klant.test/over-ons',
            'https://klant.test/blog/hallo',
            'https://klant.test/en/over-ons',
            'https://klant.test/zoek?x=1&y=2',
        ], $this->locs($response->getContent()));

        $xml = $response->getContent();
        self::assertStringContainsString('<loc>https://klant.test/</loc><lastmod>2026-11-01</lastmod>', $xml, 'a Hub entry brings the lastmod of an own page');
        self::assertStringContainsString('<loc>https://klant.test/blog/hallo</loc><lastmod>2026-10-02</lastmod>', $xml, 'the first entry of a URL stays');
        self::assertStringContainsString('x=1&amp;y=2', $xml, 'escaped');
        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function testTheOriginFallsBackToTheAppUrl(): void
    {
        $this->sitemap['canonicalOrigin'] = null;
        $this->sitemap['entries'] = [['path' => '/a', 'locale' => 'nl']];
        $this->site(static fn (Router $router) => $router->middleware('web')->get('/over-ons', fn () => 'x'));

        self::assertSame(['http://localhost/over-ons', 'http://localhost/a'], $this->locs($this->get('/sitemap.xml')->getContent()));
    }

    public function testTrailingSlashAndLocaleVariants(): void
    {
        $this->sitemap['entries'] = [
            ['path' => '/', 'locale' => 'nl'],
            ['path' => '/', 'locale' => 'en'],
            ['path' => '/over-ons', 'locale' => 'en'],
            ['path' => '/en/already', 'locale' => 'en'],
            ['path' => '/zoek?x=1', 'locale' => 'nl'],
        ];
        $this->site(static fn (Router $router) => $router->middleware('web')->get('/contact', fn () => 'x'), ['trailing_slash' => true]);

        self::assertSame([
            'https://klant.test/contact/',
            'https://klant.test/',
            'https://klant.test/en/',
            'https://klant.test/en/over-ons/',
            'https://klant.test/en/already/',
            'https://klant.test/zoek/?x=1',
        ], $this->locs($this->get('/sitemap.xml')->getContent()));
    }

    public function testEntriesInALanguageThatIsNotEnabledAreLeftOut(): void
    {
        $this->sitemap['entries'] = [
            ['path' => '/over-ons', 'locale' => 'nl'],
            ['path' => '/about', 'locale' => 'en'],
            ['path' => '/a-propos', 'locale' => 'fr'],
        ];
        $this->site(static fn (Router $router) => null);

        self::assertSame([
            'https://klant.test/over-ons',
            'https://klant.test/en/about',
        ], $this->locs($this->get('/sitemap.xml')->getContent()));
    }

    public function testWhileRobotsIndexIsOffTheUrlsetIsEmptyAndSaysSo(): void
    {
        $this->sitemap = ['robotsIndex' => false, 'canonicalOrigin' => 'https://klant.test', 'entries' => [['path' => '/a', 'locale' => 'nl']]];
        $this->site($this->usualSite());

        $response = $this->get('/sitemap.xml');
        $response->assertOk();

        $xml = $response->getContent();
        self::assertSame([], $this->locs($xml));
        self::assertStringContainsString('<!-- Empty on purpose: robotsIndex is off', $xml);
        self::assertStringContainsString('<urlset', $xml);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testAnAnsweredSitemapIsPublicAndCookieFree(): void
    {
        $this->site($this->usualSite());

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('900', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-cache'));
        self::assertSame([], $response->headers->getCookies());
    }

    public function testAHubFailureGives503WithRetryAfterAndNoStore(): void
    {
        $this->site($this->usualSite());
        $this->failures['/sitemap'] = [500, ['error' => ['code' => 'server_error', 'message' => 'x']]];

        $response = $this->get('/sitemap.xml');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('300', $response->headers->get('Retry-After'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('', $response->getContent());
    }

    public function testWithTheSeoModuleOffOnlyTheOwnPagesAreListed(): void
    {
        $this->site($this->usualSite(), $this->exclude());
        $this->failures['/sitemap'] = [404, ['error' => ['code' => 'module_disabled', 'message' => 'x']]];

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        self::assertSame(['http://localhost/', 'http://localhost/contact', 'http://localhost/mijn/pagina', 'http://localhost/over-ons'], $this->locs($response->getContent()));
    }

    public function testOnlyTheSitemapAndProjectAreReadFromTheHub(): void
    {
        $this->sitemap['entries'] = [['path' => '/a', 'locale' => 'en']];
        $this->site($this->usualSite());

        $this->get('/sitemap.xml')->assertOk();

        self::assertSame(['/sitemap', '/project'], array_values(array_unique($this->hubPaths())));
    }

    public function testASiteRouteForSitemapXmlStillWins(): void
    {
        $this->site(static fn (Router $router) => $router->get('/sitemap.xml', fn () => 'mine'));

        self::assertSame('mine', $this->get('/sitemap.xml')->getContent());
        self::assertSame([], $this->hub->requests);
    }

    public function testTheOutputIsIdenticalAfterRouteCache(): void
    {
        $this->sitemap['entries'] = [['path' => '/blog/x', 'locale' => 'nl', 'lastmod' => '2026-10-01'], ['path' => '/over-ons', 'locale' => 'en']];
        $routes = $this->usualSite();
        $this->site($routes, $this->exclude());
        $before = $this->get('/sitemap.xml')->getContent();
        self::assertStringContainsString('/blog/x', $before);
        self::assertStringNotContainsString('/bedankt', $before);
        self::assertStringNotContainsString('/oud', $before);

        $collection = $this->app['router']->getRoutes();

        foreach ($collection as $route) {
            $route->prepareForSerialization();
        }

        $this->routeCache = sys_get_temp_dir() . '/fsc-sitemap-routes-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->routeCache, "<?php\n\napp('router')->setCompiledRoutes(\n    " . var_export($collection->compile(), true) . "\n);\n");
        $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = $this->routeCache;
        putenv('APP_ROUTES_CACHE=' . $this->routeCache);

        $this->site($routes, $this->exclude());
        self::assertTrue($this->app->routesAreCached());
        require $this->routeCache;
        $this->app['router']->getRoutes()->refreshNameLookups();

        self::assertInstanceOf(\Illuminate\Routing\CompiledRouteCollection::class, $this->app['router']->getRoutes());
        self::assertSame($before, $this->get('/sitemap.xml')->getContent());
    }

    public function testTheStaticHelperKeepsItsBehaviour(): void
    {
        $this->site();
        $this->sitemap['entries'] = [['path' => '/about', 'locale' => 'nl']];

        $response = Sitemap::response();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<loc>https://klant.test/about</loc>', $response->getContent());
        self::assertFalse($response->headers->hasCacheControlDirective('public'), 'the helper sets no cache header');
    }

    public function testRobotsFollowsTheSeoSettingsAndAddsTheExtraLines(): void
    {
        $this->site(null, ['robots' => ['enabled' => true, 'extra' => ['Disallow: /account', "Disallow: /x\nInjected: yes"]]]);

        $response = $this->get('/robots.txt');

        $response->assertOk();
        self::assertSame("User-agent: *\nDisallow: /account\nDisallow: /x Injected: yes\nAllow: /\n\nSitemap: http://localhost/sitemap.xml\n", $response->getContent());
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('900', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(['/seo'], array_values(array_unique($this->hubPaths())));
    }

    public function testRobotsDisallowsEverythingWhileRobotsIndexIsOff(): void
    {
        $this->seo['robotsIndex'] = false;
        $this->site(null, ['robots' => ['enabled' => true, 'extra' => ['Disallow: /account']]]);

        $response = $this->get('/robots.txt');

        self::assertSame("User-agent: *\nDisallow: /\n", $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testRobotsIsNotCachedWhenTheHubFailed(): void
    {
        $this->site();
        $this->failures['/seo'] = [500, ['error' => ['code' => 'server_error', 'message' => 'x']]];

        $response = $this->get('/robots.txt');

        $response->assertOk();
        self::assertStringContainsString('Allow: /', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testRobotsHelperKeepsItsBehaviourAndAPlainTrueSwitchStillWorks(): void
    {
        $this->site(null, ['robots' => true]);

        self::assertFalse(Robots::response()->headers->hasCacheControlDirective('public'), 'the helper sets no cache header');
        $this->get('/robots.txt')->assertOk();
    }
}
