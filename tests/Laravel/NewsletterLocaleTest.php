<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;

/** Task 16: the signup block tells its route the page language, and the route subscribes in it. Fake transport only. */
final class NewsletterLocaleTest extends LaravelTestCase
{
    private FakeTransport $http;

    /** @var array<string, mixed> */
    private array $project = ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']];

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
        $this->http->queue(...array_fill(0, 100, function (Request $request): Response {
            $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

            return $path === '/project' ? FakeTransport::json($this->project) : FakeTransport::json(['accepted' => true], 202);
        }));
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
        RateLimiter::clear('fsc-newsletter:' . sha1('127.0.0.1'));
    }

    private function render(): string
    {
        return Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [['id' => 'nb1', 'type' => 'newsletterSignup']]]);
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        foreach (array_reverse($this->http->requests) as $request) {
            if (str_ends_with((string) parse_url($request->url, PHP_URL_PATH), '/newsletter/subscribe')) {
                return json_decode((string) $request->body, true);
            }
        }

        self::fail('No subscription was sent.');
    }

    public function testAMultilingualPageCarriesItsLanguageInTheForm(): void
    {
        $this->app->make(ContentClient::class)->setLocale('en');

        self::assertStringContainsString('<input type="hidden" name="_fsc_locale" value="en">', $this->render());
    }

    public function testASingleLanguageFormHasNoLanguageField(): void
    {
        $this->project = ['defaultLocale' => 'nl', 'locales' => ['nl']];
        $this->app->make(ContentClient::class)->setLocale('nl');

        self::assertStringNotContainsString('_fsc_locale', $this->render());
    }

    public function testAFormWithoutALanguageIsRenderedAsBeforeWithoutAnyProjectRead(): void
    {
        self::assertStringNotContainsString('_fsc_locale', $this->render());
        self::assertSame([], $this->http->requests);
    }

    public function testThePostedLanguageIsStoredWithTheSubscriber(): void
    {
        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1', '_fsc_locale' => 'en'])->assertSessionHas('content_newsletter.nb1.status', 'success');

        self::assertSame(['email' => 'iemand@example.nl', 'locale' => 'en'], $this->sentBody());
    }

    public function testALanguageThatIsNotEnabledIsIgnored(): void
    {
        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1', '_fsc_locale' => 'de'])->assertSessionHas('content_newsletter.nb1.status', 'success');

        self::assertSame(['email' => 'iemand@example.nl'], $this->sentBody());
    }

    public function testASingleLanguageProjectSendsTheOldBodyEvenWithAForgedField(): void
    {
        $this->project = ['defaultLocale' => 'nl', 'locales' => ['nl']];

        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1', '_fsc_locale' => 'nl'])->assertSessionHas('content_newsletter.nb1.status', 'success');

        self::assertSame(['email' => 'iemand@example.nl'], $this->sentBody());
    }

    public function testWithoutTheFieldNothingAsksTheProject(): void
    {
        $this->post('/content/newsletter', ['email' => 'iemand@example.nl', 'block' => 'nb1'])->assertSessionHas('content_newsletter.nb1.status', 'success');

        self::assertCount(1, $this->http->requests, 'only the subscription');
        self::assertSame(['email' => 'iemand@example.nl'], $this->sentBody());
    }
}
