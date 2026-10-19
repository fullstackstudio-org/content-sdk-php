<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Http\CurlTransport;
use FullStackStudio\Content\Http\Request as UpstreamRequest;
use FullStackStudio\Content\Http\Transport;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The analytics routes under `content.analytics.path` (default `/_fsc/a`), registered without the `web` group: no session, no cookies, no
 * CSRF.
 *
 * - `GET fsc.js`: the helper script (always, also in direct mode).
 * - First-party mode only: `GET script.js` and `GET recorder.js` (fetched from the Umami host and cached), `POST api/send` (pageviews and
 *   events), `POST api/record` (heatmap and replay uploads; the helper only lets the recorder send after cookie consent) and
 *   `GET api/websites/{id}/recorder` (the recorder settings).
 *
 * Proxying rules: only for this site's own website id (no open relay); forwards the visitor IP (`content.analytics.ip_header`, else the IP
 * Laravel sees) as `X-FSC-Client-IP`, `True-Client-IP` and `X-Forwarded-For`, plus `User-Agent`, `Content-Type` and Umami's own
 * `x-umami-cache`. Never cookies or other headers. Short timeout; a failure answers `{}` so the page never notices. Rate limit per visitor IP.
 */
final class AnalyticsController
{
    /** Container key to swap the upstream transport (tests). */
    public const TRANSPORT = 'content.analytics.transport';

    private const MAX_SEND_BYTES = 64 * 1024;

    private const MAX_RECORD_BYTES = 1024 * 1024;

    public function helper(): Response
    {
        $source = (string) file_get_contents(__DIR__ . '/../../resources/assets/fsc-analytics.js');

        return $this->javascript($source, 3600);
    }

    public function script(): Response
    {
        return $this->upstreamScript('script.js');
    }

    public function recorder(): Response
    {
        return $this->upstreamScript('recorder.js');
    }

    public function send(Request $request): Response
    {
        return $this->collect($request, 'api/send', self::MAX_SEND_BYTES);
    }

    public function record(Request $request): Response
    {
        return $this->collect($request, 'api/record', self::MAX_RECORD_BYTES);
    }

    public function recorderConfig(Request $request, string $id): Response
    {
        $settings = AnalyticsSettings::make();

        if ($id !== $settings->websiteId()) {
            return $this->json('{"enabled":false}', 200);
        }

        $key = 'fsc-analytics:recorder-config:' . $id;

        try {
            $cached = app('cache')->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_string($cached)) {
            return $this->json($cached, 200, 'private, max-age=60');
        }

        try {
            $response = $this->transport()->send(new UpstreamRequest('GET', $settings->host() . '/api/websites/' . rawurlencode($id) . '/recorder', [
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent($request),
            ], null, null, $this->timeoutMs()));
        } catch (Throwable) {
            return $this->json('{"enabled":false}', 200);
        }

        if ($response->status !== 200 || !is_array(json_decode($response->body, true))) {
            return $this->json('{"enabled":false}', 200);
        }

        try {
            app('cache')->put($key, $response->body, 60);
        } catch (Throwable) {
            // Not cached: the next page view asks again.
        }

        return $this->json($response->body, 200, 'private, max-age=60');
    }

    private function collect(Request $request, string $path, int $maxBytes): Response
    {
        $settings = AnalyticsSettings::make();
        $websiteId = $settings->websiteId();
        $ip = $this->visitorIp($request);

        if ($websiteId === null) {
            return $this->json('{}', 202);
        }

        try {
            $limiter = app(RateLimiter::class);
            $key = 'fsc-analytics:' . hash('sha256', (string) $ip);

            if ($limiter->tooManyAttempts($key, max(1, (int) config('content.analytics.rate_limit', 240)))) {
                return $this->json('{}', 429);
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            // No cache for the limiter: let it through rather than break counting.
        }

        $body = (string) $request->getContent();

        if ($body === '' || strlen($body) > $maxBytes) {
            return $this->json('{}', 413);
        }

        $payload = json_decode($body, true);

        if (!is_array($payload) || ($payload['payload']['website'] ?? null) !== $websiteId) {
            return $this->json('{}', 400);
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent($request),
        ];

        if ($ip !== null) {
            $headers['X-FSC-Client-IP'] = $ip;
            $headers['True-Client-IP'] = $ip;
            $headers['X-Forwarded-For'] = $ip;
        }

        $cache = (string) $request->headers->get('x-umami-cache', '');

        if ($cache !== '' && strlen($cache) <= 4096 && preg_match('/^[A-Za-z0-9._~+\/=-]+$/D', $cache) === 1) {
            $headers['x-umami-cache'] = $cache;
        }

        try {
            $response = $this->transport()->send(new UpstreamRequest('POST', $settings->host() . '/' . $path, $headers, $body, null, $this->timeoutMs()));
        } catch (Throwable) {
            return $this->json('{}', 202);
        }

        if ($response->status >= 200 && $response->status < 300 && strlen($response->body) <= 64 * 1024) {
            return $this->json($response->body !== '' ? $response->body : '{}', $response->status);
        }

        return $this->json('{}', 202);
    }

    private function upstreamScript(string $file): Response
    {
        $settings = AnalyticsSettings::make();
        $ttl = max(60, (int) config('content.analytics.script_ttl', 3600));
        $key = 'fsc-analytics:script:' . $file;
        $stale = $key . ':stale';

        try {
            $cached = app('cache')->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_string($cached) && $cached !== '') {
            return $this->javascript($cached, $ttl);
        }

        try {
            $response = $this->transport()->send(new UpstreamRequest('GET', $settings->host() . '/' . $file, ['Accept' => '*/*'], null, null, max($this->timeoutMs(), 5000)));
            $source = $response->status === 200 && $response->body !== '' && strlen($response->body) <= 2 * 1024 * 1024 ? $response->body : null;
        } catch (Throwable) {
            $source = null;
        }

        if ($source !== null) {
            try {
                app('cache')->put($key, $source, $ttl);
                app('cache')->forever($stale, $source);
            } catch (Throwable) {
                // Served uncached.
            }

            return $this->javascript($source, $ttl);
        }

        try {
            $old = app('cache')->get($stale);
        } catch (Throwable) {
            $old = null;
        }

        // Umami unreachable and nothing cached: an empty script, so the page only misses analytics.
        return $this->javascript(is_string($old) ? $old : '/* analytics unavailable */', is_string($old) ? 300 : 60);
    }

    private function visitorIp(Request $request): ?string
    {
        $header = config('content.analytics.ip_header');
        $candidate = is_string($header) && $header !== '' ? $request->headers->get($header) : null;

        if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_IP) !== false) {
            return trim($candidate);
        }

        return $request->ip();
    }

    private function userAgent(Request $request): string
    {
        $agent = (string) $request->headers->get('user-agent', '');

        return substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', $agent), 0, 512);
    }

    private function timeoutMs(): int
    {
        return min(10_000, max(500, (int) config('content.analytics.timeout_ms', 3000)));
    }

    private function transport(): Transport
    {
        $bound = app()->bound(self::TRANSPORT) ? app(self::TRANSPORT) : null;

        return $bound instanceof Transport ? $bound : new CurlTransport();
    }

    private function javascript(string $source, int $maxAge): Response
    {
        return new Response($source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=' . $maxAge,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function json(string $body, int $status, string $cacheControl = 'no-store'): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
