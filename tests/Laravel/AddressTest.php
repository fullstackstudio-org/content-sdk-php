<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Http\TransportException;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/** The address routes and `<x-content::address-field>`. Never talks to Content (fake transport). */
final class AddressTest extends LaravelTestCase
{
    private FakeTransport $content;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('content.address.ip_header', 'cf-connecting-ip');
        $app['config']->set('content.address.rate_limit', 5);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->content = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->content]));
    }

    /** @return array<string, mixed> */
    private static function address(): array
    {
        return ['label' => "Roggekamp 68, 2592VB 's-Gravenhage", 'street' => 'Roggekamp', 'houseNumber' => 68, 'houseLetter' => null, 'addition' => null, 'postcode' => '2592VB', 'city' => "'s-Gravenhage", 'municipality' => null, 'province' => null, 'country' => 'NL', 'lat' => 52.09, 'lon' => 4.34];
    }

    public function testSuggestAsksContentWithTheVisitorIpAndNoToken(): void
    {
        $this->content->always(FakeTransport::json(['addresses' => [self::address()]]));

        $response = $this->get('/_fsc/address/suggest?q=roggekamp+68&limit=3', ['cf-connecting-ip' => '203.0.113.9'])->assertOk()->assertJsonPath('addresses.0.postcode', '2592VB');

        self::assertSame('/api/v1/address/suggest', parse_url($this->content->last()->url, PHP_URL_PATH));
        self::assertSame(['q' => 'roggekamp 68', 'limit' => '3'], $this->content->lastQuery());
        self::assertSame('203.0.113.9', $this->content->last()->headers['X-FSC-Client-Ip'] ?? null);
        self::assertStringNotContainsString('fsc_srv_', (string) $response->getContent());
        self::assertSame([], $response->headers->getCookies(), 'no session or cookies on the address routes');
    }

    public function testInvalidInputNeverReachesContent(): void
    {
        foreach (['/_fsc/address/suggest', '/_fsc/address/suggest?q=ab', '/_fsc/address/suggest?q=abcd&limit=11', '/_fsc/address/suggest?q[]=abc', '/_fsc/address/lookup?postcode=12&number=1', '/_fsc/address/lookup?postcode=2592VB&number=x'] as $url) {
            $this->get($url)->assertStatus(400);
        }

        self::assertCount(0, $this->content->requests);
    }

    public function testLookupNormalisesThePostcode(): void
    {
        $this->content->always(FakeTransport::json(['address' => self::address()]));

        $this->get('/_fsc/address/lookup?postcode=2592+vb&number=68')->assertOk()->assertJsonPath('address.street', 'Roggekamp');

        self::assertSame(['postcode' => '2592VB', 'number' => '68'], $this->content->lastQuery());
    }

    public function testAContentOutageGivesAnEmptyAnswerNotAnError(): void
    {
        $this->content->always(FakeTransport::json(['error' => ['code' => 'internal_error', 'message' => 'x']], 500));
        $this->get('/_fsc/address/suggest?q=roggekamp')->assertOk()->assertExactJson(['addresses' => []])->assertHeader('Cache-Control', 'no-store, private');

        $this->content->queue(new TransportException('down'));
        $this->get('/_fsc/address/lookup?postcode=2592VB&number=68')->assertOk()->assertExactJson(['address' => null]);
    }

    public function testItRateLimitsPerVisitorIp(): void
    {
        $this->content->always(FakeTransport::json(['addresses' => []]));

        for ($i = 0; $i < 5; $i++) {
            $this->get('/_fsc/address/suggest?q=roggekamp', ['cf-connecting-ip' => '203.0.113.9'])->assertOk();
        }

        $this->get('/_fsc/address/suggest?q=roggekamp', ['cf-connecting-ip' => '203.0.113.9'])->assertStatus(429);
        $this->get('/_fsc/address/suggest?q=roggekamp', ['cf-connecting-ip' => '203.0.113.10'])->assertOk();
    }

    public function testTheFieldScriptIsServed(): void
    {
        $response = $this->get('/_fsc/address/field.js')->assertOk();

        self::assertStringStartsWith('text/javascript', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('data-fsc-address', (string) $response->getContent());
    }

    public function testTheComponentRendersPrefixedFieldsAndTheScriptOnce(): void
    {
        $html = Blade::render('<x-content::address-field name="delivery" required /><x-content::address-field name="billing" />');

        foreach (['street', 'houseNumber', 'addition', 'postcode', 'city', 'country'] as $key) {
            self::assertStringContainsString('name="delivery[' . $key . ']"', $html);
            self::assertStringContainsString('name="billing[' . $key . ']"', $html);
        }

        self::assertSame(1, substr_count($html, '/_fsc/address/field.js'), 'the script is included once per page');
        self::assertSame(2, substr_count($html, 'role="combobox"'));
        self::assertStringContainsString('fsc-address--nojs', $html);
        self::assertStringContainsString('"suggestUrl":"/_fsc/address/suggest"', html_entity_decode($html));
        self::assertSame(1, substr_count($html, '/_fsc/address/field.css'));
        self::assertStringNotContainsString('field.css', Blade::render('<x-content::address-field :styles="false" />'));
        self::assertStringContainsString('Adres', Blade::render('<x-content::address-field locale="nl" />'));
    }

    public function testTheComponentUsesCustomNamesPrefilledValuesAndEscapes(): void
    {
        $html = Blade::render('<x-content::address-field :names="$names" :value="$value" :geo="true" />', [
            'names' => ['street' => 'straat', 'houseNumber' => 'huisnummer', 'addition' => 'toevoeging', 'postcode' => 'postcode', 'city' => 'plaats', 'country' => 'land', 'lat' => 'lat', 'lon' => 'lon'],
            'value' => ['street' => 'Roggekamp"><script>alert(1)</script>', 'houseNumber' => 68, 'postcode' => '2592VB', 'city' => 'Den Haag', 'country' => 'FR'],
        ]);

        self::assertStringContainsString('name="straat"', $html);
        self::assertStringContainsString('name="plaats"', $html);
        self::assertStringContainsString('name="lat" value=""', $html);
        self::assertStringContainsString('value="68"', $html);
        self::assertStringContainsString('<option value="FR" selected>FR</option>', $html, 'a country outside the list stays selectable');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('data-fsc-filled="1"', $html);
    }

    public function testOldInputWinsOverTheValueAttribute(): void
    {
        $session = $this->app['session.store'];
        $session->put('_old_input', ['address' => ['city' => 'Rotterdam']]);
        $this->app['request']->setLaravelSession($session);
        $html = Blade::render('<x-content::address-field :value="[\'city\' => \'Utrecht\']" />');

        self::assertStringContainsString('value="Rotterdam"', $html);
        self::assertStringNotContainsString('value="Utrecht"', $html);
    }

    public function testTheStylesAreServed(): void
    {
        $response = $this->get('/_fsc/address/field.css')->assertOk();

        self::assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('.fsc-address__list', (string) $response->getContent());
    }
}
