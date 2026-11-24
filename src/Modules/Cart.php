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
     * The labels of the discount and of the shipping method come in the visitor's language: an own `$input['locale']` wins, then
     * `$options['locale']`, then the client's content language on a multilingual project (nothing is sent on a single-language one).
     *
     * @param array{clientIp?: string, timeoutMs?: int, locale?: string} $options
     * @return array<string, mixed>
     */
    public function applyCode(array $input, array $options = []): array
    {
        $body = ['lines' => $input['lines'] ?? []];

        foreach (['code', 'email', 'shippingMethod', 'country', 'customerKind', 'vatNumber', 'locale'] as $name) {
            if (isset($input[$name]) && $input[$name] !== '') {
                $body[$name] = $input[$name];
            }
        }

        $body = $this->ctx->bodyWithLocale($body, $options);

        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/discounts/validate',
            'body' => $body,
            'headers' => $this->ctx->visitorHeaders(null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /**
     * The shipping options for a destination. `label` and `description` come in the visitor's language (the call's `$options['locale']`, else
     * the client's content language on a multilingual project); the answer has `locale` and `availableLocales`.
     *
     * @param array{country?: string, weight?: int|float, subtotal?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function shippingOptions(array $params = [], array $options = []): array
    {
        return $this->ctx->execute([
            'path' => '/shipping/options',
            'query' => [...Arr::pick($params, ['country', 'weight', 'subtotal']), 'locale' => $this->ctx->localeQuery($options)],
            'options' => $options,
        ])->data;
    }
}
