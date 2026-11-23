<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Auto\AutoPlatform;
use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;

/**
 * Platform basics (plan sdk-out-of-the-box, task 8): trust proxies, https and the redirect middleware are applied only when the app did not
 * set them, only when the switch is on, and the app's own `web` group is otherwise left alone.
 */
final class AutoPlatformTest extends LaravelTestCase
{
    /** Per test: what the application looks like before the package acts (the application is built before the test method runs). */
    private const SETUP = [
        'testDoesNotTouchAnAppDefinedGroupOtherwise' => ['groups' => ['web' => ['App\\A', 'App\\B']]],
        'testAppWithTheMiddlewareAlreadyKeepsItsPlace' => ['groups' => ['web' => ['App\\A', RedirectMiddleware::class]]],
        'testNoWebGroupIsSkipped' => ['groups' => []],
        'testAppTrustProxiesWins' => ['proxies' => ['10.0.0.1']],
        'testAppTrustedProxyConfigWins' => ['config' => ['trustedproxy.proxies' => '10.0.0.2']],
        'testProductionForcesHttps' => ['env' => 'production'],
        'testAppForcedSchemeWins' => ['env' => 'production', 'scheme' => 'http'],
        'testPostRedirectIsHttpsBehindAProxyInProduction' => ['env' => 'production', 'proxies' => null],
        'testSwitchesOffApplyNothing' => ['env' => 'production', 'config' => ['content.auto.platform' => false, 'content.auto.redirects' => false]],
        'testMasterSwitchOffAppliesNothing' => ['env' => 'production', 'config' => ['content.auto' => ['enabled' => false]]],
        'testPlatformOffKeepsRedirects' => ['env' => 'production', 'config' => ['content.auto.platform' => false]],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $setup = self::SETUP[$this->name()] ?? [];
        $app['config']->set('content.auto.redirects', true);

        if (isset($setup['env'])) {
            $app['env'] = $setup['env'];
        }

        foreach ($setup['config'] ?? [] as $key => $value) {
            $app['config']->set($key, $value);
        }

        // The site's own settings: made after every provider booted but before ours acts (callbacks run in order of registration).
        $app->booted(static function ($app) use ($setup): void {
            if (isset($setup['groups'])) {
                $app->make(Kernel::class)->setMiddlewareGroups($setup['groups']);
            }

            if (isset($setup['proxies'])) {
                TrustProxies::at($setup['proxies']);
            }

            if (isset($setup['scheme'])) {
                $app['url']->forceScheme($setup['scheme']);
            }
        });

        // A real app has TrustProxies in its global stack (`bootstrap/app.php`); Testbench has an empty one.
        $app->booted(static function ($app): void {
            $app->make(Kernel::class)->prependMiddleware(TrustProxies::class);
        });
    }

    /** Re-run the package's booted callback order: `booted()` callbacks of providers run after the app is booted, so force the kernel. */
    private function web(): array
    {
        return $this->app->make(Kernel::class)->getMiddlewareGroups()['web'] ?? [];
    }

    private function trusted(): mixed
    {
        return (new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies'))->getValue();
    }

    private function scheme(): mixed
    {
        return (new ReflectionProperty($this->app['url'], 'forceScheme'))->getValue($this->app['url']);
    }

    public function testRedirectMiddlewareIsFirstInTheWebGroup(): void
    {
        $this->app->make(Kernel::class);

        $this->assertSame(RedirectMiddleware::class, $this->web()[0]);
        $this->assertSame(1, count(array_keys($this->web(), RedirectMiddleware::class, true)));
        $this->assertSame(AutoPlatform::APPLIED, AutoPlatform::state()['redirects']);
    }

    public function testDoesNotTouchAnAppDefinedGroupOtherwise(): void
    {
        $this->assertSame([RedirectMiddleware::class, 'App\\A', 'App\\B'], $this->web());
    }

    public function testAppWithTheMiddlewareAlreadyKeepsItsPlace(): void
    {
        $this->assertSame(['App\\A', RedirectMiddleware::class], $this->web());
        $this->assertSame(AutoPlatform::BY_APP, AutoPlatform::state()['redirects']);
    }

    public function testNoWebGroupIsSkipped(): void
    {
        $this->assertSame([], $this->web());
        $this->assertSame(AutoPlatform::SKIPPED, AutoPlatform::state()['redirects']);
    }

    public function testTrustsAllProxiesWhenTheAppSetNone(): void
    {
        $this->app->make(Kernel::class);

        $this->assertSame('*', $this->trusted());
        $this->assertSame(AutoPlatform::APPLIED, AutoPlatform::state()['proxies']);
    }

    public function testAppTrustProxiesWins(): void
    {
        $this->assertSame(['10.0.0.1'], $this->trusted());
        $this->assertSame(AutoPlatform::BY_APP, AutoPlatform::state()['proxies']);
    }

    public function testAppTrustedProxyConfigWins(): void
    {
        $this->assertEmpty($this->trusted());
        $this->assertSame(AutoPlatform::BY_APP, AutoPlatform::state()['proxies']);
    }

    public function testNoHttpsOutsideProduction(): void
    {
        $this->assertNull($this->scheme());
        $this->assertSame(AutoPlatform::SKIPPED, AutoPlatform::state()['https']);
    }

    public function testProductionForcesHttps(): void
    {
        $this->assertSame('https://', $this->scheme());
        $this->assertSame(AutoPlatform::APPLIED, AutoPlatform::state()['https']);
    }

    public function testAppForcedSchemeWins(): void
    {
        $this->assertSame('http://', $this->scheme());
        $this->assertSame(AutoPlatform::BY_APP, AutoPlatform::state()['https']);
    }

    public function testPostRedirectIsHttpsBehindAProxyInProduction(): void
    {
        // The pod sees plain http from the ingress; the proxy says the visitor used https.
        Route::post('/formulier', static fn () => redirect('/bedankt'));

        $response = $this->post('/formulier', [], ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'example.nl']);

        $response->assertStatus(302);
        $this->assertSame('https://example.nl/bedankt', $response->headers->get('Location'));
    }

    public function testSwitchesOffApplyNothing(): void
    {
        $this->app->make(Kernel::class);

        $this->assertEmpty($this->trusted());
        $this->assertNull($this->scheme());
        $this->assertNotContains(RedirectMiddleware::class, $this->web());
        $this->assertSame(['proxies' => 'off', 'https' => 'off', 'redirects' => 'off'], AutoPlatform::state());
    }

    public function testMasterSwitchOffAppliesNothing(): void
    {
        $this->app->make(Kernel::class);

        $this->assertEmpty($this->trusted());
        $this->assertNull($this->scheme());
        $this->assertNotContains(RedirectMiddleware::class, $this->web());
    }

    public function testPlatformOffKeepsRedirects(): void
    {
        $this->app->make(Kernel::class);

        $this->assertEmpty($this->trusted());
        $this->assertNull($this->scheme());
        $this->assertSame(RedirectMiddleware::class, $this->web()[0]);
    }
}
