<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

/** In direct mode only the helper route exists; the proxy routes are not registered. */
final class AnalyticsDirectModeTest extends LaravelTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.analytics.mode', 'direct');
    }

    public function testOnlyTheHelperRouteExists(): void
    {
        $this->get('/_fsc/a/fsc.js')->assertOk();
        $this->get('/_fsc/a/script.js')->assertNotFound();
        // No POST route: the automatic 404 fallback for other methods answers, as Laravel does without the package.
        self::assertSame(404, $this->post('/_fsc/a/api/send')->status());
    }
}
