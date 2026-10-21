<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\SiteSettings;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Unit\SiteTest;
use FullStackStudio\Content\Webhooks\WebhookVerifier;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Mockery;

/** `<x-content::head />`, `<x-content::body-end />`, the OG fallback of `<x-content::meta>` and the CSP helpers. Fake transport only. */
final class SiteComponentsTest extends LaravelTestCase
{
    private const WEBSITE = '2d585159-5e61-400a-8326-029816f769a9';

    private FakeTransport $http;

    /** @var array<string, Response|TransportException> answers per API path (after /api/v1) */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->http->always(new Response(500, [], '{}'));
        $this->http->queue(...array_fill(0, 50, fn (Request $request): Response => $this->answer($request)));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
    }

    private function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        $answer = $this->routes[$path] ?? FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404);

        if ($answer instanceof TransportException) {
            throw $answer;
        }

        return $answer;
    }

    /** @return list<string> */
    private function calls(string $path): array
    {
        return array_values(array_filter(
            array_map(static fn (Request $r): string => substr((string) parse_url($r->url, PHP_URL_PATH), strlen('/api/v1')), $this->http->requests),
            static fn (string $p): bool => $p === $path,
        ));
    }

    private function assertNothingAboveDebugWasLogged(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $level) {
            Log::shouldNotHaveReceived($level);
            Log::shouldNotHaveReceived('log', [$level, Mockery::any(), Mockery::any()]);
        }
    }

    private function newRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function analyticsOn(): void
    {
        $this->routes['/analytics'] = FakeTransport::json(['analytics' => ['ready' => true, 'websiteId' => self::WEBSITE, 'domains' => ['jansen.nl']]]);
    }

    public function testHeadAndBodyEndRenderTheSiteWithTheViteNonceAndOneLookup(): void
    {
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        $this->analyticsOn();
        Vite::useCspNonce('v1te');

        $head = Blade::render('<x-content::head />');
        $body = Blade::render('<x-content::body-end />');

        self::assertStringContainsString('<meta name="google-site-verification" content="g00gle-Token_1">', $head);
        self::assertStringContainsString('<script type="application/ld+json" nonce="v1te">', $head);
        self::assertStringContainsString('<template data-fsc-code data-consent="analytics"', $head);
        self::assertStringContainsString('<template data-fsc-code data-consent="marketing"', $body);
        self::assertStringContainsString("u.track('contact-click'", $body);

        preg_match_all('/<(script|style)\b[^>]*>/i', $head . $body, $tags);

        foreach ($tags[0] as $tag) {
            self::assertStringContainsString('nonce="v1te"', $tag);
        }

        self::assertCount(1, $this->calls('/site'), 'head and body-end share one lookup per request');
    }

    public function testAnExplicitNonceAndConsentKeyAreUsed(): void
    {
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        config(['content.analytics.consent_key' => 'cookie-choice']);

        $head = Blade::render('<x-content::head nonce="expl1cit" />');

        self::assertStringContainsString('<script type="application/ld+json" nonce="expl1cit">', $head);
        self::assertStringContainsString('"key":"cookie-choice"', $head);
    }

    public function testClickEventsNeedTheAnalyticsModule(): void
    {
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        $this->routes['/analytics'] = FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404);

        $body = Blade::render('<x-content::body-end />');

        self::assertStringNotContainsString('contact-click', $body);
        self::assertStringContainsString('data-consent="marketing"', $body);
    }

    public function testTheEmptyShapeRendersNothingAndLogsAtDebugOnly(): void
    {
        Log::spy();
        $this->routes['/site'] = FakeTransport::json(SiteTest::emptyShape());

        self::assertSame('', Blade::render('<x-content::head />'));
        self::assertSame('', Blade::render('<x-content::body-end />'));
        self::assertSame([], Content::siteCsp());

        $this->assertNothingAboveDebugWasLogged();
    }

    public function testModuleDisabledFromAnOlderServerRendersNothingAndPauses(): void
    {
        Log::spy();
        $this->routes['/site'] = FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404);

        self::assertSame('', Blade::render('<x-content::head />'));
        $this->newRequest();
        self::assertSame('', Blade::render('<x-content::body-end />'));

        self::assertCount(1, $this->calls('/site'), 'the module being off is remembered for a while');
        $this->assertNothingAboveDebugWasLogged();
    }

    public function testAnOutageRendersNothingWarnsOnceAndPauses(): void
    {
        Log::spy();
        $this->routes['/site'] = new TransportException('down', true);

        self::assertSame('', Blade::render('<x-content::head />'));
        self::assertSame('', Blade::render('<x-content::body-end />'));
        self::assertStringContainsString('<title>', Blade::render('<x-content::meta :page="$page" />', ['page' => ['title' => 'Over ons']]));
        $this->newRequest();
        self::assertSame('', Blade::render('<x-content::head />'));

        self::assertCount(1, $this->calls('/site'), 'one failed lookup, then a pause');
        Log::shouldHaveReceived('log')->once()->withArgs(static fn (string $level): bool => $level === 'warning');
    }

    public function testAnOutageAfterAGoodAnswerKeepsTheLastGoodAnswer(): void
    {
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        Blade::render('<x-content::head />');

        $this->newRequest();
        config(['content.cache.enabled' => false]);
        $this->app->forgetInstance(ContentClient::class);
        $this->routes['/site'] = FakeTransport::json(['error' => ['code' => 'internal', 'message' => 'x']], 500);

        self::assertStringContainsString('google-site-verification', Blade::render('<x-content::head />'));
    }

    public function testMetaFallsBackToTheSiteShareImage(): void
    {
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        $this->routes['/seo'] = FakeTransport::json(['seo' => ['siteName' => 'Jansen']]);

        $html = Blade::render('<x-content::meta :page="$page" path="/over" />', ['page' => ['title' => 'Over ons']]);

        self::assertStringContainsString('<meta property="og:image" content="https://content.test/api/media/og">', $html);
        self::assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);

        $own = Blade::render('<x-content::meta :page="$page" />', ['page' => ['title' => 'Blog', 'cover' => ['url' => 'https://content.test/api/media/cover']]]);
        self::assertStringContainsString('<meta property="og:image" content="https://content.test/api/media/cover">', $own, 'the page image wins');
    }

    public function testSiteCspHelpers(): void
    {
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());

        self::assertSame(['https://cdn.banner.test', 'https://www.googletagmanager.com'], Content::siteCsp()['script-src']);
        self::assertSame(
            ['default-src' => ["'self'"], 'script-src' => ["'self'", "'nonce-x'", 'https://cdn.banner.test', 'https://www.googletagmanager.com']],
            array_intersect_key(Content::siteCspMerge(['default-src' => ["'self'"], 'script-src' => ["'self'", "'nonce-x'"]]), ['default-src' => 1, 'script-src' => 1]),
        );
        self::assertStringContainsString("frame-src 'self' https://player.vimeo.com", Content::siteCspMerge("default-src 'self'"));
    }

    public function testTheSiteWebhookClearsTheCacheAndThePause(): void
    {
        $this->routes['/site'] = FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404);
        self::assertSame('', Blade::render('<x-content::head />'));

        $body = '{"id":"e1","type":"site.updated","createdAt":"c","projectId":"p","data":{}}';
        $this->call('POST', '/content/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FSC_SIGNATURE' => WebhookVerifier::sign('fsc_whsec_testvector', $body)], $body)
            ->assertOk()
            ->assertJsonPath('revalidated', ['content:site']);

        $this->newRequest();
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        self::assertStringContainsString('google-site-verification', Blade::render('<x-content::head />'));
        self::assertNull(app('cache')->get(SiteSettings::PAUSE_KEY));
    }

    public function testSwitchedOffInTheSiteMakesNoCall(): void
    {
        config(['content.site.enabled' => false]);
        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());

        self::assertSame('', Blade::render('<x-content::head />'));
        self::assertSame([], $this->calls('/site'));
    }
}
