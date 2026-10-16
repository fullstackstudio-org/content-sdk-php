<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Returns, reviews, downloads, FAQ and events. */
final class StorefrontExtrasTest extends ModuleTestCase
{
    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>, 4: array<string, mixed>|null}> */
    public static function calls(): iterable
    {
        // returns
        yield 'returns.request' => [fn (ContentClient $c) => $c->returns->request('OT', ['lines' => [['lineId' => 'l1', 'quantity' => 1]]]), 'POST', '/returns', [], ['lines' => [['lineId' => 'l1', 'quantity' => 1]]]];
        yield 'returns.get' => [fn (ContentClient $c) => $c->returns->get('RT'), 'GET', '/returns/by-token', [], null];
        yield 'returns.list' => [fn (ContentClient $c) => $c->returns->list(['status' => 'requested', 'page' => 2]), 'GET', '/returns', ['status' => 'requested', 'page' => '2'], null];
        yield 'returns.getById' => [fn (ContentClient $c) => $c->returns->getById('r1'), 'GET', '/returns/r1', [], null];
        yield 'returns.create' => [fn (ContentClient $c) => $c->returns->create(['orderId' => 'o1']), 'POST', '/returns', [], ['orderId' => 'o1']];
        yield 'returns.action' => [fn (ContentClient $c) => $c->returns->action('r1', 'approve', ['note' => 'ok']), 'POST', '/returns/r1/approve', [], ['note' => 'ok']];
        yield 'returns.action (no input)' => [fn (ContentClient $c) => $c->returns->action('r1', 'receive'), 'POST', '/returns/r1/receive', [], []];

        // reviews
        yield 'reviews.list' => [fn (ContentClient $c) => $c->reviews->list('schoen', ['page' => 1, 'limit' => 5, 'rating' => 5]), 'GET', '/products/schoen/reviews', ['page' => '1', 'limit' => '5', 'rating' => '5'], null];
        yield 'reviews.submit' => [fn (ContentClient $c) => $c->reviews->submit('schoen', ['rating' => 5, 'body' => 'Top']), 'POST', '/products/schoen/reviews', [], ['rating' => 5, 'body' => 'Top']];
        yield 'reviews.listAll' => [fn (ContentClient $c) => $c->reviews->listAll(['status' => 'pending']), 'GET', '/reviews', ['status' => 'pending'], null];
        yield 'reviews.moderate' => [fn (ContentClient $c) => $c->reviews->moderate('rv1', 'approve'), 'POST', '/reviews/rv1/approve', [], []];
        yield 'reviews.remove' => [fn (ContentClient $c) => $c->reviews->remove('rv1'), 'DELETE', '/reviews/rv1', [], null];

        // downloads
        yield 'downloads.listFiles' => [fn (ContentClient $c) => $c->downloads->listFiles('p1'), 'GET', '/products/p1/files', [], null];
        yield 'downloads.removeFile' => [fn (ContentClient $c) => $c->downloads->removeFile('p1', 'f1'), 'DELETE', '/products/p1/files/f1', [], null];
        yield 'downloads.forOrder' => [fn (ContentClient $c) => $c->downloads->forOrder('o1'), 'GET', '/orders/o1/downloads', [], null];
        yield 'downloads.resetLink' => [fn (ContentClient $c) => $c->downloads->resetLink('o1', 'l1'), 'POST', '/orders/o1/downloads/l1/reset', [], []];
        yield 'downloads.resendLinks' => [fn (ContentClient $c) => $c->downloads->resendLinks('o1'), 'POST', '/orders/o1/downloads/resend', [], []];
        yield 'downloads.forBuyer' => [fn (ContentClient $c) => $c->downloads->forBuyer('OT'), 'GET', '/downloads/by-order', [], null];

        // faq
        yield 'faq.list' => [fn (ContentClient $c) => $c->faq->list(['category' => 'levering', 'locale' => 'nl']), 'GET', '/faq', ['category' => 'levering', 'locale' => 'nl'], null];
        yield 'faq.categories.list' => [fn (ContentClient $c) => $c->faq->categories->list(), 'GET', '/faq/categories', [], null];
        yield 'faq.categories.create' => [fn (ContentClient $c) => $c->faq->categories->create(['name' => 'Levering']), 'POST', '/faq/categories', [], ['name' => 'Levering']];
        yield 'faq.categories.update' => [fn (ContentClient $c) => $c->faq->categories->update('fc1', ['name' => 'X']), 'PATCH', '/faq/categories/fc1', [], ['name' => 'X']];
        yield 'faq.categories.delete' => [fn (ContentClient $c) => $c->faq->categories->delete('fc1'), 'DELETE', '/faq/categories/fc1', [], null];
        yield 'faq.items.list' => [fn (ContentClient $c) => $c->faq->items->list(['status' => 'draft', 'page' => 1]), 'GET', '/faq/items', ['status' => 'draft', 'page' => '1'], null];
        yield 'faq.items.get' => [fn (ContentClient $c) => $c->faq->items->get('fi1'), 'GET', '/faq/items/fi1', [], null];
        yield 'faq.items.create' => [fn (ContentClient $c) => $c->faq->items->create(['question' => 'Wanneer?', 'answerHtml' => '<p>Morgen</p>']), 'POST', '/faq/items', [], ['question' => 'Wanneer?', 'answerHtml' => '<p>Morgen</p>']];
        yield 'faq.items.update' => [fn (ContentClient $c) => $c->faq->items->update('fi1', ['question' => 'X']), 'PATCH', '/faq/items/fi1', [], ['question' => 'X']];
        yield 'faq.items.publish' => [fn (ContentClient $c) => $c->faq->items->publish('fi1'), 'POST', '/faq/items/fi1/publish', [], []];
        yield 'faq.items.unpublish' => [fn (ContentClient $c) => $c->faq->items->unpublish('fi1'), 'POST', '/faq/items/fi1/unpublish', [], []];
        yield 'faq.items.reorder' => [fn (ContentClient $c) => $c->faq->items->reorder(['fi2', 'fi1']), 'POST', '/faq/items/reorder', [], ['ids' => ['fi2', 'fi1']]];
        yield 'faq.items.delete' => [fn (ContentClient $c) => $c->faq->items->delete('fi1'), 'DELETE', '/faq/items/fi1', [], null];

        // events
        yield 'events.list' => [fn (ContentClient $c) => $c->events->list(['upcoming' => true, 'page' => 1, 'limit' => 6]), 'GET', '/events', ['upcoming' => 'true', 'page' => '1', 'limit' => '6'], null];
        yield 'events.list (all)' => [fn (ContentClient $c) => $c->events->list(), 'GET', '/events', [], null];
        yield 'events.get' => [fn (ContentClient $c) => $c->events->get('open-dag'), 'GET', '/events/open-dag', [], null];
        yield 'events.register' => [fn (ContentClient $c) => $c->events->register('open-dag', ['email' => 'a@b.nl', 'tickets' => [['ticketTypeId' => 't1', 'quantity' => 2]]]), 'POST', '/events/open-dag/registrations', [], ['email' => 'a@b.nl', 'tickets' => [['ticketTypeId' => 't1', 'quantity' => 2]]]];
        yield 'events.startPayment' => [fn (ContentClient $c) => $c->events->startPayment('reg1'), 'POST', '/events/registrations/reg1/payment', [], []];
        yield 'events.tickets' => [fn (ContentClient $c) => $c->events->tickets('open-dag', ['q' => 'jan', 'checkedIn' => false, 'page' => 1, 'limit' => 50]), 'GET', '/events/open-dag/tickets', ['q' => 'jan', 'checkedIn' => 'false', 'page' => '1', 'limit' => '50'], null];
        yield 'events.checkIn' => [fn (ContentClient $c) => $c->events->checkIn('TICKET', ['eventId' => 'e1']), 'POST', '/events/checkin', [], ['token' => 'TICKET', 'eventId' => 'e1']];
        yield 'events.checkIn (no event)' => [fn (ContentClient $c) => $c->events->checkIn('TICKET'), 'POST', '/events/checkin', [], ['token' => 'TICKET']];
        yield 'events.create' => [fn (ContentClient $c) => $c->events->create(['title' => 'Open dag']), 'POST', '/events', [], ['title' => 'Open dag']];
        yield 'events.update' => [fn (ContentClient $c) => $c->events->update('open-dag', ['title' => 'X']), 'PATCH', '/events/open-dag', [], ['title' => 'X']];
        yield 'events.delete' => [fn (ContentClient $c) => $c->events->delete('open-dag'), 'DELETE', '/events/open-dag', [], null];
        yield 'events.ticketTypes.list' => [fn (ContentClient $c) => $c->events->ticketTypes->list('open-dag'), 'GET', '/events/open-dag/ticket-types', [], null];
        yield 'events.ticketTypes.create' => [fn (ContentClient $c) => $c->events->ticketTypes->create('open-dag', ['name' => 'Gratis']), 'POST', '/events/open-dag/ticket-types', [], ['name' => 'Gratis']];
        yield 'events.ticketTypes.update' => [fn (ContentClient $c) => $c->events->ticketTypes->update('open-dag', 't1', ['name' => 'X']), 'PATCH', '/events/open-dag/ticket-types/t1', [], ['name' => 'X']];
        yield 'events.ticketTypes.delete' => [fn (ContentClient $c) => $c->events->ticketTypes->delete('open-dag', 't1'), 'DELETE', '/events/open-dag/ticket-types/t1', [], null];
        yield 'events.getRegistration' => [fn (ContentClient $c) => $c->events->getRegistration('reg1'), 'GET', '/events/registrations/reg1', [], null];
        yield 'events.cancelRegistration' => [fn (ContentClient $c) => $c->events->cancelRegistration('reg1', ['reason' => 'ziek']), 'POST', '/events/registrations/reg1/cancel', [], ['reason' => 'ziek']];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query @param array<string, mixed>|null $body */
    #[DataProvider('calls')]
    public function testRequestShape(callable $call, string $method, string $path, array $query, ?array $body): void
    {
        $this->runCall($call, $method, $path, $query, $body);
    }

    public function testOrderAndReturnTokensTravelInHeadersOnly(): void
    {
        $this->http->always(FakeTransport::json([]));
        $client = $this->client();

        $client->returns->request('OTOKEN', ['lines' => []]);
        self::assertSame('OTOKEN', $this->http->last()->headers['X-FSC-Order-Token']);

        $client->returns->get('RTOKEN');
        self::assertSame('RTOKEN', $this->http->last()->headers['X-FSC-Return-Token']);
        self::assertStringNotContainsString('RTOKEN', $this->http->last()->url);

        $client->downloads->forBuyer('OTOKEN');
        self::assertSame('OTOKEN', $this->http->last()->headers['X-FSC-Order-Token']);
        self::assertStringNotContainsString('OTOKEN', $this->http->last()->url);

        $client->reviews->submit('p', ['rating' => 5], ['orderToken' => 'OTOKEN']);
        self::assertSame('OTOKEN', $this->http->last()->headers['X-FSC-Order-Token']);
        self::assertStringNotContainsString('OTOKEN', $this->http->last()->body ?? '');

        $client->reviews->submit('p', ['rating' => 5]);
        self::assertArrayNotHasKey('X-FSC-Order-Token', $this->http->last()->headers);
    }

    public function testUnwrapping(): void
    {
        $this->http->always(FakeTransport::json(['return' => ['id' => 'r1']]));
        self::assertSame(['id' => 'r1'], $this->client()->returns->get('RT'));
        self::assertSame(['id' => 'r1'], $this->client()->returns->getById('r1'));

        $this->http->always(FakeTransport::json(['review' => ['id' => 'rv1', 'status' => 'pending']]));
        self::assertSame(['id' => 'rv1', 'status' => 'pending'], $this->client()->reviews->submit('p', ['rating' => 5]));

        $this->http->always(FakeTransport::json(['downloads' => [['id' => 'd1']]]));
        self::assertSame([['id' => 'd1']], $this->client()->downloads->forOrder('o1'));
        self::assertSame([['id' => 'd1']], $this->client()->downloads->forBuyer('OT'));

        $this->http->always(FakeTransport::json(['download' => ['id' => 'd2']]));
        self::assertSame(['id' => 'd2'], $this->client()->downloads->resetLink('o1', 'l1'));

        $this->http->always(FakeTransport::json(['categories' => [['id' => 'fc1']]]));
        self::assertSame([['id' => 'fc1']], $this->client()->faq->categories->list());

        $this->http->always(FakeTransport::json(['item' => ['id' => 'fi1']]));
        self::assertSame(['id' => 'fi1'], $this->client()->faq->items->get('fi1'));
        self::assertSame(['id' => 'fi1'], $this->client()->faq->items->create(['question' => 'x']));

        $this->http->always(FakeTransport::json(['event' => ['id' => 'e1']]));
        self::assertSame(['id' => 'e1'], $this->client()->events->get('open-dag'));
    }

    public function testListsTakeTotalFromTheHeader(): void
    {
        $this->http->always(FakeTransport::json(['events' => []], 200, ['X-Total-Count' => '9']));
        self::assertSame(9, $this->client()->events->list()['total']);

        $this->http->always(FakeTransport::json(['reviews' => []], 200, ['X-Total-Count' => '11']));
        self::assertSame(11, $this->client()->reviews->list('p')['total']);
        self::assertSame(11, $this->client()->reviews->listAll()['total']);

        $this->http->always(FakeTransport::json(['returns' => []], 200, ['X-Total-Count' => '2']));
        self::assertSame(2, $this->client()->returns->list()['total']);

        $this->http->always(FakeTransport::json(['items' => []], 200, ['X-Total-Count' => '5']));
        self::assertSame(5, $this->client()->faq->items->list()['total']);
    }

    public function testAddFileIsMultipartAndKeepsOrRenamesTheFile(): void
    {
        $this->http->always(FakeTransport::json(['file' => ['id' => 'f1']], 201));
        $file = new UploadFile('PDFDATA', 'gids.pdf', 'application/pdf');

        $result = $this->client()->downloads->addFile('p1', $file, ['variantId' => 'v1']);
        self::assertSame(['id' => 'f1'], $result);
        self::assertSame('/products/p1/files', $this->http->lastPath());
        self::assertNull($this->http->last()->body);
        self::assertSame($file, $this->http->last()->multipart['file']);
        self::assertSame('v1', $this->http->last()->multipart['variantId']);

        $this->client()->downloads->addFile('p1', $file, ['fileName' => 'nieuw.pdf']);
        $sent = $this->http->last()->multipart['file'];
        self::assertSame('nieuw.pdf', $sent->filename);
        self::assertSame('PDFDATA', $sent->contents);
        self::assertSame('application/pdf', $sent->contentType);
        self::assertArrayNotHasKey('variantId', $this->http->last()->multipart);
    }

    public function testFaqListIsCachedUnderItsTag(): void
    {
        $cache = new VersionedTagCache(new ArrayStore());
        $client = $this->client(self::PUB, ['cache' => $cache]);
        $this->http->always(FakeTransport::json(['categories' => []]));

        $client->faq->list();
        $client->faq->list();
        self::assertCount(1, $this->http->requests);

        $cache->invalidateTags(['content:faq']);
        $client->faq->list();
        self::assertCount(2, $this->http->requests);

        $client->events->list();
        $client->events->list();
        self::assertCount(4, $this->http->requests, 'events are never cached');
    }
}
