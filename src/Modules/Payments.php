<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Payments (`$client->payments`; module `payments`, server token with `payments:read` / `payments:write`). */
final class Payments
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Starts (or reuses an open) checkout for an unpaid order. Send the buyer to `payment.checkoutUrl`. The amount comes from the order.
     * `$method` (optional): the id of a payment method from `methods()`; the provider then opens that method directly. An id that is not
     * offered is a 409 `payment_method_unavailable`; without it nothing changes.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function startForOrder(string $order, array $options = [], ?string $method = null): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($order) . '/payments', $method === null ? null : ['method' => $method], $options);
    }

    /** All attempts of an order, newest first. @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listForOrder(string $order, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/orders/' . ModuleContext::seg($order) . '/payments', 'options' => $options])->data['payments'] ?? [];
    }

    /**
     * Pay for an appointment (with `redirectUrl`) or a form submission. The same call as `admin->startPayment`.
     * `$method` (optional) is the id of a payment method from `methods()` (sent as `method`; it wins over a `method` in `$input`). An id that
     * is not offered is a 409 `payment_method_unavailable`; without it nothing changes.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function start(array $input, array $options = [], ?string $method = null): array
    {
        if ($method !== null) {
            $input['method'] = $method;
        }

        return $this->ctx->write('POST', '/payments', $input, $options);
    }

    /**
     * The payments of the project over every source (order, invoice, appointment, form submission, event registration), newest first.
     * Never names the payer. `status` may be a comma list; `view` is `refund`, `paid`, `open`, `failed` or `all`; `from` and `to` are dates
     * or timestamps.
     *
     * @param array{status?: string, subject?: string, view?: string, from?: string, to?: string, q?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed> `payments`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/payments',
            'query' => Arr::pick($params, ['status', 'subject', 'view', 'from', 'to', 'q', 'page', 'limit']),
            'options' => $options,
        ]));
    }

    /**
     * The payment methods the project can offer now, as `[{id, label}]` (the provider is not named): exactly the methods a start would accept,
     * so every listed `id` can be passed as `method` to `start()` or `startForOrder()`. `$source` (optional) is the kind of payment the buyer
     * is about to make (`order`, `invoice`, `appointment`, `form_submission` or `event_registration`): a project can send each source to
     * another provider, so pass it when you know it. Without it the answer is for the project's default provider. Never cached: a connection
     * can change any time.
     *
     * @param array<string, mixed> $options
     * @return list<array{id: string, label: string}>
     */
    public function methods(array $options = [], ?string $source = null): array
    {
        return $this->ctx->execute([
            'path' => '/payments/methods',
            'query' => $source === null ? [] : ['source' => $source],
            'options' => $options,
        ])->data['methods'] ?? [];
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
