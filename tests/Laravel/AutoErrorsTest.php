<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\ContentServiceProvider;
use FullStackStudio\Content\Laravel\Site\Hub;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Throwable;

/**
 * Error handling of the out of the box site (plan sdk-out-of-the-box, task 7): Hub 404 → 404, any other Hub failure → reported once + 503,
 * default 404/503 pages a site replaces with its own `errors/{status}` view, JSON and site render callbacks untouched.
 */
final class AutoErrorsTest extends LaravelTestCase
{
    private const SECRET = 'hub says fsc_srv_secrettoken is wrong at /internal/stack';

    private string $views;

    /** @var list<Throwable> */
    private array $reported = [];

    /** Config per test (the application is built before the test method runs). */
    private const CONFIG = [
        'testConfiguredLayoutAndSectionAreUsed' => ['content.auto.layout' => 'layouts.site', 'content.auto.section' => 'body', 'content.auto.wrap' => 'container mx-auto'],
        'testDebugKeepsLaravelsDebugPageForAHubFailure' => ['app.debug' => true],
        'testSwitchedOffEverythingIsLaravels' => ['content.auto.errors' => false],
    ];

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/fsc-auto-errors-' . bin2hex(random_bytes(6));
        mkdir($this->views . '/layouts', 0777, true);
        mkdir($this->views . '/errors', 0777, true);

        parent::setUp();

        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e): void {
            $this->reported[] = $e;
        });

        Route::get('hub/{status}', static function (string $status): never {
            throw match ($status) {
                'timeout' => new ContentException(0, 'timeout', self::SECRET),
                default => new ContentException((int) $status, (int) $status === 404 ? 'not_found' : 'server_error', self::SECRET),
            };
        });
        Route::get('abort/{status}', static fn (string $status) => abort((int) $status));
        Route::get('boom', static fn () => throw new RuntimeException('boom'));
        Route::get('or-abort', static fn () => Hub::orAbort(static fn () => throw new ContentException(500, 'server_error', self::SECRET)));
        Route::get('in-view', static fn () => view('page'));
        Route::get('api/hub', static fn () => throw new ContentException(500, 'server_error', self::SECRET));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->views);
    }

    protected function getPackageProviders($app): array
    {
        $site = $this->name() === 'testSiteRenderCallbackFromAProviderBootedLaterWins' ? [AutoErrorsSiteProvider::class] : [];

        return [...parent::getPackageProviders($app), ...$site];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.debug', false);
        $app['config']->set('view.paths', [$this->views]);

        if ($this->name() === 'testSiteRenderCallbackFromWithExceptionsWins') {
            // What `->withExceptions()` in bootstrap/app.php does, before any provider boots: a callback when the handler resolves.
            $app->afterResolving(ExceptionHandler::class, static function (object $handler): void {
                $handler->renderable(static fn (ContentException $e) => response('site-callback', 418));
            });
        }

        foreach (self::CONFIG[$this->name()] ?? [] as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    private function siteView(string $name, string $blade): void
    {
        file_put_contents($this->views . '/' . str_replace('.', '/', $name) . '.blade.php', $blade);
    }

    private function layout(): void
    {
        $this->siteView('layouts.app', '<html><body data-status="{{ app(\FullStackStudio\Content\Laravel\Site\PageContext::class)->status() }}" data-fsc-status="{{ $fscStatus ?? \'\' }}">@yield(\'content\')</body></html>');
    }

    /** No stack trace, no Hub message, no token. */
    private function assertClean(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        self::assertStringNotContainsString('fsc_srv_', $body);
        self::assertStringNotContainsString('hub says', $body);
        self::assertStringNotContainsString('/internal/stack', $body);
        self::assertStringNotContainsString('#0 ', $body);
    }

    private function assertUnavailable(TestResponse $response): void
    {
        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '300');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertSee('Even niet bereikbaar');
        $response->assertSee('<meta name="robots" content="noindex">', false);
        $this->assertClean($response);
    }

    public function testEscapedHubNotFoundIsA404InTheLayoutAndNotReported(): void
    {
        $this->layout();

        $response = $this->get('/hub/404');

        $response->assertNotFound();
        $response->assertSee('Pagina niet gevonden');
        $response->assertSee('data-status="404"', false);
        $response->assertSee('data-fsc-status="404"', false);
        $this->assertClean($response);
        self::assertSame([], $this->reported);
    }

    public function testEscapedHubFailureIsA503ReportedOnce(): void
    {
        $response = $this->get('/hub/500');

        $this->assertUnavailable($response);
        self::assertCount(1, $this->reported);
        self::assertInstanceOf(ContentException::class, $this->reported[0]);
    }

    public function testEscapedHubTimeoutIsA503ReportedOnce(): void
    {
        $this->assertUnavailable($this->get('/hub/timeout'));
        self::assertCount(1, $this->reported);
    }

    public function testHubFailureInsideAViewIsA503ReportedOnce(): void
    {
        $this->siteView('page', '<p>@php(throw new \FullStackStudio\Content\ContentException(502, "server_error", "' . self::SECRET . '"))</p>');

        $this->assertUnavailable($this->get('/in-view'));
        self::assertCount(1, $this->reported);
    }

    public function testHubNotFoundInsideAViewIsA404(): void
    {
        $this->siteView('page', '<p>@php(throw new \FullStackStudio\Content\ContentException(404, "not_found", "x"))</p>');

        $this->get('/in-view')->assertNotFound()->assertSee('Pagina niet gevonden');
        self::assertSame([], $this->reported);
    }

    public function testHubOrAbortReportsOnceAndRendersThe503(): void
    {
        $this->assertUnavailable($this->get('/or-abort'));
        self::assertCount(1, $this->reported);
    }

    public function testAbort404InSiteCodeUsesThePackage404(): void
    {
        $this->layout();

        $this->get('/abort/404')->assertNotFound()->assertSee('Pagina niet gevonden')->assertSee('data-status="404"', false);
        self::assertSame([], $this->reported);
    }

    public function testUnknownPathThroughTheAutomaticRouteUsesThePackage404(): void
    {
        $this->layout();

        $this->get('/bestaat-niet')->assertNotFound()->assertSee('Pagina niet gevonden');
    }

    public function testRouter404UsesThePackage404(): void
    {
        $this->layout();
        $this->app['router']->setRoutes(new RouteCollection());

        $this->get('/nergens')->assertNotFound()->assertSee('Pagina niet gevonden')->assertSee('data-status="404"', false);
    }

    public function testAbort503UsesThePackage503WithHeaders(): void
    {
        $this->assertUnavailable($this->get('/abort/503'));
        self::assertSame([], $this->reported);
    }

    public function testWithoutALayoutThe404IsStandalone(): void
    {
        $response = $this->get('/abort/404');

        $response->assertNotFound();
        $response->assertSee('Pagina niet gevonden');
        $response->assertSee('<meta name="robots" content="noindex">', false);
        $response->assertSee('Naar de homepage');
        self::assertSame([], $this->reported);
    }

    public function testALayoutThatThrowsFallsBackToTheStandalone404(): void
    {
        $this->siteView('layouts.app', '<html>@php(throw new RuntimeException("layout broke"))@yield(\'content\')</html>');

        $response = $this->get('/abort/404');

        $response->assertNotFound();
        $response->assertSee('Pagina niet gevonden');
        $response->assertDontSee('layout broke');
        self::assertStringStartsWith('<!DOCTYPE html>', ltrim((string) $response->getContent()));
        self::assertCount(1, $this->reported, 'the broken layout is reported once');
    }

    public function testALayoutThatThrowsAHubFailureFallsBackWithoutASecondReport(): void
    {
        $this->siteView('layouts.app', '<html>@php(throw new \FullStackStudio\Content\ContentException(500, "server_error", "x"))@yield(\'content\')</html>');

        $this->get('/abort/404')->assertNotFound()->assertSee('Pagina niet gevonden');
        self::assertSame([], $this->reported);
    }

    public function testConfiguredLayoutAndSectionAreUsed(): void
    {
        $this->siteView('layouts.site', '<main data-layout="site" data-status="{{ app(\FullStackStudio\Content\Laravel\Site\PageContext::class)->status() }}">@yield(\'body\')</main>');

        $this->get('/abort/404')
            ->assertNotFound()
            ->assertSee('data-layout="site"', false)
            ->assertSee('data-status="404"', false)
            ->assertSee('<div class="container mx-auto">', false)
            ->assertSee('Pagina niet gevonden');
    }

    public function testTheSitesOwnErrorViewsWinAndSeeTheStatus(): void
    {
        $this->siteView('errors.404', 'site-404 status={{ app(\FullStackStudio\Content\Laravel\Site\PageContext::class)->status() }}');
        $this->siteView('errors.503', 'site-503');

        $this->get('/hub/404')->assertNotFound()->assertSee('site-404 status=404')->assertDontSee('Pagina niet gevonden');

        $response = $this->get('/hub/500');
        $response->assertStatus(503)->assertSee('site-503')->assertHeader('Retry-After', '300');
        $this->assertClean($response);
    }

    public function testTheSitesGenericErrorViewWinsOverThePackageView(): void
    {
        $this->siteView('errors.4xx', 'site-4xx');

        $this->get('/abort/404')->assertNotFound()->assertSee('site-4xx');
    }

    public function testASiteErrorViewThatThrowsEndsInTheStandalonePage(): void
    {
        $this->siteView('errors.503', '@php(throw new RuntimeException("view broke"))');

        $response = $this->get('/hub/500');

        $this->assertUnavailable($response);
        $response->assertDontSee('view broke');
    }

    public function testSiteRenderCallbackFromWithExceptionsWins(): void
    {
        $this->get('/hub/500')->assertStatus(418)->assertSee('site-callback');
    }

    public function testSiteRenderCallbackFromAProviderBootedLaterWins(): void
    {
        $this->get('/hub/500')->assertStatus(418)->assertSee('site-provider');
        $this->get('/abort/404')->assertStatus(418)->assertSee('site-provider');
    }

    public function testJsonRequestsKeepLaravelsRendering(): void
    {
        $response = $this->getJson('/hub/500');

        $response->assertStatus(500);
        $response->assertJson(['message' => 'Server Error']);
        $this->assertClean($response);

        $this->getJson('/abort/404')->assertNotFound()->assertJsonMissing(['Pagina niet gevonden']);
        self::assertStringNotContainsString('Pagina niet gevonden', (string) $this->getJson('/abort/404')->getContent());
    }

    public function testApiPathsKeepLaravelsRendering(): void
    {
        $this->get('/api/hub')->assertStatus(500)->assertDontSee('Even niet bereikbaar');
        $this->get('/api/bestaat-niet')->assertNotFound()->assertDontSee('Pagina niet gevonden');
    }

    public function testDebugKeepsLaravelsDebugPageForAHubFailure(): void
    {
        // Laravel's debug page (it quotes source code, so only its own marks are checked).
        $this->get('/hub/500')->assertStatus(500)->assertSee('500 Internal Server Error')->assertHeaderMissing('Retry-After');
        $this->get('/hub/404')->assertNotFound()->assertSee('Pagina niet gevonden');
    }

    public function testOtherExceptionsAndStatusesAreLeftToLaravel(): void
    {
        $this->get('/boom')->assertStatus(500)->assertDontSee('Even niet bereikbaar');
        $this->get('/abort/403')->assertForbidden()->assertDontSee('Pagina niet gevonden');
        self::assertCount(1, $this->reported);
    }

    public function testSwitchedOffEverythingIsLaravels(): void
    {
        $this->get('/hub/500')->assertStatus(500)->assertDontSee('Even niet bereikbaar');
        $this->get('/abort/404')->assertNotFound()->assertDontSee('Pagina niet gevonden');
        $this->get('/hub/404')->assertStatus(500);
        self::assertCount(2, $this->reported, 'without the feature a Hub 404 is an ordinary exception');
    }

    public function testPublishTags(): void
    {
        foreach ([404, 503] as $status) {
            $paths = ServiceProvider::pathsToPublish(ContentServiceProvider::class, 'content-errors-' . $status);

            self::assertCount(1, $paths);
            self::assertStringEndsWith('resources/views/errors/' . $status . '.blade.php', (string) array_key_first($paths));
            self::assertFileExists((string) array_key_first($paths));
            self::assertSame($this->app->resourcePath('views/errors/' . $status . '.blade.php'), reset($paths));
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}

/** A site provider booted after the package that registers its own render callback in `boot()`. */
final class AutoErrorsSiteProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ExceptionHandler::class)->renderable(static fn (Throwable $e) => in_array(request()->path(), ['hub/500', 'abort/404'], true) ? response('site-provider', 418) : null);
    }
}
