<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Facades;

use FullStackStudio\Content\ContentClient;
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
}
