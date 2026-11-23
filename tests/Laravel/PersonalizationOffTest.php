<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/**
 * Personalisation OFF (the default, also with Intelligence itself on): a slot is exactly its element with the default content, variants
 * render nothing, there is no script and no route, and the Hub is never asked. A live site does not change until it is switched on.
 */
final class PersonalizationOffTest extends LaravelTestCase
{
    public function testASlotIsExactlyItsDefaultContentAndNothingIsAsked(): void
    {
        $content = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $content]));

        foreach ([false, true] as $intelligence) {
            config(['content.intelligence.enabled' => $intelligence]);
            $html = Blade::render(<<<'BLADE'
                <x-content::slot name="home-hero" as="section" class="g-hero"><h1 data-fsi-field="title">Websites die werken</h1><x-content::variant key="voip"><h1>Bellen</h1></x-content::variant></x-content::slot><x-content::slot name="chat-opening" :data="true" />
                BLADE);

            self::assertSame('<section class="g-hero"><h1 data-fsi-field="title">Websites die werken</h1></section>', trim($html));
        }

        // No route of its own: a POST finds only the automatic content route (GET), so 405; a GET reaches that route and is a 404.
        self::assertSame(405, $this->post('/_fsc/i/decide', ['s' => ['home-hero'], 'p' => '/'])->status());
        $this->get('/_fsc/i/slots.js')->assertNotFound();
        self::assertSame(405, $this->post('/_fsc/i/me')->status());
        // The content route may read the Hub for the unknown path; nothing of the intelligence module is asked.
        foreach ($content->requests as $request) {
            self::assertDoesNotMatchRegularExpression('#intelligence|personali|slots|decide#', $request->url);
        }
    }
}
