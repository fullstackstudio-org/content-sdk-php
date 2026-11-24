<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Carbon\Carbon;
use Closure;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The cookie free middleware of the SDK's content routes (the `'stateless'` preset of `content.auto.middleware`). A plain Hub page has no
 * session, no cookies and no CSRF check, so it sends no `Set-Cookie` and can be cached.
 *
 * - `$errors` is shared as an empty `ViewErrorBag`, so a layout with `@error` or `$errors->any()` renders as it does under `web`.
 * - A page that needs the session starts it on demand through {@see self::start()}: a Hub form or a newsletter signup (the component calls
 *   it) and `@csrf` (the SDK compiles the directive with that call first), so the token in the page belongs to a session that is saved.
 * - A visitor who already has a session cookie (back from a form post with errors or a flash message) gets the session started up front,
 *   so `$errors`, `old()` and the flash message are there wherever the layout reads them.
 *
 * A started session is saved after the page rendered and its cookie is added, encrypted exactly as `EncryptCookies` does, so the form post
 * (which runs in the `web` group) finds the same session and token. Without a started session nothing is written.
 * `csrf_token()` and `session()` called directly in a layout are not intercepted: they read an unstarted session (no token). `fsc:doctor`
 * warns about such layouts when the preset is on.
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

        // A returning visitor's session (errors and old input after a failed post, a flash message): start it before anything renders.
        if (self::cookieId($request, self::cookieName()) !== null) {
            self::start();
        }

        self::shareErrors($request);
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

    /** `$errors` for the views: the bag of a started session, else an empty one (what `ShareErrorsFromSession` does under `web`). */
    private static function shareErrors(Request $request): void
    {
        try {
            $errors = null;

            if ($request->attributes->get(self::STARTED) === true) {
                $errors = app('session.store')->get('errors');
            }

            app('view')->share('errors', $errors instanceof ViewErrorBag ? $errors : new ViewErrorBag());
        } catch (Throwable) {
            // No view factory: nothing renders a view.
        }
    }

    private static function cookieName(): string
    {
        try {
            return (string) app('config')->get('session.cookie', '');
        } catch (Throwable) {
            return '';
        }
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

    /**
     * The session id of the request's (encrypted) session cookie, or null for a visitor without one. Decrypted and checked like
     * `EncryptCookies` does: the value must carry the cookie's prefix for the current key or one of the previous keys (`APP_PREVIOUS_KEYS`).
     */
    private static function cookieId(Request $request, string $name): ?string
    {
        $raw = $name === '' ? null : $request->cookies->get($name);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $encrypter = app('encrypter');
            $plain = $encrypter->decrypt($raw, false);

            if (! is_string($plain)) {
                return null;
            }

            $keys = method_exists($encrypter, 'getAllKeys') ? $encrypter->getAllKeys() : [$encrypter->getKey()];
            $id = CookieValuePrefix::validate($name, $plain, $keys);

            return is_string($id) && $id !== '' ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
