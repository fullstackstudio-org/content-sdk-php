<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Preview;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\PersonalizationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Global middleware for the portal preview (plan page-builder B, steps 5 to 8). Registered by the provider while `content.preview.enabled`
 * is on and a server token is set; it only acts on `{content.preview.path}` (default `/_fsc/p`), every other request passes untouched.
 *
 * `POST /_fsc/p` with the form field `token` (`fscpv_...`): the token shape is checked, the visitor IP is rate limited, the Hub is asked for the
 * preview (`GET /api/v1/pages/_preview`, never cached). On any refusal the answer is a bare 404. On success the request scoped {@see Preview}
 * is activated and the request is rewritten in place to an anonymous `GET <path>` on the same host (no cookies, no body, no session that
 * outlives the request), so the site's own route, controller, layout and views render the page; `getPage()` returns the preview for that path.
 *
 * On the way out (and again in a `RequestHandled` listener, so nothing later can undo it): `preview.js` is inserted right after `<head>`, the
 * response becomes `private, no-store`, `noindex`, `no-referrer`, loses `Set-Cookie`, `ETag`, `Last-Modified` and `X-Frame-Options`, and its
 * CSP allows framing by exactly the embed origin the Hub stored with the preview. `Server-Timing: fsc-hub;dur=…, fsc-render;dur=…` tells the
 * builder how long the Hub call and the site's render took. Any other method on the path is a 404.
 */
final class PreviewRequest
{
    /** `fscpv_` + 32 random bytes in base64url (43 characters without padding). */
    public const TOKEN_PATTERN = '/^fscpv_[A-Za-z0-9_-]{43}$/D';

    /** Same-host redirects followed inside one preview. */
    private const MAX_HOPS = 2;

    /** Tokens the Hub refused, per visitor IP and minute. */
    private const MISS_LIMIT = 30;

    /** Full-page cache packages whose switch is turned off for a preview request (their stores point at `array` anyway). */
    private const PAGE_CACHE_SWITCHES = ['responsecache.enabled', 'page-cache.enabled', 'laravel-page-speed.enable'];

    public static function enabled(): bool
    {
        $config = app('config');

        return (bool) $config->get('content.preview.enabled', true) && str_starts_with(trim((string) $config->get('content.token')), 'fsc_srv_');
    }

    /** The preview path with a leading slash and no trailing slash, e.g. `/_fsc/p`. */
    public static function base(): string
    {
        return '/' . (trim((string) app('config')->get('content.preview.path', '_fsc/p'), '/') ?: '_fsc/p');
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $base = self::base();
        $path = '/' . trim($request->getPathInfo(), '/');

        if ($path !== $base || \FullStackStudio\Content\Laravel\Visitor\VisitorRoutes::handles($request)) {
            return $next($request);
        }

        if (!$request->isMethod('POST')) {
            return self::refuse(404);
        }

        // Only the form body counts: a token in the query string would end up in logs.
        $token = $request->request->get('token');

        if (!is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return self::refuse(404);
        }

        // Per token (one editor session, also behind a shared office IP), plus a per-IP limit on tokens the Hub refused, so guessing
        // well-formed tokens cannot hammer the Hub.
        $tokenKey = 'fsc-preview:' . hash('sha256', $token);
        $missKey = 'fsc-preview-miss:' . (PersonalizationController::visitorIp($request) ?? 'none');
        $limit = max(1, (int) app('config')->get('content.preview.rate_limit', 240));

        if (RateLimiter::tooManyAttempts($tokenKey, $limit) || RateLimiter::tooManyAttempts($missKey, self::MISS_LIMIT)) {
            return self::refuse(429);
        }

        RateLimiter::hit($tokenKey, 60);
        $client = app(ContentClient::class);
        $started = hrtime(true);

        try {
            $parsed = self::parse($client->getPagePreview($token));
        } catch (Throwable) {
            $parsed = null;
        }

        $hubMs = (hrtime(true) - $started) / 1e6;

        if ($parsed === null) {
            RateLimiter::hit($missKey, 60);

            return self::refuse(404);
        }

        $path = self::sitePath($parsed['page'], $parsed['path']);
        $preview = app(Preview::class);
        $preview->activate($parsed['page'], $path, $parsed['embedOrigin'], $parsed['version'], $token);
        $preview->alias($parsed['path']);
        self::isolate();
        $client->overridePages(static fn (string $ref): ?array => $preview->pageFor($ref));
        $started = hrtime(true);

        try {
            $response = $next(self::forward($request, $path));

            // The site may map the page elsewhere (home on `/`, a trailing slash, a locale prefix) and redirect to it: follow a same-host
            // redirect inside the preview, at most twice, so the preview shows the page where the site renders it.
            for ($hop = 0; $hop < self::MAX_HOPS && $response instanceof Response && $response->isRedirection(); $hop++) {
                $nextPath = self::sameHostPath($request, (string) $response->headers->get('Location', ''));

                if ($nextPath === null) {
                    break;
                }

                $preview->alias($nextPath);
                $response = $next(self::forward($request, $nextPath));
            }
        } finally {
            $client->overridePages(null);
        }

        if ($response instanceof Response) {
            self::inject($response, $preview);
            self::secure($response, $preview->embedOrigin());
            // For the builder's timing readout (preview.js reads it as `rendered.timing.server`); added to what the site already sends.
            $response->headers->set('Server-Timing', sprintf('fsc-hub;dur=%.1F, fsc-render;dur=%.1F', $hubMs, (hrtime(true) - $started) / 1e6), false);
        }

        return $response;
    }

    /**
     * The Hub answer reduced to what the site may use, or null when anything is off: `page` an object (with a list of block objects),
     * `path` a plain same-site path that is not the preview path itself, `embedOrigin` one exact https origin (http only for localhost
     * outside production), `version` a non-negative integer.
     *
     * @param array<string, mixed> $data
     * @return array{page: array<string, mixed>, path: string, embedOrigin: string, version: int}|null
     */
    public static function parse(array $data): ?array
    {
        $page = $data['page'] ?? null;
        $path = self::cleanPath($data['path'] ?? null);
        $origin = self::cleanOrigin($data['embedOrigin'] ?? null);

        if (!is_array($page) || ($page !== [] && array_is_list($page)) || $path === null || $origin === null) {
            return null;
        }

        $blocks = $page['blocks'] ?? [];
        $page['blocks'] = is_array($blocks) ? array_values(array_filter($blocks, static fn (mixed $block): bool => is_array($block) && !array_is_list($block))) : [];

        if (isset($page['media']) && !is_array($page['media'])) {
            unset($page['media']);
        }

        $page['preview'] = true;
        $version = $data['version'] ?? 0;

        return ['page' => $page, 'path' => $path, 'embedOrigin' => $origin, 'version' => is_int($version) && $version >= 0 ? $version : 0];
    }

    /** A plain absolute path on this site: `/`, `/over-ons`, `/diensten/web/`; never a scheme, host, query, `..` segment or the preview path. */
    public static function cleanPath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || strlen($path) > 512 || preg_match('#^/[A-Za-z0-9._~%/-]*$#D', $path) !== 1) {
            return null;
        }

        if (str_contains($path, '//') || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            return null;
        }

        $base = self::base();
        $trimmed = '/' . trim($path, '/');

        return $trimmed === $base || str_starts_with($trimmed, $base . '/') ? null : $path;
    }

    /** One exact origin (`https://portal.example.nl`, optional port), lower case; plain http only for localhost outside production. */
    public static function cleanOrigin(mixed $origin): ?string
    {
        if (!is_string($origin) || strlen($origin) > 255) {
            return null;
        }

        $origin = strtolower($origin);

        if (preg_match('#^https://[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*(?::\d{1,5})?$#D', $origin) === 1) {
            return $origin;
        }

        $local = preg_match('#^http://(?:localhost|127\.0\.0\.1)(?::\d{1,5})?$#D', $origin) === 1;

        return $local && !app()->environment('production') ? $origin : null;
    }

    /**
     * The headers of a preview response. Idempotent; also called from the `RequestHandled` listener so it is the last word.
     */
    public static function secure(Response $response, string $embedOrigin): void
    {
        $headers = $response->headers;
        // Removing `set-cookie` also drops every cookie object of the bag (session, XSRF-TOKEN, queued cookies).
        $headers->remove('Set-Cookie');
        $headers->set('Cache-Control', 'private, no-store');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Referrer-Policy', 'no-referrer');

        foreach (['Expires', 'ETag', 'Last-Modified', 'X-Frame-Options', 'Surrogate-Control', 'CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $name) {
            $headers->remove($name);
        }

        $headers->set('Content-Security-Policy', self::frameAncestors($headers->all('content-security-policy'), $embedOrigin));

        $reportOnly = array_values(array_filter(array_map(self::withoutFrameAncestors(...), $headers->all('content-security-policy-report-only'))));

        if ($reportOnly === []) {
            $headers->remove('Content-Security-Policy-Report-Only');
        } else {
            $headers->set('Content-Security-Policy-Report-Only', $reportOnly);
        }
    }

    /**
     * The site's CSP header values with every `frame-ancestors` directive removed and exactly one `frame-ancestors <origin>` added to the
     * first policy (a new policy when the site sends none). Several CSP headers intersect, so this allows exactly the embed origin.
     *
     * @param list<string|null> $policies
     * @return list<string>
     */
    public static function frameAncestors(array $policies, string $embedOrigin): array
    {
        $policies = array_values(array_filter(array_map(self::withoutFrameAncestors(...), $policies)));
        $directive = 'frame-ancestors ' . $embedOrigin;
        $policies[0] = isset($policies[0]) ? $policies[0] . '; ' . $directive : $directive;

        return $policies;
    }

    private static function withoutFrameAncestors(?string $policy): string
    {
        $kept = [];

        foreach (explode(';', (string) $policy) as $directive) {
            $directive = trim($directive);

            if ($directive !== '' && preg_match('/^frame-ancestors(\s|$)/i', $directive) !== 1) {
                $kept[] = $directive;
            }
        }

        return implode('; ', $kept);
    }

    /**
     * No session or cache side effects for the rest of this request: an in-memory session, and EVERY cache store (not only the default)
     * pointed at a fresh in-memory store, so a site that caches pages or responses under the live key (`Cache::store('file')->remember()`,
     * a response-cache package on its own store) neither serves the live page nor stores the preview. Known full-page cache switches go off.
     * Stores resolved before this point (the SDK's own tag cache, the rate limiter) keep working on the real store: they hold published data.
     */
    private static function isolate(): void
    {
        $config = app('config');
        $config->set('session.driver', 'array');
        $stores = array_keys((array) $config->get('cache.stores', []));

        foreach ($stores as $name) {
            $config->set("cache.stores.{$name}", ['driver' => 'array', 'serialize' => true]);
        }

        $config->set('cache.stores.array', ['driver' => 'array', 'serialize' => true]);
        $config->set('cache.default', 'array');

        foreach (self::PAGE_CACHE_SWITCHES as $key) {
            if ($config->has($key)) {
                $config->set($key, false);
            }
        }

        if (app()->resolved('cache')) {
            $manager = app('cache');
            $manager->forgetDriver([...$stores, 'array']);
            $manager->setDefaultDriver('array');
        }

        app()->forgetInstance('cache.store');
    }

    /**
     * Where this site renders the page: `content.preview.page_path` (a template with `{path}`, `{slug}`, `{locale}`, or an invokable class /
     * callable given the page and the Hub path) when set and valid, else the Hub path. The Hub path stays an alias for `getPage()`.
     *
     * @param array<string, mixed> $page
     */
    private static function sitePath(array $page, string $hubPath): string
    {
        $resolver = app('config')->get('content.preview.page_path');

        try {
            if (is_string($resolver) && str_starts_with($resolver, '/')) {
                $slug = is_string($page['slug'] ?? null) ? $page['slug'] : trim($hubPath, '/');
                $locale = is_string($page['locale'] ?? null) ? $page['locale'] : '';
                $candidate = str_replace(['{path}', '{slug}', '{locale}'], [trim($hubPath, '/'), trim($slug, '/'), $locale], $resolver);
                $candidate = '/' . ltrim((string) preg_replace('#/{2,}#', '/', $candidate), '/');
            } elseif (is_string($resolver) && $resolver !== '' && class_exists($resolver)) {
                $candidate = app($resolver)($page, $hubPath);
            } elseif (is_callable($resolver)) {
                $candidate = $resolver($page, $hubPath);
            } else {
                $candidate = null;
            }
        } catch (Throwable) {
            $candidate = null;
        }

        return self::cleanPath($candidate) ?? $hubPath;
    }

    /** The path of a redirect target on this same host (relative, or absolute with the request's own scheme and host), else null. */
    private static function sameHostPath(Request $request, string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $parts = parse_url($location);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host']) || isset($parts['scheme'])) {
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $origin = strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . $port);

            if ($origin !== strtolower($request->getSchemeAndHttpHost())) {
                return null;
            }
        }

        // `http://host` without a path is the home page.
        return self::cleanPath(($parts['path'] ?? '') === '' ? '/' : $parts['path']);
    }

    /** The anonymous GET the site renders: same host and scheme, no cookies, no body, no credentials, no query. */
    private static function forward(Request $request, string $path): Request
    {
        $server = $request->server->all();

        foreach (['HTTP_COOKIE', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH', 'HTTP_ORIGIN', 'HTTP_REFERER',
            'HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST', 'AUTH_TYPE', 'HTTP_X_XSRF_TOKEN', 'HTTP_X_CSRF_TOKEN',
            'HTTP_X_HTTP_METHOD_OVERRIDE', 'HTTP_X_REQUESTED_WITH', 'QUERY_STRING', 'REDIRECT_URL', 'REDIRECT_QUERY_STRING', 'PATH_INFO', 'ORIG_PATH_INFO'] as $name) {
            unset($server[$name]);
        }

        $forward = Request::create($path, 'GET', [], [], [], $server);
        $forward->attributes->set('fsc.preview', true);

        return $forward;
    }

    /** `preview.js` right after `<head …>` (external, so `script-src 'self'` suffices) and `data-fsc-preview` on `<html>`, for HTML answers only. */
    private static function inject(Response $response, Preview $preview): void
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse || $response->isRedirection()) {
            return;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        if ($type !== '' && stripos($type, 'text/html') === false) {
            return;
        }

        $body = $response->getContent();

        if (!is_string($body) || ($type === '' && preg_match('/^\s*(<!doctype html|<html)/i', $body) !== 1)) {
            return;
        }

        $tag = self::scriptTag($preview);
        $body = preg_replace('/<html\b(?![^>]*\bdata-fsc-preview\b)/i', '<html data-fsc-preview', $body, 1) ?? $body;

        if (preg_match('/<head\b[^>]*>/i', $body, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);
            $body = substr($body, 0, $at) . $tag . substr($body, $at);
        } elseif (preg_match('/<html\b[^>]*>/i', $body, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);
            $body = substr($body, 0, $at) . $tag . substr($body, $at);
        } else {
            $body = $tag . $body;
        }

        $response->setContent($body);
        $response->headers->remove('Content-Length');
    }

    private static function scriptTag(Preview $preview): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $base = self::base();
        $nonce = self::viteNonce();

        return '<script src="' . $e($base . '/preview.js?v=' . ContentClient::VERSION) . '"'
            . ' data-endpoint="' . $e($base) . '"'
            . ' data-token="' . $e($preview->token()) . '"'
            . ' data-origin="' . $e($preview->embedOrigin()) . '"'
            . ' data-version="' . $preview->version() . '"'
            . ' data-sdk="' . $e(ContentClient::VERSION) . '"'
            . ($nonce !== null ? ' nonce="' . $e($nonce) . '"' : '')
            . '></script>';
    }

    private static function viteNonce(): ?string
    {
        try {
            $nonce = class_exists(Vite::class) ? Vite::cspNonce() : null;
        } catch (Throwable) {
            $nonce = null;
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    private static function refuse(int $status): Response
    {
        $response = new \Illuminate\Http\Response('', $status, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);

        if ($status === 429) {
            $response->headers->set('Retry-After', '60');
        }

        return $response;
    }
}
