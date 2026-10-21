<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\Address;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
    }

    private function client(): ContentClient
    {
        return new ContentClient(['token' => 'fsc_srv_secrettoken123', 'transport' => $this->http]);
    }

    public function testSuggestAddressesSendsTheQueryAndTheVisitorIp(): void
    {
        $this->http->always(FakeTransport::json(['addresses' => [['label' => 'Roggekamp 68, 2592VB', 'postcode' => '2592VB']]]));

        $list = $this->client()->suggestAddresses('roggekamp 68', 5, ['clientIp' => '203.0.113.7']);

        self::assertSame([['label' => 'Roggekamp 68, 2592VB', 'postcode' => '2592VB']], $list);
        self::assertSame('GET', $this->http->last()->method);
        self::assertSame('/api/v1/address/suggest', parse_url($this->http->last()->url, PHP_URL_PATH));
        self::assertSame(['q' => 'roggekamp 68', 'limit' => '5'], $this->http->lastQuery());
        self::assertSame('203.0.113.7', $this->http->last()->headers['X-FSC-Client-Ip'] ?? null);
    }

    public function testSuggestAddressesIsNotCachedBySdkAndToleratesAMissingList(): void
    {
        $this->http->always(FakeTransport::json([]));

        self::assertSame([], $this->client()->suggestAddresses('abc'));
        self::assertSame('8', $this->http->lastQuery()['limit']);
    }

    public function testLookupAddressReturnsTheAddressOrNull(): void
    {
        $this->http->queue(FakeTransport::json(['address' => ['street' => 'Roggekamp', 'houseNumber' => 68]]), FakeTransport::json(['address' => null]));
        $client = $this->client();

        self::assertSame(['street' => 'Roggekamp', 'houseNumber' => 68], $client->lookupAddress('2592VB', 68));
        self::assertSame(['postcode' => '2592VB', 'number' => '68'], $this->http->lastQuery());
        self::assertNull($client->lookupAddress('2592VB', '70a'));
        self::assertSame('70a', $this->http->lastQuery()['number']);
    }

    public function testErrorsStayContentExceptions(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'invalid_request', 'message' => 'q moet tussen 3 en 100 tekens zijn.']], 400));

        $this->expectException(ContentException::class);
        $this->client()->suggestAddresses('ab');
    }

    public function testFieldNamesDefaultToAPrefixAndAcceptSafeCustomNames(): void
    {
        $names = Address::fieldNames('delivery', ['street' => 'straat', 'city' => 'plaats', 'postcode' => 'bad name"', 'houseNumber' => 'huis[nr]']);

        self::assertSame('straat', $names['street']);
        self::assertSame('plaats', $names['city']);
        self::assertSame('huis[nr]', $names['houseNumber']);
        self::assertSame('delivery[postcode]', $names['postcode'], 'an unsafe name falls back to the prefix form');
        self::assertSame('delivery[addition]', $names['addition']);
        self::assertSame('delivery.street', Address::dotted('delivery[street]'));
        self::assertSame('straat', Address::dotted('straat'));
    }

    public function testHelpers(): void
    {
        self::assertSame('A-2', Address::composeAddition('A', '2'));
        self::assertSame('A', Address::composeAddition('A', null));
        self::assertSame('2', Address::composeAddition(null, ' 2 '));
        self::assertSame('', Address::composeAddition(null, null));
        self::assertSame('2592VB', Address::normalizePostcode(' 2592 vb '));
        self::assertNull(Address::normalizePostcode('0592VB'));
        self::assertSame('roggekamp 68', Address::normalizeQuery("  roggekamp \n 68 "));
        self::assertNull(Address::normalizeQuery('ab'));
        self::assertNull(Address::normalizeQuery(str_repeat('x', 101)));
        self::assertSame('Adres', Address::labels('nl-NL')['label']);
        self::assertSame('Address', Address::labels('de')['label'], 'unknown locales fall back to English');
        self::assertSame('Zoek', Address::labels('nl', ['search' => 'Zoek'])['search']);
    }
}
