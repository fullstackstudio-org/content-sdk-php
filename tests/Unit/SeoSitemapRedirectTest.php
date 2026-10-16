<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\RedirectResolver;
use FullStackStudio\Content\Seo;
use FullStackStudio\Content\Sitemap;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SeoSitemapRedirectTest extends TestCase
{
    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
    }

    private function client(): ContentClient
    {
        return new ContentClient(['token' => 'fsc_srv_x', 'transport' => $this->http]);
    }

    public function testSeoMetaForAnArticle(): void
    {
        $seo = ['siteName' => 'Acme', 'titleTemplate' => '%s | Acme', 'defaultDescription' => 'Default', 'canonicalOrigin' => 'https://www.acme.nl/', 'twitterSite' => '@acme', 'robotsIndex' => true];
        $article = ['title' => 'Hello', 'excerpt' => 'Short', 'cover' => ['url' => '/api/media/m1', 'width' => 800, 'height' => 400, 'alt' => 'Cover'], 'publishedAt' => '2026-10-01', 'authorName' => 'Jan'];
        $meta = Seo::meta($seo, $article, ['path' => '/blog/hello', 'type' => 'article']);

        self::assertSame('Hello | Acme', $meta['title']);
        self::assertSame('Short', $meta['description']);
        self::assertSame('https://www.acme.nl/blog/hello', $meta['canonical']);
        self::assertArrayNotHasKey('robots', $meta);
        self::assertSame('article', $meta['openGraph']['type']);
        self::assertSame('https://content.fullstackstudio.nl/api/media/m1', $meta['openGraph']['image']['url']);
        self::assertSame('Jan', $meta['openGraph']['author']);
        self::assertSame('summary_large_image', $meta['twitter']['card']);

        $html = Seo::html($meta);
        self::assertStringContainsString('<title>Hello | Acme</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.acme.nl/blog/hello">', $html);
        self::assertStringContainsString('property="og:image" content="https://content.fullstackstudio.nl/api/media/m1"', $html);
    }

    public function testSeoMetaDefaultsAndEscaping(): void
    {
        $meta = Seo::meta(['siteName' => 'A "b" <c>', 'defaultDescription' => 'd', 'robotsIndex' => false]);

        self::assertSame('A "b" <c>', $meta['title']);
        self::assertSame('noindex, nofollow', $meta['robots']);
        self::assertSame('summary', $meta['twitter']['card']);
        self::assertStringContainsString('A &quot;b&quot; &lt;c&gt;', Seo::html($meta));
        self::assertSame(['type' => 'website'], Seo::meta(null)['openGraph']);
    }

    public function testSitemapEntriesAndXml(): void
    {
        $this->http->always(FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => 'https://www.acme.nl/', 'entries' => [
            ['path' => '/about', 'locale' => 'nl', 'lastmod' => '2026-10-01'],
            ['path' => '/en/about?x=1&y=2', 'locale' => 'en', 'lastmod' => null],
            ['path' => '/hidden', 'locale' => 'nl'],
        ]]));

        $entries = Sitemap::entries($this->client(), [
            'extra' => [['url' => 'https://www.acme.nl/']],
            'entryPath' => fn (array $e): ?string => $e['path'] === '/hidden' ? null : $e['path'],
        ]);

        self::assertSame([
            ['url' => 'https://www.acme.nl/', 'lastmod' => null],
            ['url' => 'https://www.acme.nl/about', 'lastmod' => '2026-10-01'],
            ['url' => 'https://www.acme.nl/en/about?x=1&y=2', 'lastmod' => null],
        ], $entries);

        $xml = Sitemap::xml($entries);
        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('x=1&amp;y=2', $xml);
        self::assertStringContainsString('<lastmod>2026-10-01</lastmod>', $xml);
    }

    public function testSitemapIsEmptyWhenNotIndexableAndNeedsAnOrigin(): void
    {
        $this->http->always(FakeTransport::json(['robotsIndex' => false, 'entries' => [['path' => '/a', 'locale' => 'nl']]]));
        self::assertSame([], Sitemap::entries($this->client()));

        $this->http->always(FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => null, 'entries' => [['path' => '/a', 'locale' => 'nl']]]));
        $this->expectException(ContentException::class);
        Sitemap::entries($this->client());
    }

    public function testRedirectResolver(): void
    {
        $this->http->queue(FakeTransport::json(['redirect' => ['location' => '/new', 'statusCode' => 301]]), FakeTransport::json(['redirect' => ['location' => 'https://x.nl/?a=1', 'statusCode' => 302]]), FakeTransport::json(['redirect' => null]));
        $resolver = new RedirectResolver($this->client());

        self::assertSame(['location' => '/new?utm=1', 'status' => 301], $resolver->resolve('/old', 'utm=1'));
        self::assertSame(['location' => 'https://x.nl/?a=1', 'status' => 302], $resolver->resolve('/old2', 'utm=1'));
        self::assertNull($resolver->resolve('/none'));
        self::assertSame(1500, $this->http->last()->timeoutMs);

        $before = count($this->http->requests);
        foreach (['/api/v1/x', '/_next/static/a.js', '/build/app.css', '/storage/a.png', '/favicon.ico'] as $skipped) {
            self::assertNull($resolver->resolve($skipped));
        }
        self::assertCount($before, $this->http->requests);
    }

    public function testRedirectResolverPausesAfterAFailure(): void
    {
        $this->http->queue(new TransportException('down'));
        $errors = 0;
        $resolver = new RedirectResolver($this->client(), ['onError' => function () use (&$errors): void {
            $errors++;
        }], new ArrayStore());

        self::assertNull($resolver->resolve('/a'));
        self::assertSame(1, $errors);
        self::assertNull($resolver->resolve('/b'));
        self::assertCount(1, $this->http->requests);
    }
}
