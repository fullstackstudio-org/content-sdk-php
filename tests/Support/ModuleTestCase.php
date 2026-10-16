<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Support;

use FullStackStudio\Content\ContentClient;
use PHPUnit\Framework\TestCase;

/** Base for the module tests: a client on a FakeTransport (never the network) and one assertion for "this call sent that request". */
abstract class ModuleTestCase extends TestCase
{
    protected const SERVER = 'fsc_srv_secrettoken123';
    protected const PUB = 'fsc_pub_publictoken123';

    protected FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
    }

    protected function client(string $token = self::SERVER, array $extra = []): ContentClient
    {
        return new ContentClient(['token' => $token, 'transport' => $this->http, ...$extra]);
    }

    /**
     * @param array<string, string> $query expected query (all of it)
     * @param array<string, mixed>|null $body expected JSON body; null = no body at all; [] = `{}`
     */
    protected function assertRequest(string $method, string $path, array $query = [], ?array $body = null): void
    {
        $request = $this->http->last();

        self::assertSame($method, $request->method);
        self::assertSame($path, $this->http->lastPath());
        self::assertSame($query, $this->http->lastQuery());

        if ($body === null) {
            self::assertNull($request->body);
        } elseif ($body === []) {
            self::assertSame('{}', $request->body);
        } else {
            self::assertEquals($body, $this->http->lastBody());
        }
    }

    /**
     * Runs a call against an empty `{}` answer (so unwrapping falls back to defaults) and asserts what it sent.
     *
     * @param callable(ContentClient): mixed $call
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    protected function runCall(callable $call, string $method, string $path, array $query = [], ?array $body = null): void
    {
        $this->http->always(FakeTransport::json([]));
        $call($this->client());
        $this->assertRequest($method, $path, $query, $body);
    }
}
