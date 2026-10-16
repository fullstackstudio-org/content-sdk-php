<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Support;

use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\Transport;
use FullStackStudio\Content\Http\TransportException;
use LogicException;

/** Records every request and answers from a queue (or a default). Never touches the network. */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|TransportException|callable(Request): Response> */
    private array $queue = [];

    private Response|null $default = null;

    public function __construct()
    {
        $this->default = self::json([]);
    }

    /** @param array<string, mixed>|list<mixed> $data @param array<string, string> $headers */
    public static function json(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_change_key_case(['content-type' => 'application/json', ...$headers], CASE_LOWER), json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param Response|TransportException|callable(Request): Response ...$responses */
    public function queue(Response|TransportException|callable ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    public function always(Response $response): self
    {
        $this->default = $response;

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? $this->default;

        if ($next instanceof TransportException) {
            throw $next;
        }

        if ($next instanceof Response) {
            return $next;
        }

        if (is_callable($next)) {
            return $next($request);
        }

        throw new LogicException('FakeTransport has nothing to answer with.');
    }

    public function last(): Request
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new LogicException('No request was made.');
    }

    /** @return array<string, mixed> */
    public function lastQuery(): array
    {
        parse_str((string) parse_url($this->last()->url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** Path after /api/v1, without query. */
    public function lastPath(): string
    {
        return substr((string) parse_url($this->last()->url, PHP_URL_PATH), strlen('/api/v1'));
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        return json_decode($this->last()->body ?? 'null', true);
    }
}
