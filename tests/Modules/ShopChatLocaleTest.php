<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Task 23 of the multilanguage plan (SDK batch 3): the visitor's content language on the shipping options, the discount validation
 * (cart->applyCode), the product categories and the chat (config and start). Same rule as batch 2: a multilingual project sends `locale`,
 * a single-language project sends exactly what it sent before.
 */
final class ShopChatLocaleTest extends ModuleTestCase
{
    /** @var array<string, mixed> */
    private array $project = ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']];

    private function answer(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));

        return $path === '/project' ? FakeTransport::json($this->project) : FakeTransport::json([]);
    }

    private function localized(bool $multilingual, ?string $locale = 'en'): ContentClient
    {
        $this->project = $multilingual ? ['defaultLocale' => 'nl', 'locales' => ['nl', 'en']] : ['defaultLocale' => 'nl', 'locales' => ['nl']];
        $this->http->always(FakeTransport::json([]));
        $this->http->queue(...array_fill(0, 40, fn (Request $request): Response => $this->answer($request)));
        $client = $this->client();
        $client->setLocale($locale);

        return $client;
    }

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

    /** @return array<string, array{callable(ContentClient, array<string, mixed>): mixed, string, array<string, string>}> */
    public static function reads(): array
    {
        return [
            'shipping options' => [static fn (ContentClient $c, array $o) => $c->cart->shippingOptions(['country' => 'NL', 'weight' => 500], $o), '/shipping/options', ['country' => 'NL', 'weight' => '500']],
            'product categories' => [static fn (ContentClient $c, array $o) => $c->products->categories($o), '/product-categories', []],
            'chat config' => [static fn (ContentClient $c, array $o) => $c->chat->config($o), '/chat/config', []],
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
        self::assertArrayNotHasKey('locale', $this->queryOfLast());
    }

    /** @param callable(ContentClient, array<string, mixed>): mixed $call @param array<string, string> $extra */
    #[DataProvider('reads')]
    public function testANotEnabledLanguageSendsNothingAndTheCallOptionWins(callable $call, string $path, array $extra): void
    {
        $call($this->localized(true, 'de'), []);
        self::assertSame($extra, $this->queryOfLast());

        $this->http = new FakeTransport();
        $this->http->always(FakeTransport::json([]));
        $call($this->client(), ['locale' => 'en']);
        self::assertCount(1, $this->http->requests, 'an explicit language needs no project read');
        self::assertSame([...$extra, 'locale' => 'en'], $this->queryOfLast());
    }

    public function testTheDiscountCheckCarriesTheLanguageInItsBody(): void
    {
        $input = ['lines' => [['productId' => 'p1', 'quantity' => 1]], 'code' => 'WELKOM', 'shippingMethod' => 'postnl'];

        $this->localized(true, 'en')->cart->applyCode($input);
        self::assertSame([...$input, 'locale' => 'en'], $this->bodyOfLast());
        self::assertSame('/discounts/validate', substr((string) parse_url($this->lastCall()->url, PHP_URL_PATH), strlen('/api/v1')));
    }

    public function testTheDiscountCheckOfASingleLanguageProjectIsUnchanged(): void
    {
        $input = ['lines' => [['productId' => 'p1', 'quantity' => 1]], 'code' => 'WELKOM'];

        $this->localized(false, 'nl')->cart->applyCode($input);
        $withLanguage = $this->lastCall()->body;

        $this->http = new FakeTransport();
        $this->client()->cart->applyCode($input);

        self::assertSame($this->http->last()->body, $withLanguage);
        self::assertArrayNotHasKey('locale', json_decode((string) $withLanguage, true));
    }

    public function testTheDiscountChecksOwnLanguageWinsThenTheOptionThenTheClient(): void
    {
        $client = $this->localized(true, 'en');
        $input = ['lines' => [], 'locale' => 'nl'];

        $client->cart->applyCode($input);
        self::assertSame('nl', $this->bodyOfLast()['locale']);

        $client->cart->applyCode(['lines' => []], ['locale' => 'de']);
        self::assertSame('de', $this->bodyOfLast()['locale']);
    }

    public function testAnOrderIsPlacedInTheVisitorsLanguage(): void
    {
        $input = ['lines' => [['productId' => 'p1', 'quantity' => 1]], 'email' => 'jan@example.nl', 'shippingMethod' => 'postnl'];

        $this->localized(true, 'en')->orders->place($input);
        self::assertSame([...$input, 'locale' => 'en'], $this->bodyOfLast());

        $this->localized(true, 'en')->orders->place([...$input, 'locale' => 'nl']);
        self::assertSame('nl', $this->bodyOfLast()['locale']);

        $this->localized(false, 'nl')->orders->place($input);
        $withLanguage = $this->lastCall()->body;
        $this->http = new FakeTransport();
        $this->client()->orders->place($input);
        self::assertSame($this->http->last()->body, $withLanguage, 'a single-language order is byte-identical');
    }

    public function testTheChatStartCarriesTheLanguage(): void
    {
        $client = $this->localized(true, 'en');

        $client->chat->open([]);
        self::assertSame(['locale' => 'en'], $this->bodyOfLast());

        $client->chat->open(['locale' => 'nl']);
        self::assertSame('nl', $this->bodyOfLast()['locale'], 'the widget\'s own language wins');

        $client->chat->open(['locale' => null, 'turnstileToken' => 'tok']);
        self::assertSame(['locale' => 'en', '_turnstile' => 'tok'], $this->bodyOfLast());
    }

    public function testTheChatStartOfASingleLanguageProjectIsUnchanged(): void
    {
        $this->localized(false, 'nl')->chat->open([]);
        $withLanguage = $this->lastCall()->body;

        $this->http = new FakeTransport();
        $this->client()->chat->open([]);

        self::assertSame($this->http->last()->body, $withLanguage, 'byte-identical to a client with no content language');
        self::assertArrayNotHasKey('locale', (array) json_decode((string) $withLanguage, true));
    }
}
