<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Carbon\Carbon;
use Closure;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The cookie free middleware of the SDK's content routes (the default of `content.auto.middleware`). A plain Hub page has no session, no
 * cookies and no CSRF check, so it sends no `Set-Cookie` and can be cached.
 *
 * A page that renders a Hub form or a newsletter signup needs the session (the CSRF token and the message after a post): that component
 * calls {@see self::start()}, which starts the session on the spot (reading the encrypted session cookie of the request like `web` does).
 * This middleware then saves the session and adds the session cookie, encrypted exactly as `EncryptCookies` does, so the form post
 * (which runs in the `web` group) finds the same session. Without a started session nothing happens.
 */
final class LazySession
{
    private const STARTED = '_fsc_lazy_session';

    /** Set by the middleware: a session is only started on a request this middleware wraps (rendering outside a request is unchanged). */
    private const ARMED = '_fsc_lazy_session_armed';

    /** Starts the session of this request when it is not started yet (the `web` group starts it itself). Never throws. */
    public static function start(): void
    {
        try {
            $config = app('config');

            if (! $config->get('session.driver') || ! app()->bound('request')) {
                return;
            }

            $request = app('request');

            if ($request->attributes->get(self::ARMED) !== true) {
                return;
            }

            $store = app('session.store');

            if ($store->isStarted()) {
                return;
            }

            $store->setId(self::cookieId($request, $store->getName()));
            $store->start();
            $request->setLaravelSession($store);
            $request->attributes->set(self::STARTED, true);
        } catch (Throwable) {
            // No session available: the form renders without a flash message (the CSRF token then fails on post, as on any stateless route).
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ARMED, true);
        $response = $next($request);

        if ($request->attributes->get(self::STARTED) === true) {
            try {
                $this->persist($request, $response);
            } catch (Throwable) {
                // The page is rendered: a session that cannot be saved must not turn it into an error.
            }
        }

        return $response;
    }

    private function persist(Request $request, Response $response): void
    {
        $config = app('config');
        $store = app('session.store');
        $encrypter = app('encrypter');
        $name = $store->getName();
        $lifetime = (int) $config->get('session.lifetime', 120);
        $expire = $config->get('session.expire_on_close') ? 0 : Carbon::now()->addMinutes($lifetime)->getTimestamp();
        $value = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()) . $store->getId(), false);

        $store->save();

        $response->headers->setCookie(new Cookie(
            $name,
            $value,
            $expire,
            (string) $config->get('session.path', '/'),
            $config->get('session.domain'),
            $config->get('session.secure', false),
            (bool) $config->get('session.http_only', true),
            false,
            $config->get('session.same_site', 'lax'),
            (bool) $config->get('session.partitioned', false),
        ));
    }

    /** The session id of the request's (encrypted) session cookie, or null for a visitor without one. */
    private static function cookieId(Request $request, string $name): ?string
    {
        $raw = $request->cookies->get($name);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $encrypter = app('encrypter');
            $plain = $encrypter->decrypt($raw, false);

            return is_string($plain) ? CookieValuePrefix::remove($plain) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
