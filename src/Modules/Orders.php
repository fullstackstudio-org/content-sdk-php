<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Placing, reading and handling orders (`$client->orders`; server token, `orders:read` / `orders:write`). */
final class Orders
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Places an order. Send only WHAT is ordered: Content prices it. Pass `expectedTotalCents` (the total you showed, e.g. from `cart->applyCode`)
     * to get a 409 `price_changed` instead of a surprise. Other errors: 409 `out_of_stock`, 409 `discount_invalid`, 429 `rate_limited`.
     * `accessToken` in the result is the buyer's token for returns and downloads (never put it in a URL).
     *
     * @param array<string, mixed> $input
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array{order: array<string, mixed>, accessToken: string|null}
     */
    public function place(array $input, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/orders',
            'body' => $input,
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /**
     * By id or order number, with lines and timeline. With `memberSession` only the member's own orders.
     *
     * @param array{memberSession?: string, timeoutMs?: int} $options
     * @return array{order: array<string, mixed>, accessToken: string|null}
     */
    public function get(string $ref, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/orders/' . ModuleContext::seg($ref), 'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null), 'options' => $options])->data;
    }

    /**
     * Newest first, without lines. With `memberSession` only the member's own orders.
     *
     * @param array{status?: string, open?: bool, q?: string, customerId?: string, page?: int, limit?: int} $params
     * @param array{memberSession?: string, timeoutMs?: int} $options
     * @return array<string, mixed> `orders`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/orders',
            'query' => Arr::pick($params, ['status', 'open', 'q', 'customerId', 'page', 'limit']),
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null),
            'options' => $options,
        ]));
    }

    /**
     * One step further in the status machine; see `order.allowedTransitions`. 409 `conflict` when it is not allowed.
     *
     * @param array{status: string, note?: string} $input
     * @param array<string, mixed> $options
     * @return array{order: array<string, mixed>, changed: bool}
     */
    public function transition(string $ref, array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($ref) . '/transition', $input, $options);
    }

    /**
     * Cancels (stock is returned; cancelling does not refund). With `memberSession` only an own, unpaid order.
     *
     * @param array{reason?: string} $input
     * @param array{memberSession?: string, timeoutMs?: int} $options
     * @return array{order: array<string, mixed>, changed: bool}
     */
    public function cancel(string $ref, array $input = [], array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/orders/' . ModuleContext::seg($ref) . '/cancel',
            'body' => $input,
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null),
            'options' => $options,
        ])->data;
    }

    /** An internal note in the timeline. @param array<string, mixed> $options @return array{event: array<string, mixed>} */
    public function addNote(string $ref, string $body, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($ref) . '/notes', ['body' => $body], $options);
    }

    /** Creates (or replaces) the buyer's access token. @param array<string, mixed> $options @return array{accessToken: string} */
    public function createAccessToken(string $ref, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($ref) . '/access-token', [], $options);
    }

    /**
     * Edits an order (lines, addresses, ...); a refund difference is reported in `refund`.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function update(string $ref, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/orders/' . ModuleContext::seg($ref), $input, $options);
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function revisions(string $ref, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/orders/' . ModuleContext::seg($ref) . '/revisions', 'options' => $options])->data['revisions'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> `revision`, `changed`, `refund` */
    public function retryRevisionRefund(string $ref, int $revision, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($ref) . '/revisions/' . ModuleContext::seg($revision) . '/refund', [], $options);
    }
}
