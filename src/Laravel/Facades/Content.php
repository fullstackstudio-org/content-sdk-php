<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Facades;

use FullStackStudio\Content\Analytics;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\AnalyticsSettings;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use Illuminate\Support\Facades\Facade;

/**
 * `Content::getArticle('hello')`, `Content::admin()`... forwards to the {@see ContentClient} in the container.
 *
 * @mixin ContentClient
 */
final class Content extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContentClient::class;
    }

    /** Registers the form route (`POST content/forms/{key}`) behind `<x-content::form>`. */
    public static function formRoutes(): void
    {
        ContentServiceProvider::registerFormRoute();
    }

    /** Registers the webhook route (`POST content/webhook`). Put it in `routes/api.php` or any routes file. */
    public static function routes(): void
    {
        ContentServiceProvider::registerWebhookRoute();
    }

    /**
     * Content-Security-Policy sources for analytics in the configured mode (first-party: 'self' only), per directive. Merge them into your
     * policy, or use `analyticsCspMerge()` on an existing policy string.
     *
     * @return array<string, list<string>>
     */
    public static function analyticsCsp(): array
    {
        $settings = AnalyticsSettings::make();

        return Analytics::csp($settings->mode(), $settings->host());
    }

    /** Adds the analytics sources to an existing CSP string (missing directives are added). */
    public static function analyticsCspMerge(string $policy): string
    {
        $settings = AnalyticsSettings::make();

        return Analytics::mergeCsp($policy, $settings->mode(), $settings->host());
    }
}
