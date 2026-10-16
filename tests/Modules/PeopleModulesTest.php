<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PeopleModulesTest extends ModuleTestCase
{
    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>, 4: array<string, mixed>|null}> */
    public static function calls(): iterable
    {
        // customers
        yield 'customers.list' => [fn (ContentClient $c) => $c->customers->list(['q' => 'bv', 'tag' => 'vip', 'kind' => 'business', 'page' => 1, 'limit' => 20]), 'GET', '/customers', ['q' => 'bv', 'tag' => 'vip', 'kind' => 'business', 'page' => '1', 'limit' => '20'], null];
        yield 'customers.get' => [fn (ContentClient $c) => $c->customers->get('c1'), 'GET', '/customers/c1', [], null];
        yield 'customers.create' => [fn (ContentClient $c) => $c->customers->create(['name' => 'Acme']), 'POST', '/customers', [], ['name' => 'Acme']];
        yield 'customers.update' => [fn (ContentClient $c) => $c->customers->update('c1', ['phone' => null]), 'PATCH', '/customers/c1', [], ['phone' => null]];
        yield 'customers.delete' => [fn (ContentClient $c) => $c->customers->delete('c1'), 'DELETE', '/customers/c1', [], null];
        yield 'customers.listNotes' => [fn (ContentClient $c) => $c->customers->listNotes('c1'), 'GET', '/customers/c1/notes', [], null];
        yield 'customers.addNote' => [fn (ContentClient $c) => $c->customers->addNote('c1', 'gebeld'), 'POST', '/customers/c1/notes', [], ['body' => 'gebeld']];
        yield 'customers.deleteNote' => [fn (ContentClient $c) => $c->customers->deleteNote('c1', 'n1'), 'DELETE', '/customers/c1/notes/n1', [], null];
        yield 'customers.listMembers' => [fn (ContentClient $c) => $c->customers->listMembers('c1'), 'GET', '/customers/c1/members', [], null];
        yield 'customers.linkMember' => [fn (ContentClient $c) => $c->customers->linkMember('c1', ['memberId' => 'm1', 'role' => 'owner']), 'POST', '/customers/c1/members', [], ['memberId' => 'm1', 'role' => 'owner']];
        yield 'customers.unlinkMember' => [fn (ContentClient $c) => $c->customers->unlinkMember('c1', 'm1'), 'DELETE', '/customers/c1/members/m1', [], null];
        yield 'customers.orders' => [fn (ContentClient $c) => $c->customers->orders('c1'), 'GET', '/customers/c1/orders', [], null];
        yield 'customers.invoices' => [fn (ContentClient $c) => $c->customers->invoices('c1'), 'GET', '/customers/c1/invoices', [], null];

        // appointments
        yield 'appointments.services' => [fn (ContentClient $c) => $c->appointments->services(), 'GET', '/appointments/services', [], null];
        yield 'appointments.service' => [fn (ContentClient $c) => $c->appointments->service('s1'), 'GET', '/appointments/services/s1', [], null];
        yield 'appointments.resources' => [fn (ContentClient $c) => $c->appointments->resources(), 'GET', '/appointments/resources', [], null];
        yield 'appointments.availability' => [fn (ContentClient $c) => $c->appointments->availability(['service' => 's1', 'from' => '2026-11-01', 'to' => '2026-11-07', 'resource' => 'r1']), 'GET', '/appointments/availability', ['service' => 's1', 'from' => '2026-11-01', 'to' => '2026-11-07', 'resource' => 'r1'], null];
        yield 'appointments.book' => [fn (ContentClient $c) => $c->appointments->book(['serviceId' => 's1', 'startsAt' => '2026-11-02T10:00:00+01:00']), 'POST', '/appointments', [], ['serviceId' => 's1', 'startsAt' => '2026-11-02T10:00:00+01:00']];
        yield 'appointments.cancelWithToken' => [fn (ContentClient $c) => $c->appointments->cancelWithToken('a1', 'tok'), 'POST', '/appointments/a1/cancel', [], ['token' => 'tok']];
        yield 'appointments.restartPayment' => [fn (ContentClient $c) => $c->appointments->restartPayment('a1', 'tok'), 'POST', '/appointments/a1/payment', [], ['token' => 'tok']];
        yield 'appointments.list' => [fn (ContentClient $c) => $c->appointments->list(['status' => 'confirmed', 'service' => 's1', 'resource' => 'r1', 'from' => '2026-11-01', 'to' => '2026-11-30', 'q' => 'jan', 'page' => 2, 'limit' => 10]), 'GET', '/appointments', ['status' => 'confirmed', 'service' => 's1', 'resource' => 'r1', 'from' => '2026-11-01', 'to' => '2026-11-30', 'q' => 'jan', 'page' => '2', 'limit' => '10'], null];
        yield 'appointments.get' => [fn (ContentClient $c) => $c->appointments->get('a1'), 'GET', '/appointments/a1', [], null];
        yield 'appointments.linkCustomer' => [fn (ContentClient $c) => $c->appointments->linkCustomer('a1', null), 'PATCH', '/appointments/a1', [], ['customerId' => null]];
        yield 'appointments.confirm' => [fn (ContentClient $c) => $c->appointments->confirm('a1', ['notifyCustomer' => false]), 'POST', '/appointments/a1/confirm', [], ['notifyCustomer' => false]];
        yield 'appointments.complete' => [fn (ContentClient $c) => $c->appointments->complete('a1'), 'POST', '/appointments/a1/complete', [], []];
        yield 'appointments.cancel' => [fn (ContentClient $c) => $c->appointments->cancel('a1', ['reason' => 'ziek']), 'POST', '/appointments/a1/cancel', [], ['reason' => 'ziek']];
        yield 'appointments.createService' => [fn (ContentClient $c) => $c->appointments->createService(['name' => 'Knippen', 'durationMinutes' => 30]), 'POST', '/appointments/services', [], ['name' => 'Knippen', 'durationMinutes' => 30]];
        yield 'appointments.updateService' => [fn (ContentClient $c) => $c->appointments->updateService('s1', ['active' => false]), 'PATCH', '/appointments/services/s1', [], ['active' => false]];
        yield 'appointments.deleteService' => [fn (ContentClient $c) => $c->appointments->deleteService('s1'), 'DELETE', '/appointments/services/s1', [], null];
        yield 'appointments.listResources' => [fn (ContentClient $c) => $c->appointments->listResources(), 'GET', '/appointments/resources', [], null];
        yield 'appointments.createResource' => [fn (ContentClient $c) => $c->appointments->createResource(['name' => 'Stoel 1']), 'POST', '/appointments/resources', [], ['name' => 'Stoel 1']];
        yield 'appointments.updateResource' => [fn (ContentClient $c) => $c->appointments->updateResource('r1', ['active' => false]), 'PATCH', '/appointments/resources/r1', [], ['active' => false]];
        yield 'appointments.deleteResource' => [fn (ContentClient $c) => $c->appointments->deleteResource('r1'), 'DELETE', '/appointments/resources/r1', [], null];
        yield 'appointments.listRules' => [fn (ContentClient $c) => $c->appointments->listRules(), 'GET', '/appointments/rules', [], null];
        yield 'appointments.createRule' => [fn (ContentClient $c) => $c->appointments->createRule(['weekday' => 1, 'startTime' => '09:00', 'endTime' => '17:00']), 'POST', '/appointments/rules', [], ['weekday' => 1, 'startTime' => '09:00', 'endTime' => '17:00']];
        yield 'appointments.updateRule' => [fn (ContentClient $c) => $c->appointments->updateRule('ru1', ['endTime' => '18:00']), 'PATCH', '/appointments/rules/ru1', [], ['endTime' => '18:00']];
        yield 'appointments.deleteRule' => [fn (ContentClient $c) => $c->appointments->deleteRule('ru1'), 'DELETE', '/appointments/rules/ru1', [], null];
        yield 'appointments.listExceptions' => [fn (ContentClient $c) => $c->appointments->listExceptions(['includePast' => true]), 'GET', '/appointments/exceptions', ['includePast' => 'true'], null];
        yield 'appointments.createException' => [fn (ContentClient $c) => $c->appointments->createException(['kind' => 'closed', 'startsOn' => '2026-12-25']), 'POST', '/appointments/exceptions', [], ['kind' => 'closed', 'startsOn' => '2026-12-25']];
        yield 'appointments.updateException' => [fn (ContentClient $c) => $c->appointments->updateException('e1', ['reason' => 'kerst']), 'PATCH', '/appointments/exceptions/e1', [], ['reason' => 'kerst']];
        yield 'appointments.deleteException' => [fn (ContentClient $c) => $c->appointments->deleteException('e1'), 'DELETE', '/appointments/exceptions/e1', [], null];

        // tickets (JSON variants)
        yield 'tickets.getByToken' => [fn (ContentClient $c) => $c->tickets->getByToken('fsc_tk_x'), 'GET', '/tickets/by-token', [], null];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query @param array<string, mixed>|null $body */
    #[DataProvider('calls')]
    public function testRequestShape(callable $call, string $method, string $path, array $query, ?array $body): void
    {
        $this->runCall($call, $method, $path, $query, $body);
    }

    public function testUnwrapping(): void
    {
        $this->http->always(FakeTransport::json(['customer' => ['id' => 'c1']]));
        self::assertSame(['id' => 'c1'], $this->client()->customers->get('c1'));
        self::assertSame(['id' => 'c1'], $this->client()->customers->create(['name' => 'x']));

        $this->http->always(FakeTransport::json(['note' => ['id' => 'n1']]));
        self::assertSame(['id' => 'n1'], $this->client()->customers->addNote('c1', 'x'));

        $this->http->always(FakeTransport::json(['services' => [['id' => 's1']]]));
        self::assertSame([['id' => 's1']], $this->client()->appointments->services());

        $this->http->always(FakeTransport::json(['payment' => ['checkoutUrl' => 'https://pay']]));
        self::assertSame(['checkoutUrl' => 'https://pay'], $this->client()->appointments->restartPayment('a1', 't'));

        $this->http->always(FakeTransport::json(['rules' => [['id' => 'ru1']]]));
        self::assertSame([['id' => 'ru1']], $this->client()->appointments->listRules());

        $this->http->always(FakeTransport::json(['ticket' => ['id' => 't1']]));
        self::assertSame(['id' => 't1'], $this->client()->tickets->getByToken('x'));
        self::assertSame(['id' => 't1'], $this->client()->tickets->get('t1', ['memberSession' => 'S']));
    }

    public function testListsTakeTotalFromTheHeader(): void
    {
        $this->http->always(FakeTransport::json(['customers' => []], 200, ['X-Total-Count' => '12']));
        self::assertSame(12, $this->client()->customers->list()['total']);

        $this->http->always(FakeTransport::json(['appointments' => []], 200, ['X-Total-Count' => '3']));
        self::assertSame(3, $this->client()->appointments->list()['total']);
    }

    public function testAppointmentServicesAreCachedUnderTheirTag(): void
    {
        $cache = new VersionedTagCache(new ArrayStore());
        $client = $this->client(self::PUB, ['cache' => $cache]);
        $this->http->always(FakeTransport::json(['services' => []]));

        $client->appointments->services();
        $client->appointments->services();
        self::assertCount(1, $this->http->requests);

        $client->appointments->availability(['service' => 's1', 'from' => 'a', 'to' => 'b']);
        $client->appointments->availability(['service' => 's1', 'from' => 'a', 'to' => 'b']);
        self::assertCount(3, $this->http->requests, 'free times are never cached');
    }

    public function testBookForAMemberSendsHeaders(): void
    {
        $this->http->always(FakeTransport::json(['appointment' => ['id' => 'a1'], 'cancelToken' => 'ct'], 201));
        $result = $this->client()->appointments->book(['serviceId' => 's1', 'startsAt' => 'x'], ['memberSession' => 'S', 'clientIp' => '203.0.113.5']);

        self::assertSame('ct', $result['cancelToken']);
        self::assertSame('S', $this->http->last()->headers['X-FSC-Member-Session']);
        self::assertSame('203.0.113.5', $this->http->last()->headers['X-FSC-Client-Ip']);
    }

    public function testTicketCreateAsJsonWithIdempotencyKey(): void
    {
        $this->http->always(FakeTransport::json(['ticket' => ['id' => 't1', 'number' => 7], 'token' => 'fsc_tk_x'], 201));
        $result = $this->client()->tickets->create(
            ['subject' => 'Help', 'message' => 'Het werkt niet', 'requester' => ['email' => 'jan@example.nl'], 'priority' => 'high', 'turnstileToken' => 'ts', 'ignored' => 'x'],
            ['idempotencyKey' => 'key-1', 'memberSession' => 'S', 'clientIp' => '203.0.113.5'],
        );

        self::assertSame('fsc_tk_x', $result['token']);
        $this->assertRequest('POST', '/tickets', [], ['subject' => 'Help', 'message' => 'Het werkt niet', 'requester' => ['email' => 'jan@example.nl'], 'priority' => 'high', 'turnstileToken' => 'ts']);
        $headers = $this->http->last()->headers;
        self::assertSame('key-1', $headers['Idempotency-Key']);
        self::assertSame('S', $headers['X-FSC-Member-Session']);
        self::assertSame('203.0.113.5', $headers['X-FSC-Client-Ip']);
        self::assertNull($this->http->last()->multipart);
    }

    public function testTicketCreateWithFilesGoesAsMultipartWithRepeatedParts(): void
    {
        $this->http->always(FakeTransport::json(['ticket' => ['id' => 't1']], 201));
        $one = new UploadFile('AAA', 'a.png', 'image/png');
        $two = new UploadFile('BBB', 'b.pdf', 'application/pdf');

        $this->client()->tickets->create(['subject' => 'S', 'message' => 'M'], ['files' => [$one, $two]]);

        $request = $this->http->last();
        self::assertNull($request->body);
        self::assertSame(['subject' => 'S', 'message' => 'M'], json_decode($request->multipart['data'], true));
        self::assertSame([$one, $two], $request->multipart['files']);
        self::assertArrayNotHasKey('Content-Type', $request->headers);

        // The hand-built body repeats the `files` field once per file.
        self::assertTrue($request->hasRepeatedParts());
        [$type, $body] = $request->multipartBody();
        self::assertStringStartsWith('multipart/form-data; boundary=', $type);
        self::assertSame(2, substr_count($body, 'name="files"; filename='));
        self::assertStringContainsString('filename="a.png"', $body);
        self::assertStringContainsString('filename="b.pdf"', $body);
        self::assertStringContainsString('name="data"', $body);
    }

    public function testTicketFilesMustBeUploadFiles(): void
    {
        $this->expectException(ContentException::class);
        $this->client()->tickets->create(['subject' => 'S', 'message' => 'M'], ['files' => ['not a file']]);
    }

    public function testTicketMemberAndTokenRoutes(): void
    {
        $this->http->always(FakeTransport::json(['tickets' => []], 200, ['X-Total-Count' => '4']));
        $list = $this->client()->tickets->list(['status' => 'open', 'priority' => 'high', 'page' => 1, 'limit' => 5], ['memberSession' => 'S']);
        self::assertSame(4, $list['total']);
        self::assertSame(['status' => 'open', 'priority' => 'high', 'page' => '1', 'limit' => '5'], $this->http->lastQuery());
        self::assertSame('S', $this->http->last()->headers['X-FSC-Member-Session']);

        $this->http->always(FakeTransport::json(['message' => ['id' => 'm1']], 201));
        $this->client()->tickets->reply('t1', ['message' => 'Dank'], ['memberSession' => 'S', 'clientIp' => '203.0.113.5']);
        $this->assertRequest('POST', '/tickets/t1/messages', [], ['message' => 'Dank']);
        self::assertSame('S', $this->http->last()->headers['X-FSC-Member-Session']);

        $this->client()->tickets->reply('t1', ['message' => 'Dank', 'files' => [new UploadFile('X', 'x.txt', 'text/plain')]], ['memberSession' => 'S']);
        self::assertNotNull($this->http->last()->multipart);

        $this->client()->tickets->getByToken('fsc_tk_secret');
        self::assertSame('fsc_tk_secret', $this->http->last()->headers['X-FSC-Ticket-Token']);
        self::assertStringNotContainsString('fsc_tk_secret', $this->http->last()->url);

        $this->client()->tickets->replyByToken('fsc_tk_secret', ['message' => 'Hoi'], ['clientIp' => '203.0.113.5']);
        $this->assertRequest('POST', '/tickets/by-token/messages', [], ['message' => 'Hoi']);
        self::assertSame('fsc_tk_secret', $this->http->last()->headers['X-FSC-Ticket-Token']);
        self::assertSame('203.0.113.5', $this->http->last()->headers['X-FSC-Client-Ip']);
    }

    public function testTicketAttachmentsAreDownloads(): void
    {
        $this->http->always(new \FullStackStudio\Content\Http\Response(200, ['content-type' => 'image/png', 'content-disposition' => 'attachment; filename="scherm.png"'], 'PNGBYTES'));

        $file = $this->client()->tickets->attachment('t1', 'att 1', ['memberSession' => 'S']);
        self::assertSame(['bytes' => 'PNGBYTES', 'contentType' => 'image/png', 'fileName' => 'scherm.png'], $file);
        self::assertSame('/tickets/t1/attachments/att%201', $this->http->lastPath());
        self::assertSame('S', $this->http->last()->headers['X-FSC-Member-Session']);

        $file = $this->client()->tickets->attachmentByToken('fsc_tk_secret', 'att1');
        self::assertSame('PNGBYTES', $file['bytes']);
        self::assertSame('/tickets/by-token/attachments/att1', $this->http->lastPath());
        self::assertSame('fsc_tk_secret', $this->http->last()->headers['X-FSC-Ticket-Token']);
    }
}
