<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Laravel\Site\ConsentController;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;

/** The default cookie banner: when it renders, the script route and `POST /_fsc/c`. Fake transport only: nothing reaches the real Hub. */
final class ConsentBannerTest extends LaravelTestCase
{
    private FakeTransport $http;

    /** @var array<string, mixed> */
    private array $consent = [
        'version' => '3',
        'bannerTitle' => 'Cookies op deze site',
        'bannerText' => 'We tellen bezoeken zonder cookies. Voor kaarten en statistieken vragen we toestemming.',
        'policyUrl' => '/privacy',
        'policyLabel' => 'Privacyverklaring',
        'categories' => ['analytics', 'marketing'],
    ];

    /** @var Response|TransportException */
    private Response|TransportException $consentAnswer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->consentAnswer = FakeTransport::json(['consent' => $this->consent]);
        $this->http->queue(...array_fill(0, 100, function (Request $request): Response {
            $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

            if ($path !== '/consent') {
                return FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404);
            }

            if ($this->consentAnswer instanceof TransportException) {
                throw $this->consentAnswer;
            }

            return $request->method === 'POST' ? FakeTransport::json(['ok' => true], 201) : $this->consentAnswer;
        }));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        cache()->flush();
    }

    private function banner(string $markup = '<x-content::scripts />'): string
    {
        $this->app->forgetScopedInstances();

        return Blade::render($markup);
    }

    /** @return list<Request> the POSTs to /consent */
    private function recorded(): array
    {
        return array_values(array_filter($this->http->requests, static fn (Request $r): bool => $r->method === 'POST' && str_ends_with((string) parse_url($r->url, PHP_URL_PATH), '/consent')));
    }

    public function testItRendersTheBannerWithTheHubTextsAndTheScript(): void
    {
        $html = $this->banner();

        self::assertStringContainsString('id="fsc-consent"', $html);
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-labelledby="fsc-consent-title"', $html);
        self::assertStringContainsString('Cookies op deze site', $html);
        self::assertStringContainsString('Voor kaarten en statistieken', $html);
        self::assertStringContainsString('href="/privacy">Privacyverklaring', $html);
        self::assertStringContainsString('data-version="3"', $html);
        self::assertStringContainsString('data-fsc-category', $html);
        self::assertStringContainsString('value="marketing"', $html);
        self::assertStringContainsString('Alleen noodzakelijk', $html);
        self::assertStringContainsString('Alles accepteren', $html);
        self::assertStringContainsString('<script src="/_fsc/c/consent.js?v=' . ConsentController::version() . '" defer></script>', $html);
        // Hidden until the script decides (no flash, and no banner without JavaScript that could not record a choice).
        self::assertMatchesRegularExpression('/<div id="fsc-consent"[^>]* hidden>/', $html);
        // The two main choices are equal: same element, same class.
        self::assertSame(2, substr_count($html, 'class="fsc-consent__button"'));
        // No inline style attribute (a nonce CSP would block it) and no inline script.
        self::assertStringNotContainsString(' style="', $html);
        self::assertSame(1, substr_count($html, '<script'));
    }

    public function testTheNonceIsOnTheStyleAndTheScriptTag(): void
    {
        $html = $this->banner('<x-content::scripts nonce="abc123" />');

        self::assertStringContainsString('<script src="/_fsc/c/consent.js?v=' . ConsentController::version() . '" nonce="abc123" defer>', $html);
        self::assertStringContainsString('<style nonce="abc123">', $html);
    }

    public function testNothingRendersWithoutBannerText(): void
    {
        $this->consentAnswer = FakeTransport::json(['consent' => [...$this->consent, 'bannerText' => '']]);

        self::assertStringNotContainsString('fsc-consent', $this->banner());
    }

    public function testNothingRendersWhenTheModuleIsOff(): void
    {
        $this->consentAnswer = FakeTransport::json(['error' => ['code' => 'module_disabled', 'message' => 'x']], 404);

        self::assertStringNotContainsString('fsc-consent', $this->banner());
    }

    public function testNothingRendersWhenTheHubIsDown(): void
    {
        $this->consentAnswer = new TransportException('down');

        self::assertStringNotContainsString('fsc-consent', $this->banner());
    }

    public function testNothingRendersInThePortalPreview(): void
    {
        $this->app->forgetScopedInstances();
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));

        self::assertStringNotContainsString('fsc-consent', Blade::render('<x-content::scripts />'));
    }

    public function testNothingRendersWhenTheSwitchIsOff(): void
    {
        config()->set('content.auto.consent', false);

        self::assertStringNotContainsString('fsc-consent', $this->banner());
    }

    public function testTheBannerPrintsOncePerRequest(): void
    {
        self::assertSame(1, substr_count($this->banner('<x-content::scripts /><x-content::scripts />'), 'id="fsc-consent"'));
    }

    public function testTheCssIsInWhereAndKnowsThePreview(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', ConsentController::styles());

        // Every top level rule starts with :where(), except the keyframes, the media blocks and the one hard rule at the end.
        preg_match_all('/^(?!\s)([^\n{]+)\{/m', $css, $selectors);
        foreach ($selectors[1] as $selector) {
            $selector = trim($selector);
            self::assertTrue(
                str_starts_with($selector, ':where(') || str_starts_with($selector, '@') || str_starts_with($selector, '.fsc-consent [hidden]') || str_starts_with($selector, 'html[data-fsc-preview]'),
                'Not in :where(): ' . $selector
            );
        }

        self::assertStringContainsString('html[data-fsc-preview] .fsc-consent', $css);
        // Colours only through --fsc-* with a fallback, or system colours.
        self::assertStringContainsString('var(--fsc-accent', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function testTheScriptRouteIsCacheableAndSetsNoCookie(): void
    {
        $plain = $this->get('/_fsc/c/consent.js');
        $plain->assertOk();
        self::assertStringContainsString('text/javascript', (string) $plain->headers->get('Content-Type'));
        self::assertStringContainsString('public', (string) $plain->headers->get('Cache-Control'));
        self::assertSame([], $plain->headers->getCookies());
        self::assertStringContainsString('fsc:consent', (string) file_get_contents(__DIR__ . '/../../resources/assets/fsc-consent.js'));

        $versioned = $this->get('/_fsc/c/consent.js?v=' . ConsentController::version());
        self::assertStringContainsString('immutable', (string) $versioned->headers->get('Cache-Control'));
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function validChoices(): array
    {
        return [
            'all' => [['choice' => 'all', 'version' => '3'], ['choice' => 'all', 'version' => '3']],
            'none' => [['choice' => 'none'], ['choice' => 'none']],
            'custom' => [['choice' => 'custom', 'categories' => ['analytics'], 'version' => '3'], ['choice' => 'custom', 'categories' => ['analytics'], 'version' => '3']],
            'categories dropped unless custom' => [['choice' => 'all', 'categories' => ['analytics']], ['choice' => 'all']],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $forwarded
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validChoices')]
    public function testItRecordsAValidChoiceAndAnswers204(array $payload, array $forwarded): void
    {
        $response = $this->postJson('/_fsc/c', $payload, ['cf-connecting-ip' => '203.0.113.7', 'User-Agent' => 'TestBrowser/1.0']);

        $response->assertNoContent();
        self::assertSame([], $response->headers->getCookies());
        $posts = $this->recorded();
        self::assertCount(1, $posts);
        self::assertSame($forwarded, json_decode((string) $posts[0]->body, true));
        self::assertSame('203.0.113.7', $posts[0]->headers['X-FSC-Client-Ip'] ?? null);
        self::assertSame('TestBrowser/1.0', $posts[0]->headers['X-FSC-Client-User-Agent'] ?? null);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidChoices(): array
    {
        return [
            'empty' => [[]],
            'unknown choice' => [['choice' => 'maybe']],
            'categories not a list' => [['choice' => 'custom', 'categories' => 'analytics']],
            'too many categories' => [['choice' => 'custom', 'categories' => array_fill(0, 11, 'a')]],
            'long version' => [['choice' => 'all', 'version' => str_repeat('v', 41)]],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChoices')]
    public function testItRejectsInvalidInputWithoutAHubCall(array $payload): void
    {
        $this->postJson('/_fsc/c', $payload)->assertStatus(422);
        self::assertSame([], $this->http->requests);
    }

    public function testItThrottlesAt20AMinutePerVisitor(): void
    {
        RateLimiter::clear('x');

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/_fsc/c', ['choice' => 'none'], ['cf-connecting-ip' => '198.51.100.1'])->assertNoContent();
        }

        $limited = $this->postJson('/_fsc/c', ['choice' => 'none'], ['cf-connecting-ip' => '198.51.100.1']);
        $limited->assertStatus(429);
        self::assertNotNull($limited->headers->get('Retry-After'));
        self::assertCount(20, $this->recorded());

        // Another visitor has a limit of its own.
        $this->postJson('/_fsc/c', ['choice' => 'none'], ['cf-connecting-ip' => '198.51.100.2'])->assertNoContent();
    }

    public function testAHubFailureStillAnswers204(): void
    {
        $this->consentAnswer = FakeTransport::json(['error' => ['code' => 'boom', 'message' => 'x']], 500);
        $this->postJson('/_fsc/c', ['choice' => 'all'])->assertNoContent();
    }

    public function testAnUnreachableHubStillAnswers204(): void
    {
        $this->consentAnswer = new TransportException('down');
        $this->postJson('/_fsc/c', ['choice' => 'all'])->assertNoContent();
    }

    public function testTheRoutesGoWithTheMasterSwitch(): void
    {
        self::assertTrue(\Illuminate\Support\Facades\Route::has('content.consent.record'));
        self::assertSame([], \Illuminate\Support\Facades\Route::getRoutes()->getByName('content.consent.record')->gatherMiddleware());
    }
}
