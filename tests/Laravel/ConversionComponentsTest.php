<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\ConversionMarker;
use Illuminate\Support\Facades\Blade;

/** The conversion markers the Hub's default goals and funnels rely on (ootb C2): names, props and the no-personal-data rule. */
final class ConversionComponentsTest extends LaravelTestCase
{
    private function render(string $template, array $data = []): string
    {
        return trim(Blade::render($template, $data));
    }

    public function testBeginCheckoutPrintsOneHiddenMarkerWithTheEventName(): void
    {
        $html = $this->render('<x-content::begin-checkout :items="2" :value="49.954" />');

        self::assertSame('<span hidden aria-hidden="true" data-fsc-fire="begin_checkout" data-fsc-prop-items="2" data-fsc-prop-value="49.95"></span>', $html);
    }

    public function testBeginCheckoutWithoutPropsIsJustTheMarker(): void
    {
        self::assertSame('<span hidden aria-hidden="true" data-fsc-fire="begin_checkout"></span>', $this->render('<x-content::begin-checkout />'));
    }

    public function testAppointmentBookedCarriesServiceReferenceAndBookingPath(): void
    {
        $html = $this->render('<x-content::appointment-booked service="knippen" reference="b-42" page="/afspraak-maken?x=1#top" />');

        self::assertStringContainsString('data-fsc-fire="appointment_booked"', $html);
        self::assertStringContainsString('data-fsc-once="b-42"', $html);
        self::assertStringContainsString('data-fsc-prop-service="knippen"', $html);
        self::assertStringContainsString('data-fsc-prop-booking-path="/afspraak-maken"', $html, 'query and fragment never travel');
    }

    public function testAppointmentBookedDefaultsTheBookingPathToTheCurrentPath(): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/afspraak', 'GET'));

        self::assertStringContainsString('data-fsc-prop-booking-path="/afspraak"', $this->render('<x-content::appointment-booked />'));
    }

    public function testPurchaseIsARevenueEventFromAnOrder(): void
    {
        $order = ['number' => '0042', 'currency' => 'eur', 'totals' => ['totalCents' => 10485], 'lines' => [['sku' => 'a'], ['sku' => 'b']],
            'email' => 'klant@example.nl', 'shippingAddress' => ['street' => 'Straat 1']];
        $html = $this->render('<x-content::purchase :order="$order" />', ['order' => $order]);

        self::assertStringContainsString('data-fsc-fire="purchase"', $html);
        self::assertStringContainsString('data-fsc-revenue="104.85"', $html);
        self::assertStringContainsString('data-fsc-currency="EUR"', $html);
        self::assertStringContainsString('data-fsc-once="0042"', $html);
        self::assertStringContainsString('data-fsc-prop-order="0042"', $html);
        self::assertStringContainsString('data-fsc-prop-items="2"', $html);
        self::assertStringNotContainsString('klant', $html);
        self::assertStringNotContainsString('Straat', $html);
    }

    public function testPurchaseTakesExplicitValuesAndDefaultsTheCurrency(): void
    {
        $html = $this->render('<x-content::purchase number="A-1" :total-cents="2000" />');

        self::assertStringContainsString('data-fsc-revenue="20"', $html);
        self::assertStringContainsString('data-fsc-currency="EUR"', $html);
    }

    public function testPurchaseWithoutAPositiveTotalRendersNothing(): void
    {
        self::assertSame('', $this->render('<x-content::purchase number="A-1" :total-cents="0" />'));
        self::assertSame('', $this->render('<x-content::purchase />'));
    }

    public function testPropsNeverCarryPersonalData(): void
    {
        $props = ConversionMarker::cleanProps([
            'order' => 'A-1', 'email' => 'x@y.nl', 'customer_name' => 'Jan', 'phone' => '0612345678', 'note' => 'hi',
            'reference' => 'jan@example.nl', 'Items' => 3, 'bad key' => 'v', 'long' => str_repeat('a', 300), 'obj' => ['a'],
        ]);

        self::assertSame(['order' => 'A-1', 'items' => 3, 'long' => str_repeat('a', 120)], $props);
    }

    public function testValuesAreEscaped(): void
    {
        $html = ConversionMarker::html('begin_checkout', ['order' => '"><script>x</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testPathIsSitePathOnly(): void
    {
        self::assertSame('/boeken', ConversionMarker::path('/boeken?utm=1'));
        self::assertNull(ConversionMarker::path('//evil.test/x'));
        self::assertNull(ConversionMarker::path('https://x.nl/a'));
        self::assertNull(ConversionMarker::path('/a@b'));
        self::assertNull(ConversionMarker::path(null));
    }
}
