<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Laravel\ModuleBlockData;
use FullStackStudio\Content\Laravel\Site\Alternates;
use FullStackStudio\Content\Laravel\Site\PathResolver;
use FullStackStudio\Content\Laravel\Site\Resolved;
use FullStackStudio\Content\Laravel\Site\Urls;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Http\Request as HttpRequest;

/**
 * Task 16 of the multilanguage plan, the site side: `?lang=` on links to Hub visitor pages, the locale prefix and `{locale}` of event links,
 * events read in the language of the path, hreflang for event pages from `availableLocales`, and the language of the module blocks.
 */
final class VisitorLocaleLinksTest extends FakeHubTestCase
{
    private function urls(): Urls
    {
        return $this->app->make(Urls::class);
    }

    private function resolve(string $path): ?Resolved
    {
        $this->app->instance('request', HttpRequest::create($path));

        return $this->app->make(PathResolver::class)->resolve($path);
    }

    protected function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $q);
        $en = ($q['locale'] ?? null) === 'en';

        if ($path === '/events') {
            return FakeTransport::json(['events' => [[
                'id' => 'e1', 'slug' => 'open-dag', 'title' => $en ? 'Open day' : 'Open dag', 'status' => 'published', 'startsAt' => '2026-12-01T09:00:00Z',
                'ticketTypes' => [], 'registrationUrl' => 'https://hub.test/e/i/klant/open-dag', 'locale' => $en ? 'en' : 'nl', 'availableLocales' => ['nl', 'en'],
            ]], 'total' => 1]);
        }

        if ($path === '/appointments/services') {
            return FakeTransport::json(['services' => [['id' => '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b', 'name' => $en ? 'Haircut' : 'Knippen', 'active' => true]], 'bookingPageUrl' => 'https://hub.test/a/b/klant']);
        }

        return parent::answer($request);
    }

    // --- Urls::visitorLink -----------------------------------------------------------------------------------------------

    public function testVisitorLinkAddsLangForANonDefaultEnabledLanguage(): void
    {
        $urls = $this->urls();

        self::assertSame('https://hub.test/e/i/klant/open-dag?lang=en', $urls->visitorLink('https://hub.test/e/i/klant/open-dag', 'en'));
        self::assertSame('https://hub.test/a/b/klant?x=1&lang=en', $urls->visitorLink('https://hub.test/a/b/klant?x=1', 'en'));
        self::assertSame('https://hub.test/a/b/klant?lang=en#top', $urls->visitorLink('https://hub.test/a/b/klant?lang=de#top', 'en'), 'an own lang is replaced, the fragment kept');
        self::assertSame('https://hub.test/a?language=1&lang=en', $urls->visitorLink('https://hub.test/a?language=1', 'en'), 'a parameter that only starts with lang stays');
    }

    public function testVisitorLinkLeavesTheDefaultAnUnknownAndNoLanguageAlone(): void
    {
        $urls = $this->urls();

        foreach ([null, 'nl', 'de', ''] as $locale) {
            self::assertSame('https://hub.test/a/b/klant', $urls->visitorLink('https://hub.test/a/b/klant', $locale), var_export($locale, true));
        }
    }

    public function testASingleLanguageSiteNeverGetsLang(): void
    {
        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl'];

        self::assertSame('https://hub.test/a/b/klant', $this->urls()->visitorLink('https://hub.test/a/b/klant', 'en'));
        self::assertSame('/afspraak', $this->urls()->localize('/afspraak', 'en'));
    }

    public function testLocalizePrefixesOnlyANonDefaultEnabledLanguage(): void
    {
        $urls = $this->urls();

        self::assertSame('/en/afspraak', $urls->localize('/afspraak', 'en'));
        self::assertSame('/en', $urls->localize('/', 'en'));
        self::assertSame('/afspraak', $urls->localize('/afspraak', 'nl'));
        self::assertSame('/afspraak', $urls->localize('/afspraak'));
        self::assertSame('/afspraak/', $urls->localize('/afspraak/', 'nl'), 'no trailing slash change, unlike path()');
    }

    // --- Event links -----------------------------------------------------------------------------------------------------

    public function testEventLinkWithoutLocaleIsWhatItWas(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);

        self::assertSame('/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag', 'id' => 'e1']));
        self::assertSame('/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag', 'locale' => 'en']), 'the event\'s own locale adds nothing without {locale}');
        self::assertSame('/en/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag'], 'en'));
        self::assertSame('/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag'], 'nl'));
    }

    public function testEventLinkWithLocaleInTheTemplate(): void
    {
        config(['content.links.event' => '/{locale}/agenda/{slug}']);

        self::assertSame('/en/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag'], 'en'));
        self::assertSame('/nl/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag'], 'nl'));
        self::assertSame('/en/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag', 'locale' => 'en']), 'the event\'s own language');
        self::assertSame('/nl/agenda/open-dag', $this->urls()->event(['slug' => 'open-dag']), 'the default language');
        self::assertSame('/{locale}/agenda/{slug}', $this->urls()->eventTemplate());
    }

    public function testTwoLocalesInAnEventTemplateAreRefused(): void
    {
        config(['content.links.event' => '/{locale}/{locale}/{slug}']);

        self::assertNull($this->urls()->eventTemplate());
    }

    // --- Resolving -------------------------------------------------------------------------------------------------------

    public function testAnEventUnderALocalePrefixIsReadInThatLanguage(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published'];

        $resolved = $this->resolve('/en/agenda/open-dag');

        self::assertSame('event', $resolved?->type);
        self::assertSame('en', $resolved->locale);
        self::assertSame('en', $this->queryOf('/events/open-dag')['locale']);
        self::assertSame('nl', $this->resolve('/agenda/open-dag')?->locale);
        self::assertSame('nl', $this->queryOf('/events/open-dag')['locale']);
    }

    public function testAnEventWithLocaleInItsTemplateTakesTheLanguageFromThePath(): void
    {
        config(['content.links.event' => '/{locale}/agenda/{slug}']);
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published'];

        $en = $this->resolve('/en/agenda/open-dag');
        self::assertSame('event', $en?->type);
        self::assertSame('en', $en->locale);
        self::assertSame('/en/agenda/open-dag', $en->path, 'as matched, like a {locale} article template');
        self::assertSame('en', $this->queryOf('/events/open-dag')['locale']);

        $nl = $this->resolve('/nl/agenda/open-dag');
        self::assertSame('nl', $nl?->locale);

        self::assertNull($this->resolve('/de/agenda/open-dag'), 'a language the project has not enabled is no event link');
        self::assertNull($this->resolve('/agenda/open-dag'), 'the template needs its locale');
    }

    public function testTheResolvedEventKeepsItsLanguageFields(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published', 'locale' => 'en', 'availableLocales' => ['nl', 'en'], 'internalNote' => 'x'];

        $resource = $this->resolve('/en/agenda/open-dag')?->resource;

        self::assertSame(['nl', 'en'], $resource['availableLocales']);
        self::assertSame('en', $resource['locale']);
        self::assertArrayNotHasKey('internalNote', $resource);
    }

    public function testASingleLanguageSiteResolvesEventsAsBefore(): void
    {
        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl'];
        config(['content.links.event' => '/agenda/{slug}']);
        $this->events['open-dag'] = ['id' => 'e1', 'slug' => 'open-dag', 'status' => 'published'];

        self::assertSame('event', $this->resolve('/agenda/open-dag')?->type);
        self::assertArrayNotHasKey('locale', $this->queryOf('/events/open-dag'), 'a single-language project sends no locale');
    }

    // --- hreflang --------------------------------------------------------------------------------------------------------

    public function testEventHreflangComesFromAvailableLocales(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);

        $found = Alternates::forEvent(['slug' => 'open-dag', 'locale' => 'nl', 'availableLocales' => ['nl', 'en']]);

        self::assertSame([['locale' => 'nl', 'url' => 'https://klant.test/agenda/open-dag'], ['locale' => 'en', 'url' => 'https://klant.test/en/agenda/open-dag']], $found['alternates']);
        self::assertSame('https://klant.test/agenda/open-dag', $found['default']);
    }

    public function testEventHreflangWithLocaleInTheTemplate(): void
    {
        config(['content.links.event' => '/{locale}/agenda/{slug}']);

        $found = Alternates::forEvent(['slug' => 'open-dag', 'availableLocales' => ['nl', 'en']]);

        self::assertSame('https://klant.test/nl/agenda/open-dag', $found['alternates'][0]['url']);
        self::assertSame('https://klant.test/en/agenda/open-dag', $found['alternates'][1]['url']);
        self::assertSame('https://klant.test/nl/agenda/open-dag', $found['default']);
    }

    public function testEventHreflangSkipsUntranslatedAndDisabledLanguages(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);

        $partial = Alternates::forEvent(['slug' => 'open-dag', 'availableLocales' => ['nl']]);
        self::assertSame([], $partial['alternates'], 'an event only in the default language has no alternate');

        $disabled = Alternates::forEvent(['slug' => 'open-dag', 'availableLocales' => ['nl', 'de']]);
        self::assertSame([], $disabled['alternates'], 'de is not enabled for the project');

        self::assertSame([], Alternates::forEvent(['slug' => 'open-dag'])['alternates'], 'a Hub without the feature sends no availableLocales');
    }

    public function testEventHreflangIsEmptyOnASingleLanguageSiteAndWithoutAnEventTemplate(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);
        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl'];
        self::assertSame([], Alternates::forEvent(['slug' => 'open-dag', 'availableLocales' => ['nl', 'en']])['alternates']);

        $this->project = ['locales' => ['nl', 'en'], 'defaultLocale' => 'nl'];
        $this->app->forgetInstance(Urls::class);
        config(['content.links.event' => null]);
        self::assertSame([], Alternates::forEvent(['slug' => 'open-dag', 'availableLocales' => ['nl', 'en']])['alternates']);
    }

    // --- Module blocks ---------------------------------------------------------------------------------------------------

    public function testTheEventListInEnglishReadsEnglishAndLinksToTheHubInEnglish(): void
    {
        $this->app->make(ContentClient::class)->setLocale('en');

        $events = ModuleBlockData::eventList([]);

        self::assertSame('Open day', $events[0]['title']);
        self::assertSame('en', $this->queryOf('/events')['locale']);
        self::assertSame('https://hub.test/e/i/klant/open-dag?lang=en', $events[0]['href'], 'no content.links.event: the Hub registration page, in English');
    }

    public function testTheEventListInTheDefaultLanguageKeepsTheHubLinkAsItWas(): void
    {
        $this->app->make(ContentClient::class)->setLocale('nl');

        $events = ModuleBlockData::eventList([]);

        self::assertSame('https://hub.test/e/i/klant/open-dag', $events[0]['href']);
    }

    public function testTheEventListLinksToTheSiteEventPageInTheVisitorsLanguage(): void
    {
        config(['content.links.event' => '/agenda/{slug}']);
        $client = $this->app->make(ContentClient::class);

        $client->setLocale('en');
        self::assertSame('/en/agenda/open-dag', ModuleBlockData::eventList([])[0]['href']);

        $client->setLocale('nl');
        self::assertSame('/agenda/open-dag', ModuleBlockData::eventList([])[0]['href']);

        config(['content.links.event' => '/{locale}/agenda/{slug}']);
        $client->setLocale('en');
        self::assertSame('/en/agenda/open-dag', ModuleBlockData::eventList([])[0]['href']);
        $client->setLocale('nl');
        self::assertSame('/nl/agenda/open-dag', ModuleBlockData::eventList([])[0]['href']);
    }

    public function testASingleLanguageSiteEventListIsWhatItWas(): void
    {
        $this->project = ['locales' => ['nl'], 'defaultLocale' => 'nl'];
        config(['content.links.event' => '/agenda/{slug}']);
        $this->app->make(ContentClient::class)->setLocale('nl');

        self::assertSame('/agenda/open-dag', ModuleBlockData::eventList([])[0]['href']);
        self::assertArrayNotHasKey('locale', $this->queryOf('/events'));
    }

    public function testTheBookingButtonFollowsTheVisitorsLanguage(): void
    {
        $client = $this->app->make(ContentClient::class);
        $service = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b';

        $client->setLocale('en');
        self::assertSame('https://hub.test/a/b/klant/' . $service . '?lang=en', ModuleBlockData::appointmentBooking(['serviceId' => $service])['href']);
        self::assertSame('https://hub.test/a/b/klant?lang=en', ModuleBlockData::appointmentBooking([])['href']);
        self::assertSame('en', $this->queryOf('/appointments/services')['locale']);

        config(['content.links.booking' => '/afspraak']);
        self::assertSame('/en/afspraak', ModuleBlockData::appointmentBooking([])['href']);

        $client->setLocale('nl');
        self::assertSame('/afspraak', ModuleBlockData::appointmentBooking([])['href']);
        config(['content.links.booking' => null]);
        self::assertSame('https://hub.test/a/b/klant', ModuleBlockData::appointmentBooking([])['href']);
    }
}
