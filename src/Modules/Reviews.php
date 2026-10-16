<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Product reviews (`$client->reviews`; module `reviews`). Reading and submitting are public; moderating needs a server token. */
final class Reviews
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** The published reviews of a product (slug or id). @param array{page?: int, limit?: int, rating?: int} $params @param array<string, mixed> $options @return array<string, mixed> */
    public function list(string $product, array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/products/' . ModuleContext::seg($product) . '/reviews',
            'query' => Arr::pick($params, ['page', 'limit', 'rating']),
            'options' => $options,
        ]));
    }

    /**
     * Submits a review. With `orderToken` (the buyer's access token, header `X-FSC-Order-Token`) it counts as a verified purchase.
     *
     * @param array<string, mixed> $input
     * @param array{orderToken?: string, timeoutMs?: int} $options
     * @return array<string, mixed> the review (with its `status`)
     */
    public function submit(string $product, array $input, array $options = []): array
    {
        $token = $options['orderToken'] ?? null;

        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/products/' . ModuleContext::seg($product) . '/reviews',
            'body' => $input,
            'headers' => $token ? ['X-FSC-Order-Token' => $token] : null,
            'options' => $options,
        ])->data['review'] ?? [];
    }

    /** Server token: all reviews of the project. @param array<string, mixed> $params @param array<string, mixed> $options @return array<string, mixed> */
    public function listAll(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute(['path' => '/reviews', 'query' => $params, 'options' => $options]));
    }

    /** Server token: `approve`, `reject`, `reply`, ... @param array<string, mixed> $input @param array<string, mixed> $options @return array{review: array<string, mixed>, changed: bool} */
    public function moderate(string $id, string $action, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/reviews/' . ModuleContext::seg($id) . '/' . ModuleContext::seg($action), $input, $options);
    }

    /** @param array<string, mixed> $options @return array{deleted: true, id: string} */
    public function remove(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/reviews/' . ModuleContext::seg($id), null, $options);
    }
}
