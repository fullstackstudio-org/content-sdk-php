<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use FullStackStudio\Content\Laravel\Site\PageContext;
use Illuminate\Contracts\Foundation\Application;

/**
 * The one place that answers "is out of the box feature X on" and reads the `content.auto` config. Reads config on every call (cheap, and
 * correct when a test or an Octane worker changes it); never calls the Hub.
 */
final class Auto
{
    private const PACKAGE_CONFIG = __DIR__ . '/../../../config/content.php';

    /**
     * Called by the service provider after `mergeConfigFrom`. `mergeConfigFrom` is shallow, so an app `auto` array would replace the package
     * block: deep-merge it so a sparse app config keeps every new default. `legal_paths` is a whole list: the app's replaces ours.
     * Also binds the request scoped {@see PageContext} and `Urls`.
     */
    public static function register(Application $app): void
    {
        $app->scoped(PageContext::class, static fn (): PageContext => new PageContext());
        // One per request: the project and SEO settings Urls reads are remembered, so a page builds many links with one lookup (Octane safe).
        $app->scoped(\FullStackStudio\Content\Laravel\Site\Urls::class);

        // A scoped binding is only reset by Octane or a queue worker. In a Laravel HTTP test (several requests in one application) the state
        // would leak from one request into the next (the "print once" parts of the first page missing from the second), so every request
        // ends by dropping both. Terminating callbacks run after the response is sent, so nothing of the request still reads them.
        $app->terminating(static function () use ($app): void {
            $app->forgetInstance(PageContext::class);
            $app->forgetInstance(\FullStackStudio\Content\Laravel\Site\Urls::class);
        });

        $config = $app['config'];
        $package = (require self::PACKAGE_CONFIG)['auto'];
        $site = $config->get('content.auto');

        if (! is_array($site)) {
            $config->set('content.auto', $package);

            return;
        }

        $merged = array_replace_recursive($package, $site);

        if (isset($site['legal_paths']) && is_array($site['legal_paths'])) {
            $merged['legal_paths'] = $site['legal_paths'];
        }

        $config->set('content.auto', $merged);
    }

    /** Master switch and feature switch. A feature that is not in the config is off. */
    public static function on(string $feature): bool
    {
        if (! self::flag(self::get('enabled'))) {
            return false;
        }

        return $feature === 'enabled' || self::flag(self::get($feature));
    }

    /** Value of `content.auto.middleware` that selects the cookie free preset ({@see self::middleware()}). */
    public const STATELESS = 'stateless';

    /**
     * Middleware of the content routes (`content.auto.middleware`): the Hub pages and the legal paths.
     *
     * - Default (null): `['web']`, as a site route in `routes/web.php` has (session, cookies, CSRF, the site's own web middleware).
     * - `'stateless'` (or `['stateless']`): the cookie free preset. The Hub redirect lookup (when `redirects` is on) and {@see LazySession}:
     *   no session, no cookies and no CSRF check on a plain Hub page, an empty `$errors` bag for layouts with `@error`, and a session
     *   that starts on demand for a page that renders a form or `@csrf` (or a visitor who already has a session cookie).
     * - Any other list: used as is.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        $configured = self::get('middleware');

        if (is_string($configured) && $configured !== '') {
            $configured = [$configured];
        }

        if (! is_array($configured) || $configured === []) {
            return ['web'];
        }

        $list = array_values(array_filter($configured, 'is_string'));

        if ($list === [self::STATELESS]) {
            return array_values(array_filter([
                self::on('redirects') ? \FullStackStudio\Content\Laravel\RedirectMiddleware::class : null,
                \FullStackStudio\Content\Laravel\Site\LazySession::class,
            ]));
        }

        return $list === [] ? ['web'] : $list;
    }

    /** Whether the content routes run the cookie free preset (`content.auto.middleware` = `'stateless'`, or a list with {@see LazySession}). */
    public static function stateless(): bool
    {
        return in_array(\FullStackStudio\Content\Laravel\Site\LazySession::class, self::middleware(), true);
    }

    /**
     * Hosts the SDK's routes and the redirect middleware answer on (`content.auto.hosts`, patterns with `*`). Empty (the default) = every
     * host. For a site whose other hosts (an admin subdomain) belong to the site alone.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        $configured = self::get('hosts');
        $list = is_string($configured) ? [$configured] : (is_array($configured) ? $configured : []);

        return array_values(array_filter(array_map(static fn ($host): string => strtolower(trim((string) $host)), $list), static fn (string $host): bool => $host !== ''));
    }

    /** Whether the SDK answers on this host (always true without `content.auto.hosts`). */
    public static function hostAllowed(string $host): bool
    {
        $hosts = self::hosts();

        return $hosts === [] || \Illuminate\Support\Str::is($hosts, strtolower($host));
    }

    /** Layout view name the default views extend, or null for the package layout. */
    public static function layout(): ?string
    {
        return self::string('layout');
    }

    /** Name of the content section of that layout (default `content`). */
    public static function section(): string
    {
        return self::string('section') ?? 'content';
    }

    /** Classes of the content container, or null. */
    public static function wrap(): ?string
    {
        return self::string('wrap');
    }

    /** View for a page type: `content.auto.views.<type>`, else `content::site.<type>`. */
    public static function view(string $type): string
    {
        return self::string('views.' . $type) ?? 'content::site.' . $type;
    }

    /** Own handler class for a page type, or null. */
    public static function handler(string $type): ?string
    {
        $class = self::string('handlers.' . $type);

        return $class !== null && class_exists($class) ? $class : null;
    }

    /** Option of a feature that has options (`articles`, `sitemap`): `Auto::option('articles', 'per_page', 12)`. */
    public static function option(string $feature, string $key, mixed $default = null): mixed
    {
        $value = self::get($feature);

        return is_array($value) && array_key_exists($key, $value) ? $value[$key] : $default;
    }

    /** @return array<string, string> path => legal kind */
    public static function legalPaths(): array
    {
        $paths = self::get('legal_paths');

        return is_array($paths) ? array_filter($paths, 'is_string') : [];
    }

    private static function get(string $key): mixed
    {
        return config('content.auto.' . $key);
    }

    private static function string(string $key): ?string
    {
        $value = self::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A feature value is a bool, or an array whose `enabled` (default true) decides. */
    private static function flag(mixed $value): bool
    {
        if (is_array($value)) {
            return (bool) ($value['enabled'] ?? true);
        }

        return (bool) $value;
    }
}
