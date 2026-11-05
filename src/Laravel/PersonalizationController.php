<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\ContentClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * FullStack Intelligence personalisation (phase 7) on the site's own first-party path (`content.intelligence.path`, default `_fsc/i`). Only
 * registered while `content.intelligence.enabled` AND `content.intelligence.personalization` are on.
 *
 * - `GET slots.js`: the small browser step (`resources/assets/fsi-slots.js`) that `<x-content::slot>` loads once per page.
 * - `POST decide { s: [slot keys], p: path, r?: referring path on this site, l?: page language, c: 0|1, pid? }`: asks the Hub which variant
 *   fits ({@see ContentClient::decideSlots()}) within `decide_timeout_ms`, applies the control group
 *   and answers `{ d: { slot: { v, g, f?, h? } } }` with `g` = `personal` (show the variant), `control` (held back: keep the default and
 *   measure as control), `customer` (functional variant for a logged-in portal customer, never held back) or `none` (no variant fits).
 *   A slot that is off or unknown is absent: the browser leaves it alone and measures nothing. Any problem (Hub away, timeout, module off,
 *   bad request) answers `{ d: {} }`: the default content stays. Never cached by a browser or the edge.
 *
 * Caching: only ANONYMOUS answers (no browser id, no recognition cookie) are cached, `decide_ttl` seconds, keyed by slots, pages and
 * language, so a visitor can never fill the site's cache with per-visitor entries. Circuit breaker: after a failure, timeout or 429 of the Hub
 * the site does not ask again for {@see self::BREAKER_SECONDS} seconds (one key per site), so a slow Hub never costs every page view its budget.
 *
 * Server-side rendering ({@see SlotDecisions}): the slot component asks {@see self::ask()} itself during the page render when the request
 * carries a signal, with the same bucket, `present()` and breaker, so the groups are identical to the browser path.
 *
 * Consent (D5): the tracker's browser id `pid` is only forwarded with `c: 1`; without it the Hub only sees the current and previous page.
 * The portal recognition cookie (`recognition_cookie`) is forwarded always but the Hub uses it only as "is a logged-in customer"
 * (functional, plan B.4). The stable bucket of the control group, PER SLOT (each slot has its own independent control group): with consent
 * from HMAC(app key, slot, browser id) (stable across visits); without consent from HMAC(app key, slot, day, IP, user agent) (stable for the
 * day, nothing stored, the IP never leaves this server).
 */
final class PersonalizationController
{
    public const MAX_BODY = 2048;

    public const MAX_SLOTS = 10;

    public const KEY = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

    public const LOCALE = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D';

    public const BREAKER_SECONDS = 10;

    public const BREAKER_KEY = 'fsi:decide:breaker';

    public const SLOW_KEY = 'fsi:decide:slow';

    public static function enabled(): bool
    {
        return IntelligenceProxy::enabled() && (bool) config('content.intelligence.personalization', false);
    }

    public function script(): Response
    {
        $source = (string) file_get_contents(__DIR__ . '/../../resources/assets/fsi-slots.js');

        return new Response($source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // The tag carries ?v=<sdk version>, so a long browser cache is safe.
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function decide(Request $request): Response
    {
        $empty = ['d' => new \stdClass()];

        if (!self::enabled()) {
            return self::json($empty, 404);
        }

        $length = (int) $request->headers->get('Content-Length', '0');
        $raw = (string) $request->getContent();

        if ($length > self::MAX_BODY || strlen($raw) > self::MAX_BODY) {
            return self::json($empty, 413);
        }

        $ip = self::visitorIp($request);

        if (RateLimiter::tooManyAttempts('fsi-decide:' . ($ip ?? 'none'), 120)) {
            return self::json($empty, 429);
        }

        RateLimiter::hit('fsi-decide:' . ($ip ?? 'none'), 60);
        $body = json_decode($raw, true);
        $input = is_array($body) ? self::input($body) : null;

        if ($input === null) {
            return self::json($empty, 400);
        }

        [$slots, $path, $referrer, $consent, $pid, $locale] = $input;
        $name = (string) config('content.intelligence.recognition_cookie', '');
        $cookie = $name !== '' ? $request->cookies->get($name) : null;
        $cookie = is_string($cookie) && $cookie !== '' && strlen($cookie) <= 200 ? $cookie : null;

        $answer = self::ask($slots, $path, $referrer, $consent ? $pid : null, $consent, $cookie, $locale);

        if (($answer['active'] ?? false) !== true || !is_array($answer['decisions'] ?? null)) {
            return self::json($empty, 200);
        }

        $control = max(0, min(50, (int) ($answer['controlPercent'] ?? 10)));
        $userAgent = (string) $request->headers->get('User-Agent', '');
        $out = [];

        foreach ($answer['decisions'] as $slot => $decision) {
            if (!is_string($slot) || !in_array($slot, $slots, true)) {
                continue;
            }

            $out[$slot] = self::present($decision, self::bucket($slot, $consent ? $pid : null, $ip, $userAgent) < $control);
        }

        return self::json(['d' => $out === [] ? new \stdClass() : $out], 200);
    }

    /**
     * The stable bucket 0-99 of the control group of one slot. Per slot, so every slot has its own independent control group. With consent:
     * from the browser id, the same on every visit. Without consent: a keyed daily hash of IP and user agent (Europe/Amsterdam day), the
     * same during the visit and the day; nothing is stored anywhere.
     */
    public static function bucket(string $slot, ?string $pid, ?string $ip, string $userAgent, ?string $day = null): int
    {
        $source = $pid !== null && $pid !== ''
            ? 'pid|' . $slot . '|' . $pid
            : 'anon|' . $slot . '|' . ($day ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')))->format('Y-m-d')) . '|' . ($ip ?? '') . '|' . substr($userAgent, 0, 300);
        $hash = hash_hmac('sha256', $source, 'fsi-bucket|' . (string) config('app.key', ''), true);

        return (int) (unpack('N', substr($hash, 0, 4))[1] % 100);
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array{0: list<string>, 1: string, 2: ?string, 3: bool, 4: ?string, 5: ?string}|null
     */
    private static function input(array $body): ?array
    {
        $slots = $body['s'] ?? null;

        if (!is_array($slots) || $slots === [] || count($slots) > self::MAX_SLOTS) {
            return null;
        }

        foreach ($slots as $slot) {
            if (!is_string($slot) || preg_match(self::KEY, $slot) !== 1) {
                return null;
            }
        }

        $path = self::path($body['p'] ?? null);

        if ($path === null) {
            return null;
        }

        $consent = ($body['c'] ?? 0) === 1 || ($body['c'] ?? false) === true;
        $pid = is_string($body['pid'] ?? null) && preg_match('/^[A-Za-z0-9-]{16,64}$/D', $body['pid']) === 1 ? $body['pid'] : null;

        $locale = is_string($body['l'] ?? null) ? strtolower($body['l']) : null;
        $locale = $locale !== null && preg_match(self::LOCALE, $locale) === 1 ? $locale : null;

        return [array_values(array_unique($slots)), $path, self::path($body['r'] ?? null), $consent, $pid, $locale];
    }

    /** A path on this site without query or fragment, at most 300 characters; null otherwise. */
    public static function path(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 300 || preg_match('#^/(?![/\\\\])[^\s]*$#D', $value) !== 1) {
            return null;
        }

        return (string) preg_replace('#[?\#].*$#', '', $value);
    }

    /**
     * @param list<string> $slots
     * @return array<string, mixed>
     */
    public static function ask(array $slots, string $path, ?string $referrer, ?string $pid, bool $consent, ?string $cookie, ?string $locale, ?int $timeoutMs = null, bool $shareable = true, ?int $slowMs = null): array
    {
        try {
            $cache = app('cache')->store();
        } catch (Throwable) {
            return [];
        }

        // Only anonymous answers are shared: the same for every visitor on these pages, so nobody can fill the cache per visitor.
        // `$shareable` false (server-side rendering with a referrer) never writes: arbitrary referrer paths must not fill the cache.
        $anonymous = $pid === null && $cookie === null && $shareable;
        $key = 'fsi:decide:' . hash('sha256', implode(',', $slots) . "\0" . $path . "\0" . ($referrer ?? '') . "\0" . ($locale ?? '') . "\0" . ($consent ? '1' : '0'));

        try {
            if ($anonymous && is_array($cached = $cache->get($key))) {
                return $cached;
            }

            if ($cache->has(self::BREAKER_KEY)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $startedAt = hrtime(true);

        try {
            $answer = app(ContentClient::class)->decideSlots($slots, $path, $referrer, $pid, $consent, $cookie, $locale, ['timeoutMs' => max(20, min(2000, $timeoutMs ?? (int) config('content.intelligence.decide_timeout_ms', 400)))]);
        } catch (Throwable) {
            // Hub away, too slow, rate limited (429) or the module off: the default content stays, and the Hub is left alone for a moment.
            try {
                $cache->put(self::BREAKER_KEY, 1, self::BREAKER_SECONDS);
            } catch (Throwable) {
                // No cache: no breaker, the default still stays.
            }

            return [];
        }

        // Slow answers count toward the breaker (`$slowMs`, server-side rendering: the pod has few PHP workers): three slower than the limit
        // within 30 s open it like a failure would.
        if ($slowMs !== null && (hrtime(true) - $startedAt) / 1_000_000 > $slowMs) {
            try {
                $cache->add(self::SLOW_KEY, 0, 30);
                $slow = $cache->increment(self::SLOW_KEY);

                if (is_int($slow) && $slow >= 3) {
                    $cache->put(self::BREAKER_KEY, 1, self::BREAKER_SECONDS);
                    $cache->forget(self::SLOW_KEY);
                }
            } catch (Throwable) {
                // No cache: no breaker.
            }
        }

        if ($anonymous) {
            try {
                $cache->put($key, $answer, max(5, (int) config('content.intelligence.decide_ttl', 60)));
            } catch (Throwable) {
                // Not cached: the next view asks again.
            }
        }

        return $answer;
    }

    /** @return array<string, mixed> */
    public static function present(mixed $decision, bool $inControl): array
    {
        if (!is_array($decision) || !is_string($decision['variant'] ?? null)) {
            return ['v' => 'default', 'g' => 'none'];
        }

        $fields = [];

        foreach (is_array($decision['fields'] ?? null) ? $decision['fields'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $fields[$name] = $value;
            }
        }

        $functional = ($decision['functional'] ?? false) === true;
        $out = ['v' => $decision['variant'], 'g' => $functional ? 'customer' : ($inControl ? 'control' : 'personal')];

        // The browser also needs the content of a held-back variant: it checks whether the swap would have moved the page, so both groups
        // drop the same visits (`fallback`) and the comparison stays fair.
        if ($fields !== []) {
            $out['f'] = $fields;
        }

        if (($decision['hide'] ?? false) === true) {
            $out['h'] = 1;
        }

        return $out;
    }

    public static function visitorIp(Request $request): ?string
    {
        $header = config('content.analytics.ip_header');
        $candidate = is_string($header) && $header !== '' ? $request->headers->get($header) : null;

        if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_IP) !== false) {
            return trim($candidate);
        }

        return $request->ip();
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
