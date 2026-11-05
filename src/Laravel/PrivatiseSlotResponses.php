<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use Illuminate\Http\Request;

/**
 * Optional middleware with the same effect as the package's own `RequestHandled` listener: a response that used a visitor signal for a
 * server-side slot decision becomes `Cache-Control: private, no-cache` (and loses `Surrogate-Control`, `CDN-Cache-Control`, `Expires`).
 * Put it as the OUTERMOST middleware of a site that wraps responses in a server-side response cache or a streamed response, where the
 * listener comes too late. See AGENTS.md.
 */
final class PrivatiseSlotResponses
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (is_object($response)) {
            SlotDecisions::privatise($request, $response);
        }

        return $response;
    }
}
