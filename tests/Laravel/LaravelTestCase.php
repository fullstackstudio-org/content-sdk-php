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
        // The Hub redirect lookup in the `web` group (AutoPlatform) would eat queued fake Hub answers of tests that render a page; AutoPlatformTest switches it on.
        $app['config']->set('content.auto.redirects', false);
        // Unknown paths reach the automatic content route in the `web` group (cookies, session), which needs a key like every real site has.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
        // The visitor page proxy never reaches a real Hub from a test: VisitorProxyTest binds its own fake upstream.
        $app->instance(\FullStackStudio\Content\Laravel\Visitor\Upstream::class, new \FullStackStudio\Content\Tests\Support\NoNetworkUpstream());
    }
}
