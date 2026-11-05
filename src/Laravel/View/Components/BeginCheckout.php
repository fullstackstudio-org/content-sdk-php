<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\ConversionMarker;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::begin-checkout />` on the checkout page: the event `begin_checkout`, once per page view (the Hub's default shop funnel
 * counts it as the step between product and purchase). Optional `items` (number of lines) and `value` (cart total in major units, e.g.
 * 49.95) are the only props. Fires through every active tracker, without consent (session-level, cookieless); renders one hidden element.
 */
final class BeginCheckout extends Component
{
    public function __construct(public readonly ?int $items = null, public readonly int|float|string|null $value = null)
    {
    }

    public function render(): View
    {
        $value = is_numeric($this->value) && (float) $this->value >= 0 ? round((float) $this->value, 2) : null;

        return view('content::components.marker', ['marker' => ConversionMarker::html(ConversionMarker::BEGIN_CHECKOUT, [
            'items' => $this->items !== null && $this->items >= 0 ? $this->items : null,
            'value' => $value,
        ])]);
    }
}
