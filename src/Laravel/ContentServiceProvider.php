<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\Cache\LaravelStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ClientOptions;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Console\AgentsCommand;
use FullStackStudio\Content\Laravel\Errors\ErrorReporter;
use FullStackStudio\Content\Laravel\Errors\Errors;
use FullStackStudio\Content\Laravel\Errors\ReporterScriptController;
use FullStackStudio\Content\Laravel\Errors\TunnelController;
use FullStackStudio\Content\Laravel\View\Components\Meta;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/** Auto-discovered (composer `extra.laravel`). Publish the config with `php artisan vendor:publish --tag=content-config`. */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/content.php', 'content');

        $this->app->singleton(ContentCache::class, function ($app): ContentCache {
            $store = $app['config']->get('content.cache.store');

            return new VersionedTagCache(new LaravelStore($app['cache']->store($store ?: null)));
        });

        $this->app->singleton(ContentClient::class, function ($app): ContentClient {
            $config = $app['config'];

            return new ContentClient(new ClientOptions(
                token: (string) $config->get('content.token'),
                baseUrl: (string) $config->get('content.base_url'),
                timeoutMs: (int) $config->get('content.timeout_ms'),
                clientIp: $app->bound('request') ? $app['request']->ip() : null,
                cache: $config->get('content.cache.enabled') ? $app->make(ContentCache::class) : null,
                cacheTtl: (int) $config->get('content.cache.ttl'),
            ));
        });

        $this->app->alias(ContentClient::class, 'content');

        // One per request: head, body-end and meta share a single lookup (and a single failure).
        $this->app->scoped(SiteSettings::class, function ($app): SiteSettings {
            $store = $app['config']->get('content.cache.store');

            return new SiteSettings($app->make(ContentClient::class), $app['cache']->store($store ?: null), $app['config'], $app->bound('log') ? $app['log'] : null);
        });

        $this->app->singleton(Errors::class, fn ($app): Errors => new Errors($app));
        $this->app->singleton(ErrorReporter::class, fn ($app): ErrorReporter => new ErrorReporter($app, $app->make(Errors::class)));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'content');
        Blade::componentNamespace('FullStackStudio\\Content\\Laravel\\View\\Components', 'content');

        $this->publishes([__DIR__ . '/../../config/content.php' => $this->app->configPath('content.php')], 'content-config');

        $this->publishes([__DIR__ . '/../../resources/assets/content.css' => $this->app->publicPath('vendor/content/content.css')], 'content-assets');
        $this->publishes([__DIR__ . '/../../resources/views' => $this->app->resourcePath('views/vendor/content')], 'content-views');

        if ($this->app->runningInConsole()) {
            $this->commands([AgentsCommand::class]);
        }

        if ($this->app['config']->get('content.webhook.enabled')) {
            self::registerWebhookRoute();
        }

        if ($this->app['config']->get('content.forms.enabled')) {
            self::registerFormRoute();
        }

        if ($this->app['config']->get('content.analytics.enabled', true)) {
            self::registerAnalyticsRoutes();
        }

        if ($this->app['config']->get('content.address.enabled', true)) {
            self::registerAddressRoutes();
        }

        if ($this->app['config']->get('content.errors.enabled', true)) {
            self::registerErrorRoutes();
            $this->callAfterResolving(ExceptionHandler::class, function (object $handler): void {
                // `reportable` exists on Laravel's own handler; a callback that returns nothing never stops the normal logging.
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(function (Throwable $error): void {
                        $this->app->make(ErrorReporter::class)->report($error);
                    });
                }
            });
        }
    }

    /**
     * The analytics routes under `content.analytics.path` (default `_fsc/a`): the helper script always, the first-party proxy (script.js,
     * recorder.js and the collect calls to Umami) unless `content.analytics.mode` is `direct`. No middleware group: no session, cookies or
     * CSRF on these routes.
     */
    public static function registerAnalyticsRoutes(): void
    {
        $config = app('config');
        $prefix = trim((string) $config->get('content.analytics.path', '_fsc/a'), '/') ?: '_fsc/a';

        Route::get($prefix . '/fsc.js', [AnalyticsController::class, 'helper'])->name('content.analytics.helper');

        if ($config->get('content.analytics.mode') === 'direct') {
            return;
        }

        Route::get($prefix . '/script.js', [AnalyticsController::class, 'script'])->name('content.analytics.script');
        Route::get($prefix . '/recorder.js', [AnalyticsController::class, 'recorder'])->name('content.analytics.recorder');
        Route::post($prefix . '/api/send', [AnalyticsController::class, 'send'])->name('content.analytics.send');
        Route::post($prefix . '/api/record', [AnalyticsController::class, 'record'])->name('content.analytics.record');
        Route::get($prefix . '/api/websites/{id}/recorder', [AnalyticsController::class, 'recorderConfig'])
            ->where('id', '[0-9a-f-]{36}')
            ->name('content.analytics.recorder-config');
    }

    /**
     * The address routes under `content.address.path` (default `_fsc/address`): `GET suggest`, `GET lookup`, `GET field.js` and `GET field.css`, the target of
     * `<x-content::address-field>`. No middleware group: no session, cookies or CSRF. Called for you when `content.address.enabled` is on (the default).
     */
    public static function registerAddressRoutes(): void
    {
        $prefix = trim((string) app('config')->get('content.address.path', '_fsc/address'), '/') ?: '_fsc/address';

        Route::get($prefix . '/suggest', [AddressController::class, 'suggest'])->name('content.address.suggest');
        Route::get($prefix . '/lookup', [AddressController::class, 'lookup'])->name('content.address.lookup');
        Route::get($prefix . '/field.js', [AddressController::class, 'script'])->name('content.address.script');
        Route::get($prefix . '/field.css', [AddressController::class, 'styles'])->name('content.address.styles');
    }

    /**
     * Registers the error-monitoring routes on the site's own domain: `GET {path}/reporter.js` (the browser reporter) and
     * `POST {path}/envelope` (the tunnel to GlitchTip, `content.errors.tunnel.enabled`). No middleware group: no session, cookies or CSRF.
     * Called for you when `content.errors.enabled` is on (the default).
     */
    public static function registerErrorRoutes(): void
    {
        $config = app('config');
        $base = trim((string) $config->get('content.errors.tunnel.path', '_fsc/e'), '/');

        Route::get($base . '/reporter.js', ReporterScriptController::class)->name('content.errors.reporter');

        if ($config->get('content.errors.tunnel.enabled', true)) {
            Route::post($base . '/envelope', TunnelController::class)->name('content.errors.tunnel');
        }
    }

    /** Registers `POST {content.forms.path}/{key}` (route name `content.form`), the target of `<x-content::form>`. Called for you when `content.forms.enabled` is on, or via `Content::formRoutes()`. */
    public static function registerFormRoute(): void
    {
        $config = app('config');

        Route::middleware($config->get('content.forms.middleware', ['web']))
            ->post(trim((string) $config->get('content.forms.path', 'content/forms'), '/') . '/{key}', FormController::class)
            ->where('key', '[A-Za-z0-9_-]+')
            ->name('content.form');
    }

    /** Registers `POST {content.webhook.path}` (route name `content.webhook`). Called for you when `content.webhook.enabled` is on, or via `Content::routes()`. */
    public static function registerWebhookRoute(): void
    {
        $config = app('config');

        Route::middleware($config->get('content.webhook.middleware', ['api']))
            ->post((string) $config->get('content.webhook.path', 'content/webhook'), WebhookController::class)
            ->name('content.webhook');
    }
}
