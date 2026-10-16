<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\Psr16Store;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private FakeTransport $http;
    private VersionedTagCache $cache;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->cache = new VersionedTagCache(new ArrayStore());
    }

    private function client(string $token = 'fsc_srv_aaa'): ContentClient
    {
        return new ContentClient(['token' => $token, 'transport' => $this->http, 'cache' => $this->cache]);
    }

    public function testSecondReadIsAHitAndDifferentQueriesAreSeparate(): void
    {
        $this->http->always(FakeTransport::json(['article' => ['slug' => 'hello']]));
        $c = $this->client();

        self::assertSame(['slug' => 'hello'], $c->getArticle('hello'));
        self::assertSame(['slug' => 'hello'], $c->getArticle('hello'));
        self::assertCount(1, $this->http->requests);

        $c->getArticle('hello', ['locale' => 'en']);
        self::assertCount(2, $this->http->requests);
    }

    public function testTotalFromHeaderSurvivesTheCache(): void
    {
        $this->http->always(FakeTransport::json(['articles' => []], 200, ['X-Total-Count' => '12']));
        $c = $this->client();

        self::assertSame(12, $c->listArticles()['total']);
        self::assertSame(12, $c->listArticles()['total']);
        self::assertCount(1, $this->http->requests);
    }

    public function testInvalidatingATagMakesOnlyThatTagMiss(): void
    {
        $this->http->always(FakeTransport::json(['article' => ['slug' => 'x'], 'seo' => ['siteName' => 'S']]));
        $c = $this->client();
        $c->getArticle('x');
        $c->getSeo();
        self::assertCount(2, $this->http->requests);

        $this->cache->invalidateTags([Tags::article('X')]);
        $c->getArticle('x');
        $c->getSeo();
        self::assertCount(3, $this->http->requests);

        $this->cache->invalidateTags(Tags::forEvent(['type' => 'seo.updated', 'data' => []]));
        $c->getSeo();
        self::assertCount(4, $this->http->requests);
    }

    public function testWritesAndSubmitFormAreNeverCached(): void
    {
        $this->http->always(FakeTransport::json(['article' => ['id' => 'a']]));
        $c = $this->client();

        $c->admin->createArticle(['title' => 't']);
        $c->admin->createArticle(['title' => 't']);
        $c->submitForm('f', ['a' => 'b']);
        $c->submitForm('f', ['a' => 'b']);
        $c->newsletter->subscribe(['email' => 'a@b.nl']);
        $c->newsletter->subscribe(['email' => 'a@b.nl']);
        self::assertCount(6, $this->http->requests);

        // Admin reads have no tags either.
        $c->admin->listWebhooks();
        $c->admin->listWebhooks();
        self::assertCount(8, $this->http->requests);
    }

    public function testCallOptionCanSkipTheCacheAndExtraTagsApply(): void
    {
        $this->http->always(FakeTransport::json(['seo' => []]));
        $c = $this->client();

        $c->getSeo(['cache' => false]);
        $c->getSeo(['cache' => false]);
        self::assertCount(2, $this->http->requests);

        $c->getSeo(['tags' => ['mine']]);
        $c->getSeo(['tags' => ['mine']]);
        self::assertCount(3, $this->http->requests);
        $this->cache->invalidateTags(['mine']);
        $c->getSeo(['tags' => ['mine']]);
        self::assertCount(4, $this->http->requests);
    }

    public function testErrorsAreNotCached(): void
    {
        $this->http->queue(FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404), FakeTransport::json(['article' => ['slug' => 'ok']]));
        $c = $this->client();

        try {
            $c->getArticle('a');
            self::fail('expected exception');
        } catch (\FullStackStudio\Content\ContentException) {
        }

        self::assertSame(['slug' => 'ok'], $c->getArticle('a'));
    }

    public function testDifferentTokensDoNotShareEntries(): void
    {
        $this->http->always(FakeTransport::json(['article' => ['slug' => 'x']]));
        $this->client('fsc_srv_aaa')->getArticle('x');
        $this->client('fsc_pub_bbb')->getArticle('x');

        self::assertCount(2, $this->http->requests);
    }

    public function testTtlExpiryAndPsr16Adapter(): void
    {
        $psr = new class () {
            /** @var array<string, mixed> */
            public array $items = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function set(string $key, mixed $value, mixed $ttl = null): bool
            {
                $this->items[$key] = $value;

                return true;
            }
        };
        $cache = new VersionedTagCache(new Psr16Store($psr));
        $calls = 0;
        $compute = function () use (&$calls): array {
            $calls++;

            return ['v' => $calls];
        };

        self::assertSame(['v' => 1], $cache->remember('k', ['t'], 60, $compute));
        self::assertSame(['v' => 1], $cache->remember('k', ['t'], 60, $compute));
        $cache->invalidateTags(['t']);
        self::assertSame(['v' => 2], $cache->remember('k', ['t'], 60, $compute));
        self::assertSame(['v' => 1], $cache->remember('other', ['u'], 60, fn () => ['v' => 1]));
        self::assertNotEmpty($psr->items);
    }

    public function testFalseyValuesAreCachedToo(): void
    {
        $calls = 0;
        $fn = function () use (&$calls): bool {
            $calls++;

            return false;
        };

        self::assertFalse($this->cache->remember('f', ['t'], 60, $fn));
        self::assertFalse($this->cache->remember('f', ['t'], 60, $fn));
        self::assertSame(1, $calls);
    }
}
