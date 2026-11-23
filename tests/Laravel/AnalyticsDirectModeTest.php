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
        // No POST route: only the automatic content route (GET) matches the path, so 405.
        self::assertSame(405, $this->post('/_fsc/a/api/send')->status());
    }
}
