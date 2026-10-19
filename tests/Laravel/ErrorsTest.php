<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Errors\EnvelopeSender;
use FullStackStudio\Content\Laravel\Errors\Errors;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/** The Laravel side of the `errors` module: config from Content, backend reporting, the Blade component and the browser tunnel. */
final class ErrorsTest extends LaravelTestCase
{
    private const BACKEND = 'https://0123456789abcdef0123456789abcdef@errors.int0.net/12';

    private const BROWSER = 'https://0123456789abcdef0123456789abcdef@errors.fullstackstudio.nl/12';

    private FakeTransport $http;

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    private array $sent = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.url', 'https://www.example.nl');
        $app['config']->set('content.errors.allow_local', true);
        $app['config']->set('content.errors.release', 'abc123');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        $this->app->instance(EnvelopeSender::class, new EnvelopeSender(function (string $url, array $headers, string $body): int {
            $this->sent[] = compact('url', 'headers', 'body');

            return 200;
        }));
        $this->http->always(FakeTransport::json(['errors' => [
            'status' => 'ready',
            'backend' => ['dsn' => self::BACKEND],
            'browser' => ['dsn' => self::BROWSER],
            'environment' => 'production',
        ]]));
    }

    private function sentEvents(): array
    {
        return array_map(fn (array $request): array => json_decode(explode("\n", $request['body'])[2], true), $this->sent);
    }

    public function testFetchesTheConfigFromContentOnceAndCachesIt(): void
    {
        $errors = $this->app->make(Errors::class);

        self::assertSame(self::BACKEND, $errors->config()?->backendDsn);
        self::assertSame(self::BROWSER, $this->app->make(Errors::class)->config()?->browserDsn);
        self::assertCount(1, $this->http->requests);
        self::assertStringEndsWith('/api/v1/project/errors', $this->http->requests[0]->url);
    }

    public function testReportsBackendExceptionsScrubbedThroughTheHandler(): void
    {
        $make = fn (): RuntimeException => new RuntimeException('Customer jan@example.nl not found');
        $this->app->make(ExceptionHandler::class)->report($make());

        self::assertCount(1, $this->sent);
        self::assertSame('https://errors.int0.net/api/12/envelope/', $this->sent[0]['url']);
        $event = $this->sentEvents()[0];
        self::assertSame('Customer [email] not found', $event['exception']['values'][0]['value']);
        self::assertSame('abc123', $event['release']);
        self::assertStringNotContainsString('jan@example.nl', $this->sent[0]['body']);

        // The same exception (class + location) is reported once.
        $this->app->make(ExceptionHandler::class)->report($make());
        self::assertCount(1, $this->sent);
    }

    public function testNothingIsSentInTestingWithoutTheOptInOrWhenTheModuleIsOff(): void
    {
        $this->app['config']->set('content.errors.allow_local', false);
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('x'));
        self::assertSame([], $this->sent);
        self::assertSame('', Blade::render('<x-content::errors />'));

        $this->app['config']->set('content.errors.allow_local', true);
        $this->app->forgetInstance(Errors::class);
        $this->http->always(FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'off']], 404));
        $this->app['cache']->store()->flush();
        self::assertNull($this->app->make(Errors::class)->config());
    }

    public function testComponentRendersConfigAndSameOriginScript(): void
    {
        $html = Blade::render('<x-content::errors nonce="n1" />');

        self::assertStringContainsString('<script type="application/json" id="fsc-errors" nonce="n1">', $html);
        self::assertStringContainsString('"tunnel":"/_fsc/e/envelope"', $html);
        self::assertStringContainsString('"dsn":"' . self::BROWSER . '"', $html);
        self::assertStringContainsString('<script src="/_fsc/e/reporter.js?v=' . ContentClient::VERSION . '" nonce="n1" defer', $html);
        self::assertStringNotContainsString('errors.int0.net', $html);

        $this->get('/_fsc/e/reporter.js?v=' . ContentClient::VERSION)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    }

    private function envelope(array $event, string $dsn = self::BROWSER): string
    {
        return json_encode(['dsn' => $dsn]) . "\n" . json_encode(['type' => 'event']) . "\n" . json_encode($event) . "\n";
    }

    private function browserEvent(string $message = 'Mail to jan@example.nl failed'): array
    {
        return [
            'event_id' => str_repeat('e', 32),
            'transaction' => 'GET /contact',
            'user' => ['ip_address' => '1.2.3.4'],
            'exception' => ['values' => [[
                'type' => 'TypeError',
                'value' => $message,
                'stacktrace' => ['frames' => [['filename' => 'https://www.example.nl/build/app.js?v=1', 'lineno' => 3, 'colno' => 1]]],
            ]]],
        ];
    }

    private function postTunnel(string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/_fsc/e/envelope', [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'text/plain;charset=UTF-8', ...$headers]), $body);
    }

    public function testTunnelRebuildsTheEventAndForwardsWithTheSitesOwnDsnAndOrigin(): void
    {
        $response = $this->postTunnel($this->envelope($this->browserEvent()), ['Origin' => 'https://www.example.nl']);

        $response->assertStatus(202);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());

        self::assertCount(1, $this->sent);
        self::assertSame('https://errors.int0.net/api/12/envelope/', $this->sent[0]['url']);
        self::assertSame('https://www.example.nl', $this->sent[0]['headers']['Origin']);
        $event = $this->sentEvents()[0];
        self::assertSame('Mail to [email] failed', $event['exception']['values'][0]['value']);
        self::assertSame('https://www.example.nl/build/app.js', $event['exception']['values'][0]['stacktrace']['frames'][0]['filename']);
        self::assertArrayNotHasKey('user', $event);
        self::assertSame('abc123', $event['release']);
    }

    public function testTunnelIsNoOpenRelay(): void
    {
        $other = 'https://ffffffffffffffffffffffffffffffff@errors.fullstackstudio.nl/99';

        $this->postTunnel($this->envelope($this->browserEvent(), $other))->assertStatus(400);
        $this->postTunnel($this->envelope($this->browserEvent()), ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->postTunnel(str_repeat('x', 70000))->assertStatus(413);
        $this->postTunnel('not an envelope')->assertStatus(400);

        // Only third-party frames: accepted but nothing forwarded.
        $event = $this->browserEvent();
        $event['exception']['values'][0]['stacktrace']['frames'] = [['filename' => 'https://cdn.other.com/x.js']];
        $this->postTunnel($this->envelope($event))->assertStatus(202);

        self::assertSame([], $this->sent);
    }

    public function testTunnelRateLimitsPerClient(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postTunnel($this->envelope($this->browserEvent("err {$i}")))->assertStatus(202);
        }

        $this->postTunnel($this->envelope($this->browserEvent()))->assertStatus(429)->assertHeader('Retry-After', '60');
    }

    public function testRoutesUseNoSessionMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('content.errors.tunnel');

        self::assertNotNull($route);
        self::assertSame([], $route->gatherMiddleware());
        self::assertSame('_fsc/e/envelope', $route->uri());
    }
}
