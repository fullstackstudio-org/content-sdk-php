<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ShopModulesTest extends ModuleTestCase
{
    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>, 4: array<string, mixed>|null}> */
    public static function calls(): iterable
    {
        // products
        yield 'products.list' => [fn (ContentClient $c) => $c->products->list(['category' => 'shoes', 'q' => 'rood', 'status' => 'active', 'page' => 2, 'limit' => 5]), 'GET', '/products', ['category' => 'shoes', 'q' => 'rood', 'status' => 'active', 'page' => '2', 'limit' => '5'], null];
        yield 'products.get' => [fn (ContentClient $c) => $c->products->get('rode schoen'), 'GET', '/products/rode%20schoen', [], null];
        yield 'products.categories' => [fn (ContentClient $c) => $c->products->categories(), 'GET', '/product-categories', [], null];
        yield 'products.create' => [fn (ContentClient $c) => $c->products->create(['name' => 'Schoen']), 'POST', '/products', [], ['name' => 'Schoen']];
        yield 'products.update' => [fn (ContentClient $c) => $c->products->update('p1', ['name' => 'X']), 'PATCH', '/products/p1', [], ['name' => 'X']];
        yield 'products.delete' => [fn (ContentClient $c) => $c->products->delete('p1'), 'DELETE', '/products/p1', [], null];
        yield 'products.createVariant' => [fn (ContentClient $c) => $c->products->createVariant('p1', ['sku' => 'A']), 'POST', '/products/p1/variants', [], ['sku' => 'A']];
        yield 'products.updateVariant' => [fn (ContentClient $c) => $c->products->updateVariant('p1', 'v1', ['sku' => 'B']), 'PATCH', '/products/p1/variants/v1', [], ['sku' => 'B']];
        yield 'products.deleteVariant' => [fn (ContentClient $c) => $c->products->deleteVariant('p1', 'v1'), 'DELETE', '/products/p1/variants/v1', [], null];
        yield 'products.setStock' => [fn (ContentClient $c) => $c->products->setStock('p1', 'v1', ['delta' => -1]), 'PATCH', '/products/p1/variants/v1/stock', [], ['delta' => -1]];
        yield 'products.revisions' => [fn (ContentClient $c) => $c->products->revisions('p1', ['limit' => 3]), 'GET', '/products/p1/revisions', ['limit' => '3'], null];
        yield 'products.createCategory' => [fn (ContentClient $c) => $c->products->createCategory(['name' => 'Schoenen']), 'POST', '/product-categories', [], ['name' => 'Schoenen']];
        yield 'products.updateCategory' => [fn (ContentClient $c) => $c->products->updateCategory('c1', ['name' => 'Y']), 'PATCH', '/product-categories/c1', [], ['name' => 'Y']];
        yield 'products.deleteCategory' => [fn (ContentClient $c) => $c->products->deleteCategory('c1'), 'DELETE', '/product-categories/c1', [], null];

        // cart
        yield 'cart.shippingOptions' => [fn (ContentClient $c) => $c->cart->shippingOptions(['country' => 'NL', 'weight' => 500, 'subtotal' => 2500]), 'GET', '/shipping/options', ['country' => 'NL', 'weight' => '500', 'subtotal' => '2500'], null];

        // orders
        yield 'orders.list' => [fn (ContentClient $c) => $c->orders->list(['status' => 'paid', 'open' => true, 'q' => 'jan', 'customerId' => 'c1', 'page' => 1, 'limit' => 10]), 'GET', '/orders', ['status' => 'paid', 'open' => 'true', 'q' => 'jan', 'customerId' => 'c1', 'page' => '1', 'limit' => '10'], null];
        yield 'orders.get' => [fn (ContentClient $c) => $c->orders->get('2026-0001'), 'GET', '/orders/2026-0001', [], null];
        yield 'orders.transition' => [fn (ContentClient $c) => $c->orders->transition('o1', ['status' => 'shipped', 'note' => 'PostNL 123']), 'POST', '/orders/o1/transition', [], ['status' => 'shipped', 'note' => 'PostNL 123']];
        yield 'orders.cancel' => [fn (ContentClient $c) => $c->orders->cancel('o1', ['reason' => 'te duur']), 'POST', '/orders/o1/cancel', [], ['reason' => 'te duur']];
        yield 'orders.cancel (no input)' => [fn (ContentClient $c) => $c->orders->cancel('o1'), 'POST', '/orders/o1/cancel', [], []];
        yield 'orders.addNote' => [fn (ContentClient $c) => $c->orders->addNote('o1', 'gebeld'), 'POST', '/orders/o1/notes', [], ['body' => 'gebeld']];
        yield 'orders.createAccessToken' => [fn (ContentClient $c) => $c->orders->createAccessToken('o1'), 'POST', '/orders/o1/access-token', [], []];
        yield 'orders.update' => [fn (ContentClient $c) => $c->orders->update('o1', ['note' => 'x']), 'PATCH', '/orders/o1', [], ['note' => 'x']];
        yield 'orders.revisions' => [fn (ContentClient $c) => $c->orders->revisions('o1'), 'GET', '/orders/o1/revisions', [], null];
        yield 'orders.retryRevisionRefund' => [fn (ContentClient $c) => $c->orders->retryRevisionRefund('o1', 3), 'POST', '/orders/o1/revisions/3/refund', [], []];

        // payments
        yield 'payments.startForOrder' => [fn (ContentClient $c) => $c->payments->startForOrder('o1'), 'POST', '/orders/o1/payments', [], null];
        yield 'payments.listForOrder' => [fn (ContentClient $c) => $c->payments->listForOrder('o1'), 'GET', '/orders/o1/payments', [], null];
        yield 'payments.start' => [fn (ContentClient $c) => $c->payments->start(['kind' => 'appointment', 'appointmentId' => 'a1']), 'POST', '/payments', [], ['kind' => 'appointment', 'appointmentId' => 'a1']];
        yield 'payments.get' => [fn (ContentClient $c) => $c->payments->get('pay1'), 'GET', '/payments/pay1', [], null];
        yield 'payments.refund' => [fn (ContentClient $c) => $c->payments->refund('pay1', ['requestId' => 'r-1', 'amountCents' => 500]), 'POST', '/payments/pay1/refunds', [], ['requestId' => 'r-1', 'amountCents' => 500]];
        yield 'payments.refund (no input)' => [fn (ContentClient $c) => $c->payments->refund('pay1'), 'POST', '/payments/pay1/refunds', [], []];

        // discounts
        yield 'discounts.list' => [fn (ContentClient $c) => $c->discounts->list(['q' => 'zomer', 'active' => true, 'kind' => 'code']), 'GET', '/discounts', ['q' => 'zomer', 'active' => 'true', 'kind' => 'code'], null];
        yield 'discounts.get' => [fn (ContentClient $c) => $c->discounts->get('d1'), 'GET', '/discounts/d1', [], null];
        yield 'discounts.create' => [fn (ContentClient $c) => $c->discounts->create(['label' => 'Zomer', 'kind' => 'code']), 'POST', '/discounts', [], ['label' => 'Zomer', 'kind' => 'code']];
        yield 'discounts.update' => [fn (ContentClient $c) => $c->discounts->update('d1', ['active' => false]), 'PATCH', '/discounts/d1', [], ['active' => false]];
        yield 'discounts.delete' => [fn (ContentClient $c) => $c->discounts->delete('d1'), 'DELETE', '/discounts/d1', [], null];

        // shipping
        yield 'shipping.listMethods' => [fn (ContentClient $c) => $c->shipping->listMethods(), 'GET', '/shipping/methods', [], null];
        yield 'shipping.getMethod' => [fn (ContentClient $c) => $c->shipping->getMethod('m1'), 'GET', '/shipping/methods/m1', [], null];
        yield 'shipping.createMethod' => [fn (ContentClient $c) => $c->shipping->createMethod(['key' => 'post', 'label' => 'Post', 'priceCents' => 695]), 'POST', '/shipping/methods', [], ['key' => 'post', 'label' => 'Post', 'priceCents' => 695]];
        yield 'shipping.updateMethod' => [fn (ContentClient $c) => $c->shipping->updateMethod('m1', ['priceCents' => 500]), 'PATCH', '/shipping/methods/m1', [], ['priceCents' => 500]];
        yield 'shipping.deleteMethod' => [fn (ContentClient $c) => $c->shipping->deleteMethod('m1'), 'DELETE', '/shipping/methods/m1', [], null];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query @param array<string, mixed>|null $body */
    #[DataProvider('calls')]
    public function testRequestShape(callable $call, string $method, string $path, array $query, ?array $body): void
    {
        $this->runCall($call, $method, $path, $query, $body);
    }

    public function testUnwrapping(): void
    {
        $this->http->always(FakeTransport::json(['product' => ['id' => 'p1']]));
        self::assertSame(['id' => 'p1'], $this->client()->products->get('p1'));
        self::assertSame(['id' => 'p1'], $this->client()->products->create(['name' => 'x']));

        $this->http->always(FakeTransport::json(['categories' => [['id' => 'c1']]]));
        self::assertSame([['id' => 'c1']], $this->client()->products->categories());

        $this->http->always(FakeTransport::json(['variant' => ['id' => 'v1']]));
        self::assertSame(['id' => 'v1'], $this->client()->products->createVariant('p1', ['sku' => 'A']));

        $this->http->always(FakeTransport::json(['stock' => ['onHand' => 8]]));
        self::assertSame(['onHand' => 8], $this->client()->products->setStock('p1', 'v1', ['set' => 8]));

        $this->http->always(FakeTransport::json(['revisions' => [['n' => 1]]]));
        self::assertSame([['n' => 1]], $this->client()->products->revisions('p1'));

        $this->http->always(FakeTransport::json(['payments' => [['id' => 'x']]]));
        self::assertSame([['id' => 'x']], $this->client()->payments->listForOrder('o1'));

        $this->http->always(FakeTransport::json(['payment' => ['id' => 'pay1']]));
        self::assertSame(['id' => 'pay1'], $this->client()->payments->get('pay1'));

        $this->http->always(FakeTransport::json(['discount' => ['id' => 'd1']]));
        self::assertSame(['id' => 'd1'], $this->client()->discounts->get('d1'));

        $this->http->always(FakeTransport::json(['method' => ['id' => 'm1']]));
        self::assertSame(['id' => 'm1'], $this->client()->shipping->getMethod('m1'));

        // update/delete/list keep the API's shape.
        $this->http->always(FakeTransport::json(['product' => ['id' => 'p1'], 'changed' => true]));
        self::assertSame(['product' => ['id' => 'p1'], 'changed' => true], $this->client()->products->update('p1', ['name' => 'x']));
    }

    public function testListTakesTotalFromTheHeader(): void
    {
        $this->http->always(FakeTransport::json(['products' => []], 200, ['X-Total-Count' => '42']));
        self::assertSame(42, $this->client()->products->list()['total']);

        $this->http->always(FakeTransport::json(['orders' => []], 200, ['X-Total-Count' => '7']));
        self::assertSame(7, $this->client()->orders->list()['total']);
    }

    public function testProductReadsAreCachedUnderTheirTagsAndWritesAreNot(): void
    {
        $cache = new VersionedTagCache(new ArrayStore());
        $client = $this->client(self::PUB, ['cache' => $cache]);
        $this->http->always(FakeTransport::json(['product' => ['id' => 'p1']]));

        $client->products->get('Rode-Schoen');
        $client->products->get('Rode-Schoen');
        self::assertCount(1, $this->http->requests);

        // The webhook's tags for this product drop exactly that entry.
        $cache->invalidateTags(['content:product:rode-schoen']);
        $client->products->get('Rode-Schoen');
        self::assertCount(2, $this->http->requests);

        $client->products->categories();
        $client->products->categories();
        self::assertCount(3, $this->http->requests);
        $cache->invalidateTags(['content:product-categories']);
        $client->products->categories();
        self::assertCount(4, $this->http->requests);

        $client->orders->get('o1');
        $client->orders->get('o1');
        self::assertCount(6, $this->http->requests, 'orders are never cached');
    }

    public function testApplyCodeBodyAndIp(): void
    {
        $client = $this->client();
        $this->http->always(FakeTransport::json(['totalCents' => 1000]));

        $result = $client->cart->applyCode([
            'lines' => [['variantId' => 'v1', 'quantity' => 2]],
            'code' => 'ZOMER',
            'email' => '',
            'shippingMethod' => null,
            'country' => 'NL',
            'customerKind' => 'business',
            'vatNumber' => 'NL123',
        ], ['clientIp' => '203.0.113.5']);

        self::assertSame(['totalCents' => 1000], $result);
        $this->assertRequest('POST', '/discounts/validate', [], [
            'lines' => [['variantId' => 'v1', 'quantity' => 2]],
            'code' => 'ZOMER',
            'country' => 'NL',
            'customerKind' => 'business',
            'vatNumber' => 'NL123',
        ]);
        self::assertSame('203.0.113.5', $this->http->last()->headers['X-FSC-Client-Ip']);

        // A pub token never forwards a visitor IP.
        $this->client(self::PUB)->cart->applyCode(['lines' => []], ['clientIp' => '203.0.113.5']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);
        self::assertArrayNotHasKey('X-FSC-Member-Session', $this->http->last()->headers);
    }

    public function testPlaceOrderSendsMemberSessionAndIpHeaders(): void
    {
        $this->http->always(FakeTransport::json(['order' => ['id' => 'o1'], 'accessToken' => 'fsc_ot_x'], 201));
        $input = ['lines' => [['variantId' => 'v1', 'quantity' => 1]], 'email' => 'jan@example.nl', 'expectedTotalCents' => 1995];

        $result = $this->client()->orders->place($input, ['memberSession' => 'sess-1', 'clientIp' => '198.51.100.9']);

        self::assertSame('fsc_ot_x', $result['accessToken']);
        $this->assertRequest('POST', '/orders', [], $input);
        $headers = $this->http->last()->headers;
        self::assertSame('sess-1', $headers['X-FSC-Member-Session']);
        self::assertSame('198.51.100.9', $headers['X-FSC-Client-Ip']);
        self::assertStringNotContainsString('sess-1', $this->http->last()->url, 'the session never goes in a URL');
    }

    public function testOrderReadsAndCancelUseTheMemberSessionButNoIp(): void
    {
        $this->http->always(FakeTransport::json(['order' => ['id' => 'o1']]));

        $this->client()->orders->get('o1', ['memberSession' => 's', 'clientIp' => '198.51.100.9']);
        self::assertSame('s', $this->http->last()->headers['X-FSC-Member-Session']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);

        $this->client()->orders->list([], ['memberSession' => 's']);
        self::assertSame('s', $this->http->last()->headers['X-FSC-Member-Session']);

        $this->client()->orders->cancel('o1', [], ['memberSession' => 's']);
        self::assertSame('s', $this->http->last()->headers['X-FSC-Member-Session']);

        $this->client()->orders->get('o1');
        self::assertArrayNotHasKey('X-FSC-Member-Session', $this->http->last()->headers);
    }

    public function testCallOptionTimeoutIsPassedOn(): void
    {
        $this->http->always(FakeTransport::json(['order' => []]));
        $this->client()->orders->get('o1', ['timeoutMs' => 1234]);
        self::assertSame(1234, $this->http->last()->timeoutMs);
    }
}
