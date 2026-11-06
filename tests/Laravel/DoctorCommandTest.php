<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Doctor;
use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Laravel\Robots;
use FullStackStudio\Content\Laravel\Sitemap;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;

final class DoctorCommandTest extends LaravelTestCase
{
    private const LAYOUT = <<<'BLADE'
        <!doctype html>
        <html>
        <head>
            <x-content::meta :path="request()->getPathInfo()" />
            <x-content::head />
            <x-content::errors />
        </head>
        <body>
            @yield('content')
            @include('partials.consent')
            <x-content::body-end />
            <x-content::analytics />
        </body>
        </html>
        BLADE;

    private string $views;
    private string $public;
    private bool $completeRoutes = true;
    private string $healthBody = '{"ok":true}';

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/fsc-doctor-views-' . bin2hex(random_bytes(4));
        $this->public = sys_get_temp_dir() . '/fsc-doctor-public-' . bin2hex(random_bytes(4));
        mkdir($this->views . '/layouts', 0777, true);
        mkdir($this->views . '/errors', 0777, true);
        mkdir($this->views . '/partials', 0777, true);
        mkdir($this->public, 0777, true);

        parent::setUp();

        $this->app['config']->set('view.paths', [$this->views]);
        $this->app['view']->addLocation($this->views);
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TrustProxies::flushState();
        foreach ([$this->views, $this->public] as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    protected function defineRoutes($router): void
    {
        if (! $this->completeRoutes) {
            return;
        }

        $router->get('/sitemap.xml', fn () => Sitemap::response());
        $router->get('/robots.txt', fn () => Robots::response());
        $router->get('/api/health', fn () => response($this->healthBody, 200, ['Content-Type' => 'application/json']));
        $router->get('/{path}', fn () => 'page')->where('path', '.*');
    }

    /** A site that does everything AGENTS.md asks. */
    private function completeSite(): void
    {
        $this->app['config']->set('content.forms.enabled', true);
        \FullStackStudio\Content\Laravel\ContentServiceProvider::registerFormRoute();
        $this->app['config']->set('app.url', 'https://example.nl');
        $this->app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $this->app['config']->set('session.driver', 'file');
        $this->app['router']->prependMiddlewareToGroup('web', RedirectMiddleware::class);
        TrustProxies::at('*');
        URL::forceScheme('https');

        file_put_contents($this->views . '/layouts/app.blade.php', self::LAYOUT);
        file_put_contents($this->views . '/partials/consent.blade.php', "<script>window.dispatchEvent(new CustomEvent('fsc:consent', {detail: c}));</script>");
        file_put_contents($this->views . '/errors/404.blade.php', "@extends('layouts.app', ['fscStatus' => 404])");
        file_put_contents($this->views . '/errors/503.blade.php', '<html><head><title>503</title></head><body>Even niet bereikbaar</body></html>');
        file_put_contents($this->views . '/contact.blade.php', '<x-content::form form-key="contact" />');
    }

    /** @return list<array{status: string, label: string, hint: ?string}> */
    private function results(bool $checkEnv = true): array
    {
        return (new Doctor($this->app))->run($checkEnv);
    }

    /** @param list<array{status: string, label: string, hint: ?string}> $results */
    private function statusOf(array $results, string $labelPart): ?string
    {
        foreach ($results as $result) {
            if (str_contains($result['label'], $labelPart)) {
                return $result['status'];
            }
        }

        return null;
    }

    /** @param list<array{status: string, label: string, hint: ?string}> $results */
    private function failures(array $results): array
    {
        return array_values(array_map(fn ($r) => $r['label'], array_filter($results, fn ($r) => $r['status'] === Doctor::FAIL)));
    }

    public function testACompleteSitePasses(): void
    {
        $this->completeSite();

        $results = $this->results();

        self::assertSame([], $this->failures($results));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'GET /sitemap.xml'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'GET /robots.txt'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'GET /api/health geeft 200'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'Webhookroute'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'Formulierroute'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'RedirectMiddleware staat vooraan'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'Layout'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'Cookiebanner'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'forceScheme'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'errors/404 meldt zich bij de tracker'));

        $this->artisan('fsc:doctor')
            ->expectsOutputToContain('0 fout.')
            ->assertExitCode(0);
    }

    public function testANotFoundViewWithoutTheTrackerHookIsAWarningAndEachHookIsAccepted(): void
    {
        $this->completeSite();
        $view = $this->views . '/errors/404.blade.php';

        file_put_contents($view, '<h1>Niet gevonden</h1>');
        $results = $this->results();
        self::assertSame(Doctor::WARN, $this->statusOf($results, 'errors/404 meldt zich niet bij de tracker'));
        self::assertSame([], $this->failures($results), 'a warning, never a failure');

        foreach (['<html data-fsa-404><body>x</body></html>', '<body data-fsa-404="">x</body>', '<x-content::analytics status="404" />', "@extends('layouts.app', ['fscStatus' => 404])"] as $hooked) {
            file_put_contents($view, $hooked);
            self::assertSame(Doctor::OK, $this->statusOf($this->results(), 'errors/404 meldt zich bij de tracker'), $hooked);
        }
    }

    public function testABareSiteFailsWithDutchLines(): void
    {
        $this->completeRoutes = false;
        $this->refreshApplication();
        $this->app['config']->set('view.paths', [$this->views]);

        $failures = $this->failures($this->results());

        self::assertContains('GET /sitemap.xml ontbreekt', $failures);
        self::assertContains('GET /robots.txt ontbreekt', $failures);
        self::assertContains('GET /api/health ontbreekt', $failures);
        self::assertContains('RedirectMiddleware staat niet in de web-groep', $failures);
        self::assertTrue((bool) array_filter($failures, fn ($f) => str_starts_with($f, 'Geen layout met <head> gevonden')));

        $this->artisan('fsc:doctor')->expectsOutputToContain('FOUT')->assertExitCode(1);
    }

    public function testCookieSessionsFail(): void
    {
        $this->completeSite();
        $this->app['config']->set('session.driver', 'cookie');

        self::assertSame(Doctor::FAIL, $this->statusOf($this->results(), 'SESSION_DRIVER is cookie'));
    }

    public function testALayoutWithoutAnalyticsOrWithMetaInTheBodyFails(): void
    {
        $this->completeSite();
        $layout = str_replace('<x-content::analytics />', '', self::LAYOUT);
        $layout = str_replace("<x-content::errors />\n</head>", "</head>\n<x-content::errors />", $layout);
        file_put_contents($this->views . '/layouts/app.blade.php', $layout);

        $failures = $this->failures($this->results());

        self::assertCount(1, $failures);
        self::assertStringContainsString('<x-content::analytics />', $failures[0]);
        self::assertStringContainsString('<x-content::errors /> hoort in <head>', $failures[0]);
    }

    public function testAStaticRobotsFileFails(): void
    {
        $this->completeSite();
        file_put_contents($this->public . '/robots.txt', "User-agent: *\n");

        self::assertSame(Doctor::FAIL, $this->statusOf($this->results(), 'public/robots.txt bestaat'));
    }

    public function testAHealthRouteWithoutOkTrueFails(): void
    {
        $this->healthBody = '{"status":"ok"}';
        $this->completeSite();

        self::assertSame(Doctor::FAIL, $this->statusOf($this->results(), 'GET /api/health geeft 200 zonder'));
    }

    public function testEnvironmentChecksAndSkip(): void
    {
        $this->completeSite();
        $this->app['config']->set('content.token', 'fsc_pub_browser');
        $this->app['config']->set('content.webhook.secret', '');

        $results = $this->results();
        self::assertSame(Doctor::FAIL, $this->statusOf($results, 'FSC_SERVER_TOKEN is geen servertoken'));
        self::assertSame(Doctor::FAIL, $this->statusOf($results, 'FSC_WEBHOOK_SECRET ontbreekt'));

        $skipped = $this->results(false);
        self::assertSame([], $this->failures($skipped));
        self::assertSame(Doctor::SKIP, $this->statusOf($skipped, 'overgeslagen'));
        $this->artisan('fsc:doctor', ['--skip-env' => true])->assertExitCode(0);
    }

    public function testASecretInAViewFails(): void
    {
        $this->completeSite();
        file_put_contents($this->views . '/oops.blade.php', '<script>const t = "fsc_srv_abcdefghijklmnop";</script>');

        self::assertSame(Doctor::FAIL, $this->statusOf($this->results(), 'Geheim gevonden in'));
    }

    public function testProductionWithoutHttpsFails(): void
    {
        $this->completeSite();
        URL::forceScheme(null);
        $this->app['config']->set('app.url', 'http://example.nl');
        $this->app['env'] = 'production';

        $failures = $this->failures($this->results());

        self::assertContains('https wordt niet afgedwongen', $failures);
        self::assertContains('APP_URL is geen https in productie (http://example.nl)', $failures);
    }

    public function testFormsInAViewNeedTheFormRoute(): void
    {
        // No completeSite(): the form route stays unregistered while a view uses the component.
        file_put_contents($this->views . '/contact.blade.php', '<x-content::form form-key="contact" />');

        self::assertSame(Doctor::FAIL, $this->statusOf($this->results(), 'maar de formulierroute ontbreekt'));
    }

    public function testFullRequestUrlInAViewIsAWarning(): void
    {
        $this->completeSite();
        file_put_contents($this->views . '/share.blade.php', '<a href="https://x.com/share?u={{ urlencode(request()->fullUrl()) }}">Deel</a>');

        self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'gebruikt de volledige URL met query'));
    }

    public function testStrictFailsOnWarnings(): void
    {
        $this->completeSite();
        unlink($this->views . '/errors/503.blade.php');

        $this->artisan('fsc:doctor')->assertExitCode(0);
        $this->artisan('fsc:doctor', ['--strict' => true])->assertExitCode(1);
    }

    public function testOnlineAsksTheHubOnce(): void
    {
        $this->completeSite();
        $http = new FakeTransport();
        $http->queue(
            FakeTransport::json(['project' => ['id' => 'p1', 'name' => 'Voorbeeld'], 'modules' => ['pages', 'forms', 'analytics']]),
            FakeTransport::json(['seo' => ['canonicalOrigin' => 'https://example.nl']]),
        );
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'transport' => $http]));

        $results = (new Doctor($this->app))->run(true, true);

        self::assertSame(Doctor::OK, $this->statusOf($results, 'De Hub antwoordt met deze token (project Voorbeeld)'));
        self::assertSame(Doctor::INFO, $this->statusOf($results, 'Modules aan: pages, forms, analytics'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'canonicalOrigin is https://example.nl'));
    }
}
