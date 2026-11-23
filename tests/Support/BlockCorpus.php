<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Support;

use FullStackStudio\Content\Http\Request;
use FullStackStudio\Content\Http\Response;
use FullStackStudio\Content\Http\Transport;

/**
 * One block of every type with a layout or tone (page builder v2, AD4) plus an in-process Hub that answers the module blocks with fixed data.
 * Used by the layout tests and by the page that shows every type in every layout (`BlockLayoutPageTest`). Nothing here touches a network.
 */
final class BlockCorpus implements Transport
{
    public const EVENT_ID = '0b7c5a52-6f1e-4d5e-9a57-2a7a8f3c1d10';

    /** @return array<string, array<string, mixed>> */
    public static function media(): array
    {
        $item = static fn (string $id, int $w, int $h): array => ['id' => $id, 'url' => "/api/media/{$id}", 'contentType' => 'image/svg+xml', 'alt' => "Afbeelding {$id}", 'width' => $w, 'height' => $h];

        return ['m1' => $item('m1', 1200, 800), 'm2' => $item('m2', 1200, 800), 'm3' => $item('m3', 1200, 800), 'm4' => $item('m4', 1200, 800)];
    }

    /**
     * The block of a type, without `layout` or `tone`.
     *
     * @return array<string, mixed>
     */
    public static function block(string $type): array
    {
        $lorem = '<h2>Een kop voor je tekst</h2><p>Een alinea met <strong>nadruk</strong> en een <a href="/contact">link</a>. Zo ziet lopende tekst eruit in dit blok, op een telefoon en op een groot scherm.</p><ul><li>Eerste punt</li><li>Tweede punt</li></ul>';

        return match ($type) {
            'hero' => ['type' => 'hero', 'heading' => 'Een sterke kop voor je pagina', 'subheading' => 'Leg in een of twee zinnen uit waar deze pagina over gaat.', 'mediaId' => 'm1', 'ctaLabel' => 'Neem contact op', 'ctaHref' => '/contact'],
            'richText' => ['type' => 'richText', 'html' => $lorem],
            'image' => ['type' => 'image', 'mediaId' => 'm2', 'alt' => '', 'caption' => 'Een bijschrift bij de afbeelding'],
            'gallery' => ['type' => 'gallery', 'columns' => 3, 'items' => [['mediaId' => 'm1', 'caption' => 'Een'], ['mediaId' => 'm2', 'caption' => ''], ['mediaId' => 'm3', 'caption' => 'Drie'], ['mediaId' => 'm4', 'caption' => ''], ['mediaId' => 'm1', 'caption' => ''], ['mediaId' => 'm2', 'caption' => '']]],
            'cta' => ['type' => 'cta', 'heading' => 'Neem contact met ons op', 'text' => 'Vertel kort waar we je mee kunnen helpen.', 'label' => 'Neem contact op', 'href' => '/contact', 'variant' => 'primary'],
            'columns' => ['type' => 'columns', 'columns' => [['html' => '<h3>Eerste kolom</h3><p>Schrijf hier je tekst.</p>'], ['html' => '<h3>Tweede kolom</h3><p>Schrijf hier je tekst.</p>'], ['html' => '<h3>Derde kolom</h3><p>Schrijf hier je tekst.</p>']]],
            'articleList' => ['type' => 'articleList', 'heading' => 'Laatste nieuws', 'category' => null, 'locale' => null, 'limit' => 3],
            'form' => ['type' => 'form', 'formKey' => 'contact'],
            'embed' => ['type' => 'embed', 'url' => 'https://youtu.be/x', 'title' => 'Video', 'provider' => 'youtube', 'embedUrl' => 'https://www.youtube-nocookie.com/embed/x'],
            'legalDocument' => ['type' => 'legalDocument', 'kind' => 'terms'],
            'eventList' => ['type' => 'eventList', 'heading' => 'Agenda', 'limit' => 3, 'emptyText' => ''],
            'event' => ['type' => 'event', 'eventRef' => self::EVENT_ID, 'showDescription' => true, 'ctaLabel' => 'Aanmelden'],
            'appointmentBooking' => ['type' => 'appointmentBooking', 'heading' => 'Maak een afspraak', 'text' => 'Kies zelf een moment dat u past.', 'serviceId' => null, 'ctaLabel' => 'Afspraak maken'],
            'faq' => ['type' => 'faq', 'heading' => 'Veelgestelde vragen', 'categoryId' => null, 'limit' => 10, 'structuredData' => false],
            'productList' => ['type' => 'productList', 'heading' => 'Onze producten', 'category' => null, 'limit' => 4],
            'newsletterSignup' => ['type' => 'newsletterSignup', 'heading' => 'Blijf op de hoogte', 'text' => 'Schrijf je in en ontvang ons nieuws in je mailbox.', 'listId' => null, 'buttonLabel' => 'Aanmelden'],
        };
    }

    /** Every type that has a layout or a tone, in registry order. */
    public const TYPES = ['hero', 'richText', 'image', 'gallery', 'cta', 'columns', 'articleList', 'form', 'embed', 'eventList', 'event', 'appointmentBooking', 'faq', 'productList', 'newsletterSignup'];

    public function send(Request $request): Response
    {
        $path = substr((string) parse_url($request->url, PHP_URL_PATH), strlen('/api/v1'));
        $cover = static fn (string $id): array => ['id' => $id, 'url' => "/api/media/{$id}", 'contentType' => 'image/svg+xml', 'alt' => "Omslag {$id}", 'width' => 1200, 'height' => 800];
        $event = static fn (string $slug, string $title, string $when, string $id, ?array $cover): array => [
            'id' => $id, 'slug' => $slug, 'title' => $title, 'summary' => 'Kom kijken en proef de sfeer.', 'descriptionHtml' => '<p>Welkom <strong>allemaal</strong>. We vertellen over de plannen voor het komende jaar en er is koffie.</p>',
            'location' => 'Werkplaats, Utrecht', 'startsAt' => $when, 'endsAt' => null, 'currency' => 'EUR', 'salesState' => 'open',
            'ticketTypes' => [['id' => 't1', 'name' => 'Gratis', 'priceCents' => 0, 'available' => null]], 'registrationUrl' => "https://content.test/e/i/studio/{$slug}",
            'cover' => $cover, 'coverMediaId' => $cover['id'] ?? null,
        ];

        $answer = match (true) {
            $path === '/events' => ['events' => [
                $event('open-dag', 'Open dag in de werkplaats', '2026-12-12T09:30:00.000Z', self::EVENT_ID, $cover('m1')),
                $event('workshop', 'Workshop houtbewerking', '2026-12-19T13:00:00.000Z', 'a', $cover('m2')),
                $event('borrel', 'Nieuwjaarsborrel', '2027-01-09T16:00:00.000Z', 'b', null),
            ], 'page' => 1, 'limit' => 3, 'total' => 3],
            $path === '/events/' . self::EVENT_ID => ['event' => $event('open-dag', 'Open dag in de werkplaats', '2026-12-12T09:30:00.000Z', self::EVENT_ID, $cover('m1'))],
            $path === '/appointments/services' => ['services' => [['id' => '5d2b1e7a-1c3f-4b8e-8e0a-6f9d2c4b7a11', 'name' => 'Intake']], 'bookingPageUrl' => 'https://content.test/a/b/studio'],
            $path === '/faq' => ['categories' => [], 'items' => [
                ['question' => 'Wat kost het?', 'answerHtml' => '<p>Een eerste gesprek is gratis. Daarna maken we een offerte op maat.</p>', 'answerText' => ''],
                ['question' => 'Hoe lang duurt een project?', 'answerHtml' => '<p>Een gemiddelde website staat binnen zes weken.</p>', 'answerText' => ''],
                ['question' => 'Kan ik zelf teksten aanpassen?', 'answerHtml' => '<p>Ja, dat gaat in het portaal en je ziet direct het resultaat.</p>', 'answerText' => ''],
            ], 'total' => 3],
            $path === '/products' => ['products' => array_map(static fn (int $i): array => ['slug' => "product-{$i}", 'name' => "Product {$i}", 'summary' => 'Een korte omschrijving van het product.', 'status' => 'active', 'currency' => 'EUR', 'priceFromCents' => 1495 * $i, 'priceToCents' => 1495 * $i, 'cover' => $cover('m' . $i)], [1, 2, 3, 4]), 'page' => 1, 'limit' => 4, 'total' => 4],
            $path === '/articles' => ['articles' => array_map(static fn (int $i): array => ['slug' => "bericht-{$i}", 'title' => "Nieuwsbericht {$i}", 'excerpt' => 'Een samenvatting van het bericht, in een of twee zinnen.', 'locale' => 'nl', 'publishedAt' => "2026-11-0{$i}T09:00:00.000Z", 'cover' => $cover('m' . $i)], [1, 2, 3]), 'page' => 1, 'limit' => 3, 'total' => 3],
            $path === '/seo' => ['seo' => ['articlePathTemplate' => '/nieuws/{slug}']],
            $path === '/sitemap' => ['entries' => []],
            str_starts_with($path, '/forms/') => ['form' => [
                'key' => 'contact', 'name' => 'Contact', 'honeypotField' => 'website', 'successMessage' => 'ok', 'confirmEnabled' => false, 'turnstileSiteKey' => null,
                'fields' => [
                    ['name' => 'name', 'type' => 'text', 'label' => 'Naam', 'required' => true, 'maxLength' => 100],
                    ['name' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true, 'maxLength' => 100],
                    ['name' => 'message', 'type' => 'textarea', 'label' => 'Bericht', 'required' => false, 'maxLength' => 1000],
                ],
            ]],
            default => null,
        };

        return $answer === null ? FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404) : FakeTransport::json($answer);
    }
}
