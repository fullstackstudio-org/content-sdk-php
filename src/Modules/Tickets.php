<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/**
 * Support requests from visitors and members (`$client->tickets`; module `tickets`; server token with `tickets:read` / `tickets:write`).
 * The requester's token (`fsc_tk_...`) is returned once by `create`; a visitor reads and answers their request with it (header, never a URL).
 * Internal notes never appear here. Files are {@see \FullStackStudio\Content\Http\UploadFile} objects (the request then goes as multipart).
 */
final class Tickets
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Files a request on behalf of a visitor or a member. The token in the result is shown once.
     *
     * @param array{subject: string, message: string, requester?: array<string, mixed>, priority?: string, turnstileToken?: string} $input
     * @param array{files?: list<\FullStackStudio\Content\Http\UploadFile>, idempotencyKey?: string, memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     *        `idempotencyKey`: the same key with the same input within 24 hours returns the same ticket (`replayed: true`).
     * @return array<string, mixed>
     */
    public function create(array $input, array $options = []): array
    {
        $extra = [];

        if (!empty($options['idempotencyKey'])) {
            $extra['Idempotency-Key'] = (string) $options['idempotencyKey'];
        }

        $body = ['subject' => $input['subject'] ?? null, 'message' => $input['message'] ?? null, ...Arr::pick($input, ['requester', 'priority', 'turnstileToken'])];

        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/tickets',
            ...Arr::withFiles($body, $options['files'] ?? null),
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null, $extra),
            'options' => $options,
        ])->data;
    }

    /**
     * The requests of a logged-in member (`memberSession` is required).
     *
     * @param array{status?: string, priority?: string, page?: int, limit?: int} $params
     * @param array{memberSession: string, timeoutMs?: int} $options
     * @return array<string, mixed> `tickets`, `page`, `limit`, `total`
     */
    public function list(array $params, array $options): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/tickets',
            'query' => Arr::pick($params, ['status', 'priority', 'page', 'limit']),
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null),
            'options' => $options,
        ]));
    }

    /** @param array{memberSession: string, timeoutMs?: int} $options @return array<string, mixed> */
    public function get(string $id, array $options): array
    {
        return $this->ctx->execute(['path' => '/tickets/' . ModuleContext::seg($id), 'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null), 'options' => $options])->data['ticket'] ?? [];
    }

    /**
     * @param array{message: string, files?: list<\FullStackStudio\Content\Http\UploadFile>} $input
     * @param array{memberSession: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function reply(string $id, array $input, array $options): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/tickets/' . ModuleContext::seg($id) . '/messages',
            ...Arr::withFiles(['message' => $input['message'] ?? null], $input['files'] ?? null),
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /** Read a request with the requester's token (header `X-FSC-Ticket-Token`, never in a URL). @param array<string, mixed> $options @return array<string, mixed> */
    public function getByToken(string $token, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/tickets/by-token', 'headers' => ['X-FSC-Ticket-Token' => $token], 'options' => $options])->data['ticket'] ?? [];
    }

    /**
     * @param array{message: string, files?: list<\FullStackStudio\Content\Http\UploadFile>} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function replyByToken(string $token, array $input, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/tickets/by-token/messages',
            ...Arr::withFiles(['message' => $input['message'] ?? null], $input['files'] ?? null),
            'headers' => $this->ctx->visitorHeaders(null, $options['clientIp'] ?? null, ['X-FSC-Ticket-Token' => $token]),
            'options' => $options,
        ])->data;
    }

    /**
     * An attachment of a member's request (`memberSession` is required).
     *
     * @param array{memberSession: string, timeoutMs?: int} $options
     * @return array{bytes: string, contentType: string, fileName: string|null}
     */
    public function attachment(string $id, string $attachmentId, array $options): array
    {
        return $this->ctx->download([
            'path' => '/tickets/' . ModuleContext::seg($id) . '/attachments/' . ModuleContext::seg($attachmentId),
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null),
            'options' => $options,
        ]);
    }

    /** @param array<string, mixed> $options @return array{bytes: string, contentType: string, fileName: string|null} */
    public function attachmentByToken(string $token, string $attachmentId, array $options = []): array
    {
        return $this->ctx->download([
            'path' => '/tickets/by-token/attachments/' . ModuleContext::seg($attachmentId),
            'headers' => ['X-FSC-Ticket-Token' => $token],
            'options' => $options,
        ]);
    }
}
