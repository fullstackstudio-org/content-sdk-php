<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Cache\ContentCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Events\ContentWebhookReceived;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Laravel\Sitemap;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Webhooks\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;

final class ProviderTest extends LaravelTestCase
{
    private FakeTransport $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        // Swap the client for one that uses the container's cache but a fake transport.
        $this->app->singleton(ContentClient::class, fn ($app) => new ContentClient([
            'token' => 'fsc_srv_laraveltoken',
            'transport' => $this->http,
            'cache' => $app->make(ContentCache::class),
        ]));
    }

    private function signedPost(string $body, ?string $signature = null)
    {
        return $this->call('POST', '/content/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FSC_SIGNATURE' => $signature ?? WebhookVerifier::sign('fsc_whsec_testvector', $body)], $body);
    }

    public function testProviderLoadsAndBindsTheClientAndFacade(): void
    {
        $this->app->forgetInstance(ContentClient::class);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'transport' => $this->http]));

        self::assertInstanceOf(ContentClient::class, app(ContentClient::class));
        self::assertSame('fsc_srv_laraveltoken', config('content.token'));
        self::assertSame(3600, config('content.cache.ttl'));

        $this->http->always(FakeTransport::json(['article' => ['slug' => 'hi']]));
        self::assertSame(['slug' => 'hi'], Content::getArticle('hi'));
    }

    public function testDefaultBindingBuildsAClientFromConfig(): void
    {
        $this->app->forgetInstance(ContentClient::class);
        $this->app->offsetUnset(ContentClient::class);
        $this->app->register(\FullStackStudio\Content\Laravel\ContentServiceProvider::class, true);

        $client = app(ContentClient::class);
        self::assertSame('https://content.fullstackstudio.nl', $client->baseUrl());
        self::assertSame($client, app('content'));
    }

    public function testWebhookRouteInvalidatesTagsAndDispatchesTheEvent(): void
    {
        Event::fake([ContentWebhookReceived::class]);
        $this->http->always(FakeTransport::json(['article' => ['slug' => 'hallo', 'v' => 1]]));

        self::assertSame(1, Content::getArticle('hallo')['v']);
        $this->http->always(FakeTransport::json(['article' => ['slug' => 'hallo', 'v' => 2]]));
        self::assertSame(1, Content::getArticle('hallo')['v'], 'served from cache');

        $body = '{"id":"7c0b1c6e-0a63-4d7f-9a6b-3b3f0c1c9a11","type":"article.updated","createdAt":"2026-10-03T10:15:00.000Z","projectId":"b1f0c2d4-0000-4000-8000-000000000001","data":{"slug":"hallo","locale":"nl"}}';
        $response = $this->signedPost($body);

        $response->assertOk();
        $response->assertJsonPath('received', true);
        $response->assertJsonPath('event', 'article.updated');
        self::assertContains('content:article:hallo', $response->json('revalidated'));
        Event::assertDispatched(ContentWebhookReceived::class, fn (ContentWebhookReceived $e) => $e->event['type'] === 'article.updated' && in_array('content:articles', $e->tags, true));

        self::assertSame(2, Content::getArticle('hallo')['v'], 'cache was invalidated');
    }

    public function testWebhookRouteRejectsABadSignatureWith401(): void
    {
        Event::fake([ContentWebhookReceived::class]);
        $body = '{"id":"a","type":"article.updated","createdAt":"c","projectId":"p","data":{}}';

        $this->signedPost($body, 't=' . time() . ',v1=' . str_repeat('0', 64))->assertStatus(401);
        $this->call('POST', '/content/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        Event::assertNotDispatched(ContentWebhookReceived::class);
    }

    public function testWebhookRouteAnswers200ForEventsThatInvalidateNothingAndForNonEvents(): void
    {
        Event::fake([ContentWebhookReceived::class]);
        $test = '{"id":"a","type":"webhook.test","createdAt":"c","projectId":"p","data":{}}';
        $this->signedPost($test)->assertOk()->assertJsonPath('revalidated', []);
        Event::assertDispatched(ContentWebhookReceived::class);

        $this->signedPost('{}')->assertOk()->assertJsonPath('received', true);
    }

    public function testWebhookRouteWithoutSecretIsA500(): void
    {
        config(['content.webhook.secret' => '']);
        $this->signedPost('{}')->assertStatus(500);
    }

    public function testWebhookRouteIsOptIn(): void
    {
        // Registering through the facade works without the config flag.
        Content::routes();
        self::assertTrue(app('router')->has('content.webhook'));
    }

    public function testRedirectMiddleware(): void
    {
        $this->http->queue(FakeTransport::json(['redirect' => ['location' => '/nieuw', 'statusCode' => 301]]));
        $middleware = app(RedirectMiddleware::class);
        $next = fn () => response('next');

        $response = $middleware->handle(Request::create('/oud?utm=1'), $next);
        self::assertSame(301, $response->getStatusCode());
        self::assertStringEndsWith('/nieuw?utm=1', $response->headers->get('Location'));

        $before = count($this->http->requests);
        self::assertSame('next', $middleware->handle(Request::create('/oud', 'POST'), $next)->getContent());
        self::assertSame('next', $middleware->handle(Request::create('/storage/a.png'), $next)->getContent());
        self::assertSame('next', $middleware->handle(Request::create('/build/app.js'), $next)->getContent());
        self::assertCount($before, $this->http->requests);
    }

    public function testSitemapResponse(): void
    {
        $this->http->always(FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => 'https://www.acme.nl', 'entries' => [['path' => '/about', 'locale' => 'nl', 'lastmod' => '2026-10-01']]]));
        $response = Sitemap::response();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        self::assertStringContainsString('<loc>https://www.acme.nl/about</loc>', $response->getContent());
    }

    public function testMetaBladeComponent(): void
    {
        $this->http->always(FakeTransport::json(['seo' => ['siteName' => 'Acme', 'canonicalOrigin' => 'https://www.acme.nl', 'defaultDescription' => 'Default']]));
        $html = Blade::render('<x-content::meta :page="$page" path="/blog/x" type="article" />', ['page' => ['title' => 'Hello <b>', 'excerpt' => 'Short']]);

        self::assertStringContainsString('<title>Hello &lt;b&gt;</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.acme.nl/blog/x">', $html);
        self::assertStringContainsString('content="article"', $html);
    }

    public function testMetaComponentSurvivesAnOutage(): void
    {
        $this->http->queue(new \FullStackStudio\Content\Http\TransportException('down'));
        $html = Blade::render('<x-content::meta :page="$page" />', ['page' => ['title' => 'Still here']]);

        self::assertStringContainsString('<title>Still here</title>', $html);
    }
}
