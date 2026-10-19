<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Errors;

use FullStackStudio\Content\Errors\BrowserEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * `POST /_fsc/e/envelope`: the first-party tunnel of the browser reporter (`<x-content::errors />`). The browser posts to the site's own
 * domain (no ad blocker or CSP rule for a third-party host), the site forwards server-side to GlitchTip.
 *
 * Not an open relay: the envelope may only name the site's own browser DSN, and it is never forwarded as is: every event is rebuilt from
 * the allowlist (`BrowserEvent::prepare`) and sent with the site's own DSN, environment and release. Body at most 64 kB, 10 events per minute
 * per client IP and 120 per minute per site, a foreign `Origin` is refused, no session or cookies (the route is outside the `web` group) and
 * the answer is always an empty `no-store` response. Forwarding happens after the response.
 */
final class TunnelController
{
    public function __invoke(Request $request, Errors $errors): Response
    {
        try {
            $config = $errors->config();
            $origin = $errors->origin();

            if ($config === null || $config->browserDsn === null || $origin === null || ! app('config')->get('content.errors.browser', true)) {
                return self::reply(404);
            }

            if (! self::sameOrigin($request, $origin)) {
                return self::reply(403);
            }

            $max = (int) app('config')->get('content.errors.tunnel.max_bytes', 65536);
            $declared = $request->headers->get('Content-Length');

            if ($declared !== null && (int) $declared > $max) {
                return self::reply(413);
            }

            $body = $request->getContent();

            if (strlen($body) > $max) {
                return self::reply(413);
            }

            if (! self::allow($request)) {
                return self::reply(429, ['Retry-After' => '60']);
            }

            $events = BrowserEvent::eventsFromEnvelope($body, $config->browserDsn);

            if ($events === null) {
                return self::reply(400);
            }

            $clean = [];

            foreach ($events as $event) {
                $prepared = BrowserEvent::prepare($event, $config, $origin);

                if ($prepared !== null) {
                    $clean[] = $prepared;
                }
            }

            if ($clean !== []) {
                app()->terminating(function () use ($errors, $clean, $config, $origin): void {
                    foreach ($clean as $event) {
                        try {
                            $errors->delivery()->deliver($event, $config, $origin);
                        } catch (Throwable) {
                            // Monitoring never breaks the app.
                        }
                    }
                });
            }

            return self::reply(202);
        } catch (Throwable) {
            return self::reply(202);
        }
    }

    /** A browser always sends `Origin` on this POST; it must be the site itself (same host as the request or `app.url`). */
    private static function sameOrigin(Request $request, string $origin): bool
    {
        $header = $request->headers->get('Origin');

        if ($header === null || $header === '') {
            return true;
        }

        $host = parse_url($header, PHP_URL_HOST);

        return is_string($host) && (strcasecmp($host, $request->getHost()) === 0 || strcasecmp($host, (string) parse_url($origin, PHP_URL_HOST)) === 0);
    }

    private static function allow(Request $request): bool
    {
        try {
            $limiter = app(\Illuminate\Cache\RateLimiter::class);
            $config = app('config');
            // The same visitor-IP header as the form route (`content.forms.ip_header`, Cloudflare by default).
            $header = $config->get('content.forms.ip_header');
            $ip = (string) ((is_string($header) && $header !== '' ? $request->headers->get($header) : null) ?: $request->ip());
            $ipKey = 'fsc-errors:tunnel:ip:' . sha1($ip);
            $allKey = 'fsc-errors:tunnel:all';

            if ($limiter->tooManyAttempts($ipKey, max(1, (int) $config->get('content.errors.tunnel.per_minute_ip', 10)))
                || $limiter->tooManyAttempts($allKey, max(1, (int) $config->get('content.errors.tunnel.per_minute', 120)))) {
                return false;
            }

            $limiter->hit($ipKey, 60);
            $limiter->hit($allKey, 60);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, string> $headers */
    private static function reply(int $status, array $headers = []): Response
    {
        return new Response('', $status, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ]);
    }
}
