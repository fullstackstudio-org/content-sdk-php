<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Visitor\VerifyController;
use FullStackStudio\Content\Laravel\Visitor\VisitorRoutes;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The verification endpoint of the visitor pages (plan sdk-out-of-the-box, section 8, V6, Task 20): the Hub probes `GET /_fsc/x/verify?n=`,
 * the site calls back with its server token. Against a fake transport: no network call and no real Hub.
 */
final class VisitorVerifyTest extends LaravelTestCase
{
    /** A nonce as the Hub makes it: 32 random bytes, base64url without padding. */
    private const NONCE = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-AbCde';

    private FakeTransport $http;

    /** @var array<string, mixed> config of the next application */
    private array $config = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakes();
    }

    private function fakes(): void
    {
        $this->http = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /** A fresh application with these config values. */
    private function site(array $config): void
    {
        $this->config = $config;
        $this->refreshApplication();
        $this->fakes();
    }

    protected function defineRoutes($router): void
    {
        // A site with a root catch-all that would answer any other path with a 200.
        $router->middleware('web')->get('/{path}', static fn (): string => 'site')->where('path', '.*');
    }

    private function confirmed(): HubResponse
    {
        return FakeTransport::json(['confirmed' => true]);
    }

    private function notFound(): HubResponse
    {
        return FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'Niet gevonden.']], 404);
    }

    public function testAValidNonceIsConfirmedWithTheServerTokenAndTheProxyAndSdkVersion(): void
    {
        $this->http->queue($this->confirmed());

        $response = $this->get('/_fsc/x/verify?n=' . self::NONCE);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertCount(1, $this->http->requests);

        $sent = $this->http->last();
        self::assertSame('POST', $sent->method);
        self::assertSame('https://content.test/api/v1/site-proxy/confirm', $sent->url);
        self::assertSame('Bearer fsc_srv_laraveltoken', $sent->headers['Authorization']);
        self::assertSame(['nonce' => self::NONCE, 'proxy' => VisitorRoutes::VERSION, 'sdk' => ContentClient::VERSION], json_decode((string) $sent->body, true));
        self::assertSame(5000, $sent->timeoutMs);
    }

    public function testTheAnswerIsNeverCachedAndCarriesNoCookie(): void
    {
        $this->http->queue($this->confirmed());

        $response = $this->get('/_fsc/x/verify?n=' . self::NONCE);

        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-store', $response->headers->get('CDN-Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    /** @return array<string, array{0: HubResponse|TransportException}> */
    public static function hubOutcomes(): array
    {
        return [
            'the Hub confirms' => [FakeTransport::json(['confirmed' => true])],
            'an unknown or expired nonce (404)' => [FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404)],
            'a token the Hub refuses (401)' => [FakeTransport::json(['error' => ['code' => 'unauthorized', 'message' => 'x']], 401)],
            'the Hub fails (500)' => [FakeTransport::json(['error' => ['code' => 'server_error', 'message' => 'x']], 500)],
            'something that is not JSON' => [new HubResponse(200, ['content-type' => 'text/html'], '<html>Cloudflare</html>')],
            'a timeout' => [new TransportException('timed out', true)],
            'the Hub is unreachable' => [new TransportException('connection refused')],
        ];
    }

    #[DataProvider('hubOutcomes')]
    public function testTheProbeAlwaysGetsTheSameEmpty204(HubResponse|TransportException $outcome): void
    {
        $this->http->queue($outcome);

        $response = $this->get('/_fsc/x/verify?n=' . self::NONCE);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertCount(1, $this->http->requests);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedNonces(): array
    {
        return [
            'no nonce' => [''],
            'empty' => ['?n='],
            'too short' => ['?n=' . 'A'],
            'one character short' => ['?n=' . 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-AbCd'],
            'one character too long' => ['?n=' . self::NONCE . 'x'],
            'padding' => ['?n=' . 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-AbC='],
            'plus and slash' => ['?n=' . 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789+/AbCde'],
            'an array' => ['?n[]=' . self::NONCE],
            'another parameter' => ['?nonce=' . self::NONCE],
        ];
    }

    #[DataProvider('malformedNonces')]
    public function testAMalformedNonceIsNeverSentToTheHubAndStillGets204(string $query): void
    {
        $response = $this->get('/_fsc/x/verify' . $query);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function testTenConfirmationsPerIpPerMinuteThenA429WithoutAHubCall(): void
    {
        $this->http->queue(...array_fill(0, 12, $this->confirmed()));
        $ip = ['HTTP_CF_CONNECTING_IP' => '198.51.100.7'];

        for ($i = 0; $i < VerifyController::PER_MINUTE; $i++) {
            self::assertSame(204, $this->call('GET', '/_fsc/x/verify', ['n' => self::NONCE], [], [], $ip)->getStatusCode(), 'call ' . $i);
        }

        $limited = $this->call('GET', '/_fsc/x/verify', ['n' => self::NONCE], [], [], $ip);
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('60', $limited->headers->get('Retry-After'));
        self::assertCount(VerifyController::PER_MINUTE, $this->http->requests);

        // A malformed nonce costs nothing and is not counted, also not by an address at its limit.
        self::assertSame(204, $this->call('GET', '/_fsc/x/verify', ['n' => 'short'], [], [], $ip)->getStatusCode());

        // Another visitor address has a limit of its own.
        self::assertSame(204, $this->call('GET', '/_fsc/x/verify', ['n' => self::NONCE], [], [], ['HTTP_CF_CONNECTING_IP' => '198.51.100.8'])->getStatusCode());
        self::assertCount(VerifyController::PER_MINUTE + 1, $this->http->requests);
    }

    public function testItAnswers404WithTheVisitorPagesOffAndNeverCallsTheHub(): void
    {
        $this->site(['content.auto.visitor_pages' => false]);

        $response = $this->get('/_fsc/x/verify?n=' . self::NONCE);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame([], $this->http->requests);
        // The visitor routes are gone, the verification route stays (so the Hub gets a clean 404 and unverifies the site).
        self::assertFalse($this->app['router']->getRoutes()->hasNamedRoute('content.visitor.appointments.1'));
        self::assertTrue($this->app['router']->getRoutes()->hasNamedRoute(VerifyController::NAME));
    }

    public function testItAnswers404WhenTheWholeAutomaticLayerIsOff(): void
    {
        $this->site(['content.auto.enabled' => false]);

        self::assertSame(404, $this->get('/_fsc/x/verify?n=' . self::NONCE)->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function testOnlyGetIsAnswered(): void
    {
        self::assertSame(204, $this->head('/_fsc/x/verify')->getStatusCode());
        // A POST on a path that only has a GET route is a 405, as without the package.
        self::assertSame(405, $this->post('/_fsc/x/verify?n=' . self::NONCE)->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function testTheRouteBeatsTheSitesCatchAllAndHasNoMiddlewareAndANameOfItsOwn(): void
    {
        $route = $this->app['router']->getRoutes()->match(\Illuminate\Http\Request::create('/_fsc/x/verify', 'GET'));

        self::assertSame(VerifyController::NAME, $route->getName());
        self::assertSame(VerifyController::class, $route->getActionName());
        self::assertSame([], $route->gatherMiddleware());
        // Not part of the proxied table: never forwarded to the Hub.
        self::assertFalse(VisitorRoutes::matches('/_fsc/x/verify'));
    }

    public function testTheClientMethodReturnsTrueOnlyForAConfirmationAndTakesATimeoutOption(): void
    {
        $this->http->queue(FakeTransport::json(['confirmed' => true]), FakeTransport::json(['ok' => true]), FakeTransport::json(['confirmed' => true]));
        $client = new ContentClient(['token' => 'fsc_srv_x', 'baseUrl' => 'https://content.test', 'transport' => $this->http]);

        self::assertTrue($client->confirmSiteProxy(self::NONCE, 1, '0.30.0'));
        self::assertSame(5000, $this->http->last()->timeoutMs);
        self::assertFalse($client->confirmSiteProxy(self::NONCE, 1, '0.30.0'));
        self::assertTrue($client->confirmSiteProxy(self::NONCE, 1, '0.30.0', ['timeoutMs' => 1234]));
        self::assertSame(1234, $this->http->last()->timeoutMs);
    }

    public function testTheClientMethodThrowsForAnyRefusal(): void
    {
        $client = new ContentClient(['token' => 'fsc_srv_x', 'baseUrl' => 'https://content.test', 'transport' => $this->http->queue($this->notFound())]);

        $this->expectException(\FullStackStudio\Content\ContentException::class);
        $client->confirmSiteProxy(self::NONCE, 1, '0.30.0');
    }
}
