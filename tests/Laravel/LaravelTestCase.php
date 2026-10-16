<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\ContentServiceProvider;
use Orchestra\Testbench\TestCase;

abstract class LaravelTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ContentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('content.token', 'fsc_srv_laraveltoken');
        $app['config']->set('content.webhook.secret', 'fsc_whsec_testvector');
        $app['config']->set('content.webhook.enabled', true);
        $app['config']->set('cache.default', 'array');
    }
}
