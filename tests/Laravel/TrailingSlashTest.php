<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request as HubRequest;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Http\Transport;
use FullStackStudio\Content\Laravel\Auto\AutoPlatform;
use FullStackStudio\Content\Laravel\Site\Urls;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Closure;
use FullStackStudio\Content\Laravel\Site\TrailingSlash;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trailing slash option (plan sdk-out-of-the-box, task 10): with `content.auto.trailing_slash` on, GET/HEAD page paths get a 301 to the
 * slashed form; files, technical prefixes, visitor proxy paths and the portal preview are left alone; it is off by default.
 */
final class TrailingSlashTest extends LaravelTestCase
{
    private const TOKEN = 'fscpv_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** Per test the config the application starts with (the default of every test: the option on). */
    private const SETUP = [
        'testOffByDefaultRedirectsNothing' => ['content.auto.trailing_slash' => false],
        'testMasterSwitchOffRedirectsNothing' => ['content.auto' => ['enabled' => false, 'trailing_slash' => true]],
        'testExtraSkipPrefixesFromConfig' => ['content.auto.trailing_slash_skip' => ['/feed', 'shop/']],
    ];

    public int $hits = 0;

    /** @var list<string> every path that reached the stack behind the preview middleware, before the trailing slash middleware */
    public static array $seen = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.auto.trailing_slash', true);
        // The visitor paths would be answered by the proxy; off, the site's catch-all shows that the middleware let them through.
        $app['config']->set('content.auto.visitor_pages', false);

        self::$seen = [];
        // Registered before the package's own booted callback, so it sits between the preview middleware and the trailing slash middleware.
        $app->booted(static function ($app): void {
            $app->make(Kernel::class)->pushMiddleware(TrailingSlashSeen::class);
        });

        foreach (self::SETUP[$this->name()] ?? [] as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/', static fn () => response('home'));
        $router->middleware('web')->match(['get', 'post', 'put'], '/{path}', function (string $path) {
            $this->hits++;

            return response('page:' . $path);
        })->where('path', '.*');
    }

    /** Through the HTTP kernel with the URI exactly as given: the test client trims a trailing slash off the URI. */
    private function send(string $uri, string $method = 'GET'): Response
    {
        return $this->app->make(Kernel::class)->handle(Request::create($uri, $method));
    }

    public function testRedirectsAPathWithoutASlash(): void
    {
        $response = $this->get('/hypnotherapie');

        $response->assertStatus(301);
        $this->assertSame('/hypnotherapie/', $response->headers->get('Location'));
    }

    public function testRedirectsANestedPathAndHead(): void
    {
        $this->get('/diensten/hypnose')->assertRedirect('/diensten/hypnose/');

        $response = $this->call('HEAD', '/over-ons');
        $response->assertStatus(301);
        $this->assertSame('/over-ons/', $response->headers->get('Location'));
    }

    public function testKeepsTheQueryString(): void
    {
        $response = $this->get('/zoeken?q=a+b&page=2');

        $response->assertStatus(301);
        // Symfony normalises the query string (sorted, percent encoded); every parameter survives.
        $this->assertSame('/zoeken/?page=2&q=a%20b', $response->headers->get('Location'));
    }

    public function testAPathWithASlashAndTheRootAreServed(): void
    {
        $this->assertSame(200, $this->send('/hypnotherapie/')->getStatusCode());
        $this->assertSame(200, $this->send('/')->getStatusCode());
        $this->assertSame(200, $this->send('/hypnotherapie/?a=1')->getStatusCode());
    }

    public function testNeverRedirectsAFile(): void
    {
        foreach (['/feed.xml', '/map/bestand.pdf'] as $path) {
            $this->get($path)->assertOk();
        }

        // Answered by the SDK itself (no Hub in this test): never a redirect to a slashed path.
        $this->assertNotSame(301, $this->get('/sitemap.xml')->getStatusCode());
    }

    public function testSkipsTheTechnicalPrefixes(): void
    {
        foreach (['/api/health', '/_fsc/x', '/content/forms/contact', '/build/assets', '/storage/foto'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function testSkipsTheVisitorProxyPaths(): void
    {
        foreach (['/a/l/tok', '/a/c/tok/afspraak', '/a/c/tok/afspraak.ics', '/e/i/proj', '/n/u/tok', '/t/tok', '/t', '/r/tok', '/api/v1/downloads/tok'] as $path) {
            $this->get($path)->assertOk();
        }

        // Only the paths of the proxy table (VisitorRoutes::matches) are skipped: the same first segment with another shape is an ordinary
        // page, and so is a longer first segment that merely starts with one of those letters.
        $this->get('/a')->assertStatus(301);
        $this->get('/a/over-ons')->assertStatus(301);
        $this->get('/afspraak')->assertStatus(301);
        $this->get('/nieuws')->assertStatus(301);
    }

    public function testOnlyGetAndHeadAreRedirected(): void
    {
        $this->assertSame(200, $this->send('/hypnotherapie', 'POST')->getStatusCode());
        $this->assertSame(200, $this->send('/hypnotherapie', 'PUT')->getStatusCode());
    }

    public function testLeadingSlashesCollapseAndStayOnTheHost(): void
    {
        // The request line `GET //evil HTTP/1.1`: the path keeps both slashes, a redirect to it would leave the site.
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET', 'SERVER_NAME' => 'localhost', 'REQUEST_URI' => '//evil']);
        $response = (new TrailingSlash())->handle($request, static fn (): Response => new Response('next'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/evil/', $response->headers->get('Location'));

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET', 'SERVER_NAME' => 'localhost', 'REQUEST_URI' => '///x//b?x=1', 'QUERY_STRING' => 'x=1']);
        $response = (new TrailingSlash())->handle($request, static fn (): Response => new Response('next'));

        $this->assertSame('/x//b/?x=1', $response->headers->get('Location'));
    }

    public function testExtraSkipPrefixesFromConfig(): void
    {
        $this->get('/feed')->assertOk();
        $this->get('/shop')->assertOk();
        $this->get('/blog')->assertStatus(301);
    }

    public function testOffByDefaultRedirectsNothing(): void
    {
        $this->assertSame(AutoPlatform::OFF, AutoPlatform::trailingSlash());
        $this->get('/hypnotherapie')->assertOk();
    }

    public function testDefaultConfigIsOff(): void
    {
        $config = require __DIR__ . '/../../config/content.php';

        $this->assertFalse($config['auto']['trailing_slash']);
        $this->assertSame([], $config['auto']['trailing_slash_skip']);
    }

    public function testMasterSwitchOffRedirectsNothing(): void
    {
        $this->get('/hypnotherapie')->assertOk();
    }

    public function testMiddlewareIsRegisteredOnceAndReported(): void
    {
        $this->app->make(Kernel::class);

        $this->assertSame(AutoPlatform::APPLIED, AutoPlatform::trailingSlash());
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(\FullStackStudio\Content\Laravel\Site\TrailingSlash::class));
    }

    public function testUrlsOutputMatchesTheRedirectTarget(): void
    {
        $built = $this->app->make(Urls::class)->path('/hypnotherapie');
        $target = (string) parse_url((string) $this->get('/hypnotherapie')->headers->get('Location'), PHP_URL_PATH);

        $this->assertSame('/hypnotherapie/', $built);
        $this->assertSame($built, $target);
    }

    public function testPreviewThroughAnUnslashedPathStillRenders(): void
    {
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $transport = new class implements Transport {
            public function send(HubRequest $request): HubResponse
            {
                return FakeTransport::json(['preview' => [
                    'version' => 7,
                    'path' => '/over-ons',
                    'embedOrigin' => 'https://portal.fullstackstudio.nl',
                    'page' => ['id' => '11111111-2222-3333-4444-555555555555', 'title' => 'Concept', 'slug' => 'over-ons', 'status' => 'published', 'preview' => true, 'media' => [], 'blocks' => []],
                ]], 200, ['cache-control' => 'no-store']);
            }
        };
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $transport]));

        $response = $this->call('POST', '/_fsc/p', ['token' => self::TOKEN], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_ORIGIN' => 'https://portal.fullstackstudio.nl']);

        $response->assertOk();
        $this->assertSame(1, $this->hits);
        $this->assertSame(['/over-ons'], self::$seen, 'the forwarded request is rendered at once, not redirected to the slashed path first');
        $this->assertStringContainsString('page:over-ons', (string) $response->getContent());
    }
}

/** Test spy: records the path of every request it sees. */
final class TrailingSlashSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        TrailingSlashTest::$seen[] = $request->getPathInfo();

        return $next($request);
    }
}
