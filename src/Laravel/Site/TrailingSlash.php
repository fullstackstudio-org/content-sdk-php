<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Site;

use Closure;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Visitor\VisitorRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the URLs of a rebuilt WordPress site 1:1: with `content.auto.trailing_slash` on, a GET/HEAD page path without a trailing slash gets a
 * 301 to the same path with one (`/hypnotherapie` to `/hypnotherapie/`); the query string is kept. Registered as a global middleware by
 * {@see \FullStackStudio\Content\Laravel\Auto\AutoPlatform}, only when that switch is on. {@see Urls} builds the same slashed form.
 *
 * Left alone: `/`, files (a dot in the last segment), the technical prefixes in {@see self::SKIP} and `content.auto.trailing_slash_skip`,
 * the visitor proxy paths of the Hub ({@see VisitorRoutes::matches()}, which have their own URL shape) and the portal preview request.
 */
final class TrailingSlash
{
    /** First path segments that are never redirected. */
    public const SKIP = ['api', '_fsc', 'content', 'build', 'storage'];

    public function handle(Request $request, Closure $next): Response
    {
        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && ! $request->attributes->get('fsc.preview')) {
            $path = $request->getPathInfo();

            if ($this->redirects($path)) {
                // Collapse leading slashes, else `//host` would become a protocol-relative redirect to another site.
                $target = '/' . trim($path, '/') . '/';
                $query = $request->getQueryString();

                return new RedirectResponse($target . ($query !== null && $query !== '' ? '?' . $query : ''), 301);
            }
        }

        return $next($request);
    }

    private function redirects(string $path): bool
    {
        if ($path === '' || $path === '/' || str_ends_with($path, '/') || str_contains(basename($path), '.')) {
            return false;
        }

        // The Hub's visitor pages: the proxy answers them exactly as the Hub's URLs are shaped.
        if (VisitorRoutes::matches($path)) {
            return false;
        }

        $first = explode('/', ltrim($path, '/'))[0];

        return ! in_array($first, self::skipped(), true);
    }

    /** @return list<string> */
    private static function skipped(): array
    {
        $extra = [];

        foreach ((array) config('content.auto.trailing_slash_skip', []) as $prefix) {
            if (is_string($prefix) && ($prefix = trim($prefix, '/')) !== '') {
                // A prefix is its first segment: `blog/feed` would never match the first segment alone, so only that part counts.
                $extra[] = explode('/', $prefix)[0];
            }
        }

        return array_merge(self::SKIP, $extra);
    }
}
