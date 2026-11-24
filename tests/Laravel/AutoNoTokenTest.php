<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Auto\AutoRoutes;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use Orchestra\Testbench\TestCase;

/** A site without Hub content (pages, articles and legal off) and without a token: unknown paths are a plain 404, never a 503. */
final class AutoNoTokenTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ContentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('content.token', null);
        $app['config']->set('content.auto.enabled', true);
        $app['config']->set('content.auto.pages', false);
        $app['config']->set('content.auto.articles', false);
        $app['config']->set('content.auto.legal', false);
        $app['config']->set('content.auto.redirects', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
    }

    public function testAnUnknownPathIs404WithoutAToken(): void
    {
        $this->get('/unknown')->assertNotFound();
        $this->get('/unknown/deeper')->assertNotFound();
    }

    public function testTheContentClientIsNeverBuilt(): void
    {
        $this->get('/unknown')->assertNotFound();

        self::assertNotNull($this->app['router']->getRoutes()->getByName(AutoRoutes::PAGE));
        self::assertFalse($this->app->resolved(ContentClient::class));
    }
}
