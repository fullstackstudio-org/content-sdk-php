<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use Closure;
use CURLFile;
use FullStackStudio\Content\Errors\ErrorsConfig;
use FullStackStudio\Content\Errors\EventBuilder;
use FullStackStudio\Content\Laravel\Errors\Errors;
use FullStackStudio\Content\Laravel\Visitor\Upstream;
use FullStackStudio\Content\Laravel\Visitor\UpstreamRequest;
use FullStackStudio\Content\Laravel\Visitor\UpstreamResponse;
use FullStackStudio\Content\Laravel\Visitor\VisitorProxyController;
use FullStackStudio\Content\Laravel\Visitor\VisitorProxyException;
use FullStackStudio\Content\Laravel\Visitor\VisitorRoutes;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor page proxy (plan sdk-out-of-the-box, section 8, Task 19) against a fake upstream: the table, both header allow-lists, the
 * Location rule, the caps, multipart, failures, precedence over a site catch-all and `route:cache`. Never a network call.
 */
final class VisitorProxyTest extends LaravelTestCase
{
    private VisitorProxyTestUpstream $hub;

    /** @var (Closure(Router): void)|null the site's routes of the next application */
    private ?Closure $siteRoutes = null;

    /** @var array<string, mixed> config of the next application */
    private array $config = [];

    /** @var list<string> messages of every reported exception */
    private array $reported = [];

    private ?string $routeCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakes();
    }

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
        $app['config']->set('content.base_url', 'https://content.test');

        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineRoutes($router): void
    {
        // Every site of the house rules has a root catch-all, registered by its route file after the package booted.
        ($this->siteRoutes ?? static function (Router $router): void {
            $router->middleware('web')->get('/{path}', VisitorProxyTestSite::class)->where('path', '.*');
        })($router);
    }

    /** A fresh application with these site routes and config values. */
    private function site(?Closure $routes = null, array $config = []): void
    {
        $this->siteRoutes = $routes;
        $this->config = $config;
        $this->refreshApplication();
        $this->fakes();
    }

    private function fakes(): void
    {
        $this->hub = new VisitorProxyTestUpstream();
        $this->app->instance(Upstream::class, $this->hub);
        $this->reported = [];
        $reported = &$this->reported;
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (VisitorProxyException $error) use (&$reported): bool {
                $reported[] = $error->getMessage();

                return false;
            });
        }
    }

    private function html(string $body = '<p>hub</p>', int $status = 200, array $headers = []): UpstreamResponse
    {
        return new UpstreamResponse($status, ['content-type' => ['text/html; charset=UTF-8'], ...$headers], $body);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function groupRequests(): array
    {
        return [
            'appointments GET' => ['GET', '/a/l/AbCdEf123456'],
            'appointments POST' => ['POST', '/a/l/AbCdEf123456'],
            'appointments booking page' => ['GET', '/a/b/kapper'],
            'appointments service POST' => ['POST', '/a/b/kapper/8f0e8a9c-1b2c-4d5e-8f90-123456789abc'],
            'appointments ics' => ['GET', '/a/c/AbCdEf123456/afspraak.ics'],
            'appointments reschedule POST' => ['POST', '/a/c/AbCdEf123456/verzetten'],
            'appointments received' => ['GET', '/a/ontvangen'],
            'events GET' => ['GET', '/e/i/klant/zomerfeest'],
            'events POST' => ['POST', '/e/i/klant/zomerfeest'],
            'events ticket without token' => ['GET', '/e/t'],
            'newsletter GET' => ['GET', '/n/u/AbCdEf123456'],
            'newsletter POST' => ['POST', '/n/u/AbCdEf123456'],
            'members POST' => ['POST', '/n/m/AbCdEf123456'],
            'tickets GET' => ['GET', '/t/AbCdEf123456'],
            'tickets POST' => ['POST', '/t/AbCdEf123456/reply'],
            'returns GET' => ['GET', '/r/AbCdEf123456'],
            'files GET' => ['GET', '/api/invoices/download/eyJpZCI6MX0.c2lnbmF0dXJl'],
            'files POST' => ['POST', '/api/privacy/exports/AbCdEf123456'],
            'files order download' => ['GET', '/api/v1/downloads/AbCdEf123456'],
        ];
    }

    #[DataProvider('groupRequests')]
    public function testEachGroupIsForwardedWithMethodPathQueryAndBody(string $method, string $path): void
    {
        $this->hub->queue($this->html('<p>hub page</p>', $method === 'POST' ? 422 : 200));
        $body = $method === 'POST' ? 'naam=Jan+de+Vries&tijd=2026-12-01T10%3A00' : null;

        $response = $this->call($method, $path . '?b=2&a=1', [], [], [], $this->server(['CONTENT_TYPE' => 'application/x-www-form-urlencoded']), $body);

        self::assertSame($method === 'POST' ? 422 : 200, $response->getStatusCode());
        self::assertSame('<p>hub page</p>', $response->getContent());
        self::assertCount(1, $this->hub->requests);
        $sent = $this->hub->requests[0];
        self::assertSame($method, $sent->method);
        // The raw query, in the browser's order.
        self::assertSame('https://content.test' . $path . '?b=2&a=1', $sent->url);
        self::assertSame($body, $sent->body);
        self::assertNull($sent->multipart);
        self::assertSame(20_000, $sent->timeoutMs);
        self::assertSame(3_000, $sent->connectTimeoutMs);
        self::assertSame(2 * 1024 * 1024, $sent->maxBytes);
    }

    public function testTheRoutesAreNamedPerGroupWithoutMiddlewareAndWithTheControllerClass(): void
    {
        $routes = $this->app['router']->getRoutes();
        $names = [];

        foreach ($routes->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), VisitorRoutes::NAME_PREFIX)) {
                $names[] = $route->getName();
                self::assertSame(VisitorProxyController::class, $route->getActionName());
                self::assertSame([], $route->gatherMiddleware());
            }
        }

        self::assertCount(count(VisitorRoutes::ROUTES), $names);
        self::assertSame('a/l/{token}', $routes->getByName('content.visitor.appointments.3')?->uri());
        self::assertEqualsCanonicalizing(['GET', 'HEAD', 'POST'], $routes->getByName('content.visitor.appointments.3')?->methods());
        self::assertSame('api/v1/downloads/{download}', $routes->getByName('content.visitor.files.3')?->uri());
        self::assertSame(1, VisitorRoutes::VERSION);
    }

    public function testOnlyTheAllowListedBrowserHeadersPassAndTheProxyHeadersAreAdded(): void
    {
        $this->hub->queue($this->html());

        $this->call('POST', 'https://klant.test/a/l/AbCdEf123456', [], ['laravel_session' => 'abc'], [], $this->server([
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_ACCEPT_LANGUAGE' => 'nl-NL,nl;q=0.9',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Test',
            'HTTP_ORIGIN' => 'https://klant.test',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_IF_NONE_MATCH' => '"v1"',
            'HTTP_COOKIE' => 'laravel_session=abc',
            'HTTP_AUTHORIZATION' => 'Bearer fsc_srv_fromthebrowser',
            'HTTP_X_FSC_CLIENT_IP' => '198.51.100.66',
            'HTTP_X_FSC_SITE_ORIGIN' => 'https://evil.test',
            'HTTP_X_FSC_PROXY' => '0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.67',
            'HTTP_FORWARDED' => 'for=198.51.100.68',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
            'HTTP_REFERER' => 'https://klant.test/mail',
        ]), 'naam=Jan');

        self::assertSame([
            'Accept' => 'text/html',
            'Accept-Language' => 'nl-NL,nl;q=0.9',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Mozilla/5.0 Test',
            'If-None-Match' => '"v1"',
            'Origin' => 'https://klant.test',
            'Sec-Fetch-Site' => 'same-origin',
            'Authorization' => 'Bearer fsc_srv_laraveltoken',
            'X-FSC-Proxy' => '1',
            'X-FSC-Site-Origin' => 'https://klant.test',
            'X-FSC-Client-Ip' => '203.0.113.7',
        ], $this->hub->requests[0]->headers);
    }

    public function testASpoofedVisitorIpHeaderIsIgnoredWhenTheProxyHeaderIsInvalid(): void
    {
        $this->hub->queue($this->html());

        $this->call('GET', '/a/l/AbCdEf123456', [], [], [], ['HTTP_CF_CONNECTING_IP' => 'not-an-ip', 'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FSC_CLIENT_IP' => '198.51.100.66']);

        self::assertSame('192.0.2.10', $this->hub->requests[0]->headers['X-FSC-Client-Ip']);
    }

    public function testOnlyTheAllowListedResponseHeadersReachTheBrowserAndNoCookie(): void
    {
        $this->hub->queue($this->html('<p>ok</p>', 200, [
            'content-security-policy' => ["default-src 'none'; form-action 'self'"],
            'referrer-policy' => ['no-referrer'],
            'x-robots-tag' => ['noindex, nofollow'],
            'x-frame-options' => ['DENY'],
            'x-content-type-options' => ['nosniff'],
            'cache-control' => ['no-store'],
            'set-cookie' => ['fsc_hub=1; Path=/', 'other=2'],
            'x-powered-by' => ['PHP'],
            'server' => ['nginx'],
            'strict-transport-security' => ['max-age=1'],
            'access-control-allow-origin' => ['*'],
            'content-length' => ['8'],
        ]));

        $response = $this->get('/a/l/AbCdEf123456');

        self::assertSame("default-src 'none'; form-action 'self'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        self::assertNull($response->headers->get('Set-Cookie'));
        self::assertNull($response->headers->get('X-Powered-By'));
        self::assertNull($response->headers->get('Strict-Transport-Security'));
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        // The SDK's own page headers belong to site pages, not to the Hub's.
        self::assertNull($response->headers->get('X-FSC-SDK'));
        self::assertNull($response->headers->get('Link'));
    }

    public function testTheHubHeadersAreFinalOverASiteMiddlewareAndNoSiteCookieLeaves(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(VisitorProxyTestSiteHeaders::class);
        $this->hub->queue($this->html('<p>ok</p>', 200, ['content-security-policy' => ["default-src 'none'"], 'cache-control' => ['no-store']]));

        $response = $this->get('/a/l/AbCdEf123456');

        self::assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        self::assertSame([], $response->headers->getCookies());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        // A header the Hub never sets is the site's business.
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));

        // A site page keeps the site middleware's headers.
        $site = $this->get('/over-ons');
        self::assertSame("default-src 'self'", $site->headers->get('Content-Security-Policy'));
    }

    public function testHtmlWithoutCacheControlIsNoStoreAndTheHubCacheControlOtherwisePasses(): void
    {
        $this->hub->queue($this->html(), $this->html('<p>x</p>', 200, ['cache-control' => ['public, max-age=60']]));

        self::assertStringContainsString('no-store', (string) $this->get('/a/b/kapper')->headers->get('Cache-Control'));
        self::assertSame('max-age=60, public', $this->get('/a/b/kapper')->headers->get('Cache-Control'));
    }

    public function testCalendarDataPassesWithItsEtagAndANotModifiedAnswer(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
        $this->hub->queue(
            new UpstreamResponse(200, ['content-type' => ['text/calendar; charset=utf-8'], 'etag' => ['"feed-1"'], 'content-disposition' => ['inline; filename="agenda.ics"']], $ics),
            new UpstreamResponse(304, ['etag' => ['"feed-1"']], ''),
        );

        $first = $this->get('/a/f/AbCdEf123456');
        self::assertSame($ics, $first->getContent());
        self::assertSame('"feed-1"', $first->headers->get('ETag'));
        self::assertSame('inline; filename="agenda.ics"', $first->headers->get('Content-Disposition'));
        self::assertSame('text/calendar; charset=utf-8', $first->headers->get('Content-Type'));

        $second = $this->get('/a/f/AbCdEf123456', ['If-None-Match' => '"feed-1"']);
        self::assertSame(304, $second->getStatusCode());
        self::assertSame('"feed-1"', $this->hub->requests[1]->headers['If-None-Match']);
    }

    /** @return array<string, array{0: string}> */
    public static function pathsOutsideTheTable(): array
    {
        return [
            'unknown second segment' => ['/a/x/AbCdEf123456'],
            'extra segment' => ['/a/l/AbCdEf123456/meer'],
            'double dot token' => ['/a/l/ab..cd'],
            'dot token' => ['/a/l/.'],
            'encoded slash' => ['/a/l/ab%2Fcd'],
            'encoded dots' => ['/t/%2e%2e'],
            'forbidden character' => ['/n/u/ab$cd'],
            'by-order API' => ['/api/v1/downloads/by-order'],
            'media' => ['/api/media/AbCdEf123456'],
            'shared dashboard' => ['/d/AbCdEf123456'],
            'webhook' => ['/api/v1/payments/webhook'],
            'site page' => ['/afspraak'],
        ];
    }

    #[DataProvider('pathsOutsideTheTable')]
    public function testAPathOutsideTheTableIsNotForwarded(string $path): void
    {
        $response = $this->get($path);

        self::assertSame([], $this->hub->requests);
        self::assertFalse(VisitorRoutes::matches($path));
        self::assertNotSame('<p>hub</p>', $response->getContent());
    }

    public function testAMethodOutsideTheTableIsNotForwarded(): void
    {
        $this->call('POST', '/a/b/kapper');
        $this->call('PUT', '/a/l/AbCdEf123456');
        $this->call('DELETE', '/t/AbCdEf123456');
        $this->call('POST', '/n/l/AbCdEf123456');

        self::assertSame([], $this->hub->requests);
    }

    public function testHeadIsForwardedAsHead(): void
    {
        $this->hub->queue($this->html());

        self::assertSame(200, $this->call('HEAD', '/e/i/klant')->getStatusCode());
        self::assertSame('HEAD', $this->hub->requests[0]->method);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function locations(): array
    {
        return [
            'relative passes' => ['/a/ontvangen?b=1', '/a/ontvangen?b=1'],
            'relative non-visitor passes' => ['/elders', '/elders'],
            'hub origin on a visitor path becomes relative' => ['https://content.test/a/c/AbCdEf123456/afspraak?ok=1#top', '/a/c/AbCdEf123456/afspraak?ok=1#top'],
            'hub origin upper case' => ['HTTPS://CONTENT.TEST/n/u/AbCdEf123456', '/n/u/AbCdEf123456'],
            'protocol relative hub on a visitor path' => ['//content.test/t/AbCdEf123456', '/t/AbCdEf123456'],
            'hub direct file url stays absolute' => ['https://content.test/api/files/direct/eyJ4Ijox.c2ln', 'https://content.test/api/files/direct/eyJ4Ijox.c2ln'],
            'mollie stays absolute' => ['https://www.mollie.com/checkout/select-method/abc', 'https://www.mollie.com/checkout/select-method/abc'],
            'newsletter click target stays' => ['https://www.example.org/actie?utm_source=nieuwsbrief', 'https://www.example.org/actie?utm_source=nieuwsbrief'],
            'other port of the hub stays' => ['https://content.test:8443/a/ontvangen', 'https://content.test:8443/a/ontvangen'],
            'http hub stays' => ['http://content.test/a/ontvangen', 'http://content.test/a/ontvangen'],
            'credentials stay' => ['https://user@content.test/a/ontvangen', 'https://user@content.test/a/ontvangen'],
        ];
    }

    #[DataProvider('locations')]
    public function testTheLocationRule(string $fromHub, string $toBrowser): void
    {
        $this->hub->queue(new UpstreamResponse(302, ['location' => [$fromHub]], ''));

        $response = $this->call('POST', '/a/l/AbCdEf123456', [], [], [], $this->server(), 'x=1');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($toBrowser, $response->headers->get('Location'));
        // Never followed by the proxy.
        self::assertCount(1, $this->hub->requests);
    }

    public function testAnAnswerOverTwoMegabytesIsA502(): void
    {
        $this->hub->queue(new VisitorProxyException(VisitorProxyException::RESPONSE_TOO_LARGE));

        $response = $this->get('/api/invoices/download/eyJpZCI6MX0.c2lnbmF0dXJl');

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('eyJpZCI6MX0', (string) $response->getContent());
        self::assertSame(['Visitor page proxy: response_too_large on GET /api/invoices/download/…'], $this->reported);
    }

    public function testARawBodyOverOneMegabyteIsRefusedWithoutAHubCall(): void
    {
        $response = $this->call('POST', '/n/u/AbCdEf123456', [], [], [], $this->server(['CONTENT_TYPE' => 'application/x-www-form-urlencoded']), str_repeat('a', 1024 * 1024 + 1));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], $this->hub->requests);
        self::assertSame([], $this->reported);
    }

    public function testAQueryOverTwoKilobytesIsRefusedWithoutAHubCall(): void
    {
        self::assertSame(414, $this->get('/a/l/AbCdEf123456?q=' . str_repeat('a', 2050))->getStatusCode());
        self::assertSame([], $this->hub->requests);
    }

    public function testMultipartIsRebuiltFromTheFieldsAndTheUploadedFilesOnDisk(): void
    {
        $this->hub->queue(new UpstreamResponse(303, ['location' => ['/t/AbCdEf123456']], ''));
        $photo = UploadedFile::fake()->create('foto vakantie.jpg', 12, 'image/jpeg');
        $pdf = UploadedFile::fake()->create('bon.pdf', 3, 'application/pdf');

        $response = $this->call(
            'POST',
            '/t/AbCdEf123456/reply',
            ['message' => '  Hallo, zie de bijlage  ', 'leeg' => '', 'meta' => ['order' => '1234']],
            [],
            ['attachments' => [$photo, $pdf]],
            $this->server(['CONTENT_TYPE' => 'multipart/form-data; boundary=----browser']),
        );

        self::assertSame(303, $response->getStatusCode());
        $sent = $this->hub->requests[0];
        self::assertNull($sent->body);
        // cURL writes its own multipart Content-Type with its own boundary.
        self::assertArrayNotHasKey('Content-Type', $sent->headers);
        self::assertSame(['message', 'leeg', 'meta[order]', 'attachments[0]', 'attachments[1]'], array_keys((array) $sent->multipart));
        // As typed: TrimStrings and ConvertEmptyStringsToNull leave proxied requests alone.
        self::assertSame('  Hallo, zie de bijlage  ', $sent->multipart['message']);
        self::assertSame('', $sent->multipart['leeg']);
        self::assertSame('1234', $sent->multipart['meta[order]']);

        $file = $sent->multipart['attachments[0]'];
        self::assertInstanceOf(CURLFile::class, $file);
        // Streamed from PHP's temp file, not read into memory.
        self::assertSame($photo->getPathname(), $file->getFilename());
        self::assertSame('foto vakantie.jpg', $file->getPostFilename());
        self::assertSame('image/jpeg', $file->getMimeType());
        self::assertSame('bon.pdf', $sent->multipart['attachments[1]']->getPostFilename());
    }

    /** @return array<string, array{0: VisitorProxyException|UpstreamResponse, 1: string}> */
    public static function hubFailures(): array
    {
        return [
            'timeout' => [new VisitorProxyException(VisitorProxyException::TIMEOUT), 'timeout'],
            'unreachable' => [new VisitorProxyException(VisitorProxyException::UNREACHABLE), 'unreachable'],
            'token refused' => [new UpstreamResponse(401, ['content-type' => ['application/json']], '{"error":{"code":"invalid_token"}}'), 'refused'],
            'site not verified' => [new UpstreamResponse(409, ['content-type' => ['application/json']], '{"error":{"code":"site_not_verified"}}'), 'refused'],
        ];
    }

    #[DataProvider('hubFailures')]
    public function testAHubFailureIsTheSite503ReportedOnceWithTheTokenScrubbed(VisitorProxyException|UpstreamResponse $answer, string $reason): void
    {
        $this->hub->queue($answer);

        $response = $this->get('/a/c/AbCdEf123456/afspraak');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('300', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // The site's own 503 view (the Testbench skeleton has one), never the Hub's answer.
        self::assertStringContainsString('Be right back', (string) $response->getContent());
        self::assertStringNotContainsString('site_not_verified', (string) $response->getContent());
        self::assertStringNotContainsString('fsc_srv_', (string) $response->getContent());
        self::assertSame(['Visitor page proxy: ' . $reason . ' on GET /a/c/…/afspraak'], $this->reported);
    }

    public function testTheSitesOwn503ViewIsUsedWhenItExists(): void
    {
        $this->app['view']->getFinder()->prependLocation(__DIR__ . '/fixtures/visitor-views');
        $this->hub->queue(new VisitorProxyException(VisitorProxyException::TIMEOUT));

        $response = $this->get('/a/l/AbCdEf123456');

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('Eigen storingspagina', (string) $response->getContent());
    }

    public function testAnHtml409OfAPageIsNotARefusal(): void
    {
        $this->hub->queue($this->html('<p>Dit tijdstip is net vergeven.</p>', 409));

        $response = $this->call('POST', '/a/l/AbCdEf123456', [], [], [], $this->server(), 'x=1');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('<p>Dit tijdstip is net vergeven.</p>', $response->getContent());
        self::assertSame([], $this->reported);
    }

    public function testNoServerTokenIsA503WithoutAHubCall(): void
    {
        $this->app['config']->set('content.token', '');

        self::assertSame(503, $this->get('/a/l/AbCdEf123456')->getStatusCode());
        self::assertSame([], $this->hub->requests);
        self::assertSame(['Visitor page proxy: not_configured on GET /a/l/…'], $this->reported);
    }

    public function testTheProxiedRequestHasNoSessionAndSetsNoCookie(): void
    {
        $this->hub->queue($this->html());

        $response = $this->get('/n/c/AbCdEf123456');

        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($this->app['request']->hasSession());
    }

    public function testASiteCatchAllRegisteredLaterDoesNotShadowTheRoutes(): void
    {
        $this->hub->queue($this->html('<p>hub</p>'));

        self::assertSame('<p>hub</p>', $this->get('/a/l/AbCdEf123456')->getContent());
        self::assertSame('site:over-ons', $this->get('/over-ons')->getContent());
        self::assertSame('site:a/over-ons', $this->get('/a/over-ons')->getContent());
    }

    public function testASiteRouteWithTheIdenticalUriWins(): void
    {
        $this->site(static function (Router $router): void {
            $router->middleware('web')->get('/a/l/{token}', VisitorProxyTestSite::class);
            $router->middleware('web')->get('/{path}', VisitorProxyTestSite::class)->where('path', '.*');
        });

        self::assertSame('site:a/l/AbCdEf123456', $this->get('/a/l/AbCdEf123456')->getContent());
        self::assertSame([], $this->hub->requests);
    }

    public function testWithTheFeatureOffThereAreNoRoutes(): void
    {
        $this->site(null, ['content.auto.visitor_pages' => false]);

        self::assertNull($this->app['router']->getRoutes()->getByName('content.visitor.appointments.1'));
        self::assertSame('site:a/l/AbCdEf123456', $this->get('/a/l/AbCdEf123456')->getContent());
        self::assertSame([], $this->hub->requests);
    }

    public function testWithTheMasterSwitchOffThereAreNoRoutes(): void
    {
        $this->site(null, ['content.auto.enabled' => false]);

        self::assertNull($this->app['router']->getRoutes()->getByName('content.visitor.tickets.1'));
    }

    public function testTheTimeoutsComeFromTheConfig(): void
    {
        $this->site(null, ['content.auto.visitor_pages' => ['enabled' => true, 'timeout' => 8, 'connect_timeout' => 1]]);
        $this->hub->queue($this->html());

        $this->get('/a/l/AbCdEf123456');

        self::assertSame(8_000, $this->hub->requests[0]->timeoutMs);
        self::assertSame(1_000, $this->hub->requests[0]->connectTimeoutMs);
    }

    public function testTheDevmodeSwitchAndTheNewsletterClickLeaveVisitorPathsAlone(): void
    {
        $this->hub->queue($this->html(), $this->html());

        $response = $this->get('/n/l/' . str_repeat('A', 68) . '?fsc_devmode=1&fscn=abc');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
        self::assertSame('https://content.test/n/l/' . str_repeat('A', 68) . '?fsc_devmode=1&fscn=abc', $this->hub->requests[0]->url);
    }

    public function testCachedRoutesGiveTheSameRoutesAndAnswers(): void
    {
        $paths = ['/a/l/AbCdEf123456', '/e/t', '/t/AbCdEf123456/attachments/8f0e8a9c', '/api/v1/downloads/AbCdEf123456', '/over-ons', '/a/over-ons', '/api/v1/downloads/by-order'];
        $before = $this->routeList();
        $answers = $this->answers($paths);

        // What `php artisan route:cache` writes: the compiled collection of a freshly booted application.
        $collection = $this->app['router']->getRoutes();

        foreach ($collection as $route) {
            $route->prepareForSerialization();
        }

        $this->routeCache = sys_get_temp_dir() . '/fsc-visitor-routes-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->routeCache, "<?php\n\napp('router')->setCompiledRoutes(\n    " . var_export($collection->compile(), true) . "\n);\n");
        $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = $this->routeCache;
        putenv('APP_ROUTES_CACHE=' . $this->routeCache);

        // A container start with cached routes: nothing is registered at runtime, the cache is loaded as RouteServiceProvider does.
        $this->site();
        self::assertTrue($this->app->routesAreCached());
        require $this->routeCache;
        $this->app['router']->getRoutes()->refreshNameLookups();

        self::assertInstanceOf(\Illuminate\Routing\CompiledRouteCollection::class, $this->app['router']->getRoutes());
        self::assertSame($before, $this->routeList());
        self::assertSame($answers, $this->answers($paths));
    }

    public function testMatchesScrubAndGroup(): void
    {
        self::assertTrue(VisitorRoutes::matches('/a/l/AbCdEf123456'));
        self::assertTrue(VisitorRoutes::matches('a/l/AbCdEf123456/'));
        self::assertTrue(VisitorRoutes::matches('/t'));
        self::assertTrue(VisitorRoutes::matches('/r'));
        self::assertTrue(VisitorRoutes::matches('/a/l/Ab%43d'));
        self::assertFalse(VisitorRoutes::matches('/'));
        self::assertFalse(VisitorRoutes::matches('/a'));
        self::assertFalse(VisitorRoutes::matches('/tickets'));
        self::assertFalse(VisitorRoutes::matches('/api/v1/downloads/by-order'));
        self::assertTrue(VisitorRoutes::matches('/api/v1/downloads/by-order-123'));

        self::assertSame('/a/l/…', VisitorRoutes::scrub('/a/l/AbCdEf123456'));
        self::assertSame('/t/…/attachments/…', VisitorRoutes::scrub('/t/AbCdEf123456/attachments/8f0e8a9c'));
        self::assertSame('/a/c/…/afspraak.ics', VisitorRoutes::scrub('/a/c/AbCdEf123456/afspraak.ics'));
        self::assertSame('/e/t', VisitorRoutes::scrub('/e/t'));
        self::assertSame('/over-ons', VisitorRoutes::scrub('/over-ons'));
        self::assertSame(['AbCdEf123456', '8f0e8a9c'], VisitorRoutes::parameters('/t/AbCdEf123456/attachments/8f0e8a9c'));

        self::assertSame('appointments', VisitorRoutes::group('/a/b/kapper'));
        self::assertSame('events', VisitorRoutes::group('/e/i/klant/zomerfeest'));
        self::assertSame('newsletter', VisitorRoutes::group('/n/m/AbCdEf123456'));
        self::assertSame('tickets', VisitorRoutes::group('/r/AbCdEf123456'));
        self::assertSame('files', VisitorRoutes::group('/api/privacy/exports/AbCdEf123456'));
        self::assertNull(VisitorRoutes::group('/over-ons'));
    }

    public function testAReportedErrorOnAVisitorPathHasTheTokensScrubbed(): void
    {
        $this->app->instance('request', Request::create('/n/u/qwertyuiopasdfgh'));
        $redact = $this->app->make(Errors::class)->redactions();

        $event = EventBuilder::fromThrowable(
            new RuntimeException('No answer for n/u/qwertyuiopasdfgh (token qwertyuiopasdfgh)'),
            new ErrorsConfig('https://k@errors.test/1', null, 'production', null),
            base_path(),
            '/n/u/{token}',
            null,
            null,
            $redact,
        );

        $message = $event['exception']['values'][0]['value'];
        self::assertSame('No answer for n/u/… (token …)', $message);

        $this->app->instance('request', Request::create('/over-ons'));
        self::assertSame([], $this->app->make(Errors::class)->redactions());
    }

    /** @param array<string, string> $extra */
    private function server(array $extra = []): array
    {
        return ['HTTP_HOST' => 'klant.test', 'HTTPS' => 'on', ...$extra];
    }

    /** @return list<string> `METHOD uri name` of every route */
    private function routeList(): array
    {
        $list = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $name = $name === null || str_starts_with($name, 'generated::') ? '-' : $name;
            $list[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' ' . $name;
        }

        sort($list);

        return $list;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string> status and body per path, with a fresh hub page queued for each
     */
    private function answers(array $paths): array
    {
        $answers = [];

        foreach ($paths as $path) {
            $this->hub->queue($this->html('<p>hub ' . $path . '</p>'));
            $response = $this->get($path);
            $answers[] = $path . ' ' . $response->getStatusCode() . ' ' . $response->getContent();
            $this->hub->reset();
        }

        return $answers;
    }
}

/** The fake Hub: answers from a queue (an exception is thrown), records every request. */
final class VisitorProxyTestUpstream implements Upstream
{
    /** @var list<UpstreamRequest> */
    public array $requests = [];

    /** @var list<UpstreamResponse|VisitorProxyException> */
    private array $queue = [];

    public function queue(UpstreamResponse|VisitorProxyException ...$answers): void
    {
        array_push($this->queue, ...$answers);
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    public function send(UpstreamRequest $request): UpstreamResponse
    {
        $this->requests[] = $request;
        $answer = array_shift($this->queue) ?? throw new \LogicException('No fake Hub answer queued for ' . $request->method . ' ' . $request->url);

        if ($answer instanceof VisitorProxyException) {
            throw $answer;
        }

        return $answer;
    }
}

/** A site controller that echoes the path, so a test sees that the site answered. */
final class VisitorProxyTestSite
{
    public function __invoke(Request $request): string
    {
        return 'site:' . ltrim($request->path(), '/');
    }
}

/** A site's global header middleware: a CSP, a report-only CSP, a cookie, public caching and HSTS on every response. */
final class VisitorProxyTestSiteHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "default-src 'self'");
        $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; report-uri /csp");
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        $response->headers->setCookie(new Cookie('site_tracker', '1'));

        return $response;
    }
}
