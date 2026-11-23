<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use Illuminate\Support\Facades\Route;

/**
 * The global `ContentResponseHeaders` middleware: preconnect and SDK version on successful HTML pages only.
 */
final class ContentResponseHeadersTest extends LaravelTestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/page', static fn () => response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        Route::get('/page-link', static fn () => response('<html></html>', 200, ['Content-Type' => 'text/html', 'Link' => '</app.css>; rel=preload; as=style']));
        Route::get('/data', static fn () => response()->json(['ok' => true]));
        Route::get('/gone', static fn () => redirect('/page'));
        Route::get('/broken', static fn () => response('<html></html>', 500, ['Content-Type' => 'text/html']));
        Route::get('/_fsc/x', static fn () => response('<html></html>', 200, ['Content-Type' => 'text/html']));
    }

    public function testBothHeadersAreOnAnHtmlPage(): void
    {
        $response = $this->get('/page')->assertOk();

        self::assertSame('<https://content.fullstackstudio.nl>; rel=preconnect', $response->headers->get('Link'));
        self::assertSame(ContentClient::VERSION, $response->headers->get('X-FSC-SDK'));
    }

    public function testNotOnJsonRedirectErrorOrFscRoutes(): void
    {
        foreach (['/data', '/gone', '/broken', '/_fsc/x'] as $path) {
            $response = $this->get($path);
            self::assertNull($response->headers->get('Link'), $path);
            self::assertNull($response->headers->get('X-FSC-SDK'), $path);
        }
    }

    public function testNotOnTheWebhook(): void
    {
        $response = $this->post('/content/webhook', [], ['Content-Type' => 'application/json']);

        self::assertNull($response->headers->get('Link'));
        self::assertNull($response->headers->get('X-FSC-SDK'));
    }

    public function testAnExistingLinkHeaderIsKept(): void
    {
        $link = (string) $this->get('/page-link')->assertOk()->headers->get('Link');

        self::assertSame('</app.css>; rel=preload; as=style, <https://content.fullstackstudio.nl>; rel=preconnect', $link);
    }

    public function testThePreconnectSwitchTurnsOnlyThatHeaderOff(): void
    {
        config(['content.response_headers.preconnect' => false]);
        $response = $this->get('/page');

        self::assertNull($response->headers->get('Link'));
        self::assertSame(ContentClient::VERSION, $response->headers->get('X-FSC-SDK'));
    }

    public function testTheVersionSwitchTurnsOnlyThatHeaderOff(): void
    {
        config(['content.response_headers.version' => false]);
        $response = $this->get('/page');

        self::assertNull($response->headers->get('X-FSC-SDK'));
        self::assertSame('<https://content.fullstackstudio.nl>; rel=preconnect', $response->headers->get('Link'));
    }

    public function testTheOriginDropsPathQueryAndCredentialsButKeepsThePort(): void
    {
        config(['content.base_url' => 'https://Content.Example.test:8443/api/v1/?x=1']);

        self::assertSame('<https://content.example.test:8443>; rel=preconnect', $this->get('/page')->headers->get('Link'));

        config(['content.base_url' => 'http://user:pw@localhost:3000/hub']);

        self::assertSame('<http://localhost:3000>; rel=preconnect', $this->get('/page')->headers->get('Link'));
    }

    public function testAnUnreadableApiUrlAddsNoPreconnect(): void
    {
        config(['content.base_url' => 'not a url']);

        self::assertNull($this->get('/page')->headers->get('Link'));
    }
}
