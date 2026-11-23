<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Auto;

use FullStackStudio\Content\Laravel\RedirectMiddleware;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Middleware\TrustProxies;
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

    /** Called once from the service provider's `boot()`. */
    public static function register(Application $app): void
    {
        self::$state = ['proxies' => self::OFF, 'https' => self::OFF, 'redirects' => self::OFF];

        $app->booted(static function (Application $app): void {
            if (Auto::on('platform')) {
                self::applyProxies($app);
                self::applyScheme($app);
            }

            if (Auto::on('redirects')) {
                self::applyRedirects($app);
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

    private static function applyProxies(Application $app): void
    {
        $set = self::staticProperty(TrustProxies::class, 'alwaysTrustProxies');
        $config = $app['config']->get('trustedproxy.proxies');

        if (! empty($set) || ! empty($config)) {
            self::$state['proxies'] = self::BY_APP;

            return;
        }

        TrustProxies::at('*');
        self::$state['proxies'] = self::APPLIED;
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

        if (in_array(RedirectMiddleware::class, $web, true)) {
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
