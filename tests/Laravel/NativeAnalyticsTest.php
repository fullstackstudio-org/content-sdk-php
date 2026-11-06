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
        self::assertStringContainsString('<script id="fsa" defer src="/_fsc/a/fsa.js?v=' . ContentClient::VERSION . '" data-sitekey="' . self::SITEKEY . '" data-endpoint="/_fsc/a/e" data-consent-key="fsc-consent" data-consent-event="fsc:consent" data-params="utm_source,gclid" data-search-params="q,s,search,zoeken" data-status="404"></script>', $html);
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

    public function testTheSearchParametersComeFromTheHubConfigAndOnlyPlainNamesReachThePage(): void
    {
        $config = self::config('native');
        $config['analytics']['searchParams'] = ['zoek', 'term', 'zoek', 'bad name', '"><script>', 5, str_repeat('x', 33)];
        $this->content->always(FakeTransport::json($config));
        self::assertStringContainsString('data-search-params="zoek,term"', Blade::render('<x-content::analytics />'));

    }

    public function testNothingUsableInTheSearchParametersFallsBackToTheDefaults(): void
    {
        $config = self::config('native');
        $config['analytics']['searchParams'] = ['bad name'];
        $this->content->always(FakeTransport::json($config));
        self::assertStringContainsString('data-search-params="q,s,search,zoeken"', Blade::render('<x-content::analytics />'));
    }

    public function testThePidCookieDomainIsRenderedOnlyWhenConfiguredAndWellFormed(): void
    {
        $this->content->always(FakeTransport::json(self::config('native')));
        self::assertStringNotContainsString('data-pid-domain', Blade::render('<x-content::analytics />'), 'host-only by default');

        config(['content.analytics.pid_cookie_domain' => '.Example.nl']);
        self::assertStringContainsString('data-params="utm_source,gclid" data-search-params="q,s,search,zoeken" data-pid-domain=".example.nl"></script>', Blade::render('<x-content::analytics />'));

        foreach (['nl', '"><script>', 'example.nl; Secure', ' '] as $bad) {
            config(['content.analytics.pid_cookie_domain' => $bad]);
            self::assertStringNotContainsString('data-pid-domain', Blade::render('<x-content::analytics />'), $bad);
        }
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
        self::assertSame(file_get_contents(__DIR__ . '/../../resources/assets/fsa.min.js'), $response->getContent(), 'the minified build is served');
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

    public function testIntelligenceIsOffByDefaultAndNeverChangesTheBatchOrCallsTheHub(): void
    {
        config(['content.intelligence.exclude_ips' => '203.0.113.0/24', 'content.intelligence.recognition_cookie' => 'fsi_rec']);
        $this->content->always(FakeTransport::json(self::config('native')));
        $body = $this->batch(['c' => 1, 'pid' => 'abcdefabcdefabcdef']);

        $this->call('POST', '/_fsc/a/e', [], ['fsi_rec' => 'v1.cookie'], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://studio.nl', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Test'], $body)->assertStatus(202);

        self::assertSame($body, $this->collector->last()->body, 'byte for byte the batch of the browser');
        self::assertSame([], array_values(array_filter($this->content->requests, fn ($r) => str_contains($r->url, '/intelligence/'))));
    }

    public function testIntelligenceMarksOfficeTrafficAndIdentifiesOnlyAConsentingBrowserOnce(): void
    {
        config(['content.intelligence.enabled' => true, 'content.intelligence.exclude_ips' => '198.51.100.0/24, 192.0.2.7', 'content.intelligence.recognition_cookie' => 'fsi_rec']);
        $this->content->always(FakeTransport::json(self::config('native')));
        $identify = fn () => array_values(array_filter($this->content->requests, fn ($r) => str_contains($r->url, '/api/v1/intelligence/identify')));
        $send = fn (array $batch, string $ip, array $cookies = []) => $this->call('POST', '/_fsc/a/e', [], $cookies, [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://studio.nl', 'HTTP_CF_CONNECTING_IP' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0 Test'], $this->batch($batch))->assertStatus(202);
        $heat = ['t' => 'heatmap', 'u' => 'https://studio.nl/', 'sel' => 'a', 'x' => 0.5, 'y' => 0.5, 'hk' => 'click'];

        // Office IP: every event that keeps properties is marked, heatmap clicks are left alone, and nobody is identified.
        $send(['c' => 1, 'pid' => 'abcdefabcdefabcdef', 'e' => [['t' => 'pageview', 'u' => 'https://studio.nl/'], ['t' => 'event', 'n' => 'cta', 'u' => 'https://studio.nl/', 'p' => ['plan' => 'pro']], $heat]], '198.51.100.20', ['fsi_rec' => 'v1.cookie']);
        $forwarded = json_decode((string) $this->collector->last()->body, true);
        self::assertSame(['fsi_ex' => '1'], $forwarded['e'][0]['p']);
        self::assertSame(['plan' => 'pro', 'fsi_ex' => '1'], $forwarded['e'][1]['p']);
        self::assertArrayNotHasKey('p', $forwarded['e'][2]);
        self::assertSame([], $identify());

        // No consent: no pid leaves the site, nothing is identified, the recognition cookie is not even passed on.
        $send(['c' => 0, 'pid' => 'abcdefabcdefabcdef'], '203.0.113.9', ['fsi_rec' => 'v1.cookie']);
        self::assertSame([], $identify());
        self::assertStringNotContainsString('fsi_ex', (string) $this->collector->last()->body);

        // Consent: one call to the Hub after the response, with the browser id and the raw cookie; the next batch makes none.
        $send(['c' => 1, 'pid' => 'abcdefabcdefabcdef'], '203.0.113.9', ['fsi_rec' => 'v1.cookie']);
        $send(['c' => 1, 'pid' => 'abcdefabcdefabcdef'], '203.0.113.9', ['fsi_rec' => 'v1.cookie']);
        self::assertCount(1, $identify());
        self::assertSame('POST', $identify()[0]->method);
        self::assertSame(['pid' => 'abcdefabcdefabcdef', 'recognition' => 'v1.cookie'], json_decode((string) $identify()[0]->body, true));
        self::assertSame('Bearer fsc_srv_laraveltoken', $identify()[0]->headers['Authorization']);

        // A staff browser (admin marker cookie): marked, never identified, also with consent and outside the office.
        $send(['c' => 1, 'pid' => 'staffstaffstaffstaff', 'e' => [['t' => 'pageview', 'u' => 'https://studio.nl/']]], '203.0.113.50', ['fsi_staff' => '1', 'fsi_rec' => 'v1.cookie']);
        self::assertSame(['fsi_ex' => '1'], json_decode((string) $this->collector->last()->body, true)['e'][0]['p']);
        self::assertCount(1, $identify());
    }

    public function testScrollMapAndFrustrationSignalsAreCookielessAndPassWithoutConsent(): void
    {
        $this->content->always(FakeTransport::json(self::config('native')));
        $heat = ['t' => 'heatmap', 'u' => 'https://studio.nl/', 'sel' => 'a', 'x' => 0.5, 'y' => 0.5, 'px' => 0.4, 'py' => 0.1, 'dh' => 5000, 'hk' => 'dead'];
        $scroll = ['t' => 'scroll_max', 'u' => 'https://studio.nl/', 'sd' => 70];
        $rage = ['t' => 'frustration', 'n' => 'rage_click', 'u' => 'https://studio.nl/', 'sel' => '#cta'];
        $error = ['t' => 'frustration', 'n' => 'error_click', 'u' => 'https://studio.nl/', 'sel' => 'button.buy', 'p' => ['err' => 'TypeError', 'eh' => '9f3a01bc']];
        $back = ['t' => 'frustration', 'n' => 'quick_back', 'u' => 'https://studio.nl/'];

        $this->postBatch($this->batch(['c' => 0, 'pid' => 'abcdefabcdefabcdef', 'e' => [$heat, $scroll, $rage, $error, $back]]))->assertStatus(202);
        $forwarded = json_decode((string) $this->collector->last()->body, true);
        self::assertArrayNotHasKey('pid', $forwarded);
        self::assertSame(['scroll_max', 'frustration', 'frustration', 'frustration'], array_column($forwarded['e'], 't'), 'heatmap is stripped, the cookieless signals stay');
        foreach (array_slice($forwarded['e'], 1) as $event) {
            self::assertSame(['t', 'n', 'u'], array_keys($event), 'no sel without consent: a frustration signal is its name only');
        }
        self::assertStringNotContainsString('TypeError', (string) $this->collector->last()->body);
        self::assertStringNotContainsString('9f3a01bc', (string) $this->collector->last()->body);

        // With consent the heatmap click (with page position) passes unchanged too.
        $this->postBatch($this->batch(['c' => 1, 'pid' => 'abcdefabcdefabcdef', 'e' => [$heat, $scroll, $error]]))->assertStatus(202);
        $forwarded = json_decode((string) $this->collector->last()->body, true);
        self::assertSame(['heatmap', 'scroll_max', 'frustration'], array_column($forwarded['e'], 't'));
        self::assertSame(['button.buy', 'TypeError', '9f3a01bc'], [$forwarded['e'][2]['sel'], $forwarded['e'][2]['p']['err'], $forwarded['e'][2]['p']['eh']], 'with consent the element and error pass');
        self::assertSame([0.4, 0.1, 5000, 'dead'], [$forwarded['e'][0]['px'], $forwarded['e'][0]['py'], $forwarded['e'][0]['dh'], $forwarded['e'][0]['hk']]);
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

    public function testNothingIsForwardedWithoutAUsableVisitorIp(): void
    {
        $this->content->always(FakeTransport::json(self::config('native')));

        $this->postBatch($this->batch(), ['HTTP_CF_CONNECTING_IP' => 'not-an-ip', 'REMOTE_ADDR' => ''])->assertStatus(202);
        self::assertCount(0, $this->collector->requests, 'unsigned batches would be refused by the collector anyway');
    }

    public function testTheSigningKeyIsOnlyInTheInternalProxyConfig(): void
    {
        $this->content->always(FakeTransport::json(self::config('native')));
        $client = app(ContentClient::class);

        self::assertArrayNotHasKey('signingKey', $client->getAnalyticsConfig(['cache' => false])['native']);
        self::assertSame(self::SITEKEY, $client->getAnalyticsConfig(['cache' => false])['native']['sitekey']);
        self::assertSame(self::SIGNING_KEY, $client->getAnalyticsProxyConfig(['cache' => false])['native']['signingKey']);
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

    // --- heatmap overlay (phase 4) ----------------------------------------------------------------------------------------------

    private const OVERLAY_TOKEN = 'v1.eyJwIjoiYWJjIiwidSI6Ii9wcmlqemVuIn0.AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_AbCdE';

    private function postOverlay(string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/_fsc/a/overlay', [], ['laravel_session' => 'secret-session'], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_AUTHORIZATION' => 'Bearer must-not-leak',
        ], $body);
    }

    public function testOverlayScriptIsServedSameOrigin(): void
    {
        $response = $this->get('/_fsc/a/overlay.js');

        $response->assertOk();
        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('fsa_overlay=', (string) $response->getContent());
        self::assertDoesNotMatchRegularExpression('/\.innerHTML\s*=|insertAdjacentHTML|\beval\(|new Function/', (string) $response->getContent());
    }

    public function testOverlayForwardsTheTokenAsAHeaderWithTheServerTokenAndReturnsTheAggregates(): void
    {
        $result = ['path' => '/prijzen', 'viewport' => 'desktop', 'grid' => 10, 'elements' => [['selector' => 'button#offerte', 'clicks' => 4, 'cells' => [[1, 5, 2]]]]];
        $this->content->queue(FakeTransport::json(['result' => $result]));

        $response = $this->postOverlay((string) json_encode(['t' => self::OVERLAY_TOKEN]));

        $response->assertOk()->assertJson(['result' => $result]);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertCount(1, $this->content->requests);
        $hub = $this->content->requests[0];
        self::assertSame('GET', $hub->method);
        self::assertSame('https://content.test/api/v1/analytics/overlay', $hub->url, 'the token is never in the URL');
        $headers = array_change_key_case($hub->headers, CASE_LOWER);
        self::assertSame(self::OVERLAY_TOKEN, $headers['x-fsa-overlay']);
        self::assertSame('Bearer fsc_srv_laraveltoken', $headers['authorization'], 'the site server token, never the browser\'s Authorization header');
        self::assertArrayNotHasKey('cookie', $headers);

        // Not cached: a second request asks the Hub again.
        $this->content->queue(FakeTransport::json(['result' => $result]));
        $this->postOverlay((string) json_encode(['t' => self::OVERLAY_TOKEN]))->assertOk();
        self::assertCount(2, $this->content->requests);
    }

    public function testOverlayRefusesMalformedInputWithoutAskingTheHub(): void
    {
        foreach (['', 'nope', '{"t":"v1.short.sig"}', '{"t":123}', '[]', (string) json_encode(['t' => self::OVERLAY_TOKEN . "\n"]), str_repeat('a', 3000)] as $body) {
            $this->postOverlay($body)->assertStatus(400);
        }

        // A declared body larger than a token is refused before it is read.
        $this->call('POST', '/_fsc/a/overlay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '999999', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], '{"t":"x"}')->assertStatus(400);

        self::assertCount(0, $this->content->requests);
    }

    public function testOverlayMapsAHubRefusalTo403AndAnOutageTo502(): void
    {
        $this->content->queue(FakeTransport::json(['error' => ['code' => 'forbidden', 'message' => 'Verlopen']], 403));
        $this->postOverlay((string) json_encode(['t' => self::OVERLAY_TOKEN]))->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        $this->content->queue(new TransportException('down'), new TransportException('down'), new TransportException('down'));
        $this->postOverlay((string) json_encode(['t' => self::OVERLAY_TOKEN]))->assertStatus(502)->assertExactJson(['error' => 'unavailable']);
    }

    public function testOverlayIsRateLimitedPerVisitorIp(): void
    {
        $this->content->always(FakeTransport::json(['result' => []]));

        for ($i = 0; $i < 30; $i++) {
            $this->postOverlay((string) json_encode(['t' => self::OVERLAY_TOKEN]))->assertOk();
        }

        $this->postOverlay((string) json_encode(['t' => self::OVERLAY_TOKEN]))->assertStatus(429);
    }
}
