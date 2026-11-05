<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Server-side rendering of FullStack Intelligence slot decisions (one instance per request, kept on the request attributes).
 *
 * `<x-content::slot>` asks {@see self::decisionFor()} while the page renders. A decision is only made when the request carries a SIGNAL:
 * a same-site `Referer` on another path, the portal recognition cookie (`recognition_cookie`), or the consented browser-id cookie
 * {@see self::COOKIE} that fsa.js mirrors (its presence implies consent; fsa.js removes it on revoke). Without a signal there is no Hub
 * call and the default renders, with zero added latency.
 *
 * ONE Hub call per request covers every slot of the page. A slot cannot know the slots that come after it, so the names are learned: after a
 * successful (2xx) response of a MATCHED, non-fallback route the slots it rendered are stored in the cache under the route pattern
 * ({@see self::learn()}, replacing the old list, so renamed slots drop out); a 404 or an unknown path writes nothing. The first
 * signal-carrying view of a page that has never been rendered before decides only the slots it already saw, the next view all of them. A slot that was not part of the call renders its
 * default. The call uses its own short timeout (`ssr_timeout_ms`), the same rate limit (120 per minute per visitor IP, over it = no signal),
 * breaker (slow answers count too), anonymous answer cache (never written for a request with a referrer), control-group bucket and
 * `present()` as the browser path ({@see PersonalizationController}), so the groups are identical.
 *
 * When a signal was used the request is flagged `fsi.private`; {@see self::privatise()} then sends `Cache-Control: private, no-cache`.
 */
final class SlotDecisions
{
    public const COOKIE = 'fsa_pid';

    public const ATTRIBUTE = 'fsi.ssr';

    public const PRIVATE_ATTRIBUTE = 'fsi.private';

    /** @var list<string> the slots rendered by this request */
    private array $rendered = [];

    /** @var list<string>|null the slots of this page learned from earlier views */
    private ?array $known = null;

    private bool $limited = false;

    private bool $shareable = true;

    /** @var array<string, mixed>|null the answer of the one Hub call of this request; null = not asked yet */
    private ?array $answer = null;

    /** @var list<string> the slots that were part of the Hub call */
    private array $asked = [];

    private ?int $control = null;

    /** @var array{0: ?string, 1: ?string, 2: ?string}|false|null [referrer path, browser id, recognition cookie] or false = no signal */
    private array|false|null $signal = null;

    public function __construct(private readonly Request $request)
    {
    }

    public static function enabled(): bool
    {
        return PersonalizationController::enabled() && (bool) config('content.intelligence.ssr', true);
    }

    public static function make(?Request $request = null): self
    {
        $request ??= app('request');
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if ($existing instanceof self) {
            return $existing;
        }

        $instance = new self($request);
        $request->attributes->set(self::ATTRIBUTE, $instance);

        return $instance;
    }

    /**
     * The presented decision `{v, g, f?, h?}` of one slot for this visitor, or null when the slot stays as the HTML has it (off, no signal,
     * not part of the call, Hub away or slow, slot unknown to the Hub). Never throws.
     *
     * @return array<string, mixed>|null
     */
    public function decisionFor(string $slot): ?array
    {
        try {
            $this->rendered[] = $slot;
            $signal = $this->signal();

            if ($signal === false || $this->limited) {
                return null;
            }

            if ($this->answer === null) {
                $this->ask($slot, $signal);
            }

            if ($this->limited || !in_array($slot, $this->asked, true) || ($this->answer['active'] ?? false) !== true) {
                return null;
            }

            $decisions = $this->answer['decisions'] ?? null;

            if (!is_array($decisions) || !array_key_exists($slot, $decisions)) {
                return null;
            }

            [, $pid] = $signal;
            $ip = PersonalizationController::visitorIp($this->request);
            $bucket = PersonalizationController::bucket($slot, $pid, $ip, (string) $this->request->headers->get('User-Agent', ''));

            return PersonalizationController::present($decisions[$slot], $bucket < ($this->control ?? 10));
        } catch (Throwable) {
            return null;
        }
    }

    /** `Cache-Control: private, no-cache` on a response that used a signal (also over the site's own `public, s-maxage`). */
    public static function privatise(Request $request, object $response): void
    {
        if ($request->attributes->get(self::PRIVATE_ATTRIBUTE) !== true || !isset($response->headers)) {
            return;
        }

        // Never shared, but not `no-store`: the browser may still keep the page for back/forward (bfcache).
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->remove('Expires');
        $response->headers->remove('Surrogate-Control');
        $response->headers->remove('CDN-Cache-Control');
        $response->headers->remove('Cloudflare-CDN-Cache-Control');
    }

    /**
     * @param array{0: ?string, 1: ?string, 2: ?string} $signal
     */
    private function ask(string $slot, array $signal): void
    {
        [$referrer, $pid, $recognition] = $signal;
        $ip = PersonalizationController::visitorIp($this->request);
        $limiterKey = 'fsi-decide:' . ($ip ?? 'none');

        // The same limit as the browser route; over it the request counts as having no signal: the default renders, no Hub call.
        if (RateLimiter::tooManyAttempts($limiterKey, 120)) {
            $this->limited = true;
            $this->answer = [];

            return;
        }

        RateLimiter::hit($limiterKey, 60);
        $this->request->attributes->set(self::PRIVATE_ATTRIBUTE, true);
        $this->known ??= $this->loadKnown();
        $slots = array_slice(array_values(array_unique([$slot, ...$this->known])), 0, PersonalizationController::MAX_SLOTS);
        $locale = strtolower(str_replace('_', '-', (string) app()->getLocale()));
        $locale = preg_match(PersonalizationController::LOCALE, $locale) === 1 ? $locale : null;
        $path = PersonalizationController::path($this->request->getPathInfo()) ?? '/';
        $consent = $pid !== null;

        $this->asked = $slots;
        $answer = PersonalizationController::ask($slots, $path, $referrer, $pid, $consent, $recognition, $locale, (int) config('content.intelligence.ssr_timeout_ms', 250), $referrer === null, 150);
        $this->answer = $answer;
        $this->control = max(0, min(50, (int) ($answer['controlPercent'] ?? 10)));
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string}|false */
    private function signal(): array|false
    {
        if ($this->signal !== null) {
            return $this->signal;
        }

        $cookies = self::rawCookies($this->request);
        $pid = $cookies[self::COOKIE] ?? null;
        $pid = is_string($pid) && preg_match('/^[A-Za-z0-9-]{16,64}$/D', $pid) === 1 && $this->request->headers->get('Sec-GPC') !== '1' ? $pid : null;

        $name = (string) config('content.intelligence.recognition_cookie', '');
        $recognition = $name !== '' ? ($cookies[$name] ?? null) : null;
        $recognition = is_string($recognition) && $recognition !== '' && strlen($recognition) <= 200 ? $recognition : null;

        $referrer = $this->referrerPath();

        return $this->signal = ($referrer !== null || $pid !== null || $recognition !== null) ? [$referrer, $pid, $recognition] : false;
    }

    /** The path of a same-site `Referer` that differs from this page; null otherwise. */
    private function referrerPath(): ?string
    {
        $referer = (string) $this->request->headers->get('Referer', '');

        if ($referer === '' || strlen($referer) > 2000) {
            return null;
        }

        $parts = parse_url($referer);

        if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== strtolower($this->request->getHost())) {
            return null;
        }

        $path = PersonalizationController::path(($parts['path'] ?? '/') === '' ? '/' : ($parts['path'] ?? '/'));

        // Only a referrer that maps to a real (non-fallback) route of this site counts, so arbitrary paths never reach the Hub.
        return $path !== null && $path !== $this->request->getPathInfo() && self::knownRoute($path) ? $path : null;
    }

    /**
     * The cookies of the raw `Cookie` header, so a cookie cannot be lost to the site's cookie encryption (these two are not Laravel's).
     *
     * @return array<string, string>
     */
    private static function rawCookies(Request $request): array
    {
        $out = [];

        foreach (explode(';', (string) $request->headers->get('Cookie', '')) as $pair) {
            $pair = trim($pair);
            $at = strpos($pair, '=');

            if ($at !== false && $at > 0) {
                $out[substr($pair, 0, $at)] ??= rawurldecode(substr($pair, $at + 1));
            }
        }

        return $out;
    }

    private static function knownRoute(string $path): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));

            return !$route->isFallback;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The cache key of the learned slot list of the route this request matched; null for no route or a fallback route. A route with
     * parameters (a catch-all page route) keys on the path as well, but only a 2xx response ever writes ({@see self::learn()}).
     */
    private static function key(Request $request): ?string
    {
        $route = $request->route();

        if (!is_object($route) || !method_exists($route, 'uri') || (property_exists($route, 'isFallback') && $route->isFallback)) {
            return null;
        }

        $parametric = str_contains((string) $route->uri(), '{');
        $locale = strtolower((string) app()->getLocale());

        return 'fsi:slots:' . hash('sha256', $route->uri() . '|' . $locale . ($parametric ? '|' . $request->getPathInfo() : ''));
    }

    /** @return list<string> */
    private function loadKnown(): array
    {
        try {
            $key = self::key($this->request);

            return $key === null ? [] : array_values(array_filter((array) app('cache')->store()->get($key, []), 'is_string'));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Called after the response: stores the slots this request rendered as the learned list of its route, but only for a matched,
     * non-fallback route with a 2xx answer, and only when the list changed (a renamed slot drops out). A 404 or unknown path writes nothing.
     */
    public static function learn(Request $request, object $response): void
    {
        try {
            $instance = $request->attributes->get(self::ATTRIBUTE);
            $key = self::key($request);

            if (!$instance instanceof self || $key === null || $instance->rendered === [] || !method_exists($response, 'isSuccessful') || !$response->isSuccessful()) {
                return;
            }

            $names = array_slice(array_values(array_unique($instance->rendered)), 0, PersonalizationController::MAX_SLOTS);
            $cache = app('cache')->store();
            $current = array_values(array_filter((array) $cache->get($key, []), 'is_string'));

            if ($current !== $names) {
                $cache->put($key, $names, 7 * 86400);
            }
        } catch (Throwable) {
            // Learning never breaks a response.
        }
    }
}
