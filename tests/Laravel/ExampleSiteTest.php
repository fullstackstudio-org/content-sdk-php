<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Laravel\Doctor;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Middleware\TrustProxies;

/**
 * End to end: `examples/laravel` (its routes, controllers, views and config, no copy) on a fake Hub. The example holds a layout with two
 * components, a theme block, one designed page and the member example; everything else (pages, articles, legal texts, sitemap, robots, health,
 * errors, banner, webhook) has to come from the package. Proves the zero-wiring claim and that `fsc:doctor` finds nothing to fix.
 */
final class ExampleSiteTest extends FakeHubTestCase
{
    private const EXAMPLE = __DIR__ . '/../../../../examples/laravel';

    private static bool $autoloadRegistered = false;

    protected function setUp(): void
    {
        if (! is_dir(self::EXAMPLE . '/app')) {
            self::markTestSkipped('The examples/laravel directory is not part of this checkout.');
        }

        // The example's classes are not in this package's autoloader: map its `App\` namespace once.
        if (! self::$autoloadRegistered) {
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'App\\')) {
                    $file = self::EXAMPLE . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                    is_file($file) && require_once $file;
                }
            });
            self::$autoloadRegistered = true;
        }

        parent::setUp();

        $this->withoutVite();
        // What the example's AppServiceProvider does: its own strings (`site::site.*`: nav, footer, the contact page).
        $this->app['translator']->addNamespace('site', self::EXAMPLE . '/resources/lang');
        $this->app['view']->getFinder()->prependLocation(self::EXAMPLE . '/resources/views');
        $this->app['config']->set('view.paths', [self::EXAMPLE . '/resources/views']);

        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl', 'modules' => ['pages', 'articles', 'forms', 'seo', 'legal']];
        $this->legal['privacy_statement'] = ['kind' => 'privacy_statement', 'title' => 'Privacyverklaring', 'bodyHtml' => '<p>Wij bewaren niets.</p>'];
        $this->page('/', 'nl', ['title' => 'Welkom', 'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Home van de Hub.</p>']]]);
        $this->page('over-ons', 'nl', ['title' => 'Over ons', 'blocks' => []]);
        $this->article('eerste-bericht', 'nl', ['title' => 'Eerste bericht', 'bodyHtml' => '<p>Tekst.</p>']);
    }

    protected function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

        return match ($path) {
            // What the Hub adds for these two: the sitemap entries (pages and articles with their paths) and the cookie banner settings.
            '/sitemap' => FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => 'https://klant.test', 'entries' => [['path' => '/', 'locale' => 'nl'], ['path' => '/over-ons', 'locale' => 'nl'], ['path' => '/blog/eerste-bericht', 'locale' => 'nl']]]),
            '/consent' => FakeTransport::json(['consent' => ['version' => '1', 'bannerText' => 'Wij gebruiken cookies.', 'categories' => ['analytics']]]),
            '/forms/contact' => FakeTransport::json(['form' => ['key' => 'contact', 'name' => 'Contact', 'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true, 'maxLength' => 100]], 'honeypotField' => 'website', 'successMessage' => 'Bedankt!', 'confirmEnabled' => false, 'turnstileSiteKey' => null]]),
            default => parent::answer($request),
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TrustProxies::flushState();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // What a real site has in its runtime env and in `config/content.php` (the example's own file, loaded as the app would).
        $example = require self::EXAMPLE . '/config/content.php';
        $app['config']->set('content.auto', array_replace_recursive($app['config']->get('content.auto', []), $example['auto']));
        $app['config']->set('content.blocks', $example['blocks']);
        $app['config']->set('content.block_layouts', $example['block_layouts']);
        $app['config']->set('content.auto.redirects', true);
        $app['config']->set('app.url', 'https://voorbeeld.test');
        $app['config']->set('session.driver', 'file');
    }

    protected function defineRoutes($router): void
    {
        // `bootstrap/app.php` of the example loads this file in the `web` group (`withRouting(web: ...)`).
        $router->middleware('web')->group(self::EXAMPLE . '/routes/web.php');
    }

    public function testTheHomePageIsTheHubPageWithPathSlash(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Home van de Hub.', false);
        $response->assertSee('<title>Welkom', false);
        self::assertSame(1, substr_count($response->getContent(), 'rel="canonical"'));
        // The layout of the example: the header it brings itself, and the banner and scripts of the package.
        $response->assertSee('class="brand"', false);
    }

    public function testPagesArticlesTheArticleListAndLegalTextsComeFromThePackage(): void
    {
        $this->get('/over-ons')->assertOk()->assertSee('<title>Over ons', false);
        $this->get('/blog/eerste-bericht')->assertOk()->assertSee('Eerste bericht')->assertSee('Tekst.', false);
        $this->get('/blog')->assertOk()->assertSee('Eerste bericht');
        $this->get('/privacyverklaring')->assertOk()->assertSee('Wij bewaren niets.', false);
        $this->get('/algemene-voorwaarden')->assertNotFound();
    }

    public function testTheDesignedPageHasItsOwnMetaAndTheForm(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('<title>Contact', false);
        $response->assertSee('name="description" content="Neem contact met ons op."', false);
        $response->assertSee('<link rel="canonical" href="https://klant.test/contact"', false);
        self::assertSame(1, substr_count($response->getContent(), '<title>'));
        $response->assertSee('/content/forms/contact', false);
    }

    public function testSitemapRobotsAndHealthAreTheSdksAndTheSitemapListsTheDesignedPage(): void
    {
        $sitemap = $this->get('/sitemap.xml');
        $sitemap->assertOk();
        self::assertStringContainsString('/contact</loc>', $sitemap->getContent());
        self::assertStringContainsString('/blog/eerste-bericht</loc>', $sitemap->getContent());
        self::assertStringNotContainsString('/leden/', $sitemap->getContent());

        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:', false);
        $this->get('/api/health')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function testAnUnknownPathIs404InTheLayoutOfTheExample(): void
    {
        $response = $this->get('/bestaat-niet');

        $response->assertNotFound();
        $response->assertSee('class="brand"', false);
        // The default 404 view of the package extends the example's layout: one document, one title, no meta of a resource that does not exist.
        self::assertSame(1, substr_count($response->getContent(), '<html'));
        self::assertStringNotContainsString('rel="canonical"', $response->getContent());
    }

    public function testAHubOutageIsA503PageAndNeverAStackTrace(): void
    {
        $this->down = ['/pages'];

        $response = $this->get('/over-ons');

        $response->assertStatus(503);
        self::assertStringNotContainsString('ContentException', $response->getContent());
        self::assertSame('300', $response->headers->get('Retry-After'));
    }

    public function testTheBannerAndItsRouteComeFromTheScriptsComponent(): void
    {
        $this->get('/over-ons')->assertOk()->assertSee('/_fsc/c/consent.js', false);
        self::assertTrue($this->app['router']->has('content.consent.record'));
        self::assertTrue($this->app['router']->has('content.webhook'));
    }

    public function testTheMemberRoutesOfTheExampleExistAndTheSiteHasNoRoutesForWhatThePackageDoes(): void
    {
        $uris = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            // Routes of the framework itself are not the example's (Laravel 12+ serves the local disk at `storage/{path}`).
            $action = ltrim((string) $route->getActionName(), '\\');
            $framework = str_starts_with($action, 'Illuminate\\') || in_array($route->getName(), ['storage.local', 'storage.local.upload'], true);

            if (! $framework && ! str_starts_with($action, 'FullStackStudio') && ! str_contains($route->uri(), '_fsc')) {
                $uris[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        foreach (['POST leden/login', 'POST leden/logout', 'GET|HEAD contact'] as $own) {
            self::assertContains($own, $uris);
        }

        // Nothing of the old hand-built set is left in the site's own routes.
        foreach (['sitemap.xml', 'robots.txt', 'api/health', 'api/consent', 'blog', 'privacyverklaring', '{path}'] as $gone) {
            foreach ($uris as $uri) {
                self::assertStringNotContainsString($gone, $uri, "the example still routes {$gone} itself");
            }
        }
    }

    public function testTheExamplesCtaSiteViewOptsInToLayoutsTonesAndInlineTyping(): void
    {
        $block = ['id' => 'b1', 'type' => 'cta', 'heading' => 'Bel ons', 'text' => 'Wij helpen.', 'label' => 'Contact', 'href' => '/contact', 'layout' => 'split', 'tone' => 'soft'];
        $this->page('cta-test', 'nl', ['title' => 'CTA', 'blocks' => [$block]]);

        // Live: the site view prints its container and the look classes, and no builder markers.
        $live = $this->get('/cta-test')->assertOk()->getContent();
        self::assertStringContainsString('<div class="wrap">', $live);
        self::assertStringContainsString('fsc-block--cta fsc-block--layout-split fsc-block--tone-soft', $live);
        self::assertStringNotContainsString('data-fsc-edit', $live);

        // The same view in a preview marks its fields and tells the builder which layouts it implements (the site's declaration).
        $markup = view('blocks.cta', ['block' => $block, 'look' => 'fsc-block--layout-split', 'preview' => true, 'edit' => static fn (string $f): string => ' data-fsc-edit="' . $f . '" data-fsc-edit-kind="plain"'])->render();
        self::assertStringContainsString('data-fsc-edit="heading"', $markup);
        self::assertSame(['standard', 'split'], config('content.block_layouts.cta'));
    }

    public function testFscDoctorFindsNothingToFixInTheExample(): void
    {
        $results = (new Doctor($this->app))->run(false);
        $failures = array_values(array_map(static fn (array $r): string => $r['label'], array_filter($results, static fn (array $r): bool => $r['status'] === Doctor::FAIL)));

        self::assertSame([], $failures, 'fsc:doctor reports FOUT lines for the example site');
        self::assertSame([], array_values(array_filter($results, static fn (array $r): bool => $r['status'] === Doctor::WARN)), 'fsc:doctor reports LET OP lines for the example site');
        $this->artisan('fsc:doctor', ['--skip-env' => true])->assertExitCode(0);
    }
}
