<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\PersonalizationController;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/**
 * FullStack Intelligence personalisation (phase 7) with personalisation ON: the default content is always in the HTML (SEO, edge cache, no
 * layout shift), variants are inert templates, slots.js is loaded once, and `POST /_fsc/i/decide` forwards the browser id only with consent,
 * applies a stable control group of the Hub's size, never holds back the functional customer variant and falls back to the default on any
 * problem. The OFF state is {@see PersonalizationOffTest}.
 */
final class PersonalizationTest extends LaravelTestCase
{
    private FakeTransport $content;

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

        $this->content = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));
    }

    private function render(string $template): string
    {
        app('request')->attributes->remove('fsi.slots-script');

        return Blade::render($template);
    }

    /** @param array<string, mixed> $body */
    private function decide(array $body, array $cookies = [], string $ip = '203.0.113.9', string $ua = 'Mozilla/5.0 Test'): array
    {
        $response = $this->call('POST', '/_fsc/i/decide', [], $cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_CF_CONNECTING_IP' => $ip, 'HTTP_USER_AGENT' => $ua], (string) json_encode($body));
        $response->assertStatus(200);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return json_decode((string) $response->getContent(), true);
    }

    /** @return list<array<string, mixed>> the bodies of the decide calls to the Hub */
    private function hubCalls(): array
    {
        return array_values(array_map(fn ($r) => json_decode((string) $r->body, true), array_filter($this->content->requests, fn ($r) => str_contains($r->url, '/api/v1/intelligence/decide'))));
    }

    private static function answer(array $decisions, int $control = 10): FakeTransport
    {
        return (new FakeTransport())->always(FakeTransport::json(['active' => true, 'controlPercent' => $control, 'decisions' => $decisions]));
    }

    public function testTheDefaultIsInTheHtmlVariantsAreTemplatesAndTheScriptComesOnce(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-content::slot name="home-hero" as="section" class="g-hero"><h1 data-fsi-field="title">Websites die werken</h1><x-content::variant key="voip"><h1>Bellen</h1></x-content::variant></x-content::slot>
            <x-content::slot name="cta"><a href="/contact">Contact</a></x-content::slot>
            <x-content::slot name="chat-opening" :data="true" />
            BLADE);

        self::assertStringContainsString('<section data-fsi-slot="home-hero" class="g-hero"><h1 data-fsi-field="title">Websites die werken</h1><template data-fsi-variant="voip"><h1>Bellen</h1></template></section>', $html);
        self::assertStringContainsString('<div data-fsi-slot="cta"><a href="/contact">Contact</a></div>', $html);
        self::assertStringContainsString('<template data-fsi-slot="chat-opening" data-fsi-data></template>', $html);
        self::assertSame(1, substr_count($html, '<script'));
        self::assertStringContainsString('<script defer src="/_fsc/i/slots.js?v=' . ContentClient::VERSION . '" data-endpoint="/_fsc/i/decide" data-client="0" data-deadline="1500"></script>', $html);
    }

    public function testTheScriptIsServedWithoutCookies(): void
    {
        $response = $this->get('/_fsc/i/slots.js');
        $response->assertOk();
        self::assertStringContainsString('fsiSlots', (string) $response->getContent());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testTheBrowserIdOnlyLeavesTheSiteWithConsentAndTheRecognitionCookieAlways(): void
    {
        $this->content = self::answer(['home-hero' => null]);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));

        $this->decide(['s' => ['home-hero'], 'p' => '/', 'r' => '/voip', 'c' => 0, 'pid' => 'abcdefabcdefabcdef'], ['fsi_rec' => 'v1.cookie']);
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 1, 'pid' => 'abcdefabcdefabcdef']);

        $calls = $this->hubCalls();
        self::assertSame(['slots' => ['home-hero'], 'path' => '/', 'consent' => false, 'referrer' => '/voip', 'recognition' => 'v1.cookie'], $calls[0]);
        self::assertSame(['slots' => ['home-hero'], 'path' => '/', 'consent' => true, 'pid' => 'abcdefabcdefabcdef'], $calls[1]);
        self::assertSame('Bearer fsc_srv_laraveltoken', $this->content->requests[0]->headers['Authorization']);
        // The visitor IP and user agent never go to the Hub.
        self::assertStringNotContainsString('203.0.113.9', (string) $this->content->requests[0]->body);
    }

    public function testTheControlGroupIsStableAboutTenPercentAndNeverHoldsBackACustomer(): void
    {
        $this->content = self::answer([
            'home-hero' => ['variant' => 'voip', 'fields' => ['title' => 'Bellen'], 'hide' => false, 'functional' => false],
            'cta' => ['variant' => 'klant', 'fields' => [], 'hide' => true, 'functional' => true],
        ]);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));

        $groups = ['personal' => 0, 'control' => 0];

        for ($i = 0; $i < 400; $i++) {
            $answer = $this->decide(['s' => ['home-hero', 'cta'], 'p' => '/voip', 'c' => 0], [], sprintf('10.%d.%d.%d', intdiv($i, 65536) % 256, intdiv($i, 256) % 256, $i % 256), 'UA ' . intdiv($i, 250));
            $groups[$answer['d']['home-hero']['g']]++;
            self::assertSame(['v' => 'klant', 'g' => 'customer', 'h' => 1], $answer['d']['cta']);
        }

        self::assertGreaterThan(20, $groups['control']);
        self::assertLessThan(70, $groups['control']);

        // Stable: the same visitor gets the same group on every request of the day; with consent the browser id decides on every visit.
        $first = $this->decide(['s' => ['home-hero'], 'p' => '/voip', 'c' => 0], [], '192.0.2.44')['d']['home-hero']['g'];
        self::assertSame($first, $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0], [], '192.0.2.44')['d']['home-hero']['g']);
        self::assertSame(PersonalizationController::bucket('home-hero', 'abcdefabcdefabcdef', '192.0.2.1', 'A'), PersonalizationController::bucket('home-hero', 'abcdefabcdefabcdef', '198.51.100.7', 'B'));
        self::assertSame(PersonalizationController::bucket('home-hero', null, '192.0.2.1', 'A', '2026-11-05'), PersonalizationController::bucket('home-hero', null, '192.0.2.1', 'A', '2026-11-05'));

        // Every slot has its own independent control group: over many visitors, being held back in one slot says nothing about another.
        $both = 0;
        $inA = 0;

        for ($i = 0; $i < 2000; $i++) {
            $a = PersonalizationController::bucket('home-hero', null, "10.0.{$i}.1", 'UA', '2026-11-05') < 10;
            $b = PersonalizationController::bucket('cta-close', null, "10.0.{$i}.1", 'UA', '2026-11-05') < 10;
            $inA += $a ? 1 : 0;
            $both += $a && $b ? 1 : 0;
        }

        self::assertGreaterThan(120, $inA);
        self::assertLessThan(60, $both); // independent: about 1% of 2000 = 20, never the 10% of a shared bucket

        // A held-back visitor keeps the default but the browser still gets the variant's content (for the same layout check).
        $control = self::answer(['home-hero' => ['variant' => 'voip', 'fields' => ['title' => 'Bellen'], 'hide' => false, 'functional' => false]], 50);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $control]));
        $seen = [];

        for ($i = 0; $i < 60; $i++) {
            $seen[] = $this->decide(['s' => ['home-hero'], 'p' => '/x' . $i, 'c' => 0], [], "203.0.113.{$i}")['d']['home-hero'];
        }

        self::assertContains(['v' => 'voip', 'g' => 'control', 'f' => ['title' => 'Bellen']], $seen);
        self::assertContains(['v' => 'voip', 'g' => 'personal', 'f' => ['title' => 'Bellen']], $seen);
    }

    public function testNoMatchIsNoneAndAnUnknownOrOffSlotIsLeftOut(): void
    {
        $this->content = self::answer(['home-hero' => null, 'other' => ['variant' => 'x', 'fields' => [], 'hide' => false, 'functional' => false]]);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));

        self::assertSame(['d' => ['home-hero' => ['v' => 'default', 'g' => 'none']]], $this->decide(['s' => ['home-hero', 'cta'], 'p' => '/', 'c' => 0]));
    }

    public function testAnyProblemKeepsTheDefaultAndTheBreakerSparesTheHub(): void
    {
        // Timeout or outage of the Hub: the default stays, and for 10 s the site does not ask again (one key per site, not per visitor).
        $this->content->queue(new TransportException('timeout'));
        self::assertSame(['d' => []], $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0]));
        self::assertSame(['d' => []], $this->decide(['s' => ['home-hero'], 'p' => '/voip', 'c' => 1, 'pid' => 'abcdefabcdefabcdef'], [], '192.0.2.77'));
        self::assertCount(1, $this->hubCalls());
        $this->travel(11)->seconds();

        // A 429 of the Hub (rate limit per token) counts as a failure too.
        $this->content->queue(FakeTransport::json(['error' => ['code' => 'rate_limited', 'message' => 'Te veel']], 429));
        self::assertSame(['d' => []], $this->decide(['s' => ['home-hero'], 'p' => '/a', 'c' => 0]));
        self::assertSame(['d' => []], $this->decide(['s' => ['home-hero'], 'p' => '/b', 'c' => 0]));
        self::assertCount(2, $this->hubCalls());
        $this->travel(11)->seconds();

        // The module is off in the Hub.
        $this->content->queue(FakeTransport::json(['active' => false, 'controlPercent' => 0, 'decisions' => []]));
        self::assertSame(['d' => []], $this->decide(['s' => ['home-hero'], 'p' => '/about', 'c' => 0]));
        // The decide call uses the small budget.
        self::assertSame(400, $this->content->requests[0]->timeoutMs);
        self::assertCount(3, $this->hubCalls());

        foreach ([['s' => [], 'p' => '/'], ['s' => ['Hero!'], 'p' => '/'], ['s' => ['a'], 'p' => 'https://elders.example/'], ['s' => ['a'], 'p' => '//elders.example/'], 'not json'] as $body) {
            $raw = is_string($body) ? $body : (string) json_encode($body);
            $this->call('POST', '/_fsc/i/decide', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(400)->assertExactJson(['d' => []]);
        }

        $this->call('POST', '/_fsc/i/decide', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 3000))->assertStatus(413);
    }

    public function testAnAnswerIsCachedBriefly(): void
    {
        $this->content = self::answer(['home-hero' => null]);
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));

        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0], [], '192.0.2.1');
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0], [], '192.0.2.2');
        self::assertCount(1, $this->hubCalls());
        $this->decide(['s' => ['home-hero'], 'p' => '/voip', 'c' => 0]);
        self::assertCount(2, $this->hubCalls());

        // Answers for a browser id or a recognition cookie are never cached: no per-visitor entries in the site's cache.
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 1, 'pid' => 'abcdefabcdefabcdef']);
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 1, 'pid' => 'abcdefabcdefabcdef']);
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0], ['fsi_rec' => 'v1.cookie']);
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0], ['fsi_rec' => 'v1.cookie']);
        self::assertCount(6, $this->hubCalls());

        // The page language goes to the Hub and is part of the anonymous cache key.
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0, 'l' => 'en']);
        self::assertSame('en', $this->hubCalls()[6]['locale']);
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0, 'l' => 'EN']);
        $this->decide(['s' => ['home-hero'], 'p' => '/', 'c' => 0, 'l' => 'bad lang']);
        self::assertCount(7, $this->hubCalls());
    }
}
