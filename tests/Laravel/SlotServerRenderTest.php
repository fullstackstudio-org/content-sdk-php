<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\PersonalizationController;
use FullStackStudio\Content\Laravel\SlotMarkup;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
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
        // withHeaders() is sticky for the rest of the test: start every visit clean.
        $this->defaultHeaders = [];

        return $this->withHeaders($headers + ['CF-Connecting-IP' => '203.0.113.9', 'User-Agent' => 'Mozilla/5.0 Test'])->get($path);
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
        self::assertStringNotContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
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
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    public function testFieldVariantsChangeTextAndLinksInPlaceEscapedAndOnlySafeLinks(): void
    {
        $this->useHub(self::answer(['variant' => 'veld', 'fields' => ['title' => 'Bellen & <b>meer</b>', 'cta_label' => 'Bel ons', 'cta_url' => '/voip'], 'hide' => false, 'functional' => false]));
        $page = '<x-content::slot name="home-hero"><h1 data-fsi-field="title">Oud <em>titel</em></h1><a class="btn" href="/contact" data-fsi-href="cta_url" data-fsi-field="cta_label">Contact</a></x-content::slot>';
        Route::get('/field', fn () => response(Blade::render($page)));
        $html = (string) $this->visit(['Referer' => 'http://localhost/voip'], '/field')->getContent();

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
        $this->visit(['Referer' => 'http://localhost/voip?x=1#y']);
        self::assertSame('/voip', $this->hubCalls()[0]['referrer']);
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
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));

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

    private function learnedKey(string $uri): string
    {
        return 'fsi:slots:' . hash('sha256', $uri . '|' . strtolower((string) app()->getLocale()));
    }

    public function testOnlyA2xxResponseOfAMatchedRouteLearnsSlotsAndAStaleNameDropsOut(): void
    {
        Route::get('/gone', fn () => response(Blade::render(self::PAGE), 404));
        $this->visit(['Cookie' => 'fsa_pid=' . self::PID], '/gone');
        $this->visit(['Cookie' => 'fsa_pid=' . self::PID], '/does/not/exist');
        self::assertNull($this->app['cache']->get($this->learnedKey('gone')), 'a 404 writes nothing');
        self::assertNull($this->app['cache']->get('fsi:slots:' . hash('sha256', '/does/not/exist')), 'an unknown path writes nothing');

        $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);
        self::assertEqualsCanonicalizing(['home-hero', 'cta', 'chat-opening'], $this->app['cache']->get($this->learnedKey('page')));

        // The page renamed a slot: the list is rebuilt from what it rendered now.
        $this->app['cache']->put($this->learnedKey('renamed'), ['old-slot', 'cta'], 60);
        Route::get('/renamed', fn () => Blade::render('<x-content::slot name="new-slot">x</x-content::slot>'));
        $this->visit(['Cookie' => 'fsa_pid=' . self::PID], '/renamed');
        self::assertSame(['new-slot'], $this->app['cache']->get($this->learnedKey('renamed')));
    }

    public function testOverTheRateLimitThereIsNoHubCallAndTheDefaultRenders(): void
    {
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit('fsi-decide:203.0.113.9', 60);
        }

        $response = $this->visit(['Cookie' => 'fsa_pid=' . self::PID]);

        self::assertCount(0, $this->hubCalls());
        self::assertStringContainsString('Websites die werken', (string) $response->getContent());
        self::assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));
    }

    public function testOnlyAReferrerThatMapsToARealRouteCountsAndItsAnswersAreNeverCached(): void
    {
        $this->visit(['Referer' => 'http://localhost/random-' . uniqid()]);
        self::assertCount(0, $this->hubCalls(), 'an unknown referrer path is no signal');

        $this->visit(['Referer' => 'http://localhost/voip']);
        $this->visit(['Referer' => 'http://localhost/voip']);
        self::assertCount(2, $this->hubCalls(), 'answers with a referrer are not shared through the cache');
    }

    public function testGlobalPrivacyControlIgnoresTheCookie(): void
    {
        $this->visit(['Cookie' => 'fsa_pid=' . self::PID, 'Sec-GPC' => '1']);
        self::assertCount(0, $this->hubCalls());
    }

    public function testANestedAlpineTemplateInAVariantStaysIntact(): void
    {
        $page = '<x-content::slot name="home-hero"><h1 data-fsi-field="title">Oud</h1><x-content::variant key="voip"><div x-data="{open:true}"><template x-if="open"><p>Binnen</p></template><template x-for="i in 3"><i x-text="i"></i></template></div></x-content::variant><template x-if="x"><b>default</b></template></x-content::slot>';
        Route::get('/alpine', fn () => response(Blade::render($page)));
        $html = (string) $this->visit(['Referer' => 'http://localhost/voip'], '/alpine')->getContent();

        self::assertStringContainsString('<div x-data="{open:true}"><template x-if="open"><p>Binnen</p></template><template x-for="i in 3"><i x-text="i"></i></template></div>', $html);
        self::assertStringNotContainsString('</template></template>', $html);
        self::assertStringNotContainsString('default', $html);

        [$default, $templates] = SlotMarkup::extractTemplates('<b>a</b><template data-fsi-variant="v"><template x-if="1"><i>n</i></template>tail</template><template x-if="d">keep</template>');
        self::assertSame('<b>a</b><template x-if="d">keep</template>', $default);
        self::assertSame(['v' => '<template x-if="1"><i>n</i></template>tail'], $templates);
    }

    public function testAResponseThatErroredAfterADecisionIsStillPrivate(): void
    {
        Route::get('/boom', function () {
            Blade::render(self::PAGE);
            abort(404);
        });
        Route::get('/fail', fn () => response(Blade::render(self::PAGE), 500)->header('Cache-Control', 'public, s-maxage=300'));

        $a = $this->visit(['Cookie' => 'fsa_pid=' . self::PID], '/boom');
        self::assertStringContainsString('private', (string) $a->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $a->headers->get('Cache-Control'));

        $this->app['cache']->forget('fsi:decide:breaker');
        $b = $this->visit(['Cookie' => 'fsa_pid=' . self::PID], '/fail');
        self::assertSame('no-cache, private', $b->headers->get('Cache-Control'));
    }

    public function testWithoutASignalTheHtmlIsTheSameAsWithServerRenderingOff(): void
    {
        $on = (string) $this->visit()->getContent();
        config(['content.intelligence.ssr' => false]);
        $off = (string) $this->visit()->getContent();

        self::assertSame(str_replace('data-client="1"', 'data-client="0"', $off), $on);
        self::assertCount(0, $this->hubCalls());
    }

    public function testSlowHubAnswersOpenTheBreaker(): void
    {
        $slow = new FakeTransport();
        $respond = function (): \FullStackStudio\Content\Http\Response {
            usleep(170_000);

            return FakeTransport::json(['active' => false, 'controlPercent' => 0, 'decisions' => []]);
        };
        $slow->queue($respond, $respond, $respond);
        $this->useHub($slow);

        for ($i = 0; $i < 3; $i++) {
            $this->visit(['Referer' => 'http://localhost/voip']);
        }

        self::assertTrue($this->app['cache']->has('fsi:decide:breaker'));
        $this->visit(['Referer' => 'http://localhost/voip']);
        self::assertCount(3, $this->hubCalls());
    }

    public function testCrawlersBotsPrefetchesAndEmptyAgentsNeverGetAVariantOrAHubCall(): void
    {
        $signal = ['Cookie' => 'fsa_pid=' . self::PID, 'Referer' => 'http://localhost/voip'];
        $plain = (string) $this->visit()->getContent();

        foreach ([
            ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            ['User-Agent' => 'Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.1)'],
            ['User-Agent' => 'curl/8.4.0'],
            ['User-Agent' => 'Mozilla/5.0 HeadlessChrome/120.0'],
            ['User-Agent' => ''],
            ['Sec-Purpose' => 'prefetch;prerender'],
            ['Purpose' => 'prefetch'],
        ] as $extra) {
            $response = $this->visit($signal + $extra);
            self::assertSame($plain, (string) $response->getContent(), json_encode($extra));
            self::assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));
            self::assertStringNotContainsString('private', (string) $response->headers->get('Cache-Control'));
        }

        self::assertCount(0, $this->hubCalls());

        // The site can add its own pattern; a broken one is ignored.
        config(['content.intelligence.crawler_pattern' => 'acmemonitor']);
        $this->visit($signal + ['User-Agent' => 'AcmeMonitor/1.0']);
        config(['content.intelligence.crawler_pattern' => '(unclosed']);
        $this->visit($signal);
        self::assertCount(1, $this->hubCalls(), 'only the normal browser was decided');
    }

    public function testTheBrowserRouteAnswersABotWithTheDefault(): void
    {
        $answer = $this->call('POST', '/_fsc/i/decide', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'Googlebot/2.1'], (string) json_encode(['s' => ['home-hero'], 'p' => '/', 'c' => 0]));
        self::assertSame(['d' => []], json_decode((string) $answer->getContent(), true));
        self::assertCount(0, $this->hubCalls());

    }
}
