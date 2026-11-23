<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Visitor;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * `GET _fsc/x/verify?n={nonce}` (plan sdk-out-of-the-box, section 8, V6): the probe of the Hub's site verification.
 *
 * The Hub calls this URL on the site's canonical origin with a fresh nonce. The site answers by calling the Hub API with its server token and
 * that nonce ({@see ContentClient::confirmSiteProxy()}); only that callback verifies the site, so it proves that the public URL reaches a
 * proxying SDK of the project the token belongs to (Cloudflare rules and route shadowing included). The answer to the probe itself carries no
 * information: 204 whether the Hub confirmed, refused or could not be reached, and also for a malformed nonce, so the route is no oracle for
 * "is this a valid nonce" or "is the Hub reachable".
 *
 * - 404 while the visitor pages are switched off (`FSC_AUTO_VISITOR_PAGES=false`): the Hub treats a clean 404 on demand as "unverify now".
 * - A nonce of another shape than the Hub's (43 URL-safe characters) is never sent to the Hub.
 * - At most {@see self::PER_MINUTE} confirmations per visitor IP per minute, then 429 without a Hub call: nobody can use the route to make the
 *   site call the Hub in a loop. The Hub's own probe is one request per check.
 * - No session, no cookie, never cached (the response goes through the same finalizer as the proxied pages).
 */
final class VerifyController
{
    /** The URI of the route (no leading slash). */
    public const URI = '_fsc/x/verify';

    /** Route name, outside the `content.visitor.` prefix of the proxied pages: this is not a page and is not forwarded. */
    public const NAME = 'content.site-proxy.verify';

    /** The shape of the nonce the Hub creates: 32 random bytes as base64url without padding. */
    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    /** Confirmations per visitor IP per minute. */
    public const PER_MINUTE = 10;

    /** Timeout of the confirmation call to the Hub, in milliseconds. */
    private const TIMEOUT_MS = 5000;

    public function __invoke(Request $request, ContentClient $client): Response
    {
        if (! Auto::on('visitor_pages')) {
            return self::answer(404);
        }

        $request->attributes->set(VisitorProxyController::ATTRIBUTE, true);
        $nonce = $request->query('n');

        if (! is_string($nonce) || preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            return self::answer(204);
        }

        if (self::throttled($request)) {
            return self::answer(429, ['Retry-After' => '60']);
        }

        try {
            $client->confirmSiteProxy($nonce, VisitorRoutes::VERSION, ContentClient::VERSION, ['timeoutMs' => self::TIMEOUT_MS]);
        } catch (Throwable) {
            // The Hub refused the nonce or did not answer: the probe learns nothing either way, and the Hub counts the missing callback.
        }

        return self::answer(204);
    }

    /** The per-IP limit. A cache failure never blocks the Hub's probe. */
    private static function throttled(Request $request): bool
    {
        $ip = ContentServiceProvider::visitorIp($request, config('content.forms.ip_header'));
        $key = 'fsc-verify:' . hash('sha256', (string) $ip);

        try {
            $limiter = app(RateLimiter::class);

            if ($limiter->tooManyAttempts($key, self::PER_MINUTE)) {
                return true;
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** @param array<string, string> $headers */
    private static function answer(int $status, array $headers = []): Response
    {
        return new Response('', $status, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex', ...$headers]);
    }
}
