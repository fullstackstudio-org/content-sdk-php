<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Illuminate\Http\Request;
use Throwable;

/**
 * Server-side rendering of FullStack Intelligence slot decisions (one instance per request, kept on the request attributes).
 *
 * `<x-content::slot>` asks {@see self::decisionFor()} while the page renders. A decision is only made when the request carries a SIGNAL:
 * a same-site `Referer` on another path, the portal recognition cookie (`recognition_cookie`), or the consented browser-id cookie
 * {@see self::COOKIE} that fsa.js mirrors (its presence implies consent; fsa.js removes it on revoke). Without a signal there is no Hub
 * call and the default renders, with zero added latency.
 *
 * ONE Hub call per request covers every slot of the page. A slot cannot know the slots that come after it, so the names are learned: each
 * rendered slot is remembered per page path in the cache ({@see self::remember()}); the first signal-carrying view of a page that has never
 * been rendered before decides only the slots it already saw, the next view all of them. A slot that was not part of the call renders its
 * default. The call uses its own short timeout (`ssr_timeout_ms`), the same breaker, answer cache, control-group bucket and `present()` as
 * the browser path ({@see PersonalizationController}), so the groups are identical.
 *
 * When a signal was used the request is flagged `fsi.private`; {@see self::privatise()} then sends `Cache-Control: private, no-store`.
 */
final class SlotDecisions
{
    public const COOKIE = 'fsa_pid';

    public const ATTRIBUTE = 'fsi.ssr';

    public const PRIVATE_ATTRIBUTE = 'fsi.private';

    /** @var list<string>|null the slots of this page remembered from earlier views */
    private ?array $known = null;

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
            $this->remember($slot);
            $signal = $this->signal();

            if ($signal === false) {
                return null;
            }

            if ($this->answer === null) {
                $this->ask($slot, $signal);
            }

            if (!in_array($slot, $this->asked, true) || ($this->answer['active'] ?? false) !== true) {
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

    /** `Cache-Control: private, no-store` on a response that used a signal (also over the site's own `public, s-maxage`). */
    public static function privatise(Request $request, object $response): void
    {
        if ($request->attributes->get(self::PRIVATE_ATTRIBUTE) !== true || !isset($response->headers)) {
            return;
        }

        $response->headers->set('Cache-Control', 'private, no-store');
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
        $this->request->attributes->set(self::PRIVATE_ATTRIBUTE, true);
        [$referrer, $pid, $recognition] = $signal;

        $slots = array_slice(array_values(array_unique([$slot, ...($this->known ?? [])])), 0, PersonalizationController::MAX_SLOTS);
        $locale = strtolower(str_replace('_', '-', (string) app()->getLocale()));
        $locale = preg_match(PersonalizationController::LOCALE, $locale) === 1 ? $locale : null;
        $path = PersonalizationController::path($this->request->getPathInfo()) ?? '/';
        $consent = $pid !== null;

        $this->asked = $slots;
        $answer = PersonalizationController::ask($slots, $path, $referrer, $pid, $consent, $recognition, $locale, (int) config('content.intelligence.ssr_timeout_ms', 250));
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
        $pid = is_string($pid) && preg_match('/^[A-Za-z0-9-]{16,64}$/D', $pid) === 1 ? $pid : null;

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

        return $path !== null && $path !== $this->request->getPathInfo() ? $path : null;
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

    /** Remembers the slot per page path (cache, 7 days), so the next view can decide the whole page with one call. */
    private function remember(string $slot): void
    {
        try {
            $key = 'fsi:slots:' . hash('sha256', $this->request->getPathInfo());
            $cache = app('cache')->store();
            $this->known ??= array_values(array_filter((array) $cache->get($key, []), 'is_string'));

            if (!in_array($slot, $this->known, true) && count($this->known) < PersonalizationController::MAX_SLOTS) {
                $this->known[] = $slot;
                $cache->put($key, $this->known, 7 * 86400);
            }
        } catch (Throwable) {
            $this->known ??= [];
        }
    }
}
