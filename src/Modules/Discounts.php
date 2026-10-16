<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Discount codes and automatic promotions (`$client->discounts`; module `discounts`, server token). Pricing a cart is `cart->applyCode`. */
final class Discounts
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array{q?: string, active?: bool, kind?: string} $params @param array<string, mixed> $options @return array{discounts: list<array<string, mixed>>, total: int} */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/discounts', 'query' => Arr::pick($params, ['q', 'active', 'kind']), 'options' => $options])->data;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/discounts/' . ModuleContext::seg($id), 'options' => $options])->data['discount'] ?? [];
    }

    /** `label` and `kind` are required. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/discounts', $input, $options)['discount'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> `discount`, `changed` */
    public function update(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/discounts/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/discounts/' . ModuleContext::seg($id), null, $options);
    }
}
