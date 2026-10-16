<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Pricing a cart (`$client->cart`): discount codes and shipping options. Nothing is stored. */
final class Cart
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Prices a cart, optionally with a discount code. `lines` is required; `code`, `email`, `shippingMethod`, `country`, `customerKind` and
     * `vatNumber` are sent only when filled in. Pass the result's total as `expectedTotalCents` to `orders->place`.
     *
     * @param array<string, mixed> $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function applyCode(array $input, array $options = []): array
    {
        $body = ['lines' => $input['lines'] ?? []];

        foreach (['code', 'email', 'shippingMethod', 'country', 'customerKind', 'vatNumber'] as $name) {
            if (isset($input[$name]) && $input[$name] !== '') {
                $body[$name] = $input[$name];
            }
        }

        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/discounts/validate',
            'body' => $body,
            'headers' => $this->ctx->visitorHeaders(null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /**
     * @param array{country?: string, weight?: int|float, subtotal?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function shippingOptions(array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/shipping/options', 'query' => Arr::pick($params, ['country', 'weight', 'subtotal']), 'options' => $options])->data;
    }
}
