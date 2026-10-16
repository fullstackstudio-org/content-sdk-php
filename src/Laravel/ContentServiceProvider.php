<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\Cache\LaravelStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ClientOptions;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\View\Components\Meta;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'content');
        Blade::componentNamespace('FullStackStudio\\Content\\Laravel\\View\\Components', 'content');

        $this->publishes([__DIR__ . '/../../config/content.php' => $this->app->configPath('content.php')], 'content-config');

        $this->publishes([__DIR__ . '/../../resources/assets/content.css' => $this->app->publicPath('vendor/content/content.css')], 'content-assets');
        $this->publishes([__DIR__ . '/../../resources/views' => $this->app->resourcePath('views/vendor/content')], 'content-views');

        if ($this->app['config']->get('content.webhook.enabled')) {
            self::registerWebhookRoute();
        }

        if ($this->app['config']->get('content.forms.enabled')) {
            self::registerFormRoute();
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
