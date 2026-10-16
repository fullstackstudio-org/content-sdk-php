<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\ClientOptions;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const SERVER = 'fsc_srv_secrettoken123';
    private const PUB = 'fsc_pub_publictoken123';

    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
    }

    private function client(string $token = self::SERVER, array $extra = []): ContentClient
    {
        return new ContentClient(['token' => $token, 'transport' => $this->http, ...$extra]);
    }

    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>}> */
    public static function readCalls(): iterable
    {
        yield 'project' => [fn (ContentClient $c) => $c->getProject(), 'GET', '/project', []];
        yield 'articles' => [fn (ContentClient $c) => $c->listArticles(['status' => 'published', 'category' => 'news', 'locale' => 'nl', 'fallback' => true, 'q' => 'hallo wereld', 'page' => 2, 'limit' => 5]), 'GET', '/articles', ['status' => 'published', 'category' => 'news', 'locale' => 'nl', 'fallback' => 'true', 'q' => 'hallo wereld', 'page' => '2', 'limit' => '5']];
        yield 'article' => [fn (ContentClient $c) => $c->getArticle('hello world', ['locale' => 'en']), 'GET', '/articles/hello%20world', ['locale' => 'en']];
        yield 'categories' => [fn (ContentClient $c) => $c->listCategories(['locale' => 'en', 'fallback' => true]), 'GET', '/categories', ['locale' => 'en', 'fallback' => 'true']];
        yield 'translations' => [fn (ContentClient $c) => $c->getTranslations('group-1'), 'GET', '/translations/group-1', []];
        yield 'pages' => [fn (ContentClient $c) => $c->listPages(['locale' => 'nl', 'limit' => 10]), 'GET', '/pages', ['locale' => 'nl', 'limit' => '10']];
        yield 'page by id' => [fn (ContentClient $c) => $c->getPage('11111111-1111-4111-8111-111111111111', ['locale' => 'nl']), 'GET', '/pages/11111111-1111-4111-8111-111111111111', ['locale' => 'nl']];
        yield 'page by path' => [fn (ContentClient $c) => $c->getPage('/Services/Web-Design/'), 'GET', '/pages', ['path' => '/services/web-design']];
        yield 'form' => [fn (ContentClient $c) => $c->getForm('contact'), 'GET', '/forms/contact', []];
        yield 'media list' => [fn (ContentClient $c) => $c->listMedia(['q' => 'logo']), 'GET', '/media', ['q' => 'logo']];
        yield 'media' => [fn (ContentClient $c) => $c->getMedia('m1'), 'GET', '/media/m1', []];
        yield 'redirects' => [fn (ContentClient $c) => $c->listRedirects(), 'GET', '/redirects', []];
        yield 'resolve redirect' => [fn (ContentClient $c) => $c->resolveRedirect('/old'), 'GET', '/redirects/resolve', ['path' => '/old']];
        yield 'seo' => [fn (ContentClient $c) => $c->getSeo(), 'GET', '/seo', []];
        yield 'sitemap' => [fn (ContentClient $c) => $c->getSitemap(['locale' => 'en']), 'GET', '/sitemap', ['locale' => 'en']];
        yield 'search' => [fn (ContentClient $c) => $c->search(['q' => 'sch', 'kind' => ['article', 'page'], 'limit' => 3, 'offset' => 6]), 'GET', '/search', ['q' => 'sch', 'kind' => 'article,page', 'limit' => '3', 'offset' => '6']];
        yield 'consent config' => [fn (ContentClient $c) => $c->getConsentConfig(), 'GET', '/consent', []];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query */
    #[DataProvider('readCalls')]
    public function testReadCallsUseTheRightMethodPathAndQuery(callable $call, string $method, string $path, array $query): void
    {
        $call($this->client());

        self::assertSame($method, $this->http->last()->method);
        self::assertSame($path, $this->http->lastPath());
        self::assertSame($query, $this->http->lastQuery());
        self::assertSame('Bearer ' . self::SERVER, $this->http->last()->headers['Authorization']);
        self::assertSame('application/json', $this->http->last()->headers['Accept']);
        self::assertNull($this->http->last()->body);
        self::assertStringStartsWith('https://content.fullstackstudio.nl/api/v1', $this->http->last()->url);
    }

    public function testSingleObjectsAreUnwrapped(): void
    {
        $this->http->always(FakeTransport::json(['article' => ['id' => 'a1', 'slug' => 'hello']]));
        self::assertSame(['id' => 'a1', 'slug' => 'hello'], $this->client()->getArticle('hello'));

        $this->http->always(FakeTransport::json(['page' => ['id' => 'p1']]));
        self::assertSame(['id' => 'p1'], $this->client()->getPage('about'));

        $this->http->always(FakeTransport::json(['categories' => [['id' => 'c1']]]));
        self::assertSame([['id' => 'c1']], $this->client()->listCategories());

        $this->http->always(FakeTransport::json(['form' => ['key' => 'contact']]));
        self::assertSame(['key' => 'contact'], $this->client()->getForm('contact'));

        $this->http->always(FakeTransport::json(['seo' => ['siteName' => 'X']]));
        self::assertSame(['siteName' => 'X'], $this->client()->getSeo());

        $this->http->always(FakeTransport::json(['redirect' => ['location' => '/new', 'statusCode' => 301]]));
        self::assertSame(['location' => '/new', 'statusCode' => 301], $this->client()->resolveRedirect('/old'));

        $this->http->always(FakeTransport::json(['redirect' => null]));
        self::assertNull($this->client()->resolveRedirect('/none'));
    }

    public function testListsKeepTheirShapeAndTotalComesFromBodyOrHeader(): void
    {
        $this->http->always(FakeTransport::json(['articles' => [], 'page' => 1, 'limit' => 20, 'total' => 7], 200, ['X-Total-Count' => '99']));
        self::assertSame(7, $this->client()->listArticles()['total']);

        $this->http->always(FakeTransport::json(['articles' => [['id' => 'a']], 'page' => 1, 'limit' => 20], 200, ['X-Total-Count' => '42']));
        $list = $this->client()->listArticles();
        self::assertSame(42, $list['total']);
        self::assertSame('a', $list['articles'][0]['id']);

        $this->http->always(FakeTransport::json(['pages' => []]));
        self::assertArrayNotHasKey('total', $this->client()->listPages());
    }

    public function testMediaAndAssetUrls(): void
    {
        $c = $this->client();

        self::assertSame('https://content.fullstackstudio.nl/api/media/a%2Fb', $c->mediaUrl('a/b'));
        self::assertSame('https://content.fullstackstudio.nl/api/media/x', $c->assetUrl('/api/media/x'));
        self::assertSame('https://content.fullstackstudio.nl/api/media/x', $c->assetUrl('api/media/x'));
        self::assertSame('https://cdn.example.com/a.png', $c->assetUrl('https://cdn.example.com/a.png'));
        self::assertCount(0, $this->http->requests);
    }

    public function testSubmitFormDropsNullsAddsMetaAndTurnstileAndForwardsIpOnlyWithServerToken(): void
    {
        $this->http->always(FakeTransport::json(['submission' => ['id' => 's1']], 201));
        $result = $this->client()->submitForm('contact', ['name' => 'Jan', 'phone' => null, 'tags' => ['a', 'b'], 'website' => ''], ['meta' => ['page' => '/contact'], 'turnstileToken' => 'tt', 'clientIp' => '203.0.113.9']);

        self::assertSame(['submission' => ['id' => 's1']], $result);
        self::assertSame('POST', $this->http->last()->method);
        self::assertSame('/forms/contact/submissions', $this->http->lastPath());
        self::assertSame(['name' => 'Jan', 'tags' => ['a', 'b'], 'website' => '', '_meta' => ['page' => '/contact'], '_turnstile' => 'tt'], $this->http->lastBody());
        self::assertSame('application/json', $this->http->last()->headers['Content-Type']);
        self::assertSame('203.0.113.9', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client(self::PUB)->submitForm('contact', ['name' => 'Jan'], ['clientIp' => '203.0.113.9']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);
    }

    public function testClientIpFromOptionsAndInvalidValuesAreIgnored(): void
    {
        $this->client(self::SERVER, ['clientIp' => '198.51.100.7'])->submitForm('f', ['a' => 'b']);
        self::assertSame('198.51.100.7', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client(self::SERVER, ['clientIp' => '198.51.100.7'])->submitForm('f', ['a' => 'b'], ['clientIp' => 'evil\r\nHeader: x']);
        self::assertSame('198.51.100.7', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client()->submitForm('f', ['a' => 'b'], ['clientIp' => '2001:db8::1']);
        self::assertSame('2001:db8::1', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client()->submitForm('f', ['a' => 'b'], ['clientIp' => 'nope!']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);
    }

    public function testRestartFormPaymentAndSearchAndNewsletterAndConsentHeaders(): void
    {
        $this->http->always(FakeTransport::json(['payment' => ['checkoutUrl' => 'https://pay']]));
        self::assertSame(['checkoutUrl' => 'https://pay'], $this->client()->restartFormPayment('shop', 'sub 1'));
        self::assertSame('/forms/shop/submissions/sub%201/payment', $this->http->lastPath());
        self::assertSame('POST', $this->http->last()->method);

        $this->client()->search(['q' => 'abc'], ['clientIp' => '10.0.0.1']);
        self::assertSame('10.0.0.1', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->http->always(FakeTransport::json(['accepted' => true]));
        $out = $this->client()->newsletter->subscribe(['email' => 'a@b.nl', 'name' => null, 'listId' => 'l1'], ['clientIp' => '10.0.0.2']);
        self::assertSame(['accepted' => true], $out);
        self::assertSame('/newsletter/subscribe', $this->http->lastPath());
        self::assertSame(['email' => 'a@b.nl', 'listId' => 'l1'], $this->http->lastBody());
        self::assertSame('10.0.0.2', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client()->newsletter->confirm('tok');
        self::assertSame('/newsletter/confirm', $this->http->lastPath());
        self::assertSame(['token' => 'tok'], $this->http->lastBody());
        $this->client()->newsletter->unsubscribe('tok2');
        self::assertSame('/newsletter/unsubscribe', $this->http->lastPath());
        self::assertSame(['token' => 'tok2'], $this->http->lastBody());

        $this->client()->recordConsent(['version' => 1], ['clientIp' => '10.0.0.3', 'userAgent' => str_repeat('u', 300)]);
        self::assertSame('/consent', $this->http->lastPath());
        self::assertSame(200, strlen($this->http->last()->headers['X-FSC-Client-User-Agent']));
        self::assertSame('10.0.0.3', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->client(self::PUB)->recordConsent(['version' => 1], ['userAgent' => 'x', 'clientIp' => '10.0.0.3']);
        self::assertArrayNotHasKey('X-FSC-Client-User-Agent', $this->http->last()->headers);
    }

    /** @return iterable<string, array{0: callable(\FullStackStudio\Content\Modules\Admin): mixed, 1: string, 2: string}> */
    public static function adminCalls(): iterable
    {
        yield 'createArticle' => [fn ($a) => $a->createArticle(['title' => 'T']), 'POST', '/articles'];
        yield 'updateArticle' => [fn ($a) => $a->updateArticle('a b', ['title' => 'T']), 'PATCH', '/articles/a%20b'];
        yield 'publishArticle' => [fn ($a) => $a->publishArticle('a1'), 'POST', '/articles/a1/publish'];
        yield 'submitArticleForReview' => [fn ($a) => $a->submitArticleForReview('a1'), 'POST', '/articles/a1/submit-for-review'];
        yield 'unpublishArticle' => [fn ($a) => $a->unpublishArticle('a1'), 'POST', '/articles/a1/unpublish'];
        yield 'deleteArticle' => [fn ($a) => $a->deleteArticle('a1'), 'DELETE', '/articles/a1'];
        yield 'translation articles' => [fn ($a) => $a->createTranslation('articles', 'a1', ['locale' => 'en']), 'POST', '/articles/a1/translations'];
        yield 'translation categories' => [fn ($a) => $a->createTranslation('categories', 'c1', ['locale' => 'en']), 'POST', '/categories/c1/translations'];
        yield 'createCategory' => [fn ($a) => $a->createCategory(['name' => 'N']), 'POST', '/categories'];
        yield 'updateCategory' => [fn ($a) => $a->updateCategory('c1', ['name' => 'N']), 'PATCH', '/categories/c1'];
        yield 'deleteCategory' => [fn ($a) => $a->deleteCategory('c1'), 'DELETE', '/categories/c1'];
        yield 'createPage' => [fn ($a) => $a->createPage(['title' => 'T']), 'POST', '/pages'];
        yield 'updatePage' => [fn ($a) => $a->updatePage('p1', ['title' => 'T']), 'PATCH', '/pages/p1'];
        yield 'publishPage' => [fn ($a) => $a->publishPage('p1'), 'POST', '/pages/p1/publish'];
        yield 'submitPageForReview' => [fn ($a) => $a->submitPageForReview('p1'), 'POST', '/pages/p1/submit-for-review'];
        yield 'unpublishPage' => [fn ($a) => $a->unpublishPage('p1'), 'POST', '/pages/p1/unpublish'];
        yield 'deletePage' => [fn ($a) => $a->deletePage('p1'), 'DELETE', '/pages/p1'];
        yield 'listPageRevisions' => [fn ($a) => $a->listPageRevisions('p1'), 'GET', '/pages/p1/revisions'];
        yield 'getPageRevision' => [fn ($a) => $a->getPageRevision('p1', 3), 'GET', '/pages/p1/revisions/3'];
        yield 'restorePageRevision' => [fn ($a) => $a->restorePageRevision('p1', 3), 'POST', '/pages/p1/revisions/3/restore'];
        yield 'createRedirect' => [fn ($a) => $a->createRedirect(['source' => '/a', 'target' => '/b']), 'POST', '/redirects'];
        yield 'updateRedirect' => [fn ($a) => $a->updateRedirect('r1', ['target' => '/c']), 'PATCH', '/redirects/r1'];
        yield 'deleteRedirect' => [fn ($a) => $a->deleteRedirect('r1'), 'DELETE', '/redirects/r1'];
        yield 'bulkRedirects' => [fn ($a) => $a->bulkRedirects(['redirects' => []]), 'POST', '/redirects/bulk'];
        yield 'updateSeo' => [fn ($a) => $a->updateSeo(['siteName' => 'S']), 'PATCH', '/seo'];
        yield 'updateMediaAlt' => [fn ($a) => $a->updateMediaAlt('m1', 'alt'), 'PATCH', '/media/m1'];
        yield 'deleteMedia' => [fn ($a) => $a->deleteMedia('m1'), 'DELETE', '/media/m1'];
        yield 'listSubmissions' => [fn ($a) => $a->listSubmissions('contact', ['status' => 'new', 'page' => 2]), 'GET', '/forms/contact/submissions'];
        yield 'getSubmission' => [fn ($a) => $a->getSubmission('contact', 's1'), 'GET', '/forms/contact/submissions/s1'];
        yield 'setSubmissionStatus' => [fn ($a) => $a->setSubmissionStatus('contact', 's1', 'done'), 'PATCH', '/forms/contact/submissions/s1'];
        yield 'listWebhooks' => [fn ($a) => $a->listWebhooks(), 'GET', '/webhooks'];
        yield 'createWebhook' => [fn ($a) => $a->createWebhook(['url' => 'https://x.nl/h', 'events' => ['*']]), 'POST', '/webhooks'];
        yield 'getWebhook' => [fn ($a) => $a->getWebhook('w1'), 'GET', '/webhooks/w1'];
        yield 'updateWebhook' => [fn ($a) => $a->updateWebhook('w1', ['active' => false]), 'PATCH', '/webhooks/w1'];
        yield 'deleteWebhook' => [fn ($a) => $a->deleteWebhook('w1'), 'DELETE', '/webhooks/w1'];
        yield 'testWebhook' => [fn ($a) => $a->testWebhook('w1'), 'POST', '/webhooks/w1/test'];
        yield 'rotateWebhookSecret' => [fn ($a) => $a->rotateWebhookSecret('w1'), 'POST', '/webhooks/w1/rotate-secret'];
        yield 'listWebhookDeliveries' => [fn ($a) => $a->listWebhookDeliveries('w1', ['limit' => 5]), 'GET', '/webhooks/w1/deliveries'];
        yield 'startPayment' => [fn ($a) => $a->startPayment(['kind' => 'appointment', 'id' => 'x']), 'POST', '/payments'];
    }

    /** @param callable(\FullStackStudio\Content\Modules\Admin): mixed $call */
    #[DataProvider('adminCalls')]
    public function testAdminRoutes(callable $call, string $method, string $path): void
    {
        $call($this->client()->admin);

        self::assertSame($method, $this->http->last()->method);
        self::assertSame($path, $this->http->lastPath());
    }

    public function testAdminUnwrapsAndBodiesGoAsJson(): void
    {
        $this->http->always(FakeTransport::json(['article' => ['id' => 'a1']], 201));
        self::assertSame(['id' => 'a1'], $this->client()->admin->createArticle(['title' => 'Hé', 'url' => 'https://x/y']));
        self::assertStringContainsString('"Hé"', $this->http->last()->body);
        self::assertStringContainsString('https://x/y', $this->http->last()->body);

        $this->http->always(FakeTransport::json([]));
        $this->client()->admin->publishArticle('a1');
        self::assertNull($this->http->last()->body);
        $this->client()->admin->bulkRedirects([]);
        self::assertSame('{}', $this->http->last()->body);
    }

    public function testUploadMediaSendsMultipart(): void
    {
        $this->http->always(FakeTransport::json(['media' => ['id' => 'm1']], 201));
        $media = $this->client()->admin->uploadMedia(['contents' => 'PNGDATA', 'filename' => 'a.png', 'contentType' => 'image/png', 'alt' => 'Logo']);

        self::assertSame(['id' => 'm1'], $media);
        self::assertSame('/media', $this->http->lastPath());
        self::assertNull($this->http->last()->body);
        $form = $this->http->last()->multipart;
        self::assertInstanceOf(UploadFile::class, $form['file']);
        self::assertSame('a.png', $form['file']->filename);
        self::assertSame('Logo', $form['alt']);
        self::assertArrayNotHasKey('Content-Type', $this->http->last()->headers);

        $this->expectException(ContentException::class);
        $this->client()->admin->uploadMedia([]);
    }

    public function testApiErrorsBecomeContentExceptions(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'No such article.']], 404, ['X-FSC-Request-Id' => 'req_1']));

        try {
            $this->client()->getArticle('nope');
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame(404, $e->status());
            self::assertSame('not_found', $e->errorCode());
            self::assertSame('No such article.', $e->getMessage());
            self::assertSame('req_1', $e->requestId());
            self::assertTrue($e->isNotFound());
            self::assertFalse($e->isRateLimited());
            self::assertNull($e->retryAfter());
        }
    }

    public function testRateLimitedHasRetryAfter(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'rate_limited', 'message' => 'Slow down']], 429, ['Retry-After' => '12']));

        try {
            $this->client()->getProject();
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertTrue($e->isRateLimited());
            self::assertSame(12, $e->retryAfter());
        }
    }

    public function testNonJsonErrorBodyIsNeverPassedOn(): void
    {
        $this->http->always(new \FullStackStudio\Content\Http\Response(502, [], '<html>Bad gateway for Bearer ' . self::SERVER . ' secret page</html>'));

        try {
            $this->client()->getProject();
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame(502, $e->status());
            self::assertSame('http_error', $e->errorCode());
            self::assertStringNotContainsString('Bad gateway', $e->getMessage());
            self::assertStringNotContainsString(self::SERVER, $e->getMessage());
        }
    }

    public function testTokenIsScrubbedFromApiMessages(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'invalid_token', 'message' => 'Token ' . self::SERVER . ' is bad']], 401));

        try {
            $this->client()->getProject();
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertStringNotContainsString(self::SERVER, $e->getMessage());
        }
    }

    public function testInvalidJsonOn200IsInvalidResponse(): void
    {
        $this->http->always(new \FullStackStudio\Content\Http\Response(200, [], '<html>oops</html>'));

        try {
            $this->client()->getProject();
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame(200, $e->status());
            self::assertSame('invalid_response', $e->errorCode());
            self::assertStringNotContainsString('oops', $e->getMessage());
        }
    }

    public function testTimeoutAndNetworkErrors(): void
    {
        $this->http->queue(new TransportException('timed out', true), new TransportException('connection refused with Bearer ' . self::SERVER));

        try {
            $this->client()->getProject();
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame(0, $e->status());
            self::assertSame('timeout', $e->errorCode());
        }

        try {
            $this->client()->getProject();
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame(0, $e->status());
            self::assertSame('network_error', $e->errorCode());
            self::assertStringNotContainsString(self::SERVER, $e->getMessage());
        }
    }

    public function testCallTimeoutOverridesClientTimeout(): void
    {
        $this->client(self::SERVER, ['timeoutMs' => 5000])->getProject();
        self::assertSame(5000, $this->http->last()->timeoutMs);
        $this->client(self::SERVER, ['timeoutMs' => 5000])->getProject(['timeoutMs' => 1500]);
        self::assertSame(1500, $this->http->last()->timeoutMs);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badConfigs(): iterable
    {
        yield 'empty token' => [['token' => ' '], 'invalid_config'];
        yield 'http remote' => [['token' => 'fsc_srv_x', 'baseUrl' => 'http://content.example.com'], 'invalid_config'];
        yield 'ftp' => [['token' => 'fsc_srv_x', 'baseUrl' => 'ftp://localhost'], 'invalid_config'];
        yield 'garbage' => [['token' => 'fsc_srv_x', 'baseUrl' => 'not a url'], 'invalid_config'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badConfigs')]
    public function testHttpsIsRequiredAndConfigIsValidated(array $options, string $code): void
    {
        try {
            new ContentClient([...$options, 'transport' => $this->http]);
            self::fail('expected exception');
        } catch (ContentException $e) {
            self::assertSame($code, $e->errorCode());
            self::assertSame(0, $e->status());
        }
    }

    public function testLocalhostMayUseHttpAndBaseUrlIsNormalized(): void
    {
        $c = $this->client('fsc_srv_x', ['baseUrl' => 'http://localhost:3000/']);
        self::assertSame('http://localhost:3000', $c->baseUrl());
        $c->getProject();
        self::assertSame('http://localhost:3000/api/v1/project', $this->http->last()->url);

        self::assertSame('https://example.com', $this->client('fsc_srv_x', ['baseUrl' => 'https://Example.com:443/path'])->baseUrl());
        self::assertSame('http://127.0.0.1:8080', $this->client('fsc_srv_x', ['baseUrl' => 'http://127.0.0.1:8080'])->baseUrl());
    }

    public function testOptionsObjectIsAccepted(): void
    {
        $client = ContentClient::create(new ClientOptions(token: self::PUB, transport: $this->http));
        $client->getProject();
        self::assertSame('Bearer ' . self::PUB, $this->http->last()->headers['Authorization']);
    }
}
