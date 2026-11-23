<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\Site\PathResolver;
use FullStackStudio\Content\Laravel\Site\Urls;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

final class UrlsTest extends FakeHubTestCase
{
    private function urls(): Urls
    {
        return $this->app->make(Urls::class);
    }

    public function testPathAppliesLocalePrefixAndTrailingSlash(): void
    {
        $urls = $this->urls();

        self::assertSame('/', $urls->path('/'));
        self::assertSame('/over-ons', $urls->path('over-ons'));
        self::assertSame('/over-ons', $urls->path('/over-ons/', 'nl'), 'the default locale has no prefix');
        self::assertSame('/en/over-ons', $urls->path('/over-ons', 'en'));
        self::assertSame('/en', $urls->path('/', 'en'));

        config(['content.auto.trailing_slash' => true]);
        self::assertSame('/', $urls->path('/'));
        self::assertSame('/over-ons/', $urls->path('over-ons'));
        self::assertSame('/en/', $urls->path('/', 'en'));
        self::assertSame('/en/over-ons/', $urls->path('/over-ons', 'en'));
    }

    public function testOnlyEnabledNonDefaultLocalesGetAPrefix(): void
    {
        $urls = $this->urls();

        self::assertTrue($urls->multilingual());
        self::assertSame('/en/over-ons', $urls->path('/over-ons', 'en'));
        self::assertSame('/over-ons', $urls->path('/over-ons', 'de'), 'a language the project has not enabled gets no prefix');
        self::assertSame('/blog/hallo', $urls->article(['slug' => 'hallo', 'locale' => 'fr']));
    }

    public function testASingleLanguageSiteHasNoPrefixesAtAll(): void
    {
        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl'];
        $urls = $this->urls();

        self::assertFalse($urls->multilingual());
        self::assertSame(['nl'], $urls->locales());
        self::assertSame('/over-ons', $urls->path('/over-ons', 'en'));
        self::assertSame('/', $urls->path('/', 'en'));
        self::assertSame('/blog/hello', $urls->article(['slug' => 'hello', 'locale' => 'en']));
    }

    public function testAnEnglishDefaultSiteHasNoPrefixForEnglish(): void
    {
        $this->project = ['locales' => ['en', 'nl'], 'defaultLocale' => 'en'];
        $urls = $this->urls();

        self::assertSame(['en', 'nl'], $urls->locales());
        self::assertSame('/about', $urls->path('/about', 'en'));
        self::assertSame('/nl/about', $urls->path('/about', 'nl'));
    }

    public function testDefaultLocaleFallsBackWhenTheProjectHasNone(): void
    {
        $this->project = ['locales' => []];

        self::assertSame('nl', $this->urls()->defaultLocale());
        self::assertSame(['nl'], $this->urls()->locales());
    }

    public function testArticleUsesTheTemplate(): void
    {
        $urls = $this->urls();

        self::assertSame('/blog/hallo', $urls->article(['slug' => 'hallo', 'locale' => 'nl']));
        self::assertSame('/en/blog/hello', $urls->article(['slug' => 'hello', 'locale' => 'en']));
        self::assertSame('/blog/een%20twee', $urls->article(['slug' => 'een twee']), 'the slug is encoded');
    }

    public function testArticleTemplateWithLocaleAddsNoPrefix(): void
    {
        $this->seo['articlePathTemplate'] = '/{locale}/nieuws/{slug}';

        self::assertSame('/en/nieuws/hello', $this->urls()->article(['slug' => 'hello', 'locale' => 'en']));
        self::assertSame('/nl/nieuws/hallo', $this->urls()->article(['slug' => 'hallo', 'locale' => 'nl']));
    }

    public function testInvalidArticleTemplatesFallBack(): void
    {
        foreach (['//evil.test/{slug}', 'blog/{slug}', '/blog', '/{slug}/{slug}', 'https://x.test/{slug}'] as $bad) {
            $this->seo['articlePathTemplate'] = $bad;
            self::assertSame('/blog/x', $this->app->make(Urls::class)->article(['slug' => 'x']), $bad);
        }
    }

    public function testEventAndLegal(): void
    {
        $urls = $this->urls();

        self::assertNull($urls->event(['slug' => 'open-dag']), 'no template, no site URL');

        config(['content.links.event' => '/evenementen/{slug}']);
        self::assertSame('/evenementen/open-dag', $urls->event(['slug' => 'open-dag', 'id' => 'e1']));

        config(['content.links.event' => '/e/{id}']);
        self::assertSame('/e/e1', $urls->event(['slug' => 'open-dag', 'id' => 'e1']));

        config(['content.links.event' => '//evil.test/{slug}']);
        self::assertNull($urls->event(['slug' => 'x']));

        self::assertSame('/privacyverklaring', $urls->legal('privacy_statement'));
        self::assertSame('/en/algemene-voorwaarden', $urls->legal('terms', 'en'));
        self::assertNull($urls->legal('unknown_kind'));
    }

    public function testAbsoluteUsesTheCanonicalOriginElseTheApp(): void
    {
        self::assertSame('https://klant.test/over-ons', $this->urls()->absolute('/over-ons'));
        self::assertSame('https://klant.test/', $this->urls()->absolute('/'));
        self::assertSame('https://other.test/x', $this->urls()->absolute('https://other.test/x'));

        $this->seo = [];
        config(['app.url' => 'http://localhost']);
        self::assertSame('http://localhost/over-ons', $this->app->make(Urls::class)->absolute('over-ons'));
    }

    public function testTheFacadeBuildsAnInstance(): void
    {
        self::assertInstanceOf(Urls::class, Content::urls());
        self::assertSame('/en/over-ons', Content::urls()->path('/over-ons', 'en'));
    }

    /** @return array<string, array{bool}> */
    public static function slashModes(): array
    {
        return ['without trailing slash' => [false], 'with trailing slash' => [true]];
    }

    /** Every type: what Urls builds is what the resolver parses back to the same resource. */
    #[DataProvider('slashModes')]
    public function testUrlsRoundTripWithTheResolver(bool $slash): void
    {
        config(['content.auto.trailing_slash' => $slash, 'content.links.event' => '/evenementen/{slug}']);
        $this->categories = [['slug' => 'nieuws', 'children' => []]];
        $this->page('over-ons', 'nl', ['title' => 'nl-page']);
        $this->page('over-ons', 'en', ['title' => 'en-page']);
        $this->page('/', 'en', ['title' => 'en-home']);
        $this->page('/', 'nl', ['title' => 'nl-home']);
        $this->article('hallo', 'nl');
        $this->article('hello', 'en');
        $this->article('nieuwtje', 'nl', ['categories' => ['nieuws']]);
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published'];
        $this->legal['privacy_statement'] = ['title' => 'Privacy'];

        $urls = $this->urls();
        $resolver = $this->app->make(PathResolver::class);
        $resolve = function (string $url) use ($resolver): ?\FullStackStudio\Content\Laravel\Site\Resolved {
            $this->app->instance('request', Request::create($url));

            return $resolver->resolve((string) parse_url($url, PHP_URL_PATH));
        };
        $end = $slash ? '/' : '';

        $cases = [
            'page nl' => [$urls->page($this->pages['nl:over-ons']), 'page', 'nl-page', '/over-ons' . $end],
            'page en' => [$urls->page($this->pages['en:over-ons']), 'page', 'en-page', '/en/over-ons' . $end],
            'home nl' => [$urls->page($this->pages['nl:']), 'page', 'nl-home', '/'],
            'home en' => [$urls->page($this->pages['en:']), 'page', 'en-home', '/en' . $end],
            'article nl' => [$urls->article($this->articles[0]), 'article', 'hallo', '/blog/hallo' . $end],
            'article en' => [$urls->article($this->articles[1]), 'article', 'hello', '/en/blog/hello' . $end],
            'event' => [$urls->event($this->events['open-dag']), 'event', 'open-dag', '/evenementen/open-dag' . $end],
            'legal' => [$urls->legal('privacy_statement'), 'legal', null, '/privacyverklaring' . $end],
            'article list' => [$urls->path('/blog'), 'articles', null, '/blog' . $end],
            'article list en' => [$urls->path('/blog', 'en'), 'articles', null, '/en/blog' . $end],
            'category' => [$urls->path('/blog/categorie/nieuws'), 'category', null, '/blog/categorie/nieuws' . $end],
        ];

        foreach ($cases as $name => [$url, $type, $marker, $expected]) {
            self::assertSame($expected, $url, $name);
            $resolved = $resolve($url);
            self::assertSame($type, $resolved?->type, $name);

            if ($marker !== null) {
                self::assertSame($marker, $resolved->resource['slug'] ?? $resolved->resource['title'], $name);
            }
        }
    }

    public function testUrlsRoundTripWithALocaleTemplate(): void
    {
        $this->seo['articlePathTemplate'] = '/{locale}/nieuws/{slug}';
        $this->article('hello', 'en');
        $this->article('hallo', 'nl');
        $resolver = $this->app->make(PathResolver::class);
        $this->app->instance('request', Request::create('/'));

        foreach ($this->articles as $article) {
            $url = $this->urls()->article($article);

            self::assertSame($article['slug'], $resolver->resolve($url)?->resource['slug'], $url);
        }
    }
}
