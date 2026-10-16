<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use FullStackStudio\Content\Cache\LaravelStore;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\RedirectResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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
    public function __construct(private readonly ContentClient $client, private readonly Repository $config, private readonly CacheFactory $cache)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $next($request);
        }

        $resolver = new RedirectResolver($this->client, [
            'timeoutMs' => (int) $this->config->get('content.redirects.timeout_ms', 1500),
            'failureCooldownSeconds' => (int) $this->config->get('content.redirects.failure_cooldown_seconds', 15),
        ], new LaravelStore($this->cache->store()));

        $target = $resolver->resolve($request->getPathInfo(), (string) $request->getQueryString());

        return $target === null ? $next($request) : new RedirectResponse($target['location'], $target['status']);
    }
}
