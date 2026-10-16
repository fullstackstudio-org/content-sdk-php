<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/** Payments (`$client->payments`; module `payments`, server token with `payments:read` / `payments:write`). */
final class Payments
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Starts (or reuses an open) checkout for an unpaid order. Send the buyer to `payment.checkoutUrl`. The amount comes from the order.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function startForOrder(string $order, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($order) . '/payments', null, $options);
    }

    /** All attempts of an order, newest first. @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listForOrder(string $order, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/orders/' . ModuleContext::seg($order) . '/payments', 'options' => $options])->data['payments'] ?? [];
    }

    /**
     * Pay for an appointment (with `redirectUrl`) or a form submission. The same call as `admin->startPayment`.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function start(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/payments', $input, $options);
    }

    /** One payment; while it runs Content first asks Mollie for the current state. @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/payments/' . ModuleContext::seg($id), 'options' => $options])->data['payment'] ?? [];
    }

    /**
     * Refunds (part of) a payment. Always pass a `requestId`: the same key always returns the same refund.
     *
     * @param array{requestId?: string, amountCents?: int, description?: string} $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function refund(string $id, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/payments/' . ModuleContext::seg($id) . '/refunds', $input, $options);
    }
}
