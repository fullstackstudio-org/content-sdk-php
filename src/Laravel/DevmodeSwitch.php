<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (registered by the service provider while `content.devmode.enabled` is on): `?fsc_devmode=1` on any GET page sets the devmode
 * cookie for a year, `?fsc_devmode=0` clears it; both answer a 302 to the same URL without the parameter, `Cache-Control: no-store`. The cookie is
 * written here, outside the `web` group, so it is plain (see {@see Devmode::requested()}); the server only reads it.
 */
final class DevmodeSwitch
{
    private const YEAR = 31_536_000;

    public function handle(Request $request, Closure $next): Response
    {
        if (!Devmode::enabled() || !$request->isMethod('GET') || !$request->query->has(Devmode::PARAM)) {
            return $next($request);
        }

        $value = $request->query->get(Devmode::PARAM);

        if ($value !== '1' && $value !== '0') {
            return $next($request);
        }

        $on = $value === '1';
        // A relative target: behind a proxy or CDN the request may arrive as http, and an absolute URL built from it would downgrade.
        $query = $request->query->all();
        unset($query[Devmode::PARAM]);
        $target = $request->getBaseUrl() . $request->getPathInfo() . ($query === [] ? '' : '?' . http_build_query($query));
        $response = new RedirectResponse($target, 302);
        $secure = $request->isSecure() || str_starts_with((string) config('app.url', ''), 'https://');
        $response->headers->setCookie(new Cookie(Devmode::cookie(), $on ? '1' : '', $on ? time() + self::YEAR : 1, '/', null, $secure, false, false, Cookie::SAMESITE_LAX));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
