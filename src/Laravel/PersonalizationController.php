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
 * - `POST decide { s: [slot keys], p: path, r?: referring path on this site, c: 0|1, pid? }`: asks the Hub which variant fits
 *   ({@see ContentClient::decideSlots()}) within `decide_timeout_ms`, caches the answer `decide_ttl` seconds, applies the control group
 *   and answers `{ d: { slot: { v, g, f?, h? } } }` with `g` = `personal` (show the variant), `control` (held back: keep the default and
 *   measure as control), `customer` (functional variant for a logged-in portal customer, never held back) or `none` (no variant fits).
 *   A slot that is off or unknown is absent: the browser leaves it alone and measures nothing. Any problem (Hub away, timeout, module off,
 *   bad request) answers `{ d: {} }`: the default content stays. Never cached by a browser or the edge.
 *
 * Consent (D5): the tracker's browser id `pid` is only forwarded with `c: 1`; without it the Hub only sees the current and previous page.
 * The portal recognition cookie (`recognition_cookie`) is forwarded always but the Hub uses it only as "is a logged-in customer"
 * (functional, plan B.4). The stable bucket of the control group: with consent from the browser id (stable across visits); without consent
 * from HMAC(app key, day, IP, user agent) (stable for the day, nothing stored, the IP never leaves this server).
 */
final class PersonalizationController
{
    public const MAX_BODY = 2048;

    public const MAX_SLOTS = 10;

    public const KEY = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

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

        [$slots, $path, $referrer, $consent, $pid] = $input;
        $name = (string) config('content.intelligence.recognition_cookie', '');
        $cookie = $name !== '' ? $request->cookies->get($name) : null;
        $cookie = is_string($cookie) && $cookie !== '' && strlen($cookie) <= 200 ? $cookie : null;

        $answer = self::ask($slots, $path, $referrer, $consent ? $pid : null, $consent, $cookie);

        if (($answer['active'] ?? false) !== true || !is_array($answer['decisions'] ?? null)) {
            return self::json($empty, 200);
        }

        $control = max(0, min(50, (int) ($answer['controlPercent'] ?? 10)));
        $bucket = self::bucket($consent ? $pid : null, $ip, (string) $request->headers->get('User-Agent', ''));
        $out = [];

        foreach ($answer['decisions'] as $slot => $decision) {
            if (!is_string($slot) || !in_array($slot, $slots, true)) {
                continue;
            }

            $out[$slot] = self::present($decision, $bucket < $control);
        }

        return self::json(['d' => $out === [] ? new \stdClass() : $out], 200);
    }

    /**
     * The stable bucket 0-99 of the control group. With consent: from the browser id, the same on every visit. Without consent: a keyed
     * daily hash of IP and user agent (Europe/Amsterdam day), the same during the visit and the day; nothing is stored anywhere.
     */
    public static function bucket(?string $pid, ?string $ip, string $userAgent, ?string $day = null): int
    {
        $source = $pid !== null && $pid !== ''
            ? 'pid|' . $pid
            : 'anon|' . ($day ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')))->format('Y-m-d')) . '|' . ($ip ?? '') . '|' . substr($userAgent, 0, 300);
        $hash = hash_hmac('sha256', $source, 'fsi-bucket|' . (string) config('app.key', ''), true);

        return (int) (unpack('N', substr($hash, 0, 4))[1] % 100);
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array{0: list<string>, 1: string, 2: ?string, 3: bool, 4: ?string}|null
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

        return [array_values(array_unique($slots)), $path, self::path($body['r'] ?? null), $consent, $pid];
    }

    /** A path on this site without query or fragment, at most 300 characters; null otherwise. */
    private static function path(mixed $value): ?string
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
    private static function ask(array $slots, string $path, ?string $referrer, ?string $pid, bool $consent, ?string $cookie): array
    {
        try {
            $cache = app('cache')->store();
            $key = 'fsi:decide:' . hash('sha256', implode(',', $slots) . "\0" . $path . "\0" . ($referrer ?? '') . "\0" . ($pid ?? '') . "\0" . ($consent ? '1' : '0') . "\0" . ($cookie ?? ''));
            $cached = $cache->get($key);

            if (is_array($cached)) {
                return $cached;
            }

            $answer = app(ContentClient::class)->decideSlots($slots, $path, $referrer, $pid, $consent, $cookie, ['timeoutMs' => max(20, min(2000, (int) config('content.intelligence.decide_timeout_ms', 50)))]);
            $cache->put($key, $answer, max(5, (int) config('content.intelligence.decide_ttl', 60)));

            return $answer;
        } catch (Throwable) {
            // Hub away, too slow or the module off: the default content stays. Never visible to the visitor.
            return [];
        }
    }

    /** @return array<string, mixed> */
    private static function present(mixed $decision, bool $inControl): array
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

    private static function visitorIp(Request $request): ?string
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
