<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\PersonalizationController;
use FullStackStudio\Content\Laravel\SlotMarkup;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/**
 * FullStack Intelligence slots rendered on the server: a variant is in the HTML when the request carries a signal (same-site Referer on
 * another path, recognition cookie, consented `fsa_pid` cookie), with ONE Hub call per request; no signal means no Hub call and the default;
 * a response that used a signal is `private, no-store` while default responses keep their headers.
 */
final class SlotServerRenderTest extends LaravelTestCase
{
    private const PID = 'abcdefabcdefabcdef12';

    private FakeTransport $hub;

    private const PAGE = <<<'BLADE'
        <x-content::slot name="home-hero" as="section" class="g-hero"><h1 data-fsi-field="title">Websites die werken</h1><a href="/contact" data-fsi-href="cta_url" data-fsi-field="cta_label">Contact</a><x-content::variant key="voip"><h1>Bellen</h1></x-content::variant></x-content::slot>
        <x-content::slot name="cta"><a href="/contact" data-fsi-href="cta_url">Plan</a></x-content::slot>
        <x-content::slot name="chat-opening" :data="true" />
        BLADE;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.intelligence.enabled', true);
        $app['config']->set('content.intelligence.personalization', true);
        $app['config']->set('content.intelligence.recognition_cookie', 'fsi_rec');
        $app['config']->set('content.analytics.ip_header', 'cf-connecting-ip');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->useHub(self::answer());
        Route::get('/page', fn () => response(Blade::render(self::PAGE))->header('Cache-Control', 'public, s-maxage=300'));
        Route::get('/voip', fn () => response(Blade::render(self::PAGE))->header('Cache-Control', 'public, s-maxage=300'));
    }

    private function useHub(FakeTransport $hub): void
    {
        $this->hub = $hub;
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->hub]));
    }

    /** @param array<string, mixed>|null $hero */
    private static function answer(?array $hero = null, int $control = 0, bool $cta = false): FakeTransport
    {
        $decisions = [
            'home-hero' => $hero ?? ['variant' => 'voip', 'fields' => ['title' => 'Bellen & meer', 'cta_label' => 'Bel ons', 'cta_url' => '/voip'], 'hide' => false, 'functional' => false],
            'cta' => $cta ? ['variant' => 'klant', 'fields' => [], 'hide' => true, 'functional' => true] : null,
            'chat-opening' => ['variant' => 'voip', 'fields' => ['message' => 'Hoi, zoek je VoIP?'], 'hide' => false, 'functional' => false],
        ];

        return (new FakeTransport())->always(FakeTransport::json(['active' => true, 'controlPercent' => $control, 'decisions' => $decisions]));
    }

    /** @return list<array<string, mixed>> */
    private function hubCalls(): array
    {
        return array_values(array_map(fn ($r) => json_decode((string) $r->body, true), array_filter($this->hub->requests, fn ($r) => str_contains($r->url, '/intelligence/decide'))));
    }

    /** @param array<string, string> $headers */
    private function visit(array $headers = [], string $path = '/page'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['CF-Connecting-IP' => '203.0.113.9', 'User-Agent' => 'Mozilla/5.0 Test'] + $headers)->get($path);
    }

    public function testWithoutASignalTheDefaultRendersNoHubCallAndTheHeadersStay(): void
    {
        $response = $this->visit();

        $html = (string) $response->getContent();
        self::assertStringContainsString('<h1 data-fsi-field="title">Websites die werken</h1>', $html);
        self::assertStringContainsString('<template data-fsi-variant="voip">', $html);
        self::assertStringNotContainsString('data-fsi-group', $html);
        self::assertCount(0, $this->hubCalls());
        self::assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // The browser step is still there but will not ask: nothing was decided and the browser path is off.
        self::assertStringContainsString('data-client="0"', $html);
    }

    public function testASignalFromAnotherPageOfThisSiteRendersTheVariantWithOneHubCallForAllSlots(): void
    {
        // The page names are learned: the first view of a page decides what it knows, the next one every slot with one call.
        $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);
        $this->hub->requests = [];
        $response = $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);

        $html = (string) $response->getContent();
        self::assertCount(1, $this->hubCalls());
        self::assertEqualsCanonicalizing(['home-hero', 'cta', 'chat-opening'], $this->hubCalls()[0]['slots']);
        self::assertSame(true, $this->hubCalls()[0]['consent']);
        self::assertSame(self::PID, $this->hubCalls()[0]['pid']);
        self::assertSame(250, $this->hub->requests[0]->timeoutMs);

        self::assertStringContainsString('<section data-fsi-slot="home-hero" class="g-hero" data-fsi-variant="voip" data-fsi-group="personal" data-fsi-fields="', $html);
        // The variant's own markup replaced the default; the templates are gone (nothing swaps in the browser).
        self::assertStringContainsString('<h1>Bellen</h1>', $html);
        self::assertStringNotContainsString('<template data-fsi-variant', $html);
        self::assertStringNotContainsString('Websites die werken', $html);
        // A data slot hands its fields to the page.
        self::assertStringContainsString('<template data-fsi-slot="chat-opening" data-fsi-data data-fsi-variant="voip" data-fsi-group="personal" data-fsi-fields="', $html);
        self::assertStringContainsString('Hoi, zoek je VoIP?', html_entity_decode($html));
        // No variant fits the visitor: the default, measured as group none.
        self::assertStringContainsString('<div data-fsi-slot="cta" data-fsi-variant="default" data-fsi-group="none"><a href="/contact" data-fsi-href="cta_url">Plan</a></div>', $html);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testFieldVariantsChangeTextAndLinksInPlaceEscapedAndOnlySafeLinks(): void
    {
        $this->useHub(self::answer(['variant' => 'veld', 'fields' => ['title' => 'Bellen & <b>meer</b>', 'cta_label' => 'Bel ons', 'cta_url' => '/voip'], 'hide' => false, 'functional' => false]));
        $page = '<x-content::slot name="home-hero"><h1 data-fsi-field="title">Oud <em>titel</em></h1><a class="btn" href="/contact" data-fsi-href="cta_url" data-fsi-field="cta_label">Contact</a></x-content::slot>';
        Route::get('/field', fn () => response(Blade::render($page)));
        $html = (string) $this->visit(['Referer' => 'http://localhost/diensten'], '/field')->getContent();

        self::assertStringContainsString('<h1 data-fsi-field="title">Bellen &amp; &lt;b&gt;meer&lt;/b&gt;</h1>', $html);
        self::assertStringContainsString('<a class="btn" href="/voip" data-fsi-href="cta_url" data-fsi-field="cta_label">Bel ons</a>', $html);

        $unsafe = SlotMarkup::applyFields('<a href="/x" data-fsi-href="u">x</a>', ['u' => 'javascript:alert(1)']);
        self::assertSame('<a href="/x" data-fsi-href="u">x</a>', $unsafe);
        self::assertSame('<div data-fsi-field="a"><div>nested</div></div><p>keep</p>', SlotMarkup::applyFields('<div data-fsi-field="a"><div>nested</div></div><p>keep</p>', []));
        self::assertSame('<div data-fsi-field="a">Nieuw</div><div>x</div><p>keep</p>', SlotMarkup::applyFields('<div data-fsi-field="a"><div>nested</div></div><div>x</div><p>keep</p>', ['a' => 'Nieuw']));
        self::assertSame('<a data-fsi-href="u" href="/ok">x</a>', SlotMarkup::applyFields('<a data-fsi-href="u">x</a>', ['u' => '/ok']));
    }

    public function testRecognitionCookieAndSameSiteRefererAreSignals(): void
    {
        $this->visit(['Cookie' => 'fsi_rec=v1.cookie']);
        $call = $this->hubCalls()[0];
        self::assertSame('v1.cookie', $call['recognition']);
        self::assertSame(false, $call['consent']);
        self::assertArrayNotHasKey('pid', $call);

        $this->hub->requests = [];
        $this->app['cache']->flush();
        $this->visit(['Referer' => 'http://localhost/diensten?x=1#y']);
        self::assertSame('/diensten', $this->hubCalls()[0]['referrer']);
    }

    public function testOtherSitesAndTheSamePageAreNoSignalAndABadCookieIsIgnored(): void
    {
        $this->visit(['Referer' => 'https://www.google.com/search?q=x']);
        $this->visit(['Referer' => 'http://localhost/page']);
        $this->visit(['Cookie' => 'fsa_pid=short; other=1']);
        self::assertCount(0, $this->hubCalls());
    }

    public function testTheHubBeingAwayKeepsTheDefaultBreaksTheCircuitAndStillMakesTheResponsePrivate(): void
    {
        $this->useHub((new FakeTransport())->queue(FakeTransport::json(['error' => 'down'], 503)));
        $response = $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);

        self::assertStringContainsString('Websites die werken', (string) $response->getContent());
        self::assertStringNotContainsString('data-fsi-group', (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);
        self::assertCount(1, $this->hubCalls(), 'the breaker spares the Hub');
    }

    public function testTheControlGroupKeepsTheDefaultButIsMarkedAndUsesTheSameBucketAsTheBrowserPath(): void
    {
        $this->useHub(self::answer(null, 50));
        $ua = 'Mozilla/5.0 Test';
        $inControl = null;
        $personal = null;

        for ($i = 0; $i < 200 && ($inControl === null || $personal === null); $i++) {
            $pid = sprintf('visitor-%012d', $i);
            PersonalizationController::bucket('home-hero', $pid, '203.0.113.9', $ua) < 50 ? $inControl ??= $pid : $personal ??= $pid;
        }

        $this->visit(['Cookie' => 'fsa_pid=' . $inControl]);
        $this->app['cache']->forget('fsi:decide:breaker');
        $control = (string) $this->visit(['Cookie' => 'fsa_pid=' . $inControl])->getContent();
        self::assertStringContainsString('data-fsi-variant="voip" data-fsi-group="control"', $control);
        self::assertStringContainsString('Websites die werken', $control);
        self::assertStringNotContainsString('data-fsi-fields', explode('</section>', $control)[0]);

        $personalHtml = (string) $this->visit(['Cookie' => 'fsa_pid=' . $personal])->getContent();
        self::assertStringContainsString('data-fsi-group="personal"', $personalHtml);
    }

    public function testAHideDecisionForACustomerHidesTheSlotOnTheServer(): void
    {
        $this->useHub(self::answer(null, 0, true));
        $this->visit(['Cookie' => 'fsi_rec=v1.cookie']);
        $this->app['cache']->forget('fsi:decide:breaker');
        $html = (string) $this->visit(['Cookie' => 'fsi_rec=v1.cookie'])->getContent();

        self::assertStringContainsString('<div data-fsi-slot="cta" data-fsi-variant="klant" data-fsi-group="customer" hidden></div>', $html);
    }

    public function testSwitchesServerRenderingOffAndTheBrowserPathOn(): void
    {
        config(['content.intelligence.ssr' => false]);
        $html = (string) $this->visit(['Cookie' => 'fsa_pid=' . self::PID])->getContent();
        self::assertCount(0, $this->hubCalls());
        self::assertStringContainsString('data-client="1"', $html);
        self::assertStringContainsString('<template data-fsi-variant="voip">', $html);

        config(['content.intelligence.ssr' => true, 'content.intelligence.client_decide' => true]);
        self::assertStringContainsString('data-client="1"', (string) $this->visit()->getContent());
    }

    public function testPersonalisationOffChangesNothingAndMakesNoCall(): void
    {
        config(['content.intelligence.personalization' => false]);
        $response = $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);

        self::assertCount(0, $this->hubCalls());
        self::assertStringNotContainsString('data-fsi-slot', (string) $response->getContent());
        self::assertStringNotContainsString('data-fsi-group', (string) $response->getContent());
        self::assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));
    }
}
