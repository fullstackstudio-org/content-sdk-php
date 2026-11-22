<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\HtmlBlock;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

/** The page builder's `html` block: isolation x consent, nonce, the CSP collector, the loader and the sizer once per page, preview. */
final class HtmlBlockTest extends LaravelTestCase
{
    private const WIDGET = '<div id="w"></div><script src="https://widgets.example.com/w.js"></script><script>init("w")</script>'
        . '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe><style>#w{color:red}</style>';

    protected function setUp(): void
    {
        parent::setUp();
        $http = new FakeTransport();
        // The module `site` is off: only the html blocks count for the CSP.
        $http->always(FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'off']], 404));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $http]));
        Vite::useCspNonce('n0nce');
    }

    /** @param list<array<string, mixed>> $blocks */
    private function render(array $blocks): string
    {
        return Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => $blocks]);
    }

    /** @return array<string, mixed> */
    private static function block(array $override = []): array
    {
        return ['id' => 'h1', 'type' => 'html', 'name' => 'Widget', 'html' => self::WIDGET, 'consent' => 'necessary', 'isolate' => true, ...$override];
    }

    /** The srcdoc of the first frame, decoded. */
    private static function srcdoc(string $html): string
    {
        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/', $html, $found), 'a srcdoc frame');

        return html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testIsolatedAndNecessaryIsASandboxedFrameWithoutSameOrigin(): void
    {
        $html = $this->render([self::block()]);

        self::assertStringContainsString('<iframe class="fsc-html__frame" data-fsc-html-frame title="Widget" width="100%" sandbox="allow-scripts allow-popups allow-forms" loading="lazy"', $html);
        self::assertStringNotContainsString('allow-same-origin', $html);
        self::assertStringNotContainsString('<template', $html);
        self::assertStringNotContainsString('data-fsc-code-placeholder', $html);

        $doc = self::srcdoc($html);
        self::assertStringContainsString('<script src="https://widgets.example.com/w.js" nonce="n0nce"></script><script nonce="n0nce">init("w")</script>', $doc, 'the code with the nonce, otherwise unchanged');
        self::assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe><style nonce="n0nce">#w{color:red}</style>', $doc);
        self::assertStringContainsString("ns: 'fsc-html'", $doc, 'the height reporter');
        self::assertSame(1, substr_count($html, "if (w.fscHtmlBlock) { return; }"), 'the parent sizer once');
        self::assertStringContainsString('<script nonce="n0nce">(function (w, d) {', $html);
        self::assertStringNotContainsString('fscCode', $html, 'no consent loader for necessary code');
    }

    public function testIsolatedWithConsentWrapsTheFrameInATemplateWithAPlaceholderAndTheLoader(): void
    {
        $html = $this->render([self::block(['consent' => 'marketing'])]);

        self::assertStringContainsString('<div class="fsc-html__placeholder" data-fsc-code-placeholder><p>Deze inhoud verschijnt zodra je cookies voor marketing accepteert.</p></div><template data-fsc-code data-consent="marketing" data-name="Widget"><iframe class="fsc-html__frame" data-fsc-html-frame', $html);
        self::assertStringContainsString('</iframe></template>', $html);
        self::assertSame(1, substr_count($html, 'w.fscCode = {'), 'the consent loader once');
        self::assertStringContainsString('"key":"fsc-consent","event":"fsc:consent"', $html);
        self::assertStringNotContainsString('allow-same-origin', $html);
    }

    public function testInlineAndNecessaryPrintsTheCodeInPlaceWithTheNonce(): void
    {
        $html = $this->render([self::block(['isolate' => false])]);

        self::assertStringContainsString('<div class="fsc-block fsc-block--html" data-name="Widget"><div id="w"></div><script src="https://widgets.example.com/w.js" nonce="n0nce"></script><script nonce="n0nce">init("w")</script>', $html);
        self::assertStringNotContainsString('<iframe class="fsc-html__frame"', $html);
        self::assertStringNotContainsString('fscHtmlBlock', $html, 'no sizer without a frame');
        self::assertStringNotContainsString('<template', $html);
    }

    public function testInlineWithConsentIsAnInertTemplateActivatedByTheLoader(): void
    {
        $html = $this->render([self::block(['isolate' => false, 'consent' => 'analytics'])]);

        self::assertStringContainsString('cookies voor statistieken accepteert', $html);
        self::assertStringContainsString('<template data-fsc-code data-consent="analytics" data-name="Widget"><div id="w"></div><script src="https://widgets.example.com/w.js" nonce="n0nce"></script>', $html);
        self::assertSame(1, substr_count($html, 'w.fscCode = {'));

        self::assertSame('', HtmlBlock::current()->render(['html' => '<p>x</p></template><script>evil()</script>', 'consent' => 'marketing', 'isolate' => false]), 'code that would end its wrapper early is not printed');
    }

    public function testTheLoaderAndTheSizerArePrintedOncePerPage(): void
    {
        $html = $this->render([
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
        self::assertStringNotContainsString('fsc-block--html', $this->render([self::block(['html' => "  \n "])]));
        self::assertStringNotContainsString('fsc-block--html', $this->render([self::block(['html' => str_repeat('a', 20001)])]));

        $html = $this->render([self::block(['consent' => 'everything', 'name' => '"><b>x'])]);
        self::assertStringContainsString('data-consent="marketing"', $html, 'an unknown category is the strict default, never necessary');
        self::assertStringNotContainsString('"><b>x', $html);
        self::assertStringContainsString('title="&quot;&gt;&lt;b&gt;x"', $html);
    }

    public function testTheCodeCannotBreakOutOfTheSrcdocAttribute(): void
    {
        $html = $this->render([self::block(['html' => '"></iframe><script>alert(document.cookie)</script>'])]);

        self::assertStringNotContainsString('"></iframe><script>alert', $html);
        self::assertStringContainsString('&quot;&gt;&lt;/iframe&gt;&lt;script nonce=&quot;n0nce&quot;&gt;alert(document.cookie)', $html);
    }

    public function testThePreviewAlwaysIsolatesAndKeepsTheMarkers(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));

        $html = $this->render([self::block(['isolate' => false, 'consent' => 'marketing'])]);

        self::assertStringContainsString('<div data-fsc-block="h1" data-fsc-block-index="0" style="display:contents">', $html);
        self::assertStringContainsString('<iframe class="fsc-html__frame" data-fsc-html-frame', $html, 'inline code is isolated in a preview');
        self::assertStringContainsString('<template data-fsc-code data-consent="marketing"', $html, 'consent still gates it');
    }

    public function testTheCollectorAddsTheOriginsOfTheRenderedBlocksToTheSiteCsp(): void
    {
        self::assertSame([], Content::siteCsp(), 'nothing before a block rendered');

        $this->render([self::block(['consent' => 'marketing'])]);

        $sources = Content::siteCsp();
        self::assertSame(['https://widgets.example.com'], $sources['script-src']);
        self::assertSame(['https://widgets.example.com'], $sources['connect-src']);
        self::assertSame(['https://www.youtube-nocookie.com'], $sources['frame-src']);

        $policy = Content::siteCspMerge("default-src 'self'; script-src 'self' 'nonce-n0nce'; frame-src 'none'");
        self::assertSame("default-src 'self'; script-src 'self' 'nonce-n0nce' https://widgets.example.com; frame-src https://www.youtube-nocookie.com; connect-src 'self' https://widgets.example.com", $policy);
    }

    public function testTheCollectorIsPerRequest(): void
    {
        $this->render([self::block()]);
        self::assertNotSame([], Content::siteCsp());

        // A new request scope (Octane, queue workers) starts empty.
        $this->app->forgetScopedInstances();
        self::assertSame([], Content::siteCsp());
    }
}
