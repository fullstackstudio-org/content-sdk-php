<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\Auto\AutoRoutes;
use FullStackStudio\Content\Laravel\Site\Resolved;
use FullStackStudio\Content\Laravel\Site\SiteViews;
use FullStackStudio\Content\Laravel\Site\Urls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The site controller and the default `content::site.*` views, through the real fallback route with a fake Hub: one feature test per resolved
 * type, HTML snapshots of the `<main>` of each, overrides, handlers, view composers and the inline CSS. Regenerate the snapshots with
 * `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter SiteViewsTest` and read the diff.
 */
final class SiteViewsTest extends FakeHubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('content.links.event', '/agenda/{slug}');
        $this->app['config']->set('content.auto.articles.per_page', 2);
        $this->app['config']->set('content.timezone', 'Europe/Amsterdam');
        $this->app['config']->set('app.url', 'https://klant.test');
    }

    /** The `<main>` element of a response, one tag per line trimmed, so a snapshot survives indentation changes. */
    private function main(string $html): string
    {
        self::assertSame(1, preg_match('#<main class="fsc-site">(.*)</main>#s', $html, $m), 'the package layout wraps the view in <main class="fsc-site">');

        $lines = array_filter(array_map(static fn (string $l): string => (string) preg_replace('/\s{2,}/', ' ', trim($l)), explode("\n", $m[1])), static fn (string $l): bool => $l !== '');

        return implode("\n", $lines) . "\n";
    }

    private function snapshot(string $name, string $html): void
    {
        $file = __DIR__ . '/fixtures/site-' . $name . '.html';
        $actual = $this->main($html);

        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($file, $actual);
        }

        self::assertFileExists($file, 'run with UPDATE_SNAPSHOTS=1 to create it');
        self::assertSame((string) file_get_contents($file), $actual, 'snapshot ' . $name);
    }

    private function eventFixture(array $extra = []): void
    {
        $this->events['open-dag'] = [
            'id' => '11111111-1111-4111-8111-111111111111', 'slug' => 'open-dag', 'status' => 'published', 'title' => 'Open dag',
            'summary' => 'Kom langs en kijk rond.', 'startsAt' => '2026-12-05T13:00:00Z', 'endsAt' => '2026-12-05T16:30:00Z',
            'location' => 'Hoofdstraat 1, Den Haag', 'salesState' => 'open', 'currency' => 'EUR',
            'registrationUrl' => 'https://hub.test/e/open-dag', 'descriptionHtml' => '<p>Een <strong>gezellige</strong> middag.</p>',
            'ticketTypes' => [['priceCents' => 0, 'active' => true]],
            'cover' => ['id' => 'c1', 'url' => 'https://content.test/api/media/c1', 'contentType' => 'image/jpeg', 'alt' => 'De zaal', 'width' => 1200, 'height' => 800],
            ...$extra,
        ];
    }

    // --- one feature test per type ----------------------------------------------------------------------------------------------

    public function testAPageRendersItsBlocksAndOneH1(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Over ons', 'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Wie wij zijn</p>']]]);

        $response = $this->get('/over-ons');

        $response->assertOk()->assertSee('Wie wij zijn', false);
        $html = $response->getContent();
        self::assertSame(1, substr_count($html, '<h1'), 'one h1: the page title');
        self::assertStringContainsString('<h1 class="fsc-site-title">Over ons</h1>', $html);
        $this->snapshot('page', $html);
    }

    public function testAPageWithAHeroFirstPrintsNoTitleOfItsOwn(): void
    {
        $this->page('/', 'nl', ['title' => 'Home', 'blocks' => [['id' => 'h', 'type' => 'hero', 'heading' => 'Welkom bij Jansen', 'subheading' => 'Altijd goed']]]);

        $html = $this->get('/')->assertOk()->getContent();

        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('Welkom bij Jansen', $html);
        self::assertStringNotContainsString('fsc-site-title', $html);
    }

    public function testAnArticleRendersHeaderCoverAndBody(): void
    {
        $this->article('hallo', 'nl', [
            'title' => 'Hallo wereld', 'excerpt' => 'Een korte inleiding.', 'authorName' => 'Piet Jansen', 'publishedAt' => '2026-11-12T09:00:00Z',
            'bodyHtml' => '<p>De <em>tekst</em>.</p>',
            'cover' => ['id' => 'c9', 'url' => 'https://content.test/api/media/c9', 'contentType' => 'image/jpeg', 'alt' => 'Omslag', 'width' => 1200, 'height' => 800],
        ]);

        $response = $this->get('/blog/hallo');

        $response->assertOk();
        $html = $response->getContent();
        self::assertSame(1, substr_count($html, '<h1'));
        foreach (['Hallo wereld', 'Een korte inleiding.', 'Piet Jansen', '12 november 2026', '<p>De <em>tekst</em>.</p>', 'alt="Omslag"', 'loading="eager"'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        $this->snapshot('article', $html);
    }

    public function testTheArticleListShowsCardsAndPagination(): void
    {
        $this->article('een', 'nl', ['title' => 'Een', 'excerpt' => 'Eerste', 'publishedAt' => '2026-11-01T09:00:00Z']);
        $this->article('twee', 'nl', ['title' => 'Twee', 'excerpt' => 'Tweede']);
        $this->article('drie', 'nl', ['title' => 'Drie', 'excerpt' => 'Derde']);

        $first = $this->get('/blog')->assertOk()->getContent();

        self::assertStringContainsString('href="/blog/een"', $first);
        self::assertStringContainsString('href="/blog/twee"', $first);
        self::assertStringNotContainsString('href="/blog/drie"', $first);
        self::assertStringContainsString('<a rel="next" href="/blog?page=2">Volgende</a>', $first);
        self::assertStringNotContainsString('rel="prev"', $first);
        self::assertStringContainsString('Pagina 1 van 2', $first);
        $this->snapshot('articles', $first);

        $second = $this->get('/blog?page=2')->assertOk()->getContent();

        self::assertStringContainsString('href="/blog/drie"', $second);
        self::assertStringContainsString('<a rel="prev" href="/blog">Vorige</a>', $second);
        self::assertStringNotContainsString('rel="next"', $second);

        $this->get('/blog?page=3')->assertNotFound();
    }

    public function testAnEmptyArticleListShowsAnEmptyState(): void
    {
        $this->get('/blog')->assertOk()->assertSee('Er zijn nog geen artikelen.', false)->assertDontSee('fsc-pager', false);
    }

    public function testACategoryListHasItsNameAndALinkBack(): void
    {
        $this->categories = [['slug' => 'nieuws', 'name' => 'Nieuws', 'children' => []]];
        $this->article('in-nieuws', 'nl', ['title' => 'Nieuwtje', 'categories' => ['nieuws']]);
        $this->article('elders', 'nl', ['title' => 'Elders', 'categories' => []]);

        $html = $this->get('/blog/categorie/nieuws')->assertOk()->getContent();

        self::assertStringContainsString('<h1 class="fsc-site-title">Nieuws</h1>', $html);
        self::assertStringContainsString('href="/blog/in-nieuws"', $html);
        self::assertStringNotContainsString('/blog/elders', $html);
        self::assertStringContainsString('<a class="fsc-site-back" href="/blog">Alle artikelen</a>', $html);
        $this->snapshot('category', $html);
    }

    public function testAnEventShowsFactsDescriptionAndTheHubRegisterButton(): void
    {
        $this->eventFixture();

        $html = $this->get('/agenda/open-dag')->assertOk()->getContent();

        self::assertSame(1, substr_count($html, '<h1'));
        foreach (['Open dag', 'Kom langs en kijk rond.', 'zaterdag 5 december 2026', '14:00 - 17:30', 'Hoofdstraat 1, Den Haag', 'Gratis', 'alt="De zaal"', '<strong>gezellige</strong>'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        self::assertStringContainsString('<a class="fsc-button fsc-button--primary" href="https://hub.test/e/open-dag" rel="noopener">Aanmelden</a>', $html);
        $this->snapshot('event', $html);
    }

    public function testAnEventWithoutOpenSalesShowsTheStateInsteadOfTheButton(): void
    {
        $this->eventFixture(['salesState' => 'sold_out']);

        $html = $this->get('/agenda/open-dag')->assertOk()->getContent();

        self::assertStringContainsString('Uitverkocht', $html);
        self::assertStringNotContainsString('Aanmelden', $html);
    }

    public function testACancelledEventIsStillServedWithItsState(): void
    {
        $this->eventFixture(['status' => 'cancelled']);

        $this->get('/agenda/open-dag')->assertOk()->assertSee('Gaat niet door', false)->assertDontSee('Aanmelden', false);
    }

    public function testALegalDocumentRendersTitleAndBody(): void
    {
        $this->legal['privacy_statement'] = ['title' => 'Privacyverklaring Jansen', 'bodyHtml' => '<h2>Wat we doen</h2><p>Weinig.</p>', 'effectiveAt' => '2026-01-01T00:00:00Z'];

        $html = $this->get('/privacyverklaring')->assertOk()->getContent();

        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('Privacyverklaring Jansen', $html);
        self::assertStringContainsString('Geldt vanaf', $html);
        self::assertStringContainsString('1 januari 2026', $html);
        self::assertStringContainsString('<h2>Wat we doen</h2>', $html);
        $this->snapshot('legal', $html);
    }

    public function testALegalDocumentWithoutATitleGetsADutchOne(): void
    {
        $this->legal['terms'] = ['bodyHtml' => '<p>Regels.</p>'];

        $this->get('/algemene-voorwaarden')->assertOk()->assertSee('Algemene voorwaarden', false);
    }

    // --- 404 and 503 -------------------------------------------------------------------------------------------------------------

    public function testAnUnknownPathIs404AndAHubFailureIs503(): void
    {
        $this->get('/bestaat-niet')->assertNotFound();

        $this->down = ['/pages'];
        $this->get('/ergens')->assertStatus(503);
    }

    public function testFeaturesThatAreOffAre404(): void
    {
        $this->page('over-ons');
        $this->article('hallo');
        $this->legal['privacy_statement'] = ['title' => 'Privacy'];

        config(['content.auto.pages' => false, 'content.auto.articles' => false, 'content.auto.legal' => false]);

        foreach (['/over-ons', '/blog/hallo', '/blog', '/privacyverklaring'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    // --- overrides ----------------------------------------------------------------------------------------------------------------

    public function testAViewInTheVendorFolderWinsAndViewComposersSeeIt(): void
    {
        View::prependNamespace('content', __DIR__ . '/views/vendor-content');
        View::composer('content::site.page', static function ($view): void {
            $view->with('composed', 'from composer');
        });
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);

        $html = $this->get('/over-ons')->assertOk()->getContent();

        self::assertSame('OVERRIDE Over ons | /over-ons | page | from composer', trim($html));
    }

    public function testConfiguredViewReplacesTheDefault(): void
    {
        View::addNamespace('site', __DIR__ . '/views');
        config(['content.auto.views.page' => 'site::site-page']);
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);

        self::assertSame('CUSTOM Over ons', trim($this->get('/over-ons')->assertOk()->getContent()));
    }

    public function testAConfiguredHandlerTakesOverAndReceivesTheRequestAndTheResolved(): void
    {
        config(['content.auto.handlers.page' => SiteViewsTestHandler::class]);
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);

        $response = $this->get('/over-ons?x=1');

        $response->assertOk();
        self::assertSame('handled page /over-ons x=1', $response->getContent());
    }

    public function testASiteLayoutIsUsedWhenConfiguredAndItsSectionFilled(): void
    {
        View::addNamespace('site', __DIR__ . '/views');
        config(['content.auto.layout' => 'site::layout', 'content.auto.section' => 'body']);
        $this->page('over-ons', 'nl', ['title' => 'Over ons']);

        $response = $this->get('/over-ons');

        $response->assertOk()->assertSee('SITE LAYOUT', false)->assertSee('Over ons', false);
    }

    public function testTheContainerClassFollowsConfigWrap(): void
    {
        config(['content.auto.wrap' => 'wrap max-w-5xl']);
        $this->article('hallo', 'nl', ['title' => 'Hallo']);

        $html = $this->get('/blog/hallo')->assertOk()->getContent();

        self::assertStringContainsString('<div class="wrap max-w-5xl">', $html);
        self::assertStringNotContainsString('class="fsc-wrap"', $html);
    }

    // --- context, urls, css ---------------------------------------------------------------------------------------------------------

    public function testTheHeadComponentSeesTheResolvedResource(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Over ons', 'seoTitle' => 'Over ons | Jansen']);

        $html = $this->get('/over-ons')->assertOk()->getContent();

        self::assertStringContainsString('<title>', $html);
        self::assertStringContainsString('Over ons', substr($html, (int) strpos($html, '<title>'), 120));
    }

    public function testUrlsIsOneInstancePerRequest(): void
    {
        $first = $this->app->make(Urls::class);

        self::assertSame($first, $this->app->make(Urls::class));

        $this->app->forgetScopedInstances();

        self::assertNotSame($first, $this->app->make(Urls::class));
    }

    public function testTheInlineCssIsPrintedOnceWithTheNonceAndNeedsNoInlineStyleAttribute(): void
    {
        config(['content.blocks.inline_styles' => true]);
        Vite::useCspNonce('abc123');
        $this->page('over-ons', 'nl', ['title' => 'Over ons', 'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>x</p>']]]);

        $html = $this->get('/over-ons')->assertOk()->getContent();

        self::assertSame(2, substr_count($this->main($html), '<style nonce="abc123">'), 'the block CSS and the site CSS, once each');
        self::assertSame(1, substr_count($html, ':where(.fsc-site-title)'));
        self::assertSame(1, substr_count($html, '--fsc-gap:'));
        self::assertDoesNotMatchRegularExpression('#<main[^>]*>.*\sstyle=".*</main>#s', $html, 'no style attribute for a strict CSP');
    }

    public function testNoCssIsPrintedWhenInlineStylesAreOff(): void
    {
        $this->article('hallo', 'nl', ['title' => 'Hallo']);

        self::assertStringNotContainsString(':where(', $this->get('/blog/hallo')->assertOk()->getContent());
    }

    public function testTheCommittedSiteCssMatchesItsGenerator(): void
    {
        require_once __DIR__ . '/../../scripts/build-block-css.php';

        self::assertSame(
            \build_site_css((string) file_get_contents(__DIR__ . '/../../resources/assets/site-source.css')),
            (string) file_get_contents(__DIR__ . '/../../resources/assets/site.css'),
            'run `php scripts/build-block-css.php` after changing resources/assets/site-source.css',
        );
    }

    public function testEverySiteCssSelectorIsWrappedInWhere(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../resources/assets/site.css');
        $css = (string) preg_replace('#@media[^{]+\{#', '', $css);

        self::assertGreaterThan(10, preg_match_all('#([^{}]+)\{[^{}]*\}#', $css, $rules));

        foreach ($rules[1] as $selectors) {
            self::assertMatchesRegularExpression('#^(?::where\([^{}]+\),?)+$#', trim($selectors), $selectors);
        }
    }

    public function testA404AfterTheResolveDoesNotLeakTheResourceIntoTheHead(): void
    {
        config(['content.auto.handlers.article' => SiteViewsTestAbortHandler::class]);
        $this->article('geheim', 'nl', ['title' => 'Geheime titel', 'seoTitle' => 'Geheime titel', 'excerpt' => 'Geheime samenvatting']);

        $response = $this->get('/blog/geheim');

        $response->assertNotFound();
        $html = (string) $response->getContent();
        self::assertStringContainsString('Pagina niet gevonden', $html);
        self::assertStringNotContainsString('Geheime', $html, 'no title, description or og of the resource');
        self::assertStringNotContainsString('application/ld+json', $html, 'no Article JSON-LD');
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString('hreflang', $html);
        self::assertStringContainsString('noindex', $html);
    }

    public function testTheAbortInTheEventViewDoesNotLeakTheEvent(): void
    {
        // The resolver only skips drafts; the event view aborts for any other status it cannot show (`abort_if` in site/event).
        $this->eventFixture(['title' => 'Geheim evenement', 'summary' => 'Geheime samenvatting', 'status' => 'archived']);

        $html = (string) $this->get('/agenda/open-dag')->assertNotFound()->getContent();

        self::assertStringContainsString('Pagina niet gevonden', $html);
        self::assertStringNotContainsString('Geheim', $html);
        self::assertStringNotContainsString('application/ld+json', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
    }

    public function testTheSitesOwn404ViewIsNoindexToo(): void
    {
        View::addNamespace('site', __DIR__ . '/views');
        config(['content.auto.handlers.article' => SiteViewsTestAbortHandler::class]);
        $this->article('geheim', 'nl', ['title' => 'Geheime titel']);
        $errors = sys_get_temp_dir() . '/fsc-site-404-' . bin2hex(random_bytes(4));
        mkdir($errors . '/errors', 0777, true);
        file_put_contents($errors . '/errors/404.blade.php', '<html><head><x-content::head /></head><body>EIGEN 404</body></html>');
        View::getFinder()->prependLocation($errors);

        try {
            $html = (string) $this->get('/blog/geheim')->assertNotFound()->getContent();
        } finally {
            @unlink($errors . '/errors/404.blade.php');
            @rmdir($errors . '/errors');
            @rmdir($errors);
        }

        self::assertStringContainsString('EIGEN 404', $html);
        self::assertStringContainsString('noindex', $html);
        self::assertStringNotContainsString('Geheime', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
    }

    // --- publishing -------------------------------------------------------------------------------------------------------------------

    #[DataProvider('types')]
    public function testEveryTypeHasADefaultViewAndAPublishTag(string $type): void
    {
        self::assertTrue(View::exists('content::site.' . $type));
        self::assertArrayHasKey('content-site-' . $type, SiteViews::publishable());
        self::assertFileExists(array_key_first(SiteViews::publishable()['content-site-' . $type]));
    }

    /** @return array<string, array{string}> */
    public static function types(): array
    {
        return array_combine(Resolved::TYPES, array_map(static fn (string $t): array => [$t], Resolved::TYPES));
    }

    public function testThePageRouteStillExistsAndIsTheSiteController(): void
    {
        self::assertSame('FullStackStudio\Content\Laravel\Site\SiteController', $this->app['router']->getRoutes()->getByName(AutoRoutes::PAGE)?->getActionName());
    }
}

/** Handler of a site: an invokable class, never a closure (config:cache). */
final class SiteViewsTestHandler
{
    public function __invoke(Request $request, Resolved $resolved): string
    {
        return 'handled ' . $resolved->type . ' ' . $resolved->path . ' x=' . $request->query('x');
    }
}

/** A site handler that decides after the resolve that the resource cannot be shown. */
final class SiteViewsTestAbortHandler
{
    public function __invoke(Request $request, Resolved $resolved): never
    {
        abort(404);
    }
}
