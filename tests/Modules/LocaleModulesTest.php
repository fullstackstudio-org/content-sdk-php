<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Task 16 of the multilanguage plan: the visitor's content language on the reads of events, appointment services and newsletter lists and in the
 * body of event register, book, subscribe, member register and ticket create. Every call is tested for both project kinds: a multilingual
 * project (`locale` goes along) and a single-language one (the request is exactly what it was without a content language).
 */
final class LocaleModulesTest extends ModuleTestCase
{
    /** @var array<string, mixed> */
    private array $project = ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']];

    private function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

        return $path === '/project' ? FakeTransport::json($this->project) : FakeTransport::json([]);
    }

    /** A client whose content language is `$locale` (null = none set) on a project of the given kind. */
    private function localized(bool $multilingual, ?string $locale = 'en', string $token = self::SERVER): ContentClient
    {
        $this->project = $multilingual ? ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']] : ['defaultLocale' => 'nl', 'locales' => ['nl']];
        $this->http->always(FakeTransport::json([]));
        $this->http->queue(...array_fill(0, 40, fn (Request $request): Response => $this->answer($request)));
        $client = $this->client($token);
        $client->setLocale($locale);

        return $client;
    }

    /** The last request that was not the project read. */
    private function lastCall(): Request
    {
        foreach (array_reverse($this->http->requests) as $request) {
            if (! str_ends_with((string) parse_url($request->url, PHP_URL_PATH), '/project')) {
                return $request;
            }
        }

        self::fail('No call was made.');
    }

    /** @return array<string, string> */
    private function queryOfLast(): array
    {
        parse_str((string) parse_url($this->lastCall()->url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<string, mixed> */
    private function bodyOfLast(): array
    {
        return json_decode((string) $this->lastCall()->body, true);
    }

    /**
     * The reads: name => [call, path, extra query].
     *
     * @return array<string, array{callable(ContentClient, array<string, mixed>): mixed, string, array<string, string>}>
     */
    public static function reads(): array
    {
        return [
            'events list' => [static fn (ContentClient $c, array $o) => $c->events->list(['upcoming' => true, 'limit' => 5], $o), '/events', ['upcoming' => 'true', 'limit' => '5']],
            'events get' => [static fn (ContentClient $c, array $o) => $c->events->get('open-dag', $o), '/events/open-dag', []],
            'appointment services' => [static fn (ContentClient $c, array $o) => $c->appointments->services($o), '/appointments/services', []],
            'appointment service' => [static fn (ContentClient $c, array $o) => $c->appointments->service('svc1', $o), '/appointments/services/svc1', []],
            'booking page url' => [static fn (ContentClient $c, array $o) => $c->appointments->bookingPageUrl($o), '/appointments/services', []],
            'newsletter lists' => [static fn (ContentClient $c, array $o) => $c->newsletter->lists->list($o), '/newsletter/lists', []],
            'newsletter list' => [static fn (ContentClient $c, array $o) => $c->newsletter->lists->get('l1', $o), '/newsletter/lists/l1', []],
        ];
    }

    /** @param callable(ContentClient, array<string, mixed>): mixed $call @param array<string, string> $extra */
    #[DataProvider('reads')]
    public function testAMultilingualProjectReadsInTheVisitorsLanguage(callable $call, string $path, array $extra): void
    {
        $call($this->localized(true, 'en'), []);

        self::assertSame($path, substr((string) parse_url($this->lastCall()->url, PHP_URL_PATH), strlen('/api/v1')));
        self::assertSame([...$extra, 'locale' => 'en'], $this->queryOfLast());
    }

    /** @param callable(ContentClient, array<string, mixed>): mixed $call @param array<string, string> $extra */
    #[DataProvider('reads')]
    public function testASingleLanguageProjectSendsExactlyWhatItSentBefore(callable $call, string $path, array $extra): void
    {
        $call($this->localized(false, 'nl'), []);
        $withLanguage = $this->lastCall()->url;

        $this->http = new FakeTransport();
        $call($this->client(), []);

        self::assertSame($this->http->last()->url, $withLanguage, 'same URL as a client with no content language at all');
        self::assertSame($extra, $this->queryOfLast());
        self::assertArrayNotHasKey('locale', $this->queryOfLast());
    }

    /** @param callable(ContentClient, array<string, mixed>): mixed $call @param array<string, string> $extra */
    #[DataProvider('reads')]
    public function testAClientWithoutALanguageNeverAsksTheProject(callable $call, string $path, array $extra): void
    {
        $this->http->always(FakeTransport::json([]));
        $call($this->client(), []);

        self::assertCount(1, $this->http->requests, 'one read, no project read');
        self::assertSame($extra, $this->queryOfLast());
    }

    /** @param callable(ContentClient, array<string, mixed>): mixed $call @param array<string, string> $extra */
    #[DataProvider('reads')]
    public function testALanguageThatIsNotEnabledSendsNothing(callable $call, string $path, array $extra): void
    {
        $call($this->localized(true, 'de'), []);

        self::assertSame($extra, $this->queryOfLast());
    }

    /** @param callable(ContentClient, array<string, mixed>): mixed $call @param array<string, string> $extra */
    #[DataProvider('reads')]
    public function testTheCallOptionWinsAndNeedsNoProjectRead(callable $call, string $path, array $extra): void
    {
        $this->http->always(FakeTransport::json([]));
        $call($this->client(), ['locale' => ' en-GB ']);

        self::assertCount(1, $this->http->requests);
        self::assertSame([...$extra, 'locale' => 'en-GB'], $this->queryOfLast());
    }

    public function testThePublicTokenReadsInTheLanguageToo(): void
    {
        $this->localized(true, 'en', self::PUB)->events->list();

        self::assertSame(['locale' => 'en'], $this->queryOfLast());
    }

    public function testEventReadsAreCachedPerLanguage(): void
    {
        $cache = new \FullStackStudio\Content\Cache\VersionedTagCache(new \FullStackStudio\Content\Cache\ArrayStore());
        $this->http->always(FakeTransport::json(['event' => ['title' => 'x']]));
        $this->http->queue(...array_fill(0, 20, fn (Request $request): Response => $this->answer($request)));
        $en = $this->client(self::SERVER, ['cache' => $cache]);
        $en->setLocale('en');
        $nl = $this->client(self::SERVER, ['cache' => $cache]);
        $nl->setLocale('nl');

        $en->events->get('open-dag', ['cache' => true]);
        $en->events->get('open-dag', ['cache' => true]);
        $nl->events->get('open-dag', ['cache' => true]);

        $reads = array_values(array_filter($this->http->requests, static fn (Request $r): bool => str_contains($r->url, '/events/open-dag')));
        self::assertCount(2, $reads, 'English once (then cached), Dutch is its own cache entry');
    }

    /**
     * The writes: name => [call, path, input, JSON body without the language].
     *
     * @return array<string, array{callable(ContentClient, array<string, mixed>, array<string, mixed>): mixed, string, array<string, mixed>}>
     */
    public static function writes(): array
    {
        return [
            'event register' => [static fn (ContentClient $c, array $i, array $o) => $c->events->register('open-dag', $i, $o), '/events/open-dag/registrations', ['tickets' => [['ticketTypeId' => 't1', 'quantity' => 2]], 'name' => 'Jan', 'email' => 'jan@example.nl']],
            'appointment book' => [static fn (ContentClient $c, array $i, array $o) => $c->appointments->book($i, $o), '/appointments', ['serviceId' => 's1', 'startsAt' => '2026-12-01T10:00:00+01:00', 'name' => 'Jan', 'email' => 'jan@example.nl']],
            'newsletter subscribe' => [static fn (ContentClient $c, array $i, array $o) => $c->newsletter->subscribe($i, $o), '/newsletter/subscribe', ['email' => 'jan@example.nl', 'name' => 'Jan']],
            'member register' => [static fn (ContentClient $c, array $i, array $o) => $c->members->register($i, $o), '/members/register', ['email' => 'jan@example.nl', 'password' => 'a-long-password-1', 'name' => 'Jan']],
            'ticket create' => [static fn (ContentClient $c, array $i, array $o) => $c->tickets->create($i, $o), '/tickets', ['subject' => 'Hulp', 'message' => 'Het werkt niet']],
        ];
    }

    /** @param callable(ContentClient, array<string, mixed>, array<string, mixed>): mixed $call @param array<string, mixed> $input */
    #[DataProvider('writes')]
    public function testAMultilingualProjectStoresTheVisitorsLanguage(callable $call, string $path, array $input): void
    {
        $call($this->localized(true, 'en'), $input, []);

        self::assertSame($path, substr((string) parse_url($this->lastCall()->url, PHP_URL_PATH), strlen('/api/v1')));
        self::assertSame([...$input, 'locale' => 'en'], $this->bodyOfLast(), 'the language is added after the caller\'s own fields');
        self::assertSame([], $this->queryOfLast());
    }

    /** @param callable(ContentClient, array<string, mixed>, array<string, mixed>): mixed $call @param array<string, mixed> $input */
    #[DataProvider('writes')]
    public function testASingleLanguageProjectSendsTheSameBodyAsBefore(callable $call, string $path, array $input): void
    {
        $call($this->localized(false, 'nl'), $input, []);
        $withLanguage = $this->lastCall()->body;

        $this->http = new FakeTransport();
        $call($this->client(), $input, []);

        self::assertSame($this->http->last()->body, $withLanguage, 'byte-identical to a client with no content language');
        self::assertArrayNotHasKey('locale', json_decode((string) $withLanguage, true));
    }

    /** @param callable(ContentClient, array<string, mixed>, array<string, mixed>): mixed $call @param array<string, mixed> $input */
    #[DataProvider('writes')]
    public function testTheCallersOwnLanguageWins(callable $call, string $path, array $input): void
    {
        $call($this->localized(true, 'en'), [...$input, 'locale' => 'nl'], []);

        self::assertSame('nl', $this->bodyOfLast()['locale']);
        self::assertSame(1, substr_count((string) $this->lastCall()->body, '"locale"'));
    }

    /** @param callable(ContentClient, array<string, mixed>, array<string, mixed>): mixed $call @param array<string, mixed> $input */
    #[DataProvider('writes')]
    public function testTheCallOptionBeatsTheClientsLanguage(callable $call, string $path, array $input): void
    {
        $this->http->always(FakeTransport::json([]));
        $call($this->client(), $input, ['locale' => 'en']);

        self::assertSame('en', $this->bodyOfLast()['locale']);
        self::assertCount(1, $this->http->requests, 'no project read for an explicit language');
    }

    /** @param callable(ContentClient, array<string, mixed>, array<string, mixed>): mixed $call @param array<string, mixed> $input */
    #[DataProvider('writes')]
    public function testALanguageThatIsNotEnabledIsNotSent(callable $call, string $path, array $input): void
    {
        $call($this->localized(true, 'de'), $input, []);

        self::assertArrayNotHasKey('locale', $this->bodyOfLast());
    }

    public function testAnEmptyOrNullOwnLanguageFallsBackToTheClients(): void
    {
        $client = $this->localized(true, 'en');
        $client->newsletter->subscribe(['email' => 'jan@example.nl', 'locale' => null]);
        self::assertSame('en', $this->bodyOfLast()['locale']);

        $client->members->register(['email' => 'jan@example.nl', 'password' => 'a-long-password-1', 'locale' => '']);
        self::assertSame('en', $this->bodyOfLast()['locale']);
    }

    public function testTheVisitorIpAndHeadersStayWhereTheyWere(): void
    {
        $client = $this->localized(true, 'en');
        $client->events->register('open-dag', ['name' => 'Jan'], ['clientIp' => '203.0.113.7']);
        self::assertSame('203.0.113.7', $this->lastCall()->headers['X-FSC-Client-Ip']);

        $client->appointments->book(['serviceId' => 's1'], ['memberSession' => 'sess', 'clientIp' => '203.0.113.7']);
        self::assertSame('sess', $this->lastCall()->headers['X-FSC-Member-Session']);
        self::assertSame('en', $this->bodyOfLast()['locale']);
    }

    public function testATicketWithFilesCarriesTheLanguageInItsDataPart(): void
    {
        $this->localized(true, 'en')->tickets->create(['subject' => 'Hulp', 'message' => 'x'], ['files' => [new UploadFile('abc', 'a.txt', 'text/plain')]]);

        $form = $this->lastCall()->multipart;
        self::assertIsArray($form);
        self::assertSame('en', json_decode((string) $form['data'], true)['locale']);
    }

    public function testACopyWithALanguageHasItsOwnModules(): void
    {
        $this->http->always(FakeTransport::json([]));
        $this->http->queue(...array_fill(0, 40, fn (Request $request): Response => $this->answer($request)));
        $nl = $this->client();
        $nl->setLocale('nl');
        $en = $nl->withLocale('en');

        $en->events->list();
        self::assertSame(['locale' => 'en'], $this->queryOfLast());

        $nl->events->list();
        self::assertSame(['locale' => 'nl'], $this->queryOfLast(), 'the original keeps its own language');
        self::assertSame('nl', $nl->locale());
        self::assertSame('en', $en->locale());
        self::assertNotSame($nl->context(), $en->context());
    }
}
