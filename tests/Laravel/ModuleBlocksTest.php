<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Laravel\ModuleBlockData;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;

/**
 * The default views of the page builder's module blocks (SDK 0.28): data, empty, error and preview per block. Never a real Hub: every
 * answer comes from FakeTransport.
 */
final class ModuleBlocksTest extends LaravelTestCase
{
    private const EVENT_ID = '0b7c5a52-6f1e-4d5e-9a57-2a7a8f3c1d10';

    private const SERVICE_ID = '5d2b1e7a-1c3f-4b8e-8e0a-6f9d2c4b7a11';

    private FakeTransport $http;

    protected function setUp(): void
    {
        parent::setUp();
        ModuleBlockData::resetLog();
        app()->setLocale('nl');
        $this->http = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
    }

    /** @param array<string, array<string, mixed>|int> $routes API path => answer (an int = that error status) */
    private function hub(array $routes): void
    {
        // A fresh transport (and client) per call, so an earlier queue never answers.
        $this->http = new FakeTransport();
        $this->app->forgetInstance(ContentClient::class);
        $this->http->always(new Response(500, [], ''));
        $answer = static function (Request $request) use ($routes): Response {
            $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
            $body = $routes[$path] ?? 404;

            if ($body === 'module_disabled') {
                return FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'off']], 404);
            }

            return is_int($body) ? FakeTransport::json(['error' => ['code' => 'x', 'message' => 'x']], $body) : FakeTransport::json($body);
        };
        $this->http->queue(...array_fill(0, 20, $answer));
    }

    /** @param list<array<string, mixed>> $blocks */
    private function render(array $blocks): string
    {
        return (string) preg_replace('/ {2,}/', ' ', Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => $blocks]));
    }

    /** @return array<string, mixed> */
    private function queryOf(string $path): array
    {
        foreach ($this->http->requests as $request) {
            if (substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1')) === $path) {
                parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

                return $query;
            }
        }

        return [];
    }

    private function preview(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));
    }

    /** @return array<string, mixed> */
    private static function event(array $override = []): array
    {
        return [
            'id' => self::EVENT_ID,
            'slug' => 'open-dag',
            'title' => 'Open <dag>',
            'summary' => 'Kom kijken',
            'descriptionHtml' => '<p>Welkom <strong>allemaal</strong></p>',
            'location' => 'Werkplaats, Utrecht',
            'startsAt' => '2026-12-12T09:30:00.000Z',
            'endsAt' => '2026-12-12T12:00:00.000Z',
            'currency' => 'EUR',
            'salesState' => 'open',
            'ticketTypes' => [['id' => 't1', 'name' => 'Gratis', 'priceCents' => 0, 'available' => null]],
            'registrationUrl' => 'https://content.test/e/i/studio/open-dag',
            ...$override,
        ];
    }

    // --- eventList ----------------------------------------------------------------------------------------------------------

    public function testTheEventListShowsUpcomingPublishedEventsLinkedToTheHubPage(): void
    {
        $this->hub(['/events' => ['events' => [
            self::event(),
            self::event(['slug' => 'workshop', 'title' => 'Workshop', 'ticketTypes' => [['priceCents' => 1250], ['priceCents' => 2500]], 'registrationUrl' => null, 'salesState' => 'sold_out']),
            self::event(['slug' => 'concept', 'title' => 'Concept', 'status' => 'draft']),
        ], 'page' => 1, 'limit' => 3, 'total' => 3]]);

        $html = $this->render([['id' => 'e1', 'type' => 'eventList', 'heading' => 'Agenda', 'limit' => 3, 'emptyText' => '']]);

        self::assertSame(['upcoming' => 'true', 'status' => 'published', 'limit' => '3'], $this->queryOf('/events'));
        self::assertStringContainsString('<h2 class="fsc-eventlist__heading">Agenda</h2>', $html);
        self::assertSame(2, substr_count($html, '<article class="fsc-eventcard">'), 'a draft is never shown');
        self::assertStringContainsString('<a href="https://content.test/e/i/studio/open-dag" rel="noopener">Open &lt;dag&gt;</a>', $html);
        self::assertStringContainsString('zaterdag 12 december 2026', $html, 'date in the page locale and Amsterdam time');
        self::assertStringContainsString('10:30 - 13:00', $html);
        self::assertStringContainsString('Werkplaats, Utrecht', $html);
        self::assertStringContainsString('<span class="fsc-eventcard__price">Gratis</span>', $html);
        self::assertStringContainsString('<h3 class="fsc-eventcard__title">Workshop</h3>', $html, 'no link without a site route or Hub page');
        self::assertStringContainsString('Vanaf € 12,50', $html);
        self::assertStringContainsString('Uitverkocht', $html);
    }

    public function testTheEventListLinksToTheSiteEventPageWhenConfigured(): void
    {
        config(['content.links.event' => '/evenementen/{slug}']);
        $this->hub(['/events' => ['events' => [self::event()], 'page' => 1, 'limit' => 6, 'total' => 1]]);

        $html = $this->render([['type' => 'eventList', 'heading' => '', 'limit' => 6, 'emptyText' => '']]);

        self::assertStringContainsString('<a href="/evenementen/open-dag">', $html);
        self::assertStringNotContainsString('/e/i/studio', $html);
    }

    public function testAFullUrlAsLinkTemplateIsIgnored(): void
    {
        config(['content.links.event' => 'https://evil.test/{slug}']);
        $this->hub(['/events' => ['events' => [self::event()], 'page' => 1, 'limit' => 6, 'total' => 1]]);

        $html = $this->render([['type' => 'eventList', 'heading' => '', 'limit' => 6, 'emptyText' => '']]);

        self::assertStringNotContainsString('evil.test', $html);
        self::assertStringContainsString('href="https://content.test/e/i/studio/open-dag"', $html);
    }

    public function testAnEmptyEventListPrintsTheEmptyTextOrNothing(): void
    {
        $this->hub(['/events' => ['events' => [], 'page' => 1, 'limit' => 6, 'total' => 0]]);
        $html = $this->render([['type' => 'eventList', 'heading' => 'Agenda', 'limit' => 6, 'emptyText' => 'Er staat <niets> gepland.']]);
        self::assertStringContainsString('<p class="fsc-eventlist__empty">Er staat &lt;niets&gt; gepland.</p>', $html);

        $this->http->requests = [];
        $this->hub(['/events' => ['events' => [], 'page' => 1, 'limit' => 6, 'total' => 0]]);
        self::assertStringNotContainsString('fsc-block--eventList', $this->render([['type' => 'eventList', 'heading' => 'Agenda', 'limit' => 6, 'emptyText' => '']]));
    }

    public function testTheEventListRendersNothingOnAnErrorOrWithTheModuleOffAndLogsOnce(): void
    {
        Log::spy();
        $blocks = [['type' => 'eventList', 'heading' => 'Agenda', 'limit' => 6, 'emptyText' => 'Niets'], ['type' => 'richText', 'html' => '<p>ok</p>']];

        $this->hub(['/events' => 500]);
        $html = $this->render($blocks);
        self::assertStringNotContainsString('fsc-block--eventList', $html, 'emptyText is not printed on an error');
        self::assertStringContainsString('<p>ok</p>', $html);
        $this->hub(['/events' => 500]);
        $this->render($blocks);

        $this->hub(['/events' => 'module_disabled']);
        self::assertStringNotContainsString('fsc-block--eventList', $this->render($blocks));

        Log::shouldHaveReceived('log')->twice();
    }

    public function testEventsAreCachedUnderTheEventsTagAndTheWebhookClearsThem(): void
    {
        config(['content.cache.enabled' => true]);
        $this->app->forgetInstance(ContentClient::class);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http, 'cache' => app(\FullStackStudio\Content\Cache\ContentCache::class)]));
        $this->http->always(FakeTransport::json(['events' => [self::event()], 'page' => 1, 'limit' => 6, 'total' => 1]));
        $block = [['type' => 'eventList', 'heading' => '', 'limit' => 6, 'emptyText' => '']];

        $this->render($block);
        $this->render($block);
        self::assertCount(1, $this->http->requests, 'the second render comes from the cache');

        self::assertContains(Tags::EVENTS, Tags::forEvent(['type' => 'event.updated', 'data' => ['slug' => 'open-dag']]));
        self::assertContains(Tags::EVENTS, Tags::forEvent(['type' => 'event.registered', 'data' => []]));
        self::assertNotContains(Tags::EVENTS, Tags::forEvent(['type' => 'event.checked_in', 'data' => []]));
        app(\FullStackStudio\Content\Cache\ContentCache::class)->invalidateTags([Tags::EVENTS]);
        $this->render($block);
        self::assertCount(2, $this->http->requests);

        // The API methods themselves stay uncached unless asked (registration flows want live seats).
        app(ContentClient::class)->events->list();
        app(ContentClient::class)->events->list();
        self::assertCount(4, $this->http->requests);
    }

    public function testEventReadsUseTheShorterTtl(): void
    {
        $cache = new class implements \FullStackStudio\Content\Cache\ContentCache {
            /** @var list<array{tags: list<string>, ttl: int}> */
            public array $calls = [];

            public function remember(string $key, array $tags, int $ttlSeconds, callable $compute): mixed
            {
                $this->calls[] = ['tags' => $tags, 'ttl' => $ttlSeconds];

                return $compute();
            }

            public function invalidateTags(array $tags): void
            {
            }
        };
        $this->app->forgetInstance(ContentClient::class);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http, 'cache' => $cache]));
        $this->http->always(FakeTransport::json(['events' => [], 'page' => 1, 'limit' => 6, 'total' => 0]));

        $this->render([['type' => 'eventList', 'limit' => 6, 'emptyText' => 'Niets']]);

        self::assertSame([['tags' => [Tags::EVENTS], 'ttl' => ModuleBlockData::EVENTS_TTL]], $cache->calls);
        self::assertSame(300, ModuleBlockData::EVENTS_TTL);
    }

    // --- event --------------------------------------------------------------------------------------------------------------

    public function testTheEventBlockShowsOneEventWithDescriptionAndButton(): void
    {
        $this->hub(['/events/' . self::EVENT_ID => ['event' => self::event()]]);

        $html = $this->render([['id' => 'x', 'type' => 'event', 'eventRef' => self::EVENT_ID, 'showDescription' => true, 'ctaLabel' => 'Ik kom']]);

        self::assertStringContainsString('<h2 class="fsc-event__title">Open &lt;dag&gt;</h2>', $html);
        self::assertStringContainsString('<div class="fsc-article-body fsc-event__description"><p>Welkom <strong>allemaal</strong></p></div>', $html);
        self::assertStringContainsString('<a class="fsc-button fsc-button--primary" href="https://content.test/e/i/studio/open-dag" rel="noopener">Ik kom</a>', $html);

        $this->http->requests = [];
        $this->hub(['/events/' . self::EVENT_ID => ['event' => self::event(['salesState' => 'ended'])]]);
        $closed = $this->render([['type' => 'event', 'eventRef' => self::EVENT_ID, 'showDescription' => false, 'ctaLabel' => 'Ik kom']]);
        self::assertStringNotContainsString('fsc-event__description', $closed);
        self::assertStringNotContainsString('Ik kom', $closed, 'no button once registering closed');
        self::assertStringContainsString('Aanmelden is gesloten', $closed);
    }

    public function testTheEventBlockRendersNothingWhenTheEventIsGoneOrTheRefIsMalformed(): void
    {
        $this->hub(['/events/' . self::EVENT_ID => 404]);
        self::assertStringNotContainsString('fsc-block--event', $this->render([['type' => 'event', 'eventRef' => self::EVENT_ID]]));

        $this->http->requests = [];
        $this->render([['type' => 'event', 'eventRef' => '../admin']]);
        self::assertSame([], $this->http->requests, 'a malformed reference never reaches the Hub');

        $this->hub(['/events/' . self::EVENT_ID => ['event' => self::event(['status' => 'cancelled'])]]);
        self::assertStringNotContainsString('fsc-block--event', $this->render([['type' => 'event', 'eventRef' => self::EVENT_ID]]));
    }

    public function testTheEventDescriptionIsSanitizedAgainInTheSdk(): void
    {
        $dirty = '<p onclick="steal()">Welkom <b>allemaal</b></p><script>alert(1)</script><img src="x" onerror="alert(2)">'
            . '<a href="javascript:alert(3)">klik</a><a href=" JaVa&#09;ScRiPt:alert(4)">ook</a><a href="https://example.nl/info" target="_blank" style="color:red">info</a>'
            . '<style>body{display:none}</style><iframe src="https://evil.test"></iframe><svg><script>alert(5)</script></svg>';
        $this->hub(['/events/' . self::EVENT_ID => ['event' => self::event(['descriptionHtml' => $dirty])]]);

        $html = $this->render([['type' => 'event', 'eventRef' => self::EVENT_ID, 'showDescription' => true]]);

        self::assertStringContainsString('<div class="fsc-article-body fsc-event__description"><p>Welkom <strong>allemaal</strong></p>klikook<a href="https://example.nl/info" rel="noopener nofollow">info</a></div>', $html);
        foreach (['<script', 'onerror', 'onclick', 'javascript:', 'alert(', '<img', '<iframe', '<style', '<svg', 'target=', 'style='] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $html, $needle);
        }
    }

    public function testInactiveTicketTypesDoNotSetThePrice(): void
    {
        $this->hub(['/events/' . self::EVENT_ID => ['event' => self::event(['ticketTypes' => [
            ['priceCents' => 0, 'active' => false],
            ['priceCents' => 1500, 'active' => true],
            ['priceCents' => 900, 'active' => false],
        ]])]]);

        $html = $this->render([['type' => 'event', 'eventRef' => self::EVENT_ID]]);

        self::assertStringContainsString('<span class="fsc-event__price">€ 15,00</span>', $html, 'not "Gratis", not "Vanaf € 9,00"');
    }

    // --- appointmentBooking ---------------------------------------------------------------------------------------------------

    public function testTheBookingButtonGoesToTheHubPageOrTheSiteRoute(): void
    {
        $services = ['services' => [['id' => self::SERVICE_ID, 'name' => 'Intake'], ['id' => 'aaaaaaaa-1c3f-4b8e-8e0a-6f9d2c4b7a11', 'name' => 'Oud', 'active' => false]], 'bookingPageUrl' => 'https://content.test/a/b/studio'];
        $this->hub(['/appointments/services' => $services]);

        $html = $this->render([['type' => 'appointmentBooking', 'heading' => 'Langskomen?', 'text' => 'Plan het zelf.', 'serviceId' => null, 'ctaLabel' => '']]);
        self::assertStringContainsString('<h2 class="fsc-booking__heading">Langskomen?</h2>', $html);
        self::assertStringContainsString('<a class="fsc-button fsc-button--primary" href="https://content.test/a/b/studio">Afspraak maken</a>', $html);

        $this->hub(['/appointments/services' => $services]);
        self::assertStringContainsString('href="https://content.test/a/b/studio/' . self::SERVICE_ID . '"', $this->render([['type' => 'appointmentBooking', 'serviceId' => self::SERVICE_ID, 'ctaLabel' => 'Boek']]));

        config(['content.links.booking' => '/afspraak/{service}']);
        $this->hub(['/appointments/services' => $services]);
        self::assertStringContainsString('href="/afspraak/' . self::SERVICE_ID . '"', $this->render([['type' => 'appointmentBooking', 'serviceId' => self::SERVICE_ID]]));
        $this->hub(['/appointments/services' => $services]);
        self::assertStringContainsString('href="/afspraak"', $this->render([['type' => 'appointmentBooking', 'serviceId' => null]]));

        config(['content.links.booking' => '/afspraak']);
        $this->hub(['/appointments/services' => $services]);
        self::assertStringContainsString('href="/afspraak?service=' . self::SERVICE_ID . '"', $this->render([['type' => 'appointmentBooking', 'serviceId' => self::SERVICE_ID]]));
    }

    public function testTheBookingBlockRendersNothingWithoutATargetOrAnActiveService(): void
    {
        $this->hub(['/appointments/services' => ['services' => [['id' => self::SERVICE_ID]], 'bookingPageUrl' => null]]);
        self::assertStringNotContainsString('fsc-block--appointmentBooking', $this->render([['type' => 'appointmentBooking']]), 'no site route and the Hub page is off');

        $this->hub(['/appointments/services' => ['services' => [['id' => self::SERVICE_ID]], 'bookingPageUrl' => 'https://content.test/a/b/studio']]);
        self::assertStringNotContainsString('fsc-block--appointmentBooking', $this->render([['type' => 'appointmentBooking', 'serviceId' => '9d2b1e7a-1c3f-4b8e-8e0a-6f9d2c4b7a11']]), 'the chosen service is gone');

        $this->hub(['/appointments/services' => 'module_disabled']);
        self::assertStringNotContainsString('fsc-block--appointmentBooking', $this->render([['type' => 'appointmentBooking']]));

        $this->hub(['/appointments/services' => ['services' => [], 'bookingPageUrl' => 'javascript:alert(1)']]);
        self::assertStringNotContainsString('javascript:', $this->render([['type' => 'appointmentBooking']]));
    }

    // --- faq ----------------------------------------------------------------------------------------------------------------

    public function testTheFaqBlockShowsDetailsAndFaqPageJsonLdWithTheNonce(): void
    {
        \Illuminate\Support\Facades\Vite::useCspNonce('n0nce');
        $this->hub(['/faq' => ['categories' => [], 'items' => [
            ['question' => 'Wat kost het?', 'answerHtml' => '<p>Niets <em>extra</em></p>', 'answerText' => 'Niets extra'],
            ['question' => 'Zijn jullie er </script> ook?', 'answerHtml' => '<p>Ja</p>', 'answerText' => 'Ja'],
            ['question' => 'Te veel', 'answerHtml' => '<p>x</p>', 'answerText' => 'x'],
        ], 'total' => 3]]);

        $html = $this->render([['type' => 'faq', 'heading' => 'Vragen', 'categoryId' => 'prijzen', 'limit' => 2, 'structuredData' => true]]);

        self::assertSame(['category' => 'prijzen'], $this->queryOf('/faq'));
        self::assertSame(2, substr_count($html, '<details class="fsc-faq__item">'));
        self::assertStringContainsString('<summary class="fsc-faq__question">Wat kost het?</summary>', $html);
        self::assertStringContainsString('<div class="fsc-article-body fsc-faq__answer"><p>Niets <em>extra</em></p></div>', $html);
        self::assertStringContainsString('<script type="application/ld+json" nonce="n0nce">', $html);
        self::assertStringContainsString('"@type":"FAQPage"', $html);
        self::assertStringNotContainsString('</script> ook', $html, 'a question can never end the JSON-LD script');
        self::assertStringNotContainsString('Te veel', $html);
    }

    public function testTheFaqBlockWithoutStructuredDataAndOnErrors(): void
    {
        $this->hub(['/faq' => ['categories' => [], 'items' => [['question' => 'Q', 'answerHtml' => '<p>A</p>']], 'total' => 1]]);
        $html = $this->render([['type' => 'faq', 'heading' => '', 'categoryId' => null, 'limit' => 10, 'structuredData' => false]]);
        self::assertStringContainsString('fsc-block--faq', $html);
        self::assertStringNotContainsString('ld+json', $html);

        $this->hub(['/faq' => 404]);
        self::assertStringNotContainsString('fsc-block--faq', $this->render([['type' => 'faq', 'categoryId' => 'weg']]));

        $this->hub(['/faq' => ['categories' => [], 'items' => [], 'total' => 0]]);
        self::assertStringNotContainsString('fsc-block--faq', $this->render([['type' => 'faq']]));

        $this->hub(['/faq' => 'module_disabled']);
        self::assertStringNotContainsString('fsc-block--faq', $this->render([['type' => 'faq']]));
    }

    // --- productList ----------------------------------------------------------------------------------------------------------

    public function testTheProductListShowsCardsLinkedThroughTheHubPaths(): void
    {
        $this->hub([
            '/products' => ['products' => [
                ['slug' => 'mok', 'name' => 'Mok <wit>', 'summary' => 'Groot', 'status' => 'active', 'currency' => 'EUR', 'priceFromCents' => 1495, 'priceToCents' => 1495, 'cover' => ['id' => 'm1', 'url' => '/api/media/m1', 'contentType' => 'image/png', 'alt' => 'Mok', 'width' => 600, 'height' => 600]],
                ['slug' => 'shirt', 'name' => 'Shirt', 'summary' => '', 'status' => 'active', 'priceFromCents' => 1999, 'priceToCents' => 2499, 'cover' => null],
                ['slug' => 'oud', 'name' => 'Oud', 'status' => 'archived'],
            ], 'page' => 1, 'limit' => 4, 'total' => 3],
            '/sitemap' => ['entries' => [['type' => 'product', 'slug' => 'mok', 'path' => '/winkel/mok'], ['type' => 'product', 'slug' => 'shirt', 'path' => '//evil.test/shirt']]],
        ]);

        $html = $this->render([['type' => 'productList', 'heading' => 'Shop', 'category' => 'servies', 'limit' => 4]]);

        self::assertSame(['category' => 'servies', 'status' => 'active', 'limit' => '4'], $this->queryOf('/products'));
        self::assertSame(2, substr_count($html, '<article class="fsc-productcard">'));
        self::assertStringContainsString('<h3 class="fsc-productcard__title"><a href="/winkel/mok">Mok &lt;wit&gt;</a></h3>', $html);
        self::assertStringContainsString('<p class="fsc-productcard__price">€ 14,95</p>', $html);
        self::assertStringContainsString('Vanaf € 19,99', $html);
        self::assertStringContainsString('href="/producten/shirt"', $html, 'an unsafe sitemap path falls back to the default');
        self::assertStringNotContainsString('evil.test', $html);
        self::assertStringContainsString('src="https://content.test/api/media/m1', $html);
    }

    public function testTheProductListUsesTheSiteTemplateAndRendersNothingOnErrors(): void
    {
        config(['content.links.product' => '/shop/{slug}']);
        $this->hub(['/products' => ['products' => [['slug' => 'mok', 'name' => 'Mok']], 'page' => 1, 'limit' => 4, 'total' => 1]]);
        $html = $this->render([['type' => 'productList', 'heading' => '', 'category' => null, 'limit' => 4]]);
        self::assertStringContainsString('href="/shop/mok"', $html);
        self::assertNotContains('/sitemap', array_map(static fn ($r) => (string) parse_url($r->url, PHP_URL_PATH), $this->http->requests));

        $this->hub(['/products' => 500]);
        self::assertStringNotContainsString('fsc-block--productList', $this->render([['type' => 'productList']]));

        $this->hub(['/products' => ['products' => [], 'page' => 1, 'limit' => 4, 'total' => 0]]);
        self::assertStringNotContainsString('fsc-block--productList', $this->render([['type' => 'productList']]));
    }

    // --- preview ------------------------------------------------------------------------------------------------------------

    public function testModuleBlocksCarryThePreviewMarkers(): void
    {
        $this->preview();
        $this->hub([
            '/events' => ['events' => [self::event()], 'page' => 1, 'limit' => 6, 'total' => 1],
            '/faq' => ['categories' => [], 'items' => [['question' => 'Q', 'answerHtml' => '<p>A</p>']], 'total' => 1],
        ]);

        $html = $this->render([['id' => 'ev', 'type' => 'eventList', 'limit' => 6], ['id' => 'fq', 'type' => 'faq']]);

        self::assertStringContainsString('data-fsc-preview-blocks', $html);
        self::assertStringContainsString('<div data-fsc-block="ev" data-fsc-block-index="0" style="display:contents">', $html);
        self::assertStringContainsString('<div data-fsc-block="fq" data-fsc-block-index="1" style="display:contents">', $html);
        self::assertStringContainsString('fsc-block--eventList', $html);
        self::assertStringContainsString('fsc-block--faq', $html);
    }
}
