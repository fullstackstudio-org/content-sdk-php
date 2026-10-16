<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BillingModulesTest extends ModuleTestCase
{
    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>, 4: array<string, mixed>|null}> */
    public static function calls(): iterable
    {
        yield 'invoices.create' => [fn (ContentClient $c) => $c->invoices->create(['customerId' => 'c1', 'lines' => []]), 'POST', '/invoices', [], ['customerId' => 'c1', 'lines' => []]];
        yield 'invoices.list' => [fn (ContentClient $c) => $c->invoices->list(['status' => 'open', 'kind' => 'invoice', 'customer' => 'c1', 'q' => '2026', 'from' => '2026-01-01', 'to' => '2026-12-31', 'page' => 1, 'limit' => 25]), 'GET', '/invoices', ['status' => 'open', 'kind' => 'invoice', 'customer' => 'c1', 'q' => '2026', 'from' => '2026-01-01', 'to' => '2026-12-31', 'page' => '1', 'limit' => '25'], null];
        yield 'invoices.get' => [fn (ContentClient $c) => $c->invoices->get('2026-0001'), 'GET', '/invoices/2026-0001', [], null];
        yield 'invoices.update' => [fn (ContentClient $c) => $c->invoices->update('i1', ['note' => 'x']), 'PATCH', '/invoices/i1', [], ['note' => 'x']];
        yield 'invoices.delete' => [fn (ContentClient $c) => $c->invoices->delete('i1'), 'DELETE', '/invoices/i1', [], null];
        yield 'invoices.finalize' => [fn (ContentClient $c) => $c->invoices->finalize('i1'), 'POST', '/invoices/i1/finalize', [], []];
        yield 'invoices.createCreditNote' => [fn (ContentClient $c) => $c->invoices->createCreditNote('i1', ['reason' => 'x']), 'POST', '/invoices/i1/credit-note', [], ['reason' => 'x']];
        yield 'invoices.markPaid' => [fn (ContentClient $c) => $c->invoices->markPaid('i1', ['method' => 'bank']), 'POST', '/invoices/i1/mark-paid', [], ['method' => 'bank']];
        yield 'invoices.markPaid (no input)' => [fn (ContentClient $c) => $c->invoices->markPaid('i1'), 'POST', '/invoices/i1/mark-paid', [], []];
        yield 'invoices.markUnpaid' => [fn (ContentClient $c) => $c->invoices->markUnpaid('i1'), 'POST', '/invoices/i1/mark-unpaid', [], []];
        yield 'invoices.fromOrder' => [fn (ContentClient $c) => $c->invoices->fromOrder('o1'), 'POST', '/invoices/from-order/o1', [], []];
        yield 'invoices.retryPdf' => [fn (ContentClient $c) => $c->invoices->retryPdf('i1'), 'POST', '/invoices/i1/pdf/retry', [], []];
        yield 'invoices.send' => [fn (ContentClient $c) => $c->invoices->send('i1', ['to' => 'a@b.nl']), 'POST', '/invoices/i1/send', [], ['to' => 'a@b.nl']];
        yield 'invoices.remind' => [fn (ContentClient $c) => $c->invoices->remind('i1'), 'POST', '/invoices/i1/remind', [], []];
        yield 'invoices.rotatePayToken' => [fn (ContentClient $c) => $c->invoices->rotatePayToken('i1'), 'POST', '/invoices/i1/pay-token/rotate', [], []];
        yield 'invoices.payLink' => [fn (ContentClient $c) => $c->invoices->payLink('i1'), 'GET', '/invoices/i1/pay-link', [], null];
        yield 'invoices.startPayment' => [fn (ContentClient $c) => $c->invoices->startPayment('i1'), 'POST', '/invoices/i1/payments', [], null];
        yield 'invoices.listPayments' => [fn (ContentClient $c) => $c->invoices->listPayments('i1'), 'GET', '/invoices/i1/payments', [], null];
        yield 'invoices.cancelPayments' => [fn (ContentClient $c) => $c->invoices->cancelPayments('i1'), 'POST', '/invoices/i1/payments/cancel', [], []];
        yield 'invoices.byToken' => [fn (ContentClient $c) => $c->invoices->byToken('tok', ['payment' => 'paid']), 'GET', '/invoices/by-token/tok', ['payment' => 'paid'], null];
        yield 'invoices.payByToken' => [fn (ContentClient $c) => $c->invoices->payByToken('tok'), 'POST', '/invoices/by-token/tok/payments', [], null];

        yield 'newsletter.lists.list' => [fn (ContentClient $c) => $c->newsletter->lists->list(), 'GET', '/newsletter/lists', [], null];
        yield 'newsletter.lists.get' => [fn (ContentClient $c) => $c->newsletter->lists->get('l1'), 'GET', '/newsletter/lists/l1', [], null];
        yield 'newsletter.lists.create' => [fn (ContentClient $c) => $c->newsletter->lists->create(['name' => 'Nieuws']), 'POST', '/newsletter/lists', [], ['name' => 'Nieuws']];
        yield 'newsletter.lists.update' => [fn (ContentClient $c) => $c->newsletter->lists->update('l1', ['name' => 'X']), 'PATCH', '/newsletter/lists/l1', [], ['name' => 'X']];
        yield 'newsletter.lists.delete' => [fn (ContentClient $c) => $c->newsletter->lists->delete('l1'), 'DELETE', '/newsletter/lists/l1', [], null];
        yield 'newsletter.subscribers.list' => [fn (ContentClient $c) => $c->newsletter->subscribers->list(['status' => 'confirmed', 'list' => 'l1', 'q' => 'jan', 'page' => 2, 'limit' => 50]), 'GET', '/newsletter/subscribers', ['status' => 'confirmed', 'list' => 'l1', 'q' => 'jan', 'page' => '2', 'limit' => '50'], null];
        yield 'newsletter.subscribers.get' => [fn (ContentClient $c) => $c->newsletter->subscribers->get('s1'), 'GET', '/newsletter/subscribers/s1', [], null];
        yield 'newsletter.subscribers.update' => [fn (ContentClient $c) => $c->newsletter->subscribers->update('s1', ['status' => 'unsubscribed']), 'PATCH', '/newsletter/subscribers/s1', [], ['status' => 'unsubscribed']];
        yield 'newsletter.subscribers.delete' => [fn (ContentClient $c) => $c->newsletter->subscribers->delete('s1'), 'DELETE', '/newsletter/subscribers/s1', [], null];
        yield 'newsletter.subscribers.import' => [fn (ContentClient $c) => $c->newsletter->subscribers->import(['subscribers' => [['email' => 'a@b.nl']]]), 'POST', '/newsletter/subscribers', [], ['subscribers' => [['email' => 'a@b.nl']]]];
        yield 'newsletter.campaigns.list' => [fn (ContentClient $c) => $c->newsletter->campaigns->list(['status' => 'draft', 'page' => 1, 'limit' => 5]), 'GET', '/newsletter/campaigns', ['status' => 'draft', 'page' => '1', 'limit' => '5'], null];
        yield 'newsletter.campaigns.get' => [fn (ContentClient $c) => $c->newsletter->campaigns->get('k1'), 'GET', '/newsletter/campaigns/k1', [], null];
        yield 'newsletter.campaigns.create' => [fn (ContentClient $c) => $c->newsletter->campaigns->create(['subject' => 'Hoi']), 'POST', '/newsletter/campaigns', [], ['subject' => 'Hoi']];
        yield 'newsletter.campaigns.update' => [fn (ContentClient $c) => $c->newsletter->campaigns->update('k1', ['subject' => 'X']), 'PATCH', '/newsletter/campaigns/k1', [], ['subject' => 'X']];
        yield 'newsletter.campaigns.delete' => [fn (ContentClient $c) => $c->newsletter->campaigns->delete('k1'), 'DELETE', '/newsletter/campaigns/k1', [], null];
        yield 'newsletter.campaigns.send' => [fn (ContentClient $c) => $c->newsletter->campaigns->send('k1'), 'POST', '/newsletter/campaigns/k1/send', [], []];
        yield 'newsletter.campaigns.schedule' => [fn (ContentClient $c) => $c->newsletter->campaigns->schedule('k1', '2026-11-01T09:00:00Z'), 'POST', '/newsletter/campaigns/k1/schedule', [], ['at' => '2026-11-01T09:00:00Z']];
        yield 'newsletter.campaigns.pause' => [fn (ContentClient $c) => $c->newsletter->campaigns->pause('k1'), 'POST', '/newsletter/campaigns/k1/pause', [], []];
        yield 'newsletter.campaigns.resume' => [fn (ContentClient $c) => $c->newsletter->campaigns->resume('k1'), 'POST', '/newsletter/campaigns/k1/resume', [], []];
        yield 'newsletter.campaigns.cancel' => [fn (ContentClient $c) => $c->newsletter->campaigns->cancel('k1'), 'POST', '/newsletter/campaigns/k1/cancel', [], []];
        yield 'newsletter.campaigns.test' => [fn (ContentClient $c) => $c->newsletter->campaigns->test('k1', 'me@example.nl'), 'POST', '/newsletter/campaigns/k1/test', [], ['to' => 'me@example.nl']];
        yield 'newsletter.campaigns.progress' => [fn (ContentClient $c) => $c->newsletter->campaigns->progress('k1'), 'GET', '/newsletter/campaigns/k1/progress', [], null];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query @param array<string, mixed>|null $body */
    #[DataProvider('calls')]
    public function testRequestShape(callable $call, string $method, string $path, array $query, ?array $body): void
    {
        $this->runCall($call, $method, $path, $query, $body);
    }

    public function testUnwrapping(): void
    {
        $this->http->always(FakeTransport::json(['invoice' => ['id' => 'i1']]));
        self::assertSame(['id' => 'i1'], $this->client()->invoices->get('i1'));
        self::assertSame(['id' => 'i1'], $this->client()->invoices->create([]));
        self::assertSame(['id' => 'i1'], $this->client()->invoices->finalize('i1'));
        self::assertSame(['id' => 'i1'], $this->client()->invoices->fromOrder('o1'));

        $this->http->always(FakeTransport::json(['payments' => [['id' => 'p1']]]));
        self::assertSame([['id' => 'p1']], $this->client()->invoices->listPayments('i1'));

        $this->http->always(FakeTransport::json(['result' => ['imported' => 2]]));
        self::assertSame(['imported' => 2], $this->client()->newsletter->subscribers->import(['subscribers' => []]));

        $this->http->always(FakeTransport::json(['campaign' => ['id' => 'k1']]));
        self::assertSame(['id' => 'k1'], $this->client()->newsletter->campaigns->pause('k1'));
        self::assertSame(['id' => 'k1'], $this->client()->newsletter->campaigns->get('k1'));

        $this->http->always(FakeTransport::json(['progress' => ['sent' => 3]]));
        self::assertSame(['sent' => 3], $this->client()->newsletter->campaigns->progress('k1'));
    }

    public function testScheduleAcceptsADateTimeAndSendsUtcMilliseconds(): void
    {
        $this->http->always(FakeTransport::json(['campaign' => []]));
        $this->client()->newsletter->campaigns->schedule('k1', new \DateTimeImmutable('2026-11-01 10:00:00', new \DateTimeZone('Europe/Amsterdam')));

        self::assertSame(['at' => '2026-11-01T09:00:00.000Z'], $this->http->lastBody());
    }

    public function testInvoicePdfsAreBinaryDownloads(): void
    {
        $this->http->always(new Response(200, ['content-type' => 'application/pdf', 'content-disposition' => 'attachment; filename="factuur-2026-0001.pdf"'], '%PDF-1.7 bytes'));

        $pdf = $this->client()->invoices->pdf('2026-0001');
        self::assertSame(['bytes' => '%PDF-1.7 bytes', 'contentType' => 'application/pdf', 'fileName' => 'factuur-2026-0001.pdf'], $pdf);
        self::assertSame('/invoices/2026-0001/pdf', $this->http->lastPath());

        $this->client()->invoices->pdfByToken('tok');
        self::assertSame('/invoices/by-token/tok/pdf', $this->http->lastPath());

        $this->client()->newsletter->subscribers->export(['status' => 'confirmed']);
        self::assertSame('/newsletter/subscribers/export', $this->http->lastPath());
        self::assertSame(['status' => 'confirmed'], $this->http->lastQuery());
    }

    public function testThePublicNewsletterRoutesStillWork(): void
    {
        $this->http->always(FakeTransport::json(['accepted' => true]));
        $this->client(self::PUB)->newsletter->subscribe(['email' => 'a@b.nl']);
        self::assertSame('/newsletter/subscribe', $this->http->lastPath());
    }
}
