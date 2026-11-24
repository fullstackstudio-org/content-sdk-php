<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Middleware\TrustProxies;

/**
 * Task 23 of the multilanguage plan: `examples/laravel` on a two-language project (Dutch default, English enabled). The contact page shows the
 * form and the cookie banner in the language of the page, `/contact` in Dutch and `/en/contact` in English, and a single-language project
 * shows neither the language switch nor an English page. The fake Hub answers by the `locale` it is asked for, like the real one.
 */
final class ExampleSiteMultilingualTest extends FakeHubTestCase
{
    private const EXAMPLE = __DIR__ . '/../../../../examples/laravel';

    private static bool $autoloadRegistered = false;

    protected function setUp(): void
    {
        if (! is_dir(self::EXAMPLE . '/app')) {
            self::markTestSkipped('The examples/laravel directory is not part of this checkout.');
        }

        if (! self::$autoloadRegistered) {
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'App\\')) {
                    $file = self::EXAMPLE . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                    is_file($file) && require_once $file;
                }
            });
            self::$autoloadRegistered = true;
        }

        parent::setUp();

        $this->withoutVite();
        // What the example's AppServiceProvider does: its own strings (`site::site.*`: nav, footer, the contact page).
        $this->app['translator']->addNamespace('site', self::EXAMPLE . '/resources/lang');
        $this->app['view']->getFinder()->prependLocation(self::EXAMPLE . '/resources/views');
        $this->app['config']->set('view.paths', [self::EXAMPLE . '/resources/views']);

        $this->project = ['locales' => ['nl', 'en'], 'defaultLocale' => 'nl', 'modules' => ['pages', 'articles', 'forms', 'seo', 'legal']];
        $this->page('/', 'nl', ['title' => 'Welkom', 'blocks' => []]);
        $this->page('/', 'en', ['title' => 'Welcome', 'blocks' => []]);
    }

    protected function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        $en = ($query['locale'] ?? null) === 'en';

        return match ($path) {
            '/consent' => FakeTransport::json(['consent' => [
                'version' => '1',
                'locale' => $en ? 'en' : 'nl',
                'availableLocales' => ['nl', 'en'],
                'bannerTitle' => $en ? 'Cookies and privacy' : 'Cookies en privacy',
                'bannerText' => $en ? 'We use cookies to make the site work.' : 'Wij gebruiken cookies om de site te laten werken.',
                'acceptAllLabel' => $en ? 'Accept all' : 'Alles accepteren',
                'rejectAllLabel' => $en ? 'Necessary only' : 'Alleen noodzakelijke',
                'categories' => ['analytics'],
            ]]),
            '/forms/contact' => FakeTransport::json(['form' => [
                'key' => 'contact',
                'name' => 'Contact',
                'locale' => $en ? 'en' : 'nl',
                'availableLocales' => $this->project['locales'],
                'fields' => [
                    ['name' => 'email', 'type' => 'email', 'label' => $en ? 'Your email address' : 'Je e-mailadres', 'required' => true, 'maxLength' => 100],
                    ['name' => 'kind', 'type' => 'select', 'label' => $en ? 'What is it about?' : 'Waar gaat het over?', 'required' => false, 'maxLength' => 50, 'options' => ['Offerte', 'Vraag'], ...($en ? ['optionLabels' => ['Quote', 'Question']] : [])],
                ],
                'honeypotField' => 'website',
                'successMessage' => $en ? 'Thanks, we will be in touch.' : 'Bedankt, we nemen contact op.',
                'confirmEnabled' => false,
                'turnstileSiteKey' => null,
            ]]),
            '/forms/contact/submissions' => FakeTransport::json(['id' => 's1']),
            '/sitemap' => FakeTransport::json(['robotsIndex' => true, 'canonicalOrigin' => 'https://klant.test', 'entries' => [['path' => '/', 'locale' => 'nl']]]),
            default => parent::answer($request),
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TrustProxies::flushState();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $example = require self::EXAMPLE . '/config/content.php';
        $app['config']->set('content.auto', array_replace_recursive($app['config']->get('content.auto', []), $example['auto']));
        $app['config']->set('content.auto.redirects', true);
        $app['config']->set('app.url', 'https://voorbeeld.test');
        $app['config']->set('session.driver', 'file');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(self::EXAMPLE . '/routes/web.php');
    }

    public function testTheDutchContactPageShowsTheFormAndTheBannerInDutch(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringContainsString('<html lang="nl">', $html);
        self::assertStringContainsString('<title>Contact', $html);
        self::assertStringContainsString('content="Neem contact met ons op."', $html);
        // The form: Hub labels in Dutch, the options as they are, and the language of the page in a hidden field.
        self::assertStringContainsString('Je e-mailadres', $html);
        self::assertStringContainsString('<option value="Offerte" >Offerte</option>', $html);
        self::assertStringContainsString('name="_fsc_locale" value="nl"', $html);
        // The banner (server rendered by the scripts component).
        self::assertStringContainsString('Wij gebruiken cookies om de site te laten werken.', $html);
        self::assertStringContainsString('>Alles accepteren</button>', $html);
        // The site's own strings.
        self::assertStringContainsString('Privacyverklaring', $html);
        self::assertStringContainsString('Cookie-instellingen', $html);
        self::assertContains('/forms/contact?locale=nl', $this->hubUrls());
    }

    public function testTheEnglishContactPageShowsTheFormAndTheBannerInEnglish(): void
    {
        $html = $this->get('/en/contact')->assertOk()->getContent();

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('content="Get in touch with us."', $html);
        self::assertStringContainsString('Your email address', $html);
        self::assertStringContainsString('<option value="Offerte" >Quote</option>', $html);
        self::assertStringContainsString('<option value="Vraag" >Question</option>', $html);
        self::assertStringContainsString('name="_fsc_locale" value="en"', $html);
        self::assertStringContainsString('We use cookies to make the site work.', $html);
        self::assertStringContainsString('>Accept all</button>', $html);
        self::assertStringContainsString('Privacy statement', $html);
        self::assertStringContainsString('Cookie settings', $html);
        self::assertStringNotContainsString('Je e-mailadres', $html);
        self::assertStringNotContainsString('Wij gebruiken cookies', $html);
        self::assertContains('/forms/contact?locale=en', $this->hubUrls());
        self::assertContains('/consent?locale=en', $this->hubUrls());
    }

    public function testTheLayoutLinksAndTheLanguageSwitchFollowTheLanguage(): void
    {
        $nl = $this->get('/contact')->getContent();
        self::assertStringContainsString('href="/contact">Contact</a>', $nl);
        self::assertStringContainsString('href="/en/contact" hreflang="en" lang="en">EN</a>', $nl);
        self::assertStringContainsString('href="/contact" hreflang="nl" lang="nl" aria-current="true">NL</a>', $nl);

        $en = $this->get('/en/contact')->getContent();
        self::assertStringContainsString('href="/en/contact">Contact</a>', $en);
        self::assertStringContainsString('href="/en/blog">Blog</a>', $en);
        self::assertStringContainsString('href="/contact" hreflang="nl" lang="nl">NL</a>', $en);
        self::assertStringContainsString('href="/en/contact" hreflang="en" lang="en" aria-current="true">EN</a>', $en);
    }

    public function testThePackagesOwnPagesCarryTheLanguageOfTheExampleLayoutToo(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Welcome', $html);
        self::assertStringContainsString('href="/en/contact">Contact</a>', $html);
        self::assertStringContainsString('Cookie settings', $html);
    }

    public function testASubmitFromTheEnglishPageAnswersInEnglish(): void
    {
        $this->post('/content/forms/contact', ['email' => 'jan@example.nl', 'kind' => 'Offerte', 'website' => '', '_fsc_locale' => 'en'], ['Referer' => 'https://voorbeeld.test/en/contact'])
            ->assertRedirect()
            ->assertSessionHas('content_form.contact', ['status' => 'success', 'message' => 'Thanks, we will be in touch.']);

        $submit = array_values(array_filter($this->http->requests, static fn (Request $r): bool => $r->method === 'POST'))[0];
        self::assertSame('en', json_decode((string) $submit->body, true)['_meta']['locale']);
    }

    public function testASingleLanguageProjectHasNoSwitchNoLocaleFieldAndNoEnglishPage(): void
    {
        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl', 'modules' => ['pages', 'forms', 'seo']];

        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringNotContainsString('language-switch', $html);
        self::assertStringNotContainsString('_fsc_locale', $html);
        self::assertStringContainsString('href="/contact">Contact</a>', $html);
        self::assertStringContainsString('Je e-mailadres', $html);
        self::assertSame([], array_values(array_filter($this->hubUrls(), static fn (string $u): bool => str_contains($u, 'locale='))));

        // English is not enabled: `/en/contact` is not a page.
        $this->get('/en/contact')->assertNotFound();
    }

    public function testTheSitemapListsTheContactPageInBothLanguages(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        self::assertStringContainsString('/contact</loc>', $sitemap);
        self::assertStringContainsString('/en/contact</loc>', $sitemap);
    }

    /** @return list<string> path and query of every Hub request, without the `/api/v1` prefix */
    private function hubUrls(): array
    {
        return array_map(static fn (Request $r): string => substr($r->url, strlen('https://content.test/api/v1')), $this->http->requests);
    }
}
