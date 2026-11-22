<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Preview\Preview;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The `newsletterSignup` block and its route. Nobody is ever subscribed: every Hub call goes to FakeTransport.
 */
final class NewsletterSignupTest extends LaravelTestCase
{
    private const LIST_ID = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    private FakeTransport $http;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeTransport();
        $this->http->always(FakeTransport::json(['accepted' => true], 202));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        RateLimiter::clear('fsc-newsletter:' . sha1('127.0.0.1'));
    }

    /** @param list<array<string, mixed>> $blocks */
    private function render(array $blocks): string
    {
        return (string) preg_replace('/ {2,}/', ' ', Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => $blocks]));
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        return json_decode((string) $this->http->last()->body, true);
    }

    public function testTheBlockRendersAFormWithHoneypotCsrfAndAnEncryptedList(): void
    {
        $html = $this->render([['id' => 'nb1', 'type' => 'newsletterSignup', 'heading' => 'Blijf op de hoogte', 'text' => 'Eens per maand.', 'listId' => self::LIST_ID, 'buttonLabel' => '']]);

        self::assertStringContainsString('<section class="fsc-block fsc-block--newsletterSignup" id="fsc-newsletter-nb1">', $html);
        self::assertStringContainsString('<h2 class="fsc-newsletter__heading">Blijf op de hoogte</h2>', $html);
        self::assertStringContainsString('action="/content/newsletter"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('name="website"', $html, 'honeypot');
        self::assertStringContainsString('type="email" name="email"', $html);
        self::assertStringContainsString('>Aanmelden</button>', $html);
        self::assertStringNotContainsString(self::LIST_ID, $html, 'the list id is never in the page as plain text');
        self::assertMatchesRegularExpression('/name="list" value="([^"]+)"/', $html);
        preg_match('/name="list" value="([^"]+)"/', $html, $found);
        self::assertSame(self::LIST_ID, Crypt::decryptString(html_entity_decode($found[1])));
        self::assertSame([], $this->http->requests, 'rendering never calls the Hub');
    }

    public function testThePreviewDisablesTheForm(): void
    {
        app(Preview::class)->activate(['blocks' => []], '/x', 'https://portal.test', 1, 'fscpv_' . str_repeat('A', 43));

        $html = $this->render([['id' => 'nb1', 'type' => 'newsletterSignup', 'heading' => 'Nieuws']]);

        self::assertStringContainsString('<fieldset disabled class="fsc-form__preview"', $html);
        self::assertStringContainsString('<div data-fsc-block="nb1" data-fsc-block-index="0" style="display:contents">', $html);
    }

    public function testWithoutTheRouteTheBlockRendersNothing(): void
    {
        $routes = new \Illuminate\Routing\RouteCollection();
        $this->app['router']->setRoutes($routes);

        self::assertStringNotContainsString('fsc-block--newsletterSignup', $this->render([['type' => 'newsletterSignup']]));
    }

    public function testASubscriptionGoesToTheHubWithTheListAndTheVisitorIpAndRedirectsBack(): void
    {
        $response = $this->withHeaders(['Referer' => 'http://localhost/nieuws?x=1', 'cf-connecting-ip' => '203.0.113.7'])
            ->post('/content/newsletter', ['email' => ' iemand@example.nl ', 'block' => 'nb1', 'list' => Crypt::encryptString(self::LIST_ID), 'website' => '']);

        $response->assertRedirect('http://localhost/nieuws?x=1#fsc-newsletter-nb1');
        $response->assertSessionHas('content_newsletter.nb1', ['status' => 'success', 'message' => \FullStackStudio\Content\Laravel\NewsletterController::MESSAGES['success']]);
        self::assertCount(1, $this->http->requests);
        self::assertStringEndsWith('/api/v1/newsletter/subscribe', $this->http->last()->url);
        self::assertSame(['email' => 'iemand@example.nl', 'listIds' => [self::LIST_ID]], $this->sentBody());
        self::assertSame('203.0.113.7', $this->http->last()->headers['X-FSC-Client-Ip'] ?? null);

        // The flash message shows in place of the form.
        $this->app['session.store']->put('content_newsletter.nb1', ['status' => 'success', 'message' => 'Bijna klaar']);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        self::assertStringContainsString('<p class="fsc-newsletter__success" role="status">Bijna klaar</p>', $this->render([['id' => 'nb1', 'type' => 'newsletterSignup']]));
    }

    public function testNoListMeansTheDefaultList(): void
    {
        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1'])->assertSessionHas('content_newsletter.nb1.status', 'success');

        self::assertSame(['email' => 'iemand@example.nl'], $this->sentBody());
    }

    public function testAnInvalidAddressATamperedListOrAFilledHoneypotNeverReachTheHub(): void
    {
        $this->post('/content/newsletter', ['email' => 'geen-adres', 'block' => 'nb1'])
            ->assertSessionHas('content_newsletter.nb1', ['status' => 'error', 'message' => 'Vul een geldig e-mailadres in.']);

        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1', 'list' => self::LIST_ID])
            ->assertSessionHas('content_newsletter.nb1.status', 'error');

        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1', 'website' => 'http://spam.test'])
            ->assertSessionHas('content_newsletter.nb1.status', 'success');

        self::assertSame([], $this->http->requests);
    }

    public function testTheRateLimitAndHubErrorsGiveADutchMessage(): void
    {
        config(['content.newsletter.rate_limit' => 2]);
        $this->http->always(FakeTransport::json(['error' => ['code' => 'rate_limited', 'message' => 'x']], 429));
        $this->post('/content/newsletter', ['email' => 'a@example.nl', 'block' => 'nb1'])
            ->assertSessionHas('content_newsletter.nb1.message', 'Je hebt het te vaak geprobeerd. Probeer het over een paar minuten opnieuw.');

        $this->http->always(FakeTransport::json(['error' => ['code' => 'x', 'message' => 'x']], 500));
        $this->post('/content/newsletter', ['email' => 'a@example.nl', 'block' => 'nb1'])
            ->assertSessionHas('content_newsletter.nb1.message', 'Aanmelden lukte niet. Probeer het later opnieuw.');

        $calls = count($this->http->requests);
        $this->post('/content/newsletter', ['email' => 'a@example.nl', 'block' => 'nb1'])
            ->assertSessionHas('content_newsletter.nb1.message', 'Je hebt het te vaak geprobeerd. Probeer het over een paar minuten opnieuw.');
        self::assertCount($calls, $this->http->requests, 'over the limit the Hub is not asked');
    }

    public function testTheRouteRunsInTheWebGroupForSessionAndCsrf(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('content.newsletter');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->methods());
        self::assertContains('web', $route->gatherMiddleware());
    }

    public function testARedirectNeverLeavesTheSite(): void
    {
        $this->withHeaders(['Referer' => 'https://evil.test/x'])->post('/content/newsletter', ['email' => 'a@example.nl', 'block' => '"><x'])
            ->assertRedirect('http://localhost/#fsc-newsletter-b0');
    }
}
