<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Visitor\VisitorProxyException;
use Throwable;

/**
 * The one shared "the Hub is unreachable, stop asking" switch of the reads the SDK does while it renders a page (`content.hub`).
 *
 * - A timeout or a connection error (no answer at all) trips it for `content.hub.cooldown_seconds` (default 15). A 4xx/5xx answer does not:
 *   the Hub is up and answers, so asking again is cheap.
 * - While it is on, {@see self::read()} throws a {@see ContentException} with status 0 and code `hub_cooldown` without a Hub call, so a
 *   hanging Hub costs one timeout per cooldown window instead of one per request (or several per page).
 * - It lives in the `content.cache.store` (the default store when unset), so it is shared by every request and PHP worker of the pod.
 *   A cache that fails never blocks a read: without a cache there is simply no cooldown.
 *
 * Used by the fallback route and its path resolver, the head and meta (`getSeo`), robots.txt, the site standards, the redirect lookup
 * and the visitor page proxy. The site's own calls through the client are not affected.
 */
final class HubCooldown
{
    public const KEY = 'fsc:hub:cooldown:v1';

    /** Error code of the exception {@see self::read()} throws while the cooldown is on. */
    public const CODE = 'hub_cooldown';

    /** Whether the cooldown is on now. */
    public static function active(): bool
    {
        if (self::seconds() <= 0) {
            return false;
        }

        try {
            return self::store()->get(self::KEY) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Starts the cooldown when the error means "no answer from the Hub" (timeout, connection, DNS, TLS). Other errors are ignored. */
    public static function trip(Throwable $error): void
    {
        if (! self::unreachable($error) || ($seconds = self::seconds()) <= 0) {
            return;
        }

        try {
            self::store()->put(self::KEY, 1, $seconds);
        } catch (Throwable) {
            // No cache: no cooldown.
        }
    }

    /** Ends the cooldown (tests, or after a deploy that fixed the connection). */
    public static function clear(): void
    {
        try {
            self::store()->forget(self::KEY);
        } catch (Throwable) {
            // Nothing to clear.
        }
    }

    /**
     * Runs a Hub read under the cooldown: refuses at once while it is on, trips it when the read gets no answer. The exception of the read
     * is rethrown as is.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public static function read(callable $read): mixed
    {
        if (self::active()) {
            throw new ContentException(0, self::CODE, 'FullStack Hub was unreachable a moment ago; not asking again yet.');
        }

        try {
            return $read();
        } catch (Throwable $error) {
            self::trip($error);

            throw $error;
        }
    }

    /** Timeout in milliseconds of one read under the cooldown (`content.hub.timeout_ms`, default 2500). */
    public static function timeoutMs(): int
    {
        return max(100, (int) config('content.hub.timeout_ms', 2500));
    }

    /**
     * Call options of a read under the cooldown: the short timeout, plus `$extra`.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function options(array $extra = []): array
    {
        return ['timeoutMs' => self::timeoutMs(), ...$extra];
    }

    /** Whether an error is "no answer from the Hub": the cooldown's own refusal counts too (so it is never reported as a new failure). */
    public static function unreachable(Throwable $error): bool
    {
        if ($error instanceof ContentException) {
            return $error->status() === 0 && in_array($error->errorCode(), ['timeout', 'network_error', self::CODE], true);
        }

        if ($error instanceof VisitorProxyException) {
            return in_array($error->reason, [VisitorProxyException::TIMEOUT, VisitorProxyException::UNREACHABLE], true);
        }

        return $error instanceof TransportException;
    }

    /** Whether this is the cooldown's own refusal (no Hub call was made). */
    public static function isRefusal(Throwable $error): bool
    {
        return $error instanceof ContentException && $error->status() === 0 && $error->errorCode() === self::CODE;
    }

    private static function seconds(): int
    {
        return (int) config('content.hub.cooldown_seconds', 15);
    }

    private static function store(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('content.cache.store');

        return app('cache')->store(is_string($store) && $store !== '' ? $store : null);
    }
}
