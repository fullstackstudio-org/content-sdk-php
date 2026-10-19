<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Analytics;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\AnalyticsController;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/** `<x-content::analytics />`, the config cache and the first-party proxy routes. Never talks to Content or Umami (fake transports). */
final class AnalyticsTest extends LaravelTestCase
{
    private const WEBSITE = '2d585159-5e61-400a-8326-029816f769a9';

    private FakeTransport $content;

    private FakeTransport $umami;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.analytics.ip_header', 'cf-connecting-ip');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->content = new FakeTransport();
        $this->umami = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));
        $this->app->instance(AnalyticsController::TRANSPORT, $this->umami);
    }

    /** @return array<string, mixed> */
    private static function config(array $override = []): array
    {
        return ['analytics' => [...[
            'ready' => true,
            'websiteId' => self::WEBSITE,
            'scriptUrl' => 'https://analytics.fullstackstudio.nl/script.js',
            'recorderUrl' => 'https://analytics.fullstackstudio.nl/recorder.js',
            'hostUrl' => 'https://analytics.fullstackstudio.nl',
            'domains' => ['studio.nl', 'www.studio.nl'],
            'consent' => ['pageviewsWithoutConsent' => true, 'eventsWithoutConsent' => true, 'replayRequiresConsent' => true, 'heatmapRequiresConsent' => true],
            'attribution' => ['params' => ['utm_source', 'gclid']],
        ], ...$override]];
    }

    private function render(string $template = '<x-content::analytics />'): string
    {
        return Blade::render($template);
    }

    public function testFirstPartyIsTheDefaultAndLoadsEverythingFromThisSite(): void
    {
        $this->content->always(FakeTransport::json(self::config()));
        $html = $this->render();

        self::assertStringContainsString('<script id="fsc-analytics" defer src="/_fsc/a/script.js" data-website-id="' . self::WEBSITE . '" data-host-url="/_fsc/a" data-do-not-track="true" data-domains="studio.nl,www.studio.nl" data-fsc-recorder-src="/_fsc/a/recorder.js"></script>', $html);
        self::assertStringContainsString('<script defer src="/_fsc/a/fsc.js" data-tracker="fsc-analytics" data-consent-key="fsc-consent" data-consent-event="fsc:consent" data-params="utm_source,gclid"></script>', $html);
        self::assertStringNotContainsString('analytics.fullstackstudio.nl', $html, 'first-party: no third-party host in the page');
        self::assertStringNotContainsString('data-exclude-search', $html, 'utm_* must reach Umami');
        self::assertStringNotContainsString(' src="/_fsc/a/recorder.js"', $html, 'the recorder is never a tag: the helper injects it after consent');
        self::assertSame('/analytics', substr((string) parse_url($this->content->last()->url, PHP_URL_PATH), strlen('/api/v1')));
    }

    public function testDirectModePreconnectsAndLoadsFromTheAnalyticsHost(): void
    {
        config(['content.analytics.mode' => 'direct']);
        $this->content->always(FakeTransport::json(self::config()));
        $html = $this->render('<x-content::analytics nonce="n0nce" />');

        self::assertStringContainsString('<link rel="preconnect" href="https://analytics.fullstackstudio.nl" crossorigin>', $html);
        self::assertStringContainsString('<link rel="dns-prefetch" href="https://analytics.fullstackstudio.nl">', $html);
        self::assertStringContainsString('src="https://analytics.fullstackstudio.nl/script.js"', $html);
        self::assertStringContainsString('data-host-url="https://analytics.fullstackstudio.nl"', $html);
        self::assertStringContainsString('data-fsc-recorder-src="https://analytics.fullstackstudio.nl/recorder.js" nonce="n0nce"></script>', $html);
        self::assertStringContainsString('src="/_fsc/a/fsc.js"', $html);
    }

    public function testRendersNothingWhenTheModuleIsOffAndCachesThat(): void
    {
        $this->content->always(FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404));

        self::assertSame('', trim($this->render()));
        self::assertSame('', trim($this->render()));
        self::assertCount(1, $this->content->requests, 'a site without analytics makes one call per minute, not one per page');
    }

    public function testRendersNothingWhenNotProvisionedOrContentIsDown(): void
    {
        $this->content->queue(FakeTransport::json(self::config(['ready' => false, 'websiteId' => null])));
        self::assertSame('', trim($this->render()));

        cache()->flush();
        $this->content->queue(new TransportException('down'));
        self::assertSame('', trim($this->render()));
    }

    public function testTheConfigIsCachedBetweenPages(): void
    {
        $this->content->always(FakeTransport::json(self::config()));
        $this->render();
        $this->render();

        self::assertCount(1, $this->content->requests);
    }

    public function testEscapesValues(): void
    {
        $this->content->always(FakeTransport::json(self::config(['domains' => ['a.nl"><script>alert(1)</script>'], 'websiteId' => self::WEBSITE])));

        self::assertStringNotContainsString('<script>alert', $this->render());
        self::assertSame('', Analytics::tags(['ready' => true, 'websiteId' => 'x" onload="alert(1)']));
    }

    public function testTheHelperScriptIsServed(): void
    {
        $response = $this->get('/_fsc/a/fsc.js')->assertOk();

        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('fsc:consent', (string) $response->getContent());
        self::assertSame([], $response->headers->getCookies(), 'no session or cookies on the analytics routes');
    }

    public function testCollectIsProxiedWithTheVisitorIpAndNothingElse(): void
    {
        $this->content->always(FakeTransport::json(self::config()));
        $this->umami->queue(FakeTransport::json(['cache' => 'abc.def', 'sessionId' => 's']));
        $body = json_encode(['type' => 'event', 'payload' => ['website' => self::WEBSITE, 'url' => '/?utm_source=x']]);

        $response = $this->call('POST', '/_fsc/a/api/send', [], ['laravel_session' => 'secret-session'], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Test',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
            'HTTP_X_UMAMI_CACHE' => 'tok.en',
            'HTTP_AUTHORIZATION' => 'Bearer must-not-leak',
        ], $body);

        $response->assertOk();
        self::assertSame('{"cache":"abc.def","sessionId":"s"}', $response->getContent());
        $request = $this->umami->last();
        self::assertSame('POST', $request->method);
        self::assertSame('https://analytics.fullstackstudio.nl/api/send', $request->url);
        self::assertSame($body, $request->body);
        self::assertSame('203.0.113.7', $request->headers['X-FSC-Client-IP']);
        self::assertSame('203.0.113.7', $request->headers['True-Client-IP']);
        self::assertSame('203.0.113.7', $request->headers['X-Forwarded-For']);
        self::assertSame('Mozilla/5.0 Test', $request->headers['User-Agent']);
        self::assertSame('tok.en', $request->headers['x-umami-cache']);
        self::assertSame(['Content-Type', 'Accept', 'User-Agent', 'X-FSC-Client-IP', 'True-Client-IP', 'X-Forwarded-For', 'x-umami-cache'], array_keys($request->headers), 'no cookies, no authorization');
        self::assertSame([], $response->headers->getCookies());
    }

    public function testAnotherWebsiteIdIsNotProxied(): void
    {
        $this->content->always(FakeTransport::json(self::config()));
        $body = json_encode(['type' => 'event', 'payload' => ['website' => '00000000-0000-4000-8000-000000000000']]);

        $this->call('POST', '/_fsc/a/api/send', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
        $this->call('POST', '/_fsc/a/api/send', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);
        $this->get('/_fsc/a/api/websites/00000000-0000-4000-8000-000000000000/recorder')->assertOk()->assertExactJson(['enabled' => false]);
        self::assertCount(0, $this->umami->requests, 'no open relay');
    }

    public function testAnUmamiOutageNeverBreaksThePage(): void
    {
        $this->content->always(FakeTransport::json(self::config()));
        $this->umami->queue(new TransportException('timeout'));
        $body = json_encode(['type' => 'event', 'payload' => ['website' => self::WEBSITE]]);

        $this->call('POST', '/_fsc/a/api/send', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(202)->assertExactJson([]);
    }

    public function testTheRateLimitPerVisitorIp(): void
    {
        config(['content.analytics.rate_limit' => 2]);
        $this->content->always(FakeTransport::json(self::config()));
        $this->umami->always(FakeTransport::json([]));
        $body = json_encode(['type' => 'event', 'payload' => ['website' => self::WEBSITE]]);
        $send = fn (string $ip) => $this->call('POST', '/_fsc/a/api/send', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_CF_CONNECTING_IP' => $ip], $body);

        $send('198.51.100.1')->assertOk();
        $send('198.51.100.1')->assertOk();
        $send('198.51.100.1')->assertStatus(429);
        $send('198.51.100.2')->assertOk();
    }

    public function testReplayUploadsAndTheRecorderConfigAreProxied(): void
    {
        $this->content->always(FakeTransport::json(self::config()));
        $this->umami->queue(
            FakeTransport::json(['ok' => true]),
            FakeTransport::json(['enabled' => true, 'replayEnabled' => true, 'heatmapEnabled' => true, 'maskLevel' => 'moderate']),
        );
        $record = json_encode(['type' => 'record', 'payload' => ['website' => self::WEBSITE, 'events' => [['type' => 2]]]]);

        $this->call('POST', '/_fsc/a/api/record', [], [], [], ['CONTENT_TYPE' => 'application/json'], $record)->assertOk();
        self::assertSame('https://analytics.fullstackstudio.nl/api/record', $this->umami->last()->url);

        $this->get('/_fsc/a/api/websites/' . self::WEBSITE . '/recorder')->assertOk()->assertJsonPath('heatmapEnabled', true);
        $this->get('/_fsc/a/api/websites/' . self::WEBSITE . '/recorder')->assertOk();
        self::assertSame('https://analytics.fullstackstudio.nl/api/websites/' . self::WEBSITE . '/recorder', $this->umami->last()->url);
        self::assertCount(2, $this->umami->requests, 'the recorder config is cached for a minute');
    }

    public function testScriptsAreCachedAndServedStaleWhenUmamiIsDown(): void
    {
        $this->umami->queue(new Response(200, ['content-type' => 'application/javascript'], '!function(){/*umami*/}();'));

        $first = $this->get('/_fsc/a/script.js')->assertOk();
        self::assertSame('!function(){/*umami*/}();', $first->getContent());
        self::assertSame('max-age=3600, public', $first->headers->get('Cache-Control'));
        $this->get('/_fsc/a/script.js')->assertOk();
        self::assertCount(1, $this->umami->requests);

        cache()->forget('fsc-analytics:script:script.js');
        $this->umami->queue(new TransportException('down'));
        self::assertSame('!function(){/*umami*/}();', $this->get('/_fsc/a/script.js')->assertOk()->getContent());

        $this->umami->queue(new TransportException('down'));
        self::assertSame('/* analytics unavailable */', $this->get('/_fsc/a/recorder.js')->assertOk()->getContent());
    }

    public function testCspSources(): void
    {
        self::assertSame(['script-src' => ["'self'"], 'connect-src' => ["'self'"], 'worker-src' => ["'self'", 'blob:', 'data:']], Analytics::csp());
        self::assertSame(['script-src' => ['https://analytics.fullstackstudio.nl'], 'connect-src' => ['https://analytics.fullstackstudio.nl'], 'worker-src' => ['blob:', 'data:']], Analytics::csp('direct'));
        self::assertSame("default-src 'self'; script-src 'self' 'nonce-x'; connect-src 'self'; worker-src 'self' blob: data:", Analytics::mergeCsp("default-src 'self'; script-src 'self' 'nonce-x'"));
        self::assertSame(['script-src' => ["'self'"], 'connect-src' => ["'self'"], 'worker-src' => ["'self'", 'blob:', 'data:']], \FullStackStudio\Content\Laravel\Facades\Content::analyticsCsp());
    }
}
