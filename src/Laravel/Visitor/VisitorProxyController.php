<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use FullStackStudio\Content\Laravel\Auto\AutoErrors;
use FullStackStudio\Content\Laravel\ContentResponseHeaders;
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
 * - HTML without `Cache-Control` gets `no-store` (token pages are never cached).
 * - Hub unreachable, timeout, or a 401/409 refusal from the Hub: the site's 503 page with `Retry-After`; an answer over 2 MB: 502; a body
 *   over 1 MB: 413. Each failure is reported once, with the path scrubbed (`/a/l/…`).
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

        try {
            $upstream = $forwarder->forward($request, $path);
        } catch (VisitorProxyException $error) {
            return self::failed($error, $request, $path);
        } catch (Throwable) {
            return self::failed(new VisitorProxyException(VisitorProxyException::UNREACHABLE), $request, $path);
        }

        if (self::refused($upstream)) {
            return self::failed(new VisitorProxyException(VisitorProxyException::REFUSED), $request, $path);
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
        $headers = $request->attributes->get(self::HEADERS_ATTRIBUTE);

        if (! is_array($headers)) {
            return;
        }

        foreach (self::RESPONSE_HEADERS as $spelling) {
            $response->headers->remove($spelling);
        }

        $response->headers->remove('Content-Security-Policy-Report-Only');
        self::apply($response, $headers);
    }

    /**
     * The allow-listed headers of the Hub's answer, with the `Location` rule and `no-store` for HTML without `Cache-Control`.
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

        $html = str_starts_with(strtolower($headers['Content-Type'][0] ?? ''), 'text/html');

        if ($html && ! isset($headers['Cache-Control'])) {
            $headers['Cache-Control'] = ['no-store'];
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

    /** A 401 or 409 JSON answer is the Hub refusing the site (bad server token, site not verified), not a page. */
    private static function refused(UpstreamResponse $upstream): bool
    {
        return in_array($upstream->status, [401, 409], true)
            && str_contains(strtolower((string) $upstream->header('content-type')), 'json');
    }

    private static function failed(VisitorProxyException $error, Request $request, string $path): Response
    {
        // A body over the limit is the visitor's doing, not a fault of the site or the Hub.
        if ($error->reason !== VisitorProxyException::REQUEST_TOO_LARGE) {
            try {
                report($error->forRequest($request->getMethod(), $path));
            } catch (Throwable) {
                // Reporting never breaks the answer.
            }
        }

        return match ($error->reason) {
            VisitorProxyException::REQUEST_TOO_LARGE => self::plain(413, 'Te groot'),
            VisitorProxyException::RESPONSE_TOO_LARGE => AutoErrors::standaloneResponse(502),
            default => AutoErrors::unavailable(),
        };
    }

    private static function plain(int $status, string $title): Response
    {
        return new Response(
            '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . e($title) . '</title></head>'
            . '<body><h1>' . e($title) . '</h1></body></html>',
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }
}
