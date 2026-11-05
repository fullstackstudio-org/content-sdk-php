<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

/**
 * The inert marker behind `<x-content::begin-checkout />`, `<x-content::appointment-booked />` and `<x-content::purchase />`: a hidden
 * `<span data-fsc-fire="<event>">` that the analytics helper (`fsc.js`) fires once per page through every active tracker. Event names are
 * part of the data contract with the Hub's default goals and funnels (`begin_checkout`, `appointment_booked`, revenue event `purchase`).
 *
 * Props never carry personal data: only short scalar values under a safe key, never under a key that names a person or contact detail, and
 * never a string that contains an `@`. The page keeps its own markup; the marker is a single hidden element.
 */
final class ConversionMarker
{
    public const BEGIN_CHECKOUT = 'begin_checkout';

    public const APPOINTMENT_BOOKED = 'appointment_booked';

    public const PURCHASE = 'purchase';

    /** Keys that could hold a person's data; refused whatever the value. */
    private const FORBIDDEN_KEY = '/(name|mail|phone|tel|mobile|address|street|zip|postcode|city|ip|token|password|secret|message|body|content|note|data)/';

    /**
     * @param  array<string, mixed>  $props
     * @param  string|null  $once  a reference (order number, booking id): the event then also fires once per browser session
     * @param  array{amount: float, currency: string}|null  $revenue  fire a revenue event instead of a plain event
     */
    public static function html(string $event, array $props = [], ?string $once = null, ?array $revenue = null): string
    {
        $attributes = ['hidden' => '', 'aria-hidden' => 'true', 'data-fsc-fire' => $event];

        if ($revenue !== null && $revenue['amount'] > 0 && preg_match('/^[A-Z]{3}$/', $revenue['currency']) === 1) {
            $attributes['data-fsc-revenue'] = rtrim(rtrim(number_format($revenue['amount'], 4, '.', ''), '0'), '.');
            $attributes['data-fsc-currency'] = $revenue['currency'];
        }

        $once = self::value($once);

        if ($once !== null) {
            $attributes['data-fsc-once'] = (string) $once;
        }

        foreach (self::cleanProps($props) as $key => $value) {
            $attributes['data-fsc-prop-' . str_replace('_', '-', $key)] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . ($value === '' ? '' : '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"');
        }

        return '<span' . $html . '></span>';
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, string|int|float|bool>
     */
    public static function cleanProps(array $props): array
    {
        $clean = [];

        foreach ($props as $key => $value) {
            $key = strtolower((string) $key);

            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                continue;
            }

            $value = self::value($value);

            if ($value !== null && count($clean) < 10) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** A prop value that is safe to print, or null: scalar, short, control characters removed, no `@`. */
    private static function value(mixed $value): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

        return $value === '' || str_contains($value, '@') ? null : mb_substr($value, 0, 120);
    }

    /** A site path for a prop (`/afspraak-maken`): a leading slash, no query or fragment, no personal-looking segment; null otherwise. */
    public static function path(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = explode('#', explode('?', trim($path), 2)[0], 2)[0];

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || strlen($path) > 200 || str_contains($path, '@')) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9\/_.~%+-]+$/', $path) === 1 ? $path : null;
    }
}
