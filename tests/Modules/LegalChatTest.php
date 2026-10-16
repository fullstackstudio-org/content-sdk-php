<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LegalChatTest extends ModuleTestCase
{
    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>, 4: array<string, mixed>|null}> */
    public static function calls(): iterable
    {
        yield 'legal.list' => [fn (ContentClient $c) => $c->legal->list(['locale' => 'nl']), 'GET', '/legal', ['locale' => 'nl'], null];
        yield 'legal.get' => [fn (ContentClient $c) => $c->legal->get('terms', ['locale' => 'en']), 'GET', '/legal/terms', ['locale' => 'en'], null];
        yield 'legal.getVersion' => [fn (ContentClient $c) => $c->legal->getVersion('terms', 3), 'GET', '/legal/terms/versions/3', [], null];
        yield 'legal.accept' => [fn (ContentClient $c) => $c->legal->accept(['documents' => [['kind' => 'terms', 'version' => 3]], 'email' => 'a@b.nl']), 'POST', '/legal/accept', [], ['documents' => [['kind' => 'terms', 'version' => 3]], 'email' => 'a@b.nl']];
        yield 'legal.documents.list' => [fn (ContentClient $c) => $c->legal->documents->list(['locale' => 'nl']), 'GET', '/legal/documents', ['locale' => 'nl'], null];
        yield 'legal.documents.create' => [fn (ContentClient $c) => $c->legal->documents->create(['kind' => 'terms', 'title' => 'AV']), 'POST', '/legal/documents', [], ['kind' => 'terms', 'title' => 'AV']];
        yield 'legal.documents.get' => [fn (ContentClient $c) => $c->legal->documents->get('d1'), 'GET', '/legal/documents/d1', [], null];
        yield 'legal.documents.update' => [fn (ContentClient $c) => $c->legal->documents->update('d1', ['title' => 'X']), 'PATCH', '/legal/documents/d1', [], ['title' => 'X']];
        yield 'legal.documents.delete' => [fn (ContentClient $c) => $c->legal->documents->delete('d1'), 'DELETE', '/legal/documents/d1', [], null];
        yield 'legal.documents.versions' => [fn (ContentClient $c) => $c->legal->documents->versions('d1'), 'GET', '/legal/documents/d1/versions', [], null];
        yield 'legal.documents.createVersion' => [fn (ContentClient $c) => $c->legal->documents->createVersion('d1', ['bodyHtml' => '<p>x</p>']), 'POST', '/legal/documents/d1/versions', [], ['bodyHtml' => '<p>x</p>']];
        yield 'legal.documents.createVersion (no input)' => [fn (ContentClient $c) => $c->legal->documents->createVersion('d1'), 'POST', '/legal/documents/d1/versions', [], []];
        yield 'legal.documents.getVersion' => [fn (ContentClient $c) => $c->legal->documents->getVersion('d1', 2), 'GET', '/legal/documents/d1/versions/2', [], null];
        yield 'legal.documents.updateVersion' => [fn (ContentClient $c) => $c->legal->documents->updateVersion('d1', 2, ['summary' => null]), 'PATCH', '/legal/documents/d1/versions/2', [], ['summary' => null]];
        yield 'legal.documents.deleteVersion' => [fn (ContentClient $c) => $c->legal->documents->deleteVersion('d1', 2), 'DELETE', '/legal/documents/d1/versions/2', [], null];
        yield 'legal.documents.publish' => [fn (ContentClient $c) => $c->legal->documents->publish('d1', 2, ['effectiveAt' => '2026-12-01T00:00:00+01:00']), 'POST', '/legal/documents/d1/versions/2/publish', [], ['effectiveAt' => '2026-12-01T00:00:00+01:00']];
        yield 'legal.documents.publish (no input)' => [fn (ContentClient $c) => $c->legal->documents->publish('d1', 2), 'POST', '/legal/documents/d1/versions/2/publish', [], []];

        yield 'chat.config' => [fn (ContentClient $c) => $c->chat->config(), 'GET', '/chat/config', [], null];
        yield 'chat.open' => [fn (ContentClient $c) => $c->chat->open(['locale' => 'nl', 'turnstileToken' => 'ts']), 'POST', '/chat/conversations', [], ['locale' => 'nl', '_turnstile' => 'ts']];
        yield 'chat.open (empty)' => [fn (ContentClient $c) => $c->chat->open(), 'POST', '/chat/conversations', [], []];
        yield 'chat.get' => [fn (ContentClient $c) => $c->chat->get('cv1', 'CT'), 'GET', '/chat/conversations/cv1', [], null];
        yield 'chat.send' => [fn (ContentClient $c) => $c->chat->send('cv1', 'CT', 'Hallo'), 'POST', '/chat/conversations/cv1/messages', [], ['text' => 'Hallo']];
        yield 'chat.feedback' => [fn (ContentClient $c) => $c->chat->feedback('cv1', 'CT', 'msg1', -1), 'POST', '/chat/conversations/cv1/feedback', [], ['messageId' => 'msg1', 'value' => -1]];
        yield 'chat.feedback (removed)' => [fn (ContentClient $c) => $c->chat->feedback('cv1', 'CT', 'msg1', null), 'POST', '/chat/conversations/cv1/feedback', [], ['messageId' => 'msg1', 'value' => null]];
        yield 'chat.verifyRequest' => [fn (ContentClient $c) => $c->chat->verifyRequest('cv1', 'CT', 'a@b.nl'), 'POST', '/chat/conversations/cv1/verify/request', [], ['email' => 'a@b.nl']];
        yield 'chat.verifyConfirm' => [fn (ContentClient $c) => $c->chat->verifyConfirm('cv1', 'CT', '123456'), 'POST', '/chat/conversations/cv1/verify/confirm', [], ['code' => '123456']];
        yield 'chat.close' => [fn (ContentClient $c) => $c->chat->close('cv1', 'CT'), 'POST', '/chat/conversations/cv1/close', [], null];
        yield 'chat.list' => [fn (ContentClient $c) => $c->chat->list(['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'open', 'page' => 1, 'limit' => 10]), 'GET', '/chat/conversations', ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'open', 'page' => '1', 'limit' => '10'], null];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query @param array<string, mixed>|null $body */
    #[DataProvider('calls')]
    public function testRequestShape(callable $call, string $method, string $path, array $query, ?array $body): void
    {
        $this->runCall($call, $method, $path, $query, $body);
    }

    public function testLegalUnwrappingAndCaching(): void
    {
        $this->http->always(FakeTransport::json(['documents' => [['kind' => 'terms']]]));
        self::assertSame([['kind' => 'terms']], $this->client()->legal->list());

        $this->http->always(FakeTransport::json(['document' => ['kind' => 'terms', 'version' => 3]]));
        self::assertSame(['kind' => 'terms', 'version' => 3], $this->client()->legal->get('terms'));
        self::assertSame(['kind' => 'terms', 'version' => 3], $this->client()->legal->getVersion('terms', 3));

        $cache = new VersionedTagCache(new ArrayStore());
        $client = $this->client(self::PUB, ['cache' => $cache]);
        $this->http->requests = [];
        $client->legal->get('Terms');
        $client->legal->get('Terms');
        self::assertCount(1, $this->http->requests);
        $cache->invalidateTags(['content:legal:terms']);
        $client->legal->get('Terms');
        self::assertCount(2, $this->http->requests);

        $client->legal->list();
        $client->legal->list();
        self::assertCount(3, $this->http->requests);
        $cache->invalidateTags(['content:legal']);
        $client->legal->list();
        self::assertCount(4, $this->http->requests);

        $client->legal->accept(['documents' => []]);
        $client->legal->accept(['documents' => []]);
        self::assertCount(6, $this->http->requests, 'accept is never cached');
    }

    public function testLegalPdfPathsAndDownload(): void
    {
        $this->http->always(new Response(200, ['content-type' => 'application/pdf', 'content-disposition' => 'inline; filename="algemene-voorwaarden.pdf"'], 'PDFBYTES'));

        $current = $this->client()->legal->pdf('terms', ['locale' => 'nl']);
        self::assertSame(['bytes' => 'PDFBYTES', 'contentType' => 'application/pdf', 'fileName' => 'algemene-voorwaarden.pdf'], $current);
        self::assertSame('/legal/terms/pdf', $this->http->lastPath());
        self::assertSame(['locale' => 'nl'], $this->http->lastQuery());

        $this->client()->legal->pdf('terms', ['version' => 2]);
        self::assertSame('/legal/terms/versions/2/pdf', $this->http->lastPath());
    }

    public function testLegalAcceptForwardsMemberSessionAndIp(): void
    {
        $this->http->always(FakeTransport::json(['accepted' => true]));
        $this->client()->legal->accept(['documents' => []], ['memberSession' => 'S', 'clientIp' => '203.0.113.5']);

        self::assertSame('S', $this->http->last()->headers['X-FSC-Member-Session']);
        self::assertSame('203.0.113.5', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client(self::PUB)->legal->accept(['documents' => []], ['memberSession' => 'S', 'clientIp' => '203.0.113.5']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);
    }

    public function testChatConversationTokenTravelsAsAHeaderNeverInAUrl(): void
    {
        $this->http->always(FakeTransport::json([]));
        $client = $this->client();
        $calls = [
            fn () => $client->chat->get('cv1', 'SECRET-CT'),
            fn () => $client->chat->send('cv1', 'SECRET-CT', 'x'),
            fn () => $client->chat->feedback('cv1', 'SECRET-CT', 'm', 1),
            fn () => $client->chat->verifyRequest('cv1', 'SECRET-CT', 'a@b.nl'),
            fn () => $client->chat->verifyConfirm('cv1', 'SECRET-CT', '123456'),
            fn () => $client->chat->close('cv1', 'SECRET-CT'),
        ];

        foreach ($calls as $call) {
            $call();
            $request = $this->http->last();
            self::assertSame('SECRET-CT', $request->headers['X-FSC-Conversation']);
            self::assertStringNotContainsString('SECRET-CT', $request->url);
            self::assertStringNotContainsString('SECRET-CT', $request->body ?? '');
        }
    }

    public function testChatMemberSessionAndIpHeaders(): void
    {
        $this->http->always(FakeTransport::json([]));
        $this->client()->chat->send('cv1', 'CT', 'x', ['memberSession' => 'S', 'clientIp' => '203.0.113.5']);
        $headers = $this->http->last()->headers;
        self::assertSame('S', $headers['X-FSC-Member-Session']);
        self::assertSame('203.0.113.5', $headers['X-FSC-Client-Ip']);
        self::assertSame('CT', $headers['X-FSC-Conversation']);

        $this->client()->chat->open(['locale' => 'nl'], ['memberSession' => 'S', 'clientIp' => '203.0.113.5']);
        self::assertSame('S', $this->http->last()->headers['X-FSC-Member-Session']);
        self::assertArrayNotHasKey('X-FSC-Conversation', $this->http->last()->headers);

        $this->client(self::PUB)->chat->send('cv1', 'CT', 'x', ['clientIp' => '203.0.113.5']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);
        self::assertSame('CT', $this->http->last()->headers['X-FSC-Conversation']);
    }

    public function testChatConfigUnwraps(): void
    {
        $this->http->always(FakeTransport::json(['config' => ['name' => 'Stacko']]));
        self::assertSame(['name' => 'Stacko'], $this->client()->chat->config());
    }
}
