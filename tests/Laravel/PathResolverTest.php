<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\Site\PathResolver;
use FullStackStudio\Content\Laravel\Site\Resolved;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

final class PathResolverTest extends FakeHubTestCase
{
    private function resolve(string $path, string $uri = '/'): ?Resolved
    {
        $this->app->instance('request', Request::create($uri));

        return $this->app->make(PathResolver::class)->resolve($path);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'dot in last segment' => ['/about/file.html'],
            'dot only' => ['/.env'],
            'too long' => ['/' . str_repeat('a', 200)],
            'leading dash' => ['/-x'],
            'space' => ['/a b'],
            'double slash' => ['/a//b'],
            'percent' => ['/a%20b'],
            'non ascii' => ['/caf' . "\u{e9}"],
            'query char' => ['/a?b=1'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testStepZeroRejectsWithoutAHubCall(string $path): void
    {
        $this->page('about');

        self::assertNull($this->resolve($path));
        self::assertSame([], $this->http->requests);
    }

    public function testRowOneHomeAndPlainPage(): void
    {
        $this->page('/');
        $this->page('over-ons');

        $home = $this->resolve('/');
        self::assertSame('page', $home?->type);
        self::assertSame('/', $home->path);

        $about = $this->resolve('over-ons/');
        self::assertSame('page', $about?->type);
        self::assertSame('/over-ons', $about->path, 'the trailing slash is not part of the path');
        self::assertSame('nl', $about->locale);
        self::assertSame('over-ons', trim($this->queryOf('/pages')['path'], '/'));
        self::assertNull($this->resolve('/bestaat-niet'));
    }

    public function testRowOneLocalePrefixIsStrippedAndPassedOn(): void
    {
        $this->page('over-ons', 'en', ['title' => 'About']);
        $this->page('/', 'en', ['title' => 'Home']);

        $about = $this->resolve('/en/over-ons');
        self::assertSame('About', $about?->resource['title']);
        self::assertSame('en', $about->locale);
        self::assertSame('/over-ons', $about->path);
        self::assertSame('en', $this->queryOf('/pages')['locale']);
        self::assertSame('en', app()->getLocale());

        self::assertSame('Home', $this->resolve('/en')?->resource['title'], 'a bare locale is that language\'s home page');
        self::assertNull($this->resolve('/nl/over-ons'), 'the default locale has no prefix: nl/over-ons is just a path');
        self::assertNull($this->resolve('/de/over-ons'), 'an unknown locale is a path segment, not a language');
    }

    public function testTheDefaultLocaleIsPassedOnToo(): void
    {
        $this->page('over-ons');
        $this->resolve('/over-ons');

        self::assertSame('nl', $this->queryOf('/pages')['locale']);
        self::assertSame('nl', app()->getLocale());
    }

    public function testRowTwoLegalDocumentAndFallThrough(): void
    {
        $this->legal['privacy_statement'] = ['title' => 'Privacy', 'bodyHtml' => '<p>x</p>'];
        $this->page('algemene-voorwaarden', 'nl', ['title' => 'Own terms page']);

        $privacy = $this->resolve('/privacyverklaring');
        self::assertSame('legal', $privacy?->type);
        self::assertSame('privacy_statement', $privacy->extra['kind']);
        self::assertSame([], array_filter($this->hubPaths(), static fn (string $p): bool => $p === '/pages'), 'no page lookup when the document exists');

        $terms = $this->resolve('/algemene-voorwaarden');
        self::assertSame('page', $terms?->type, 'no document in force: falls through to the page');
        self::assertSame('Own terms page', $terms->resource['title']);
    }

    public function testRowTwoLegalOffIsAPlainPath(): void
    {
        config(['content.auto.legal' => false]);
        $this->legal['privacy_statement'] = ['title' => 'Privacy'];

        self::assertNull($this->resolve('/privacyverklaring'));
        self::assertNotContains('/legal/privacy_statement', $this->hubPaths());
    }

    public function testRowTwoLegalPathsAreConfigurable(): void
    {
        config(['content.auto.legal_paths' => ['voorwaarden' => 'terms']]);
        $this->legal['terms'] = ['title' => 'Terms'];

        self::assertSame('legal', $this->resolve('/voorwaarden')?->type);
        self::assertNull($this->resolve('/algemene-voorwaarden'));
    }

    public function testRowThreeEventByTemplate(): void
    {
        config(['content.links.event' => '/evenementen/{slug}']);
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published'];
        $this->events['gestopt'] = ['id' => 'e2', 'slug' => 'gestopt', 'status' => 'cancelled'];
        $this->events['concept'] = ['id' => 'e3', 'slug' => 'concept', 'status' => 'draft'];
        $this->page('evenementen/open-dag');

        $event = $this->resolve('/evenementen/open-dag');
        self::assertSame('event', $event?->type);
        self::assertNotContains('/pages', $this->hubPaths(), 'a template match is decisive');
        self::assertSame('event', $this->resolve('/evenementen/gestopt')?->type, 'a cancelled event is still served');
        self::assertNull($this->resolve('/evenementen/concept'), 'a draft event is a 404');
        self::assertNull($this->resolve('/evenementen/onbekend'));
    }

    public function testRowThreeWithoutAnEventTemplateEventsAreNotServed(): void
    {
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published'];

        self::assertNull($this->resolve('/evenementen/open-dag'));
        self::assertNotContains('/events/open-dag', $this->hubPaths());
    }

    public function testRowFourArticleByTemplateIsDecisive(): void
    {
        $this->article('hallo');
        $this->article('concept', 'nl', ['status' => 'draft']);
        $this->page('blog/hallo');

        $article = $this->resolve('/blog/hallo');
        self::assertSame('article', $article?->type);
        self::assertSame('/blog/hallo', $article->path);
        self::assertNotContains('/pages', $this->hubPaths(), 'no page lookup for /blog/x');

        self::assertNull($this->resolve('/blog/concept'), 'a draft article is a 404');
        self::assertNull($this->resolve('/blog/onbekend'));
        self::assertNotContains('/pages', $this->hubPaths());
    }

    public function testRowFourParentIsAPageFirstThenTheArticleList(): void
    {
        $this->article('een');
        $this->article('twee');

        $list = $this->resolve('/blog');
        self::assertSame('articles', $list?->type);
        self::assertCount(2, $list->resource['articles']);
        self::assertSame(['page' => 1, 'per_page' => 12, 'total' => 2, 'pages' => 1, 'category' => null], $list->extra);
        self::assertSame(['/project', '/seo', '/pages', '/articles'], array_values(array_unique($this->hubPaths())));
        self::assertSame('published', $this->queryOf('/articles')['status']);
        self::assertSame('12', $this->queryOf('/articles')['limit']);

        $this->page('blog', 'nl', ['title' => 'Own blog page']);
        $page = $this->resolve('/blog');
        self::assertSame('page', $page?->type, 'a CMS page at the parent path wins over the generated list');
    }

    public function testArticleListPagination(): void
    {
        config(['content.auto.articles.per_page' => 2]);

        foreach (['a', 'b', 'c'] as $slug) {
            $this->article($slug);
        }

        $first = $this->resolve('/blog');
        self::assertCount(2, $first->resource['articles']);
        self::assertSame(2, $first->extra['pages']);
        self::assertSame('2', $this->queryOf('/articles')['limit']);

        $second = $this->resolve('/blog', '/blog?page=2');
        self::assertCount(1, $second->resource['articles']);
        self::assertSame(2, $second->extra['page']);
        self::assertSame('2', $this->queryOf('/articles')['page']);

        foreach (['0', '-1', 'abc', '1.5', '', '99999999'] as $bad) {
            self::assertNull($this->resolve('/blog', '/blog?page=' . $bad), 'page=' . $bad);
        }

        self::assertNull($this->resolve('/blog', '/blog?page=3'), 'beyond the last page');
    }

    public function testRowFourCategoryList(): void
    {
        $this->categories = [['slug' => 'nieuws', 'name' => 'Nieuws', 'children' => [['slug' => 'pers', 'name' => 'Pers', 'children' => []]]]];
        $this->article('in-nieuws', 'nl', ['categories' => ['nieuws']]);
        $this->article('elders');

        $nieuws = $this->resolve('/blog/categorie/nieuws');
        self::assertSame('category', $nieuws?->type);
        self::assertSame('nieuws', $nieuws->extra['category']['slug']);
        self::assertCount(1, $nieuws->resource['articles']);
        self::assertSame('nieuws', $this->queryOf('/articles')['category']);
        self::assertNotContains('/pages', $this->hubPaths());

        self::assertSame('category', $this->resolve('/blog/categorie/pers')?->type, 'a nested category');
        self::assertNull($this->resolve('/blog/categorie/onbekend'));
    }

    public function testRowFiveAndSixRootLevelOrderIsPageThenArticleThenEvent(): void
    {
        $this->seo['articlePathTemplate'] = '/{slug}';
        config(['content.links.event' => '/{slug}']);
        $this->page('gedeeld');
        $this->article('gedeeld');
        $this->article('alleen-artikel');
        $this->events['gedeeld'] = ['id' => 'e1', 'slug' => 'gedeeld', 'status' => 'published'];
        $this->events['alleen-event'] = ['id' => 'e2', 'slug' => 'alleen-event', 'status' => 'published'];
        $this->events['alleen-artikel'] = ['id' => 'e3', 'slug' => 'alleen-artikel', 'status' => 'published'];

        self::assertSame('page', $this->resolve('/gedeeld')?->type);
        self::assertSame('article', $this->resolve('/alleen-artikel')?->type, 'article beats event');
        self::assertSame('event', $this->resolve('/alleen-event')?->type);
        self::assertNull($this->resolve('/nergens'));
        self::assertNull($this->resolve('/a/b'), 'a root level template never matches a deep path');
    }

    public function testRootLevelDraftsAreSkippedForTheNextType(): void
    {
        $this->seo['articlePathTemplate'] = '/{slug}';
        config(['content.links.event' => '/{slug}']);
        $this->article('x', 'nl', ['status' => 'draft']);
        $this->events['x'] = ['id' => 'e1', 'slug' => 'x', 'status' => 'published'];

        self::assertSame('event', $this->resolve('/x')?->type);
    }

    public function testADraftPageIsA404(): void
    {
        $this->page('concept', 'nl', ['status' => 'draft']);

        self::assertNull($this->resolve('/concept'));
    }

    public function testModuleOffIsA404(): void
    {
        $this->off = ['/pages'];
        $this->page('over-ons');
        self::assertNull($this->resolve('/over-ons'));

        $this->off = ['/articles/hallo'];
        $this->article('hallo');
        self::assertNull($this->resolve('/blog/hallo'));

        $this->off = ['/articles'];
        self::assertNull($this->resolve('/blog'));
    }

    public function testFeatureSwitchesGateTheTypes(): void
    {
        $this->page('over-ons');
        $this->article('hallo');

        config(['content.auto.articles' => false]);
        self::assertNull($this->resolve('/blog/hallo'));
        self::assertNull($this->resolve('/blog'));
        self::assertNotContains('/articles/hallo', $this->hubPaths());
        self::assertNotContains('/seo', $this->hubPaths(), 'no SEO read when articles are off');

        config(['content.auto.pages' => false]);
        self::assertNull($this->resolve('/over-ons'));
    }

    public function testAHubFailurePropagates(): void
    {
        $this->down = ['/pages'];
        $this->page('over-ons');

        $this->expectException(ContentException::class);
        $this->resolve('/over-ons');
    }

    public function testAHubFailureOnAnArticlePropagates(): void
    {
        $this->down = ['/articles/hallo'];

        $this->expectException(ContentException::class);
        $this->resolve('/blog/hallo');
    }

    public function testAHubFailureOnTheProjectPropagates(): void
    {
        $this->down = ['/project'];

        $this->expectException(ContentException::class);
        $this->resolve('/over-ons');
    }

    public function testLocalePrefixAndArticleTemplate(): void
    {
        $this->article('hello', 'en');
        $this->article('hallo', 'nl');

        $en = $this->resolve('/en/blog/hello');
        self::assertSame('en', $en?->locale);
        self::assertSame('/blog/hello', $en->path);
        self::assertSame('en', $this->queryOf('/articles/hello')['locale']);
        self::assertNull($this->resolve('/blog/hello'), 'the English article does not exist in the default language');
        self::assertSame('en', $this->resolve('/en/blog')?->locale);
        self::assertSame('en', $this->queryOf('/articles')['locale']);
    }

    public function testLocaleInTheArticleTemplate(): void
    {
        $this->seo['articlePathTemplate'] = '/{locale}/nieuws/{slug}';
        $this->article('hello', 'en');
        $this->article('hallo', 'nl');

        self::assertSame('en', $this->resolve('/en/nieuws/hello')?->locale);
        self::assertSame('nl', $this->resolve('/nl/nieuws/hallo')?->locale);
        self::assertNull($this->resolve('/de/nieuws/hallo'), 'only project locales fill {locale}');
        self::assertSame('articles', $this->resolve('/en/nieuws')?->type);
    }

    public function testAnInvalidArticleTemplateFallsBackToTheDefault(): void
    {
        $this->seo['articlePathTemplate'] = '//evil.test/{slug}';
        $this->article('hallo');

        self::assertSame('article', $this->resolve('/blog/hallo')?->type);
    }
}
