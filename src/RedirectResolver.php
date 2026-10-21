<?php

declare(strict_types=1);

namespace FullStackStudio\Content;

use FullStackStudio\Content\Cache\Store;

/**
 * Looks up a Content redirect for a request path (mirror of `contentRedirectMiddleware` in `next.ts`), framework independent.
 *
 * Cost: every new path costs one call to `GET /api/v1/redirects/resolve` unless the client has a cache (then results, including
 * "no redirect", are cached under the `content:redirects` tag and a `redirect.*` webhook clears them). If Content fails, the
 * resolver stops asking for `failureCooldownSeconds` (when a pause store is given) so an outage never slows the site down.
 */
final class RedirectResolver
{
    /**
     * @param array{timeoutMs?: int, failureCooldownSeconds?: int, moduleOffCooldownSeconds?: int, skip?: callable(string): bool, onError?: callable(\Throwable): void} $options
     *        `moduleOffCooldownSeconds` (default 60): pause after a 404 `module_disabled` (the project has no redirects module); that is not
     *        an error, so `onError` is not called for it
     * @param Store|null $pauseStore remembers the failure cooldown across requests
     */
    public function __construct(private readonly ContentClient $client, private readonly array $options = [], private readonly ?Store $pauseStore = null)
    {
    }

    /** Next internals, `/api/`, `/build`, `/storage` and files with an extension. */
    public static function defaultSkip(string $path): bool
    {
        return $path === '/api' || str_starts_with($path, '/api/') || str_starts_with($path, '/_next/') || $path === '/build' || str_starts_with($path, '/build/')
            || $path === '/storage' || str_starts_with($path, '/storage/') || preg_match('/\.[a-z0-9]+$/i', $path) === 1;
    }

    /**
     * @param string $query the request's query string without `?`; a relative target without its own query takes it along, any other
     *        target keeps at least the attribution parameters (utm_*, ad click ids) so campaigns survive the redirect
     * @return array{location: string, status: int}|null
     */
    public function resolve(string $path, string $query = ''): ?array
    {
        $skip = $this->options['skip'] ?? [self::class, 'defaultSkip'];

        if ($skip($path)) {
            return null;
        }

        $pauseKey = 'fsc:redirect:pause';

        if ($this->pauseStore !== null && $this->pauseStore->get($pauseKey) !== null) {
            return null;
        }

        try {
            $resolved = $this->client->resolveRedirect($path, ['timeoutMs' => $this->options['timeoutMs'] ?? 1500]);
        } catch (\Throwable $error) {
            if ($error instanceof ContentException && $error->isModuleDisabled()) {
                $this->pauseStore?->put($pauseKey, 1, $this->options['moduleOffCooldownSeconds'] ?? 60);

                return null;
            }

            $this->pauseStore?->put($pauseKey, 1, $this->options['failureCooldownSeconds'] ?? 15);

            if (isset($this->options['onError'])) {
                ($this->options['onError'])($error);
            }

            return null;
        }

        if ($resolved === null) {
            return null;
        }

        $location = (string) $resolved['location'];

        if ($query !== '' && str_starts_with($location, '/') && !str_contains($location, '?')) {
            $location .= '?' . $query;
        } elseif ($query !== '') {
            $location = self::withAttribution($location, $query);
        }

        return ['location' => $location, 'status' => (int) ($resolved['statusCode'] ?? 301)];
    }

    /** Appends the utm_* and ad click id parameters of `$query` that `$location` does not have yet (before a `#fragment`). */
    public static function withAttribution(string $location, string $query): string
    {
        $keep = [];

        foreach (explode('&', $query) as $pair) {
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

            if ($pair !== '' && in_array($name, Analytics::DEFAULT_PARAMS, true) && preg_match('/[?&]' . preg_quote($name, '/') . '=/i', $location) !== 1) {
                $keep[] = $pair;
            }
        }

        if ($keep === []) {
            return $location;
        }

        [$base, $fragment] = array_pad(explode('#', $location, 2), 2, null);

        return $base . (str_contains($base, '?') ? '&' : '?') . implode('&', $keep) . ($fragment !== null ? '#' . $fragment : '');
    }
}
