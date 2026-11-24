<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\RedirectMiddleware;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/** Fixes from the migration wave: case insensitive paths, the cookie free content route, the head on pages the layout partly printed. Fake Hub only. */
final class WaveFixesTest extends FakeHubTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.url', 'https://klant.test');
    }

    protected function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

        if ($path === '/forms/contact') {
            return FakeTransport::json(['form' => [
                'key' => 'contact', 'name' => 'Contact',
                'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true, 'maxLength' => 100]],
                'honeypotField' => 'website', 'successMessage' => 'ok', 'confirmEnabled' => false, 'turnstileSiteKey' => null,
            ]]);
        }

        return parent::answer($request);
    }

    // --- case insensitive paths ---------------------------------------------------------------------------------------------------

    public function testAPathInOtherLettersIsRedirectedToTheStoredPath(): void
    {
        $this->page('diensten', 'nl', ['title' => 'Diensten']);
        // The Hub matches case insensitively: the same page answers for the capitalised path.
        $this->pages['nl:Diensten'] = $this->pages['nl:diensten'];
        $this->pages['nl:Over-Ons'] = $this->pages['nl:diensten'];

        $this->get('/Diensten')->assertStatus(301)->assertRedirect('http://localhost/diensten');
        $this->get('/Diensten?utm_source=x')->assertStatus(301)->assertRedirect('http://localhost/diensten?utm_source=x');
        $this->get('/diensten')->assertOk();
    }

    // --- the cookie free content route --------------------------------------------------------------------------------------------

    public function testAPlainHubPageSendsNoCookie(): void
    {
        $this->page('over-ons', 'nl', ['title' => 'Over ons', 'blocks' => [['id' => 'b1', 'type' => 'richText', 'html' => '<p>Wie wij zijn</p>']]]);

        $response = $this->get('/over-ons');

        $response->assertOk()->assertSee('Wie wij zijn', false);
        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    public function testAPageWithAFormBlockStartsTheSessionAndKeepsItForThePost(): void
    {
        $this->page('contact', 'nl', ['title' => 'Contact', 'blocks' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);

        $first = $this->get('/contact');
        $first->assertOk();
        preg_match('/name="_token" value="([^"]+)"/', (string) $first->getContent(), $m);
        self::assertNotEmpty($m[1] ?? '', 'the form has a CSRF token');

        $cookie = $first->getCookie((string) config('session.cookie'), false);
        self::assertNotNull($cookie, 'the session cookie is sent');

        // The next request (the `web` group of the form post) finds the same session: same token.
        $second = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/contact');
        preg_match('/name="_token" value="([^"]+)"/', (string) $second->getContent(), $m2);
        self::assertSame($m[1], $m2[1] ?? null);
    }

    public function testTheFormPostStillWorks(): void
    {
        $this->page('contact', 'nl', ['title' => 'Contact', 'blocks' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);
        $this->get('/contact')->assertOk();

        $response = $this->post('/content/forms/contact', ['email' => 'a@b.nl']);

        self::assertNotSame(404, $response->getStatusCode());
        self::assertNotSame(419, $response->getStatusCode());
    }

    // --- redirect lookup once ---------------------------------------------------------------------------------------------------

    public function testTheRedirectLookupRunsOnceWhenTheMiddlewareIsInTheStackTwice(): void
    {
        $this->app['config']->set('content.token', 'fsc_srv_laraveltoken');
        $this->app['router']->middleware([RedirectMiddleware::class, RedirectMiddleware::class])->get('/eigen', fn () => 'x');

        $this->get('/eigen')->assertOk();

        self::assertSame(1, count(array_filter($this->hubPaths(), static fn (string $p): bool => $p === '/redirects/resolve')));
    }

    // --- the head ---------------------------------------------------------------------------------------------------------------

    public function testTheHeadSkipsTagsTheLayoutAlreadyPrinted(): void
    {
        $context = $this->app->make(PageContext::class);
        $context->setStatus(404);

        $html = Blade::render('<title>Eigen titel</title><meta name="robots" content="noindex, follow"><meta property="og:title" content="x"><x-content::head />');

        self::assertSame(1, substr_count($html, '<title'));
        self::assertSame(1, substr_count($html, 'name="robots"'));
        self::assertSame(1, substr_count($html, 'property="og:title"'));
    }

    public function testEveryErrorStatusIsNoindexWithoutCanonical(): void
    {
        foreach ([419, 429, 500] as $status) {
            $this->app->forgetInstance(PageContext::class);
            $this->app->make(PageContext::class)->setStatus($status);

            $html = Blade::render('<x-content::head />');

            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, (string) $status);
            self::assertStringNotContainsString('rel="canonical"', $html, (string) $status);
        }
    }

    public function testRobotsStringAndOgTypeOverrides(): void
    {
        Content::seo(['noindex' => 'noindex, follow', 'ogType' => 'product']);

        $html = Blade::render('<x-content::head path="/product" />');

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        self::assertStringContainsString('<meta property="og:type" content="product">', $html);
    }

    // --- a dead Hub never blocks a static page ------------------------------------------------------------------------------------

    public function testAStaticPageRendersQuicklyWithADeadHub(): void
    {
        $this->http = new FakeTransport();
        $this->http->always(new Response(500, [], '{}'));
        $dead = new TransportException('down', true);
        $this->http->queue(...array_fill(0, 50, $dead));
        $this->app->singleton(\FullStackStudio\Content\ContentClient::class, fn () => new \FullStackStudio\Content\ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        $this->app->forgetInstance(\FullStackStudio\Content\Laravel\SiteSettings::class);
        $this->app['router']->middleware('web')->get('/statisch', static fn () => Blade::render('<html><head><x-content::head /></head><body>Hallo<x-content::scripts /></body></html>'));

        $started = microtime(true);
        $first = $this->get('/statisch');
        $firstCalls = count($this->http->requests);
        $second = $this->get('/statisch');

        $first->assertOk()->assertSee('Hallo', false);
        $second->assertOk()->assertSee('Hallo', false);
        self::assertLessThan(2.0, microtime(true) - $started);

        foreach ($this->http->requests as $request) {
            self::assertLessThanOrEqual(2000, $request->timeoutMs, $request->url . ' short timeout on every read of a static page');
        }

        // The failure is remembered: the second page does not ask the dead Hub again.
        self::assertSame($firstCalls, count($this->http->requests));
    }
}
