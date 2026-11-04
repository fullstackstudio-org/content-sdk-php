<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Analytics;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\AnalyticsController;
use FullStackStudio\Content\Laravel\NativeAnalyticsController;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/**
 * The native engine in the SDK: the engine switch of `<x-content::analytics />` and the signed first-party proxy `POST /_fsc/a/e`. Never
 * talks to Content, Umami or a collector (fake transports).
 */
final class NativeAnalyticsTest extends LaravelTestCase
{
    private const WEBSITE = '2d585159-5e61-400a-8326-029816f769a9';

    /** Shared vector with collector/internal/proxyauth/vector_test.go and the Hub's NativeEngineTest. */
    private const SITEKEY = 'fsa_TestSite0123456789abcd';

    private const SIGNING_KEY = 'd6990060e457f64dd81a25868ec7f7f99a86405c47ef8c0198ecfe04c10ba810';

    private FakeTransport $content;

    private FakeTransport $collector;

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
        $this->collector = new FakeTransport();
        $this->umami = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));
        $this->app->instance(NativeAnalyticsController::TRANSPORT, $this->collector);
        $this->app->instance(AnalyticsController::TRANSPORT, $this->umami);
    }

    /** @return array<string, mixed> */
    private static function config(string $engine, array $native = []): array
    {
        return ['analytics' => [
            'ready' => true,
            'engine' => $engine,
            'websiteId' => $engine === 'native' ? null : self::WEBSITE,
            'domains' => ['studio.nl'],
            'native' => [...['ready' => true, 'sitekey' => self::SITEKEY, 'signingKey' => self::SIGNING_KEY], ...$native],
            'attribution' => ['params' => ['utm_source', 'gclid']],
        ]];
    }

    private function batch(array $override = []): string
    {
        return (string) json_encode([...[
            'k' => self::SITEKEY, 'c' => 0, 'vw' => 1280, 'vh' => 800, 'lang' => 'nl-NL',
            'e' => [['t' => 'pageview', 'ts' => 1_800_000_000_000, 'u' => 'https://studio.nl/', 'r' => '', 'ti' => 'Home', 'p' => new \stdClass()]],
        ], ...$override], JSON_UNESCAPED_SLASHES);
    }

    private function postBatch(string $body, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/_fsc/a/e', [], ['laravel_session' => 'secret-session'], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Test',
            'HTTP_ACCEPT_LANGUAGE' => 'nl-NL,nl;q=0.9',
            'HTTP_ORIGIN' => 'https://studio.nl',
            'HTTP_REFERER' => 'https://studio.nl/prijzen',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_AUTHORIZATION' => 'Bearer must-not-leak',
            ...$server,
        ], $body);
    }

    public function testSignHeadersMatchTheCollectorContract(): void
    {
        $body = '{"k":"fsa_TestSite0123456789abcd","e":[{"t":"pageview","u":"https://example.nl/"}]}';

        self::assertSame([
            'X-Fsc-Client-Ip' => '203.0.113.9',
            'X-Fsc-Ts' => '1800000000',
            'X-Fsc-Sig' => '15f42c3418926c910ebdfaed1ebfdfc48878f383229905da73c7a3def72cae1a',
        ], Analytics::signHeaders(self::SIGNING_KEY, '203.0.113.9', $body, 1_800_000_000));
    }

    public function testEngineBothRendersUmamiAndTheNativeTrackerAndOneHelper(): void
    {
        $this->content->always(FakeTransport::json(self::config('both')));
        $html = Blade::render('<x-content::analytics status="404" />');

        self::assertStringContainsString('<script id="fsc-analytics" defer src="/_fsc/a/script.js" data-website-id="' . self::WEBSITE . '"', $html);
        self::assertStringContainsString('<script id="fsa" defer src="/_fsc/a/fsa.js?v=' . ContentClient::VERSION . '" data-sitekey="' . self::SITEKEY . '" data-endpoint="/_fsc/a/e" data-consent-key="fsc-consent" data-consent-event="fsc:consent" data-params="utm_source,gclid" data-status="404"></script>', $html);
        self::assertSame(1, substr_count($html, '/_fsc/a/fsc.js'));
        self::assertStringNotContainsString(self::SIGNING_KEY, $html, 'the signing key never reaches the page');
    }

    public function testEngineNativeRendersOnlyTheNativeTrackerAlsoInDirectMode(): void
    {
        config(['content.analytics.mode' => 'direct']);
        $this->content->always(FakeTransport::json(self::config('native')));
        $html = Blade::render('<x-content::analytics />');

        self::assertStringContainsString('<script id="fsa" defer src="/_fsc/a/fsa.js?v=', $html);
        self::assertStringNotContainsString('data-website-id', $html);
        self::assertStringNotContainsString('analytics.fullstackstudio.nl', $html);
        self::assertStringNotContainsString('data-status', $html);
        self::assertStringContainsString('<script defer src="/_fsc/a/fsc.js"', $html);
    }

    public function testUmamiOnlyForAnOlderHubAndNoNativeTrackerWithoutASigningKey(): void
    {
        $old = self::config('umami');
        unset($old['analytics']['engine'], $old['analytics']['native']);
        $this->content->always(FakeTransport::json($old));
        $html = Blade::render('<x-content::analytics />');
        self::assertStringContainsString('data-website-id="' . self::WEBSITE . '"', $html);
        self::assertStringNotContainsString('fsa.js', $html);

        // A Hub that sends no signing key (publishable token, no master secret): signing is impossible, so the native tag is left out.
        cache()->flush();
        $this->content->always(FakeTransport::json(self::config('both', ['signingKey' => null])));
        $html = Blade::render('<x-content::analytics />');
        self::assertStringContainsString('data-website-id', $html);
        self::assertStringNotContainsString('fsa.js', $html);

        cache()->flush();
        $this->content->always(FakeTransport::json(self::config('native', ['ready' => false])));
        self::assertSame('', trim(Blade::render('<x-content::analytics />')));
    }

    public function testTheTrackerIsServedWithoutCookies(): void
    {
        $response = $this->get('/_fsc/a/fsa.js')->assertOk();

        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('fsa-id', (string) $response->getContent());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testABatchIsForwardedToTheCollectorWithASignedVisitorIpAndOnlyTheNeededHeaders(): void
    {
        $this->content->always(FakeTransport::json(self::config('both')));
        $this->collector->always(FakeTransport::json([], 202));
        $body = $this->batch();

        $response = $this->postBatch($body);

        $response->assertStatus(202)->assertExactJson([]);
        self::assertSame([], $response->headers->getCookies());
        $request = $this->collector->last();
        self::assertSame('POST', $request->method);
        self::assertSame('http://collector.analytics.svc.cluster.local/e', $request->url);
        self::assertSame($body, $request->body);
        self::assertSame(['Content-Type', 'User-Agent', 'Accept-Language', 'Origin', 'Referer', 'X-Fsc-Client-Ip', 'X-Fsc-Ts', 'X-Fsc-Sig'], array_keys($request->headers), 'no cookies, no authorization');
        self::assertSame('Mozilla/5.0 (Macintosh) Test', $request->headers['User-Agent']);
        self::assertSame('https://studio.nl', $request->headers['Origin']);
        self::assertSame('203.0.113.9', $request->headers['X-Fsc-Client-Ip']);
        self::assertLessThanOrEqual(5, abs(time() - (int) $request->headers['X-Fsc-Ts']));
        $expected = hash_hmac('sha256', $request->headers['X-Fsc-Ts'] . "\n203.0.113.9\n" . hash('sha256', $body), self::SIGNING_KEY);
        self::assertSame($expected, $request->headers['X-Fsc-Sig']);
        self::assertLessThanOrEqual(5000, $request->timeoutMs);
        self::assertCount(0, $this->umami->requests);
    }

    public function testWithoutConsentThePersistentIdAndHeatmapClicksNeverLeaveTheSite(): void
    {
        $this->content->always(FakeTransport::json(self::config('native')));
        $heat = ['t' => 'heatmap', 'u' => 'https://studio.nl/', 'sel' => 'a', 'x' => 0.5, 'y' => 0.5, 'hk' => 'click'];
        $pv = ['t' => 'pageview', 'u' => 'https://studio.nl/', 'p' => new \stdClass()];

        $this->postBatch($this->batch(['c' => 0, 'pid' => 'abcdefabcdefabcdef', 'e' => [$pv, $heat]]))->assertStatus(202);
        $forwarded = json_decode((string) $this->collector->last()->body, true);
        self::assertArrayNotHasKey('pid', $forwarded);
        self::assertSame(0, $forwarded['c']);
        self::assertSame(['pageview'], array_column($forwarded['e'], 't'));
        self::assertStringContainsString('"p":{}', (string) $this->collector->last()->body, 'empty props stay an object for the collector');
        // The signature covers the body as forwarded (after removing the fields).
        $request = $this->collector->last();
        self::assertSame(hash_hmac('sha256', $request->headers['X-Fsc-Ts'] . "\n203.0.113.9\n" . hash('sha256', (string) $request->body), self::SIGNING_KEY), $request->headers['X-Fsc-Sig']);

        // Only heatmap clicks without consent: nothing to forward at all.
        $count = count($this->collector->requests);
        $this->postBatch($this->batch(['c' => 0, 'e' => [$heat]]))->assertStatus(202);
        self::assertCount($count, $this->collector->requests);

        // With consent both pass unchanged.
        $this->postBatch($this->batch(['c' => 1, 'pid' => 'abcdefabcdefabcdef', 'e' => [$pv, $heat]]))->assertStatus(202);
        $forwarded = json_decode((string) $this->collector->last()->body, true);
        self::assertSame('abcdefabcdefabcdef', $forwarded['pid']);
        self::assertSame(['pageview', 'heatmap'], array_column($forwarded['e'], 't'));
    }

    public function testNoOpenRelayAndBadRequestsAreRefusedWithoutForwarding(): void
    {
        $this->content->always(FakeTransport::json(self::config('native')));

        $this->postBatch($this->batch(['k' => 'fsa_someoneElse000000000000']))->assertStatus(400);
        $this->postBatch('not json')->assertStatus(400);
        $this->postBatch('')->assertStatus(400);
        $this->postBatch($this->batch(['e' => []]))->assertStatus(400);
        $this->postBatch($this->batch(['e' => array_fill(0, 51, ['t' => 'event', 'n' => 'x', 'u' => 'https://studio.nl/'])]))->assertStatus(400);
        $this->postBatch(str_repeat('x', 65 * 1024))->assertStatus(413);
        self::assertCount(0, $this->collector->requests);
    }

    public function testEngineUmamiOrNoSigningKeyDropsQuietlyAndACollectorOutageNeverBreaksThePage(): void
    {
        $this->content->always(FakeTransport::json(self::config('umami')));
        $this->postBatch($this->batch())->assertStatus(202)->assertExactJson([]);
        self::assertCount(0, $this->collector->requests);

        cache()->flush();
        $this->content->always(FakeTransport::json(self::config('native', ['signingKey' => null])));
        $this->postBatch($this->batch())->assertStatus(202);
        self::assertCount(0, $this->collector->requests);

        cache()->flush();
        $this->content->always(FakeTransport::json(self::config('native')));
        $this->collector->queue(new TransportException('connection refused'));
        $this->postBatch($this->batch())->assertStatus(202)->assertExactJson([]);
        self::assertCount(1, $this->collector->requests);
    }

    public function testTheRateLimitPerVisitorIp(): void
    {
        config(['content.analytics.rate_limit' => 2]);
        $this->content->always(FakeTransport::json(self::config('native')));

        $this->postBatch($this->batch(), ['HTTP_CF_CONNECTING_IP' => '198.51.100.1'])->assertStatus(202);
        $this->postBatch($this->batch(), ['HTTP_CF_CONNECTING_IP' => '198.51.100.1'])->assertStatus(202);
        $this->postBatch($this->batch(), ['HTTP_CF_CONNECTING_IP' => '198.51.100.1'])->assertStatus(429);
        $this->postBatch($this->batch(), ['HTTP_CF_CONNECTING_IP' => '198.51.100.2'])->assertStatus(202);
    }

    public function testCspAddsSelfInDirectModeWhenTheNativeTrackerRuns(): void
    {
        self::assertSame(["'self'", 'https://analytics.fullstackstudio.nl'], Analytics::csp(Analytics::MODE_DIRECT, Analytics::DEFAULT_HOST, true)['connect-src']);
        self::assertSame(['https://analytics.fullstackstudio.nl'], Analytics::csp(Analytics::MODE_DIRECT)['connect-src']);
    }
}
