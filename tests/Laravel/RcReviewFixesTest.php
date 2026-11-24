<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use Closure;
use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request as HubRequest;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Auto\AutoRoutes;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use FullStackStudio\Content\Laravel\HubCooldown;
use FullStackStudio\Content\Laravel\Sitemap;
use FullStackStudio\Content\Laravel\Visitor\Upstream;
use FullStackStudio\Content\Laravel\Visitor\UpstreamRequest;
use FullStackStudio\Content\Laravel\Visitor\UpstreamResponse;
use FullStackStudio\Content\Laravel\Visitor\VisitorProxyException;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Review findings of the 0.32 release candidate: the `web` default and the opt-in stateless preset (A), legal paths without a document and
 * component layouts (B), Hub-down resilience (C), the double slash 308, and the optional hardening (sitemap, session cookie, visitor IP, hosts).
 * Fake Hub only.
 */
final class RcReviewFixesTest extends FakeHubTestCase
{
    private string $views = '';

    /** @var array<string, mixed> `content.*` values for the next application */
    private array $config = [];

    private ?Closure $siteRoutes = null;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/fsc-rc-views-' . bin2hex(random_bytes(4));
        mkdir($this->views . '/rc', 0777, true);
        // A classic layout (@yield) and a component layout ({{ $slot }}), both with @error and @csrf as many site layouts have.
        file_put_contents($this->views . '/rc/classic.blade.php', '<!doctype html><html><head><x-content::head /></head><body>CLASSIC @error(\'email\')<p>{{ $message }}</p>@enderror'
            . '<form method="post" action="/zoek">@csrf</form>@yield(\'content\')<x-content::scripts /></body></html>');
        file_put_contents($this->views . '/rc/component.blade.php', '@props([\'title\' => null])<!doctype html><html><head><x-content::head /></head><body {{ $attributes->merge([\'class\' => \'site\']) }}>COMPONENT '
            . '@error(\'email\')<p>{{ $message }}</p>@enderror<form method="post" action="/zoek">@csrf</form>{{ $slot }}<x-content::scripts /></body></html>');
        file_put_contents($this->views . '/rc/plain.blade.php', '<!doctype html><html><head><x-content::head /></head><body>PLAIN @if ($errors->any()) fout @endif @yield(\'content\')<x-content::scripts /></body></html>');

        parent::setUp();
        HubCooldown::clear();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/rc/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->views . '/rc');
        @rmdir($this->views);
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.url', 'https://klant.test');
        $app['config']->set('view.paths', [$this->views, ...(array) $app['config']->get('view.paths', [])]);
        $app['config']->set('content.auto.layout', 'rc.classic');

        foreach ($this->config as $key => $value) {
            $app['config']->set('content.' . $key, $value);
        }
    }

    protected function defineRoutes($router): void
    {
        if ($this->siteRoutes !== null) {
            ($this->siteRoutes)($router);
        }
    }

    protected function answer(HubRequest $request): HubResponse
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

        if ($path === '/forms/contact') {
            return FakeTransport::json(['form' => [
                'key' => 'contact', 'name' => 'Contact',
                'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true, 'maxLength' => 100]],
                'honeypotField' => 'website', 'successMessage' => 'ok', 'confirmEnabled' => false, 'turnstileSiteKey' => null,
            ]]);
        }

        return parent::answer($request);
    }

    /** A fresh application with these `content.*` values and site routes; the fake Hub keeps its pages. */
    private function site(array $config = [], ?Closure $routes = null): void
    {
        $this->config = $config;
        $this->siteRoutes = $routes;
        $this->refreshApplication();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        HubCooldown::clear();
    }

    /** Enforces the CSRF check in a test (Laravel skips its own while running unit tests): posted `_token` must equal the session's. */
    private function enforceCsrf(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', RcEnforceCsrf::class);
    }

    private static function token(string $html): string
    {
        preg_match('/name="_token" value="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    }

    // --- A: the default is `web`, cookie free is the opt-in preset -----------------------------------------------------------------

    public function testALayoutWithErrorAndCsrfRendersOnAnAutoPageUnderWeb(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);
        $this->site();

        $response = $this->get('/over-ons');

        $response->assertOk()->assertSee('CLASSIC', false)->assertSee('Over ons', false);
        self::assertNotSame('', self::token((string) $response->getContent()), '@csrf prints the token of the web session');
        self::assertSame(['web'], $this->app['router']->getRoutes()->getByName(AutoRoutes::PAGE)->gatherMiddleware());
    }

    public function testALayoutWithErrorAndCsrfRendersOnAStatelessAutoPageAndItsTokenPassesARealCsrfCheck(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);
        $this->site(['auto.middleware' => 'stateless']);
        $this->enforceCsrf();
        $this->app['router']->middleware('web')->post('/zoek', static fn () => 'gezocht');

        $page = $this->get('/over-ons');
        $page->assertOk()->assertSee('CLASSIC', false);
        $token = self::token((string) $page->getContent());
        $cookie = $page->getCookie((string) config('session.cookie'), false);

        self::assertNotSame('', $token, '@csrf started the session lazily');
        self::assertNotNull($cookie, 'the lazily started session is written as a cookie');

        // The post (web group, CSRF enforced) with the lazily written cookie and the token of the page.
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->post('/zoek', ['_token' => $token])->assertOk()->assertSee('gezocht');

        // The control: a wrong token is refused with 419, so the check above really ran.
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->post('/zoek', ['_token' => 'fout'])->assertStatus(419);
    }

    public function testAStatelessPageWhoseLayoutReadsErrorsSendsNoCookie(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);
        $this->site(['auto.middleware' => 'stateless', 'auto.layout' => 'rc.plain']);

        $response = $this->get('/over-ons');

        $response->assertOk()->assertSee('PLAIN', false)->assertDontSee('fout', false);
        self::assertSame([], $response->headers->getCookies(), 'an empty $errors bag needs no session');
    }

    public function testTheFormPostFromAStatelessPagePassesARealCsrfCheckWithTheLazilyWrittenCookie(): void
    {
        $this->page('contact', 'nl', ['title' => 'Contact', 'blocks' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);
        $this->site(['auto.middleware' => 'stateless', 'auto.layout' => 'rc.plain']);
        $this->enforceCsrf();

        $page = $this->get('/contact');
        $page->assertOk();
        $token = self::token((string) $page->getContent());
        $cookie = $page->getCookie((string) config('session.cookie'), false);
        self::assertNotSame('', $token);
        self::assertNotNull($cookie);

        $wrong = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->post('/content/forms/contact', ['_token' => 'fout', 'email' => 'a@b.nl']);
        self::assertSame(419, $wrong->getStatusCode(), 'the CSRF check is enforced');

        $post = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->post('/content/forms/contact', ['_token' => $token, 'email' => 'a@b.nl']);
        self::assertNotContains($post->getStatusCode(), [404, 419, 500], 'the form post with the lazily written cookie and token is accepted');
        self::assertTrue($post->isRedirection() || $post->isSuccessful());
    }

    public function testAReturningVisitorsSessionIsStartedUpFrontSoErrorsShowOnAStatelessPage(): void
    {
        $this->page('contact', 'nl', ['title' => 'Contact', 'blocks' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);
        $this->site(['auto.middleware' => 'stateless']);

        $first = $this->get('/contact');
        $cookie = $first->getCookie((string) config('session.cookie'), false);
        self::assertNotNull($cookie);

        // What a failed post flashes, then the visitor comes back to the page.
        $store = $this->app['session.store'];
        $store->put('errors', (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['email' => ['Vul een e-mailadres in.']])));
        $store->save();

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/contact')->assertOk()->assertSee('Vul een e-mailadres in.', false);
    }

    // --- B: legal paths without a document, component layouts ------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function layouts(): array
    {
        return ['classic layout' => ['rc.classic'], 'component layout' => ['rc.component']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('layouts')]
    public function testALegalDocumentRendersInBothLayoutStyles(string $layout): void
    {
        $this->legal['privacy_statement'] = ['kind' => 'privacy_statement', 'title' => 'Privacy', 'bodyHtml' => '<p>Wij bewaren niets.</p>'];
        $this->site(['auto.layout' => $layout]);

        $response = $this->get('/privacyverklaring');

        $response->assertOk()->assertSee('Wij bewaren niets.', false)->assertSee($layout === 'rc.component' ? 'COMPONENT' : 'CLASSIC', false);
        self::assertSame(1, substr_count((string) $response->getContent(), '<html'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('layouts')]
    public function testWithoutALegalDocumentTheHubPageAtThatPathIsServed(string $layout): void
    {
        $this->page('privacyverklaring', 'nl', ['title' => 'Onze privacy', 'blocks' => [['id' => 'b', 'type' => 'richText', 'html' => '<p>Eigen pagina</p>']]]);
        $this->site(['auto.layout' => $layout]);

        $response = $this->get('/privacyverklaring');

        $response->assertOk()->assertSee('Eigen pagina', false)->assertSee($layout === 'rc.component' ? 'COMPONENT' : 'CLASSIC', false);
        self::assertContains('/legal/privacy_statement', $this->hubPaths());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('layouts')]
    public function testWithoutALegalDocumentAndWithoutAPageItIsA404(string $layout): void
    {
        $this->site(['auto.layout' => $layout]);

        $this->get('/algemene-voorwaarden')->assertNotFound();
    }

    public function testWithoutALegalDocumentTheSitesOwnCatchAllAnswersAsBefore(): void
    {
        $this->site([], static function ($router): void {
            $router->middleware('web')->get('/{path}', static fn (Request $request, string $path) => 'site:' . $path)->where('path', '.*');
        });

        // The early legal route is matched first; without a document the site's catch-all answers, as it did before 0.32.
        $this->get('/cookieverklaring')->assertOk()->assertSeeText('site:cookieverklaring');

        // With a document (published: the webhook clears the legal tag) the SDK serves it.
        $this->app->make(ContentCache::class)->invalidateTags([Tags::LEGAL]);
        $this->legal['cookie_statement'] = ['kind' => 'cookie_statement', 'title' => 'Cookies', 'bodyHtml' => '<p>Alleen noodzakelijk.</p>'];
        $this->get('/cookieverklaring')->assertOk()->assertSee('Alleen noodzakelijk.', false);
    }

    public function testAHubPageRendersInAComponentLayout(): void
    {
        $this->page('diensten', 'nl', ['title' => 'Diensten']);
        $this->site(['auto.layout' => 'rc.component']);

        $this->get('/diensten')->assertOk()->assertSee('COMPONENT', false)->assertSee('class="site"', false)->assertSee('Diensten', false);
    }

    // --- C: Hub-down resilience ---------------------------------------------------------------------------------------------------

    public function testAHangingHubDoesNotPinRequestsAfterTheFirstTimeout(): void
    {
        $this->site();
        $this->http = new FakeTransport();
        $this->http->queue(...array_fill(0, 50, new TransportException('timed out', true)));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));

        $this->get('/onbekend-1')->assertStatus(503);
        $first = count($this->http->requests);
        self::assertGreaterThan(0, $first);

        foreach ($this->http->requests as $request) {
            self::assertLessThanOrEqual(2500, $request->timeoutMs, $request->url . ' uses the short resolver timeout');
        }

        // Every later unknown path within the cooldown fails fast: a 503 without a Hub call.
        foreach (['/onbekend-2', '/onbekend-3', '/nog/een/pad'] as $path) {
            $this->get($path)->assertStatus(503)->assertHeader('Retry-After');
        }

        self::assertSame($first, count($this->http->requests), 'no Hub call during the cooldown');
        self::assertTrue(HubCooldown::active());

        // After the cooldown the Hub is asked again.
        HubCooldown::clear();
        $this->get('/onbekend-4')->assertStatus(503);
        self::assertGreaterThan($first, count($this->http->requests));
    }

    public function testAHubErrorAnswerDoesNotStartTheCooldown(): void
    {
        $this->down = ['/pages'];
        $this->site();

        $this->get('/over-ons')->assertStatus(503);
        self::assertFalse(HubCooldown::active(), 'a 500 is an answer: the Hub is up');
    }

    public function testAnUnknownPathIsRememberedBrieflyAndAWebhookClearsIt(): void
    {
        $this->site();

        $pages = fn (): int => count(array_filter($this->hubPaths(), static fn (string $p): bool => $p === '/pages'));

        $this->get('/bestaat-niet')->assertNotFound();
        self::assertSame(1, $pages());
        $this->get('/bestaat-niet')->assertNotFound();
        self::assertSame(1, $pages(), 'the second 404 does not ask the Hub for the page again');

        // A page is published: the webhook clears the page tags, so the new page shows at once.
        $this->page('bestaat-niet', 'nl', ['title' => 'Nu wel']);
        $this->app->make(ContentCache::class)->invalidateTags([Tags::PAGES]);
        $this->get('/bestaat-niet')->assertOk()->assertSee('Nu wel', false);
    }

    public function testTheNegativeCacheCanBeSwitchedOff(): void
    {
        $this->site(['hub.not_found_ttl' => 0]);

        $this->get('/bestaat-niet')->assertNotFound();
        $this->get('/bestaat-niet')->assertNotFound();
        self::assertSame(2, count(array_filter($this->hubPaths(), static fn (string $p): bool => $p === '/pages')));
    }

    public function testTheVisitorProxySharesTheCooldownAndHasABoundedTimeout(): void
    {
        $this->site();
        $upstream = new RcTimeoutUpstream();
        $this->app->instance(Upstream::class, $upstream);

        $this->get('/a/l/AbCdEf123456')->assertStatus(503);
        self::assertCount(1, $upstream->sent);
        self::assertSame(8000, $upstream->sent[0]->timeoutMs);
        self::assertTrue(HubCooldown::active());

        // Within the cooldown: the 503 page at once, no upstream call.
        $this->get('/a/l/AbCdEf123456')->assertStatus(503);
        self::assertCount(1, $upstream->sent);
    }

    // --- the double slash -----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function modes(): array
    {
        return ['web' => [[]], 'stateless' => [['auto.middleware' => 'stateless']]];
    }

    /** @param array<string, mixed> $config */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testADoubleSlashIsA308ToTheLocalPathAndNeverAnOpenRedirect(array $config): void
    {
        $this->site($config);

        foreach ([
            'http://localhost//evil.example//' => '/evil.example',
            'http://localhost//evil.example' => '/evil.example',
            'http://localhost/diensten//web?x=1' => '/diensten/web?x=1',
            'http://localhost///' => '/',
        ] as $uri => $location) {
            $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(Request::create($uri));

            self::assertSame(308, $response->getStatusCode(), $uri);
            self::assertSame($location, $response->headers->get('Location'), $uri);
            self::assertStringStartsNotWith('//', (string) $response->headers->get('Location'));
        }

        // An encoded backslash stays encoded (a raw `/\evil.example` would read as `//evil.example` in a browser): a local path.
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('http://localhost//%5Cevil.example'));
        self::assertSame('/%5Cevil.example', $response->headers->get('Location'));
    }

    public function testADoubleSlashKeepsTheTrailingSlashFormOfTheSite(): void
    {
        $this->site(['auto.trailing_slash' => true]);

        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('http://localhost/diensten//web/'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/diensten/web/', $response->headers->get('Location'));

        // A leading double slash without the trailing one: the trailing slash middleware answers first (301), also with a local path.
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('http://localhost//over-ons'));
        self::assertSame('/over-ons/', $response->headers->get('Location'));
        // A last segment with a dot gets no trailing slash (a file); the double slash still becomes a local path.
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('http://localhost//evil.example'));
        self::assertSame('/evil.example', $response->headers->get('Location'));
    }

    // --- optional hardening ---------------------------------------------------------------------------------------------------------

    public function testTheSitemapLeavesPrivateDomainAndThankYouRoutesOut(): void
    {
        $this->site([], static function ($router): void {
            $router->middleware('web')->get('/diensten', static fn () => 'x');
            $router->middleware(['web', \Illuminate\Auth\Middleware\Authenticate::class])->get('/account', static fn () => 'x');
            $router->middleware(['web', RcCustomAuthenticate::class])->get('/portaal', static fn () => 'x');
            $router->middleware(['web', 'guest'])->get('/inloggen', static fn () => 'x');
            $router->middleware(['web', 'signed'])->get('/uitschrijven', static fn () => 'x');
            $router->middleware('web')->domain('admin.klant.test')->get('/beheer', static fn () => 'x');
            $router->middleware('web')->get('/bedankt', static fn () => 'x');
            $router->middleware('web')->get('/contact/thank-you', static fn () => 'x');
        });

        $paths = Sitemap::ownPaths();

        self::assertContains('/diensten', $paths);

        foreach (['/account', '/portaal', '/inloggen', '/uitschrijven', '/beheer', '/bedankt', '/contact/thank-you'] as $private) {
            self::assertNotContains($private, $paths, $private);
        }

        $this->app['config']->set('content.auto.sitemap.thank_you', []);
        self::assertContains('/bedankt', Sitemap::ownPaths());
    }

    public function testTheLazySessionAcceptsACookieEncryptedWithAPreviousKey(): void
    {
        $old = 'base64:' . base64_encode(str_repeat('o', 32));
        $this->page('contact', 'nl', ['title' => 'Contact', 'blocks' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);

        // The session is written while the old key is the app key.
        $this->site(['auto.middleware' => 'stateless']);
        $this->app['config']->set('app.key', $old);
        $this->app->forgetInstance('encrypter');
        $first = $this->get('/contact');
        $cookie = $first->getCookie((string) config('session.cookie'), false);
        $token = self::token((string) $first->getContent());

        // After a key rotation (APP_PREVIOUS_KEYS) the same session is found.
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('n', 32)));
        $this->app['config']->set('app.previous_keys', [$old]);
        $this->app->forgetInstance('encrypter');

        if (! method_exists($this->app->make('encrypter'), 'getAllKeys')) {
            self::markTestSkipped('No previous keys on this Laravel version.');
        }

        $second = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/contact');
        self::assertSame($token, self::token((string) $second->getContent()));
    }

    public function testTheProxyIpHeaderIsOnlyTrustedFromATrustedProxy(): void
    {
        $spoofed = Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']);
        [$proxies, $headers] = [Request::getTrustedProxies(), Request::getTrustedHeaderSet()];

        try {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
            self::assertSame('203.0.113.9', ContentServiceProvider::visitorIp($spoofed, 'cf-connecting-ip'), 'an untrusted client cannot choose its IP');

            Request::setTrustedProxies(['203.0.113.9'], Request::HEADER_X_FORWARDED_FOR);
            self::assertSame('198.51.100.1', ContentServiceProvider::visitorIp($spoofed, 'cf-connecting-ip'), 'the header of a trusted proxy is used');
        } finally {
            Request::setTrustedProxies($proxies, $headers);
        }
    }

    public function testTheHostsApplyToThePackageRoutesAlsoWithTheAutomaticSiteOff(): void
    {
        $this->site(['auto.enabled' => false, 'auto.hosts' => ['www.klant.nl']]);

        // The visitor proxy, the verification probe and the webhook still exist with FSC_AUTO=false; they answer on the site's hosts only.
        foreach (['content.visitor.appointments.1', 'content.webhook', 'content.consent.record'] as $name) {
            $route = $this->app['router']->getRoutes()->getByName($name);

            if ($route === null) {
                continue;
            }

            self::assertContains(\FullStackStudio\Content\Laravel\Auto\HostOnly::class, $route->gatherMiddleware(), $name);
        }

        $this->post('http://admin.klant.nl/content/webhook')->assertNotFound();
    }
}

/** The CSRF check of `web`, enforced in a test: the posted token must be the session's. */
final class RcEnforceCsrf
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = (string) $request->input('_token', $request->header('X-CSRF-TOKEN', ''));
            $session = $request->hasSession() ? (string) $request->session()->token() : '';

            if ($session === '' || ! hash_equals($session, $token)) {
                throw new TokenMismatchException('CSRF token mismatch.');
            }
        }

        return $next($request);
    }
}

/** A custom auth middleware of a site that does not extend the framework's. */
final class RcCustomAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

/** The Hub never answers. */
final class RcTimeoutUpstream implements Upstream
{
    /** @var list<UpstreamRequest> */
    public array $sent = [];

    public function send(UpstreamRequest $request): UpstreamResponse
    {
        $this->sent[] = $request;

        throw new VisitorProxyException(VisitorProxyException::TIMEOUT);
    }
}
