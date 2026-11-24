<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request as HubRequest;
use FullStackStudio\Content\Http\Response as HubResponse;
use FullStackStudio\Content\Laravel\Doctor;
use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Laravel\Robots;
use FullStackStudio\Content\Laravel\Sitemap;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Router;
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

    /** @var (Closure(Router): void)|null routes of a fixture app built with {@see site()}; null = the hand-built default */
    private ?Closure $siteRoutes = null;

    /** @var array<string, mixed>|null config of a fixture app built with {@see site()} */
    private ?array $fixtureConfig = null;

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

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->fixtureConfig !== null) {
            foreach ($this->fixtureConfig as $key => $value) {
                $app['config']->set($key, $value);
            }

            return;
        }

        // A bare site from before the automatic routes; the doctor checks for those come with their own tests.
        if (! $this->completeRoutes) {
            $app['config']->set('content.auto.enabled', false);
        }
    }

    protected function defineRoutes($router): void
    {
        if ($this->fixtureConfig !== null) {
            if ($this->siteRoutes !== null) {
                ($this->siteRoutes)($router);
            }

            return;
        }

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
        file_put_contents($this->views . '/partials/consent.blade.php', "<style>html[data-fsc-preview] #fsc-consent{display:none!important}</style><script>window.dispatchEvent(new CustomEvent('fsc:consent', {detail: c}));</script>");
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

    // --- Out of the box fixtures (plan sdk-out-of-the-box, task 12) -----------------------------------------------------------------

    /**
     * Builds a fixture app: `$routes` are the routes of the site, `$config` extra config on top of an https, file-session site that has its
     * redirect middleware switched on (the base test case switches it off).
     *
     * @param array<string, mixed> $config
     */
    private function site(?Closure $routes = null, array $config = []): void
    {
        $this->siteRoutes = $routes;
        $this->fixtureConfig = [
            'app.url' => 'https://example.nl',
            'session.driver' => 'file',
            'content.auto.redirects' => true,
            ...$config,
        ];

        // The trusted proxies are process wide: a site that is built again must look like a fresh process to the SDK.
        TrustProxies::flushState();
        $this->refreshApplication();
        $this->app['config']->set('view.paths', [$this->views]);
        $this->app['view']->addLocation($this->views);
        $this->app->usePublicPath($this->public);
    }

    private function layoutWith(string $head, string $body, string $name = 'app'): void
    {
        file_put_contents($this->views . "/layouts/{$name}.blade.php", "<!doctype html>\n<html>\n<head>\n{$head}\n</head>\n<body>\n@yield('content')\n{$body}\n</body>\n</html>\n");
    }

    /**
     * @param list<array{status: string, label: string, hint: ?string}> $results
     * @return list<string> the labels with a status
     */
    private function labels(array $results, string $status): array
    {
        return array_values(array_map(fn ($r) => $r['label'], array_filter($results, fn ($r) => $r['status'] === $status)));
    }

    /** @param list<array{status: string, label: string, hint: ?string}> $results */
    private function assertLine(array $results, string $status, string $labelPart): void
    {
        foreach ($results as $result) {
            if ($result['status'] === $status && str_contains($result['label'], $labelPart)) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail("No {$status} line containing \"{$labelPart}\". Lines: " . implode(' | ', array_map(fn ($r) => $r['status'] . ': ' . $r['label'], $results)));
    }

    private function assertFailure(string $labelPart): void
    {
        foreach ($this->failures($this->results()) as $label) {
            if (str_contains($label, $labelPart)) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail("No failure containing \"{$labelPart}\". Failures: " . implode(' | ', $this->failures($this->results())));
    }

    public function testFixtureAFullyAutomaticSiteHasNothingToFixAndNothingToWarnAbout(): void
    {
        $this->site();

        $results = $this->results();

        self::assertSame([], $this->failures($results));
        self::assertSame([], $this->labels($results, Doctor::WARN));
        $this->assertLine($results, Doctor::OK, 'Sitemap automatisch (SDK): GET /sitemap.xml');
        $this->assertLine($results, Doctor::OK, 'Robots automatisch (SDK): GET /robots.txt');
        $this->assertLine($results, Doctor::OK, 'Healthcheck automatisch (SDK): GET /api/health');
        $this->assertLine($results, Doctor::OK, 'GET /api/health geeft 200');
        $this->assertLine($results, Doctor::OK, 'Pagina\'s uit de Hub automatisch (SDK)');
        $this->assertLine($results, Doctor::OK, 'Artikelen en categorieën automatisch (SDK)');
        $this->assertLine($results, Doctor::OK, 'Juridische teksten automatisch (SDK): /privacyverklaring');
        $this->assertLine($results, Doctor::OK, 'Platform automatisch (SDK): proxy\'s vertrouwd (SDK)');
        $this->assertLine($results, Doctor::OK, 'Redirects uit de Hub automatisch (SDK)');
        $this->assertLine($results, Doctor::OK, 'RedirectMiddleware staat vooraan in de web-groep');
        $this->assertLine($results, Doctor::OK, 'Foutpagina\'s (404 en 503) automatisch (SDK)');
        $this->assertLine($results, Doctor::OK, 'Cookiebanner automatisch (SDK, via <x-content::scripts />)');
        $this->assertLine($results, Doctor::OK, 'Bezoekerspagina\'s: routes actief (proxy 1');
        $this->assertLine($results, Doctor::OK, 'Geen eigen layout: de pagina\'s van de SDK gebruiken zijn standaardlayout');
        $this->assertLine($results, Doctor::OK, 'Foutpagina errors/404 van de SDK (automatisch)');
        $this->assertLine($results, Doctor::OK, 'Foutpagina errors/503 van de SDK (automatisch)');
        $this->assertLine($results, Doctor::INFO, 'Geen eigen pagina\'s van de site in de sitemap');
        $this->assertLine($results, Doctor::INFO, 'Slash aan het eind van URL\'s staat uit (FSC_AUTO_TRAILING_SLASH');
        self::assertNull($this->statusOf($results, 'Twee cookiebanners'));
        self::assertNull($this->statusOf($results, 'Geen cookiebanner gevonden'));

        $this->artisan('fsc:doctor')->expectsOutputToContain('0 fout.')->expectsOutputToContain('automatisch (SDK)')->assertExitCode(0);
    }

    public function testFixtureBAHandBuiltSiteKeepsItsChecksAndOnlyGetsExtraInfoLines(): void
    {
        $this->completeSite();

        $results = $this->results();

        self::assertSame([], $this->failures($results));
        self::assertSame([], $this->labels($results, Doctor::WARN));
        // The site's own routes win, and the doctor names them.
        $this->assertLine($results, Doctor::INFO, 'Sitemap: eigen route wint: sitemap.xml → Closure');
        $this->assertLine($results, Doctor::INFO, 'Robots: eigen route wint: robots.txt → Closure');
        $this->assertLine($results, Doctor::INFO, 'Healthcheck: eigen route wint: api/health → Closure');
        $this->assertLine($results, Doctor::INFO, 'Pagina\'s: eigen route wint: {path} → Closure');
        // The checks of before still say what they said.
        $this->assertLine($results, Doctor::OK, 'GET /sitemap.xml heeft een eigen route');
        $this->assertLine($results, Doctor::OK, 'GET /robots.txt heeft een eigen route');
        $this->assertLine($results, Doctor::OK, 'Catch-all voor pagina\'s uit de Hub is aanwezig ({path})');
        $this->assertLine($results, Doctor::OK, 'app.blade.php heeft meta, head, errors, body-end en analytics');
        $this->assertLine($results, Doctor::OK, 'Cookiebanner gevonden (stuurt fsc:consent)');
        $this->assertLine($results, Doctor::OK, 'Eigen foutpagina errors/404.blade.php');
        self::assertNull($this->statusOf($results, 'Artikelen en categorieën'), 'the SDK does not serve articles where the site owns the catch-all');
        self::assertNull($this->statusOf($results, 'content.auto.layout'));
    }

    public function testFixtureCMixedSiteOwnsRobotsAndAPageAndLetsTheSdkDoTheRest(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        file_put_contents($this->views . '/errors/404.blade.php', "@extends('layouts.app', ['fscStatus' => 404])");
        $this->site(static function (Router $router): void {
            $router->get('/robots.txt', fn () => response('User-agent: *', 200, ['Content-Type' => 'text/plain']));
            $router->middleware('web')->group(static function () use ($router): void {
                $router->get('/over-ons', fn () => 'over ons');
                $router->get('/contact', fn () => 'contact');
            });
        }, ['content.auto.wrap' => 'wrap max-w-5xl']);

        $results = $this->results();

        self::assertSame([], $this->failures($results));
        self::assertSame([], $this->labels($results, Doctor::WARN));
        $this->assertLine($results, Doctor::INFO, 'Robots: eigen route wint: robots.txt → Closure');
        $this->assertLine($results, Doctor::OK, 'GET /robots.txt heeft een eigen route');
        $this->assertLine($results, Doctor::OK, 'Sitemap automatisch (SDK)');
        $this->assertLine($results, Doctor::OK, 'Healthcheck automatisch (SDK)');
        $this->assertLine($results, Doctor::OK, 'Pagina\'s uit de Hub automatisch (SDK)');
        $this->assertLine($results, Doctor::INFO, 'Eigen pagina\'s van de site in de sitemap: /contact, /over-ons');
        $this->assertLine($results, Doctor::OK, 'app.blade.php heeft head en scripts');
        $this->assertLine($results, Doctor::OK, 'heeft @yield(\'content\') voor de standaardpagina\'s');
        $this->assertLine($results, Doctor::OK, 'Cookiebanner automatisch (SDK');
        $this->assertLine($results, Doctor::OK, 'Eigen foutpagina errors/404.blade.php');
        $this->assertLine($results, Doctor::OK, 'Foutpagina errors/503 van de SDK (automatisch)');
    }

    public function testFixtureDNothingAtAllFailsOnEveryMissingPiece(): void
    {
        $this->site(null, ['content.auto.enabled' => false]);

        $results = $this->results();
        $failures = $this->failures($results);

        self::assertContains('GET /sitemap.xml ontbreekt', $failures);
        self::assertContains('GET /robots.txt ontbreekt', $failures);
        self::assertContains('GET /api/health ontbreekt', $failures);
        self::assertContains('RedirectMiddleware staat niet in de web-groep', $failures);
        self::assertContains('Geen route voor pagina\'s uit de Hub: niets toont de pagina\'s van het project', $failures);
        self::assertTrue((bool) array_filter($failures, fn ($f) => str_starts_with($f, 'Geen layout met <head> gevonden')));
        $this->assertLine($results, Doctor::INFO, 'De automatische laag van de SDK staat uit (FSC_AUTO=false)');
        self::assertNull($this->statusOf($results, 'automatisch (SDK)'));

        $this->artisan('fsc:doctor')->expectsOutputToContain('De automatische laag van de SDK staat uit')->assertExitCode(1);
    }

    public function testFixtureDWithSwitchesOffEachFeatureSaysUitAndStillFailsWhereNothingTakesOver(): void
    {
        $this->site(null, [
            'content.auto.sitemap' => false,
            'content.auto.robots' => false,
            'content.auto.health' => false,
            'content.auto.pages' => false,
            'content.auto.errors' => false,
            'content.auto.redirects' => false,
            'content.auto.platform' => false,
            'content.auto.consent' => false,
            'content.auto.visitor_pages' => false,
        ]);

        $results = $this->results();

        foreach (['Sitemap staat uit (FSC_AUTO_SITEMAP=false)', 'Robots staat uit (FSC_AUTO_ROBOTS=false)', 'Healthcheck staat uit (FSC_AUTO_HEALTH=false)', 'Pagina\'s uit de Hub staan uit (FSC_AUTO_PAGES=false)', 'Foutpagina\'s van de SDK staan uit (FSC_AUTO_ERRORS=false)', 'Redirects uit de Hub staan uit (FSC_AUTO_REDIRECTS=false)', 'Platform (trustProxies, https) staat uit (FSC_AUTO_PLATFORM=false)', 'De banner van de SDK staat uit (FSC_AUTO_CONSENT=false)', 'Bezoekerspagina\'s van de Hub via het domein van de site staan uit (FSC_AUTO_VISITOR_PAGES=false)'] as $line) {
            $this->assertLine($results, Doctor::INFO, $line);
        }

        $failures = $this->failures($results);
        self::assertContains('GET /sitemap.xml ontbreekt', $failures);
        self::assertContains('GET /api/health ontbreekt', $failures);
        self::assertContains('Geen route voor pagina\'s uit de Hub: niets toont de pagina\'s van het project', $failures);
    }

    public function testASiteWithOwnRoutesButPagesSwitchedOffOnlyWarnsAboutTheMissingCatchAll(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site(static function (Router $router): void {
            $router->middleware('web')->group(static fn () => $router->get('/over-ons', fn () => 'x'));
        }, ['content.auto.pages' => false]);

        $results = $this->results();

        $this->assertLine($results, Doctor::WARN, 'Geen catch-all route voor pagina\'s uit de Hub');
        self::assertNull($this->statusOf($results, 'Geen route voor pagina\'s uit de Hub'));
    }

    public function testFixtureEAWelcomeRouteIsAFailureInEveryShape(): void
    {
        $shapes = [
            'closure' => static function (Router $router): void {
                $router->get('/', fn () => view('welcome'));
            },
            'view route' => static function (Router $router): void {
                $router->view('/', 'welcome');
            },
            'controller' => static function (Router $router): void {
                $router->get('/', [WelcomeProbeController::class, 'show']);
            },
            'invokable' => static function (Router $router): void {
                $router->get('/', WelcomeProbeInvokable::class);
            },
        ];

        foreach ($shapes as $shape => $routes) {
            $this->site($routes);
            $results = $this->results();

            self::assertSame(Doctor::FAIL, $this->statusOf($results, 'GET / toont de standaard Laravel-welkomstpagina'), $shape);
            self::assertSame(['GET / toont de standaard Laravel-welkomstpagina (view welcome)'], $this->failures($results), $shape);
        }

        $this->artisan('fsc:doctor')->expectsOutputToContain('FOUT')->assertExitCode(1);
    }

    public function testAHomePageThatIsNotTheWelcomeViewIsFine(): void
    {
        $this->site(static function (Router $router): void {
            $router->get('/', fn () => view('home'));
            $router->get('/welcomeback', fn () => 'x');
        });

        self::assertNull($this->statusOf($this->results(), 'welkomstpagina'));
    }

    public function testFixtureFTwoBannersIsAWarningAndEachBannerAloneIsFine(): void
    {
        $this->layoutWith('<x-content::head />', "@include('partials.consent')\n<x-content::scripts />");
        file_put_contents($this->views . '/partials/consent.blade.php', "<script>window.dispatchEvent(new CustomEvent('fsc:consent', {detail: c}));</script>");
        $this->site();

        $results = $this->results();
        $this->assertLine($results, Doctor::WARN, 'Twee cookiebanners: een eigen banner (stuurt fsc:consent) en die van de SDK');
        self::assertSame([], $this->failures($results), 'a warning, never a failure');
        self::assertNull($this->statusOf($results, 'Cookiebanner automatisch'));
        self::assertNull($this->statusOf($results, 'Geen cookiebanner gevonden'));
        $this->artisan('fsc:doctor', ['--strict' => true])->assertExitCode(1);

        // The own banner stays and the SDK's is switched off in the tag ...
        $this->layoutWith('<x-content::head />', "@include('partials.consent')\n<x-content::scripts :consent=\"false\" />");
        $results = $this->results();
        self::assertNull($this->statusOf($results, 'Twee cookiebanners'));
        $this->assertLine($results, Doctor::OK, 'Cookiebanner gevonden (stuurt fsc:consent)');

        // ... or in the config.
        $this->layoutWith('<x-content::head />', "@include('partials.consent')\n<x-content::scripts />");
        $this->site(null, ['content.auto.consent' => false]);
        $results = $this->results();
        self::assertNull($this->statusOf($results, 'Twee cookiebanners'));
        $this->assertLine($results, Doctor::OK, 'Cookiebanner gevonden (stuurt fsc:consent)');
        $this->assertLine($results, Doctor::INFO, 'De banner van de SDK staat uit (FSC_AUTO_CONSENT=false)');
    }

    public function testAnOwnBannerWithACustomConsentEventCounts(): void
    {
        $this->layoutWith('<x-content::head />', "@include('partials.consent')");
        file_put_contents($this->views . '/partials/consent.blade.php', "<script>window.dispatchEvent(new CustomEvent('fss:consent', {detail: c}));</script>");
        $this->site(null, ['content.analytics.consent_event' => 'fss:consent']);

        $results = $this->results();
        $this->assertLine($results, Doctor::OK, 'Cookiebanner gevonden (stuurt fss:consent)');
        self::assertNull($this->statusOf($results, 'Geen cookiebanner gevonden'));

        // Without the config the custom name is not a banner of ours.
        $this->site();
        $this->assertLine($this->results(), Doctor::WARN, 'Geen cookiebanner gevonden');
    }

    public function testAnOwnBannerInResourcesJsCounts(): void
    {
        $js = $this->app->resourcePath('js');
        $existed = is_dir($js);
        @mkdir($js, 0777, true);
        $file = $js . '/consent-banner-test.js';

        try {
            $this->layoutWith('<x-content::head />', '');
            file_put_contents($file, "window.dispatchEvent(new CustomEvent('fss:consent', { detail: choice }));");
            $this->site(null, ['content.analytics.consent_event' => 'fss:consent']);

            $results = $this->results();
            $this->assertLine($results, Doctor::OK, 'Cookiebanner gevonden (stuurt fss:consent)');
            self::assertNull($this->statusOf($results, 'Geen cookiebanner gevonden'));
        } finally {
            @unlink($file);
            if (! $existed) {
                @rmdir($js);
            }
        }
    }

    public function testNoBannerAtAllIsStillAWarningAndTheHintNamesTheScriptsComponent(): void
    {
        $this->layoutWith('<x-content::head />', '');
        $this->site(static function (Router $router): void {
            $router->middleware('web')->group(static fn () => $router->get('/over-ons', fn () => 'x'));
        });

        $results = $this->results();
        $line = array_values(array_filter($results, fn ($r) => str_starts_with($r['label'], 'Geen cookiebanner gevonden')));

        self::assertCount(1, $line);
        self::assertSame(Doctor::WARN, $line[0]['status']);
        self::assertStringContainsString('<x-content::scripts />', (string) $line[0]['hint']);
    }

    public function testALayoutWithHeadAndScriptsPassesAndOneWithoutScriptsNamesWhatIsMissing(): void
    {
        $this->completeSite();
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        self::assertSame(Doctor::OK, $this->statusOf($this->results(), 'app.blade.php heeft head en scripts'));
        self::assertSame([], $this->failures($this->results()));

        $this->layoutWith('<x-content::head />', '');
        $this->assertFailure('app.blade.php mist: <x-content::scripts />');

        $this->layoutWith('', '');
        $this->assertFailure('app.blade.php mist: <x-content::head />, <x-content::scripts />');

        // <x-content::head /> outside <head> is misplaced in the new shape too.
        file_put_contents($this->views . '/layouts/app.blade.php', "<html><head></head><body><x-content::head /><x-content::scripts /></body></html>");
        $this->assertFailure('app.blade.php mist: <x-content::head /> hoort in <head>');
    }

    public function testTheLayoutOfTheSdkPagesMustExistAndPrintTheConfiguredSection(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site(null, ['content.auto.layout' => 'layouts.missing']);
        $this->assertFailure('content.auto.layout (layouts.missing) bestaat niet');

        // A layout without the section: every automatic page would render empty.
        file_put_contents($this->views . '/layouts/app.blade.php', "<html><head><x-content::head /></head><body>{{ \$slot }}<x-content::scripts /></body></html>");
        $this->site();
        $this->assertFailure("heeft geen @yield('content'): de standaardpagina's van de SDK blijven leeg");

        // The section is configurable.
        file_put_contents($this->views . '/layouts/app.blade.php', "<html><head><x-content::head /></head><body>@yield(\"main\")<x-content::scripts /></body></html>");
        $this->site(null, ['content.auto.section' => 'main']);
        self::assertSame([], $this->failures($this->results()));
        $this->assertLine($this->results(), Doctor::OK, "heeft @yield('main') voor de standaardpagina's van de SDK");

        // A site with its own catch-all does not use the SDK's views, so the layout is its own business.
        file_put_contents($this->views . '/layouts/app.blade.php', "<html><head><x-content::head /></head><body>{{ \$slot }}<x-content::scripts /></body></html>");
        $this->site(static function (Router $router): void {
            $router->get('/sitemap.xml', fn () => Sitemap::response());
            $router->get('/robots.txt', fn () => Robots::response());
            $router->get('/api/health', fn () => response('{"ok":true}', 200, ['Content-Type' => 'application/json']));
            $router->get('/{path}', fn () => 'page')->where('path', '.*');
        });
        self::assertSame([], $this->failures($this->results()));
    }

    public function testAnEmptyWrapOnASiteLayoutIsAWarningUnlessTheLayoutOrTheCssKnowsFscWrap(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site();
        $this->assertLine($this->results(), Doctor::WARN, 'staan in de terugvalbreedte (.fsc-wrap)');
        self::assertSame([], $this->failures($this->results()));

        $this->site(null, ['content.auto.wrap' => 'wrap max-w-5xl']);
        self::assertSame([], $this->labels($this->results(), Doctor::WARN));

        $this->layoutWith('<x-content::head /><style>.fsc-wrap{max-width:60rem}</style>', '<x-content::scripts />');
        $this->site();
        self::assertSame([], $this->labels($this->results(), Doctor::WARN));
    }

    public function testThePlatformLineNamesWhatTheAppSetItself(): void
    {
        // `config/trustedproxy.php` set by the app before the SDK acts: the line says the app did it.
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site(null, ['trustedproxy.proxies' => '10.0.0.1']);

        $this->assertLine($this->results(), Doctor::INFO, 'Platform: proxy\'s door de app ingesteld');
    }

    public function testNoNewFailureForAHandBuiltSiteWithoutAutomaticLayer(): void
    {
        // The site of before the automatic layer: everything by hand, the layer switched off.
        $this->site(static function (Router $router): void {
            $router->get('/sitemap.xml', fn () => Sitemap::response());
            $router->get('/robots.txt', fn () => Robots::response());
            $router->get('/api/health', fn () => response('{"ok":true}', 200, ['Content-Type' => 'application/json']));
            $router->get('/{path}', fn () => 'page')->where('path', '.*');
        }, ['content.auto.enabled' => false]);
        $this->completeSite();

        $results = $this->results();

        self::assertSame([], $this->failures($results));
        $this->assertLine($results, Doctor::INFO, 'De automatische laag van de SDK staat uit');
        $this->assertLine($results, Doctor::OK, 'GET /sitemap.xml heeft een eigen route');
        $this->assertLine($results, Doctor::OK, 'Catch-all voor pagina\'s uit de Hub is aanwezig ({path})');
    }

    // --- Sitemap, online -------------------------------------------------------------------------------------------------------------

    /**
     * Binds a fake Hub that answers by API path. `$sitemap` and `$seo` are the bodies of `/sitemap` and `/seo`; `$status` overrides the
     * status of `/sitemap`.
     *
     * @param array<string, mixed> $sitemap
     * @param array<string, mixed> $seo
     */
    private function hub(array $sitemap, array $seo, int $status = 200, array $project = ['name' => 'Voorbeeld', 'defaultLocale' => 'nl', 'locales' => ['nl'], 'modules' => ['pages', 'seo']]): FakeTransport
    {
        $http = new FakeTransport();
        $http->queue(...array_fill(0, 50, function (HubRequest $request) use ($sitemap, $seo, $status, $project): HubResponse {
            $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

            return match ($path) {
                '/project' => FakeTransport::json($project),
                '/seo' => FakeTransport::json(['seo' => $seo]),
                '/sitemap' => FakeTransport::json($status === 200 ? $sitemap : ['error' => ['code' => 'upstream', 'message' => 'x']], $status),
                default => FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404),
            };
        }));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $http]));

        return $http;
    }

    /** @return list<array{status: string, label: string, hint: ?string}> */
    private function onlineResults(): array
    {
        return (new Doctor($this->app))->run(true, true);
    }

    public function testOnlineSitemapCountsTheUrlsOfTheAutomaticSitemap(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site(static function (Router $router): void {
            $router->middleware('web')->group(static fn () => $router->get('/over-ons', fn () => 'x'));
        });
        $this->hub(['robotsIndex' => true, 'canonicalOrigin' => 'https://example.nl', 'entries' => [['path' => '/', 'lastmod' => '2026-11-01'], ['path' => '/diensten']]], ['canonicalOrigin' => 'https://example.nl', 'robotsIndex' => true]);

        $results = $this->onlineResults();

        $this->assertLine($results, Doctor::OK, 'sitemap.xml bevat 3 URL\'s');
        $this->assertLine($results, Doctor::INFO, 'Talen van het project: nl (standaard), geen prefix in de paden');
        self::assertSame([], $this->failures($results));
    }

    public function testOnlineSitemapOfAHandBuiltRouteIsCountedToo(): void
    {
        $this->completeSite();
        $this->hub(['robotsIndex' => true, 'canonicalOrigin' => 'https://example.nl', 'entries' => [['path' => '/'], ['path' => '/a']]], ['canonicalOrigin' => 'https://example.nl', 'robotsIndex' => true]);

        $this->assertLine($this->onlineResults(), Doctor::OK, 'sitemap.xml bevat 2 URL\'s');
    }

    public function testOnlineEmptySitemapWhileIndexableIsAFailure(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site();
        $this->hub(['robotsIndex' => true, 'canonicalOrigin' => 'https://example.nl', 'entries' => []], ['canonicalOrigin' => 'https://example.nl', 'robotsIndex' => true]);

        $results = $this->onlineResults();

        self::assertContains('sitemap.xml is leeg terwijl robotsIndex aan staat', $this->failures($results));
        $this->artisan('fsc:doctor', ['--skip-env' => true])->assertExitCode(0);
    }

    public function testOnlineEmptySitemapOfATestSiteIsInfoAndOfALiveSiteOnNoindexAWarning(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site();
        $seo = ['canonicalOrigin' => 'https://test.example.org', 'robotsIndex' => false];
        $this->hub(['robotsIndex' => false, 'entries' => []], $seo);

        $results = $this->onlineResults();
        $this->assertLine($results, Doctor::INFO, 'sitemap is leeg omdat robotsIndex uit staat (testsite)');
        self::assertSame([], $this->failures($results));
        self::assertNull($this->statusOf($results, 'noindex'));

        // Production, and the canonical origin is this very site: the live site still says noindex.
        $this->app['env'] = 'production';
        $this->hub(['robotsIndex' => false, 'entries' => []], ['canonicalOrigin' => 'https://example.nl', 'robotsIndex' => false]);
        $results = $this->onlineResults();
        $this->assertLine($results, Doctor::INFO, 'sitemap is leeg omdat robotsIndex uit staat (testsite)');
        $this->assertLine($results, Doctor::WARN, 'de live site staat nog op noindex');

        // Production, but another host: a test site on its own address, nothing to warn about.
        $this->hub(['robotsIndex' => false, 'entries' => []], $seo);
        self::assertNull($this->statusOf($this->onlineResults(), 'noindex'));
    }

    // --- Content language of a multilingual project, online -----------------------------------------------------------------------

    /** @var array<string, mixed> */
    private const MULTILINGUAL = ['name' => 'Voorbeeld', 'defaultLocale' => 'nl', 'locales' => ['nl', 'en'], 'modules' => ['pages', 'seo']];

    private function ownRoutesSite(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site(static function (Router $router): void {
            $router->middleware('web')->group(static fn () => $router->get('/over-ons', fn () => 'x'));
        }, ['content.auto.pages' => false]);
    }

    public function testAMultilingualProjectWhoseSiteNeverSetsAContentLocaleIsAWarning(): void
    {
        $this->ownRoutesSite();
        $this->hub(['robotsIndex' => true, 'entries' => [['path' => '/']]], ['robotsIndex' => true], 200, self::MULTILINGUAL);

        $results = $this->onlineResults();

        $this->assertLine($results, Doctor::WARN, 'Het project is meertalig (nl, en), maar de site zet nergens een inhoudstaal');
        self::assertNull($this->statusOf($results, 'Meertalig project'));
        $this->assertLine($results, Doctor::INFO, 'Talen van het project: nl (standaard), met pad-prefix: en');
    }

    public function testTheWarningNamesTheFixAndTheSectionOfAgentsMd(): void
    {
        $this->ownRoutesSite();
        $this->hub(['entries' => []], [], 200, self::MULTILINGUAL);

        foreach ($this->onlineResults() as $result) {
            if ($result['status'] === Doctor::WARN && str_contains($result['label'], 'inhoudstaal')) {
                self::assertStringContainsString('Content::setLocale($locale)', (string) $result['hint']);
                self::assertStringContainsString('Meertalige teksten', (string) $result['hint']);

                return;
            }
        }

        self::fail('No warning about the content locale.');
    }

    public function testAMultilingualProjectOnTheSdkPagesIsFine(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site();
        $this->hub(['robotsIndex' => true, 'entries' => [['path' => '/']]], ['robotsIndex' => true], 200, self::MULTILINGUAL);

        $results = $this->onlineResults();

        $this->assertLine($results, Doctor::OK, 'Meertalig project (nl, en): de pagina\'s van de SDK zetten de inhoudstaal zelf');
        self::assertNull($this->statusOf($results, 'nergens een inhoudstaal'));
    }

    public function testAViewOrRouteThatSetsTheContentLocaleSilencesTheWarning(): void
    {
        $this->ownRoutesSite();
        file_put_contents($this->views . '/partials/lang.blade.php', "@php(\\FullStackStudio\\Content\\Laravel\\Facades\\Content::setLocale(app()->getLocale()))");
        $this->hub(['entries' => []], [], 200, self::MULTILINGUAL);

        $results = $this->onlineResults();

        $this->assertLine($results, Doctor::OK, 'Meertalig project (nl, en): de site zet een inhoudstaal');
        self::assertNull($this->statusOf($results, 'nergens een inhoudstaal'));
    }

    public function testASingleLanguageProjectNeverGetsTheContentLocaleLine(): void
    {
        $this->ownRoutesSite();
        $this->hub(['entries' => []], [], 200);

        $results = $this->onlineResults();

        self::assertNull($this->statusOf($results, 'inhoudstaal'));
        self::assertNull($this->statusOf($results, 'Meertalig project'));
    }

    public function testOfflineNeverChecksTheContentLocale(): void
    {
        $this->ownRoutesSite();
        $this->hub(['entries' => []], [], 200, self::MULTILINGUAL);

        self::assertNull($this->statusOf($this->results(), 'inhoudstaal'));
    }

    public function testOnlineSitemapWhenTheHubFailsIsAFailure(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site();
        $this->hub([], ['canonicalOrigin' => 'https://example.nl'], 500);

        $results = $this->onlineResults();

        self::assertContains('GET /sitemap.xml geeft 503', $this->failures($results));
    }

    public function testOfflineNeverAsksTheHubForTheSitemap(): void
    {
        $this->layoutWith('<x-content::head />', '<x-content::scripts />');
        $this->site();
        $http = $this->hub(['robotsIndex' => true, 'entries' => []], []);

        $this->results();

        self::assertSame([], $http->requests);
    }

    public function testPageBlocksWithoutWrapWarnsAndWithWrapPasses(): void
    {
        $this->completeSite();

        file_put_contents($this->views . '/page.blade.php', '<x-content::page-blocks :blocks="$page[\'blocks\'] ?? []" :media="$page[\'media\'] ?? null" />');
        $results = $this->results();
        self::assertSame(Doctor::WARN, $this->statusOf($results, '<x-content::page-blocks> zonder wrap in '));
        self::assertSame([], $this->failures($results), 'a warning, never a failure');
        $hint = array_values(array_filter($results, fn ($r) => str_contains($r['label'], 'zonder wrap')))[0]['hint'];
        self::assertStringContainsString('wrap="wrap"', $hint);
        self::assertStringContainsString('A18', $hint);

        file_put_contents($this->views . '/page.blade.php', '<x-content::page-blocks :blocks="$page[\'blocks\']" wrap="wrap max-w-5xl" />');
        $results = $this->results();
        self::assertSame(Doctor::OK, $this->statusOf($results, '<x-content::page-blocks> krijgt wrap'));
        self::assertNull($this->statusOf($results, 'zonder wrap'));
    }

    public function testPageBlocksWithoutWrapIsFineWhenTheSiteViewsCoverEveryDefaultType(): void
    {
        $this->completeSite();

        $types = array_map(static fn (string $f): string => basename($f, '.blade.php'), glob(__DIR__ . '/../../resources/views/blocks/*.blade.php'));
        $views = implode(', ', array_map(static fn (string $t): string => "'{$t}' => 'blocks.{$t}'", $types));
        file_put_contents($this->views . '/page.blade.php', "<x-content::page-blocks :blocks=\"\$b\" :views=\"[{$views}]\" />");

        $results = $this->results();
        self::assertSame(Doctor::OK, $this->statusOf($results, 'zonder wrap, maar de site heeft een eigen view'));
        self::assertNull($this->statusOf($results, 'zonder wrap in'));
    }

    public function testASiteWithoutPageBlocksGetsNoWrapLine(): void
    {
        $this->completeSite();

        self::assertNull($this->statusOf($this->results(), 'page-blocks'));
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
        self::assertSame(Doctor::OK, $this->statusOf($results, 'Voorbeeld vanuit het portaal staat aan'));
        self::assertSame([], array_values(array_filter($results, fn ($r) => $r['status'] === Doctor::WARN)), 'a complete site has no warnings');

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

    public function testAFreshSiteWithoutWebhookSecretHasNoWebhookFailureOfItsOwn(): void
    {
        // A fresh `content:install` site: no secret yet, so the route is not registered (it follows the secret) and the doctor must not fail on it.
        $this->site(null, ['content.webhook.enabled' => false, 'content.webhook.secret' => '']);

        $skipped = $this->results(false);
        $this->assertLine($skipped, Doctor::INFO, 'Webhookroute volgt zodra FSC_WEBHOOK_SECRET gezet is');
        self::assertNull($this->statusOf($skipped, 'Webhookroute ontbreekt'));
        self::assertNotContains('Webhookroute ontbreekt', $this->failures($skipped));

        // Before going live (without --skip-env) the missing secret is the one failure, with the way out in the hint.
        $this->assertLine($this->results(), Doctor::FAIL, 'FSC_WEBHOOK_SECRET ontbreekt');
        self::assertNull($this->statusOf($this->results(), 'Webhookroute ontbreekt'));
    }

    public function testASecretRegistersTheWebhookRouteAndAnExplicitOffWithASecretIsTheFailure(): void
    {
        // The route follows the secret, whatever FSC_WEBHOOK_ENABLED did before: enabled is derived from the secret in the package config.
        $this->site(null, ['content.webhook.enabled' => true, 'content.webhook.secret' => 'fsc_whsec_abc']);
        $this->assertLine($this->results(), Doctor::OK, 'Webhookroute POST /content/webhook is geregistreerd');
        $this->assertLine($this->results(), Doctor::OK, 'FSC_WEBHOOK_SECRET is gezet');

        $this->site(null, ['content.webhook.enabled' => false, 'content.webhook.secret' => 'fsc_whsec_abc']);
        $this->assertLine($this->results(), Doctor::FAIL, 'Webhookroute ontbreekt');
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
        // The route is on by default: remove it to simulate FSC_FORMS_ENABLED=false while a view uses the component.
        $off = new \Illuminate\Routing\RouteCollection();
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if ($route->getName() !== 'content.form') {
                $off->add($route);
            }
        }
        $this->app['router']->setRoutes($off);
        file_put_contents($this->views . '/contact.blade.php', '<x-content::form form-key="contact" />');

        self::assertSame(Doctor::FAIL, $this->statusOf($this->results(), 'formulierroute staat uit'));
    }

    public function testFullRequestUrlInAViewIsAWarning(): void
    {
        $this->completeSite();
        file_put_contents($this->views . '/share.blade.php', '<a href="https://x.com/share?u={{ urlencode(request()->fullUrl()) }}">Deel</a>');

        self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'gebruikt de volledige URL met query'));
    }

    public function testABannerThatShowsInThePreviewIsAWarning(): void
    {
        $this->completeSite();
        file_put_contents($this->views . '/partials/consent.blade.php', "<script>window.dispatchEvent(new CustomEvent('fsc:consent', {detail: c}));</script>");

        $results = $this->results();
        self::assertSame(Doctor::WARN, $this->statusOf($results, 'Cookiebanner verbergt zich niet in het voorbeeld'));
        self::assertSame([], $this->failures($results));

        file_put_contents($this->views . '/partials/consent.blade.php', "<script>if (!window.fscPreviewMode) { window.dispatchEvent(new CustomEvent('fsc:consent')); }</script>");
        self::assertNull($this->statusOf($this->results(), 'verbergt zich niet'));
    }

    public function testFramingBlockedOutsidePhpIsAWarning(): void
    {
        $this->completeSite();
        file_put_contents($this->public . '/.htaccess', "Header always set X-Frame-Options SAMEORIGIN\n");

        self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'X-Frame-Options of frame-ancestors buiten PHP in'));
    }

    public function testACspWithoutSiteCspMergeIsAWarningAndOneThroughItIsOk(): void
    {
        $this->completeSite();
        $app = $this->views . '/../fsc-doctor-app-' . bin2hex(random_bytes(3));
        mkdir($app . '/Http/Middleware', 0777, true);
        $this->app->useAppPath($app);

        self::assertSame(Doctor::INFO, $this->statusOf($this->results(), 'Geen Content-Security-Policy in de PHP-code'));
        self::assertSame(Doctor::OK, $this->statusOf($this->results(), 'Nieuwsbriefroute'));

        file_put_contents($app . '/Http/Middleware/Csp.php', '<?php $response->headers->set("Content-Security-Policy", "default-src \'self\'");');
        self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'Content-Security-Policy zonder Content::siteCspMerge()'));

        file_put_contents($app . '/Http/Middleware/Csp.php', '<?php $response->headers->set("Content-Security-Policy", Content::siteCspMerge("default-src \'self\'"));');
        self::assertSame(Doctor::OK, $this->statusOf($this->results(), 'Content-Security-Policy gaat door Content::siteCspMerge()'));

        // A CSP middleware in the global stack would also run on /_fsc/h; in the web group it does not.
        $bootstrap = $this->app->basePath('bootstrap/app.php');
        $original = is_file($bootstrap) ? file_get_contents($bootstrap) : null;
        file_put_contents($app . '/Http/Middleware/Csp.php', '<?php class Csp {} // $response->headers->set("Content-Security-Policy", Content::siteCspMerge($p));');
        try {
            file_put_contents($bootstrap, '<?php return fn ($middleware) => $middleware->append(\App\Http\Middleware\Csp::class);');
            self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'CSP-middleware Csp staat in de globale middleware'));
            file_put_contents($bootstrap, '<?php return fn ($middleware) => $middleware->web(append: [\App\Http\Middleware\Csp::class]);');
            self::assertNull($this->statusOf($this->results(), 'globale middleware'));
        } finally {
            if ($original === null) {
                @unlink($bootstrap);
            } else {
                file_put_contents($bootstrap, $original);
            }
        }

        file_put_contents($this->public . '/.htaccess', "Header always set Content-Security-Policy \"default-src 'self'\"\n");
        self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'Content-Security-Policy buiten PHP in'));

        exec('rm -rf ' . escapeshellarg($app));
    }

    public function testAFullPageCachePackageIsAWarning(): void
    {
        $this->completeSite();
        self::assertNull($this->statusOf($this->results(), 'Paginacache-pakket'));

        $base = sys_get_temp_dir() . '/fsc-doctor-base-' . bin2hex(random_bytes(4));
        mkdir($base);
        file_put_contents($base . '/composer.lock', json_encode(['packages' => [['name' => 'spatie/laravel-responsecache', 'version' => '7.0.0']]], JSON_PRETTY_PRINT));
        $original = $this->app->basePath();
        $this->app->setBasePath($base);

        try {
            self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'Paginacache-pakket spatie/laravel-responsecache gevonden'));
        } finally {
            $this->app->setBasePath($original);
            exec('rm -rf ' . escapeshellarg($base));
        }
    }

    public function testTheResponseHeadersLineShowsBothSwitches(): void
    {
        $this->completeSite();

        self::assertSame(Doctor::INFO, $this->statusOf($this->results(), 'preconnect naar de Hub aan (FSC_PRECONNECT), SDK-versie aan'));

        $this->app['config']->set('content.response_headers.preconnect', false);
        self::assertSame(Doctor::INFO, $this->statusOf($this->results(), 'preconnect naar de Hub uit (FSC_PRECONNECT), SDK-versie aan'));
    }

    public function testThePreviewLineFollowsTheKillSwitchAndTheToken(): void
    {
        $this->completeSite();

        $this->app['config']->set('content.preview.enabled', false);
        self::assertSame(Doctor::INFO, $this->statusOf($this->results(), 'Voorbeeld vanuit het portaal staat uit'));

        $this->app['config']->set('content.preview.enabled', true);
        $this->app['config']->set('content.token', 'fsc_pub_x');
        self::assertSame(Doctor::WARN, $this->statusOf($this->results(), 'Voorbeeld vanuit het portaal werkt niet'));
    }

    // --- Page builder canvas and layouts (SDK 0.31) ---------------------------------------------------------------------------------

    public function testTheCanvasLinePassesOnACorrectSetup(): void
    {
        $this->completeSite();

        $results = $this->results();

        self::assertSame(Doctor::OK, $this->statusOf($results, 'Paginabouwer kan op de pagina bewerken'));
        self::assertSame([], $this->failures($results));
    }

    public function testAMissingPreviewScriptRouteIsAFailureThatNamesTheRouteCache(): void
    {
        $this->completeSite();
        // The route exists after boot; a site whose route cache predates the update does not have it. Rebuild the collection without it.
        $routes = new \Illuminate\Routing\RouteCollection();

        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getName() !== 'content.preview.script') {
                $routes->add($route);
            }
        }

        $routes->refreshNameLookups();
        $this->app['router']->setRoutes($routes);

        $results = $this->results();
        $this->assertLine($results, Doctor::FAIL, 'Voorbeeldscript wordt niet geserveerd');
        self::assertNull($this->statusOf($results, 'Paginabouwer kan op de pagina bewerken'));
        $hint = array_values(array_filter($results, static fn (array $r): bool => str_contains($r['label'], 'Voorbeeldscript')))[0]['hint'];
        self::assertStringContainsString('route:clear', (string) $hint);
    }

    public function testAnotherPreviewPathIsAWarning(): void
    {
        $this->completeSite();
        $this->app['config']->set('content.preview.path', 'voorbeeld');

        $this->assertLine($this->results(), Doctor::WARN, 'FSC_PREVIEW_PATH wijkt af');
    }

    public function testNoCanvasLineWhenThePreviewIsOffOnPurpose(): void
    {
        $this->completeSite();
        $this->app['config']->set('content.preview.enabled', false);

        $results = $this->results();
        self::assertNull($this->statusOf($results, 'Paginabouwer kan op de pagina bewerken'));
        self::assertNull($this->statusOf($results, 'Voorbeeldscript'));
    }

    public function testASiteViewForATypeWithLayoutsWithoutADeclarationIsAWarning(): void
    {
        $this->completeSite();
        mkdir($this->views . '/blocks', 0777, true);
        file_put_contents($this->views . '/blocks/cta.blade.php', '<section class="fsc-block">{{ $block["heading"] ?? "" }}</section>');
        $this->app['config']->set('content.blocks.cta', 'blocks.cta');

        $results = $this->results();
        $this->assertLine($results, Doctor::WARN, 'Eigen view voor blok cta, maar content.block_layouts heeft geen regel voor cta');
        $this->assertLine($results, Doctor::INFO, 'Eigen blokview zonder $edit-markeringen: cta');
        self::assertSame([], $this->failures($results));
    }

    public function testADeclaredSiteViewPassesAndAnEmptyDeclarationConfirmsNoLayouts(): void
    {
        $this->completeSite();
        mkdir($this->views . '/blocks', 0777, true);
        file_put_contents($this->views . '/blocks/cta.blade.php', '<section class="fsc-block {{ $look }}"><h2{!! $edit("heading") !!}>{{ $block["heading"] ?? "" }}</h2></section>');
        file_put_contents($this->views . '/blocks/hero.blade.php', '<section {!! $edit("heading") !!}></section>');
        $this->app['config']->set('content.blocks.cta', 'blocks.cta');
        $this->app['config']->set('content.blocks.hero', 'blocks.hero');
        $this->app['config']->set('content.block_layouts', ['cta' => ['standard', 'split'], 'hero' => []]);

        $results = $this->results();
        $this->assertLine($results, Doctor::OK, 'Eigen blokviews (cta, hero): layouts zijn gedeclareerd');
        self::assertSame([], $this->labels(array_values(array_filter($results, static fn (array $r): bool => str_contains($r['label'], 'blok'))), Doctor::WARN));
        self::assertNull($this->statusOf($results, 'zonder $edit-markeringen'));
    }

    public function testADeclarationForAnUnknownTypeOrLayoutIsAWarning(): void
    {
        $this->completeSite();
        $this->app['config']->set('content.block_layouts', ['carousel' => ['standard'], 'cta' => ['standard', 'diagonal']]);

        $results = $this->results();
        $this->assertLine($results, Doctor::WARN, 'content.block_layouts.carousel');
        $this->assertLine($results, Doctor::WARN, 'content.block_layouts.cta: onbekende layout diagonal');
    }

    public function testASiteViewInThePageBlocksViewsPropCountsToo(): void
    {
        $this->completeSite();
        mkdir($this->views . '/blocks', 0777, true);
        file_put_contents($this->views . '/blocks/faq.blade.php', '<div>{{ $block["heading"] ?? "" }}</div>');
        file_put_contents($this->views . '/page.blade.php', "<x-content::page-blocks :blocks=\"\$page['blocks']\" wrap=\"wrap\" :views=\"['faq' => 'blocks.faq']\" />");

        $this->assertLine($this->results(), Doctor::WARN, 'Eigen view voor blok faq');
    }

    public function testAViewsPropFromAVariableInTheSameViewIsRead(): void
    {
        $this->completeSite();
        mkdir($this->views . '/blocks', 0777, true);
        file_put_contents($this->views . '/blocks/faq.blade.php', '<div>{{ $block["heading"] ?? "" }}</div>');
        file_put_contents($this->views . '/page.blade.php', "@php(\$views = ['faq' => 'blocks.faq'])\n<x-content::page-blocks :blocks=\"\$page['blocks']\" wrap=\"wrap\" :views=\"\$views\" />");

        $results = $this->results();
        $this->assertLine($results, Doctor::WARN, 'Eigen view voor blok faq');
        self::assertNull($this->statusOf($results, 'zijn niet te lezen'));
        self::assertNull($this->statusOf($results, 'Blokken hebben geen eigen views'));
    }

    public function testAViewsPropFromAControllerVariableAsksForContentBlocks(): void
    {
        $this->completeSite();
        file_put_contents($this->views . '/page.blade.php', '<x-content::page-blocks :blocks="$page[\'blocks\']" wrap="wrap" :views="$views" />');

        $results = $this->results();
        $this->assertLine($results, Doctor::WARN, 'Blokviews via de views-prop van <x-content::page-blocks> in ');
        $line = array_values(array_filter($results, fn ($r) => str_contains($r['label'], 'zijn niet te lezen')))[0];
        self::assertStringContainsString('($views)', $line['label']);
        self::assertStringContainsString("'blocks'", $line['hint']);
        self::assertSame([], $this->failures($results), 'a warning, never a failure');
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
            FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => 'https://example.nl', 'entries' => [['path' => '/']]]),
        );
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'transport' => $http]));

        $results = (new Doctor($this->app))->run(true, true);

        self::assertSame(Doctor::OK, $this->statusOf($results, 'De Hub antwoordt met deze token (project Voorbeeld)'));
        self::assertSame(Doctor::INFO, $this->statusOf($results, 'Modules aan: pages, forms, analytics'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'canonicalOrigin is https://example.nl'));
        self::assertSame(Doctor::OK, $this->statusOf($results, 'sitemap.xml bevat 1 URL'));
    }

    // --- Visitor pages on the site's domain (plan sdk-out-of-the-box, task 20) -------------------------------------------------------

    public function testFixtureVisitorPathsReachTheProxyAndTheVerificationRoute(): void
    {
        $this->site(static function (Router $router): void {
            // Near misses that do not collide: a POST on a table path, a path under `a/` that is not in the table, an ordinary page.
            $router->middleware('web')->group(static function () use ($router): void {
                $router->post('/t/over-ons', fn () => 'x');
                $router->get('/a/over-ons', fn () => 'x');
                $router->get('/afspraak', fn () => 'x');
            });
        });

        $results = $this->results();

        $this->assertLine($results, Doctor::OK, 'Bezoekerspagina\'s: routes actief (proxy 1');
        $this->assertLine($results, Doctor::OK, 'Bezoekerspagina\'s: geen route of middleware van de site op de gereserveerde paden');
        self::assertSame([], $this->failures($results));
        // The sample requests never reach a real Hub: the stand-in is gone again afterwards.
        self::assertInstanceOf(\FullStackStudio\Content\Tests\Support\NoNetworkUpstream::class, $this->app->make(\FullStackStudio\Content\Laravel\Visitor\Upstream::class));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function siteRoutesOnVisitorPaths(): array
    {
        return [
            'an identical URI replaces the proxy route' => ['/t/{token?}', 'een eigen route van de site valt onder de bezoekerspaden (t/{token?}'],
            'a static page under a visitor path is never reached' => ['/t/over-ons', 'een eigen route van de site valt onder de bezoekerspaden (t/over-ons'],
            'a parameter under a visitor path' => ['/a/b/{naam}', 'een eigen route van de site valt onder de bezoekerspaden (a/b/{naam}'],
            'the returns path' => ['/r/{id?}', 'een eigen route van de site valt onder de bezoekerspaden (r/{id?}'],
            'a file link' => ['/api/v1/downloads/{x}', 'een eigen route van de site valt onder de bezoekerspaden (api/v1/downloads/{x}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('siteRoutesOnVisitorPaths')]
    public function testASiteRouteOnAVisitorPathIsAFailure(string $uri, string $expected): void
    {
        $this->site(static function (Router $router) use ($uri): void {
            $router->get($uri, fn () => 'site');
        });

        $failures = array_values(array_filter($this->failures($this->results()), fn (string $label): bool => str_contains($label, 'Bezoekerspagina\'s')));

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertStringContainsString($expected, $failures[0]);
        self::assertNull($this->statusOf($this->results(), 'geen route of middleware van de site op de gereserveerde paden'));
        $this->artisan('fsc:doctor', ['--skip-env' => true])->assertExitCode(1);
    }

    public function testASiteMiddlewareThatRedirectsAVisitorPathIsAFailure(): void
    {
        $this->site(static function (Router $router): void {
            // `app()` is the application being built (`$this->app` still is the previous one while the routes load).
            app(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(DoctorTestPathMiddleware::class);
        });

        $failures = array_values(array_filter($this->failures($this->results()), fn (string $label): bool => str_contains($label, 'Bezoekerspagina\'s')));

        // `/a/l/…` is moved and the file path is blocked; the other paths are left alone.
        self::assertContains('Bezoekerspagina\'s: een middleware van de site stuurt /a/l/fsc-doctor om naar https://example.nl/elders', $failures);
        self::assertContains('Bezoekerspagina\'s: /api/v1/downloads/fsc-doctor geeft 403 in plaats van de bezoekerspagina (een middleware van de site blokkeert of vervangt het antwoord)', $failures);
        self::assertCount(2, $failures, implode(' | ', $failures));
    }

    public function testSwitchedOffTheShadowCheckStaysQuiet(): void
    {
        $this->site(static function (Router $router): void {
            $router->get('/t/over-ons', fn () => 'site');
        }, ['content.auto.visitor_pages' => false]);

        $results = $this->results();

        $this->assertLine($results, Doctor::INFO, 'Bezoekerspagina\'s van de Hub via het domein van de site staan uit (FSC_AUTO_VISITOR_PAGES=false)');
        self::assertNull($this->statusOf($results, 'gereserveerde paden'));
        self::assertSame([], array_values(array_filter($this->failures($results), fn (string $label): bool => str_contains($label, 'Bezoekerspagina\'s'))));
    }

    /** @param array<string, mixed>|null $siteProxy @param list<string> $modules */
    private function hubWithSiteProxy(?array $siteProxy, array $modules = ['pages', 'seo']): void
    {
        $project = ['name' => 'Voorbeeld', 'defaultLocale' => 'nl', 'locales' => ['nl'], 'modules' => $modules];

        if ($siteProxy !== null) {
            $project['siteProxy'] = $siteProxy;
        }

        $this->hub(['robotsIndex' => true, 'canonicalOrigin' => 'https://example.nl', 'entries' => [['path' => '/']]], ['canonicalOrigin' => 'https://example.nl', 'robotsIndex' => true], 200, $project);
    }

    public function testOnlineAVerifiedSiteIsOkWithTheDateInDutchTime(): void
    {
        $this->site();
        $this->hubWithSiteProxy(['origin' => 'https://example.nl', 'verified' => true, 'verifiedAt' => '2026-11-23T10:30:00Z', 'proxyVersion' => 1, 'sdkVersion' => '0.30.0', 'lastProbeAt' => '2026-11-23T10:30:00Z', 'failedProbes' => 0, 'linksOnSite' => true]);

        $results = $this->onlineResults();

        $this->assertLine($results, Doctor::OK, 'Bezoekerspagina\'s: geverifieerd door de Hub sinds 23-11-2026 11:30 (https://example.nl, proxy 1)');
        self::assertSame([], $this->failures($results));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unverifiedStates(): array
    {
        return [
            'never probed' => [['origin' => 'https://example.nl', 'verified' => false, 'verifiedAt' => null, 'failedProbes' => 0], 'na een wijziging van de canonicalOrigin en daarna elke dag'],
            'probes without an answer' => [['origin' => 'https://example.nl', 'verified' => false, 'verifiedAt' => null, 'failedProbes' => 2], 'kreeg 2x geen bevestiging terug'],
            'no canonical origin' => [['origin' => null, 'verified' => false, 'verifiedAt' => null, 'failedProbes' => 0], 'Zet de canonicalOrigin'],
        ];
    }

    /** @param array<string, mixed> $siteProxy */
    #[\PHPUnit\Framework\Attributes\DataProvider('unverifiedStates')]
    public function testOnlineASiteTheHubDidNotVerifyIsInfoWithTheReason(array $siteProxy, string $hint): void
    {
        $this->site();
        $this->hubWithSiteProxy($siteProxy);

        $results = $this->onlineResults();

        $this->assertLine($results, Doctor::INFO, 'Bezoekerspagina\'s: nog niet geverifieerd door de Hub');
        $line = array_values(array_filter($results, fn (array $r): bool => str_contains($r['label'], 'nog niet geverifieerd')))[0];
        self::assertStringContainsString($hint, (string) $line['hint']);
        self::assertSame([], $this->failures($results));
        self::assertSame([], $this->labels($results, Doctor::WARN));
    }

    public function testOnlineAnOlderHubWithoutTheStatusIsInfo(): void
    {
        $this->site();
        $this->hubWithSiteProxy(null);

        $this->assertLine($this->onlineResults(), Doctor::INFO, 'Bezoekerspagina\'s: de Hub geeft geen verificatiestatus');
    }

    public function testOnlineSwitchedOffButStillVerifiedAtTheHubIsAWarning(): void
    {
        $this->site(null, ['content.auto.visitor_pages' => false]);
        $this->hubWithSiteProxy(['origin' => 'https://example.nl', 'verified' => true, 'verifiedAt' => '2026-11-01T08:00:00Z', 'proxyVersion' => 1]);

        $results = $this->onlineResults();
        $this->assertLine($results, Doctor::WARN, 'Bezoekerspagina\'s staan uit, maar de Hub houdt deze site nog voor geverifieerd');

        $this->hubWithSiteProxy(['origin' => 'https://example.nl', 'verified' => false]);
        self::assertNull($this->statusOf($this->onlineResults(), 'geverifieerd'));
    }

    public function testOnlineTheUploadLimitIsComparedWithTheHubTicketFileLimit(): void
    {
        $this->site();
        $this->hubWithSiteProxy(['verified' => false, 'origin' => 'https://example.nl'], ['pages', 'tickets']);

        try {
            putenv('FSC_UPLOAD_MAX_MB=8');
            $results = $this->onlineResults();
            $this->assertLine($results, Doctor::WARN, 'FSC_UPLOAD_MAX_MB is 8 MB, onder de 10 MB per bijlage die een ticket toestaat');

            putenv('FSC_UPLOAD_MAX_MB=10');
            $this->assertLine($this->onlineResults(), Doctor::OK, 'FSC_UPLOAD_MAX_MB is 10 MB: genoeg voor de bijlagen van tickets');

            putenv('FSC_UPLOAD_MAX_MB');
            // Unset: the image's default of 20 MB.
            $this->assertLine($this->onlineResults(), Doctor::OK, 'FSC_UPLOAD_MAX_MB is 20 MB');

            // Not a number: the entrypoint refuses to start, nothing for the doctor to compare.
            putenv('FSC_UPLOAD_MAX_MB=veel');
            self::assertNull($this->statusOf($this->onlineResults(), 'FSC_UPLOAD_MAX_MB'));
        } finally {
            putenv('FSC_UPLOAD_MAX_MB');
        }
    }

    public function testOnlineWithoutTheTicketsModuleTheUploadLimitIsNotChecked(): void
    {
        $this->site();
        $this->hubWithSiteProxy(['verified' => false, 'origin' => 'https://example.nl']);

        try {
            putenv('FSC_UPLOAD_MAX_MB=2');
            self::assertNull($this->statusOf($this->onlineResults(), 'FSC_UPLOAD_MAX_MB'));
        } finally {
            putenv('FSC_UPLOAD_MAX_MB');
        }
    }

    public function testOfflineNeverAsksTheHubForTheVerification(): void
    {
        $this->site();
        $this->hubWithSiteProxy(['verified' => true, 'origin' => 'https://example.nl']);

        $results = $this->results();

        self::assertNull($this->statusOf($results, 'geverifieerd'));
        self::assertNull($this->statusOf($results, 'FSC_UPLOAD_MAX_MB'));
    }
}

/** Probes for the welcome route detection: never called, only read. */
final class WelcomeProbeController
{
    public function show(): string
    {
        return view('welcome')->render();
    }
}

final class WelcomeProbeInvokable
{
    public function __invoke(): string
    {
        return view('welcome')->render();
    }
}

/** A site's global middleware that moves one visitor path and blocks another. */
final class DoctorTestPathMiddleware
{
    public function handle(\Illuminate\Http\Request $request, \Closure $next): mixed
    {
        if (str_starts_with($request->path(), 'a/l/')) {
            return redirect('https://example.nl/elders');
        }

        if (str_starts_with($request->path(), 'api/v1/downloads/')) {
            return response('verboden', 403);
        }

        return $next($request);
    }
}
