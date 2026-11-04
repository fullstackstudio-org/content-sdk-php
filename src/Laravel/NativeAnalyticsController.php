<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Analytics;
use FullStackStudio\Content\Http\CurlTransport;
use FullStackStudio\Content\Http\Request as UpstreamRequest;
use FullStackStudio\Content\Http\Transport;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;
use Throwable;

/**
 * The native tracker routes under `content.analytics.path` (default `/_fsc/a`), registered in every mode and without the `web` group:
 * no session, no cookies, no CSRF.
 *
 * - `GET fsa.js`: the tracker (`resources/assets/fsa.js`).
 * - `POST e`: one batch of the tracker. Checked here, then forwarded to the collector inside the cluster
 *   (`content.analytics.collector_url`) with the visitor IP signed with this site's key (`X-Fsc-Client-Ip`, `X-Fsc-Ts`, `X-Fsc-Sig`,
 *   contract `collector/internal/proxyauth`). Only this site's own sitekey is forwarded (no open relay); without consent (`c != 1`) the
 *   persistent id and heatmap clicks are removed before signing, whatever the browser sent. Forwarded headers: `User-Agent`,
 *   `Accept-Language`, `Origin`, `Referer` (the collector checks the domain). Never cookies. The answer is always `202 {}` unless the
 *   request itself is wrong (400/413/429): a collector problem never reaches the page.
 */
final class NativeAnalyticsController
{
    /** Container key to swap the collector transport (tests). */
    public const TRANSPORT = 'content.analytics.native-transport';

    private const MAX_BYTES = 64 * 1024;

    private const MAX_EVENTS = 50;

    public function tracker(): Response
    {
        $source = (string) file_get_contents(__DIR__ . '/../../resources/assets/fsa.js');

        return new Response($source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // The tag carries ?v=<sdk version>, so a long browser cache is safe.
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function collect(Request $request): Response
    {
        $body = (string) $request->getContent();

        if ($body === '') {
            return $this->json(400);
        }

        if (strlen($body) > self::MAX_BYTES) {
            return $this->json(413);
        }

        $settings = AnalyticsSettings::make();
        $sitekey = $settings->nativeSitekey();
        $signingKey = $settings->nativeSigningKey();
        $collector = $settings->collectorUrl();

        if ($sitekey === null || $signingKey === null || $collector === null) {
            return $this->json(202);
        }

        $ip = $this->visitorIp($request);

        try {
            $limiter = app(RateLimiter::class);
            $key = 'fsa:' . hash('sha256', (string) $ip);

            if ($limiter->tooManyAttempts($key, max(1, (int) config('content.analytics.rate_limit', 240)))) {
                return $this->json(429);
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            // No cache for the limiter: let it through rather than break counting.
        }

        try {
            $batch = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->json(400);
        }

        if (!$batch instanceof stdClass || ($batch->k ?? null) !== $sitekey || !is_array($batch->e ?? null) || $batch->e === [] || count($batch->e) > self::MAX_EVENTS) {
            return $this->json(400);
        }

        self::enforceConsent($batch);

        if ($batch->e === []) {
            return $this->json(202);
        }

        $forward = (string) json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'text/plain; charset=utf-8', 'User-Agent' => $this->header($request, 'user-agent', 512)];

        foreach (['accept-language' => 'Accept-Language', 'origin' => 'Origin', 'referer' => 'Referer'] as $from => $to) {
            $value = $this->header($request, $from, $from === 'referer' ? 1024 : 256);

            if ($value !== '') {
                $headers[$to] = $value;
            }
        }

        if ($ip !== null) {
            $headers = [...$headers, ...Analytics::signHeaders($signingKey, $ip, $forward)];
        }

        try {
            $this->transport()->send(new UpstreamRequest('POST', $collector, $headers, $forward, null, $this->timeoutMs()));
        } catch (Throwable) {
            // Collector unreachable: this batch is lost, the page never notices.
        }

        return $this->json(202);
    }

    /**
     * D5: without consent no persistent id and no heatmap clicks leave this site, whatever the browser sent. The collector enforces
     * the same; this is the site-side half, so a manipulated tracker cannot even make the request.
     */
    public static function enforceConsent(stdClass $batch): void
    {
        if (($batch->c ?? 0) === 1) {
            return;
        }

        unset($batch->pid);
        $batch->c = 0;
        $batch->e = array_values(array_filter($batch->e, fn (mixed $event) => !($event instanceof stdClass && ($event->t ?? null) === 'heatmap')));
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

    private function header(Request $request, string $name, int $max): string
    {
        return substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $request->headers->get($name, '')), 0, $max);
    }

    private function timeoutMs(): int
    {
        return min(5000, max(200, (int) config('content.analytics.collector_timeout_ms', 1500)));
    }

    private function transport(): Transport
    {
        $bound = app()->bound(self::TRANSPORT) ? app(self::TRANSPORT) : null;

        return $bound instanceof Transport ? $bound : new CurlTransport();
    }

    private function json(int $status): Response
    {
        return new Response('{}', $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
