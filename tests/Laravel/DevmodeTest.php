<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Devmode;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/**
 * The dev panel: off for everybody, written into a page only for a request with the devmode cookie, switched by `?fsc_devmode=1|0`. Fake transport only.
 */
final class DevmodeTest extends LaravelTestCase
{
    private const AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();

        $http = new FakeTransport();
        $http->always(FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $http]));
    }

    protected function defineRoutes($router): void
    {
        Route::get('/page', static fn () => response('<html><head>' . Blade::render('<x-content::head />') . '</head><body>hi</body></html>')->header('Cache-Control', 'public, s-maxage=300'));
        Route::get('/bare', static fn (): string => Blade::render('<x-content::devmode />') . Blade::render('<x-content::devmode />'));
        Route::post('/page', static fn (): string => 'posted');
    }

    /** @return array<string, string> */
    private function visitor(string $cookie = 'fsc_devmode=1'): array
    {
        return ['User-Agent' => self::AGENT, 'Cookie' => $cookie];
    }

    public function testWithoutTheCookieThePageHasNoDevmodeBitAndKeepsItsHeaders(): void
    {
        $response = $this->withHeaders(['User-Agent' => self::AGENT])->get('/page');

        $response->assertOk();
        self::assertStringNotContainsString('devmode', $response->getContent());
        self::assertStringNotContainsString('flags.js', $response->getContent());
        self::assertSame('public, s-maxage=300', $response->headers->get('Cache-Control'));
    }

    public function testOtherCookieValuesAndOtherCookiesDoNotCount(): void
    {
        foreach (['fsc_devmode=0', 'fsc_devmode=11', 'xfsc_devmode=1', 'other=1'] as $cookie) {
            self::assertStringNotContainsString('devmode.js', $this->withHeaders($this->visitor($cookie))->get('/page')->getContent(), $cookie);
        }

        self::assertStringContainsString('devmode.js', $this->withHeaders($this->visitor('a=b; fsc_devmode=1; c=d'))->get('/page')->getContent());
    }

    public function testWithTheCookieThePageGetsTheTagsAndIsPrivate(): void
    {
        $response = $this->withHeaders($this->visitor())->get('/page');
        $html = $response->getContent();
        $version = ContentClient::VERSION;

        self::assertStringContainsString('<script src="/_fsc/d/flags.js?v=' . $version . '"></script>', $html);
        self::assertStringContainsString('<script src="/_fsc/d/devmode.js?v=' . $version . '" defer data-v="' . $version . '" data-cookie="fsc_devmode"></script>', $html);
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'), 'the site\'s own public header is replaced');
        self::assertLessThan(strpos($html, 'devmode.js'), strpos($html, 'flags.js'), 'the blocking flags script comes first');
    }

    public function testACrawlerNeverGetsThePanel(): void
    {
        $response = $this->withHeaders(['User-Agent' => 'Googlebot/2.1', 'Cookie' => 'fsc_devmode=1'])->get('/page');

        self::assertStringNotContainsString('devmode', $response->getContent());
        self::assertSame('public, s-maxage=300', $response->headers->get('Cache-Control'));
    }

    public function testHeadAndTheComponentWriteTheTagsOnlyOncePerRequest(): void
    {
        self::assertSame(1, substr_count($this->withHeaders($this->visitor())->get('/bare')->getContent(), 'devmode.js'));
    }

    public function testTheCookieNameAndTheOptionalDataComeFromTheConfig(): void
    {
        config([
            'content.devmode.cookie' => 'my_dev',
            'content.devmode.portal_url' => 'https://portal.test/p/{ref}',
            'content.devmode.scripts' => ['/js/dev-site.js', '//evil.test/x.js', 'https://evil.test/x.js', '/js/"bad'],
            'content.devmode.aliases' => ['devmenu', '1bad', 'bad name'],
            'content.intelligence.enabled' => true,
        ]);

        self::assertStringNotContainsString('devmode.js', $this->withHeaders($this->visitor())->get('/page')->getContent(), 'the default cookie name no longer counts');

        $html = $this->withHeaders($this->visitor('my_dev=1'))->get('/page')->getContent();

        self::assertStringContainsString('data-cookie="my_dev"', $html);
        self::assertStringContainsString('data-me="/_fsc/i/me"', $html);
        self::assertStringContainsString('data-portal="https://portal.test/p/{ref}"', $html);
        self::assertStringContainsString('data-scripts="[&quot;/js/dev-site.js&quot;]"', $html);
        self::assertStringContainsString('data-aliases="[&quot;devmenu&quot;]"', $html);
    }

    public function testAPortalUrlWithoutARefPlaceholderIsLeftOut(): void
    {
        config(['content.devmode.portal_url' => 'https://portal.test/p']);

        self::assertStringNotContainsString('data-portal', $this->withHeaders($this->visitor())->get('/page')->getContent());
    }

    public function testTheNonceIsCopiedToBothTags(): void
    {
        $html = Blade::render('<x-content::devmode nonce="n0nce" />', [], false);
        self::assertSame('', trim($html), 'no cookie on the test request');

        request()->headers->set('Cookie', 'fsc_devmode=1');
        request()->headers->set('User-Agent', self::AGENT);
        $html = Blade::render('<x-content::devmode nonce="n0nce" />');

        self::assertSame(2, substr_count($html, 'nonce="n0nce"'));
    }

    public function testTheSwitchSetsTheCookieAndRedirectsWithoutTheParameter(): void
    {
        $response = $this->get('/page?a=1&fsc_devmode=1&b=2');

        $response->assertRedirect('http://localhost/page?a=1&b=2');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $cookie = collect($response->headers->getCookies())->first(static fn ($c): bool => $c->getName() === 'fsc_devmode');
        self::assertNotNull($cookie);
        self::assertSame('1', $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertGreaterThan(time() + 360 * 86400, $cookie->getExpiresTime());
    }

    public function testTheSwitchOffClearsTheCookie(): void
    {
        $response = $this->get('/page?fsc_devmode=0');

        $response->assertRedirect('http://localhost/page');
        $cookie = collect($response->headers->getCookies())->first(static fn ($c): bool => $c->getName() === 'fsc_devmode');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isCleared());
    }

    public function testTheCookieIsSecureOnHttps(): void
    {
        $response = $this->get('https://localhost/page?fsc_devmode=1');

        $cookie = collect($response->headers->getCookies())->first(static fn ($c): bool => $c->getName() === 'fsc_devmode');
        self::assertTrue($cookie->isSecure());
        $response->assertRedirect('https://localhost/page');
    }

    public function testOnlyTheValuesOneAndZeroOnGetPagesSwitch(): void
    {
        $this->get('/page?fsc_devmode=yes')->assertOk();
        $this->get('/page?fsc_devmode')->assertOk();
        $this->post('/page?fsc_devmode=1')->assertOk()->assertSee('posted');
    }

    public function testTheAssetsAreServedAndCacheable(): void
    {
        foreach ([['devmode.js', 'fscdev'], ['flags.js', 'data-fsc-motion']] as [$file, $needle]) {
            $response = $this->get('/_fsc/d/' . $file);
            $response->assertOk();
            self::assertStringContainsString('text/javascript', (string) $response->headers->get('Content-Type'));
            self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString($needle, $response->getContent());
        }
    }
}
