<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Resolved;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Unit\SiteTest;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;

/** The `head` and `scripts` components, their print-once guard and the old five-component layout. Fake transport only. */
final class HeadScriptsTest extends LaravelTestCase
{
    private const WEBSITE = '2d585159-5e61-400a-8326-029816f769a9';

    private const BROWSER = 'https://0123456789abcdef0123456789abcdef@errors.fullstackstudio.nl/12';

    private const OLD_LAYOUT = '<head><x-content::meta :page="$page" path="/over" /><x-content::head /><x-content::errors /></head>'
        . '<body><x-content::body-end /><x-content::analytics :status="404" /></body>';

    private FakeTransport $http;

    /** @var array<string, Response|TransportException> answers per API path (after /api/v1) */
    private array $routes = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.errors.allow_local', true);
        $app['config']->set('content.errors.release', 'abc123');
        $app['config']->set('app.url', 'https://www.example.nl');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->http->always(new Response(500, [], '{}'));
        $this->http->queue(...array_fill(0, 200, fn (Request $request): Response => $this->answer($request)));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));

        $this->routes['/site'] = FakeTransport::json(SiteTest::contract());
        $this->routes['/seo'] = FakeTransport::json(['seo' => ['siteName' => 'Jansen', 'titleTemplate' => '%s | Jansen', 'defaultDescription' => 'Standaard', 'canonicalOrigin' => 'https://www.jansen.nl']]);
        $this->routes['/analytics'] = FakeTransport::json(['analytics' => ['ready' => true, 'websiteId' => self::WEBSITE, 'domains' => ['jansen.nl']]]);
        $this->routes['/project/errors'] = FakeTransport::json(['errors' => ['status' => 'ready', 'backend' => null, 'browser' => ['dsn' => self::BROWSER], 'environment' => 'production']]);
        $this->routes['/project'] = FakeTransport::json(['project' => ['name' => 'Jansen'], 'defaultLocale' => 'nl', 'locales' => ['nl', 'en']]);
    }

    private function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        $answer = $this->routes[$path] ?? FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404);

        if ($answer instanceof TransportException) {
            throw $answer;
        }

        return $answer;
    }

    private function render(string $layout, array $data = []): string
    {
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(ContentClient::class);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        cache()->flush();

        return Blade::render($layout, [...['page' => ['title' => 'Over ons', 'seoDescription' => 'Wie wij zijn']], ...$data]);
    }

    /** @param array<string, int> $expected needle => count */
    private function assertCounts(string $html, array $expected, string $message = ''): void
    {
        foreach ($expected as $needle => $count) {
            self::assertSame($count, substr_count($html, $needle), $message . ' ' . $needle);
        }
    }

    private const ONCE = ['<title>' => 1, '<link rel="canonical"' => 1, 'id="fsc-errors"' => 1, 'data-website-id=' => 1, 'google-site-verification' => 1];

    public function testTheOldFiveComponentLayoutIsByteIdentical(): void
    {
        $html = $this->render(self::OLD_LAYOUT);
        $file = __DIR__ . '/fixtures/old-five-layout.html';

        if (getenv('FSC_WRITE_FIXTURES') === '1') {
            file_put_contents($file, $html);
        }

        self::assertSame((string) file_get_contents($file), $html);
        $this->assertCounts($html, self::ONCE);
    }

    public function testTheNewPairPrintsEverythingOnce(): void
    {
        $html = $this->render('<head><x-content::head :page="$page" path="/over" /></head><body><x-content::scripts /></body>');

        $this->assertCounts($html, self::ONCE);
        self::assertStringContainsString('<title>Over ons | Jansen</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.jansen.nl/over">', $html);
        self::assertStringContainsString('<meta name="description" content="Wie wij zijn">', $html);
    }

    /** @return array<string, array{string}> */
    public static function mixedLayouts(): array
    {
        return [
            'old five' => [self::OLD_LAYOUT],
            'new pair, then the old ones' => ['<head><x-content::head :page="$page" path="/over" /><x-content::meta :page="$page" path="/over" /><x-content::errors /></head><body><x-content::scripts /><x-content::body-end /><x-content::analytics /></body>'],
            'old ones, then the new pair' => ['<head><x-content::meta :page="$page" path="/over" /><x-content::errors /><x-content::head :page="$page" path="/over" /></head><body><x-content::body-end /><x-content::analytics /><x-content::scripts /></body>'],
            'new head, old errors and old body' => ['<head><x-content::head :page="$page" path="/over" /><x-content::errors /></head><body><x-content::body-end /><x-content::analytics /></body>'],
            'old meta, old head, new scripts' => ['<head><x-content::meta :page="$page" path="/over" /><x-content::head /><x-content::errors /></head><body><x-content::scripts /></body>'],
            'new scripts, old analytics after' => ['<head><x-content::head :page="$page" path="/over" /></head><body><x-content::scripts /><x-content::analytics /></body>'],
            'head twice' => ['<head><x-content::head :page="$page" path="/over" /><x-content::head :page="$page" path="/over" /></head><body><x-content::scripts /><x-content::scripts /></body>'],
        ];
    }

    #[DataProvider('mixedLayouts')]
    public function testEveryCombinationPrintsOneOfEach(string $layout): void
    {
        $this->assertCounts($this->render($layout), self::ONCE);
    }

    public function testMetaOverridesGoPropThenContentSeoThenResourceThenDefaults(): void
    {
        $head = '<x-content::head :page="$page" path="/over" :meta="$meta" />';

        // Resource and project defaults only.
        $html = $this->render($head, ['meta' => []]);
        self::assertStringContainsString('<title>Over ons | Jansen</title>', $html);

        // Content::seo() beats the resource.
        $this->app->forgetScopedInstances();
        Content::seo(['title' => 'Uit controller', 'description' => 'Controller tekst']);
        $html = Blade::render($head, ['page' => ['title' => 'Over ons'], 'meta' => []]);
        self::assertStringContainsString('<title>Uit controller</title>', $html);
        self::assertStringContainsString('<meta property="og:title" content="Uit controller">', $html);
        self::assertStringContainsString('<meta name="description" content="Controller tekst">', $html);

        // The prop beats Content::seo().
        $this->app->forgetScopedInstances();
        Content::seo(['title' => 'Uit controller']);
        $html = Blade::render($head, ['page' => ['title' => 'Over ons'], 'meta' => ['title' => 'Uit prop', 'noindex' => true, 'image' => '/media/x.png']]);
        self::assertStringContainsString('<title>Uit prop</title>', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        self::assertStringContainsString('<meta property="og:image" content="https://content.test/media/x.png">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function testTheCanonicalNeverCarriesAQueryString(): void
    {
        $this->app->forgetScopedInstances();
        $request = \Illuminate\Http\Request::create('/zoeken?q=boom&utm=x#top');
        $this->app->instance('request', $request);

        $html = Blade::render('<x-content::head :meta="[]" />');
        self::assertStringContainsString('<link rel="canonical" href="https://www.jansen.nl/zoeken">', $html);

        $this->app->forgetScopedInstances();
        $html = Blade::render('<x-content::head path="/contact?x=1#a" />');
        self::assertStringContainsString('<link rel="canonical" href="https://www.jansen.nl/contact">', $html);
        self::assertStringNotContainsString('?', (string) preg_replace('/^.*rel="canonical" href="([^"]*)".*$/s', '$1', $html));
    }

    public function testTheResolvedResourceFeedsTheMetaAndAnArticleGetsJsonLd(): void
    {
        $this->app->forgetScopedInstances();
        $article = ['title' => 'Boom snoeien', 'excerpt' => 'Zo doe je dat', 'authorName' => 'Jan', 'publishedAt' => '2026-11-01T10:00:00Z', 'updatedAt' => '2026-11-02T10:00:00Z', 'cover' => ['url' => '/api/media/c1', 'width' => 800, 'height' => 600]];
        app(PageContext::class)->resolve(new Resolved('article', $article, '/blog/boom-snoeien', 'nl'));

        $html = Blade::render('<x-content::head nonce="n0nce" />');

        self::assertStringContainsString('<title>Boom snoeien | Jansen</title>', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.jansen.nl/blog/boom-snoeien">', $html);
        self::assertSame(1, preg_match('#<script type="application/ld\+json" nonce="n0nce">(\{"@context":"https://schema.org","@type":"Article".*?\})</script>#', $html, $m));
        $ld = json_decode($m[1], true);
        self::assertSame('Boom snoeien', $ld['headline']);
        self::assertSame('2026-11-01T10:00:00Z', $ld['datePublished']);
        self::assertSame('2026-11-02T10:00:00Z', $ld['dateModified']);
        self::assertSame(['@type' => 'Person', 'name' => 'Jan'], $ld['author']);
        self::assertSame('https://content.test/api/media/c1', $ld['image']);
        self::assertSame('https://www.jansen.nl/blog/boom-snoeien', $ld['mainEntityOfPage']['@id']);
        // The site's own LocalBusiness JSON-LD stays: two blocks, one each.
        self::assertSame(1, substr_count($html, '"@type":"Article"'));
    }

    public function testTheCanonicalOfAResolvedResourceCarriesTheLocalePrefix(): void
    {
        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('page', ['title' => 'About'], '/about', 'en'));

        self::assertStringContainsString('<link rel="canonical" href="https://www.jansen.nl/en/about">', Blade::render('<x-content::head />'));
    }

    public function testAnEventGetsEventJsonLdWithOnlyTheFieldsItHas(): void
    {
        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('event', ['title' => 'Open dag </script>', 'startsAt' => '2026-12-01T09:00:00Z', 'status' => 'cancelled'], '/agenda/open-dag', 'nl'));

        $html = Blade::render('<x-content::head />');

        self::assertSame(1, preg_match('#<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"Event".*?\})</script>#', $html, $m));
        $ld = json_decode($m[1], true);
        self::assertSame('Open dag </script>', $ld['name']);
        self::assertSame('https://schema.org/EventCancelled', $ld['eventStatus']);
        self::assertArrayNotHasKey('endDate', $ld);
        self::assertArrayNotHasKey('location', $ld);
        self::assertArrayNotHasKey('image', $ld);
        self::assertStringNotContainsString('</script> ', $m[1]);
    }

    public function testAnEventHeadUsesTheSummaryAndTheTitleTemplate(): void
    {
        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('event', ['title' => 'Open dag', 'summary' => 'Kom langs en proef mee.', 'startsAt' => '2026-12-01T09:00:00Z'], '/agenda/open-dag', 'nl'));

        $html = Blade::render('<x-content::head />');

        self::assertStringContainsString('<title>Open dag | Jansen</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Kom langs en proef mee.">', $html);
        self::assertStringContainsString('<meta property="og:description" content="Kom langs en proef mee.">', $html);
        self::assertStringNotContainsString('Standaard', $html);
    }

    public function testAnEventWithoutASummaryFallsBackToItsDescriptionThenTheDefault(): void
    {
        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('event', ['title' => 'Open dag', 'summary' => '', 'descriptionHtml' => '<p>Een <strong>lange</strong> beschrijving.</p>', 'startsAt' => '2026-12-01T09:00:00Z'], '/agenda/open-dag', 'nl'));

        self::assertStringContainsString('<meta name="description" content="Een lange beschrijving.">', Blade::render('<x-content::head />'));

        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('event', ['title' => 'Open dag', 'startsAt' => '2026-12-01T09:00:00Z'], '/agenda/open-dag', 'nl'));

        self::assertStringContainsString('<meta name="description" content="Standaard">', Blade::render('<x-content::head />'));
    }

    public function testAnEventPageGetsHreflangFromItsAvailableLocales(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);
        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('event', ['title' => 'Open dag', 'slug' => 'open-dag', 'locale' => 'nl', 'availableLocales' => ['nl', 'en'], 'startsAt' => '2026-12-01T09:00:00Z'], '/agenda/open-dag', 'nl'));

        $html = Blade::render('<x-content::head />');

        self::assertStringContainsString('<link rel="alternate" hreflang="nl" href="https://www.jansen.nl/agenda/open-dag">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://www.jansen.nl/en/agenda/open-dag">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://www.jansen.nl/agenda/open-dag">', $html);
        self::assertSame(3, substr_count($html, 'rel="alternate"'));
    }

    public function testAnEventPageInOneLanguageOnlyHasNoHreflang(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);
        $this->app->forgetScopedInstances();
        app(PageContext::class)->resolve(new Resolved('event', ['title' => 'Open dag', 'slug' => 'open-dag', 'locale' => 'nl', 'availableLocales' => ['nl'], 'startsAt' => '2026-12-01T09:00:00Z'], '/agenda/open-dag', 'nl'));

        self::assertStringNotContainsString('hreflang', Blade::render('<x-content::head />'));
    }

    public function testHreflangHasSelfTranslationsAndXDefault(): void
    {
        $page = ['title' => 'Over ons', 'slug' => 'over-ons', 'locale' => 'nl', 'translations' => [
            ['id' => '1', 'locale' => 'nl', 'slug' => 'over-ons', 'status' => 'published'],
            ['id' => '2', 'locale' => 'en', 'slug' => 'about', 'status' => 'published'],
            ['id' => '3', 'locale' => 'de', 'slug' => 'ueber', 'status' => 'draft'],
        ]];

        $html = $this->render('<x-content::head :page="$page" path="/over-ons" />', ['page' => $page]);

        self::assertStringContainsString('<link rel="alternate" hreflang="nl" href="https://www.jansen.nl/over-ons">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://www.jansen.nl/en/about">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://www.jansen.nl/over-ons">', $html);
        self::assertStringNotContainsString('hreflang="de"', $html);
        self::assertSame(3, substr_count($html, 'rel="alternate"'));

        $single = $this->render('<x-content::head :page="$page" path="/over-ons" />', ['page' => ['title' => 'Alleen', 'slug' => 'alleen', 'locale' => 'nl']]);
        self::assertStringNotContainsString('rel="alternate"', $single);
    }

    public function testHreflangOnlyListsEnabledLanguages(): void
    {
        $page = ['title' => 'Over ons', 'slug' => 'over-ons', 'locale' => 'nl', 'translations' => [
            ['id' => '1', 'locale' => 'nl', 'slug' => 'over-ons', 'status' => 'published'],
            ['id' => '2', 'locale' => 'en', 'slug' => 'about', 'status' => 'published'],
            ['id' => '3', 'locale' => 'fr', 'slug' => 'a-propos', 'status' => 'published'],
        ]];

        $html = $this->render('<x-content::head :page="$page" path="/over-ons" />', ['page' => $page]);

        self::assertStringContainsString('hreflang="en"', $html);
        self::assertStringNotContainsString('hreflang="fr"', $html, 'fr is published but not enabled for the project');
        self::assertSame(3, substr_count($html, 'rel="alternate"'));
    }

    public function testASingleLanguageSiteHasNoHreflang(): void
    {
        $this->routes['/project'] = FakeTransport::json(['project' => ['name' => 'Jansen'], 'defaultLocale' => 'nl', 'locales' => ['nl']]);
        $page = ['title' => 'Over ons', 'slug' => 'over-ons', 'locale' => 'nl', 'translations' => [
            ['id' => '1', 'locale' => 'nl', 'slug' => 'over-ons', 'status' => 'published'],
            ['id' => '2', 'locale' => 'en', 'slug' => 'about', 'status' => 'published'],
        ]];

        $html = $this->render('<x-content::head :page="$page" path="/over-ons" />', ['page' => $page]);

        self::assertStringNotContainsString('rel="alternate"', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.jansen.nl/over-ons">', $html);
    }

    public function testTheHubBeingDownRendersWithoutAnException(): void
    {
        foreach (['/site', '/seo', '/analytics', '/project/errors', '/project'] as $path) {
            $this->routes[$path] = new TransportException('down', true);
        }

        $html = $this->render('<head><x-content::head :page="$page" path="/over" /></head><body><x-content::scripts /></body>');

        self::assertStringContainsString('<title>Over ons</title>', $html);
        self::assertStringContainsString('<style>@layer fsc-rich;</style>', $html);
        self::assertStringNotContainsString('fsc-errors', $html);
        self::assertStringNotContainsString('data-website-id', $html);
    }

    public function testThe404StatusReachesTheTrackerWithoutAStatusProp(): void
    {
        $this->routes['/analytics'] = FakeTransport::json(['analytics' => [
            'ready' => true, 'engine' => 'native', 'websiteId' => null, 'domains' => ['jansen.nl'],
            'native' => ['ready' => true, 'sitekey' => 'fsa_TestSite0123456789abcd', 'signingKey' => str_repeat('ab', 32)],
            'attribution' => ['params' => ['utm_source']],
        ]]);

        $this->app->forgetScopedInstances();
        app(PageContext::class)->setStatus(404);
        $html = Blade::render('<x-content::scripts />');
        self::assertStringContainsString('data-status="404"', $html);

        $this->app->forgetScopedInstances();
        self::assertStringNotContainsString('data-status', Blade::render('<x-content::scripts />'));
    }

    public function testScriptsPrintTheBannerSlotOnlyWhenConsentIsOnAndNeverTwice(): void
    {
        // The default banner view is empty until the banner task fills it: the slot prints nothing and does not break the page.
        $html = $this->render('<x-content::scripts /><x-content::scripts :consent="false" />');
        self::assertSame(1, substr_count($html, 'data-website-id='));
    }
}
