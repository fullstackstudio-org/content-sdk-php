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
        // The inline block styles are printed once per request and are tested on their own (BlockStyleTest); off here keeps the other markup tests exact.
        $app['config']->set('content.blocks.inline_styles', false);
        $app['config']->set('cache.default', 'array');
        // Unknown paths reach the automatic content route in the `web` group (cookies, session), which needs a key like every real site has.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
    }
}
