<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (registered by the service provider): a page view with `?fscn=<token>` (a FullStack Hub newsletter click link, plan
 * ootb-integrations D9) hands the token to {@see IntelligenceProxy::newsletterClickLater()}, which links a consenting browser to the
 * subscriber after the response. The page itself is served unchanged; fsa.js and fsc.js remove `fscn` from the address bar. Does nothing
 * while `content.intelligence.enabled` is off or without the parameter.
 */
final class NewsletterClickLink
{
    public function handle(Request $request, Closure $next): Response
    {
        IntelligenceProxy::newsletterClickLater($request);

        return $next($request);
    }
}
