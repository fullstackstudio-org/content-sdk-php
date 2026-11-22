<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request as HubRequest;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Http\Transport;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Laravel\Preview\PreviewRequest;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * The portal preview on the site (plan page-builder B, task 1.4): `POST /_fsc/p` with a preview token renders the site's own route with the
 * unpublished page, private and unframeable except by the portal; everything else is untouched. Never talks to the Hub (fake transport).
 */
final class PreviewTest extends LaravelTestCase
{
    private const TOKEN = 'fscpv_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const PORTAL = 'https://portal.fullstackstudio.nl';

    private const LAYOUT = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><title>{{ $page[\'title\'] ?? \'\' }}</title></head><body>'
        . '<x-content::page-blocks :blocks="$page[\'blocks\'] ?? []" /><x-content::form form-key="contact" /><x-content::analytics /></body></html>';

    /** @var list<HubRequest> */
    public array $hub = [];

    /** @var array<string, mixed>|null the preview the fake Hub answers with; null = 404 */
    public ?array $preview = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('content.forms.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $page = static function (string $ref) {
            $page = app(ContentClient::class)->getPage($ref);

            return response(Blade::render(self::LAYOUT, ['page' => $page]))
                ->header('Content-Security-Policy', "default-src 'self'; frame-ancestors 'self'")
                ->header('X-Frame-Options', 'SAMEORIGIN')
                ->header('ETag', '"live"')
                ->header('Cache-Control', 'public, s-maxage=600')
                ->cookie('site_cookie', 'x');
        };

        $router->middleware('web')->get('/over-ons', static fn () => $page('over-ons'));
        $router->middleware('web')->get('/kaal', static fn () => response('<html><head></head><body>x</body></html>'));
        // A site that caches the page itself: the preview must neither read nor write that cache.
        $router->middleware('web')->get('/cached', static fn () => Cache::remember('page:over-ons', 600, static fn () => app(ContentClient::class)->getPage('over-ons'))['title'] ?? '');
        $router->middleware('web')->get('/{path}', static fn (string $path) => $page($path))->where('path', '.*');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preview = [
            'version' => 7,
            'path' => '/over-ons',
            'embedOrigin' => self::PORTAL,
            'page' => ['id' => '11111111-2222-3333-4444-555555555555', 'title' => 'Concept over ons', 'slug' => 'over-ons', 'status' => 'published', 'preview' => true, 'media' => [], 'blocks' => [
                ['id' => 'b1', 'type' => 'richText', 'html' => '<p>Nog niet gepubliceerd</p>'],
                ['type' => 'cta', 'heading' => 'Bel ons', 'label' => 'Bellen', 'href' => 'tel:0101234567'],
            ]],
        ];
        $test = $this;
        $transport = new class ($test) implements Transport {
            public function __construct(private readonly PreviewTest $test)
            {
            }

            public function send(HubRequest $request): HubResponse
            {
                $this->test->hub[] = $request;
                $path = (string) parse_url($request->url, PHP_URL_PATH);

                return match (true) {
                    $path === '/api/v1/pages/preview' => $this->test->preview !== null && ($request->headers['X-FSC-Preview'] ?? null) === PreviewTest::tokenValue()
                        ? FakeTransport::json(['preview' => $this->test->preview], 200, ['cache-control' => 'no-store'])
                        : FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404),
                    $path === '/api/v1/pages' => FakeTransport::json(['page' => ['id' => 'live', 'title' => 'Live over ons', 'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Live tekst</p>']]]]),
                    $path === '/api/v1/forms/contact' => FakeTransport::json(['form' => ['key' => 'contact', 'name' => 'Contact', 'honeypotField' => 'website', 'turnstileSiteKey' => 'site-key', 'fields' => [
                        ['name' => 'name', 'type' => 'text', 'label' => 'Naam', 'required' => true, 'maxLength' => 20],
                    ]]]),
                    $path === '/api/v1/analytics' => FakeTransport::json(['analytics' => ['ready' => true, 'websiteId' => '0b6a3c1e-6f0a-4c1b-9d2e-1f2a3b4c5d6e', 'scriptUrl' => 'https://analytics.fullstackstudio.nl/script.js', 'hostUrl' => 'https://analytics.fullstackstudio.nl', 'domains' => ['localhost']]]),
                    default => FakeTransport::json([]),
                };
            }
        };
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $transport]));
    }

    public static function tokenValue(): string
    {
        return self::TOKEN;
    }

    /** @return list<string> the API paths the site asked the Hub for */
    private function hubPaths(): array
    {
        return array_map(static fn (HubRequest $r): string => substr((string) parse_url($r->url, PHP_URL_PATH), strlen('/api/v1')), $this->hub);
    }

    private function postPreview(string $token = self::TOKEN, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/_fsc/p', ['token' => $token], ['laravel_session' => 'stolen', 'site_cookie' => 'y'], [], [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_ORIGIN' => self::PORTAL,
            ...$server,
        ]);
    }

    public function testAValidTokenRendersTheSiteRouteWithThePreviewPage(): void
    {
        $response = $this->postPreview();
        $html = (string) $response->getContent();

        $response->assertOk();
        self::assertStringContainsString('<title>Concept over ons</title>', $html);
        self::assertStringContainsString('<p>Nog niet gepubliceerd</p>', $html);
        self::assertStringNotContainsString('Live tekst', $html);
        self::assertNotContains('/pages', $this->hubPaths(), 'the preview page never comes from the live page route');

        // The Hub call: the token in a header, the SDK version, never in the URL.
        $call = $this->hub[0];
        self::assertSame('https://content.test/api/v1/pages/preview', $call->url);
        self::assertSame(self::TOKEN, $call->headers['X-FSC-Preview']);
        self::assertSame('php/' . ContentClient::VERSION, $call->headers['X-FSC-SDK']);
        self::assertSame(8000, $call->timeoutMs);
    }

    public function testThePreviewResponseIsPrivateUnindexedAndFramedOnlyByThePortal(): void
    {
        $response = $this->postPreview();
        $headers = $response->headers;

        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertStringNotContainsString('s-maxage', (string) $headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertFalse($headers->has('X-Frame-Options'));
        self::assertFalse($headers->has('ETag'));
        self::assertFalse($headers->has('Last-Modified'));
        self::assertSame([], $headers->getCookies(), 'no cookie of the site, the session or CSRF');
        self::assertFalse($headers->has('Set-Cookie'));
        self::assertSame(["default-src 'self'; frame-ancestors " . self::PORTAL], $headers->all('content-security-policy'));
    }

    public function testThePreviewHasTheScriptTagAndTheBlockMarkers(): void
    {
        $html = (string) $this->postPreview()->getContent();

        self::assertStringContainsString('<html data-fsc-preview lang="nl"><head><script src="/_fsc/p/preview.js?v=' . ContentClient::VERSION . '" data-endpoint="/_fsc/p" data-token="' . self::TOKEN . '" data-origin="' . self::PORTAL . '" data-version="7" data-sdk="' . ContentClient::VERSION . '"></script><meta charset="utf-8">', $html);
        self::assertStringContainsString('<div class="fsc-blocks" data-fsc-preview-blocks >', $html);
        self::assertStringContainsString('<div data-fsc-block="b1" data-fsc-block-index="0" style="display:contents">', $html);
        self::assertStringContainsString('<div data-fsc-block="1" data-fsc-block-index="1" style="display:contents">', $html);
    }

    public function testThePreviewRendersNoAnalyticsAndAFormThatCannotSubmit(): void
    {
        $html = (string) $this->postPreview()->getContent();

        self::assertNotContains('/analytics', $this->hubPaths());
        self::assertStringNotContainsString('_fsc/a/', $html);
        self::assertStringContainsString('<fieldset disabled class="fsc-form__preview"', $html);
        self::assertStringContainsString('</button>' . "\n" . '</fieldset>', $html);
        self::assertStringNotContainsString('challenges.cloudflare.com', $html, 'no Turnstile in a preview');
    }

    public function testThePreviewUsesNoSessionStoreAndNoSiteCache(): void
    {
        config(['session.driver' => 'file', 'session.files' => sys_get_temp_dir() . '/fsc-preview-sessions-' . getmypid()]);
        @mkdir((string) config('session.files'));
        $before = glob(config('session.files') . '/*') ?: [];

        $this->postPreview()->assertOk();

        self::assertSame($before, glob(config('session.files') . '/*') ?: [], 'no session file written');
        self::assertSame('array', config('cache.default'));
    }

    public function testAnInvalidOrUnknownTokenIs404(): void
    {
        $this->postPreview('fscpv_short')->assertNotFound();
        $this->postPreview('fsc_srv_' . str_repeat('A', 43))->assertNotFound();
        self::assertSame([], $this->hub, 'a malformed token never reaches the Hub');

        $this->preview = null;
        $response = $this->postPreview();
        $response->assertNotFound();
        self::assertSame('', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testATokenInTheQueryStringIsIgnored(): void
    {
        $this->call('POST', '/_fsc/p?token=' . self::TOKEN, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'])->assertNotFound();
        self::assertSame([], $this->hub);
    }

    public function testAGetOnThePreviewPathIs404(): void
    {
        $this->get('/_fsc/p')->assertNotFound();
        $this->get('/_fsc/p?token=' . self::TOKEN)->assertNotFound();
        $this->get('/_fsc/p/')->assertNotFound();
        self::assertSame([], $this->hub);
    }

    public function testTheHelperScriptIsServed(): void
    {
        $response = $this->get('/_fsc/p/preview.js');

        $response->assertOk();
        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString("var NS = 'fsc-preview';", (string) $response->getContent());
    }

    public function testANormalPageRequestIsUnchanged(): void
    {
        $response = $this->get('/over-ons');
        $html = (string) $response->getContent();

        $response->assertOk();
        self::assertStringContainsString('Live tekst', $html);
        self::assertStringNotContainsString('data-fsc-preview', $html);
        self::assertStringNotContainsString('data-fsc-block', $html);
        self::assertStringNotContainsString('preview.js', $html);
        self::assertStringNotContainsString('<fieldset', $html);
        self::assertStringContainsString('_fsc/a/', $html, 'analytics render on a live page');
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame(["default-src 'self'; frame-ancestors 'self'"], $response->headers->all('content-security-policy'));
        self::assertSame('"live"', $response->headers->get('ETag'));
        self::assertNotSame([], $response->headers->getCookies());
        self::assertNotContains('/pages/preview', $this->hubPaths());
        self::assertFalse(app(Preview::class)->active());
    }

    public function testEveryHubCallCarriesTheSdkHeader(): void
    {
        $this->get('/over-ons')->assertOk();

        self::assertNotSame([], $this->hub);
        foreach ($this->hub as $call) {
            self::assertSame('php/' . ContentClient::VERSION, $call->headers['X-FSC-SDK'] ?? null, $call->url);
        }
    }

    public function testAPageWithoutASlugRendersThroughTheCatchAll(): void
    {
        $this->preview['path'] = '/voorbeeld-ab12cd34';
        $this->preview['page']['slug'] = null;

        $html = (string) $this->postPreview()->getContent();

        self::assertStringContainsString('Nog niet gepubliceerd', $html);
    }

    public function testTheLiveCacheIsNeverWrittenWithThePreview(): void
    {
        config(['cache.default' => 'file', 'cache.stores.file.path' => sys_get_temp_dir() . '/fsc-preview-cache-' . getmypid()]);
        Cache::store('file')->flush();
        $this->preview['path'] = '/cached';
        $this->preview['page']['slug'] = 'over-ons';

        Cache::store('file')->put('page:over-ons', ['title' => 'Live uit de cache'], 600);
        $response = $this->postPreview();

        $response->assertOk();
        self::assertStringEndsWith('></script>Concept over ons', (string) $response->getContent());
        self::assertSame(['title' => 'Live uit de cache'], Cache::store('file')->get('page:over-ons'), 'the preview neither reads nor overwrites the site cache');
        Cache::store('file')->flush();
    }

    public function testASiteWithoutCspGetsOnlyFrameAncestors(): void
    {
        $this->preview['path'] = '/kaal';

        $response = $this->postPreview();

        self::assertSame(['frame-ancestors ' . self::PORTAL], $response->headers->all('content-security-policy'));
        self::assertStringContainsString('<head><script src="/_fsc/p/preview.js', (string) $response->getContent());
    }

    public function testHubAnswersThatAreOffAreRefused(): void
    {
        foreach ([
            ['path' => 'https://evil.test/x'],
            ['path' => '//evil.test/x'],
            ['path' => '/../etc'],
            ['path' => '/a?b=c'],
            ['path' => '/_fsc/p'],
            ['path' => '/_fsc/p/preview.js'],
            ['embedOrigin' => 'https://portal.test/path'],
            ['embedOrigin' => "https://portal.test 'unsafe-inline'"],
            ['embedOrigin' => 'http://portal.test'],
            ['embedOrigin' => '*'],
            ['page' => 'x'],
            ['page' => [['type' => 'richText']]],
        ] as $override) {
            $this->preview = [...$this->preview, ...$override];
            $this->postPreview()->assertNotFound();
            $this->setUp();
        }
    }

    public function testTheRateLimitStopsAFlood(): void
    {
        config(['content.preview.rate_limit' => 2]);

        $this->postPreview()->assertOk();
        $this->postPreview()->assertOk();
        $this->postPreview()->assertStatus(429);
        self::assertSame(2, count(array_filter($this->hubPaths(), static fn (string $p): bool => $p === '/pages/preview')));
    }

    public function testFrameAncestorsRewritesEveryPolicy(): void
    {
        self::assertSame(["script-src 'self'; frame-ancestors https://p.test", "img-src *"], PreviewRequest::frameAncestors(["frame-ancestors 'none'; script-src 'self'", "img-src *; frame-ancestors 'self'"], 'https://p.test'));
        self::assertSame(['frame-ancestors https://p.test'], PreviewRequest::frameAncestors([], 'https://p.test'));
    }
}
