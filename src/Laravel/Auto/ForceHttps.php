<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware of the `platform` feature: `URL::forceScheme('https')` in production, decided at request time (see
 * {@see AutoPlatform::applySchemeNow()}). Behind Cloudflare and the ingress the request arrives as http, so without it redirects and
 * generated URLs would be `http://`. A scheme the app forced itself is left alone.
 */
final class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        AutoPlatform::applySchemeNow(app());

        return $next($request);
    }
}
