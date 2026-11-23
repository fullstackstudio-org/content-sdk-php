<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * The print-once guard of the layout components. Every part of a layout (`meta`, `standards`, `errors`, `body-end`, `analytics`, `banner`,
 * ...) is printed by whichever component asks first; the second asker gets nothing. Never throws: outside a request that can hold the
 * flags (no container) a part counts as not printed yet.
 */
final class Parts
{
    /** Marks the part and tells whether it was already printed in this request. */
    public static function seen(string $part): bool
    {
        try {
            return app(PageContext::class)->printed($part);
        } catch (Throwable) {
            return false;
        }
    }

    /** The CSP nonce a component prints with: the given one, else Laravel's Vite nonce, else null. */
    public static function nonce(?string $nonce): ?string
    {
        if ($nonce !== null && $nonce !== '') {
            return $nonce;
        }

        try {
            $vite = class_exists(Vite::class) ? Vite::cspNonce() : null;
        } catch (Throwable) {
            $vite = null;
        }

        return is_string($vite) && $vite !== '' ? $vite : null;
    }
}
