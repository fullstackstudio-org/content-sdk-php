<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Auto\AutoErrors;
use FullStackStudio\Content\Laravel\ContentResponseHeaders;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use FullStackStudio\Content\Laravel\HubCooldown;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Answers a visitor route of {@see VisitorRoutes} with the Hub's page (plan sdk-out-of-the-box, section 8, V7 to V11).
 *
 * - The path is checked again against the table and a strict character set before anything is sent; the query is capped at 2 KB.
 * - The answer is the Hub's status, the allow-listed headers of {@see self::RESPONSE_HEADERS} and the body. `Set-Cookie` never passes.
 *   The headers are final: {@see self::finalize()} applies them again after the whole middleware stack, so a site's header middleware
 *   cannot replace the Hub's CSP (V9).
 * - `Location`: relative passes as is; absolute to the Hub's own origin on a visitor path becomes relative (the visitor stays on the site);
 *   any other absolute target (Mollie, a newsletter click target, a direct file URL on the Hub) is the Hub's decision and passes (V10, V11).
 * - Every answer without `Cache-Control` gets `private, no-store` (token pages are never cached). CDN cache headers a site middleware set
 *   (`CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control`, `Surrogate-Control`) are removed and `CDN-Cache-Control: no-store` is set on every
 *   proxied answer, also an error page, so a Cloudflare cache rule of the site never stores a token page.
 * - At most {@see self::DEFAULT_PER_MINUTE} requests per visitor IP per minute (`content.auto.visitor_pages.per_minute`), then a 429 without
 *   a Hub call. The Hub has its own, stricter limits per page; this one keeps one address from filling the site's PHP workers.
 * - The Hub no longer trusts this site's origin (409 `site_not_verified`, e.g. an old link after a domain switch): the neutral 404, not
 *   reported. Hub unreachable, timeout, or a 401 refusal of the server token: the site's 503 page with `Retry-After`; an answer over 2 MB:
 *   502; a body over 1 MB: 413; a multipart field name that cannot be forwarded: 400. Failures are reported with the path scrubbed
 *   (`/a/l/…`), at most once per reason per {@see self::REPORT_EVERY_SECONDS} seconds, so an outage does not flood the error tracker.
 * - A timeout or connection error starts the shared {@see HubCooldown}: while it is on, a visitor route answers the 503 page at once
 *   without asking the Hub. The timeout is `content.auto.visitor_pages.timeout` (default 8 s, connect 3 s).
 */
final class VisitorProxyController
{
    /** Request attribute: this request is a proxied visitor request (cookies are stripped from its response). */
    public const ATTRIBUTE = 'fsc.visitor';

    /** Request attribute: the Hub's allow-listed headers, re-applied after the middleware stack. */
    public const HEADERS_ATTRIBUTE = 'fsc.visitor.headers';

    /** Response headers of the Hub that reach the browser, in their usual spelling. */
    public const RESPONSE_HEADERS = [
        'content-type' => 'Content-Type',
        'content-disposition' => 'Content-Disposition',
        'content-security-policy' => 'Content-Security-Policy',
        'referrer-policy' => 'Referrer-Policy',
        'x-robots-tag' => 'X-Robots-Tag',
        'x-content-type-options' => 'X-Content-Type-Options',
        'x-frame-options' => 'X-Frame-Options',
        'cache-control' => 'Cache-Control',
        'etag' => 'ETag',
        'last-modified' => 'Last-Modified',
        'location' => 'Location',
        'retry-after' => 'Retry-After',
    ];

    /** A raw request path the proxy forwards: non-empty segments of URL-safe characters and percent escapes. */
    private const RAW_PATH = '#^(?:/[A-Za-z0-9._~%-]+)+/?$#D';

    private const MAX_PATH_BYTES = 2048;

    /** Proxied requests per visitor IP per minute, unless `content.auto.visitor_pages.per_minute` says otherwise. */
    public const DEFAULT_PER_MINUTE = 120;

    /** One report per failure reason per this many seconds. */
    public const REPORT_EVERY_SECONDS = 300;

    /** CDN cache headers a site may set that would let a CDN store a token page. */
    private const CDN_CACHE_HEADERS = ['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control', 'Surrogate-Control'];

    public function __invoke(Request $request, Forwarder $forwarder): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);
        $path = $request->getPathInfo();

        if (strlen($path) > self::MAX_PATH_BYTES || preg_match(self::RAW_PATH, $path) !== 1 || ! VisitorRoutes::matches($path)) {
            return self::plain(404, 'Niet gevonden');
        }

        if (strlen(Forwarder::query($request)) > Forwarder::MAX_QUERY_BYTES) {
            return self::plain(414, 'Adres te lang');
        }

        if (self::throttled($request)) {
            return self::plain(429, 'Te veel verzoeken', ['Retry-After' => '60']);
        }

        // The shared Hub cooldown: the Hub gave no answer a moment ago (here or to another SDK read), so do not tie up a PHP worker for
        // another timeout. The site's 503 page at once, not reported again.
        if (HubCooldown::active()) {
            return AutoErrors::unavailable();
        }

        try {
            $upstream = $forwarder->forward($request, $path);
        } catch (VisitorProxyException $error) {
            return self::failed($error, $request, $path);
        } catch (Throwable) {
            return self::failed(new VisitorProxyException(VisitorProxyException::UNREACHABLE), $request, $path);
        }

        $refusal = self::refusal($upstream);

        if ($refusal !== null) {
            return self::failed(new VisitorProxyException($refusal), $request, $path);
        }

        $headers = self::headers($upstream);
        $request->attributes->set(self::HEADERS_ATTRIBUTE, $headers);

        $response = new Response($upstream->status === 304 ? '' : $upstream->body, $upstream->status);
        self::apply($response, $headers);

        return $response;
    }

    /**
     * Called after the whole middleware stack (`RequestHandled`): a proxied response carries no cookie, and the Hub's headers replace whatever a
     * site middleware set under the same names (also a site CSP-Report-Only, which would send token URLs to the site's report endpoint).
     */
    public static function finalize(Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true) {
            return;
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        $response->headers->remove('Set-Cookie');

        foreach (self::CDN_CACHE_HEADERS as $name) {
            $response->headers->remove($name);
        }

        $response->headers->set('CDN-Cache-Control', 'no-store');
        $headers = $request->attributes->get(self::HEADERS_ATTRIBUTE);

        if (! is_array($headers)) {
            // The proxy's own answer (an error page, a refusal): never cacheable, whatever a site middleware set.
            $response->headers->set('Cache-Control', 'private, no-store');

            return;
        }

        foreach (self::RESPONSE_HEADERS as $spelling) {
            $response->headers->remove($spelling);
        }

        $response->headers->remove('Content-Security-Policy-Report-Only');
        self::apply($response, $headers);
    }

    /**
     * The allow-listed headers of the Hub's answer, with the `Location` rule and `private, no-store` for any answer without `Cache-Control`.
     *
     * @return array<string, list<string>>
     */
    public static function headers(UpstreamResponse $upstream): array
    {
        $headers = [];

        foreach (self::RESPONSE_HEADERS as $name => $spelling) {
            $values = array_values(array_filter($upstream->headers[$name] ?? [], static fn (string $value): bool => $value !== ''));

            if ($values !== []) {
                $headers[$spelling] = $values;
            }
        }

        if (isset($headers['Location'])) {
            $headers['Location'] = [self::location($headers['Location'][0])];
        }

        if (! isset($headers['Cache-Control'])) {
            $headers['Cache-Control'] = ['private, no-store'];
        }

        return $headers;
    }

    /** The `Location` rule of "Security Considerations": only an absolute URL on the Hub's origin and a visitor path is made relative. */
    public static function location(string $location): string
    {
        $parts = parse_url($location);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $location;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $origin = ContentResponseHeaders::hubOrigin();
        $target = ($scheme === '' ? (string) parse_url((string) $origin, PHP_URL_SCHEME) : $scheme) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';

        if ($origin === null || $target !== $origin || isset($parts['user']) || ! VisitorRoutes::matches($path) || ! str_starts_with($path, '/')) {
            return $location;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /** @param array<string, list<string>> $headers */
    private static function apply(\Symfony\Component\HttpFoundation\Response $response, array $headers): void
    {
        foreach ($headers as $name => $values) {
            $response->headers->set($name, $values);
        }
    }

    /**
     * A 401 or 409 JSON answer is the Hub's proxy gate refusing the site, not a page (a page's own 409, e.g. a slot just taken, is HTML):
     * 401 = the server token ({@see VisitorProxyException::REFUSED}), 409 = this origin is not (or no longer) trusted
     * ({@see VisitorProxyException::NOT_VERIFIED}).
     *
     * @return VisitorProxyException::REFUSED|VisitorProxyException::NOT_VERIFIED|null
     */
    private static function refusal(UpstreamResponse $upstream): ?string
    {
        if (! str_contains(strtolower((string) $upstream->header('content-type')), 'json')) {
            return null;
        }

        return match ($upstream->status) {
            401 => VisitorProxyException::REFUSED,
            409 => VisitorProxyException::NOT_VERIFIED,
            default => null,
        };
    }

    /** The per-IP limit of the visitor routes. A cache failure never blocks a visitor. */
    private static function throttled(Request $request): bool
    {
        $limit = (int) Auto::option('visitor_pages', 'per_minute', self::DEFAULT_PER_MINUTE);
        $ip = ContentServiceProvider::visitorIp($request, config('content.forms.ip_header'));

        if ($limit <= 0 || ! is_string($ip) || $ip === '') {
            return false;
        }

        try {
            $limiter = app(RateLimiter::class);
            $key = 'fsc-visitor:' . hash('sha256', $ip);

            if ($limiter->tooManyAttempts($key, $limit)) {
                return true;
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function failed(VisitorProxyException $error, Request $request, string $path): Response
    {
        // A timeout or connection error starts the shared cooldown (HubCooldown); a refusal or a too large answer does not.
        HubCooldown::trip($error);

        // A body over the limit, or one that cannot be forwarded, is the visitor's doing; a site the Hub no longer trusts for this origin is
        // an expected state (old links after a domain switch). Neither is a fault of the site or the Hub.
        if (! in_array($error->reason, [VisitorProxyException::REQUEST_TOO_LARGE, VisitorProxyException::BAD_REQUEST, VisitorProxyException::NOT_VERIFIED], true)) {
            self::report($error->forRequest($request->getMethod(), $path));
        }

        return match ($error->reason) {
            VisitorProxyException::REQUEST_TOO_LARGE => self::plain(413, 'Te groot'),
            VisitorProxyException::BAD_REQUEST => self::plain(400, 'Ongeldig verzoek'),
            VisitorProxyException::NOT_VERIFIED => self::plain(404, 'Niet gevonden'),
            VisitorProxyException::RESPONSE_TOO_LARGE => AutoErrors::standaloneResponse(502),
            default => AutoErrors::unavailable(),
        };
    }

    /** Reports a failure at most once per reason per {@see self::REPORT_EVERY_SECONDS}; without a working cache, every time. */
    private static function report(VisitorProxyException $error): void
    {
        try {
            if (! app('cache')->add('fsc:visitor-proxy:reported:' . $error->reason, 1, self::REPORT_EVERY_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // No cache: report anyway.
        }

        try {
            report($error);
        } catch (Throwable) {
            // Reporting never breaks the answer.
        }
    }

    /** @param array<string, string> $headers */
    private static function plain(int $status, string $title, array $headers = []): Response
    {
        return new Response(
            '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . e($title) . '</title></head>'
            . '<body><h1>' . e($title) . '</h1></body></html>',
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', ...$headers],
        );
    }
}
