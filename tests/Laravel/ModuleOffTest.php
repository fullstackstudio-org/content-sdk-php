<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Laravel\Robots;
use FullStackStudio\Content\Laravel\Sitemap;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;

/**
 * "Always in every site": pages, redirects, sitemap/robots, site, analytics and errors are wired in every layout and route file, also for a
 * project without the module. Off = a normal 404 or nothing, never an exception or a log line above debug.
 */
final class ModuleOffTest extends LaravelTestCase
{
    private FakeTransport $http;

    /** @var array<string, Response|TransportException> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->http->queue(...array_fill(0, 50, function (Request $request): Response {
            $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
            $answer = $this->routes[$path] ?? self::off();

            if ($answer instanceof TransportException) {
                throw $answer;
            }

            return $answer;
        }));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        Log::spy();
    }

    protected function defineRoutes($router): void
    {
        Route::get('/sitemap.xml', fn () => Sitemap::response(['extra' => [['url' => 'https://jansen.nl/']]]));
        Route::get('/robots.txt', fn () => Robots::response());
        Route::middleware(RedirectMiddleware::class)->get('/{path}', function (string $path) {
            try {
                return app(ContentClient::class)->getPage($path)['title'];
            } catch (ContentException $e) {
                if ($e->isNotFound()) {
                    abort(404);
                }

                abort(503);
            }
        })->where('path', '.*');
    }

    private static function off(): Response
    {
        return FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'Deze module staat voor dit project niet aan.']], 404);
    }

    private function assertNothingAboveDebugWasLogged(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $level) {
            Log::shouldNotHaveReceived($level);
            Log::shouldNotHaveReceived('log', [$level, Mockery::any(), Mockery::any()]);
        }
    }

    public function testEveryComponentRendersNothing(): void
    {
        foreach (['<x-content::head />', '<x-content::body-end />', '<x-content::analytics />', '<x-content::errors />'] as $component) {
            self::assertSame('', trim(Blade::render($component)), $component);
        }

        self::assertStringContainsString('<title>Over ons</title>', Blade::render('<x-content::meta :page="$page" />', ['page' => ['title' => 'Over ons']]));
        $this->assertNothingAboveDebugWasLogged();
    }

    public function testThePageRouteIsANormal404AndRedirectsDoNothing(): void
    {
        self::assertTrue((new ContentException(404, 'module_disabled', 'x'))->isModuleDisabled());
        self::assertFalse((new ContentException(404, 'not_found', 'x'))->isModuleDisabled());

        $this->get('/diensten/lekkage')->assertNotFound();
        $this->get('/over-ons')->assertNotFound();

        $redirectCalls = array_filter($this->http->requests, static fn (Request $r): bool => str_contains($r->url, '/redirects/resolve'));
        self::assertCount(1, $redirectCalls, 'redirect lookups pause while the module is off');
        $this->assertNothingAboveDebugWasLogged();
    }

    public function testTheSitemapListsYourOwnEntriesAndRobotsAllowsCrawling(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>https://jansen.nl/</loc>', false);
        $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nAllow: /\n\nSitemap: http://localhost/sitemap.xml\n", false);
        $this->assertNothingAboveDebugWasLogged();
    }

    public function testSitemapAndRobotsFollowContentWhenOn(): void
    {
        $this->routes['/sitemap'] = FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => null, 'entries' => [['path' => '/diensten', 'lastmod' => '2026-10-01']]]);
        $this->routes['/seo'] = FakeTransport::json(['seo' => ['robotsIndex' => false]]);

        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>http://localhost/diensten</loc>', false);
        $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /\n", false);
    }

    public function testSitemapAndRobotsAnswer503WhenContentIsDown(): void
    {
        $this->routes['/sitemap'] = new TransportException('down', true);
        $this->routes['/seo'] = new TransportException('down', true);

        $this->get('/sitemap.xml')->assertStatus(503)->assertHeader('Retry-After', '300');
        $this->get('/robots.txt')->assertStatus(503);
    }
}
