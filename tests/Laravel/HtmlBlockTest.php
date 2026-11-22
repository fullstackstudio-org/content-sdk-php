<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\HtmlBlock;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

/**
 * The page builder's `html` block: isolation x consent, the frame route `/_fsc/h/{pageId}/{key}` with its own sandbox CSP, the srcdoc of the
 * preview, the page CSP (only inline blocks add hosts), and the loader and sizer once per page.
 */
final class HtmlBlockTest extends LaravelTestCase
{
    private const PAGE_ID = '7a1d3c5e-2b4f-4a6c-8d9e-0f1a2b3c4d5e';

    private const WIDGET = '<div id="w"></div><script src="https://widgets.example.com/w.js"></script><script>init("w")</script>'
        . '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe><style>#w{color:red}</style>';

    private const SANDBOX = 'allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox';

    /** @var array<string, mixed>|int the answer of GET /pages/{id} (an int = that error status) */
    private array|int $page = 404;

    private FakeTransport $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeTransport();
        $this->http->always(new Response(500, [], ''));
        $this->http->queue(...array_fill(0, 30, function (Request $request): Response {
            $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

            if ($path === '/pages/' . self::PAGE_ID) {
                return is_int($this->page) ? FakeTransport::json(['error' => ['code' => 'x', 'message' => 'x']], $this->page) : FakeTransport::json(['page' => $this->page]);
            }

            // The module `site` is off: only the html blocks count for the CSP.
            return FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'off']], 404);
        }));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        Vite::useCspNonce('n0nce');
    }

    /** @return array<string, mixed> */
    private static function block(array $override = []): array
    {
        return ['id' => 'h1', 'type' => 'html', 'name' => 'Widget', 'html' => self::WIDGET, 'consent' => 'necessary', 'isolate' => true, ...$override];
    }

    /** The site fetched the page (as every page controller does), then renders its blocks. @param list<array<string, mixed>> $blocks */
    private function renderPage(array $blocks, string $status = 'published'): string
    {
        $this->page = ['id' => self::PAGE_ID, 'status' => $status, 'slug' => 'widget', 'blocks' => $blocks];
        $page = app(ContentClient::class)->getPage(self::PAGE_ID);

        return Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => $page['blocks']]);
    }

    private function preview(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));
    }

    // --- markup -------------------------------------------------------------------------------------------------------------

    public function testAnIsolatedBlockIsASandboxedFrameOnTheSiteRouteWithoutSameOriginOrTheCode(): void
    {
        $html = $this->renderPage([self::block()]);

        self::assertStringContainsString('<iframe class="fsc-html__frame" data-fsc-html-frame title="Widget" width="100%" sandbox="' . self::SANDBOX . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" src="/_fsc/h/' . self::PAGE_ID . '/h1"></iframe>', $html);
        self::assertStringNotContainsString('allow-same-origin', $html);
        self::assertStringNotContainsString('srcdoc', $html);
        self::assertStringNotContainsString('widgets.example.com', $html, 'the code is not in the page');
        self::assertStringNotContainsString('<template', $html);
        self::assertSame(1, substr_count($html, 'if (w.fscHtmlBlock) { return; }'), 'the parent sizer once');
        self::assertStringContainsString('<script nonce="n0nce">(function (w, d) {', $html);
        self::assertStringNotContainsString('fscCode', $html, 'no consent loader for necessary code');
    }

    public function testABlockWithoutIdIsAddressedByItsIndexAndAnUnknownPageRendersNothing(): void
    {
        $html = $this->renderPage([['type' => 'richText', 'html' => '<p>x</p>'], self::block(['id' => null])]);
        self::assertStringContainsString('src="/_fsc/h/' . self::PAGE_ID . '/~1"', $html);

        // Blocks the site did not get through getPage() (its own fetch): no frame, no fallback that would need the page CSP.
        $html = Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [self::block(['id' => 'elders', 'html' => '<p>other</p>'])]]);
        self::assertStringNotContainsString('fsc-block--html', $html);
    }

    public function testNonBooleanIsolateValuesStayIsolated(): void
    {
        foreach (['false', 0, '0', null, 'no', []] as $value) {
            $html = $this->renderPage([self::block(['isolate' => $value])]);
            self::assertStringContainsString('data-fsc-html-frame', $html, var_export($value, true));
            self::assertStringNotContainsString('<script src="https://widgets.example.com/w.js"', $html, var_export($value, true));
        }
    }

    public function testIsolatedWithConsentWrapsTheFrameInATemplateWithAPlaceholderAndTheLoader(): void
    {
        $html = $this->renderPage([self::block(['consent' => 'marketing'])]);

        self::assertStringContainsString('<div class="fsc-html__placeholder" data-fsc-code-placeholder><p>Deze inhoud verschijnt zodra je cookies voor marketing accepteert.</p></div><template data-fsc-code data-consent="marketing" data-name="Widget"><iframe class="fsc-html__frame" data-fsc-html-frame', $html);
        self::assertStringContainsString('</iframe></template>', $html);
        self::assertSame(1, substr_count($html, 'w.fscCode = {'), 'the consent loader once');
        self::assertStringContainsString('"key":"fsc-consent","event":"fsc:consent"', $html);
    }

    public function testInlineAndNecessaryPrintsTheCodeInPlaceWithTheNonce(): void
    {
        $html = $this->renderPage([self::block(['isolate' => false])]);

        self::assertStringContainsString('<div class="fsc-block fsc-block--html" data-name="Widget"><div id="w"></div><script src="https://widgets.example.com/w.js" nonce="n0nce"></script><script nonce="n0nce">init("w")</script>', $html);
        self::assertStringNotContainsString('<iframe class="fsc-html__frame"', $html);
        self::assertStringNotContainsString('fscHtmlBlock', $html, 'no sizer without a frame');
    }

    public function testInlineWithConsentIsAnInertTemplateActivatedByTheLoader(): void
    {
        $html = $this->renderPage([self::block(['isolate' => false, 'consent' => 'analytics'])]);

        self::assertStringContainsString('cookies voor statistieken accepteert', $html);
        self::assertStringContainsString('<template data-fsc-code data-consent="analytics" data-name="Widget"><div id="w"></div><script src="https://widgets.example.com/w.js" nonce="n0nce"></script>', $html);
        self::assertSame(1, substr_count($html, 'w.fscCode = {'));

        self::assertSame('', HtmlBlock::current()->render(['html' => '<p>x</p></template><script>evil()</script>', 'consent' => 'marketing', 'isolate' => false]), 'code that would end its wrapper early is not printed');
    }

    public function testTheLoaderAndTheSizerArePrintedOncePerPage(): void
    {
        $html = $this->renderPage([
            self::block(['id' => 'a', 'consent' => 'marketing']),
            self::block(['id' => 'b', 'consent' => 'preferences']),
            self::block(['id' => 'c', 'consent' => 'analytics', 'isolate' => false]),
        ]);

        self::assertSame(1, substr_count($html, 'w.fscCode = {'));
        self::assertSame(1, substr_count($html, 'if (w.fscHtmlBlock) { return; }'));
        self::assertSame(3, substr_count($html, '<template data-fsc-code'));
    }

    public function testEmptyOrOversizedCodeAndUnknownConsentValues(): void
    {
        self::assertStringNotContainsString('fsc-block--html', $this->renderPage([self::block(['html' => "  \n "])]));
        self::assertStringNotContainsString('fsc-block--html', $this->renderPage([self::block(['html' => str_repeat('a', 20001)])]));

        $html = $this->renderPage([self::block(['consent' => 'everything', 'name' => '"><b>x'])]);
        self::assertStringContainsString('data-consent="marketing"', $html, 'an unknown category is the strict default, never necessary');
        self::assertStringNotContainsString('"><b>x', $html);
        self::assertStringContainsString('title="&quot;&gt;&lt;b&gt;x"', $html);
    }

    // --- preview ------------------------------------------------------------------------------------------------------------

    public function testThePreviewUsesSrcdocAlwaysIsolatedAndKeepsTheMarkers(): void
    {
        $this->preview();

        $html = Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [self::block(['isolate' => false, 'consent' => 'marketing'])]]);

        self::assertStringContainsString('<div data-fsc-block="h1" data-fsc-block-index="0" style="display:contents">', $html);
        self::assertStringContainsString('<iframe class="fsc-html__frame" data-fsc-html-frame', $html, 'inline code is isolated in a preview');
        self::assertStringContainsString('sandbox="' . self::SANDBOX . '"', $html);
        self::assertStringContainsString('<template data-fsc-code data-consent="marketing"', $html, 'consent still gates it');
        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/', $html, $found), 'unsaved blocks: a srcdoc frame, nothing to serve');
        $doc = html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // The srcdoc inherits the preview response's policy, so its scripts need that response's nonce.
        self::assertStringContainsString('<script src="https://widgets.example.com/w.js" nonce="n0nce"></script>', $doc);
        self::assertStringContainsString("ns: 'fsc-html'", $doc);
        self::assertSame([], Content::siteCsp(), 'a preview frame adds nothing to the page CSP');
    }

    public function testTheCodeCannotBreakOutOfTheSrcdocAttribute(): void
    {
        $this->preview();
        $html = Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [self::block(['html' => '"></iframe><script>alert(document.cookie)</script>'])]]);

        self::assertStringNotContainsString('"></iframe><script>alert', $html);
        self::assertStringContainsString('&quot;&gt;&lt;/iframe&gt;&lt;script nonce=&quot;n0nce&quot;&gt;alert(document.cookie)', $html);
    }

    // --- page CSP -----------------------------------------------------------------------------------------------------------

    public function testIsolatedBlocksAddOnlyFrameSrcSelfToThePageCsp(): void
    {
        self::assertSame([], Content::siteCsp(), 'nothing before a block rendered');

        $this->renderPage([self::block(['consent' => 'marketing'])]);

        self::assertSame(['frame-src' => ["'self'"]], Content::siteCsp(), 'never the hosts of isolated code');
        self::assertSame("default-src 'self'; script-src 'self' 'nonce-n0nce'; frame-src 'self'", Content::siteCspMerge("default-src 'self'; script-src 'self' 'nonce-n0nce'; frame-src 'none'"));
    }

    public function testInlineBlocksAddTheirHostsToThePageCsp(): void
    {
        $this->renderPage([self::block(['isolate' => false])]);

        $sources = Content::siteCsp();
        self::assertSame(['https://widgets.example.com'], $sources['script-src']);
        self::assertSame(['https://widgets.example.com'], $sources['connect-src']);
        self::assertSame(['https://www.youtube-nocookie.com'], $sources['frame-src']);
    }

    public function testAPageWithoutHtmlBlocksAddsNothingAndTheCollectorIsPerRequest(): void
    {
        $this->renderPage([['id' => 'r', 'type' => 'richText', 'html' => '<p>Hallo</p>']]);
        self::assertSame([], Content::siteCsp());

        $this->renderPage([self::block(['isolate' => false])]);
        self::assertNotSame([], Content::siteCsp());

        // A new request scope (Octane, queue workers) starts empty.
        $this->app->forgetScopedInstances();
        self::assertSame([], Content::siteCsp());
    }

    // --- the frame route ----------------------------------------------------------------------------------------------------

    public function testTheRouteServesThePublishedBlockAsItsOwnSandboxedDocument(): void
    {
        $this->page = ['id' => self::PAGE_ID, 'status' => 'published', 'blocks' => [self::block(['consent' => 'marketing'])]];

        $response = $this->get('/_fsc/h/' . self::PAGE_ID . '/h1');

        $response->assertOk();
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString('; sandbox ' . self::SANDBOX, $csp);
        self::assertStringNotContainsString('allow-same-origin', $csp);
        self::assertStringContainsString("script-src 'unsafe-inline' 'unsafe-eval' https: blob:", $csp);
        self::assertStringContainsString('connect-src https: wss:', $csp);
        self::assertStringContainsString('frame-src https:', $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies(), 'no session, no cookies');

        $body = (string) $response->getContent();
        self::assertStringContainsString(self::WIDGET, $body, 'the code exactly as stored');
        self::assertStringNotContainsString('nonce', $body, 'never the page nonce');
        self::assertStringContainsString("ns: 'fsc-html'", $body, 'the height reporter');
    }

    public function testTheRouteAddressesABlockWithoutIdByIndexOnly(): void
    {
        $this->page = ['id' => self::PAGE_ID, 'status' => 'published', 'blocks' => [self::block(), self::block(['id' => null, 'html' => '<p>zonder id</p>'])]];

        self::assertStringContainsString('<p>zonder id</p>', (string) $this->get('/_fsc/h/' . self::PAGE_ID . '/~1')->assertOk()->getContent());
        $this->get('/_fsc/h/' . self::PAGE_ID . '/~0')->assertNotFound();
    }

    public function testTheRouteAnswers404ForAnythingButAPublishedIsolatedHtmlBlock(): void
    {
        $blocks = [
            self::block(),
            ['id' => 'rt', 'type' => 'richText', 'html' => '<p>x</p>'],
            self::block(['id' => 'inline', 'isolate' => false]),
            self::block(['id' => 'empty', 'html' => '  ']),
        ];
        $this->page = ['id' => self::PAGE_ID, 'status' => 'published', 'blocks' => $blocks];

        foreach (['rt', 'inline', 'empty', 'missing'] as $key) {
            $this->get('/_fsc/h/' . self::PAGE_ID . '/' . $key)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->get('/_fsc/h/not-a-uuid/h1')->assertNotFound();
        $this->get('/_fsc/h/' . self::PAGE_ID . '/' . str_repeat('a', 41))->assertNotFound();

        $this->page = ['id' => self::PAGE_ID, 'status' => 'draft', 'blocks' => $blocks];
        $this->get('/_fsc/h/' . self::PAGE_ID . '/h1')->assertNotFound();

        $this->page = 404;
        $this->get('/_fsc/h/' . self::PAGE_ID . '/h1')->assertNotFound();

        $this->page = 500;
        $this->get('/_fsc/h/' . self::PAGE_ID . '/h1')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
    }
}
