<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Cache\ArrayStore;
use FullStackStudio\Content\Cache\VersionedTagCache;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Laravel\Facades\Content;
use FullStackStudio\Content\Laravel\FormLocale;
use FullStackStudio\Content\Laravel\Site\PathResolver;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Blade;

/**
 * Task 8 of the multilanguage plan: the request scoped content locale, the automatic `locale` on consent, SEO and form reads, form option
 * labels and the banner texts in two languages. Fake transport only: nothing reaches a Hub.
 */
final class ContentLocaleTest extends LaravelTestCase
{
    private FakeTransport $http;

    /** @var array<string, mixed> */
    private array $project = ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']];

    private bool $projectDown = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.forms.enabled', true);
        $app['config']->set('content.cache.store', 'array');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/contact', fn () => Blade::render('<x-content::form form-key="contact" />'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->http->queue(...array_fill(0, 200, fn (Request $request): Response => $this->answer($request)));
        $this->app->singleton(ContentClient::class, fn () => $this->client());
        cache()->flush();
    }

    private ?VersionedTagCache $cache = null;

    private function client(bool $cached = false): ContentClient
    {
        $this->cache ??= new VersionedTagCache(new ArrayStore());

        return new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http] + ($cached ? ['cache' => $this->cache] : []));
    }

    private function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        $locale = isset($query['locale']) ? (string) $query['locale'] : null;

        return match (true) {
            $path === '/project' && $this->projectDown => FakeTransport::json(['error' => ['code' => 'x', 'message' => 'x']], 500),
            $path === '/project' => FakeTransport::json($this->project),
            $path === '/seo' => FakeTransport::json(['seo' => ['articlePathTemplate' => '/blog/{slug}', 'locale' => $locale ?? 'nl', 'siteName' => $locale === 'en' ? 'Site EN' : 'Site NL']]),
            $path === '/consent' && $request->method === 'GET' => FakeTransport::json(['consent' => $this->consent($locale)]),
            $path === '/forms/contact' => FakeTransport::json(['form' => $this->form($locale)]),
            $path === '/forms/contact/submissions' => FakeTransport::json(['id' => 's1']),
            $path === '/pages' => FakeTransport::json(['page' => ['id' => 'p1', 'path' => '/', 'locale' => $locale ?? 'nl', 'status' => 'published', 'title' => 'Home']]),
            default => FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404),
        };
    }

    /** @return array<string, mixed> */
    private function consent(?string $locale): array
    {
        $en = $locale === 'en';

        return [
            'version' => '1',
            'locale' => $locale ?? 'nl',
            'availableLocales' => ['nl', 'en'],
            'bannerTitle' => $en ? 'Cookies and privacy' : 'Cookies en privacy',
            'bannerText' => $en ? 'We use cookies to make the site work.' : 'We gebruiken cookies om de site te laten werken.',
            'acceptAllLabel' => $en ? 'Accept all' : 'Alles accepteren',
            'rejectAllLabel' => $en ? 'Necessary only' : 'Alleen noodzakelijke',
            'categories' => ['analytics', 'marketing'],
        ];
    }

    /** @return array<string, mixed> */
    private function form(?string $locale): array
    {
        $en = $locale === 'en';

        return [
            'key' => 'contact',
            'name' => 'Contact',
            'locale' => $locale ?? 'nl',
            'availableLocales' => $this->project['locales'],
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => $en ? 'Name' : 'Naam', 'required' => true, 'maxLength' => 50],
                ['name' => 'kind', 'type' => 'select', 'label' => $en ? 'Kind' : 'Soort', 'required' => false, 'maxLength' => 50, 'options' => ['Offerte', 'Vraag'], ...($en ? ['optionLabels' => ['Quote', 'Question']] : [])],
            ],
            'honeypotField' => 'website',
            'successMessage' => $en ? 'Thanks!' : 'Bedankt!',
            'confirmEnabled' => false,
            'turnstileSiteKey' => null,
        ];
    }

    /** @return list<string> the path and query of every request, without the `/api/v1` prefix */
    private function urls(): array
    {
        return array_map(static fn (Request $r): string => substr($r->url, strlen('https://content.test/api/v1')), $this->http->requests);
    }

    public function testAMultilingualProjectGetsTheLocaleOnConsentSeoAndForm(): void
    {
        $client = $this->client();
        $client->setLocale('en');

        self::assertSame('en', $client->locale());
        self::assertSame('en', $client->getConsentConfig()['locale']);
        self::assertSame('Site EN', $client->getSeo()['siteName']);
        self::assertSame('Thanks!', $client->getForm('contact')['successMessage']);
        self::assertContains('/consent?locale=en', $this->urls());
        self::assertContains('/seo?locale=en', $this->urls());
        self::assertContains('/forms/contact?locale=en', $this->urls());
    }

    public function testAnExplicitLocaleWinsOverTheClientLocale(): void
    {
        $client = $this->client();
        $client->setLocale('en');

        self::assertSame('Site NL', $client->getSeo(['locale' => 'nl'])['siteName']);
        self::assertContains('/seo?locale=nl', $this->urls());
        $client->getForm('contact', ['locale' => 'nl']);
        $client->getConsentConfig(['locale' => 'nl']);
        self::assertContains('/forms/contact?locale=nl', $this->urls());
        self::assertContains('/consent?locale=nl', $this->urls());

        // An explicit locale is the caller's word: it is sent without asking the project (also for a client without a locale).
        $fresh = $this->client();
        $fresh->getSeo(['locale' => 'en']);
        self::assertSame(['/seo?locale=en'], array_slice($this->urls(), -1));
        self::assertNotContains('/project', array_slice($this->urls(), -1));
    }

    public function testASingleLanguageProjectSendsNoLocaleAndKeepsItsCacheKeys(): void
    {
        $this->project = ['defaultLocale' => 'nl', 'locales' => ['nl']];

        $without = $this->client(true);
        $without->getSeo();
        $without->getForm('contact');
        $without->getConsentConfig();
        $baseline = array_values(array_filter($this->urls(), static fn (string $u): bool => $u !== '/project'));

        self::assertSame(['/seo', '/forms/contact', '/consent'], $baseline);

        // The same reads with a content locale set: byte-identical URLs, so the cache keys (derived from the URL) are the same, and
        // a second client sharing the cache answers from it without a request.
        $before = count($this->http->requests);
        $with = $this->client(true);
        $with->setLocale('nl');
        $with->getSeo();
        $with->getForm('contact');
        $with->getConsentConfig();
        $sent = array_slice($this->urls(), $before);

        self::assertSame([], array_values(array_filter($sent, static fn (string $u): bool => str_contains($u, 'locale'))));
        self::assertSame([], array_values(array_filter($sent, static fn (string $u): bool => $u !== '/project')), 'every read came from the cache');
    }

    public function testALocaleThatIsNotEnabledAndAnUnreadableProjectSendNothing(): void
    {
        $client = $this->client();
        $client->setLocale('de');
        $client->getSeo();
        self::assertContains('/seo', $this->urls());

        $this->projectDown = true;
        $down = $this->client();
        $down->setLocale('en');
        $down->getForm('contact');
        self::assertContains('/forms/contact', $this->urls());
        self::assertNotContains('/forms/contact?locale=en', $this->urls());
    }

    public function testWithLocaleReturnsACopyAndSetLocaleNullClears(): void
    {
        $client = $this->client();
        $copy = $client->withLocale('en');

        self::assertNull($client->locale());
        self::assertSame('en', $copy->locale());
        self::assertNotSame($client, $copy);

        $copy->setLocale('  ');
        self::assertNull($copy->locale());
    }

    public function testTheFacadeForwardsSetLocaleAndLocale(): void
    {
        Content::setLocale('en');

        self::assertSame('en', Content::locale());
        self::assertSame('en', app(ContentClient::class)->locale());
    }

    public function testThePathResolverSetsTheContentLocale(): void
    {
        $this->app->instance('request', HttpRequest::create('/en'));
        $this->app->make(PathResolver::class)->resolve('/en');

        self::assertSame('en', Content::locale());

        $this->app->instance('request', HttpRequest::create('/'));
        $this->app->make(PathResolver::class)->resolve('/');

        self::assertSame('nl', Content::locale());
    }

    public function testFormTextsFollowTheContentLocale(): void
    {
        $this->app['config']->set('content.forms.locale', null);
        Content::setLocale('en');

        self::assertSame('en', FormLocale::resolve());

        Content::setLocale(null);
        self::assertSame('nl', FormLocale::resolve());
    }

    public function testASelectShowsTheOptionLabelAndSubmitsTheOption(): void
    {
        Content::setLocale('en');
        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringContainsString('<option value="Offerte" >Quote</option>', $html);
        self::assertStringContainsString('<option value="Vraag" >Question</option>', $html);
        self::assertStringContainsString('name="_fsc_locale" value="en"', $html);
    }

    public function testASelectWithoutOptionLabelsFallsBackToTheOptions(): void
    {
        // The default language of a Hub without the feature (or a form without translations) has no `optionLabels`.
        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringContainsString('<option value="Offerte" >Offerte</option>', $html);
        self::assertStringContainsString('<option value="Vraag" >Vraag</option>', $html);
    }

    public function testASingleLanguageFormHasNoLocaleField(): void
    {
        $this->project = ['defaultLocale' => 'nl', 'locales' => ['nl']];
        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringNotContainsString('_fsc_locale', $html);
        self::assertStringNotContainsString('locale=', implode(' ', $this->urls()));
    }

    public function testASubmitUsesTheLanguageOfThePageTheFormSatOn(): void
    {
        $this->post('/content/forms/contact', ['name' => 'Jan', 'kind' => 'Offerte', 'website' => '', '_fsc_locale' => 'en'], ['Referer' => 'http://localhost/en'])
            ->assertRedirect()
            ->assertSessionHas('content_form.contact', ['status' => 'success', 'message' => 'Thanks!']);

        self::assertContains('/forms/contact?locale=en', $this->urls());
        $submit = array_values(array_filter($this->http->requests, static fn (Request $r): bool => $r->method === 'POST'))[0];
        self::assertSame('en', json_decode((string) $submit->body, true)['_meta']['locale']);
    }

    public function testASubmitIgnoresALocaleThatIsNotEnabled(): void
    {
        $this->post('/content/forms/contact', ['name' => 'Jan', 'website' => '', '_fsc_locale' => 'zz'])->assertRedirect();

        self::assertNotContains('/forms/contact?locale=zz', $this->urls());
        self::assertContains('/forms/contact', $this->urls());
    }

    public function testTheBannerShowsTheHubTextsInTheVisitorLanguage(): void
    {
        foreach (['nl' => ['We gebruiken cookies om de site te laten werken.', 'Alles accepteren'], 'en' => ['We use cookies to make the site work.', 'Accept all']] as $locale => [$text, $accept]) {
            Content::setLocale($locale);
            $this->app->forgetScopedInstances();
            $html = Blade::render('<x-content::scripts />');

            self::assertStringContainsString($text, $html);
            self::assertStringContainsString('>' . $accept . '</button>', $html);
        }
    }

    public function testTheBannerUsesItsOwnStringsOnlyWhereTheHubSendsNone(): void
    {
        // A Hub without the feature answers the texts it has and no labels: the SDK strings fill the rest, in the language of the page.
        $this->project = ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']];
        $this->http = new FakeTransport();
        $this->http->queue(...array_fill(0, 50, fn (Request $request): Response => str_ends_with((string) parse_url($request->url, PHP_URL_PATH), '/consent')
            ? FakeTransport::json(['consent' => ['version' => '1', 'bannerText' => 'Tekst van de Hub', 'categories' => ['analytics', 'custom']]])
            : $this->answer($request)));
        $this->app->forgetInstance(ContentClient::class);

        foreach (['nl' => ['Statistieken', 'Alleen noodzakelijk', 'altijd aan'], 'en' => ['Statistics', 'Necessary only', 'always on']] as $locale => [$statistics, $reject, $always]) {
            $this->app['config']->set('content.forms.locale', $locale);
            $this->app->forgetScopedInstances();
            $html = Blade::render('<x-content::scripts />');

            self::assertStringContainsString('Tekst van de Hub', $html);
            self::assertStringContainsString($statistics, $html);
            self::assertStringContainsString($reject, $html);
            self::assertStringContainsString($always, $html);
            self::assertStringContainsString('> Custom</label>', $html, 'a category without a text is shown capitalised');
        }
    }

    public function testTheLanguageFilesHaveTheSameKeys(): void
    {
        $nl = require __DIR__ . '/../../resources/lang/nl/content.php';
        $en = require __DIR__ . '/../../resources/lang/en/content.php';

        self::assertSame(self::keys($nl), self::keys($en));
    }

    /**
     * @param array<string, mixed> $array
     * @return list<string>
     */
    private static function keys(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $keys = [...$keys, ...(is_array($value) ? self::keys($value, $prefix . $key . '.') : [$prefix . $key])];
        }

        sort($keys);

        return $keys;
    }
}
