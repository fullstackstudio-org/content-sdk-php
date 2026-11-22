<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\Preview\PreviewRequest;
use Illuminate\Support\Facades\Route;

/** `FSC_PREVIEW_ENABLED=false`, or no server token: no preview route, no middleware, a POST to the path is not a preview. */
final class PreviewOffTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.preview.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/over-ons', static fn () => '<html><head></head><body>live</body></html>');
    }

    public function testTheKillSwitchRemovesThePreview(): void
    {
        self::assertFalse(PreviewRequest::enabled());
        self::assertFalse(Route::has('content.preview.script'));
        $this->get('/_fsc/p/preview.js')->assertNotFound();
        self::assertNotSame(200, $this->post('/_fsc/p', ['token' => 'fscpv_' . str_repeat('A', 43)])->getStatusCode());
    }

    public function testAPubTokenNeverEnablesThePreview(): void
    {
        config(['content.preview.enabled' => true, 'content.token' => 'fsc_pub_x']);

        self::assertFalse(PreviewRequest::enabled());
    }
}
