<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\ConversionMarker;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::purchase :order="$order" />` on the order thank-you page: the revenue event `purchase` (`fsa.revenue(total, currency,
 * {name: 'purchase', order: <number>})`). Once per page view and, because the order number is the reference, once per browser session per
 * order, so a reload does not count the order twice.
 *
 * Give the order from `Orders::get` (reads `number`, `totals.totalCents`, `currency` and the count of `lines`) or the values yourself:
 * `number`, `total-cents` (integer cents, VAT included, as the Hub prices it) and `currency` (default `EUR`). Nothing is rendered when
 * there is no positive total: a free or unknown order is not revenue. Never pass names, e-mail addresses or an address.
 */
final class Purchase extends Component
{
    /** @param array<string, mixed>|null $order */
    public function __construct(
        public readonly ?array $order = null,
        public readonly ?string $number = null,
        public readonly int|float|string|null $totalCents = null,
        public readonly ?string $currency = null,
    ) {
    }

    public function render(): View
    {
        $order = $this->order ?? [];
        $number = $this->number ?? (isset($order['number']) && is_scalar($order['number']) ? (string) $order['number'] : null);
        $cents = $this->totalCents ?? ($order['totals']['totalCents'] ?? null);
        $currency = strtoupper(trim((string) ($this->currency ?? $order['currency'] ?? 'EUR')));
        $lines = isset($order['lines']) && is_array($order['lines']) ? count($order['lines']) : null;

        if (!is_numeric($cents) || (float) $cents <= 0) {
            return view('content::components.marker', ['marker' => '']);
        }

        return view('content::components.marker', ['marker' => ConversionMarker::html(
            ConversionMarker::PURCHASE,
            ['order' => $number, 'items' => $lines],
            $number,
            ['amount' => round((float) $cents / 100, 2), 'currency' => $currency],
        )]);
    }
}
