<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;

final class RecommendedProductsTest extends ModuleTestCase
{
    private const PID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    public function testRecommendedReturnsIdsAndSendsOnlyAWellFormedPid(): void
    {
        $this->http->always(FakeTransport::json(['active' => true, 'source' => 'interest', 'ids' => ['p2', 'p1']]));

        self::assertSame(['p2', 'p1'], $this->client()->products->recommended(self::PID, 4));
        $this->assertRequest('GET', '/products/recommended', ['limit' => '4']);
        self::assertSame(self::PID, $this->http->last()->headers['X-FSI-Pid'] ?? null);
        self::assertStringNotContainsString(self::PID, $this->http->last()->url, 'the browser id never travels in the URL');

        // No id, or an id that is not a browser id: no pid on the wire, default limit 8.
        $this->client()->products->recommended();
        $this->assertRequest('GET', '/products/recommended', ['limit' => '8']);
        self::assertArrayNotHasKey('X-FSI-Pid', $this->http->last()->headers);
        $this->client()->products->recommended('not a browser id!');
        $this->assertRequest('GET', '/products/recommended', ['limit' => '8']);
        self::assertArrayNotHasKey('X-FSI-Pid', $this->http->last()->headers);
    }

    public function testTheLimitIsClampedAndTheOptionsTravel(): void
    {
        $this->http->always(FakeTransport::json(['active' => false, 'ids' => []]));

        $this->client()->products->recommended(null, 99, ['locale' => 'en']);
        $this->assertRequest('GET', '/products/recommended', ['limit' => '24', 'locale' => 'en']);
        self::assertArrayNotHasKey('Sec-GPC', $this->http->last()->headers);

        $this->client()->products->recommended(self::PID, 0, ['gpc' => true]);
        $this->assertRequest('GET', '/products/recommended', ['limit' => '1']);
        self::assertSame(self::PID, $this->http->last()->headers['X-FSI-Pid'] ?? null);
        self::assertSame('1', $this->http->last()->headers['Sec-GPC'] ?? null);
    }

    public function testRecommendationsKeepsTheFlagsAndDropsJunk(): void
    {
        $this->http->always(FakeTransport::json(['active' => false, 'source' => 'fallback', 'ids' => ['a', 7, null, 'b']]));

        self::assertSame(['active' => false, 'source' => 'fallback', 'ids' => ['a', 'b']], $this->client()->products->recommendations());

        // An older Hub answer shape never breaks the page.
        $this->http->always(FakeTransport::json([]));
        self::assertSame(['active' => false, 'source' => 'fallback', 'ids' => []], $this->client()->products->recommendations());
    }

    public function testNeverTouchesTheCacheAndNeedsNoTags(): void
    {
        $this->http->always(FakeTransport::json(['active' => true, 'source' => 'interest', 'ids' => ['p1']]));
        $client = $this->client(extra: ['cache' => new \FullStackStudio\Content\Cache\VersionedTagCache(new \FullStackStudio\Content\Cache\ArrayStore())]);

        $client->products->recommended(self::PID);
        $client->products->recommended(self::PID);

        self::assertCount(2, $this->http->requests, 'a personal answer is never served from the SDK cache');
    }

    public function testRecommendedProductsHydratesInOrderAndSkipsAGoneProduct(): void
    {
        $this->http->queue(
            FakeTransport::json(['active' => true, 'source' => 'interest', 'ids' => ['p2', 'gone', 'p1']]),
            FakeTransport::json(['product' => ['id' => 'p2', 'name' => 'Stoel']]),
            FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'Product niet gevonden.']], 404),
            FakeTransport::json(['product' => ['id' => 'p1', 'name' => 'Tafel']]),
        );

        $products = $this->client()->products->recommendedProducts(self::PID, 3);

        self::assertSame(['p2', 'p1'], array_column($products, 'id'));
        self::assertStringEndsWith('/api/v1/products/recommended', (string) parse_url($this->http->requests[0]->url, PHP_URL_PATH));
        self::assertStringEndsWith('/api/v1/products/p2', (string) parse_url($this->http->requests[1]->url, PHP_URL_PATH));
        self::assertStringEndsWith('/api/v1/products/gone', (string) parse_url($this->http->requests[2]->url, PHP_URL_PATH));
    }

    public function testRecommendedProductsDropsAProductThatIsOutOfStock(): void
    {
        $this->http->queue(
            FakeTransport::json(['active' => true, 'source' => 'interest', 'ids' => ['p1', 'p2']]),
            FakeTransport::json(['product' => ['id' => 'p1', 'inStock' => false]]),
            FakeTransport::json(['product' => ['id' => 'p2', 'inStock' => true]]),
        );

        self::assertSame(['p2'], array_column($this->client()->products->recommendedProducts(), 'id'));
    }

    public function testAnErrorOtherThanNotFoundPropagates(): void
    {
        $this->http->queue(
            FakeTransport::json(['active' => true, 'source' => 'fallback', 'ids' => ['p1']]),
            FakeTransport::json(['error' => ['code' => 'internal', 'message' => 'Mis.']], 500),
        );

        $this->expectException(ContentException::class);
        $this->client(extra: ['retries' => 0])->products->recommendedProducts();
    }

    public function testAPubTokenIsRefusedByTheHub(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'insufficient_scope', 'message' => 'Dit kan alleen met een servertoken (fsc_srv_…).']], 403));

        try {
            $this->client(self::PUB)->products->recommended();
            self::fail('expected a ContentException');
        } catch (ContentException $error) {
            self::assertSame(403, $error->status());
        }
    }
}
