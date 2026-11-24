<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Added to the SDK's routes when `content.auto.hosts` is set: on any other host they are a plain 404, as if they did not exist. */
final class HostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auto::hostAllowed($request->getHost())) {
            abort(404);
        }

        return $next($request);
    }
}
