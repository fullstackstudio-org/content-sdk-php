<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/**
 * Invoices (`$client->invoices`; module `invoicing`, server token with `invoicing:read` / `invoicing:write`). The `*ByToken` methods are
 * the buyer's public side (a pay-token page). PDFs come back as `['bytes', 'contentType', 'fileName']`.
 */
final class Invoices
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the invoice */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices', $input, $options)['invoice'] ?? [];
    }

    /**
     * @param array{status?: string, kind?: string, customer?: string, q?: string, from?: string, to?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed> `invoices`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/invoices',
            'query' => Arr::pick($params, ['status', 'kind', 'customer', 'q', 'from', 'to', 'page', 'limit']),
            'options' => $options,
        ]));
    }

    /** By id or invoice number. @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $ref, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/invoices/' . ModuleContext::seg($ref), 'options' => $options])->data['invoice'] ?? [];
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/invoices/' . ModuleContext::seg($id), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/invoices/' . ModuleContext::seg($id), null, $options);
    }

    /** Gives a draft its number and locks it. @param array<string, mixed> $options @return array<string, mixed> the invoice */
    public function finalize(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/finalize', [], $options)['invoice'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the credit note */
    public function createCreditNote(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/credit-note', $input, $options)['invoice'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function markPaid(string $id, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/mark-paid', $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function markUnpaid(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/mark-unpaid', [], $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> the invoice */
    public function fromOrder(string $orderId, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/from-order/' . ModuleContext::seg($orderId), [], $options)['invoice'] ?? [];
    }

    /** @param array<string, mixed> $options @return array{bytes: string, contentType: string, fileName: string|null} */
    public function pdf(string $ref, array $options = []): array
    {
        return $this->ctx->download(['path' => '/invoices/' . ModuleContext::seg($ref) . '/pdf', 'options' => $options]);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function retryPdf(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/pdf/retry', [], $options);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function send(string $id, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/send', $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function remind(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/remind', [], $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function rotatePayToken(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/pay-token/rotate', [], $options);
    }

    /** @param array<string, mixed> $options @return array{url: string, expiresAt: string|null} */
    public function payLink(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/invoices/' . ModuleContext::seg($id) . '/pay-link', 'options' => $options])->data;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function startPayment(string $ref, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($ref) . '/payments', null, $options);
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listPayments(string $ref, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/invoices/' . ModuleContext::seg($ref) . '/payments', 'options' => $options])->data['payments'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function cancelPayments(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/' . ModuleContext::seg($id) . '/payments/cancel', [], $options);
    }

    // --- The buyer's side (pay token) -----------------------------------------------------------------------------------------

    /** @param array{payment?: string} $params @param array<string, mixed> $options @return array<string, mixed> */
    public function byToken(string $token, array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/invoices/by-token/' . ModuleContext::seg($token), 'query' => Arr::pick($params, ['payment']), 'options' => $options])->data;
    }

    /** @param array<string, mixed> $options @return array{bytes: string, contentType: string, fileName: string|null} */
    public function pdfByToken(string $token, array $options = []): array
    {
        return $this->ctx->download(['path' => '/invoices/by-token/' . ModuleContext::seg($token) . '/pdf', 'options' => $options]);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function payByToken(string $token, array $options = []): array
    {
        return $this->ctx->write('POST', '/invoices/by-token/' . ModuleContext::seg($token) . '/payments', null, $options);
    }
}
