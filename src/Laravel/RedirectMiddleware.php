<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use FullStackStudio\Content\Cache\LaravelStore;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\RedirectResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Performs a redirect from Content (mirror of `contentRedirectMiddleware`). Add it to the web group, early. Only GET and HEAD
 * requests are looked up; `/api`, `/build`, `/storage` and files with an extension are skipped. Results (including "no redirect")
 * are cached under the `content:redirects` tag by the client, and an outage at Content pauses lookups for 15 seconds.
 */
final class RedirectMiddleware
{
    /** The client is resolved on use, not injected: without a server token it cannot be built, and this middleware then has nothing to ask. */
    public function __construct(private readonly Container $container, private readonly Repository $config, private readonly CacheFactory $cache)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $next($request);
        }

        // `content.auto.hosts`: a site's other hosts (an admin subdomain) are none of the Hub's business.
        if (!Auto::hostAllowed($request->getHost())) {
            return $next($request);
        }

        // Once per request: the middleware may sit in the global stack and in the `web` group (or twice in a group).
        if ($request->attributes->get('_fsc_redirects_checked') === true) {
            return $next($request);
        }

        $request->attributes->set('_fsc_redirects_checked', true);

        // No token (local development, a site without Hub content): nothing to look up, so the page renders.
        if (trim((string) $this->config->get('content.token')) === '') {
            return $next($request);
        }

        $resolver = new RedirectResolver($this->container->make(ContentClient::class), [
            'timeoutMs' => (int) $this->config->get('content.redirects.timeout_ms', 1500),
            'failureCooldownSeconds' => (int) $this->config->get('content.redirects.failure_cooldown_seconds', 15),
        ], new LaravelStore($this->cache->store()));

        $target = $resolver->resolve($request->getPathInfo(), (string) $request->getQueryString());

        return $target === null ? $next($request) : new RedirectResponse($target['location'], $target['status']);
    }
}
