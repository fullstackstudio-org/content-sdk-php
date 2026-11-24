<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Laravel\Site\TrailingSlash;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use ReflectionProperty;
use Throwable;

/**
 * Platform basics of the out of the box site, three things that broke live sites when forgotten:
 *
 * - `platform` on: trust proxies `*` (only when the app configured none, in `bootstrap/app.php`, a provider or `config/trustedproxy.php`)
 *   and `URL::forceScheme('https')` (only in production, and only when the app did not force a scheme itself).
 * - `redirects` on: `RedirectMiddleware` first in the `web` group (only when the group does not contain it already).
 *
 * - `trailing_slash` on (off by default): the global middleware {@see TrailingSlash}, readable through {@see self::trailingSlash()}.
 *
 * Everything runs after all providers booted, so what the site set itself is visible and always wins. Idempotent. The kernel is only
 * touched where it exists: in the console nothing resolves it, so nothing happens there (a test that handles a request does resolve it).
 * What was applied and what the app had set is readable through {@see self::state()} for `fsc:doctor`.
 */
final class AutoPlatform
{
    public const APPLIED = 'applied';
    public const BY_APP = 'app';
    public const OFF = 'off';
    /** Not applicable (https outside production, the redirect middleware where there is no `web` group). */
    public const SKIPPED = 'skipped';
    /** The switch is on but the kernel has not been resolved yet (console). */
    public const PENDING = 'pending';

    /** @var array{proxies: string, https: string, redirects: string} */
    private static array $state = ['proxies' => self::OFF, 'https' => self::OFF, 'redirects' => self::OFF];

    /** State of the trailing slash middleware, kept apart from {@see self::state()} (one of the constants). */
    private static string $trailingSlash = self::OFF;

    /** Called once from the service provider's `boot()`. */
    public static function register(Application $app): void
    {
        self::$state = ['proxies' => self::OFF, 'https' => self::OFF, 'redirects' => self::OFF];
        self::$trailingSlash = self::OFF;

        $app->booted(static function (Application $app): void {
            if (Auto::on('platform')) {
                self::applyProxies($app);
                self::applyScheme($app);
                self::registerSchemeMiddleware($app);
            }

            if (Auto::on('redirects')) {
                self::applyRedirects($app);
            }

            if (Auto::on('trailing_slash')) {
                self::applyTrailingSlash($app);
            }
        });
    }

    /**
     * @return array{proxies: string, https: string, redirects: string} per item one of the constants:
     *     `applied` (the package did it), `app` (the app had set it), `off` (switch off), `skipped`, `pending`
     */
    public static function state(): array
    {
        return self::$state;
    }

    /** `applied`, `app` (the app already has the middleware), `off`, `pending` (console, kernel not resolved yet) or `skipped`. */
    public static function trailingSlash(): string
    {
        return self::$trailingSlash;
    }

    private static function applyTrailingSlash(Application $app): void
    {
        if ($app->resolved(KernelContract::class)) {
            self::pushTrailingSlash($app->make(KernelContract::class));

            return;
        }

        self::$trailingSlash = self::PENDING;
        $app->afterResolving(KernelContract::class, static fn (object $kernel) => self::pushTrailingSlash($kernel));
    }

    private static function pushTrailingSlash(object $kernel): void
    {
        if (! method_exists($kernel, 'pushMiddleware')) {
            self::$trailingSlash = self::SKIPPED;

            return;
        }

        if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(TrailingSlash::class)) {
            self::$trailingSlash = self::BY_APP;

            return;
        }

        $kernel->pushMiddleware(TrailingSlash::class);
        self::$trailingSlash = self::APPLIED;
    }

    private static function applyProxies(Application $app): void
    {
        $set = self::staticProperty(TrustProxies::class, 'alwaysTrustProxies');
        $config = $app['config']->get('trustedproxy.proxies');

        if (! empty($set) || ! empty($config)) {
            self::$state['proxies'] = self::BY_APP;

            return;
        }

        TrustProxies::at('*');

        // Not Laravel's default of all forwarded headers: a client can send `X-Forwarded-Host` too (Cloudflare passes it on), and trusting it
        // lets a visitor choose the host of `url()`, redirects and cache keys. The app's own `withHeaders()` call wins.
        if (self::staticProperty(TrustProxies::class, 'alwaysTrustHeaders') === null) {
            TrustProxies::withHeaders(self::forwardedHeaders($app));
        }

        self::$state['proxies'] = self::APPLIED;
    }

    /**
     * Bit field of the forwarded headers to trust (`content.auto.proxy_headers`, default `for`, `proto` and `port`; `host` and `prefix`
     * are there for a site that really needs them).
     */
    private static function forwardedHeaders(Application $app): int
    {
        $map = [
            'for' => Request::HEADER_X_FORWARDED_FOR,
            'proto' => Request::HEADER_X_FORWARDED_PROTO,
            'port' => Request::HEADER_X_FORWARDED_PORT,
            'host' => Request::HEADER_X_FORWARDED_HOST,
            'prefix' => Request::HEADER_X_FORWARDED_PREFIX,
        ];
        $configured = $app['config']->get('content.auto.proxy_headers', ['for', 'proto', 'port']);
        $bits = 0;

        foreach (is_array($configured) ? $configured : [] as $name) {
            $bits |= $map[strtolower((string) $name)] ?? 0;
        }

        return $bits === 0 ? $map['for'] | $map['proto'] | $map['port'] : $bits;
    }

    /**
     * Also decides the scheme per request (global middleware): the environment can change after boot (a test that switches to production,
     * a worker whose config is reloaded), and a scheme decided only once at boot would then stay `http`.
     */
    private static function registerSchemeMiddleware(Application $app): void
    {
        $push = static function (object $kernel): void {
            if (method_exists($kernel, 'pushMiddleware') && ! (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(ForceHttps::class))) {
                $kernel->pushMiddleware(ForceHttps::class);
            }
        };

        if ($app->resolved(KernelContract::class)) {
            $push($app->make(KernelContract::class));

            return;
        }

        $app->afterResolving(KernelContract::class, $push);
    }

    /** What the {@see ForceHttps} middleware runs on every request: the boot time decision again, with the environment as it is now. */
    public static function applySchemeNow(Application $app): void
    {
        if (Auto::on('platform')) {
            self::applyScheme($app);
        }
    }

    private static function applyScheme(Application $app): void
    {
        if (! $app->environment('production')) {
            self::$state['https'] = self::SKIPPED;

            return;
        }

        /** @var UrlGenerator $url */
        $url = $app['url'];

        if (self::instanceProperty($url, 'forceScheme') !== null) {
            self::$state['https'] = self::BY_APP;

            return;
        }

        $url->forceScheme('https');
        self::$state['https'] = self::APPLIED;
    }

    private static function applyRedirects(Application $app): void
    {
        // The kernel is resolved before the providers boot on a real request, so it is normally ready here. Where it is not (console),
        // wait for it: the callback runs the moment something resolves it.
        if ($app->resolved(KernelContract::class)) {
            self::prependRedirects($app->make(KernelContract::class));

            return;
        }

        self::$state['redirects'] = self::PENDING;
        $app->afterResolving(KernelContract::class, static fn (object $kernel) => self::prependRedirects($kernel));
    }

    private static function prependRedirects(object $kernel): void
    {
        if (! method_exists($kernel, 'getMiddlewareGroups') || ! method_exists($kernel, 'prependMiddlewareToGroup')) {
            self::$state['redirects'] = self::SKIPPED;

            return;
        }

        $web = $kernel->getMiddlewareGroups()['web'] ?? null;

        if (! is_array($web)) {
            self::$state['redirects'] = self::SKIPPED;

            return;
        }

        $global = method_exists($kernel, 'getGlobalMiddleware') ? $kernel->getGlobalMiddleware() : [];

        if (in_array(RedirectMiddleware::class, $web, true) || in_array(RedirectMiddleware::class, $global, true)) {
            self::$state['redirects'] = self::BY_APP;

            return;
        }

        $kernel->prependMiddlewareToGroup('web', RedirectMiddleware::class);
        self::$state['redirects'] = self::APPLIED;
    }

    /** Laravel has no getter for these two settings, so the doctor and this class read the property. */
    private static function staticProperty(string $class, string $name): mixed
    {
        try {
            return (new ReflectionProperty($class, $name))->getValue();
        } catch (Throwable) {
            return null;
        }
    }

    private static function instanceProperty(object $object, string $name): mixed
    {
        try {
            return (new ReflectionProperty($object, $name))->getValue($object);
        } catch (Throwable) {
            return null;
        }
    }
}
